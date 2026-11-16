<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Status of a batch verification job.
 *
 * Counts are sourced from the API's `summary: {valid, invalid, risky,
 * unknown}` object (risky includes catch_all/role/spam_trap; invalid
 * includes disposable).
 */
class JobStatusResult
{
    /**
     * @param array<VerificationResult> $results One page of results (the API
     *   pages 100 at a time); $nextKey/$hasMore describe further pages. Use
     *   downloadResults() to fetch everything.
     */
    public function __construct(
        public readonly string $jobId,
        public readonly JobStatus $status,
        public readonly int $totalEmails,
        public readonly int $processedEmails,
        public readonly int $validCount,
        public readonly int $invalidCount,
        public readonly int $riskyCount,
        public readonly int $unknownCount,
        public readonly float $progressPercent,
        public readonly ?string $createdAt = null,
        public readonly ?string $completedAt = null,
        public readonly array $results = [],
        public readonly ?string $nextKey = null,
        public readonly bool $hasMore = false,
        /**
         * Where the money stands: charged, refund_pending, refunded or
         * refund_failed. Null on a job whose bill the API has not published.
         *
         * Last, so that positional construction keeps working: this is a new
         * parameter on a constructor callers already pass `results` to by
         * position, and inserting it earlier would bind their array to it.
         */
        public readonly ?string $billingStatus = null,
        /**
         * Set only when a read tried to apply the per-address cache discount
         * and could not, almost always because the results index had not
         * caught up. The next read retries it, and until then the bill is not
         * final.
         */
        public readonly bool $cacheDiscountPending = false,
        /** What the job was charged (the job's credits_used), before any refund. */
        public readonly ?float $creditsCharged = null,
        /**
         * What has come back since. The job cost $creditsCharged -
         * $creditsRefunded, both read from the job.
         */
        public readonly ?float $creditsRefunded = null,
        /**
         * Why the job ended as it did, in the API's own words
         * (`error_message`), for example on a failed job. Null when the API
         * gave no reason.
         */
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        $results = [];
        if (isset($data['results']) && is_array($data['results'])) {
            $results = array_map(
                fn(array $r) => VerificationResult::fromArray($r),
                $data['results']
            );
        }

        // Counts live under `summary`; fall back to legacy flat *_count fields.
        $summary = $data['summary'] ?? [];

        return new self(
            jobId: $data['job_id'] ?? '',
            status: JobStatus::tryFrom($data['status'] ?? 'pending') ?? JobStatus::PENDING,
            totalEmails: $data['total_emails'] ?? 0,
            processedEmails: $data['processed_emails'] ?? 0,
            validCount: $summary['valid'] ?? $data['valid_count'] ?? 0,
            invalidCount: $summary['invalid'] ?? $data['invalid_count'] ?? 0,
            riskyCount: $summary['risky'] ?? $data['risky_count'] ?? 0,
            unknownCount: $summary['unknown'] ?? $data['unknown_count'] ?? 0,
            progressPercent: (float)($data['progress_percent'] ?? 0.0),
            createdAt: $data['created_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            billingStatus: $data['billing_status'] ?? null,
            cacheDiscountPending: ($data['cache_discount_pending'] ?? false) === true,
            results: $results,
            nextKey: $data['next_key'] ?? null,
            hasMore: (bool)($data['has_more'] ?? false),
            creditsCharged: self::amount($data['credits_used'] ?? null),
            creditsRefunded: self::amount($data['credits_refunded'] ?? null),
            errorMessage: is_string($data['error_message'] ?? null) ? $data['error_message'] : null,
        );
    }

    /** A credit amount: any JSON number as a float; anything else is null. */
    private static function amount(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * Check if job is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === JobStatus::COMPLETED;
    }

    /**
     * Check if job failed.
     */
    public function isFailed(): bool
    {
        return $this->status === JobStatus::FAILED;
    }

    /**
     * Check if job was cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === JobStatus::CANCELLED;
    }

    /**
     * Whether the job's BILL is final.
     *
     * Completed and failed imply it. A cancelled job does not: its verdicts
     * are final the moment it reads "cancelled", because the worker commits
     * every result under a condition on the running statuses and the cancel
     * moves the job out of them, but the refund for the addresses it never
     * checked waits for the in-flight ones to time out, a few minutes later.
     * `completed_at` is the API's own "the books are closed" marker: a cancel
     * leaves it null, and every path that settles the job sets it.
     *
     * Results do not need this; act on them as soon as the status is terminal.
     * Read this only if you are reconciling the charge.
     */
    // A refund the API has not applied yet is money still owed, whatever the
    // status says: a stale single verification is reconciled to "failed" with
    // billing_status "refund_pending", and the credit comes back on a LATER
    // read. So the billing status has a veto here.
    public function isSettled(): bool
    {
        if ($this->billingStatus === 'refund_pending' || $this->cacheDiscountPending) {
            return false;
        }

        if ($this->isCompleted() || $this->isFailed()) {
            return true;
        }

        return $this->isCancelled() && $this->completedAt !== null;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'status' => $this->status->value,
            'total_emails' => $this->totalEmails,
            'processed_emails' => $this->processedEmails,
            'valid_count' => $this->validCount,
            'invalid_count' => $this->invalidCount,
            'risky_count' => $this->riskyCount,
            'unknown_count' => $this->unknownCount,
            'progress_percent' => $this->progressPercent,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
            'billing_status' => $this->billingStatus,
            'cache_discount_pending' => $this->cacheDiscountPending,
            'results' => array_map(fn($r) => $r->toArray(), $this->results),
            'next_key' => $this->nextKey,
            'has_more' => $this->hasMore,
            'credits_charged' => $this->creditsCharged,
            'credits_refunded' => $this->creditsRefunded,
            'error_message' => $this->errorMessage,
        ];
    }
}
