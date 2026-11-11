<?php

declare(strict_types=1);

namespace Kawaa;

use Kawaa\Exceptions\ValidationException;

/**
 * Per-status counts for a completed bulk job.
 */
class WebhookJobSummary
{
    public function __construct(
        public readonly int $valid,
        public readonly int $invalid,
        public readonly int $risky,
        public readonly int $unknown,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            valid: (int) ($data['valid'] ?? 0),
            invalid: (int) ($data['invalid'] ?? 0),
            risky: (int) ($data['risky'] ?? 0),
            unknown: (int) ($data['unknown'] ?? 0),
        );
    }
}

/**
 * Data payload of a `verification.completed` event.
 */
class VerificationCompletedData
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?string $email,
        public readonly ?string $status,
        public readonly ?string $subStatus,
        public readonly ?int $qualityScore,
        public readonly ?string $verifiedAt,
        /** What the verification cost: 1 fresh, 0.5 when served from cache. */
        public readonly ?float $creditsUsed,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['job_id'] ?? '',
            email: $data['email'] ?? null,
            status: $data['status'] ?? null,
            subStatus: $data['sub_status'] ?? null,
            qualityScore: isset($data['quality_score']) ? (int) $data['quality_score'] : null,
            verifiedAt: $data['verified_at'] ?? null,
            creditsUsed: isset($data['credits_used']) ? (float) $data['credits_used'] : null,
        );
    }
}

/**
 * Data payload of a `verification.failed` event.
 */
class VerificationFailedData
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?string $error,
        public readonly ?string $failedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['job_id'] ?? '',
            error: $data['error'] ?? null,
            failedAt: $data['failed_at'] ?? null,
        );
    }
}

/**
 * Data payload of a `job.completed` event (also synthesized from the legacy
 * flat per-job callback body).
 *
 * `totalEmails` and `processedEmails` count the addresses that were checked.
 * `requestedEmails` is how many were submitted (and charged for); when some
 * were never checked, `partial` is true and the difference has no result and
 * was refunded.
 */
class JobCompletedData
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?string $status,
        public readonly ?int $totalEmails,
        public readonly ?int $processedEmails,
        public readonly ?WebhookJobSummary $summary,
        public readonly ?string $createdAt,
        public readonly ?string $completedAt,
        public readonly ?string $downloadUrl,
        /** Addresses submitted; null only on a payload that predates the field. */
        public readonly ?int $requestedEmails = null,
        /** True when some submitted addresses were never checked; null only on a payload that predates the field. */
        public readonly ?bool $partial = null,
        /** Every credit returned for the job, including the cached-result discount, so it can be fractional. */
        public readonly ?float $creditsRefunded = null,
        /**
         * True while the cached-result discount has not been applied yet, so
         * creditsRefunded will still grow. Sent only while true, so its absence
         * reads as false (settled); null when the payload carried something
         * other than a boolean, since whether it is settled is then unknown.
         */
        public readonly ?bool $cacheDiscountPending = false,
    ) {}

    public static function fromArray(array $data): self
    {
        $pending = array_key_exists('cache_discount_pending', $data) ? $data['cache_discount_pending'] : false;

        return new self(
            jobId: $data['job_id'] ?? '',
            status: $data['status'] ?? null,
            totalEmails: isset($data['total_emails']) ? (int) $data['total_emails'] : null,
            processedEmails: isset($data['processed_emails']) ? (int) $data['processed_emails'] : null,
            summary: is_array($data['summary'] ?? null) ? WebhookJobSummary::fromArray($data['summary']) : null,
            createdAt: $data['created_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            downloadUrl: $data['download_url'] ?? null,
            requestedEmails: isset($data['requested_emails']) ? (int) $data['requested_emails'] : null,
            partial: is_bool($data['partial'] ?? null) ? $data['partial'] : null,
            creditsRefunded: isset($data['credits_refunded']) ? (float) $data['credits_refunded'] : null,
            cacheDiscountPending: is_bool($pending) ? $pending : null,
        );
    }
}

/**
 * Data payload of a `job.failed` event.
 */
class JobFailedData
{
    public function __construct(
        public readonly string $jobId,
        public readonly ?string $status,
        public readonly ?int $totalEmails,
        public readonly ?int $processedEmails,
        public readonly ?string $error,
        public readonly ?string $failedAt,
        /** Addresses submitted. */
        public readonly ?int $requestedEmails = null,
        /** Every credit returned for the job so far. */
        public readonly ?float $creditsRefunded = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['job_id'] ?? '',
            status: $data['status'] ?? null,
            totalEmails: isset($data['total_emails']) ? (int) $data['total_emails'] : null,
            processedEmails: isset($data['processed_emails']) ? (int) $data['processed_emails'] : null,
            error: $data['error'] ?? null,
            failedAt: $data['failed_at'] ?? null,
            requestedEmails: isset($data['requested_emails']) ? (int) $data['requested_emails'] : null,
            creditsRefunded: isset($data['credits_refunded']) ? (float) $data['credits_refunded'] : null,
        );
    }
}

/**
 * Data payload of a `credits.low` event.
 *
 * On an account with white-label sub-accounts, the event about a
 * sub-account's balance goes to the parent's webhooks with `accountType`
 * "sub_account" and the sub-account's id, name and allocation. On an event
 * about the account's own balance those four are null.
 */
class CreditsLowData
{
    public function __construct(
        /** Fractional like every balance. */
        public readonly ?float $creditsRemaining,
        public readonly ?float $threshold,
        public readonly ?string $triggeredAt,
        /** "sub_account" when the balance is a sub-account's; null for the account's own. */
        public readonly ?string $accountType = null,
        public readonly ?string $subAccountId = null,
        public readonly ?string $subAccountName = null,
        /** Credits allocated to the sub-account; its threshold is a share of this. */
        public readonly ?float $creditsAllocated = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            creditsRemaining: isset($data['credits_remaining']) ? (float) $data['credits_remaining'] : null,
            threshold: isset($data['threshold']) ? (float) $data['threshold'] : null,
            triggeredAt: $data['triggered_at'] ?? null,
            accountType: $data['account_type'] ?? null,
            subAccountId: $data['sub_account_id'] ?? null,
            subAccountName: $data['sub_account_name'] ?? null,
            creditsAllocated: isset($data['credits_allocated']) ? (float) $data['credits_allocated'] : null,
        );
    }
}

/**
 * Data payload of a `credits.exhausted` event. Carries the same sub-account
 * fields as CreditsLowData when the balance is a sub-account's.
 */
class CreditsExhaustedData
{
    public function __construct(
        public readonly ?float $creditsRemaining,
        public readonly ?string $triggeredAt,
        public readonly ?string $accountType = null,
        public readonly ?string $subAccountId = null,
        public readonly ?string $subAccountName = null,
        public readonly ?float $creditsAllocated = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            creditsRemaining: isset($data['credits_remaining']) ? (float) $data['credits_remaining'] : null,
            triggeredAt: $data['triggered_at'] ?? null,
            accountType: $data['account_type'] ?? null,
            subAccountId: $data['sub_account_id'] ?? null,
            subAccountName: $data['sub_account_name'] ?? null,
            creditsAllocated: isset($data['credits_allocated']) ? (float) $data['credits_allocated'] : null,
        );
    }
}

/**
 * Data payload of a `blacklist.detected` event.
 */
class BlacklistDetectedData
{
    /**
     * @param string[] $blacklists
     * @param array<int, array<string, mixed>> $listedOn
     * @param array<string, mixed>|null $summary severity counts (critical/high/medium/low)
     */
    public function __construct(
        public readonly ?string $monitorId,
        public readonly ?string $domain,
        public readonly ?string $target,
        public readonly ?string $type,
        public readonly array $blacklists,
        public readonly array $listedOn,
        public readonly ?int $totalListed,
        public readonly ?array $summary,
        public readonly ?string $detectedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            monitorId: $data['monitor_id'] ?? null,
            domain: $data['domain'] ?? null,
            target: $data['target'] ?? null,
            type: $data['type'] ?? null,
            blacklists: is_array($data['blacklists'] ?? null) ? $data['blacklists'] : [],
            listedOn: is_array($data['listed_on'] ?? null) ? $data['listed_on'] : [],
            totalListed: isset($data['total_listed']) ? (int) $data['total_listed'] : null,
            summary: is_array($data['summary'] ?? null) ? $data['summary'] : null,
            detectedAt: $data['detected_at'] ?? null,
        );
    }
}

/**
 * SPF/DKIM/DMARC component status inside a `dmarc.failure` payload.
 * `policy` is only set on the DMARC component.
 */
class DmarcComponentStatus
{
    public function __construct(
        public readonly ?string $status,
        public readonly ?float $score,
        public readonly ?string $policy,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'] ?? null,
            score: isset($data['score']) ? (float) $data['score'] : null,
            policy: $data['policy'] ?? null,
        );
    }
}

/**
 * Data payload of a `dmarc.failure` event.
 */
class DmarcFailureData
{
    /**
     * @param array<int, mixed> $recommendations
     */
    public function __construct(
        public readonly ?string $monitorId,
        public readonly ?string $domain,
        public readonly ?string $alertType,
        public readonly ?string $issue,
        public readonly ?float $complianceScore,
        public readonly ?bool $compliant,
        public readonly ?DmarcComponentStatus $spf,
        public readonly ?DmarcComponentStatus $dkim,
        public readonly ?DmarcComponentStatus $dmarc,
        public readonly array $recommendations,
        public readonly ?string $detectedAt,
    ) {}

    public static function fromArray(array $data): self
    {
        $component = static fn (string $key): ?DmarcComponentStatus =>
            is_array($data[$key] ?? null) ? DmarcComponentStatus::fromArray($data[$key]) : null;

        return new self(
            monitorId: $data['monitor_id'] ?? null,
            domain: $data['domain'] ?? null,
            alertType: $data['alert_type'] ?? null,
            issue: $data['issue'] ?? null,
            complianceScore: isset($data['compliance_score']) ? (float) $data['compliance_score'] : null,
            compliant: isset($data['compliant']) ? (bool) $data['compliant'] : null,
            spf: $component('spf'),
            dkim: $component('dkim'),
            dmarc: $component('dmarc'),
            recommendations: is_array($data['recommendations'] ?? null) ? $data['recommendations'] : [],
            detectedAt: $data['detected_at'] ?? null,
        );
    }
}

/**
 * Webhook event envelope from Kawaa.
 *
 * Registered webhooks (POST /v1/webhooks, Settings -> Webhooks) deliver:
 *
 *   {event: "job.completed", data: {job_id, ..., summary: {...}},
 *    webhook_id, timestamp}
 *
 * The event-specific fields live under `data`; the typed accessors
 * (jobCompleted(), verificationCompleted(), ...) return a data object when
 * `event` matches, else null. Test deliveries set `test` and may omit some
 * data fields. The legacy flat per-job `webhook_url` callback body
 * ({event, job_id, status, results: {...}, requested_emails, ...}) is
 * normalized into the same envelope (with webhookId/timestamp null).
 */
class WebhookEvent
{
    /**
     * @param array<string, mixed> $data raw event-specific payload
     * @param array<string, mixed> $rawPayload complete raw JSON body
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
        public readonly ?string $webhookId,
        public readonly ?string $timestamp,
        public readonly bool $test,
        public readonly array $rawPayload,
    ) {}

    /**
     * Create from a webhook body (envelope or legacy flat callback).
     */
    public static function fromArray(array $raw): self
    {
        if (!isset($raw['data']) && array_key_exists('job_id', $raw)) {
            // Legacy per-job webhook_url callback: flat body with no `data`
            // object (absent or null), no envelope. The job_id KEY gates the
            // fallback (even when null), matching the other SDKs. Every field
            // but `event` and `results` moves under `data` as it is, null
            // included (a failure's `error` and `failed_at`,
            // `requested_emails`, `partial`, `credits_refunded`, ...):
            // dropping a null would make a present field read as an absent
            // one. `results` becomes the envelope's total_emails,
            // processed_emails and summary.
            $results = is_array($raw['results'] ?? null) ? $raw['results'] : null;
            $data = array_diff_key($raw, ['event' => true, 'data' => true, 'results' => true]);
            if ($results !== null) {
                if (isset($results['total'])) {
                    $data['total_emails'] = $results['total'];
                }
                if (isset($results['processed'])) {
                    $data['processed_emails'] = $results['processed'];
                }
                $data['summary'] = [
                    'valid' => $results['valid'] ?? 0,
                    'invalid' => $results['invalid'] ?? 0,
                    'risky' => $results['risky'] ?? 0,
                    'unknown' => $results['unknown'] ?? 0,
                ];
            }

            return new self(
                event: $raw['event'] ?? 'job.completed',
                data: $data,
                webhookId: null,
                timestamp: null,
                test: false,
                rawPayload: $raw,
            );
        }

        return new self(
            event: $raw['event'] ?? '',
            data: is_array($raw['data'] ?? null) ? $raw['data'] : [],
            webhookId: $raw['webhook_id'] ?? null,
            timestamp: $raw['timestamp'] ?? null,
            test: ($raw['test'] ?? false) === true,
            rawPayload: $raw,
        );
    }

    /** Typed data for `verification.completed` events, else null. */
    public function verificationCompleted(): ?VerificationCompletedData
    {
        return $this->event === 'verification.completed'
            ? VerificationCompletedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `verification.failed` events, else null. */
    public function verificationFailed(): ?VerificationFailedData
    {
        return $this->event === 'verification.failed'
            ? VerificationFailedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `job.completed` events, else null. */
    public function jobCompleted(): ?JobCompletedData
    {
        return $this->event === 'job.completed'
            ? JobCompletedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `job.failed` events, else null. */
    public function jobFailed(): ?JobFailedData
    {
        return $this->event === 'job.failed'
            ? JobFailedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `credits.low` events, else null. */
    public function creditsLow(): ?CreditsLowData
    {
        return $this->event === 'credits.low'
            ? CreditsLowData::fromArray($this->data)
            : null;
    }

    /** Typed data for `credits.exhausted` events, else null. */
    public function creditsExhausted(): ?CreditsExhaustedData
    {
        return $this->event === 'credits.exhausted'
            ? CreditsExhaustedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `blacklist.detected` events, else null. */
    public function blacklistDetected(): ?BlacklistDetectedData
    {
        return $this->event === 'blacklist.detected'
            ? BlacklistDetectedData::fromArray($this->data)
            : null;
    }

    /** Typed data for `dmarc.failure` events, else null. */
    public function dmarcFailure(): ?DmarcFailureData
    {
        return $this->event === 'dmarc.failure'
            ? DmarcFailureData::fromArray($this->data)
            : null;
    }
}

/**
 * Webhook utilities for Kawaa.
 */
class Webhook
{
    private const MISSING_SECRET_MESSAGE = 'Webhook secret is missing or blank, so the signature cannot be checked. '
        . 'Pass the signing secret for this webhook, or call Webhook::parseUnverified() '
        . 'for a per-job callback that was sent without a webhook_secret.';

    /** Default replay window, in seconds, for {@see Webhook::verifySignatureV2()}. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Whitespace trimmed around the X-Kawaa-Signature-V2 header and each of its
     * parts: only ASCII space and tab (HTTP's optional whitespace), the same in
     * every Kawaa SDK, so they accept and reject the same headers.
     */
    private const SIGNATURE_V2_WHITESPACE = " \t";

    private const LEGACY_SIGNATURE_REFUSED_MESSAGE = 'Webhook signature is a legacy X-Kawaa-Signature value, '
        . 'which signs no time and can be replayed. Pass the X-Kawaa-Signature-V2 header instead, '
        . 'or set allowLegacySignature: true to accept the legacy header.';

    private const LEGACY_ONLY_REQUEST_MESSAGE = 'Missing X-Kawaa-Signature-V2 header. The legacy '
        . 'X-Kawaa-Signature header alone is accepted only with allowLegacySignature: true, '
        . 'because it can be replayed.';

    /** True when $secret is a non-blank string that can key an HMAC. */
    private static function secretIsSet(?string $secret): bool
    {
        return $secret !== null && trim($secret) !== '';
    }

    /**
     * Refuse to build a handler that could never verify a signature.
     *
     * @throws \InvalidArgumentException
     */
    private static function requireHandlerSecret(?string $secret): void
    {
        if (!self::secretIsSet($secret)) {
            throw new \InvalidArgumentException(
                'Webhook secret is missing or blank. Set it to your webhook signing secret '
                . '(for example from KAWAA_WEBHOOK_SECRET); a handler without one would accept forged webhooks.'
            );
        }
    }

    /**
     * Refuse a negative replay window: a programming error, not bad request data.
     *
     * @throws \InvalidArgumentException
     */
    private static function requireTolerance(int $toleranceSeconds): void
    {
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException(
                "toleranceSeconds must be zero or a positive number of seconds, got {$toleranceSeconds}"
            );
        }
    }

    /**
     * Refuse a legacy opt-in that is not a real boolean. The parameter is
     * `mixed` on purpose: a `bool` type would let a caller without
     * strict_types pass `getenv('...')` and have the string "false" coerced
     * to true, silently accepting replayable signatures.
     *
     * @throws \TypeError
     */
    private static function requireAllowLegacySignature(mixed $allowLegacySignature): bool
    {
        if (!is_bool($allowLegacySignature)) {
            throw new \TypeError(
                'allowLegacySignature must be true or false, got ' . get_debug_type($allowLegacySignature)
            );
        }

        return $allowLegacySignature;
    }

    /**
     * Verify a legacy X-Kawaa-Signature webhook signature.
     *
     * The legacy header is an HMAC of the body alone. It signs no time, so a
     * captured delivery verifies here forever: anyone who records one request
     * can replay it. Prefer {@see Webhook::verifySignatureV2()}, which every
     * Kawaa delivery also carries. The verifying helpers ({@see Webhook::parse()},
     * {@see Webhook::laravelHandler()}, {@see Webhook::symfonyHandler()},
     * {@see Webhook::handleRequest()}) check the V2 header and use this one
     * only when you pass `allowLegacySignature: true`.
     *
     * @param string $payload Raw request body
     * @param string $signature Value of X-Kawaa-Signature header
     * @param string $secret Your webhook secret
     * @return bool True if signature is valid. Always false when the signature
     *              is empty or the secret is empty or blank: an HMAC keyed with
     *              an empty secret is something anyone can compute. Never
     *              throws on a malformed or non-ASCII signature: it is false.
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        if ($signature === '' || !self::secretIsSet($secret)) {
            return false;
        }

        // Strip "sha256=" prefix if present
        $sig = $signature;
        if (str_starts_with($sig, 'sha256=')) {
            $sig = substr($sig, 7);
        }

        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $sig);
    }

    /**
     * Verify the timestamped X-Kawaa-Signature-V2 header of a webhook delivery.
     *
     * The header looks like `t=1790000000,v1=3320cbcf...`: `t` is the Unix
     * time of the delivery attempt in seconds and `v1` is the hex
     * HMAC-SHA256, keyed with the same secret as X-Kawaa-Signature, of the `t`
     * value exactly as sent, a ".", then the raw request body. Unlike
     * {@see Webhook::verifySignature()}, this rejects a delivery whose `t` is
     * more than $toleranceSeconds away from now, so a captured request cannot
     * be replayed later. Every delivery attempt (automatic retry, manual
     * retry, test delivery) carries a fresh `t` and signature. When the header
     * holds several `v1` entries, any one matching is enough; unknown keys are
     * ignored.
     *
     * @param string $payload Raw request body, exactly as received (do not
     *                        re-encode parsed JSON)
     * @param string|null $signatureHeader Value of the X-Kawaa-Signature-V2 header
     * @param string|null $secret Your webhook secret
     * @param int $toleranceSeconds Largest accepted distance between `t` and
     *                              the current time, in either direction
     * @return bool True only when the header is well formed, `t` is inside the
     *              window and a `v1` signature matches. False for any bad
     *              input, including a missing header or a missing or blank secret.
     * @throws \InvalidArgumentException If $toleranceSeconds is negative
     *
     * @example
     * ```php
     * $payload = file_get_contents('php://input');
     * $valid = Webhook::verifySignatureV2(
     *     $payload,
     *     $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] ?? null,
     *     getenv('KAWAA_WEBHOOK_SECRET') ?: null,
     * );
     * ```
     */
    public static function verifySignatureV2(
        string $payload,
        ?string $signatureHeader,
        ?string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        self::requireTolerance($toleranceSeconds);
        if ($signatureHeader === null || $secret === null || !self::secretIsSet($secret)) {
            return false;
        }

        $parsed = self::parseSignatureV2Header($signatureHeader);
        if ($parsed === null) {
            return false;
        }

        [$timestamp, $candidates] = $parsed;
        $signedAt = (int) $timestamp;
        $now = time();
        if ($now - $signedAt > $toleranceSeconds || $signedAt - $now > $toleranceSeconds) {
            return false;
        }

        // Sign the bytes as received: the literal t, ".", then the raw body.
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, strtolower($candidate))) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * Split an X-Kawaa-Signature-V2 value into its `t` string and `v1` values.
     *
     * @return array{0: string, 1: list<string>}|null null when the header is malformed
     */
    private static function parseSignatureV2Header(string $signatureHeader): ?array
    {
        $header = trim($signatureHeader, self::SIGNATURE_V2_WHITESPACE);
        if ($header === '') {
            return null;
        }

        $timestamps = [];
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part, self::SIGNATURE_V2_WHITESPACE);
            $separator = strpos($part, '=');
            if ($separator === false) {
                return null;
            }

            $key = substr($part, 0, $separator);
            $value = substr($part, $separator + 1);
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1') {
                if (preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
                    return null;
                }
                $candidates[] = $value;
            }
        }

        if (count($timestamps) !== 1 || preg_match('/\A[0-9]{1,15}\z/', $timestamps[0]) !== 1 || $candidates === []) {
            return null;
        }

        return [$timestamps[0], $candidates];
    }

    /** True for a value shaped like the legacy header (`sha256=...` or bare 64 hex). */
    private static function isLegacySignatureValue(string $value): bool
    {
        return str_starts_with($value, 'sha256=') || preg_match('/\A[0-9a-fA-F]{64}\z/', $value) === 1;
    }

    /**
     * Check one signature value the way {@see Webhook::parse()} does: a V2
     * value against the V2 header rules and window, a legacy-shaped value only
     * when legacy signatures are allowed.
     *
     * @throws ValidationException When the signature does not check out
     */
    private static function checkSignatureValue(
        string $payload,
        string $signature,
        string $secret,
        int $toleranceSeconds,
        bool $allowLegacySignature,
    ): void {
        if (self::isLegacySignatureValue($signature)) {
            if (!$allowLegacySignature) {
                throw new ValidationException(self::LEGACY_SIGNATURE_REFUSED_MESSAGE);
            }
            if (!self::verifySignature($payload, $signature, $secret)) {
                throw new ValidationException('Invalid webhook signature');
            }

            return;
        }
        if (!self::verifySignatureV2($payload, $signature, $secret, $toleranceSeconds)) {
            throw new ValidationException('Invalid or expired webhook signature');
        }
    }

    /**
     * Check a request's signature headers for the request handlers. A request
     * with an X-Kawaa-Signature-V2 header (any value, even empty) is checked
     * against it alone; the legacy header is read only when that header is
     * absent and legacy signatures are allowed.
     *
     * @param string|null $v2Header X-Kawaa-Signature-V2, null when the request has none
     * @param string|null $legacyHeader X-Kawaa-Signature, null when the request has none
     * @throws ValidationException When the signature does not check out
     */
    private static function checkRequestSignature(
        string $payload,
        ?string $v2Header,
        ?string $legacyHeader,
        string $secret,
        int $toleranceSeconds,
        bool $allowLegacySignature,
    ): void {
        if ($v2Header !== null) {
            if (!self::verifySignatureV2($payload, $v2Header, $secret, $toleranceSeconds)) {
                throw new ValidationException('Invalid or expired webhook signature');
            }

            return;
        }
        if ($legacyHeader === null || $legacyHeader === '') {
            throw new ValidationException('Webhook signature missing');
        }
        if (!$allowLegacySignature) {
            throw new ValidationException(self::LEGACY_ONLY_REQUEST_MESSAGE);
        }
        if (!self::verifySignature($payload, $legacyHeader, $secret)) {
            throw new ValidationException('Invalid webhook signature');
        }
    }

    /**
     * One header value as a string: null when absent, the first value when a
     * framework hands over a list, and '' for anything else (so a present but
     * unreadable header still counts as present and fails verification).
     */
    private static function headerValue(mixed $value): ?string
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }
            $value = reset($value);
        }
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Verify and parse a webhook payload.
     *
     * The signature is always checked: a missing or blank secret or a missing
     * signature throws instead of skipping verification. Pass the value of the
     * X-Kawaa-Signature-V2 header: it is checked against the body, and its `t`
     * must be within $toleranceSeconds (300 by default) of the current time,
     * so a captured delivery cannot be replayed later.
     *
     * A legacy X-Kawaa-Signature value (`sha256=<hex>`) is refused unless you
     * pass `allowLegacySignature: true`. For a per-job webhook_url callback
     * sent without a webhook_secret (those are unsigned), call
     * {@see Webhook::parseUnverified()}.
     *
     * @param string $payload Raw request body (JSON string)
     * @param string|null $signature Value of the X-Kawaa-Signature-V2 header
     *                               (or, with $allowLegacySignature, of the
     *                               legacy X-Kawaa-Signature header)
     * @param string|null $secret Your webhook secret
     * @param int $toleranceSeconds Largest accepted distance between the V2
     *                              header's `t` and the current time, in
     *                              either direction (default 300,
     *                              {@see Webhook::DEFAULT_TOLERANCE_SECONDS})
     * @param bool $allowLegacySignature (must be a real bool) Also accept a legacy X-Kawaa-Signature
     *                                   value. Off by default. REPLAYABLE: that
     *                                   header signs no time, so a request
     *                                   accepted through it can be a replay of
     *                                   any delivery ever captured. Turn it on
     *                                   only for a sender that cannot send
     *                                   X-Kawaa-Signature-V2; every Kawaa
     *                                   delivery sends both. A V2 value is
     *                                   always checked against the V2 rules
     *                                   and window, whatever this says.
     * @return WebhookEvent
     * @throws ValidationException If the secret is missing or blank, the
     *                             signature is missing, wrong, outside the
     *                             window or a refused legacy value, or the
     *                             body is not JSON
     * @throws \InvalidArgumentException If $toleranceSeconds is negative
     * @throws \TypeError If $allowLegacySignature is not a bool (a string
     *                    such as "false" is refused, not coerced)
     *
     * @example
     * ```php
     * $event = Webhook::parse(
     *     (string) file_get_contents('php://input'),
     *     $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] ?? null,
     *     (string) getenv('KAWAA_WEBHOOK_SECRET'),
     * );
     * ```
     */
    public static function parse(
        string $payload,
        ?string $signature = null,
        ?string $secret = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        mixed $allowLegacySignature = false,
    ): WebhookEvent {
        self::requireTolerance($toleranceSeconds);
        $allowLegacySignature = self::requireAllowLegacySignature($allowLegacySignature);
        if (!self::secretIsSet($secret)) {
            throw new ValidationException(self::MISSING_SECRET_MESSAGE);
        }
        if ($signature === null || $signature === '') {
            throw new ValidationException('Webhook signature missing');
        }
        self::checkSignatureValue($payload, $signature, (string) $secret, $toleranceSeconds, $allowLegacySignature);

        return self::parseUnverified($payload);
    }

    /**
     * Parse a webhook payload WITHOUT checking its signature.
     *
     * Use this only for a per-job webhook_url callback you created without a
     * webhook_secret (Kawaa sends those unsigned), or for a body whose
     * signature you already checked with {@see Webhook::verifySignatureV2()}.
     * Anyone who knows your endpoint URL can send a body that parses here.
     *
     * @param string $payload Raw request body (JSON string)
     * @return WebhookEvent
     * @throws ValidationException If the body is not JSON
     */
    public static function parseUnverified(string $payload): WebhookEvent
    {
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            throw new ValidationException('Invalid webhook payload: not valid JSON');
        }

        return WebhookEvent::fromArray($data);
    }

    /**
     * Create a webhook handler middleware for Laravel.
     *
     * Each request is checked against its X-Kawaa-Signature-V2 header, and
     * its `t` must be within $toleranceSeconds (300 by default) of the
     * current time, so a captured delivery cannot be replayed later. A
     * request with only the legacy X-Kawaa-Signature header is refused unless
     * you pass `allowLegacySignature: true`; a request that carries the V2
     * header is checked against it alone, whatever that option says.
     *
     * @param string $secret Webhook secret
     * @param int $toleranceSeconds Largest accepted distance between the V2
     *                              header's `t` and the current time, in
     *                              either direction (default 300)
     * @param bool $allowLegacySignature (must be a real bool) Also accept a request signed only with
     *                                   the legacy X-Kawaa-Signature header.
     *                                   Off by default. REPLAYABLE: that header
     *                                   signs no time, so a request accepted
     *                                   through it can be a replay of any
     *                                   delivery ever captured. Turn it on only
     *                                   for a sender that cannot send
     *                                   X-Kawaa-Signature-V2. A request that
     *                                   carries X-Kawaa-Signature-V2 is always
     *                                   checked against it.
     * @return callable The handler throws a ValidationException when the
     *                  signature does not check out or the body is not JSON
     * @throws \InvalidArgumentException If $secret is empty or blank, so an
     *                                   unset environment variable fails at
     *                                   boot instead of letting forged webhooks
     *                                   through, or if $toleranceSeconds is negative
     * @throws \TypeError If $allowLegacySignature is not a bool (a string
     *                    such as "false" is refused, not coerced)
     *
     * @example
     * ```php
     * // In routes/api.php
     * Route::post('/webhook', function (Request $request) {
     *     $handler = Webhook::laravelHandler('your-secret');
     *     $event = $handler($request);
     *     // Handle event...
     * });
     * ```
     */
    public static function laravelHandler(
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        mixed $allowLegacySignature = false,
    ): callable {
        self::requireHandlerSecret($secret);
        self::requireTolerance($toleranceSeconds);
        $allowLegacySignature = self::requireAllowLegacySignature($allowLegacySignature);

        return function ($request) use ($secret, $toleranceSeconds, $allowLegacySignature): WebhookEvent {
            $payload = $request->getContent();
            self::checkRequestSignature(
                $payload,
                self::headerValue($request->header('X-Kawaa-Signature-V2')),
                self::headerValue($request->header('X-Kawaa-Signature')),
                $secret,
                $toleranceSeconds,
                $allowLegacySignature,
            );

            return self::parseUnverified($payload);
        };
    }

    /**
     * Create a webhook handler for Symfony.
     *
     * Requests are checked exactly as {@see Webhook::laravelHandler()} checks
     * them: against X-Kawaa-Signature-V2 and its window, and against the
     * legacy X-Kawaa-Signature header only with `allowLegacySignature: true`
     * and only when the request has no V2 header.
     *
     * @param string $secret Webhook secret
     * @param int $toleranceSeconds Largest accepted distance between the V2
     *                              header's `t` and the current time, in
     *                              either direction (default 300)
     * @param bool $allowLegacySignature (must be a real bool) Also accept a request signed only with
     *                                   the legacy X-Kawaa-Signature header.
     *                                   Off by default. REPLAYABLE: that header
     *                                   signs no time, so a request accepted
     *                                   through it can be a replay of any
     *                                   delivery ever captured. Turn it on only
     *                                   for a sender that cannot send
     *                                   X-Kawaa-Signature-V2. A request that
     *                                   carries X-Kawaa-Signature-V2 is always
     *                                   checked against it.
     * @return callable The handler throws a ValidationException when the
     *                  signature does not check out or the body is not JSON
     * @throws \InvalidArgumentException If $secret is empty or blank, so an
     *                                   unset environment variable fails at
     *                                   boot instead of letting forged webhooks
     *                                   through, or if $toleranceSeconds is negative
     * @throws \TypeError If $allowLegacySignature is not a bool (a string
     *                    such as "false" is refused, not coerced)
     *
     * @example
     * ```php
     * // In controller
     * public function webhookAction(Request $request): Response
     * {
     *     $handler = Webhook::symfonyHandler('your-secret');
     *     $event = $handler($request);
     *     // Handle event...
     * }
     * ```
     */
    public static function symfonyHandler(
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        mixed $allowLegacySignature = false,
    ): callable {
        self::requireHandlerSecret($secret);
        self::requireTolerance($toleranceSeconds);
        $allowLegacySignature = self::requireAllowLegacySignature($allowLegacySignature);

        return function ($request) use ($secret, $toleranceSeconds, $allowLegacySignature): WebhookEvent {
            $payload = $request->getContent();
            self::checkRequestSignature(
                $payload,
                self::headerValue($request->headers->get('X-Kawaa-Signature-V2')),
                self::headerValue($request->headers->get('X-Kawaa-Signature')),
                $secret,
                $toleranceSeconds,
                $allowLegacySignature,
            );

            return self::parseUnverified($payload);
        };
    }

    /**
     * Create a simple webhook handler for vanilla PHP.
     *
     * Reads the body from php://input and the headers from $_SERVER, and
     * checks them exactly as {@see Webhook::laravelHandler()} does: against
     * X-Kawaa-Signature-V2 and its window, and against the legacy
     * X-Kawaa-Signature header only with `allowLegacySignature: true` and only
     * when the request has no V2 header.
     *
     * @param string $secret Webhook secret
     * @param int $toleranceSeconds Largest accepted distance between the V2
     *                              header's `t` and the current time, in
     *                              either direction (default 300)
     * @param bool $allowLegacySignature (must be a real bool) Also accept a request signed only with
     *                                   the legacy X-Kawaa-Signature header.
     *                                   Off by default. REPLAYABLE: that header
     *                                   signs no time, so a request accepted
     *                                   through it can be a replay of any
     *                                   delivery ever captured. Turn it on only
     *                                   for a sender that cannot send
     *                                   X-Kawaa-Signature-V2. A request that
     *                                   carries X-Kawaa-Signature-V2 is always
     *                                   checked against it.
     * @return WebhookEvent
     * @throws ValidationException If the secret is empty or blank, the
     *                             signature is missing, wrong, outside the
     *                             window or a refused legacy header, or the
     *                             body is not JSON
     * @throws \InvalidArgumentException If $toleranceSeconds is negative
     * @throws \TypeError If $allowLegacySignature is not a bool (a string
     *                    such as "false" is refused, not coerced)
     *
     * @example
     * ```php
     * // webhook.php
     * $event = Webhook::handleRequest('your-secret');
     * if ($job = $event->jobCompleted()) {
     *     echo "Job {$job->jobId} completed!";
     * }
     * ```
     */
    public static function handleRequest(
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        mixed $allowLegacySignature = false,
    ): WebhookEvent {
        self::requireTolerance($toleranceSeconds);
        $allowLegacySignature = self::requireAllowLegacySignature($allowLegacySignature);
        if (!self::secretIsSet($secret)) {
            throw new ValidationException(self::MISSING_SECRET_MESSAGE);
        }

        $payload = file_get_contents('php://input');
        if ($payload === false) {
            throw new ValidationException('Could not read request body');
        }

        self::checkRequestSignature(
            $payload,
            self::headerValue($_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] ?? null),
            self::headerValue($_SERVER['HTTP_X_KAWAA_SIGNATURE'] ?? null),
            $secret,
            $toleranceSeconds,
            $allowLegacySignature,
        );

        return self::parseUnverified($payload);
    }
}
