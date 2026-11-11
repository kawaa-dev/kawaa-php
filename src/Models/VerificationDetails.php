<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Technical verification details.
 */
class VerificationDetails
{
    /**
     * @param array<string> $mxRecords
     * @param ?string $smtpResponse The mail server's reply to the mailbox
     *   probe, e.g. "250 2.1.5 OK". The API sends it as
     *   `verification.smtp_message` on a verification and as `smtp_response`
     *   on a flat download row. Null when no probe got an answer.
     * @param ?int $smtpCode The reply's numeric code (`verification.smtp_code`),
     *   e.g. 250. Null when no probe got an answer, or on a download row,
     *   which does not carry it.
     * @param ?bool $catchAll The API's catch-all finding
     *   (`verification.catch_all`, or `catch_all` on a flat download row).
     *   Null when the API left it out because nothing measured it.
     */
    public function __construct(
        public readonly array $mxRecords = [],
        public readonly ?string $smtpResponse = null,
        public readonly ?int $smtpCode = null,
        public readonly ?bool $catchAll = null,
    ) {}

    /**
     * Create from API response array.
     *
     * Reading only `smtp_response`, which only the flat download rows carry,
     * left the reply null on every verification and every job result row.
     */
    public static function fromArray(array $data): self
    {
        $message = $data['smtp_message'] ?? null;
        $response = $data['smtp_response'] ?? null;
        $code = $data['smtp_code'] ?? null;
        $catchAll = $data['catch_all'] ?? null;
        return new self(
            mxRecords: $data['mx_records'] ?? [],
            smtpResponse: is_string($message) ? $message : (is_string($response) ? $response : null),
            // An actual integer only: anything else is "not stated".
            smtpCode: is_int($code) ? $code : null,
            // An actual boolean only: anything else is "not stated".
            catchAll: is_bool($catchAll) ? $catchAll : null,
        );
    }

    /**
     * Convert to array. `catch_all` is included only when the API stated it,
     * so fromArray() reads the same finding back.
     */
    public function toArray(): array
    {
        $array = [
            'mx_records' => $this->mxRecords,
            'smtp_response' => $this->smtpResponse,
            'smtp_code' => $this->smtpCode,
        ];
        if ($this->catchAll !== null) {
            $array['catch_all'] = $this->catchAll;
        }
        return $array;
    }
}
