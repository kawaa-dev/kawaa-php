<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kawaa\Exceptions\KawaaException;
use Kawaa\Kawaa;
use Kawaa\Models\BatchJobResult;
use Kawaa\Models\DownloadResult;
use Kawaa\Models\JobStatusResult;
use Kawaa\Models\VerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * Fields the API sends that 0.2.0 dropped.
 *
 * The bodies are production response shapes captured on 2026-11-16 with a
 * test account and test addresses: POST /v1/verify (fresh with
 * options.enrich, and a cache hit) and POST /v1/verify/batch.
 */
class DroppedFieldsTest extends TestCase
{
    private const FRESH_WITH_ENRICHMENT = [
        'classifier_version' => 7,
        'credits_remaining' => 975.25,
        'credits_used' => 1,
        'domain_info' => ['auth_grade' => 'C', 'auth_score' => 74, 'domain' => 'gmail.com', 'provider_type' => 'free'],
        'email' => 'zz-no-such-user-2b192d6a1b@gmail.com',
        'enrichment' => ['country' => 'United States', 'country_code' => 'US'],
        'flags' => [
            'abuse_email' => false, 'bot_generated' => false, 'complainer' => false, 'disposable' => false,
            'free_provider' => true, 'honeypot' => false, 'role_account' => false, 'spam_trap' => false,
        ],
        'from_cache' => false,
        'job_id' => 'a033a8e2-bf86-42f1-97cc-6707bbea33ea',
        'processing_time_ms' => 153,
        'quality_score' => 10,
        'status' => 'invalid',
        'sub_status' => 'mailbox_reject',
        'verification' => [
            'mailbox_exists' => false, 'mx_records' => ['gmail-smtp-in.l.google.com'],
            'smtp_code' => 550, 'smtp_message' => '550 5.1.1 no such user',
        ],
        'verified_at' => '2026-11-16T02:31:49.759Z',
    ];

    private const CACHED = [
        'email' => 'zz-no-such-user-2b192d6a1b@gmail.com',
        'status' => 'invalid',
        'sub_status' => 'mailbox_reject',
        'quality_score' => 10,
        'verification' => ['mailbox_exists' => false, 'smtp_code' => 550],
        'flags' => [
            'disposable' => false, 'role_account' => false, 'free_provider' => true, 'spam_trap' => false,
            'abuse_email' => false, 'honeypot' => false, 'complainer' => false, 'bot_generated' => false,
        ],
        'verified_at' => '2026-11-16T02:25:50.384Z',
        'from_cache' => true,
        'cache_age_seconds' => 269,
        'credits_used' => 0.5,
        'credits_remaining' => 981.25,
    ];

    /** @param array<Response> $responses */
    private function client(array $responses): Kawaa
    {
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);
        $client = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($client))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $http);
        return $client;
    }

    private static function envelope(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['success' => true, 'data' => $data]));
    }

    public function testVerifyKeepsTheEnrichmentTheRequestAskedFor(): void
    {
        $client = $this->client([self::envelope(self::FRESH_WITH_ENRICHMENT)]);
        $result = $client->verify('zz-no-such-user-2b192d6a1b@gmail.com', enrich: true);

        $this->assertNotNull($result->enrichment, 'the API answered options.enrich; the result must carry it');
        $this->assertSame('United States', $result->enrichment->country);
        $this->assertSame('US', $result->enrichment->countryCode);
        $this->assertNull($result->enrichment->firstName);
        $this->assertFalse($result->fromCache);
        $this->assertNull($result->cacheAgeSeconds);
        $this->assertSame('2026-11-16T02:31:49.759Z', $result->verifiedAt);
        $this->assertSame(['country' => 'United States', 'country_code' => 'US'], array_filter($result->toArray()['enrichment']));
    }

    public function testACachedVerdictSaysHowOldItIs(): void
    {
        $result = VerificationResult::fromArray(self::CACHED);

        $this->assertTrue($result->fromCache);
        $this->assertSame(269, $result->cacheAgeSeconds);
        $this->assertSame('2026-11-16T02:25:50.384Z', $result->verifiedAt, 'the time of the original check');
        $this->assertNull($result->enrichment, 'not asked for');

        // A round trip through toArray() keeps them.
        $again = VerificationResult::fromArray($result->toArray());
        $this->assertTrue($again->fromCache);
        $this->assertSame(269, $again->cacheAgeSeconds);
        $this->assertSame('2026-11-16T02:25:50.384Z', $again->verifiedAt);
    }

    public function testTheNewFieldsAreReadLeniently(): void
    {
        // Charged before it is parsed: a wrong-typed value is "not stated",
        // never a TypeError that drives a retry under a fresh key.
        $result = VerificationResult::fromArray([
            'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90, 'flags' => [
                'abuse_email' => 'true', 'complainer' => 1, 'honeypot' => null, 'bot_generated' => 'no',
            ],
            'enrichment' => 'unexpected', 'from_cache' => 'yes', 'cache_age_seconds' => '269', 'verified_at' => 17,
        ]);
        $this->assertNull($result->enrichment);
        $this->assertFalse($result->fromCache);
        $this->assertNull($result->cacheAgeSeconds);
        $this->assertNull($result->verifiedAt);
        $this->assertFalse($result->flags->abuseEmail);
        $this->assertFalse($result->flags->complainer);
        $this->assertFalse($result->flags->honeypot);
        $this->assertFalse($result->flags->botGenerated);

        $partial = VerificationResult::fromArray([
            'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90, 'flags' => [],
            'enrichment' => ['first_name' => 'Ada', 'last_name' => null, 'gender' => 3, 'country_code' => 'GB'],
        ]);
        $this->assertNotNull($partial->enrichment);
        $this->assertSame('Ada', $partial->enrichment->firstName);
        $this->assertNull($partial->enrichment->lastName);
        $this->assertNull($partial->enrichment->gender);
        $this->assertSame('GB', $partial->enrichment->countryCode);

        $null = VerificationResult::fromArray([
            'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90, 'flags' => [], 'enrichment' => null,
        ]);
        $this->assertNull($null->enrichment);
    }

    public function testTheAbuseAndComplainerFlagsAreKept(): void
    {
        $result = VerificationResult::fromArray([
            'email' => 'abuse@spamhaus.org', 'status' => 'risky', 'quality_score' => 20,
            'flags' => [
                'disposable' => false, 'role_account' => true, 'free_provider' => false, 'spam_trap' => false,
                'abuse_email' => true, 'honeypot' => true, 'complainer' => true, 'bot_generated' => true,
            ],
        ]);
        $this->assertTrue($result->flags->abuseEmail);
        $this->assertTrue($result->flags->complainer);
        $this->assertTrue($result->flags->honeypot);
        $this->assertTrue($result->flags->botGenerated);
        $this->assertTrue($result->flags->role);

        $array = $result->flags->toArray();
        $this->assertTrue($array['abuse_email']);
        $this->assertTrue($array['complainer']);
        $again = VerificationResult::fromArray($result->toArray());
        $this->assertTrue($again->flags->complainer);
        $this->assertTrue($again->flags->abuseEmail);

        $fresh = VerificationResult::fromArray(self::FRESH_WITH_ENRICHMENT);
        $this->assertFalse($fresh->flags->abuseEmail);
        $this->assertFalse($fresh->flags->complainer);
    }

    public function testJobResultRowsAndDownloadRowsKeepTheNewFields(): void
    {
        $job = JobStatusResult::fromArray([
            'job_id' => 'j1', 'status' => 'completed', 'total_emails' => 1, 'processed_emails' => 1,
            'progress_percent' => 100, 'summary' => ['valid' => 0, 'invalid' => 1, 'risky' => 0, 'unknown' => 0],
            'results' => [self::CACHED],
        ]);
        $this->assertCount(1, $job->results);
        $this->assertTrue($job->results[0]->fromCache);
        $this->assertSame(269, $job->results[0]->cacheAgeSeconds);

        // A flat download row carries verified_at at the top level.
        $download = DownloadResult::fromArray([
            'job_id' => 'j1', 'result_count' => 1, 'complete' => true, 'expected_rows' => 1, 'rows_missing' => 0,
            'results' => [[
                'email' => 'zz-no-such-user-9e98fb73f0@gmail.com', 'status' => 'invalid', 'score' => 10,
                'disposable' => false, 'role' => false, 'free_provider' => true, 'mx_records' => ['gmail-smtp-in.l.google.com'],
                'smtp_response' => '550 5.1.1 no such user', 'verified_at' => '2026-11-16T02:26:48.656Z',
                'sub_status' => 'mailbox_reject',
            ]],
        ]);
        $this->assertSame('2026-11-16T02:26:48.656Z', $download->results[0]->verifiedAt);
    }

    public function testBatchAcceptanceReportsSkippedAndUnconfirmedAddresses(): void
    {
        $job = BatchJobResult::fromArray([
            'credits_remaining' => 987.75, 'credits_used' => 3, 'duplicates_removed' => 1, 'estimated_time_seconds' => 6,
            'invalid_emails_skipped' => 1, 'job_id' => '92b44b4d-4594-4262-bac5-d3ffb78a62dd',
            'message' => 'Batch verification started.', 'status' => 'processing', 'total_submitted' => 5, 'unique_emails' => 3,
        ]);
        $this->assertSame(1, $job->invalidEmailsSkipped);
        $this->assertNull($job->enqueueUnknown);
        $this->assertSame(1, $job->toArray()['invalid_emails_skipped']);

        $held = BatchJobResult::fromArray([
            'job_id' => 'j2', 'status' => 'enqueue_unknown', 'total_submitted' => 3, 'unique_emails' => 1,
            'credits_used' => 1, 'credits_held' => 2, 'enqueue_unknown' => 2, 'invalid_emails_skipped' => 0,
        ]);
        $this->assertSame(2, $held->enqueueUnknown);
        $this->assertSame(0, $held->invalidEmailsSkipped);

        $odd = BatchJobResult::fromArray([
            'job_id' => 'j3', 'status' => 'processing', 'total_submitted' => 1, 'unique_emails' => 1,
            'credits_used' => 1, 'invalid_emails_skipped' => 'one', 'enqueue_unknown' => null,
        ]);
        $this->assertSame('j3', $odd->jobId, 'a wrong-typed count never loses the job id of a charged batch');
        $this->assertNull($odd->invalidEmailsSkipped);
        $this->assertNull($odd->enqueueUnknown);
    }

    public function testWaitForJobSaysWhyAJobFailed(): void
    {
        $failed = [
            'job_id' => 'job_f', 'status' => 'failed', 'total_emails' => 1, 'processed_emails' => 0,
            'progress_percent' => 0, 'credits_used' => 1, 'credits_refunded' => 1, 'billing_status' => 'refunded',
            'error_message' => 'Verification failed and was refunded',
        ];
        $this->assertSame('Verification failed and was refunded', JobStatusResult::fromArray($failed)->errorMessage);
        $this->assertNull(JobStatusResult::fromArray(['job_id' => 'j', 'error_message' => 5])->errorMessage);

        $client = $this->client([self::envelope($failed)]);
        try {
            $client->waitForJob('job_f', 0.01, 5);
            $this->fail('a failed job must throw');
        } catch (KawaaException $e) {
            $this->assertSame('Job job_f failed: Verification failed and was refunded', $e->getMessage());
        }
    }
}
