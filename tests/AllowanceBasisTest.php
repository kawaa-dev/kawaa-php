<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kawaa\Kawaa;
use Kawaa\Models\AccountInfo;
use PHPUnit\Framework\TestCase;

/**
 * GET /v1/account calls the allowance monthly_allowance and the spend
 * used_this_month on every plan, and says in credits.allowance_basis whether
 * either renews. On Free it is a one-time grant that nothing resets. The
 * client dropped the field, so a Free account read as "66 of 100 used this
 * month" with a refill to come. The payloads are the shapes production
 * returned on 2026-10-01.
 */
class AllowanceBasisTest extends TestCase
{
    /** @param array<string, mixed> $credits */
    private function account(string $plan, array $credits): AccountInfo
    {
        return AccountInfo::fromArray([
            'account' => ['user_id' => 'u', 'email' => 'u@example.com', 'plan' => $plan, 'created_at' => 'x'],
            'credits' => $credits,
        ]);
    }

    public function testGetAccountReportsAOneTimeGrant(): void
    {
        $body = json_encode(['success' => true, 'data' => [
            'account' => [
                'user_id' => 'usr_free', 'email' => 'free@example.com', 'plan' => 'free',
                'status' => 'active', 'created_at' => '2026-09-01T00:00:00.000Z',
            ],
            'credits' => ['balance' => 34, 'used_this_month' => 66, 'monthly_allowance' => 100, 'allowance_basis' => 'one_time_grant'],
        ]]);
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)])),
        ]);
        $kawaa = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($kawaa))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($kawaa, $http);

        $account = $kawaa->getAccount();

        $this->assertSame(AccountInfo::ALLOWANCE_ONE_TIME_GRANT, $account->allowanceBasis);
        $this->assertSame(100.0, $account->monthlyAllowance);
        $this->assertNull($account->nextReset);
        $this->assertSame('one_time_grant', $account->toArray()['allowance_basis']);
    }

    public function testARenewingAllowanceSaysSo(): void
    {
        $account = $this->account('starter', [
            'balance' => 4000, 'used_this_month' => 1000, 'monthly_allowance' => 5000,
            'next_reset' => '2026-10-15T00:00:00.000Z', 'allowance_basis' => 'billing_period',
        ]);
        $this->assertSame(AccountInfo::ALLOWANCE_BILLING_PERIOD, $account->allowanceBasis);
    }

    public function testAPaidPlanWithNoBillingPeriodIsCumulative(): void
    {
        $account = $this->account('enterprise', [
            'balance' => 122.75, 'used_this_month' => 876.75, 'monthly_allowance' => 500000,
            'allowance_basis' => 'no_billing_period',
        ]);
        $this->assertSame(AccountInfo::ALLOWANCE_NO_BILLING_PERIOD, $account->allowanceBasis);
    }

    public function testASubAccountIsAProviderAllocation(): void
    {
        // No allowance_basis and no monthly_allowance on a sub-account: its
        // plan says what the allowance is.
        $account = $this->account('sub_account', ['balance' => 18.5, 'credits_used' => 31.5, 'allocated' => 50]);
        $this->assertSame(AccountInfo::ALLOWANCE_PROVIDER_ALLOCATION, $account->allowanceBasis);
        $this->assertSame(31.5, $account->creditsUsed);
    }

    public function testNoBasisIsUnknownNotRenewing(): void
    {
        $account = $this->account('starter', ['balance' => 10, 'used_this_month' => 0, 'monthly_allowance' => 5000]);
        $this->assertNull($account->allowanceBasis);
    }

    public function testABasisThisReleaseDoesNotNameIsPassedThrough(): void
    {
        $account = $this->account('starter', ['balance' => 10, 'monthly_allowance' => 5000, 'allowance_basis' => 'seasonal']);
        $this->assertSame('seasonal', $account->allowanceBasis);
    }
}
