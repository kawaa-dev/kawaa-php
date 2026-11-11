<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when a resource is not found.
 */
class NotFoundException extends KawaaException
{
    public function __construct(string $message = 'Resource not found', ?array $responseBody = null)
    {
        parent::__construct($message, 404, 'not_found', $responseBody);
    }
}
