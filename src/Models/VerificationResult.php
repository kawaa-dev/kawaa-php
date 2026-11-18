<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Result of a single email verification.
 */
class VerificationResult
{
    public function __construct(
        public readonly string $email,
        public readonly VerificationStatus $status,
        public readonly int $score,
        public readonly VerificationFlags $flags,
        public readonly ?string $suggestion = null,
        public readonly ?VerificationDetails $verification = null,
        public readonly ?string $reason = null,
        /** Machine-readable reason explaining how status was reached (e.g. "mailbox_reject"). */
        public readonly ?string $subStatus = null,
        /** Raw wire status (preserved even when status falls back to UNKNOWN). */
        public readonly ?string $rawStatus = null,
        /** Job handle for polling; present when status is PENDING. */
        public readonly ?string $jobId = null,
        /** Human-readable note (present when status is PENDING). */
        public readonly ?string $message = null,
        /**
         * Credits this verification charged: 1 fresh, 0.5 from cache. Null when
         * the API did not report it.
         *
         * On an idempotent replay this is what the ORIGINAL request's
         * verification cost, read back from its job (as FindResult::$creditsUsed
         * reports the search's cost on a replay). Null when that cost is not
         * known: the result could not be read back (the status is still
         * PENDING), the job read carried no charge, or the job's cache discount
         * was still being applied after the short poll. Read the job by $jobId
         * for the charge. The replay's own credits_used of 0 is what the repeat
         * cost, so it is never reported as the cost.
         */
        public readonly ?float $creditsUsed = null,
        /** The balance after the charge; null when the API omitted it. */
        public readonly ?float $creditsRemaining = null,
        /** Credits held rather than settled, present while the status is PENDING. */
        public readonly ?float $creditsHeld = null,
        /**
         * True when this response repeated a request the API had already
         * accepted under the same Idempotency-Key, whether this client's own
         * retry after a lost response (a timeout, a dropped connection, a 5xx)
         * or a caller key used twice, and so charged nothing more.
         *
         * The API answers such a repeat with a PENDING pointer to the original
         * job; verify() reads that job back (a short, bounded poll) and returns
         * its result. When it cannot, because the job is still running or the
         * key may not read jobs (verification:read), the status is PENDING,
         * $creditsUsed is null and $jobId is the job to poll: the original
         * charge stands on that job. Null when the API did not say.
         */
        public readonly ?bool $idempotentReplay = null,
        /**
         * True when the API answered from its verification cache (charged
         * 0.5) instead of checking the mailbox again.
         */
        public readonly bool $fromCache = false,
        /**
         * How old a cached result is, in seconds. Set on every cache hit,
         * beside $fromCache; null otherwise. Without it a result reused from
         * days ago reads exactly like one measured this second.
         */
        public readonly ?int $cacheAgeSeconds = null,
        /**
         * When the verdict was measured (ISO 8601). On a cache hit it is the
         * time of the original check, not of this call.
         */
        public readonly ?string $verifiedAt = null,
        /**
         * Name, gender and country hints, attached when the request set
         * `enrich` and the plan includes data enrichment (Business and up).
         * Null when the request did not ask for it, and when the API knows
         * nothing about the address, which is not a judgement about it.
         */
        public readonly ?Enrichment $enrichment = null,
        /**
         * Whether the answer said if it came from the cache. A download row
         * (downloadResults() in JSON) and a PENDING answer do not: $fromCache
         * is then false without meaning "checked fresh", and this is false.
         * getJobStatus($jobId, true) rows and finished verify() answers say.
         */
        public readonly bool $fromCacheStated = true,
        /**
         * $email with an internationalized domain written back in its
         * standard Unicode spelling ('info@müller.de' where $email is
         * 'info@xn--mller-kva.de'), the same as $email for every other
         * address (email_unicode). The API checks and returns the ASCII form,
         * so this is what to match a row to your own list by, ignoring case.
         * Set on getJobStatus($jobId, true) rows and downloadResults() rows
         * (every row from November 17, 2026); null on older rows and on a
         * single verify() answer.
         */
        public readonly ?string $emailUnicode = null,
    ) {}

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        // Handle flat structure or nested flags
        $flagsData = $data['flags'] ?? [];
        if (empty($flagsData) && isset($data['disposable'])) {
            $flagsData = [
                'disposable' => $data['disposable'] ?? false,
                'role' => $data['role_account'] ?? $data['role'] ?? false,
                'free_provider' => $data['free_provider'] ?? false,
                'catch_all' => $data['catch_all'] ?? false,
            ];
        }

        $verificationData = $data['verification'] ?? null;
        // Flat download rows (GET /v1/jobs/{id}/download) carry mx_records /
        // smtp_response / catch_all at the TOP level; capture them when the
        // nested object is absent, so toArray() writes the catch-all finding
        // where fromArray() reads it back.
        if ($verificationData === null
            && (array_key_exists('mx_records', $data) || array_key_exists('smtp_response', $data)
                || is_bool($data['catch_all'] ?? null))) {
            $verificationData = [
                'mx_records' => $data['mx_records'] ?? [],
                'smtp_response' => $data['smtp_response'] ?? null,
            ];
            if (is_bool($data['catch_all'] ?? null)) {
                $verificationData['catch_all'] = $data['catch_all'];
            }
        }
        $rawStatus = $data['status'] ?? 'unknown';
        $status = VerificationStatus::tryFrom($rawStatus) ?? VerificationStatus::UNKNOWN;

        // flags.catch_all is optional on the wire; the authoritative locations
        // are verification.catch_all and status === "catch_all".
        if (empty($flagsData['catch_all'])) {
            $derivedCatchAll = (bool)($verificationData['catch_all'] ?? false)
                || $status === VerificationStatus::CATCH_ALL;
            if ($derivedCatchAll) {
                $flagsData['catch_all'] = true;
            }
        }
        // Where the API states its catch-all finding: verification.catch_all,
        // or catch_all at the top level of a flat download row. It leaves the
        // finding out when nothing measured it, so only a stated false (or a
        // positive signal) is a measurement. A flags.catch_all of false is
        // not: the API does not send that key, and a stored row from an older
        // worker could carry it unmeasured.
        $rawVerification = $data['verification'] ?? null;
        $catchAllFinding = is_array($rawVerification)
            ? ($rawVerification['catch_all'] ?? null)
            : ($data['catch_all'] ?? null);
        $catchAllMeasured = !empty($flagsData['catch_all']) || $catchAllFinding === false;
        if ($status === VerificationStatus::DISPOSABLE && empty($flagsData['disposable'])) {
            $flagsData['disposable'] = true;
        }
        if ($status === VerificationStatus::ROLE && empty($flagsData['role']) && empty($flagsData['role_account'])) {
            $flagsData['role'] = true;
        }
        if ($status === VerificationStatus::SPAM_TRAP && empty($flagsData['spam_trap'])) {
            $flagsData['spam_trap'] = true;
        }

        return new self(
            email: $data['email'] ?? '',
            status: $status,
            score: $data['quality_score'] ?? $data['score'] ?? 0,
            flags: VerificationFlags::fromArray($flagsData, $catchAllMeasured),
            suggestion: $data['suggestion'] ?? null,
            verification: $verificationData ? VerificationDetails::fromArray($verificationData) : null,
            reason: $data['reason'] ?? null,
            subStatus: $data['sub_status'] ?? null,
            rawStatus: is_string($rawStatus) ? $rawStatus : null,
            jobId: $data['job_id'] ?? null,
            message: $data['message'] ?? null,
            creditsUsed: self::amount($data['credits_used'] ?? null),
            creditsRemaining: self::amount($data['credits_remaining'] ?? null),
            creditsHeld: self::amount($data['credits_held'] ?? null),
            // Actual booleans only, as on FindResult: a coercion would invert
            // "charged nothing" into "charged".
            idempotentReplay: is_bool($data['idempotent_replay'] ?? null)
                ? $data['idempotent_replay']
                : null,
            // Read leniently, like the credit figures: the verification is
            // charged before it is parsed, and a value of the wrong type is
            // "not stated", never an error that drives a paid retry.
            fromCache: ($data['from_cache'] ?? null) === true,
            cacheAgeSeconds: is_int($data['cache_age_seconds'] ?? null) ? $data['cache_age_seconds'] : null,
            verifiedAt: is_string($data['verified_at'] ?? null) ? $data['verified_at'] : null,
            enrichment: is_array($data['enrichment'] ?? null) ? Enrichment::fromArray($data['enrichment']) : null,
            // toArray() writes from_cache_stated, so a round trip keeps "not
            // stated" instead of reading its false back as "checked fresh".
            fromCacheStated: is_bool($data['from_cache'] ?? null) && ($data['from_cache_stated'] ?? null) !== false,
            emailUnicode: is_string($data['email_unicode'] ?? null) ? $data['email_unicode'] : null,
        );
    }

    /**
     * A credit amount: any JSON number (whole or fractional) as a float;
     * absent or not a number is null.
     */
    private static function amount(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * Check if email is valid.
     */
    public function isValid(): bool
    {
        return $this->status === VerificationStatus::VALID;
    }

    /**
     * Check if status is valid.
     */
    public function isDeliverable(): bool
    {
        return $this->status === VerificationStatus::VALID;
    }

    /**
     * True when POST /v1/verify timed out and returned a job handle
     * (poll getJobStatus(jobId) for the final result).
     */
    public function isPending(): bool
    {
        return $this->status === VerificationStatus::PENDING;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'status' => $this->status->value,
            'score' => $this->score,
            'flags' => $this->flags->toArray(),
            'suggestion' => $this->suggestion,
            'verification' => $this->verification?->toArray(),
            'reason' => $this->reason,
            'sub_status' => $this->subStatus,
            'raw_status' => $this->rawStatus,
            'job_id' => $this->jobId,
            'message' => $this->message,
            'credits_used' => $this->creditsUsed,
            'credits_remaining' => $this->creditsRemaining,
            'credits_held' => $this->creditsHeld,
            'idempotent_replay' => $this->idempotentReplay,
            'from_cache' => $this->fromCache,
            'cache_age_seconds' => $this->cacheAgeSeconds,
            'verified_at' => $this->verifiedAt,
            'enrichment' => $this->enrichment?->toArray(),
            'from_cache_stated' => $this->fromCacheStated,
            'email_unicode' => $this->emailUnicode,
        ];
    }
}
