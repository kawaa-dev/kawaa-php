<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kawaa\Kawaa;
use Kawaa\Models\VerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * email_unicode reaches the caller.
 *
 * The API checks an address with an internationalized domain in its ASCII
 * form, and every job result row and download row says so in email
 * ('info@xn--mller-kva.de'). Since November 17, 2026 each row also carries
 * email_unicode, the same address written back in Unicode ('info@müller.de'),
 * which is what a caller matches a row to its own list by. Up to 0.3.2 the
 * client dropped it.
 */
class EmailUnicodeTest extends TestCase
{
    private const JOB_ID = '2e31e0ec-c747-4aec-9b37-1a24c184ee7b';
    private const ASCII = 'info@xn--mller-kva.de';
    private const UNICODE = 'info@müller.de';

    private static function jobRow(array $fields = []): array
    {
        return array_merge([
            'job_id' => self::JOB_ID,
            'batch_job_id' => self::JOB_ID,
            'email' => self::ASCII,
            'status' => 'invalid',
            'sub_status' => 'no_mx',
            'quality_score' => 10,
            'flags' => ['disposable' => false, 'role_account' => true, 'free_provider' => false],
            'verification' => ['syntax_valid' => true, 'domain_exists' => true, 'mx_found' => false],
            'verified_at' => '2026-11-18T02:56:58.165Z',
            'from_cache' => false,
        ], $fields);
    }

    private static function downloadRow(array $fields = []): array
    {
        return array_merge([
            'email' => self::ASCII, 'status' => 'invalid', 'score' => 10, 'disposable' => false, 'role' => true,
            'free_provider' => false, 'suggestion' => null, 'mx_records' => [], 'smtp_response' => null,
            'verified_at' => '2026-11-18T02:56:58.165Z', 'sub_status' => 'no_mx',
        ], $fields);
    }

    private static function clientAnswering(array $body): Kawaa
    {
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'application/json'], json_encode($body)),
            ])),
        ]);
        $client = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($client))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $http);
        return $client;
    }

    public function testJobResultRowsCarryEmailUnicode(): void
    {
        $client = self::clientAnswering(['success' => true, 'data' => [
            'job_id' => self::JOB_ID, 'status' => 'completed', 'total_emails' => 2, 'processed_emails' => 2,
            'progress_percent' => 100, 'summary' => ['valid' => 0, 'invalid' => 2, 'risky' => 0, 'unknown' => 0],
            'results' => [
                self::jobRow(['email_unicode' => self::UNICODE]),
                self::jobRow(['email' => 'a@example.com', 'email_unicode' => 'a@example.com']),
            ],
            'next_key' => null, 'has_more' => false,
        ]]);

        $job = $client->getJobStatus(self::JOB_ID, true);

        $this->assertSame(self::ASCII, $job->results[0]->email);
        $this->assertSame(self::UNICODE, $job->results[0]->emailUnicode);
        $this->assertSame('a@example.com', $job->results[1]->emailUnicode);
    }

    public function testDownloadRowsCarryEmailUnicode(): void
    {
        $client = self::clientAnswering([
            'job_id' => self::JOB_ID, 'status' => 'completed', 'result_count' => 1, 'complete' => true,
            'expected_rows' => 1, 'rows_missing' => 0,
            'results' => [self::downloadRow(['email_unicode' => self::UNICODE])],
        ]);

        $download = $client->downloadResults(self::JOB_ID);

        $this->assertSame(self::ASCII, $download->results[0]->email);
        $this->assertSame(self::UNICODE, $download->results[0]->emailUnicode);
        $this->assertSame('no_mx', $download->results[0]->subStatus);
    }

    public function testAMissingOrWrongTypedValueIsNull(): void
    {
        foreach ([[], ['email_unicode' => null], ['email_unicode' => 7], ['email_unicode' => ['x']]] as $fields) {
            $this->assertNull(VerificationResult::fromArray(self::jobRow($fields))->emailUnicode);
            $this->assertNull(VerificationResult::fromArray(self::downloadRow($fields))->emailUnicode);
        }
    }

    public function testARoundTripThroughToArrayKeepsIt(): void
    {
        $row = VerificationResult::fromArray(self::downloadRow(['email_unicode' => self::UNICODE]));

        $this->assertSame(self::UNICODE, $row->toArray()['email_unicode']);
        $this->assertSame(self::UNICODE, VerificationResult::fromArray($row->toArray())->emailUnicode);
    }
}
