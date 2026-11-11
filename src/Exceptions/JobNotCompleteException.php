<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when trying to download results from an incomplete job.
 */
class JobNotCompleteException extends KawaaException
{
    private string $jobId;
    private string $status;

    public function __construct(string $jobId, string $status = 'processing', ?array $responseBody = null)
    {
        $this->jobId = $jobId;
        $this->status = $status;
        parent::__construct(
            "Job {$jobId} is not complete (status: {$status})",
            400,
            'job_not_complete',
            $responseBody
        );
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
