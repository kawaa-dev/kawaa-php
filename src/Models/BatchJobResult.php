<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Result of creating a batch verification job.
 *
 * Credit amounts are floats: a batch's charge can be fractional once cached
 * addresses are billed at 0.5. A figure the API did not send as a number is
 * null (unknown), never 0.
 */
class BatchJobResult
{
    public function __construct(
        public readonly string $jobId,
        public readonly JobStatus $status,
        public readonly int $totalSubmitted,
        public readonly int $duplicatesRemoved,
        public readonly int $uniqueEmails,
        /**
         * What the batch was charged when the API accepted it. Null when it
         * is not known: the response carried no number, or it was an
         * idempotent replay (see $idempotentReplay).
         */
        public readonly ?float $creditsUsed,
        /**
         * The balance after the original charge. Null when the API omitted it
         * (a replay whose ledger snapshot could not be safely read omits it),
         * never 0.
         */
        public readonly ?float $creditsRemaining,
        public readonly ?string $message = null,
        /** Credits held rather than settled after an ambiguous enqueue. */
        public readonly ?float $creditsHeld = null,
        /**
         * True when this response repeated a batch the API had already
         * accepted under the same Idempotency-Key: this client's own retry
         * after a lost response, or a caller key used again. Nothing more was
         * charged. The API answers such a repeat with credits_used 0, which is
         * what the repeat cost, not what the batch cost, so $creditsUsed is
         * null then. The batch's charge and refunds are on its job:
         * getJobStatus($jobId) reports creditsCharged and creditsRefunded.
         * Null when the API did not say.
         */
        public readonly ?bool $idempotentReplay = null,
    ) {}

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        // Actual booleans only: a coercion would invert "charged nothing
        // more" into "charged".
        $replay = is_bool($data['idempotent_replay'] ?? null) ? $data['idempotent_replay'] : null;

        return new self(
            jobId: $data['job_id'] ?? '',
            status: JobStatus::tryFrom($data['status'] ?? 'pending') ?? JobStatus::PENDING,
            totalSubmitted: $data['total_submitted'] ?? 0,
            duplicatesRemoved: $data['duplicates_removed'] ?? 0,
            uniqueEmails: $data['unique_emails'] ?? 0,
            // A replay's credits_used is what the repeat cost, not the batch.
            creditsUsed: $replay === true ? null : self::amount($data['credits_used'] ?? null),
            creditsRemaining: self::amount($data['credits_remaining'] ?? null),
            message: $data['message'] ?? null,
            creditsHeld: self::amount($data['credits_held'] ?? null),
            idempotentReplay: $replay,
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
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'status' => $this->status->value,
            'total_submitted' => $this->totalSubmitted,
            'duplicates_removed' => $this->duplicatesRemoved,
            'unique_emails' => $this->uniqueEmails,
            'credits_used' => $this->creditsUsed,
            'credits_remaining' => $this->creditsRemaining,
            'message' => $this->message,
            'credits_held' => $this->creditsHeld,
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }
}
