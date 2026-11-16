<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * Flags indicating email characteristics.
 *
 * `catchAll` is true when Kawaa saw the domain accept mail for any address
 * (or the status is `catch_all`). False does not on its own mean the domain
 * was checked: it is also false when nothing measured it, for example a
 * disposable verdict, a domain with no usable mail server, or a mail server
 * that refused the mailbox or never answered. `catchAllMeasured` tells the
 * two apart: true with `catchAll` false means the domain was checked and does
 * not accept every address.
 */
class VerificationFlags
{
    public function __construct(
        public readonly bool $disposable = false,
        public readonly bool $role = false,
        public readonly bool $freeProvider = false,
        public readonly bool $catchAll = false,
        public readonly bool $spamTrap = false,
        public readonly bool $catchAllMeasured = false,
        /**
         * The local part matched an abuse, complaint-handling, feedback-loop,
         * legal or blocklist-removal name or pattern (abuse@, postmaster@,
         * feedback@), at any domain. A match on the address itself, not a
         * finding about its owner.
         */
        public readonly bool $abuseEmail = false,
        /**
         * A narrower $abuseEmail: a complaint-handling mailbox at a reviewed
         * anti-spam, feedback-loop or consumer-protection organization. Every
         * address with $complainer set has $abuseEmail too.
         */
        public readonly bool $complainer = false,
        /** The API's honeypot flag. */
        public readonly bool $honeypot = false,
        /** The API's bot_generated flag. */
        public readonly bool $botGenerated = false,
        /**
         * Whether the answer carried the four risk flags above. Download rows
         * (downloadResults() in JSON) do not: there $abuseEmail, $complainer,
         * $honeypot and $botGenerated read false without meaning "no", and
         * this is false. Read them from getJobStatus($jobId, true) instead.
         */
        public readonly bool $riskFlagsStated = true,
    ) {}

    /**
     * Create from API response array.
     *
     * A `flags` object states catch-all only when it is true. The API's
     * measured finding is `verification.catch_all`, which
     * VerificationResult::fromArray() reads and passes as $catchAllMeasured.
     */
    public static function fromArray(array $data, ?bool $catchAllMeasured = null): self
    {
        $catchAll = $data['catch_all'] ?? false;
        // Stated when the answer sent any of them as a boolean. toArray()
        // writes risk_flags_stated, so a round trip keeps a download row's
        // "not stated" instead of turning its false values into findings.
        $riskFlagsStated = ($data['risk_flags_stated'] ?? null) !== false
            && (is_bool($data['abuse_email'] ?? null) || is_bool($data['complainer'] ?? null)
                || is_bool($data['honeypot'] ?? null) || is_bool($data['bot_generated'] ?? null));
        return new self(
            disposable: $data['disposable'] ?? false,
            role: $data['role_account'] ?? $data['role'] ?? false,
            freeProvider: $data['free_provider'] ?? false,
            catchAll: $catchAll,
            spamTrap: $data['spam_trap'] ?? false,
            catchAllMeasured: $catchAllMeasured ?? ($catchAll === true),
            // Actual booleans only: the verification is charged before it is
            // parsed, so a value of another type reads as false rather than
            // failing the result.
            abuseEmail: ($data['abuse_email'] ?? null) === true,
            complainer: ($data['complainer'] ?? null) === true,
            honeypot: ($data['honeypot'] ?? null) === true,
            botGenerated: ($data['bot_generated'] ?? null) === true,
            riskFlagsStated: $riskFlagsStated,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'disposable' => $this->disposable,
            'role' => $this->role,
            'free_provider' => $this->freeProvider,
            'catch_all' => $this->catchAll,
            'spam_trap' => $this->spamTrap,
            'catch_all_measured' => $this->catchAllMeasured,
            'abuse_email' => $this->abuseEmail,
            'complainer' => $this->complainer,
            'honeypot' => $this->honeypot,
            'bot_generated' => $this->botGenerated,
            'risk_flags_stated' => $this->riskFlagsStated,
        ];
    }
}
