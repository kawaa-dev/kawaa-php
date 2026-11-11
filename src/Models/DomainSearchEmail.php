<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * A single discovered mailbox from GET /v1/domain/{domain}.
 *
 * Item shape: {email, status, quality_score, is_role_account, is_catch_all,
 * from_cache}.
 */
class DomainSearchEmail
{
    public function __construct(
        public readonly string $email,
        public readonly string $status,
        public readonly int $qualityScore,
        public readonly bool $isRoleAccount,
        public readonly bool $isCatchAll,
        public readonly bool $fromCache,
    ) {}

    /**
     * Create from API response array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'] ?? '',
            status: $data['status'] ?? 'unknown',
            qualityScore: $data['quality_score'] ?? 0,
            isRoleAccount: (bool)($data['is_role_account'] ?? false),
            isCatchAll: (bool)($data['is_catch_all'] ?? false),
            fromCache: (bool)($data['from_cache'] ?? false),
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'status' => $this->status,
            'quality_score' => $this->qualityScore,
            'is_role_account' => $this->isRoleAccount,
            'is_catch_all' => $this->isCatchAll,
            'from_cache' => $this->fromCache,
        ];
    }
}
