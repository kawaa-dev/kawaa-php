<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Webhook;
use Kawaa\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * The documented webhook fields survive parsing.
 *
 * The fixtures in testdata/webhooks are bodies the senders' own builders
 * produced (the server tests hold them to those
 * builders). The typed models used to drop `partial`, `requested_emails`,
 * `credits_refunded` and `cache_discount_pending` from job events, the
 * per-job failure's `error` and `failed_at`, and a sub-account's identity
 * from credit events.
 */
class WebhookPayloadFieldsTest extends TestCase
{
    private function fixture(string $name): string
    {
        $body = file_get_contents(SharedFixture::path('webhooks/' . $name));
        $this->assertIsString($body);

        return $body;
    }

    private function parse(string $name): WebhookEvent
    {
        return Webhook::parseUnverified($this->fixture($name));
    }

    public function testVerifyingParseOfASignedBodyKeepsThemToo(): void
    {
        $secret = 'whsec_fixture';
        $signed = function (string $name) use ($secret): WebhookEvent {
            $body = $this->fixture($name);

            $t = time();

            return Webhook::parse($body, "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $secret), $secret);
        };

        $job = $signed('job-completed-partial.json')->jobCompleted();
        $this->assertNotNull($job);
        $this->assertSame(1000, $job->requestedEmails);
        $this->assertTrue($job->partial);
        $this->assertSame(40.0, $job->creditsRefunded);
        $this->assertTrue($job->cacheDiscountPending);
        $low = $signed('credits-low-sub-account.json')->creditsLow();
        $this->assertNotNull($low);
        $this->assertSame('sub_account', $low->accountType);
        $this->assertSame('sub_3f9a1c7e5b2d8a64', $low->subAccountId);
    }

    public function testPartialJobCompletedKeepsCoverageAndRefund(): void
    {
        $job = $this->parse('job-completed-partial.json')->jobCompleted();
        $this->assertNotNull($job);
        $this->assertSame('6f1c2b9e-0d4a-4c8e-9b7f-2a3d4e5f6a7b', $job->jobId);
        $this->assertSame(960, $job->totalEmails);
        $this->assertSame(960, $job->processedEmails);
        $this->assertSame(1000, $job->requestedEmails);
        $this->assertTrue($job->partial);
        $this->assertSame(40.0, $job->creditsRefunded);
        $this->assertTrue($job->cacheDiscountPending);
    }

    public function testJobFailedKeepsCoverageAndRefund(): void
    {
        $job = $this->parse('job-failed.json')->jobFailed();
        $this->assertNotNull($job);
        $this->assertSame('b2d7e1f0-3c4a-4b5d-8e6f-7a8b9c0d1e2f', $job->jobId);
        $this->assertSame(2500, $job->totalEmails);
        $this->assertSame(0, $job->processedEmails);
        $this->assertSame(2500, $job->requestedEmails);
        $this->assertSame(2500.0, $job->creditsRefunded);
        $this->assertSame('All messages failed to enqueue', $job->error);
        $this->assertSame('2026-09-30T15:00:04.215Z', $job->failedAt);
    }

    public function testPerJobCompletionKeepsCoverageAndRefund(): void
    {
        $event = $this->parse('per-job-completed-partial.json');
        $this->assertNull($event->webhookId);
        $job = $event->jobCompleted();
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status);
        $this->assertSame(960, $job->totalEmails);
        $this->assertSame(960, $job->processedEmails);
        $this->assertSame(1000, $job->requestedEmails);
        $this->assertTrue($job->partial);
        $this->assertSame(52.5, $job->creditsRefunded);
        $this->assertFalse($job->cacheDiscountPending);
        $this->assertNotNull($job->summary);
        $this->assertSame(900, $job->summary->valid);
        $this->assertSame('2026-09-30T14:21:07.412Z', $job->completedAt);
        $this->assertSame(
            'https://api.kawaa.com/v1/jobs/6f1c2b9e-0d4a-4c8e-9b7f-2a3d4e5f6a7b/download',
            $job->downloadUrl,
        );
    }

    public function testPerJobFailureKeepsErrorAndFailedAt(): void
    {
        $event = $this->parse('per-job-failed.json');
        $this->assertSame('job.failed', $event->event);
        $job = $event->jobFailed();
        $this->assertNotNull($job);
        $this->assertSame('b2d7e1f0-3c4a-4b5d-8e6f-7a8b9c0d1e2f', $job->jobId);
        $this->assertSame('failed', $job->status);
        $this->assertSame(2500, $job->totalEmails);
        $this->assertSame(0, $job->processedEmails);
        $this->assertSame(2500, $job->requestedEmails);
        $this->assertSame(2500.0, $job->creditsRefunded);
        $this->assertSame('All messages failed to enqueue', $job->error);
        $this->assertSame('2026-09-30T15:00:04.215Z', $job->failedAt);
    }

    public function testCreditsLowNamesTheSubAccount(): void
    {
        $low = $this->parse('credits-low-sub-account.json')->creditsLow();
        $this->assertNotNull($low);
        $this->assertSame('sub_account', $low->accountType);
        $this->assertSame('sub_3f9a1c7e5b2d8a64', $low->subAccountId);
        $this->assertSame('Northwind Agency', $low->subAccountName);
        $this->assertSame(5000.0, $low->creditsAllocated);
        $this->assertSame(999.5, $low->creditsRemaining);
        $this->assertSame(1000.0, $low->threshold);
    }

    public function testCreditsExhaustedNamesTheSubAccount(): void
    {
        $exhausted = $this->parse('credits-exhausted-sub-account.json')->creditsExhausted();
        $this->assertNotNull($exhausted);
        $this->assertSame('sub_account', $exhausted->accountType);
        $this->assertSame('sub_3f9a1c7e5b2d8a64', $exhausted->subAccountId);
        $this->assertSame('Northwind Agency', $exhausted->subAccountName);
        $this->assertSame(5000.0, $exhausted->creditsAllocated);
        $this->assertSame(0.0, $exhausted->creditsRemaining);
    }

    public function testCacheDiscountPendingThatIsNotABooleanIsUnknownNotSettled(): void
    {
        $envelope = json_decode($this->fixture('job-completed-partial.json'), true, 512, JSON_THROW_ON_ERROR);
        $flat = json_decode($this->fixture('per-job-completed-partial.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([null, 'true', 1] as $value) {
            $envelope['data']['cache_discount_pending'] = $value;
            $job = Webhook::parseUnverified(json_encode($envelope, JSON_THROW_ON_ERROR))->jobCompleted();
            $this->assertNotNull($job);
            $this->assertNull($job->cacheDiscountPending, var_export($value, true));

            $flat['cache_discount_pending'] = $value;
            $job = Webhook::parseUnverified(json_encode($flat, JSON_THROW_ON_ERROR))->jobCompleted();
            $this->assertNotNull($job);
            $this->assertNull($job->cacheDiscountPending, var_export($value, true));
        }
    }

    public function testOwnCreditsLowHasNoSubAccount(): void
    {
        $low = $this->parse('credits-low.json')->creditsLow();
        $this->assertNotNull($low);
        $this->assertNull($low->accountType);
        $this->assertNull($low->subAccountId);
        $this->assertNull($low->subAccountName);
        $this->assertNull($low->creditsAllocated);
        $this->assertSame(99.5, $low->creditsRemaining);
    }
}
