<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Kawaa;
use Kawaa\Models\VerificationResult;
use Kawaa\Models\VerificationStatus;
use Kawaa\Models\VerificationFlags;
use Kawaa\Models\FindResult;
use Kawaa\Models\DomainSearchResult;
use Kawaa\Webhook;
use Kawaa\WebhookEvent;
use Kawaa\Exceptions\ValidationException;
use Kawaa\Exceptions\IncompleteExportException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class KawaaTest extends TestCase
{
    /** @param array<Response> $responses */
    private function downloadClient(array $responses): Kawaa
    {
        $handler = new MockHandler($responses);
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create($handler),
        ]);
        $client = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($client))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $http);
        return $client;
    }

    public function testClientInstantiation(): void
    {
        $client = new Kawaa('test-api-key');
        $this->assertInstanceOf(Kawaa::class, $client);
    }

    public function testClientWithCustomConfig(): void
    {
        $client = new Kawaa(
            apiKey: 'test-api-key',
            baseUrl: 'https://custom.api.com',
            timeout: 60.0,
            maxRetries: 5
        );
        $this->assertInstanceOf(Kawaa::class, $client);
    }

    public function testCompleteDownloadSucceeds(): void
    {
        $client = $this->downloadClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'job_id' => 'job-whole',
                'result_count' => 1,
                'complete' => true,
                'expected_rows' => 1,
                'rows_missing' => 0,
                'results' => [['email' => 'a@example.com', 'status' => 'valid', 'score' => 95]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $client->downloadResults('job-whole');
        $this->assertEquals(1, $result->resultCount);
    }

    public function testMeasuredShortCsvDownloadRaisesTypedException(): void
    {
        $client = $this->downloadClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'download_url' => 'http://127.0.0.1:1/must-not-be-fetched',
                'format' => 'csv',
                'result_count' => 1497,
                'complete' => false,
                'expected_rows' => 1500,
                'rows_missing' => 3,
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $client->downloadResults('job-short', 'csv');
            $this->fail('Expected IncompleteExportException');
        } catch (IncompleteExportException $error) {
            $this->assertFalse($error->getComplete());
            $this->assertEquals(1500, $error->getExpectedRows());
            $this->assertEquals(3, $error->getRowsMissing());
            $this->assertTrue($error->isRetryable());
        }
    }

    public function testRefusedInlineCsvRaisesTypedException(): void
    {
        $client = $this->downloadClient([
            new Response(409, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT',
                    'message' => 'This CSV export is not the whole job. Request it again.',
                    'request_id' => 'req-short',
                    'retryable' => true,
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $client->downloadResults('job-short', 'csv');
            $this->fail('Expected IncompleteExportException');
        } catch (IncompleteExportException $error) {
            $this->assertFalse($error->getComplete());
            $this->assertNull($error->getExpectedRows());
            $this->assertNull($error->getRowsMissing());
            $this->assertEquals(409, $error->getCode());
            $this->assertEquals('CONFLICT', $error->getResponseBody()['error']['code']);
            $this->assertTrue($error->isRetryable());
        }
    }

    public function testUnverifiableDownloadIsNotTreatedAsComplete(): void
    {
        $client = $this->downloadClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'job_id' => 'job-legacy',
                'result_count' => 1,
                'complete' => null,
                'expected_rows' => null,
                'rows_missing' => null,
                'results' => [['email' => 'a@example.com', 'status' => 'valid', 'score' => 95]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $client->downloadResults('job-legacy');
            $this->fail('Expected IncompleteExportException');
        } catch (IncompleteExportException $error) {
            $this->assertNull($error->getComplete());
            $this->assertNull($error->getExpectedRows());
            $this->assertNull($error->getRowsMissing());
            $this->assertFalse($error->isRetryable());
        }
    }

    public function testVerificationResultFromArray(): void
    {
        $data = [
            'email' => 'test@example.com',
            'status' => 'valid',
            'quality_score' => 95,
            'flags' => [
                'disposable' => false,
                'role_account' => false,
                'free_provider' => true,
                'catch_all' => false,
            ],
            'suggestion' => null,
        ];

        $result = VerificationResult::fromArray($data);

        $this->assertEquals('test@example.com', $result->email);
        $this->assertEquals(VerificationStatus::VALID, $result->status);
        $this->assertEquals(95, $result->score);
        $this->assertFalse($result->flags->disposable);
        $this->assertTrue($result->flags->freeProvider);
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isDeliverable());
    }

    public function testVerificationResultRisky(): void
    {
        $data = [
            'email' => 'risky@example.com',
            'status' => 'risky',
            'quality_score' => 75,
            'flags' => [],
        ];

        $result = VerificationResult::fromArray($data);

        $this->assertEquals(VerificationStatus::RISKY, $result->status);
        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isDeliverable());
    }

    public function testVerificationResultNotDeliverable(): void
    {
        $data = [
            'email' => 'risky@example.com',
            'status' => 'risky',
            'quality_score' => 50,
            'flags' => [],
        ];

        $result = VerificationResult::fromArray($data);

        $this->assertFalse($result->isDeliverable());
    }

    public function testVerificationResultExtendedStatuses(): void
    {
        $spamTrap = VerificationResult::fromArray([
            'email' => 'trap@example.com',
            'status' => 'spam_trap',
            'sub_status' => 'mailbox_reject',
            'quality_score' => 0,
            'flags' => ['spam_trap' => true],
        ]);

        $this->assertEquals(VerificationStatus::SPAM_TRAP, $spamTrap->status);
        $this->assertEquals('mailbox_reject', $spamTrap->subStatus);
        $this->assertTrue($spamTrap->flags->spamTrap);
        $this->assertFalse($spamTrap->isValid());

        // flags.spam_trap derives from status when the flags object omits it.
        $derived = VerificationResult::fromArray([
            'email' => 'trap2@example.com',
            'status' => 'spam_trap',
            'quality_score' => 0,
            'flags' => [],
        ]);
        $this->assertTrue($derived->flags->spamTrap);

        $role = VerificationResult::fromArray([
            'email' => 'info@example.com',
            'status' => 'role',
            'quality_score' => 40,
            'flags' => [],
        ]);
        $this->assertEquals(VerificationStatus::ROLE, $role->status);
        $this->assertTrue($role->flags->role);
    }

    public function testVerificationResultCatchAllDerived(): void
    {
        $result = VerificationResult::fromArray([
            'email' => 'any@catchall.com',
            'status' => 'catch_all',
            'quality_score' => 60,
            'flags' => ['disposable' => false],
            'verification' => ['catch_all' => true],
        ]);

        $this->assertEquals(VerificationStatus::CATCH_ALL, $result->status);
        $this->assertTrue($result->flags->catchAll);
    }

    public function testVerificationResultPendingSyncTimeout(): void
    {
        $result = VerificationResult::fromArray([
            'job_id' => 'job-pending',
            'email' => 'slow@example.com',
            'status' => 'pending',
            'message' => 'Verification still processing.',
        ]);

        $this->assertTrue($result->isPending());
        $this->assertEquals('job-pending', $result->jobId);
        $this->assertEquals(0, $result->score);
        $this->assertFalse($result->isValid());
    }

    public function testVerificationResultUnknownFutureStatusFallsBack(): void
    {
        $result = VerificationResult::fromArray([
            'email' => 'x@y.com',
            'status' => 'brand_new_status',
            'quality_score' => 10,
        ]);

        $this->assertEquals(VerificationStatus::UNKNOWN, $result->status);
        $this->assertEquals('brand_new_status', $result->rawStatus);
    }

    public function testJobStatusResultReadsSummary(): void
    {
        $status = \Kawaa\Models\JobStatusResult::fromArray([
            'job_id' => 'job-123',
            'status' => 'completed',
            'total_emails' => 10,
            'processed_emails' => 10,
            'summary' => ['valid' => 8, 'invalid' => 1, 'risky' => 1, 'unknown' => 0],
            'progress_percent' => 100.0,
            'next_key' => 'b64cursor',
            'has_more' => true,
        ]);

        $this->assertEquals(8, $status->validCount);
        $this->assertEquals(1, $status->invalidCount);
        $this->assertEquals(1, $status->riskyCount);
        $this->assertEquals(0, $status->unknownCount);
        $this->assertEquals('b64cursor', $status->nextKey);
        $this->assertTrue($status->hasMore);
    }

    public function testJobStatusResultLegacyFlatCounts(): void
    {
        $status = \Kawaa\Models\JobStatusResult::fromArray([
            'job_id' => 'job-legacy',
            'status' => 'completed',
            'total_emails' => 4,
            'processed_emails' => 4,
            'valid_count' => 3,
            'invalid_count' => 1,
            'risky_count' => 0,
            'unknown_count' => 0,
            'progress_percent' => 100.0,
        ]);

        $this->assertEquals(3, $status->validCount);
        $this->assertEquals(1, $status->invalidCount);
    }

    public function testJobStatusEnqueueUnknownParses(): void
    {
        $status = \Kawaa\Models\JobStatusResult::fromArray([
            'job_id' => 'job-amb',
            'status' => 'enqueue_unknown',
            'total_emails' => 10,
            'processed_emails' => 0,
            'progress_percent' => 0.0,
        ]);

        $this->assertEquals(\Kawaa\Models\JobStatus::ENQUEUE_UNKNOWN, $status->status);
        $this->assertFalse($status->isCompleted());
    }

    public function testAccountInfoNestedMapping(): void
    {
        $account = \Kawaa\Models\AccountInfo::fromArray([
            'account' => [
                'user_id' => 'user-123',
                'email' => 'user@example.com',
                'name' => 'Jane Doe',
                'plan' => 'professional',
                'status' => 'active',
                'created_at' => '2024-01-01T00:00:00Z',
            ],
            'credits' => [
                'balance' => 1000,
                'used_this_month' => 500,
                'monthly_allowance' => 10000,
                'next_reset' => '2024-02-01T00:00:00Z',
            ],
        ]);

        $this->assertEquals('user-123', $account->userId);
        $this->assertEquals('user@example.com', $account->email);
        $this->assertEquals('professional', $account->plan);
        $this->assertEquals(1000, $account->creditsRemaining);
        $this->assertEquals(500, $account->creditsUsed);
        $this->assertEquals(10000, $account->monthlyAllowance);
        $this->assertEquals('2024-02-01T00:00:00Z', $account->nextReset);
        $this->assertEquals('2024-01-01T00:00:00Z', $account->createdAt);
    }

    /**
     * The API returns its best guess with verified false on a catch-all,
     * risky or role result. A guess, even with high confidence, is not a find.
     */
    public function testFindResultUnconfirmedGuessIsNotFound(): void
    {
        foreach (['catch_all', 'risky', 'role', 'unknown'] as $status) {
            $result = FindResult::fromArray([
                'first_name' => 'John',
                'last_name' => 'Smith',
                'email' => 'john.smith@example.com',
                'confidence' => 85,
                'pattern' => 'first.last',
                'verified' => false,
                'status' => $status,
                'credits_used' => 5,
                'credits_remaining' => 100,
            ]);

            $this->assertSame('john.smith@example.com', $result->email, $status);
            $this->assertSame(85, $result->confidence, $status);
            $this->assertFalse($result->verified, $status);
            $this->assertFalse($result->found(), $status);
        }
    }

    public function testFindResultReadsOnlyARealTrueAsVerified(): void
    {
        foreach (['true', 1, null] as $verified) {
            $result = FindResult::fromArray([
                'email' => 'john.smith@example.com',
                'confidence' => 92,
                'verified' => $verified,
                'status' => 'valid',
                'credits_used' => 5,
            ]);

            $this->assertFalse($result->verified, var_export($verified, true));
            $this->assertFalse($result->found(), var_export($verified, true));
        }
    }

    public function testFindResultFound(): void
    {
        $data = [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@example.com',
            'confidence' => 95,
            'pattern' => 'first.last',
            'verified' => true,
            'status' => 'valid',
            'credits_used' => 5,
            'credits_remaining' => 100,
        ];

        $result = FindResult::fromArray($data);

        $this->assertTrue($result->found());
        $this->assertEquals('John', $result->firstName);
        $this->assertEquals('Smith', $result->lastName);
        $this->assertEquals('john.smith@example.com', $result->email);
        $this->assertEquals(95, $result->confidence);
        $this->assertEquals('first.last', $result->pattern);
        $this->assertEquals(5, $result->creditsUsed);
        // Absent means the API said nothing either way, not "this was charged".
        $this->assertNull($result->idempotentReplay);
        $this->assertArrayNotHasKey('idempotent_replay', $result->toArray());
    }

    /**
     * A de-duplicated repeat still reports credits_used: 5, which is what the
     * SEARCH cost, so idempotentReplay is the only thing saying THIS call
     * charged nothing. /v1/find honours an Idempotency-Key (sent on every
     * find call here); without one it de-duplicates an identical search
     * against a fixed two-minute bucket, and a caller summing
     * creditsUsed without checking this over-counts by exactly what was saved.
     */
    public function testFindResultIdempotentReplay(): void
    {
        $result = FindResult::fromArray([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@example.com',
            'confidence' => 95,
            'verified' => true,
            'status' => 'valid',
            'credits_used' => 5,
            'credits_remaining' => 100,
            'idempotent_replay' => true,
        ]);

        $this->assertTrue($result->idempotentReplay);
        $this->assertEquals(5, $result->creditsUsed);
        $this->assertTrue($result->toArray()['idempotent_replay']);
    }

    /**
     * A present value that is not a boolean is not a boolean. `(bool)` maps a
     * present null to false and the string "false" to true, and false is
     * itself the claim "you were charged", so coercing inverts the signal
     * rather than degrading it.
     */
    public function testFindResultMalformedReplayMarkerIsUnknown(): void
    {
        foreach ([null, 'false', 'true', 0, 1] as $raw) {
            $result = FindResult::fromArray([
                'email' => 'john.smith@example.com',
                'confidence' => 95,
                'status' => 'valid',
                'credits_used' => 5,
                'credits_remaining' => 100,
                'idempotent_replay' => $raw,
            ]);

            $this->assertNull($result->idempotentReplay, var_export($raw, true));
        }
    }

    public function testFindResultNotFound(): void
    {
        $data = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => null,
            'confidence' => 0,
            'pattern' => null,
            'verified' => false,
            'status' => 'not_found',
            'message' => 'No verified email found',
            'credits_used' => 5,
            'credits_remaining' => 100,
        ];

        $result = FindResult::fromArray($data);

        $this->assertFalse($result->found());
        $this->assertEquals('No verified email found', $result->message);
    }

    public function testDomainSearchResult(): void
    {
        $data = [
            'domain' => 'example.com',
            'emails' => [
                ['email' => 'info@example.com', 'status' => 'role', 'quality_score' => 90, 'is_role_account' => true, 'is_catch_all' => false, 'from_cache' => true],
                ['email' => 'sales@example.com', 'status' => 'valid', 'quality_score' => 85, 'is_role_account' => false, 'is_catch_all' => false, 'from_cache' => false],
            ],
            'email_count' => 2,
            'status' => 'completed',
            'patterns_checked' => 12,
            'completion_reason' => 'all_candidates_checked',
            'from_cache' => false,
            'credits_used' => 10,
            'credits_remaining' => 90,
        ];

        $result = DomainSearchResult::fromArray($data);

        $this->assertEquals('example.com', $result->domain);
        $this->assertEquals(2, $result->emailCount);
        $this->assertCount(2, $result->emails);
        $this->assertEquals('info@example.com', $result->emails[0]->email);
        $this->assertTrue($result->emails[0]->isRoleAccount);
        $this->assertEquals(90, $result->emails[0]->qualityScore);
        $this->assertFalse($result->isProcessing());
        $this->assertEquals('completed', $result->status);
    }

    public function testDomainSearchResultProcessing(): void
    {
        $result = DomainSearchResult::fromArray([
            'domain' => 'example.com',
            'emails' => [],
            'email_count' => 0,
            'status' => 'processing',
            'progress_percent' => 25,
            'job_id' => 'domain-job-1',
            'from_cache' => false,
            'credits_used' => 10,
            'credits_remaining' => 90,
        ]);

        $this->assertTrue($result->isProcessing());
        $this->assertEquals('domain-job-1', $result->jobId);
        $this->assertEquals(25.0, $result->progressPercent);
        $this->assertCount(0, $result->emails);
        $this->assertNull($result->creditsRefunded);
    }

    public function testDomainSearchResultPartialRefund(): void
    {
        // The owner's poll that completes a partial search carries the refund
        // for the candidates it could not check.
        $result = DomainSearchResult::fromArray([
            'domain' => 'example.com',
            'emails' => [],
            'email_count' => 0,
            'status' => 'completed',
            'patterns_checked' => 45,
            'completion_reason' => 'time_limit_reached',
            'from_cache' => false,
            'credits_used' => 0,
            'credits_refunded' => 0.5,
            'credits_remaining' => 90.5,
        ]);

        $this->assertEquals('time_limit_reached', $result->completionReason);
        $this->assertEquals(45, $result->patternsChecked);
        $this->assertSame(0.5, $result->creditsRefunded);
        $this->assertSame(90.5, $result->creditsRemaining);
        $this->assertSame(0.5, $result->toArray()['credits_refunded']);
    }

    public function testWebhookSignatureVerification(): void
    {
        $payload = '{"event":"batch.completed","job_id":"test-123"}';
        $secret = 'webhook-secret';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue(Webhook::verifySignature($payload, $signature, $secret));
        $this->assertFalse(Webhook::verifySignature($payload, 'invalid', $secret));
    }

    public function testWebhookParseEnvelope(): void
    {
        // Registered-webhook deliveries nest the event data under `data`.
        $payload = json_encode([
            'event' => 'job.completed',
            'data' => [
                'job_id' => 'job-123',
                'status' => 'completed',
                'total_emails' => 100,
                'processed_emails' => 100,
                'summary' => [
                    'valid' => 80,
                    'invalid' => 15,
                    'risky' => 5,
                    'unknown' => 0,
                ],
                'created_at' => '2024-01-15T09:00:00Z',
                'completed_at' => '2024-01-15T10:00:00Z',
                'download_url' => 'https://example.com/download',
            ],
            'webhook_id' => 'wh_abc',
            'timestamp' => '2024-01-15T10:00:01Z',
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertEquals('job.completed', $event->event);
        $this->assertEquals('wh_abc', $event->webhookId);
        $this->assertEquals('2024-01-15T10:00:01Z', $event->timestamp);
        $this->assertFalse($event->test);

        $job = $event->jobCompleted();
        $this->assertNotNull($job);
        $this->assertEquals('job-123', $job->jobId);
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(100, $job->totalEmails);
        $this->assertEquals(100, $job->processedEmails);
        $this->assertEquals(80, $job->summary->valid);
        $this->assertEquals(15, $job->summary->invalid);
        $this->assertEquals('2024-01-15T10:00:00Z', $job->completedAt);

        // Accessors for other events return null
        $this->assertNull($event->verificationCompleted());
        $this->assertNull($event->creditsLow());
    }

    public function testWebhookParseVerificationCompleted(): void
    {
        $payload = json_encode([
            'event' => 'verification.completed',
            'data' => [
                'job_id' => 'job_abc',
                'email' => 'user@example.com',
                'status' => 'valid',
                'quality_score' => 95,
                'verified_at' => '2025-01-01T00:00:00Z',
                'credits_used' => 1,
            ],
            'webhook_id' => 'wh_1',
            'timestamp' => '2025-01-01T00:00:01Z',
        ]);

        $event = Webhook::parseUnverified($payload);

        $data = $event->verificationCompleted();
        $this->assertNotNull($data);
        $this->assertEquals('job_abc', $data->jobId);
        $this->assertEquals('user@example.com', $data->email);
        $this->assertEquals('valid', $data->status);
        $this->assertEquals(95, $data->qualityScore);
        $this->assertEquals(1, $data->creditsUsed);
    }

    public function testWebhookCreditFiguresKeepTheirFractions(): void
    {
        $low = Webhook::parseUnverified((string) json_encode([
            'event' => 'credits.low',
            'data' => ['credits_remaining' => 19.5, 'threshold' => 20, 'triggered_at' => '2026-09-25T00:00:00Z'],
            'webhook_id' => 'wh_3',
            'timestamp' => '2026-09-25T00:00:01Z',
        ]))->creditsLow();
        $this->assertNotNull($low);
        $this->assertSame(19.5, $low->creditsRemaining);
        $this->assertSame(20.0, $low->threshold);

        $exhausted = Webhook::parseUnverified((string) json_encode([
            'event' => 'credits.exhausted',
            'data' => ['credits_remaining' => 0, 'triggered_at' => '2026-09-25T00:00:00Z'],
            'webhook_id' => 'wh_4',
            'timestamp' => '2026-09-25T00:00:01Z',
        ]))->creditsExhausted();
        $this->assertNotNull($exhausted);
        $this->assertSame(0.0, $exhausted->creditsRemaining);

        $completed = Webhook::parseUnverified((string) json_encode([
            'event' => 'verification.completed',
            'data' => ['job_id' => 'job_1', 'status' => 'valid', 'verified_at' => '2026-09-25T00:00:00Z', 'credits_used' => 0.5],
            'webhook_id' => 'wh_5',
            'timestamp' => '2026-09-25T00:00:01Z',
        ]))->verificationCompleted();
        $this->assertNotNull($completed);
        $this->assertSame(0.5, $completed->creditsUsed);
    }

    public function testWebhookParseTestDeliveryWithOmittedFields(): void
    {
        // Dashboard Test deliveries add test: true and omit some data fields.
        $payload = json_encode([
            'event' => 'job.completed',
            'data' => [
                'job_id' => 'job_t',
                'total_emails' => 1000,
                'summary' => ['valid' => 800, 'invalid' => 150, 'risky' => 50],
                'completed_at' => '2025-01-01T00:00:00Z',
            ],
            'webhook_id' => 'wh_t',
            'test' => true,
            'timestamp' => '2025-01-01T00:00:01Z',
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertTrue($event->test);
        $job = $event->jobCompleted();
        $this->assertEquals('job_t', $job->jobId);
        $this->assertNull($job->status);
        $this->assertEquals(0, $job->summary->unknown);
    }

    public function testWebhookParseLegacyFlatCallback(): void
    {
        // The legacy per-job webhook_url callback (flat body) is normalized.
        $payload = json_encode([
            'event' => 'job.completed',
            'job_id' => 'job-legacy',
            'status' => 'completed',
            'created_at' => '2024-01-15T09:00:00Z',
            'completed_at' => '2024-01-15T10:00:00Z',
            'results' => [
                'total' => 100,
                'processed' => 100,
                'valid' => 80,
                'invalid' => 15,
                'risky' => 5,
                'unknown' => 0,
            ],
            'download_url' => 'https://example.com/download',
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertEquals('job.completed', $event->event);
        $this->assertNull($event->webhookId);
        $this->assertNull($event->timestamp);

        $job = $event->jobCompleted();
        $this->assertNotNull($job);
        $this->assertEquals('job-legacy', $job->jobId);
        $this->assertEquals(100, $job->totalEmails);
        $this->assertEquals(100, $job->processedEmails);
        $this->assertEquals(80, $job->summary->valid);
        $this->assertEquals('https://example.com/download', $job->downloadUrl);
    }

    public function testWebhookParseNullDataWithFlatFieldsIsLegacy(): void
    {
        // `data: null` plus a top-level job_id is the legacy flat callback.
        $payload = json_encode([
            'event' => 'job.completed',
            'data' => null,
            'job_id' => 'job-null-data',
            'status' => 'completed',
            'results' => [
                'total' => 10,
                'processed' => 10,
                'valid' => 8,
                'invalid' => 2,
                'risky' => 0,
                'unknown' => 0,
            ],
            'download_url' => 'https://api.kawaa.com/v1/jobs/job-null-data/download',
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertNull($event->webhookId);
        $job = $event->jobCompleted();
        $this->assertNotNull($job);
        $this->assertEquals('job-null-data', $job->jobId);
        $this->assertEquals(10, $job->totalEmails);
        $this->assertEquals(8, $job->summary->valid);
    }

    public function testWebhookParseNullJobIdStillGatesLegacy(): void
    {
        // The job_id KEY (even explicitly null) gates the legacy fallback.
        $payload = json_encode([
            'event' => 'job.completed',
            'job_id' => null,
            'status' => 'completed',
            'results' => ['total' => 5, 'valid' => 5, 'invalid' => 0, 'risky' => 0, 'unknown' => 0],
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertNull($event->webhookId);
        $this->assertNull($event->timestamp);
        $job = $event->jobCompleted();
        $this->assertNotNull($job);
        $this->assertEquals('', $job->jobId);
        $this->assertEquals(5, $job->totalEmails);
        $this->assertEquals(5, $job->summary->valid);
    }

    public function testWebhookParseUnknownEvent(): void
    {
        $payload = json_encode([
            'event' => 'something.new',
            'data' => ['foo' => 'bar', 'count' => 3],
            'webhook_id' => 'wh_9',
            'timestamp' => '2025-01-01T00:00:00Z',
        ]);

        $event = Webhook::parseUnverified($payload);

        $this->assertEquals('something.new', $event->event);
        $this->assertEquals(['foo' => 'bar', 'count' => 3], $event->data);
        $this->assertNull($event->jobCompleted());
        $this->assertNull($event->dmarcFailure());
    }

    public function testWebhookParseWithValidSignature(): void
    {
        $payload = '{"event":"job.completed","data":{"job_id":"test-123"},"webhook_id":"wh_1","timestamp":"2026-01-01T00:00:00Z"}';
        $secret = 'webhook-secret';
        $t = time();
        $signature = "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret);

        $event = Webhook::parse($payload, $signature, $secret);

        $this->assertEquals('test-123', $event->jobCompleted()->jobId);
    }

    public function testWebhookParseWithBareLegacySignatureAndTheOptIn(): void
    {
        $payload = '{"event":"job.completed","data":{"job_id":"test-123"},"webhook_id":"wh_1","timestamp":"2026-01-01T00:00:00Z"}';
        $secret = 'webhook-secret';
        $signature = hash_hmac('sha256', $payload, $secret);

        $event = Webhook::parse($payload, $signature, $secret, allowLegacySignature: true);

        $this->assertEquals('test-123', $event->jobCompleted()->jobId);
    }

    public function testWebhookParseWithPrefixedSignature(): void
    {
        $payload = '{"event":"job.completed","data":{"job_id":"test-123"},"webhook_id":"wh_1","timestamp":"2026-01-01T00:00:00Z"}';
        $secret = 'webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $event = Webhook::parse($payload, $signature, $secret, allowLegacySignature: true);

        $this->assertEquals('test-123', $event->jobCompleted()->jobId);
    }

    public function testWebhookParseWithInvalidSignature(): void
    {
        $this->expectException(ValidationException::class);

        $payload = '{"event":"batch.completed","job_id":"test-123"}';
        Webhook::parse($payload, 'invalid-signature', 'secret');
    }

    public function testWebhookParseRejectsMissingSignatureWhenSecretSet(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('signature missing');

        $payload = '{"event":"batch.completed","job_id":"test-123"}';
        Webhook::parse($payload, null, 'my-secret');
    }

    public function testWebhookParseRejectsMissingSecretWhenSignatureSet(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('secret is missing');

        $payload = '{"event":"batch.completed","job_id":"test-123"}';
        Webhook::parse($payload, 'sha256=abc', null);
    }

    public function testVerificationFlagsToArray(): void
    {
        $flags = new VerificationFlags(
            disposable: true,
            role: false,
            freeProvider: true,
            catchAll: false
        );

        $array = $flags->toArray();

        $this->assertTrue($array['disposable']);
        $this->assertFalse($array['role']);
        $this->assertTrue($array['free_provider']);
        $this->assertFalse($array['catch_all']);
    }

    /**
     * composer.json declared `"php": ">=8.0"` while the models used readonly
     * properties and enums, which are PHP 8.1 syntax: on 8.0 every model file
     * is a parse error. CI only runs 8.3, so nothing there executes on the
     * oldest runtime composer.json promises; the source is asserted instead.
     */
    public function testComposerFloorCoversTheSyntaxTheSourceUses(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertIsArray($composer);
        $floor = $composer['require']['php'] ?? null;
        $this->assertIsString($floor);
        $this->assertMatchesRegularExpression('/^>=\s*(\d+\.\d+)$/', $floor);
        preg_match('/(\d+\.\d+)/', $floor, $match);

        $usesPhp81Syntax = false;
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src')
        );
        foreach ($directory as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\breadonly\b|^\s*enum\s+\w+/m', $source) === 1) {
                $usesPhp81Syntax = true;
            }
        }

        $this->assertTrue($usesPhp81Syntax, 'the guard no longer finds the syntax it is about');
        $this->assertTrue(
            version_compare($match[1], '8.1', '>='),
            "The source uses readonly properties or enums (PHP 8.1), but composer.json allows PHP {$floor}"
        );
    }

    /**
     * CI only runs 8.3, so nothing here executes on the oldest runtime
     * composer.json allows. Unpacking an array with STRING keys (`[...$a, ...['k' => $v]]`) is a
     * fatal `Error: Cannot unpack array with string keys` before PHP 8.1, and
     * the crash lands inside a `toArray()` a customer calls to log a result.
     * The floor is 8.1 now, so this holds the line should it ever be lowered.
     * That is invisible to a test suite on 8.3 and to `phpstan`, which checks
     * types rather than the floor version, so the source is asserted instead.
     *
     * Integer-keyed unpacking (`[...$a, ...$b]` over lists) has worked since
     * 7.4 and is fine; only a literal string key or a variable spread that can
     * carry one is at issue, so the pattern below looks for `=>` inside the
     * unpacked expression.
     */
    public function testSourceDoesNotUnpackStringKeyedArrays(): void
    {
        $offenders = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src')
        );

        foreach ($directory as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $number => $line) {
                if (preg_match('/\.\.\..*=>/', $line) === 1) {
                    $offenders[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "String-keyed array unpacking is fatal before PHP 8.1. "
                . "Build the array and assign the key instead:\n" . implode("\n", $offenders)
        );
    }

    /**
     * The absent case is asserted in testFindResultWithoutReplayMarker; this
     * is the present one on the SERIALIZED shape, because `toArray()` is what
     * a customer logs and it carries `credits_used: 5` next to it.
     */
    public function testFindResultToArrayKeepsTheReplayMarker(): void
    {
        foreach ([true, false] as $marker) {
            $result = new FindResult(
                email: 'john.smith@example.com',
                confidence: 95,
                pattern: 'first.last',
                verified: true,
                status: 'valid',
                creditsUsed: 5,
                creditsRemaining: 100,
                idempotentReplay: $marker
            );

            $array = $result->toArray();

            $this->assertArrayHasKey('idempotent_replay', $array);
            $this->assertSame($marker, $array['idempotent_replay']);
            // A float: credit amounts are fractional (a cached verify is 0.5).
            $this->assertSame(5.0, $array['credits_used']);
        }
    }

    public function testIncompleteJobDownloadPreservesReportedJobStatus(): void
    {
        $client = $this->downloadClient([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Job is still pending. Please wait for completion.',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $client->downloadResults('job-pending');
            $this->fail('Expected JobNotCompleteException');
        } catch (\Kawaa\Exceptions\JobNotCompleteException $error) {
            $this->assertSame('job-pending', $error->getJobId());
            $this->assertSame('pending', $error->getStatus());
        }
    }

    public function testAccountInfoHandlesWithheldUsageAndWithheldFreeGrant(): void
    {
        $account = \Kawaa\Models\AccountInfo::fromArray([
            'account' => [
                'user_id' => 'user-free-2',
                'email' => 'alias@example.com',
                'plan' => 'free',
                'status' => 'active',
                'created_at' => '2026-11-01T00:00:00Z',
            ],
            'credits' => [
                'balance' => 0,
                'monthly_allowance' => 100,
                'allowance_basis' => 'one_time_grant',
                'free_grant' => 'withheld',
            ],
            'withheld' => ['usage' => true],
        ]);

        $this->assertNull($account->creditsUsed);
        $this->assertTrue($account->usageWithheld);
        $this->assertSame('withheld', $account->freeGrant);
        $serialized = $account->toArray();
        $this->assertNull($serialized['credits_used']);
        $this->assertTrue($serialized['usage_withheld']);
        $this->assertSame('withheld', $serialized['free_grant']);
    }
}
