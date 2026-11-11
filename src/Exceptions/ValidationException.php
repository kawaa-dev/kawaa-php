<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

/**
 * Exception thrown when request validation fails.
 */
class ValidationException extends KawaaException
{
    private array $errors;

    public function __construct(string $message = 'Validation failed', array $errors = [], ?array $responseBody = null)
    {
        $this->errors = $errors;
        parent::__construct($message, 400, 'validation_error', $responseBody);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
