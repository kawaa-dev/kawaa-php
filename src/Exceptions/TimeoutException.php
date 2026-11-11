<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

use Exception;

/**
 * Exception thrown when a request times out.
 *
 * The API may have acted on a request that timed out. When the call carried
 * an Idempotency-Key, repeat it with getIdempotencyKey() so a charge that
 * did land is replayed rather than paid again.
 */
class TimeoutException extends KawaaException
{
    public function __construct(float $timeout, ?array $responseBody = null, ?Exception $previous = null)
    {
        parent::__construct(
            "Request timed out after {$timeout} seconds",
            408,
            'timeout',
            $responseBody,
            $previous
        );
    }
}
