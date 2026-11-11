<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when account has insufficient credits (HTTP 402).
 *
 * The API error body carries {code, message, request_id?} and retry
 * guidance, never credits_required or credits_available, so this exception
 * exposes the server's message as-is. Query getAccount() for current balances.
 */
class InsufficientCreditsException extends KawaaException
{
    public function __construct(
        string $message = 'Insufficient credits',
        ?array $responseBody = null
    ) {
        parent::__construct(
            $message,
            402,
            'insufficient_credits',
            $responseBody
        );
    }
}
