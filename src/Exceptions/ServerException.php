<?php

declare(strict_types=1);

namespace Kawaa\Exceptions;

use Exception;

/**
 * Exception thrown when the API answers with a server error (HTTP 5xx).
 *
 * getCode() is the HTTP status. When the call carried an Idempotency-Key
 * (verify, verifyBatch, find), repeat it with getIdempotencyKey(): the API
 * replays an attempt that was charged instead of charging again. When
 * retryMayChargeAgain() is true there is no key to reuse, so check the
 * balance before repeating it.
 */
class ServerException extends KawaaException
{
    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message = 'Server error',
        int $statusCode = 500,
        ?array $responseBody = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $statusCode, 'server_error', $responseBody, $previous);
    }
}
