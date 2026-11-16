<?php

declare(strict_types=1);

namespace Kawaa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Kawaa\Exceptions\AuthenticationException;
use Kawaa\Exceptions\InsufficientCreditsException;
use Kawaa\Exceptions\IncompleteExportException;
use Kawaa\Exceptions\JobNotCompleteException;
use Kawaa\Exceptions\KawaaException;
use Kawaa\Exceptions\NotFoundException;
use Kawaa\Exceptions\RateLimitException;
use Kawaa\Exceptions\ServerException;
use Kawaa\Exceptions\TimeoutException;
use Kawaa\Exceptions\ValidationException;
use Kawaa\Models\AccountInfo;
use Kawaa\Models\BatchJobResult;
use Kawaa\Models\DomainSearchResult;
use Kawaa\Models\DownloadResult;
use Kawaa\Models\FindResult;
use Kawaa\Models\JobStatusResult;
use Kawaa\Models\VerificationResult;

/**
 * Kawaa Email Verification API client.
 *
 * @example
 * ```php
 * $client = new Kawaa('your-api-key');
 * $result = $client->verify('test@example.com');
 * echo $result->status->value; // valid, invalid, risky, or unknown
 * ```
 */
class Kawaa
{
    private const DEFAULT_BASE_URL = 'https://api.kawaa.com';
    private const DEFAULT_TIMEOUT = 30.0;
    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_POLL_INTERVAL = 2.0;
    private const DEFAULT_POLL_TIMEOUT = 300.0;
    /**
     * This package's version, sent in the User-Agent. Composer packages take
     * their version from the release tag, so this is the one place it is
     * written; the PHP SDK is released with the other SDKs in this repository
     * and a test holds it to their version.
     */
    public const VERSION = '0.3.0';
    /** Reads of a replayed verification's job, in total, before verify() returns it as pending. */
    private const REPLAY_POLL_MAX_READS = 3;

    /**
     * The longest the client waits before an automatic retry, in seconds,
     * whatever `Retry-After` asks for. A longer wait is the caller's decision:
     * the exception reports the server's own value (getRetryAfterSeconds()).
     */
    public const MAX_RETRY_AFTER_SECONDS = 60;

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private ?Client $client = null;
    /** Seconds between reads of a replayed verification's job. Not an option; tests shorten it. */
    private float $replayPollInterval = 2.0;
    /** Waits before a retry, in seconds; usleep() when null. Not an option; tests record it. */
    private ?\Closure $sleeper = null;

    /**
     * Initialize the Kawaa client.
     *
     * @param string $apiKey Your Kawaa API key
     * @param string $baseUrl API base URL (default: https://api.kawaa.com)
     * @param float $timeout Request timeout in seconds (default: 30)
     * @param int $maxRetries Retries after the first attempt (default: 3). 0 sends each
     *   request once and never retries it.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /**
     * Get or create the HTTP client.
     *
     * It never follows a redirect. Every request carries the API key in
     * X-Api-Key, and Guzzle keeps that header when it follows a redirect to
     * another host (it drops only Authorization and Cookie), so following one
     * would send the key to wherever it points. The Kawaa API never
     * redirects; a 3xx answer is refused (see redirectRefused()).
     */
    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'base_uri' => $this->baseUrl,
                'timeout' => $this->timeout,
                'allow_redirects' => false,
                'headers' => [
                    'X-Api-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'kawaa-php/' . self::VERSION,
                ],
            ]);
        }

        return $this->client;
    }

    /**
     * curl error numbers that certainly mean no byte of the request was sent:
     * the proxy or host name could not be resolved, or the TCP connection
     * could not be opened. Guzzle's ConnectException alone is not enough: it
     * also covers a timeout and an empty reply after the request went out.
     */
    private const PRE_SEND_CURL_ERRNOS = [
        5, // CURLE_COULDNT_RESOLVE_PROXY
        6, // CURLE_COULDNT_RESOLVE_HOST
        7, // CURLE_COULDNT_CONNECT
    ];

    /**
     * curl's error number for a transfer that ran out of time
     * (CURLE_OPERATION_TIMEDOUT). Guzzle reports it as a ConnectException
     * whether or not the request had gone out.
     */
    private const CURL_TIMEOUT_ERRNO = 28;

    /**
     * Make an HTTP request with retry logic.
     *
     * Attempts are the first one plus up to $maxRetries retries. Which
     * failures are retried:
     *
     * - A 429: for every method, after `Retry-After`. The limiter refuses
     *   before anything is charged.
     * - A 409 that the API marks `retryable` on a call that carries an
     *   Idempotency-Key: a copy of the same keyed call is still running, and
     *   repeating it under the same key replays the paid result.
     * - A 5xx, a timeout or a dropped connection: only when a repeat cannot
     *   charge twice, that is a call that carries an Idempotency-Key
     *   (verify, verifyBatch, find; the API replays the original job for the
     *   same key) or a GET that charges nothing. domainSearch() is a GET that
     *   charges and takes no key, so it is sent once ($charging). A charging
     *   POST without a key is never re-sent either.
     * - A connection that was never opened (see PRE_SEND_CURL_ERRNOS), for
     *   every method: nothing reached the API.
     *
     * Nothing is retried when the error body says `retry_may_charge_again`.
     * Every exception thrown from a keyed call carries its key
     * (KawaaException::getIdempotencyKey()), so the caller's own retry can
     * reuse it.
     *
     * @throws KawaaException
     */
    private function request(
        string $method,
        string $path,
        ?array $json = null,
        ?array $query = null,
        ?string $idempotencyKey = null,
        bool $charging = false,
    ): array {
        try {
            return $this->sendWithRetries($method, $path, $json, $query, $idempotencyKey, $charging);
        } catch (KawaaException $e) {
            if ($idempotencyKey !== null) {
                $e->attachIdempotencyKey($idempotencyKey);
            }
            throw $e;
        }
    }

    /**
     * The retry loop behind request().
     *
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>|null $query
     * @return array<mixed>
     * @throws KawaaException
     */
    private function sendWithRetries(
        string $method,
        string $path,
        ?array $json,
        ?array $query,
        ?string $idempotencyKey,
        bool $charging,
    ): array {
        $options = [];
        $keyed = $idempotencyKey !== null;
        // Safe to send twice: the key makes the API replay a charged attempt,
        // and a GET that charges nothing changes nothing.
        $replaySafe = $keyed || (strtoupper($method) === 'GET' && !$charging);
        $attempts = max($this->maxRetries, 0) + 1;

        if ($json !== null) {
            $options[RequestOptions::JSON] = $json;
        }

        if ($query !== null) {
            $options[RequestOptions::QUERY] = $query;
        }

        if ($keyed) {
            $options[RequestOptions::HEADERS] = ['Idempotency-Key' => $idempotencyKey];
        }

        for ($attempt = 0; ; $attempt++) {
            $lastAttempt = $attempt + 1 >= $attempts;
            try {
                $response = $this->getClient()->request($method, $path, $options);
            } catch (ConnectException $e) {
                // A connect timeout here can also be a timeout after the
                // request went out, so only a failure to connect at all is
                // safe to repeat for a request that is not replay-safe.
                if ($lastAttempt || (!$replaySafe && !self::failedBeforeSend($e))) {
                    throw $this->transportFailure($e, $attempt + 1);
                }
                $this->pause($this->backoffSeconds($attempt));
                continue;
            } catch (RequestException $e) {
                $errorResponse = $e->getResponse();
                // A 2xx response on a RequestException is a success status
                // whose body failed partway (curl's errno 18 or 56 after the
                // headers): no answer was read. It is a dropped connection
                // like the one with no response at all, so a replay-safe
                // request is sent again under its key instead of being
                // reported as an error with status 200. (A 3xx here is
                // Guzzle's TooManyRedirectsException, mapped as before.)
                $status = $errorResponse?->getStatusCode();
                if ($errorResponse === null || ($status >= 200 && $status < 300)) {
                    // The connection dropped after the request went out: the
                    // server may have acted (and charged).
                    if ($lastAttempt || !$replaySafe) {
                        throw $this->transportFailure($e, $attempt + 1);
                    }
                    $this->pause($this->backoffSeconds($attempt));
                    continue;
                }

                $statusCode = $errorResponse->getStatusCode();
                $error = $this->errorFor(
                    $statusCode,
                    self::decodeErrorBody((string) $errorResponse->getBody()),
                    $errorResponse->getHeaderLine('Retry-After'),
                    $attempt,
                );
                if ($lastAttempt || !self::shouldRetry($statusCode, $error, $replaySafe, $keyed)) {
                    throw $this->givenUp($error, $attempt + 1);
                }
                $this->pause($this->retryDelaySeconds($errorResponse->getHeaderLine('Retry-After'), $error, $attempt));
                continue;
            } catch (TransferException $e) {
                // Any other transfer failure: treated like a dropped connection.
                if ($lastAttempt || !$replaySafe) {
                    throw $this->transportFailure($e, $attempt + 1);
                }
                $this->pause($this->backoffSeconds($attempt));
                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300 && $statusCode < 400) {
                // Not followed (allow_redirects), and not retried: the same
                // request meets the same redirect. request() adds the key.
                throw self::redirectRefused($response);
            }
            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);
            if (!self::isJsonObject($decoded)) {
                // Every API answer is a JSON object. This one cannot be read
                // (empty, not JSON, null, a list), and on a call that charges
                // the charge may stand: the exception carries the key so a
                // repeat replays it rather than mapping it to an empty result.
                throw new KawaaException(
                    "The API answered HTTP {$statusCode} with a body that is not a JSON object",
                    $statusCode
                );
            }

            // Unwrap response envelope
            if (isset($decoded['success']) && $decoded['success'] === true && array_key_exists('data', $decoded)) {
                return $decoded['data'];
            }
            if (isset($decoded['success']) && $decoded['success'] === false) {
                throw $this->errorFor($statusCode, $decoded, $response->getHeaderLine('Retry-After'), $attempt);
            }

            return $decoded;
        }
    }

    /**
     * The exception for a 3xx answer. It names where the redirect pointed
     * (the origin only, never a path or query), so a wrong base URL, such as
     * an http:// address answered by a redirect to https://, is plain to see.
     */
    private static function redirectRefused(\Psr\Http\Message\ResponseInterface $response): KawaaException
    {
        $status = $response->getStatusCode();
        $target = '';
        $location = $response->getHeaderLine('Location');
        if ($location !== '') {
            $parts = parse_url($location);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $target = ' to ' . $parts['scheme'] . '://' . $parts['host']
                    . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        return new KawaaException(
            "The API answered HTTP {$status}, a redirect{$target}. This client does not follow redirects, "
                . 'because the request carries your API key and following one would send the key on to '
                . 'wherever it points. The Kawaa API never redirects, so check the base URL this client was '
                . 'given (the default is ' . self::DEFAULT_BASE_URL . ').',
            $status
        );
    }

    /**
     * Whether json_decode(..., true) produced a JSON object: an array that is
     * not a list. `{}` decodes to [] and counts as an object.
     *
     * @param mixed $decoded
     * @phpstan-assert-if-true array<string, mixed> $decoded
     */
    private static function isJsonObject($decoded): bool
    {
        if (!is_array($decoded)) {
            return false;
        }

        return $decoded === [] || array_keys($decoded) !== range(0, count($decoded) - 1);
    }

    /**
     * Whether a failed attempt is sent again (see request()).
     */
    private static function shouldRetry(int $statusCode, KawaaException $error, bool $replaySafe, bool $keyed): bool
    {
        if ($error->retryMayChargeAgain()) {
            return false;
        }
        // The API's own answer that waiting cannot cure it (a 501, a 503 for
        // an integration that is not configured), whatever the status.
        if ($error->getRetryable() === false) {
            return false;
        }
        if ($statusCode === 429) {
            return true;
        }
        if ($statusCode === 409) {
            return $keyed && $error->getRetryable() === true;
        }

        return $statusCode >= 500 && $replaySafe;
    }

    /**
     * The exception for a request that got no response, after $attempts
     * attempts.
     */
    private function transportFailure(TransferException $e, int $attempts): KawaaException
    {
        $context = $e instanceof ConnectException || $e instanceof RequestException ? $e->getHandlerContext() : [];
        // A transfer that ran out of time, and (as before) a connection that
        // dropped after the request went out, are reported as timeouts.
        if (($context['errno'] ?? null) === self::CURL_TIMEOUT_ERRNO || $e instanceof RequestException) {
            return new TimeoutException($this->timeout, null, $e);
        }

        return new KawaaException(
            "Request failed after {$attempts} attempt" . ($attempts === 1 ? '' : 's') . ': ' . $e->getMessage(),
            0,
            null,
            null,
            $e
        );
    }

    /**
     * A server error that outlasted the retries says how many attempts were
     * made; every other error is thrown as the API worded it.
     */
    private function givenUp(KawaaException $error, int $attempts): KawaaException
    {
        if (!$error instanceof ServerException || $attempts === 1) {
            return $error;
        }

        $exhausted = new ServerException(
            "Request failed after {$attempts} attempts: {$error->getMessage()}",
            $error->getCode(),
            $error->getResponseBody(),
            $error
        );
        $exhausted->attachRetryAfterHeader($error->getRetryAfterSeconds());

        return $exhausted;
    }

    /**
     * The `Retry-After` header as a number of seconds, or null when it is
     * absent or unusable: negative, not finite, an HTTP date or text.
     */
    private static function parseRetryAfter(string $header): int|float|null
    {
        $header = trim($header);
        if (preg_match('/^\d+(\.\d+)?$/', $header) !== 1) {
            return null;
        }
        // Digits only, so the value is finite; a run too long for an int
        // saturates, and the wait is capped before it is used.
        return str_contains($header, '.') ? (float) $header : (int) $header;
    }

    /**
     * The error body's `retry_after_seconds` when it is a usable number.
     *
     * @param array<string, mixed> $data
     */
    private static function bodyRetryAfter(array $data): int|float|null
    {
        $seconds = is_array($data['error'] ?? null) ? ($data['error']['retry_after_seconds'] ?? null) : null;
        if ((is_int($seconds) || is_float($seconds)) && is_finite((float) $seconds) && $seconds >= 0) {
            return $seconds;
        }

        return null;
    }

    /** Whole seconds for RateLimitException::getRetryAfter(), saturating at PHP_INT_MAX. */
    private static function wholeSeconds(int|float $seconds): int
    {
        if (is_int($seconds)) {
            return $seconds;
        }

        return $seconds >= PHP_INT_MAX ? PHP_INT_MAX : (int) ceil($seconds);
    }

    /**
     * Seconds to wait before retrying: the server's `Retry-After` (else the
     * body's `retry_after_seconds`), capped at MAX_RETRY_AFTER_SECONDS, or the
     * exponential backoff when the server gave neither.
     */
    private function retryDelaySeconds(string $retryAfterHeader, KawaaException $error, int $attempt): float
    {
        $seconds = self::parseRetryAfter($retryAfterHeader) ?? $error->getRetryAfterSeconds();
        if ($seconds === null) {
            return $this->backoffSeconds($attempt);
        }

        return (float) min($seconds, self::MAX_RETRY_AFTER_SECONDS);
    }

    private function backoffSeconds(int $attempt): float
    {
        return (float) (2 ** min($attempt, 30));
    }

    private function pause(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * True when curl reports the connection was never opened, so nothing
     * reached the API. Without a curl error number (another handler) the
     * failure is treated as possibly sent.
     */
    private static function failedBeforeSend(ConnectException $e): bool
    {
        $errno = $e->getHandlerContext()['errno'] ?? null;
        return is_int($errno) && in_array($errno, self::PRE_SEND_CURL_ERRNOS, true);
    }

    /**
     * Read a keyed call's successful (and perhaps charged) answer so that
     * whatever escapes carries the call's key. request() puts the key on
     * every error it raises; this covers what follows it, mapping the answer
     * into a model and reading back a replay. A repeat under the same key
     * replays the charge instead of paying again.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     * @throws KawaaException
     */
    private static function readKeyed(string $idempotencyKey, callable $read): mixed
    {
        try {
            return $read();
        } catch (KawaaException $e) {
            $e->attachIdempotencyKey($idempotencyKey);
            throw $e;
        } catch (\Throwable $e) {
            $error = new KawaaException(
                "Could not read the API's response: {$e->getMessage()}. Repeat the call with the same "
                    . 'Idempotency-Key (getIdempotencyKey()) to replay it instead of paying again.',
                0,
                null,
                null,
                $e
            );
            $error->attachIdempotencyKey($idempotencyKey);
            throw $error;
        }
    }

    /**
     * A fresh random UUID v4 naming one logical request.
     */
    private static function newIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * An error response body as an array; text that is not a JSON object is
     * kept as the message.
     *
     * @return array<string, mixed>
     */
    private static function decodeErrorBody(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['error' => $body];
    }

    /**
     * The exception for an error response.
     *
     * The error body is {"success": false, "error": {"code", "message",
     * "request_id"?, "retryable"?, "retry_after_seconds"?,
     * "retry_requires_same_idempotency_key"?, "retry_may_charge_again"?}};
     * an API Gateway failure is a bare {"message": "..."}. Retry timing is in
     * the `Retry-After` header and, on the API's own errors, in the body too.
     *
     * @param array<string, mixed> $data
     */
    private function errorFor(int $statusCode, array $data, string $retryAfterHeader, int $attempt): KawaaException
    {
        $rawError = $data['error'] ?? $data['message'] ?? 'Unknown error';
        if (is_array($rawError)) {
            $message = $rawError['message'] ?? (string)json_encode($rawError);
        } else {
            $message = $rawError;
        }
        $message = is_string($message) ? $message : (string) json_encode($message);
        $headerSeconds = self::parseRetryAfter($retryAfterHeader);

        switch ($statusCode) {
            case 401:
                $error = new AuthenticationException($message, $data);
                break;

            case 402:
                $error = new InsufficientCreditsException($message, $data);
                break;

            case 404:
                $error = new NotFoundException($message, $data);
                break;

            case 422:
            case 400:
                // All API validation failures are 400 BAD_REQUEST (422 is
                // kept only for safety; the API never emits it). The
                // "job still processing" case is re-mapped to
                // JobNotCompleteException by downloadResults() itself; doing
                // it here would hijack ANY endpoint's 400 whose message
                // happens to contain "still", with a bogus job id.
                $error = new ValidationException($message, $data['errors'] ?? [], $data);
                break;

            case 429:
                // The server's wait (header, else body), or this attempt's
                // backoff when it gave none.
                $seconds = $headerSeconds ?? self::bodyRetryAfter($data);
                $error = new RateLimitException(
                    $seconds === null ? (int) $this->backoffSeconds($attempt) : self::wholeSeconds($seconds),
                    $data
                );
                break;

            default:
                $error = $statusCode >= 500
                    ? new ServerException($message, $statusCode, $data)
                    : new KawaaException($message, $statusCode, null, $data);
        }

        $error->attachRetryAfterHeader($headerSeconds);

        return $error;
    }

    // -------------------------------------------------------------------------
    // Single Email Verification
    // -------------------------------------------------------------------------

    /**
     * Build the API `options` object.
     *
     * The API accepts only: deep_verify, include_ai, enrich, timeout_ms,
     * skip_cache. The old checkMx/checkSmtp/checkCatchAll/useAi names were
     * never real API options; checkSmtp and useAi are kept as deprecated
     * aliases of deepVerify and includeAi, and the other two are ignored
     * (MX and catch-all checks always run server-side).
     */
    private static function buildVerifyOptions(
        ?bool $deepVerify,
        ?bool $includeAi,
        ?bool $enrich,
        ?int $timeoutMs,
        ?bool $skipCache,
        ?bool $checkSmtp,
        ?bool $useAi,
    ): array {
        $resolvedDeepVerify = $deepVerify ?? $checkSmtp;
        $resolvedIncludeAi = $includeAi ?? $useAi;

        $options = [
            'deep_verify' => $resolvedDeepVerify ?? true,
            'include_ai' => $resolvedIncludeAi ?? true,
        ];
        if ($enrich !== null) {
            $options['enrich'] = $enrich;
        }
        if ($timeoutMs !== null) {
            $options['timeout_ms'] = $timeoutMs;
        }
        if ($skipCache !== null) {
            $options['skip_cache'] = $skipCache;
        }

        return $options;
    }

    /**
     * Verify a single email address.
     *
     * @param string $email Email address to verify
     * @param bool|null $deepVerify Perform deep (SMTP-level) verification (default: true)
     * @param bool|null $includeAi Use AI for typo detection / catch-all confidence (default: true)
     * @param bool|null $enrich Opt-in data enrichment (default: false)
     * @param int|null $timeoutMs Server-side verification/poll budget in milliseconds
     * @param bool|null $skipCache Bypass the verification cache (default: false)
     * @param bool|null $checkMx Deprecated; not a real API option (ignored)
     * @param bool|null $checkSmtp Deprecated alias of $deepVerify
     * @param bool|null $checkCatchAll Deprecated; not a real API option (ignored)
     * @param bool|null $useAi Deprecated alias of $includeAi
     * @param string|null $idempotencyKey Sent as the Idempotency-Key header;
     *   generated per call when omitted, so a retry after a timeout or 5xx
     *   never charges twice. Supply your own to make repeats of the same
     *   logical request from your side safe too.
     * @return VerificationResult When the synchronous poll window elapses the
     *   API returns status "pending" with a jobId; poll getJobStatus() then.
     *   A repeat of a request already accepted under the same key (this
     *   client's own retry after a lost response) is read back from its job
     *   and returned with $idempotentReplay true; see VerificationResult.
     * @throws KawaaException
     *
     * @example
     * ```php
     * $result = $client->verify('john@gmail.com');
     * if ($result->isValid()) {
     *     echo "Valid email with score {$result->score}";
     * }
     * ```
     */
    public function verify(
        string $email,
        ?bool $deepVerify = null,
        ?bool $includeAi = null,
        ?bool $enrich = null,
        ?int $timeoutMs = null,
        ?bool $skipCache = null,
        ?bool $checkMx = null,
        ?bool $checkSmtp = null,
        ?bool $checkCatchAll = null,
        ?bool $useAi = null,
        ?string $idempotencyKey = null,
    ): VerificationResult {
        unset($checkMx, $checkCatchAll); // never real API options

        $key = $idempotencyKey ?? self::newIdempotencyKey();
        $data = $this->request('POST', '/v1/verify', [
            'email' => $email,
            'options' => self::buildVerifyOptions(
                $deepVerify,
                $includeAi,
                $enrich,
                $timeoutMs,
                $skipCache,
                $checkSmtp,
                $useAi
            ),
        ], null, $key);

        return self::readKeyed($key, function () use ($data): VerificationResult {
            // A repeat of a request the API had already accepted under this
            // key (usually this call's own retry after the first response was
            // lost) answers "pending" with the original job, whatever that
            // job's state. Returned as-is, a verification that had completed
            // (and been charged) read as unfinished and free.
            $jobId = $data['job_id'] ?? null;
            if (($data['idempotent_replay'] ?? null) === true && is_string($jobId) && $jobId !== '') {
                return $this->resolveVerifyReplay($data, $jobId);
            }

            return VerificationResult::fromArray($data);
        });
    }

    /**
     * Read back the job an Idempotency-Key replay of POST /v1/verify points
     * at, and return its result.
     *
     * A short, bounded poll: a job still running after REPLAY_POLL_MAX_READS
     * reads is returned as the API's own "pending" replay answer, with the
     * jobId to poll. So is every failure to read it: a key without
     * verification:read, a 5xx, a dropped connection. The POST already
     * succeeded and the charge stands, so an exception here would only push
     * the caller into retrying under a fresh key and paying again.
     *
     * The replay's own credits_used (0) is what the repeat cost, not what the
     * verification cost, so the unresolved replay's creditsUsed is null: the
     * cost is on the job.
     *
     * @param array<string, mixed> $replay
     */
    private function resolveVerifyReplay(array $replay, string $jobId): VerificationResult
    {
        $unresolved = VerificationResult::fromArray(
            ['idempotent_replay' => true, 'credits_used' => null] + $replay
        );
        // A verdict already read whose bill was still settling: returned, cost
        // left out, if no later read settles it.
        $unsettled = null;

        try {
            for ($read = 1; ; $read++) {
                $job = $this->request('GET', '/v1/jobs/' . rawurlencode($jobId), null, ['include_results' => 'true']);
                $status = $job['status'] ?? null;

                if ($status === 'completed') {
                    $results = $job['results'] ?? null;
                    $first = is_array($results) ? ($results[0] ?? null) : null;
                    if (!is_array($first) || !is_string($first['status'] ?? null)) {
                        return $unsettled ?? $unresolved;
                    }

                    // A result served from the worker's cache is billed 0.5,
                    // settled by a refund the job read applies. When that read
                    // could not apply it, the job says so and the next read
                    // retries it: until then its figures are the pre-discount
                    // charge, not the cost. The verdict stands either way.
                    $discountPending = ($job['cache_discount_pending'] ?? null) === true;

                    // The result row carries the verdict; the job carries the
                    // bill. Its charge less its refunds is what the verification
                    // cost, the figure the synchronous response and the
                    // verification.completed webhook report as credits_used.
                    $verdict = $first;
                    unset(
                        $verdict['credits_used'],
                        $verdict['credits_remaining'],
                        $verdict['credits_held'],
                        $verdict['message'],
                    );
                    $charged = $job['credits_used'] ?? null;
                    $refunded = $job['credits_refunded'] ?? null;
                    $verdict['job_id'] = $jobId;
                    if (!is_string($verdict['email'] ?? null)) {
                        $verdict['email'] = $replay['email'] ?? '';
                    }
                    // Still unsettled after the last read: the verdict stands,
                    // the cost is not known yet (null, never the pre-discount
                    // figure). A job read without a numeric charge leaves it
                    // unknown too (null, never the replay's 0).
                    $verdict['credits_used'] = !$discountPending && (is_int($charged) || is_float($charged))
                        ? $charged - (is_int($refunded) || is_float($refunded) ? $refunded : 0)
                        : null;
                    $verdict['credits_remaining'] = $replay['credits_remaining'] ?? null;
                    $verdict['idempotent_replay'] = true;

                    if ($discountPending) {
                        $unsettled = VerificationResult::fromArray($verdict);
                        if ($read >= self::REPLAY_POLL_MAX_READS) {
                            return $unsettled;
                        }
                        usleep((int) ($this->replayPollInterval * 1000000));
                        continue;
                    }

                    return VerificationResult::fromArray($verdict);
                }

                if ($status === 'failed' || $status === 'cancelled' || $read >= self::REPLAY_POLL_MAX_READS) {
                    return $unsettled ?? $unresolved;
                }
                usleep((int) ($this->replayPollInterval * 1000000));
            }
        } catch (\Throwable) {
            // Not measured, and said so: the verdict already read with its
            // cost left out, or the API's own replay answer (pending, the job
            // to poll) rather than an error that invites a second charge.
            return $unsettled ?? $unresolved;
        }
    }

    // -------------------------------------------------------------------------
    // Batch Verification
    // -------------------------------------------------------------------------

    /**
     * Start a batch email verification job.
     *
     * See verify() for option semantics (deep_verify, include_ai, enrich and
     * skip_cache). Do not pass $timeoutMs here: it bounds only the
     * synchronous wait of verify(), and the API refuses it on a batch with
     * 400 INVALID_OPTION (a ValidationException) before anything is charged.
     *
     * @param array<string> $emails List of email addresses to verify
     * @param string|null $webhookUrl URL to receive completion notification
     * @param string|null $webhookSecret Secret for HMAC signature verification
     * @param bool|null $deepVerify Perform deep (SMTP-level) verification (default: true)
     * @param bool|null $includeAi Use AI for typo detection (default: true)
     * @param bool|null $enrich Opt-in data enrichment (default: false)
     * @param int|null $timeoutMs Leave null. The API refuses timeout_ms on a batch
     *   (400 INVALID_OPTION, nothing charged); it applies to verify() only
     * @param bool|null $skipCache Bypass the verification cache (default: false)
     * @param bool|null $checkMx Deprecated; ignored
     * @param bool|null $checkSmtp Deprecated alias of $deepVerify
     * @param bool|null $checkCatchAll Deprecated; ignored
     * @param bool|null $useAi Deprecated alias of $includeAi
     * @param string|null $idempotencyKey Sent as the Idempotency-Key header;
     *   generated per call when omitted, so a retry after a timeout or 5xx
     *   never charges the batch twice.
     * @return BatchJobResult
     * @throws KawaaException
     */
    public function verifyBatch(
        array $emails,
        ?string $webhookUrl = null,
        ?string $webhookSecret = null,
        ?bool $deepVerify = null,
        ?bool $includeAi = null,
        ?bool $enrich = null,
        ?int $timeoutMs = null,
        ?bool $skipCache = null,
        ?bool $checkMx = null,
        ?bool $checkSmtp = null,
        ?bool $checkCatchAll = null,
        ?bool $useAi = null,
        ?string $idempotencyKey = null,
    ): BatchJobResult {
        unset($checkMx, $checkCatchAll); // never real API options

        $payload = [
            'emails' => $emails,
            'options' => self::buildVerifyOptions(
                $deepVerify,
                $includeAi,
                $enrich,
                $timeoutMs,
                $skipCache,
                $checkSmtp,
                $useAi
            ),
        ];

        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }
        if ($webhookSecret !== null) {
            $payload['webhook_secret'] = $webhookSecret;
        }

        $key = $idempotencyKey ?? self::newIdempotencyKey();
        $data = $this->request('POST', '/v1/verify/batch', $payload, null, $key);

        return self::readKeyed($key, static fn (): BatchJobResult => BatchJobResult::fromArray($data));
    }

    /**
     * Start a batch verification and wait for completion.
     *
     * @param array<string> $emails List of email addresses to verify
     * @param float $pollInterval Seconds between status checks (default: 2)
     * @param float $timeout Maximum seconds to wait (default: 300)
     * @param int|null $timeoutMs Leave null. The API refuses timeout_ms on a batch
     *   (400 INVALID_OPTION, nothing charged); $timeout bounds the wait here
     * @param string|null $idempotencyKey Sent as the batch's Idempotency-Key header;
     *   generated when omitted. Every exception thrown while submitting or waiting
     *   carries it (getIdempotencyKey()): repeat the call with it to replay the
     *   paid batch instead of buying it again.
     * @return JobStatusResult
     * @throws KawaaException
     * @throws TimeoutException
     */
    public function verifyBatchAndWait(
        array $emails,
        float $pollInterval = self::DEFAULT_POLL_INTERVAL,
        float $timeout = self::DEFAULT_POLL_TIMEOUT,
        ?bool $deepVerify = null,
        ?bool $includeAi = null,
        ?bool $enrich = null,
        ?int $timeoutMs = null,
        ?bool $skipCache = null,
        ?string $idempotencyKey = null,
    ): JobStatusResult {
        // Resolved here, not inside verifyBatch(), so a failure while waiting
        // carries the batch's key too: repeating the whole call under it
        // replays the paid batch and waits again; a fresh key buys it twice.
        $key = $idempotencyKey ?? self::newIdempotencyKey();
        $job = $this->verifyBatch(
            $emails,
            null,
            null,
            $deepVerify,
            $includeAi,
            $enrich,
            $timeoutMs,
            $skipCache,
            idempotencyKey: $key,
        );
        try {
            return $this->waitForJob($job->jobId, $pollInterval, $timeout);
        } catch (KawaaException $e) {
            $e->attachIdempotencyKey($key);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Job Management
    // -------------------------------------------------------------------------

    /**
     * Get the status of a batch verification job.
     *
     * @param string $jobId The job ID returned from verifyBatch
     * @param bool $includeResults Include individual results (default: false)
     * @return JobStatusResult
     * @throws KawaaException
     */
    public function getJobStatus(string $jobId, bool $includeResults = false): JobStatusResult
    {
        // The API defaults include_results to true, so send it explicitly.
        $query = ['include_results' => $includeResults ? 'true' : 'false'];
        $data = $this->request('GET', "/v1/jobs/{$jobId}", null, $query);
        return JobStatusResult::fromArray($data);
    }

    /**
     * Wait for a batch job to complete.
     *
     * @param string $jobId The job ID to wait for
     * @param float $pollInterval Seconds between status checks (default: 2)
     * @param float $timeout Maximum seconds to wait (default: 300)
     * @return JobStatusResult
     * @throws KawaaException
     * @throws TimeoutException
     */
    public function waitForJob(
        string $jobId,
        float $pollInterval = self::DEFAULT_POLL_INTERVAL,
        float $timeout = self::DEFAULT_POLL_TIMEOUT,
    ): JobStatusResult {
        $startTime = microtime(true);

        while (true) {
            $status = $this->getJobStatus($jobId, true);

            if ($status->isCompleted()) {
                return $status;
            }

            // The status, not settlement: a cancelled job's verdicts are final the moment
            // it reads `cancelled`, because the worker commits each result under a
            // condition on the running statuses. Only the refund is still outstanding.
            if ($status->isCancelled()) {
                return $status;
            }

            if ($status->isFailed()) {
                throw new KawaaException(
                    $status->errorMessage !== null
                        ? "Job {$jobId} failed: {$status->errorMessage}"
                        : "Job {$jobId} failed"
                );
            }

            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                throw new TimeoutException(
                    $timeout,
                    ['message' => "Job {$jobId} did not complete within {$timeout}s (progress: {$status->progressPercent}%)"]
                );
            }

            usleep((int)($pollInterval * 1000000));
        }
    }

    /**
     * Download results from a completed batch job.
     *
     * @param string $jobId The job ID to download results for
     * @param string $format Output format ("json" or "csv")
     * @return DownloadResult|string DownloadResult for JSON, raw CSV string for CSV format
     * @throws KawaaException
     * @throws JobNotCompleteException
     */
    public function downloadResults(string $jobId, string $format = 'json'): DownloadResult|string
    {
        try {
            return $this->downloadResultsInner($jobId, $format);
        } catch (ValidationException $e) {
            // The API answers 400 "Job is still <status>. Please wait for
            // completion." for incomplete jobs; surface that as the typed
            // JobNotCompleteException with the CALLER's job id. Scoped here
            // (not in the shared error mapper) so other endpoints' 400s are
            // never hijacked by message-text matching.
            if (str_contains(strtolower($e->getMessage()), 'still')) {
                $status = preg_match('/\bjob is still ([a-z_]+)\b/i', $e->getMessage(), $matches) === 1
                    ? strtolower($matches[1])
                    : 'processing';
                throw new JobNotCompleteException($jobId, $status, $e->getResponseBody());
            }
            throw $e;
        } catch (KawaaException $e) {
            $responseBody = $e->getResponseBody();
            $rawError = is_array($responseBody) ? ($responseBody['error'] ?? null) : null;
            $errorCode = is_array($rawError) ? ($rawError['code'] ?? null) : null;
            if ($e->getCode() === 409 && $errorCode === 'CONFLICT') {
                throw new IncompleteExportException(
                    $jobId,
                    false,
                    null,
                    null,
                    $e->getMessage(),
                    409,
                    $responseBody,
                );
            }
            throw $e;
        }
    }

    /**
     * @throws KawaaException
     */
    private function downloadResultsInner(string $jobId, string $format): DownloadResult|string
    {
        $query = ['format' => $format];

        if ($format === 'csv') {
            try {
                $response = $this->getClient()->get("/v1/jobs/{$jobId}/download", [
                    RequestOptions::QUERY => $query,
                ]);
            } catch (ConnectException $e) {
                throw new TimeoutException($this->timeout, null, $e);
            } catch (RequestException $e) {
                $errorResponse = $e->getResponse();
                if ($errorResponse === null) {
                    throw new TimeoutException($this->timeout, null, $e);
                }
                // One-shot path: the typed exception for the response, never
                // a retry (5xx is a ServerException carrying its status).
                throw $this->errorFor(
                    $errorResponse->getStatusCode(),
                    self::decodeErrorBody((string) $errorResponse->getBody()),
                    $errorResponse->getHeaderLine('Retry-After'),
                    0,
                );
            }
            if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                throw self::redirectRefused($response);
            }
            $body = $response->getBody()->getContents();

            // Jobs with >1000 results return a JSON envelope
            // {download_url, format, result_count, expires_in_seconds}
            // instead of the CSV body; fetch the presigned URL transparently.
            if (str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && isset($decoded['download_url'])) {
                    $this->ensureExportComplete($jobId, $decoded);
                    return $this->fetchDownloadUrl($decoded['download_url']);
                }
                throw new KawaaException('Unexpected JSON response for CSV download');
            }

            return $body;
        }

        $data = $this->request('GET', "/v1/jobs/{$jobId}/download", null, $query);

        $this->ensureExportComplete($jobId, $data);

        // >1000-result shape: fetch the presigned URL so results are populated.
        // A non-array root would mean silent data loss (empty results while
        // result_count says otherwise), so it throws instead.
        if (isset($data['download_url']) && !array_key_exists('results', $data)) {
            $rows = json_decode($this->fetchDownloadUrl($data['download_url']), true);
            if ($rows === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new KawaaException('Failed to parse downloaded results JSON: ' . json_last_error_msg());
            }
            // Require a JSON *list* (json_decode also yields associative
            // arrays for object roots; array_is_list needs PHP >= 8.1 and
            // composer allows >= 8.0, hence the manual check).
            $isList = is_array($rows)
                && ($rows === [] || array_keys($rows) === range(0, count($rows) - 1));
            if (!$isList) {
                throw new KawaaException('Unexpected downloaded results shape: expected a JSON array');
            }
            $data['job_id'] = $jobId;
            $data['results'] = $rows;
        }

        return DownloadResult::fromArray($data);
    }

    /**
     * Refuse a short or unverifiable export before its rows reach the caller.
     *
     * @param array<string, mixed> $data
     * @throws IncompleteExportException
     */
    private function ensureExportComplete(string $jobId, array $data): void
    {
        if (($data['complete'] ?? null) === true) {
            return;
        }

        $complete = ($data['complete'] ?? null) === false ? false : null;
        $expectedRows = isset($data['expected_rows']) && is_int($data['expected_rows'])
            ? $data['expected_rows']
            : null;
        $rowsMissing = isset($data['rows_missing']) && is_int($data['rows_missing'])
            ? $data['rows_missing']
            : null;
        $message = isset($data['message']) && is_string($data['message']) && $data['message'] !== ''
            ? $data['message']
            : null;

        throw new IncompleteExportException(
            $jobId,
            $complete,
            $expectedRows,
            $rowsMissing,
            $message,
        );
    }

    /**
     * Fetch the body of a presigned results download URL.
     *
     * Deliberately uses a fresh bare Guzzle client (NOT $this->getClient(),
     * whose default headers carry X-Api-Key on every request): the presigned
     * URL itself is the credential, and the download host (S3/third-party
     * infrastructure) must never receive the API key.
     *
     * @throws KawaaException
     */
    private function fetchDownloadUrl(string $downloadUrl): string
    {
        $client = new Client(['timeout' => $this->timeout]);
        $response = $client->get($downloadUrl);
        if ($response->getStatusCode() >= 400) {
            throw new KawaaException(
                "Failed to fetch results from download URL (HTTP {$response->getStatusCode()})",
                $response->getStatusCode()
            );
        }
        return $response->getBody()->getContents();
    }

    // -------------------------------------------------------------------------
    // Domain Search
    // -------------------------------------------------------------------------

    /**
     * Search for email addresses at a domain.
     *
     * The first uncached call starts an async discovery job and returns
     * status = "processing" with an empty email list; re-call
     * domainSearch($domain) to poll until $result->isProcessing() is false.
     *
     * @param string $domain Domain to search (e.g., "company.com")
     * @return DomainSearchResult
     * @throws KawaaException
     *
     * @example
     * ```php
     * $result = $client->domainSearch('stripe.com');
     * while ($result->isProcessing()) {
     *     sleep(2);
     *     $result = $client->domainSearch('stripe.com');
     * }
     * foreach ($result->emails as $email) {
     *     echo "{$email->email}: {$email->status}\n";
     * }
     * ```
     */
    public function domainSearch(string $domain): DomainSearchResult
    {
        // 10 credits a call, and the route takes no Idempotency-Key: a
        // repeat after a 5xx or a timeout could be charged again, so it is
        // never sent twice on the client's own initiative.
        $data = $this->request('GET', "/v1/domain/{$domain}", null, null, null, charging: true);
        return DomainSearchResult::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Email Finder
    // -------------------------------------------------------------------------

    /**
     * Find a business email address by name and domain.
     *
     * @param string $firstName Person's first name
     * @param string $lastName Person's last name
     * @param string $domain Company domain (e.g., "company.com")
     * @return FindResult
     * @throws KawaaException
     *
     * @param string|null $idempotencyKey Sent as the Idempotency-Key header;
     *   generated per call when omitted. A repeat of the same search under the
     *   same key replays the stored result and charges nothing (the receipt
     *   lasts 30 days). Supply your own whenever you may need to retry, and
     *   reuse that value on the retry.
     *
     * @example
     * ```php
     * $result = $client->find('John', 'Smith', 'company.com');
     * if ($result->found()) {
     *     echo "Found: {$result->email} ({$result->confidence}% confidence)";
     * }
     * ```
     */
    public function find(string $firstName, string $lastName, string $domain, ?string $idempotencyKey = null): FindResult
    {
        $key = $idempotencyKey ?? self::newIdempotencyKey();
        $data = $this->request('POST', '/v1/find', [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'domain' => $domain,
        ], null, $key);

        return self::readKeyed($key, static fn (): FindResult => FindResult::fromArray($data));
    }

    // -------------------------------------------------------------------------
    // Account
    // -------------------------------------------------------------------------

    /**
     * Get account information.
     *
     * @return AccountInfo
     * @throws KawaaException
     *
     * @example
     * ```php
     * $account = $client->getAccount();
     * echo "Credits remaining: {$account->creditsRemaining}";
     * ```
     */
    public function getAccount(): AccountInfo
    {
        $data = $this->request('GET', '/v1/account');
        return AccountInfo::fromArray($data);
    }
}
