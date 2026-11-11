<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Email verification status.
 *
 * The API emits the full vocabulary below. PENDING is only returned by
 * POST /v1/verify when the synchronous poll window elapses before the
 * worker finishes; poll getJobStatus(jobId) for the final result then.
 *
 * Unrecognized future statuses parse as UNKNOWN (see
 * VerificationResult::fromArray, which also preserves the raw wire value).
 */
enum VerificationStatus: string
{
    case VALID = 'valid';
    case INVALID = 'invalid';
    case RISKY = 'risky';
    case UNKNOWN = 'unknown';
    case SPAM_TRAP = 'spam_trap';
    case DISPOSABLE = 'disposable';
    case ROLE = 'role';
    case CATCH_ALL = 'catch_all';
    case PENDING = 'pending';
}
