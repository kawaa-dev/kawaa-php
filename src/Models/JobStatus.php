<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Batch job status.
 *
 * ENQUEUE_UNKNOWN is returned by POST /v1/verify/batch when some messages
 * had an unknown enqueue outcome; credits are held pending reconciliation
 * and the job should be polled via GET /v1/jobs/{id}.
 */
enum JobStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case ENQUEUE_UNKNOWN = 'enqueue_unknown';
}
