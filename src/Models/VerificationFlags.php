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
        return new self(
            disposable: $data['disposable'] ?? false,
            role: $data['role_account'] ?? $data['role'] ?? false,
            freeProvider: $data['free_provider'] ?? false,
            catchAll: $catchAll,
            spamTrap: $data['spam_trap'] ?? false,
            catchAllMeasured: $catchAllMeasured ?? ($catchAll === true),
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
        ];
    }
}
