<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Domain search result (GET /v1/domain/{domain}).
 *
 * Response shape: {domain, emails, email_count, status?, progress?,
 * progress_percent?, job_id?, patterns_checked?, completion_reason?,
 * catch_all_domain?, message?, from_cache, cached_at?, credits_used,
 * credits_refunded?, credits_remaining}.
 *
 * The first uncached call starts an async discovery job and returns
 * status = "processing" with an empty email list; re-poll
 * domainSearch($domain) until status is "completed" (isProcessing()).
 */
class DomainSearchResult
{
    /**
     * @param array<DomainSearchEmail> $emails
     */
    public function __construct(
        public readonly string $domain,
        public readonly int $emailCount,
        public readonly array $emails,
        /** "processing" | "completed" | null (legacy cached rows: treat as completed). */
        public readonly ?string $status,
        public readonly bool $fromCache,
        public readonly float $creditsUsed,
        /** Fractional like every balance (a cached verification costs 0.5). */
        public readonly float $creditsRemaining,
        public readonly int $patternsChecked = 0,
        public readonly ?string $completionReason = null,
        public readonly bool $catchAllDomain = false,
        public readonly ?float $progressPercent = null,
        public readonly ?string $jobId = null,
        public readonly ?string $cachedAt = null,
        public readonly ?string $message = null,
        /**
         * Present only on the poll, by the account that started the search,
         * that completes a partial search (completion_reason
         * "time_limit_reached"): the credits given back for the candidates it
         * could not check. creditsRemaining already includes them.
         */
        public readonly ?float $creditsRefunded = null,
    ) {}

    /**
     * True while the async discovery job is still running; re-poll
     * domainSearch($domain) until completed.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        $emails = [];
        if (isset($data['emails']) && is_array($data['emails'])) {
            $emails = array_map(
                fn(array $e) => DomainSearchEmail::fromArray($e),
                $data['emails']
            );
        }

        return new self(
            domain: $data['domain'] ?? '',
            emailCount: $data['email_count'] ?? count($emails),
            emails: $emails,
            status: $data['status'] ?? null,
            fromCache: (bool)($data['from_cache'] ?? false),
            creditsUsed: $data['credits_used'] ?? 0,
            creditsRemaining: $data['credits_remaining'] ?? 0,
            patternsChecked: $data['patterns_checked'] ?? 0,
            completionReason: $data['completion_reason'] ?? null,
            catchAllDomain: (bool)($data['catch_all_domain'] ?? false),
            progressPercent: isset($data['progress_percent']) ? (float)$data['progress_percent'] : null,
            jobId: $data['job_id'] ?? null,
            cachedAt: $data['cached_at'] ?? null,
            message: $data['message'] ?? null,
            creditsRefunded: isset($data['credits_refunded']) ? (float)$data['credits_refunded'] : null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'email_count' => $this->emailCount,
            'emails' => array_map(fn($e) => $e->toArray(), $this->emails),
            'status' => $this->status,
            'from_cache' => $this->fromCache,
            'credits_used' => $this->creditsUsed,
            'credits_remaining' => $this->creditsRemaining,
            'patterns_checked' => $this->patternsChecked,
            'completion_reason' => $this->completionReason,
            'catch_all_domain' => $this->catchAllDomain,
            'progress_percent' => $this->progressPercent,
            'job_id' => $this->jobId,
            'cached_at' => $this->cachedAt,
            'message' => $this->message,
            'credits_refunded' => $this->creditsRefunded,
        ];
    }
}
