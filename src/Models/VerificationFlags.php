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
    /** The four risk flags, by their API names, and the property each fills. */
    private const RISK_FLAGS = [
        'abuse_email' => 'abuseEmail',
        'complainer' => 'complainer',
        'honeypot' => 'honeypot',
        'bot_generated' => 'botGenerated',
    ];

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
         * Whether the answer carried all four risk flags above. A job result
         * row and a verification do. A download row (downloadResults() in
         * JSON) does not: it has columns for abuse_email, complainer and
         * bot_generated but none for honeypot, and a result stored before
         * those columns existed has none of them. Then this is false, and a
         * flag the row did not state reads false here without meaning "no":
         * use riskFlag() for each one.
         */
        public readonly bool $riskFlagsStated = true,
        /**
         * The risk flags the answer stated, by their API names
         * ('abuse_email' => true, ...). riskFlag() reads it.
         *
         * @var array<string, bool>
         */
        public readonly array $statedRiskFlags = [],
    ) {}

    /**
     * One risk flag as the answer stated it: true, false, or null when it
     * did not state it. $name is the API's name: 'abuse_email',
     * 'complainer', 'honeypot' or 'bot_generated'. On a download row,
     * 'honeypot' is always null, and the other three are null for a result
     * stored before the download had those columns.
     */
    public function riskFlag(string $name): ?bool
    {
        if (!array_key_exists($name, self::RISK_FLAGS)) {
            throw new \InvalidArgumentException(
                "Unknown risk flag: {$name}. Use one of: " . implode(', ', array_keys(self::RISK_FLAGS))
            );
        }
        if (array_key_exists($name, $this->statedRiskFlags)) {
            return $this->statedRiskFlags[$name];
        }
        // Flags built by hand, without $statedRiskFlags: the properties are
        // what was given, when riskFlagsStated says they were all given.
        if ($this->statedRiskFlags === [] && $this->riskFlagsStated) {
            return $this->{self::RISK_FLAGS[$name]};
        }
        return null;
    }

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
        // A flag is stated when the answer sent it as a boolean. toArray()
        // writes which ones were (stated_risk_flags), so a round trip keeps a
        // download row's "not stated" instead of turning its false values
        // into findings; an array from 0.3.3 or earlier has only
        // risk_flags_stated, and false there means none was stated.
        $named = is_array($data['stated_risk_flags'] ?? null) ? $data['stated_risk_flags'] : null;
        $stated = [];
        if ($named !== null || ($data['risk_flags_stated'] ?? null) !== false) {
            foreach (array_keys(self::RISK_FLAGS) as $name) {
                if (is_bool($data[$name] ?? null) && ($named === null || in_array($name, $named, true))) {
                    $stated[$name] = $data[$name];
                }
            }
        }
        $riskFlagsStated = count($stated) === count(self::RISK_FLAGS);
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
            statedRiskFlags: $stated,
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
            'stated_risk_flags' => array_keys(array_filter(
                self::RISK_FLAGS,
                fn (string $name): bool => $this->riskFlag($name) !== null,
                ARRAY_FILTER_USE_KEY,
            )),
        ];
    }
}
