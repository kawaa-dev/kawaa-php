<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kawaa\Kawaa;
use Kawaa\Models\DownloadResult;
use Kawaa\Models\JobStatusResult;
use Kawaa\Models\VerificationFlags;
use Kawaa\Models\VerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * A download row says neither whether its verdict came from the cache nor
 * what the risk flags were. The client must not read that silence as "fresh"
 * and "no".
 *
 * The bodies are production shapes captured on 2026-11-16 with a test
 * account: one batch job, read with GET /v1/jobs/{id}?include_results=true
 * and with GET /v1/jobs/{id}/download?format=json. The same address was
 * served from cache and flagged bot_generated in the job read; the download
 * row carries neither field.
 */
class StatedFieldsTest extends TestCase
{
    private const JOB_ROW = [
        'job_id' => 'e4bb91c8-7aac-4088-8cad-2d2757a1e6ee',
        'batch_job_id' => 'e4bb91c8-7aac-4088-8cad-2d2757a1e6ee',
        'email' => 'zz-no-such-user-5f87f0b5b6@gmail.com',
        'status' => 'invalid',
        'sub_status' => 'mailbox_reject',
        'quality_score' => 10,
        'verification' => [
            'syntax_valid' => true, 'domain_exists' => true, 'mx_found' => true,
            'mx_records' => ['gmail-smtp-in.l.google.com'], 'smtp_check' => true, 'mailbox_exists' => false,
            'greylisted' => false, 'smtp_code' => 550, 'smtp_message' => '550 5.1.1 no such user',
        ],
        'flags' => [
            'disposable' => false, 'role_account' => false, 'free_provider' => true, 'spam_trap' => false,
            'abuse_email' => false, 'honeypot' => false, 'complainer' => false, 'bot_generated' => true,
        ],
        'enrichment' => ['country' => 'United States', 'country_code' => 'US'],
        'credits_used' => 0.5,
        'processing_time_ms' => 0,
        'verified_at' => '2026-11-16T09:40:03.385Z',
        'from_cache' => true,
        'classifier_version' => 7,
    ];

    private const DOWNLOAD = [
        'job_id' => 'e4bb91c8-7aac-4088-8cad-2d2757a1e6ee',
        'status' => 'completed',
        'result_count' => 1,
        'complete' => true,
        'expected_rows' => 1,
        'rows_missing' => 0,
        'results' => [[
            'email' => 'zz-no-such-user-5f87f0b5b6@gmail.com', 'status' => 'invalid', 'score' => 10,
            'disposable' => false, 'role' => false, 'free_provider' => true, 'suggestion' => null,
            'mx_records' => ['gmail-smtp-in.l.google.com'], 'smtp_response' => '550 5.1.1 no such user',
            'verified_at' => '2026-11-16T09:40:03.385Z', 'sub_status' => 'mailbox_reject',
        ]],
    ];

    public function testAJobRowStatesBothAndADownloadRowOfTheSameJobStatesNeither(): void
    {
        $job = JobStatusResult::fromArray([
            'job_id' => 'e4bb91c8-7aac-4088-8cad-2d2757a1e6ee', 'status' => 'completed', 'total_emails' => 1,
            'processed_emails' => 1, 'progress_percent' => 100, 'results' => [self::JOB_ROW],
        ]);
        $row = $job->results[0];
        $this->assertTrue($row->fromCache);
        $this->assertTrue($row->fromCacheStated);
        $this->assertTrue($row->flags->botGenerated);
        $this->assertTrue($row->flags->riskFlagsStated);

        $download = DownloadResult::fromArray(self::DOWNLOAD)->results[0];
        $this->assertSame('2026-11-16T09:40:03.385Z', $download->verifiedAt);
        // The same verdict: false here is "not stated", which the accessors say.
        $this->assertFalse($download->fromCache);
        $this->assertFalse($download->fromCacheStated, 'a download row does not say whether it came from cache');
        $this->assertFalse($download->flags->botGenerated);
        $this->assertFalse($download->flags->riskFlagsStated, 'a download row carries no risk flags');
        // What the row does carry is still read.
        $this->assertTrue($download->flags->freeProvider);
        $this->assertSame('mailbox_reject', $download->subStatus);
    }

    public function testARoundTripThroughToArrayKeepsNotStated(): void
    {
        $download = DownloadResult::fromArray(self::DOWNLOAD)->results[0];
        $array = $download->toArray();
        $this->assertFalse($array['from_cache_stated']);
        $this->assertFalse($array['flags']['risk_flags_stated']);

        $again = VerificationResult::fromArray($array);
        $this->assertFalse($again->fromCacheStated, 'its false from_cache must not come back as a finding');
        $this->assertFalse($again->flags->riskFlagsStated);

        $job = VerificationResult::fromArray(self::JOB_ROW);
        $back = VerificationResult::fromArray($job->toArray());
        $this->assertTrue($back->fromCache);
        $this->assertTrue($back->fromCacheStated);
        $this->assertTrue($back->flags->botGenerated);
        $this->assertTrue($back->flags->riskFlagsStated);
    }

    public function testAPendingAnswerDoesNotSayWhetherItCameFromCache(): void
    {
        // A key without verification:read cannot read the replayed job back,
        // so verify() returns the replay's pending pointer itself.
        $replay = [
            'job_id' => '294f52e0-ce80-43b3-9cd6-72e271976475', 'email' => 'zz-no-such-user-f4c48aaaac@gmail.com',
            'status' => 'pending', 'idempotent_replay' => true,
            'message' => 'This request was already accepted under the same Idempotency-Key; nothing more was charged.',
            'credits_used' => 0, 'credits_remaining' => 938.75,
        ];
        $scope = ['success' => false, 'error' => ['code' => 'INSUFFICIENT_SCOPE', 'message' => 'no verification:read', 'retryable' => false]];
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['success' => true, 'data' => $replay])),
                new Response(403, ['Content-Type' => 'application/json'], json_encode($scope)),
            ])),
        ]);
        $client = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($client))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $http);

        $result = $client->verify('zz-no-such-user-f4c48aaaac@gmail.com', idempotencyKey: 'k-1');
        $this->assertTrue($result->isPending());
        $this->assertFalse($result->fromCacheStated);
        $this->assertFalse($result->flags->riskFlagsStated);
    }

    public function testValuesOfTheWrongTypeAreNotStated(): void
    {
        $result = VerificationResult::fromArray([
            'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90,
            'flags' => ['abuse_email' => 'true', 'complainer' => 1, 'honeypot' => null, 'bot_generated' => 'no'],
            'from_cache' => 'yes',
        ]);
        $this->assertFalse($result->fromCacheStated);
        $this->assertFalse($result->flags->riskFlagsStated);
    }

    public function testObjectsYouBuildYourselfStateWhatYouGaveThem(): void
    {
        $flags = new VerificationFlags(botGenerated: true);
        $this->assertTrue($flags->riskFlagsStated);
        $this->assertTrue(VerificationFlags::fromArray($flags->toArray())->riskFlagsStated);
    }
}
