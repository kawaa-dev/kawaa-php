<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Email finder result.
 *
 * Matches POST /v1/find's response fields exactly: first_name, last_name,
 * email, confidence, pattern, verified, status, message?, credits_used,
 * credits_remaining, idempotent_replay?.
 */
class FindResult
{
    public function __construct(
        public readonly ?string $email,
        public readonly int $confidence,
        public readonly ?string $pattern,
        public readonly bool $verified,
        public readonly string $status,
        public readonly float $creditsUsed,
        /** The post-charge balance, fractional like every balance. Null when the API omitted it (a keyed
         *  replay whose ledger snapshot could not be safely read omits it
         *  rather than publish a stale figure), so null means unknown,
         *  never zero. */
        public readonly ?float $creditsRemaining,
        public readonly ?string $message = null,
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        /**
         * True when this repeated a search that had already been charged, and
         * therefore charged nothing.
         *
         * /v1/find honours an Idempotency-Key (this client sends one on every
         * find call): a repeat under the same key replays the stored result
         * for the key's 30-day receipt, and without one an identical search is
         * still de-duplicated against a fixed two-minute bucket. Either way,
         * $creditsUsed still reports what the SEARCH cost (5); it has always
         * meant that, so this is the only way to tell a second charge from a
         * free repeat. Summing $creditsUsed across responses without checking
         * it over-counts by exactly what the de-duplication saved.
         *
         * Null means the API said nothing either way (a build predating the
         * field), not that the call was charged.
         */
        public readonly ?bool $idempotentReplay = null,
    ) {}

    /**
     * Whether the address is confirmed: true only when an email is set and
     * `verified` is true (a `valid` verdict). On a catch-all, risky or role
     * result the API still returns its best guess as the email, with
     * `verified` false; that guess is not a find, however high its confidence.
     */
    public function found(): bool
    {
        return $this->email !== null && $this->email !== '' && $this->verified;
    }

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? null,
            confidence: $data['confidence'] ?? 0,
            pattern: $data['pattern'] ?? null,
            // Only a real true; any other value is not a confirmation.
            verified: ($data['verified'] ?? false) === true,
            status: $data['status'] ?? 'not_found',
            creditsUsed: $data['credits_used'] ?? 0,
            creditsRemaining: $data['credits_remaining'] ?? null,
            message: $data['message'] ?? null,
            firstName: $data['first_name'] ?? '',
            lastName: $data['last_name'] ?? '',
            // Actual booleans only. `(bool)` would map a present null to
            // false and the string "false" to true, and false means "you were
            // charged" while true means "it was free", so a coercion inverts
            // the signal rather than degrading it.
            idempotentReplay: is_bool($data['idempotent_replay'] ?? null)
                ? $data['idempotent_replay']
                : null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        $result = [
            'email' => $this->email,
            'confidence' => $this->confidence,
            'pattern' => $this->pattern,
            'verified' => $this->verified,
            'status' => $this->status,
            'credits_used' => $this->creditsUsed,
            'credits_remaining' => $this->creditsRemaining,
            'message' => $this->message,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
        ];

        // Only when the API said so: emitting `false` here would turn "not
        // stated" into "you were charged".
        //
        // Assigned rather than unpacked with `...`. composer.json supports
        // PHP >=8.0, and unpacking an array with STRING keys is a fatal
        // `Error: Cannot unpack array with string keys` until 8.1, so on 8.0
        // the spread form threw on exactly the responses this field exists to
        // report, and returned fine on the ones where it is absent.
        if ($this->idempotentReplay !== null) {
            $result['idempotent_replay'] = $this->idempotentReplay;
        }

        return $result;
    }
}
