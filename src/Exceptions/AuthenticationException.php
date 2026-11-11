<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when API key is invalid or missing.
 */
class AuthenticationException extends KawaaException
{
    public function __construct(string $message = 'Invalid or missing API key', ?array $responseBody = null)
    {
        parent::__construct($message, 401, 'authentication_error', $responseBody);
    }
}
