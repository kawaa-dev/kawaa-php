<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when rate limit is exceeded.
 */
class RateLimitException extends KawaaException
{
    private int $retryAfter;

    public function __construct(int $retryAfter = 60, ?array $responseBody = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct(
            "Rate limit exceeded. Retry after {$retryAfter} seconds",
            429,
            'rate_limit_error',
            $responseBody
        );
    }

    /**
     * Seconds the API asked to wait (the `Retry-After` header, else the
     * body's `retry_after_seconds`), or the client's backoff when it gave
     * neither. Not capped: the client's own retries wait at most
     * Kawaa::MAX_RETRY_AFTER_SECONDS.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
