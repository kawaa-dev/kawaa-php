<?php

declare(strict_types=1);

namespace Kawaa\Models;

/**
 * User account information.
 *
 * Mapped from GET /v1/account's nested response:
 * `{account: {...}, credits: {balance, used_this_month, monthly_allowance,
 * next_reset, allowance_basis}, ...}`.
 *
 * Credit amounts are floats: balances and charges are routinely fractional
 * (a cached verification costs 0.5, an activity lookup 0.25). A whole number
 * on the wire reads as the same float.
 *
 * Read `$allowanceBasis` before describing `$monthlyAllowance` or
 * `$creditsUsed` as monthly. The API names them "monthly" on every plan
 * because a paid subscription is the common case, but the Free plan's credits
 * are a one-time grant that nothing resets.
 */
class AccountInfo
{
    /**
     * The allowance renews each billing period; `$nextReset` says when. Take
     * it as renewing only when `$nextReset` is set too: an API build from
     * before 2026-09-30 sent it for a paid plan with no billing period on file.
     */
    public const ALLOWANCE_BILLING_PERIOD = 'billing_period';
    /** A paid plan with no billing period on file: nothing resets the spend. */
    public const ALLOWANCE_NO_BILLING_PERIOD = 'no_billing_period';
    /** The Free plan: credits granted once and never reset. */
    public const ALLOWANCE_ONE_TIME_GRANT = 'one_time_grant';
    /** A white-label sub-account spending its provider's allocation. */
    public const ALLOWANCE_PROVIDER_ALLOCATION = 'provider_allocation';

    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $plan,
        public readonly float $creditsRemaining,
        /**
         * Credits spent. Spend in the current billing period only when
         * `$allowanceBasis` is ALLOWANCE_BILLING_PERIOD; otherwise cumulative.
         * Null when the API omitted the figure (for example, for a key without
         * the `analytics:read` scope, where `$usageWithheld` is true). Never 0
         * for "not reported": 0 reads as "spent nothing".
         */
        public readonly ?float $creditsUsed,
        public readonly string $createdAt,
        public readonly ?string $name = null,
        public readonly ?string $status = null,
        /**
         * The plan's credit allowance (credits.monthly_allowance). Renews each
         * billing period only when `$allowanceBasis` is
         * ALLOWANCE_BILLING_PERIOD; on Free it is the one-time grant.
         */
        public readonly ?float $monthlyAllowance = null,
        public readonly ?string $nextReset = null,
        /**
         * Whether the allowance renews (credits.allowance_basis): one of the
         * ALLOWANCE_* constants, and only ALLOWANCE_BILLING_PERIOD renews. The
         * API sends none for a white-label sub-account, so for plan
         * "sub_account" it is ALLOWANCE_PROVIDER_ALLOCATION. Null when the API
         * did not say (a build predating the field): unknown, not renewing. A
         * value this release does not name is passed through as sent, and does
         * not renew either.
         */
        public readonly ?string $allowanceBasis = null,
        /**
         * True when the API withheld `$creditsUsed` because this key is not
         * scoped for usage (`withheld.usage` in the response).
         */
        public readonly bool $usageWithheld = false,
        /**
         * Present (`"withheld"`) on the Free plan when another account already
         * claimed this mailbox's one-time free grant (`credits.free_grant`).
         */
        public readonly ?string $freeGrant = null,
    ) {}

    /**
     * Create from API response array (nested account/credits payload).
     */
    public static function fromArray(array $data): self
    {
        $account = $data['account'] ?? [];
        $credits = $data['credits'] ?? [];

        // Tolerate a legacy flat payload if `account` is absent.
        if (empty($account)) {
            $account = $data;
        }

        $creditsUsed = self::firstNumber(
            $credits['used_this_month'] ?? null,
            $credits['credits_used'] ?? null,
            $data['credits_used'] ?? null,
        );
        $withheld = $data['withheld'] ?? null;
        $usageWithheld = $creditsUsed === null
            && is_array($withheld)
            && ($withheld['usage'] ?? null) === true;
        $rawFreeGrant = $credits['free_grant'] ?? null;
        $freeGrant = is_string($rawFreeGrant) && $rawFreeGrant !== '' ? $rawFreeGrant : null;

        return new self(
            userId: $account['user_id'] ?? '',
            email: $account['email'] ?? '',
            plan: $account['plan'] ?? 'free',
            creditsRemaining: self::firstNumber(
                $credits['balance'] ?? null,
                $data['credits_remaining'] ?? null,
            ) ?? 0.0,
            // Sub-accounts report `credits_used` instead of `used_this_month`.
            creditsUsed: $creditsUsed,
            createdAt: $account['created_at'] ?? '',
            name: $account['name'] ?? null,
            status: $account['status'] ?? null,
            monthlyAllowance: self::firstNumber($credits['monthly_allowance'] ?? null),
            nextReset: $credits['next_reset'] ?? null,
            allowanceBasis: self::allowanceBasisFrom($credits, $account['plan'] ?? null),
            usageWithheld: $usageWithheld,
            freeGrant: $freeGrant,
        );
    }

    private static function firstNumber(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    /**
     * credits.allowance_basis, or ALLOWANCE_PROVIDER_ALLOCATION for a
     * sub-account. A sub-account's credits block carries only balance,
     * credits_used and allocated, so the API sends no basis for it; its plan
     * is the declared literal "sub_account", and its allowance is the pool its
     * provider allocated.
     *
     * @param array<string, mixed> $credits
     */
    private static function allowanceBasisFrom(array $credits, mixed $plan): ?string
    {
        $basis = $credits['allowance_basis'] ?? null;
        if (is_string($basis) && $basis !== '') {
            return $basis;
        }
        return $plan === 'sub_account' ? self::ALLOWANCE_PROVIDER_ALLOCATION : null;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        $array = [
            'user_id' => $this->userId,
            'email' => $this->email,
            'plan' => $this->plan,
            'credits_remaining' => $this->creditsRemaining,
            'credits_used' => $this->creditsUsed,
            'created_at' => $this->createdAt,
            'name' => $this->name,
            'status' => $this->status,
            'monthly_allowance' => $this->monthlyAllowance,
            'next_reset' => $this->nextReset,
            'allowance_basis' => $this->allowanceBasis,
            'usage_withheld' => $this->usageWithheld,
        ];
        if ($this->freeGrant !== null) {
            $array['free_grant'] = $this->freeGrant;
        }
        return $array;
    }
}
