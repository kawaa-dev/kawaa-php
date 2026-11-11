<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

use Exception;

/**
 * Base exception for all Kawaa API errors.
 *
 * Besides the message and HTTP status (getCode()), every exception carries
 * the retry guidance the API put in its error body, and the Idempotency-Key
 * the call sent. When a call that charges credits fails, repeat it with
 * getIdempotencyKey(): an attempt that was charged is then replayed rather
 * than bought again.
 */
class KawaaException extends Exception
{
    protected ?string $errorCode = null;
    protected ?array $responseBody = null;
    private ?string $idempotencyKey = null;
    private int|float|null $retryAfterHeaderSeconds = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?string $errorCode = null,
        ?array $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
    }

    /**
     * This SDK's category for the error ('authentication_error',
     * 'rate_limit_error', ...). See getApiErrorCode() for the API's own code.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    /**
     * The Idempotency-Key the call sent on every attempt (verify,
     * verifyBatch, find), or null for a call that sends none. Pass it back as
     * the $idempotencyKey argument to repeat the call without paying twice.
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * The API's stable error code (`error.code`, for example
     * `INSUFFICIENT_SCOPE` or `CONFLICT`), when the response carried one.
     */
    public function getApiErrorCode(): ?string
    {
        $code = $this->errorField('code');
        return is_string($code) ? $code : null;
    }

    /** The API's request id (`error.request_id`), when the response carried one. */
    public function getRequestId(): ?string
    {
        $requestId = $this->errorField('request_id');
        return is_string($requestId) ? $requestId : null;
    }

    /**
     * Whether the identical request, sent again after a wait, can succeed
     * (`error.retryable`). Null when the API did not say.
     */
    public function getRetryable(): ?bool
    {
        $retryable = $this->errorField('retryable');
        return is_bool($retryable) ? $retryable : null;
    }

    /**
     * How long the API asked the caller to wait, in seconds: the body's
     * `retry_after_seconds`, else the `Retry-After` header. Null when the API
     * gave neither. This is the server's own value; the client's automatic
     * retries wait at most Kawaa::MAX_RETRY_AFTER_SECONDS.
     */
    public function getRetryAfterSeconds(): int|float|null
    {
        $seconds = $this->errorField('retry_after_seconds');
        if ((is_int($seconds) || is_float($seconds)) && is_finite((float) $seconds) && $seconds >= 0) {
            return $seconds;
        }

        return $this->retryAfterHeaderSeconds;
    }

    /**
     * True when the API said to repeat this request only with the same
     * Idempotency-Key (`error.retry_requires_same_idempotency_key`): a keyed
     * call to a route that charges failed, and a fresh key could pay again.
     */
    public function retryRequiresSameIdempotencyKey(): bool
    {
        return $this->errorField('retry_requires_same_idempotency_key') === true;
    }

    /**
     * True when the API said this attempt may have been charged and a repeat
     * may be charged again (`error.retry_may_charge_again`). The client never
     * repeats such a request on its own; check the balance or the credit
     * history before you do.
     */
    public function retryMayChargeAgain(): bool
    {
        return $this->errorField('retry_may_charge_again') === true;
    }

    /**
     * Record the Idempotency-Key the failed call sent. Set by the client;
     * the first key recorded is kept.
     *
     * @internal
     */
    public function attachIdempotencyKey(string $idempotencyKey): void
    {
        if ($this->idempotencyKey === null) {
            $this->idempotencyKey = $idempotencyKey;
        }
    }

    /**
     * Record the response's parsed `Retry-After` header. Set by the client.
     *
     * @internal
     */
    public function attachRetryAfterHeader(int|float|null $seconds): void
    {
        $this->retryAfterHeaderSeconds = $seconds;
    }

    private function errorField(string $name): mixed
    {
        $error = $this->responseBody['error'] ?? null;
        return is_array($error) ? ($error[$name] ?? null) : null;
    }
}
