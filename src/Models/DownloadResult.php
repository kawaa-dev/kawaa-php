<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Result of downloading job results.
 */
class DownloadResult
{
    /**
     * @param array<VerificationResult> $results
     */
    public function __construct(
        public readonly string $jobId,
        public readonly int $resultCount,
        public readonly array $results = [],
        public readonly ?string $downloadUrl = null,
        public readonly ?int $expiresInSeconds = null,
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

        return new self(
            jobId: $data['job_id'] ?? '',
            resultCount: $data['result_count'] ?? 0,
            results: $results,
            downloadUrl: $data['download_url'] ?? null,
            expiresInSeconds: $data['expires_in_seconds'] ?? null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'result_count' => $this->resultCount,
            'results' => array_map(fn($r) => $r->toArray(), $this->results),
            'download_url' => $this->downloadUrl,
            'expires_in_seconds' => $this->expiresInSeconds,
        ];
    }
}
