<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * The API could not certify that an export is the whole result set.
 *
 * complete=false is a measured shortfall and is normally retryable after a
 * few seconds. complete=null means no comparison was possible; callers must
 * not treat the file as complete.
 */
class IncompleteExportException extends KawaaException
{
    public function __construct(
        private string $jobId,
        private ?bool $complete,
        private ?int $expectedRows,
        private ?int $rowsMissing,
        ?string $message = null,
        int $statusCode = 0,
        ?array $responseBody = null,
    ) {
        $message ??= $complete === false
            ? "Export for job {$jobId} is incomplete. Retry the download in a few seconds."
            : "Completeness of the export for job {$jobId} could not be verified. "
                . 'Do not treat it as the whole result set.';
        parent::__construct(
            $message,
            $statusCode,
            'incomplete_export',
            $responseBody ?? [
                'complete' => $complete,
                'expected_rows' => $expectedRows,
                'rows_missing' => $rowsMissing,
            ],
        );
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getComplete(): ?bool
    {
        return $this->complete;
    }

    public function getExpectedRows(): ?int
    {
        return $this->expectedRows;
    }

    public function getRowsMissing(): ?int
    {
        return $this->rowsMissing;
    }

    public function isRetryable(): bool
    {
        return $this->complete === false;
    }
}
