<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kawaa\Kawaa;
use Kawaa\Models\VerificationFlags;
use Kawaa\Models\VerificationResult;
use Kawaa\Models\VerificationStatus;
use PHPUnit\Framework\TestCase;

/**
 * Download rows carry three risk flags, and a new reason code passes through.
 *
 * The download gained abuse_email, complainer and bot_generated as its last
 * columns (after email_unicode). A result stored without them gets an empty
 * CSV cell and no JSON key, so a flag the row does not state must read as not
 * stated, never as a confident false. There is no honeypot column, so a
 * download row never states all four, and riskFlagsStated stays false there.
 * The API also added the reason ip_address_mx (risky: a mailbox accepted at
 * an MX record that names an IP address); $subStatus is an open string.
 */
class DownloadFlagsTest extends TestCase
{
    private const JOB_ID = '2e31e0ec-c747-4aec-9b37-1a24c184ee7b';

    private static function downloadRow(array $fields = []): array
    {
        return array_merge([
            'email' => 'abuse@example.org', 'status' => 'valid', 'score' => 40, 'disposable' => false,
            'role' => true, 'free_provider' => false, 'suggestion' => null, 'mx_records' => ['mx.example.org'],
            'smtp_response' => '250 2.1.5 OK', 'verified_at' => '2026-11-18T02:56:58.165Z',
            'sub_status' => 'mailbox_exists', 'email_unicode' => 'abuse@example.org',
        ], $fields);
    }

    private static function clientAnswering(string $contentType, string $body): Kawaa
    {
        $http = new Client([
            'base_uri' => 'https://api.kawaa.com',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => $contentType], $body),
            ])),
        ]);
        $client = new Kawaa('test-api-key');
        $property = (new \ReflectionClass($client))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($client, $http);
        return $client;
    }

    public function testADownloadRowCarriesTheThreeFlagsAsStated(): void
    {
        $client = self::clientAnswering('application/json', json_encode([
            'job_id' => self::JOB_ID, 'status' => 'completed', 'result_count' => 1, 'complete' => true,
            'expected_rows' => 1, 'rows_missing' => 0,
            'results' => [self::downloadRow(['abuse_email' => true, 'complainer' => false, 'bot_generated' => false])],
        ]));

        $flags = $client->downloadResults(self::JOB_ID)->results[0]->flags;

        $this->assertTrue($flags->riskFlag('abuse_email'));
        $this->assertFalse($flags->riskFlag('complainer'));
        $this->assertFalse($flags->riskFlag('bot_generated'));
        $this->assertNull($flags->riskFlag('honeypot'), 'there is no honeypot column');
        $this->assertFalse($flags->riskFlagsStated, 'a download row never states all four');
        $this->assertTrue($flags->abuseEmail);
    }

    public function testAnUnstatedOrWrongTypedFlagIsNull(): void
    {
        $cases = [
            [],
            ['abuse_email' => null, 'complainer' => null, 'bot_generated' => null],
            ['abuse_email' => 'true', 'complainer' => 1, 'bot_generated' => 'no'],
        ];
        foreach ($cases as $fields) {
            $flags = VerificationResult::fromArray(self::downloadRow($fields))->flags;
            foreach (['abuse_email', 'complainer', 'honeypot', 'bot_generated'] as $name) {
                $this->assertNull($flags->riskFlag($name), $name . ' ' . json_encode($fields));
            }
            $this->assertFalse($flags->riskFlagsStated);
        }
    }

    public function testAJobRowStatesAllFour(): void
    {
        $flags = VerificationFlags::fromArray([
            'disposable' => false, 'role_account' => false, 'free_provider' => true, 'spam_trap' => false,
            'abuse_email' => false, 'honeypot' => false, 'complainer' => false, 'bot_generated' => true,
        ]);

        $this->assertTrue($flags->riskFlagsStated);
        $this->assertTrue($flags->riskFlag('bot_generated'));
        $this->assertFalse($flags->riskFlag('honeypot'));
    }

    public function testARoundTripThroughToArrayKeepsWhichFlagsWereStated(): void
    {
        $row = VerificationResult::fromArray(self::downloadRow(['abuse_email' => true, 'complainer' => false, 'bot_generated' => false]));

        $again = VerificationResult::fromArray($row->toArray())->flags;

        $this->assertTrue($again->riskFlag('abuse_email'));
        $this->assertFalse($again->riskFlag('complainer'));
        $this->assertNull($again->riskFlag('honeypot'), 'its false honeypot must not come back as a finding');
        $this->assertFalse($again->riskFlagsStated);
    }

    public function testAnArrayFromAnOlderToArrayStillReadsAsNotStated(): void
    {
        // toArray() up to 0.3.3 wrote risk_flags_stated and no stated_risk_flags.
        $flags = VerificationFlags::fromArray([
            'abuse_email' => false, 'complainer' => false, 'honeypot' => false, 'bot_generated' => false,
            'risk_flags_stated' => false,
        ]);

        $this->assertNull($flags->riskFlag('abuse_email'));
        $this->assertFalse($flags->riskFlagsStated);
    }

    public function testFlagsBuiltByHandAnswerFromTheirProperties(): void
    {
        $flags = new VerificationFlags(abuseEmail: true);

        $this->assertTrue($flags->riskFlag('abuse_email'));
        $this->assertFalse($flags->riskFlag('honeypot'));
    }

    public function testAnUnknownFlagNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VerificationFlags())->riskFlag('abuseEmail');
    }

    public function testACsvDownloadWithTheNewColumnsIsReturnedAsSent(): void
    {
        $csv = "email,status,score,disposable,role,free_provider,catch_all,suggestion,mx_records,smtp_response,"
            . "verified_at,sub_status,email_unicode,abuse_email,complainer,bot_generated\n"
            . "abuse@example.org,valid,40,false,true,false,,,mx.example.org,250 2.1.5 OK,"
            . "2026-11-18T02:56:58.165Z,mailbox_exists,abuse@example.org,true,false,false\n";
        $client = self::clientAnswering('text/csv', $csv);

        $this->assertSame($csv, $client->downloadResults(self::JOB_ID, format: 'csv'));
    }

    public function testIpAddressMxPassesThrough(): void
    {
        $row = VerificationResult::fromArray(self::downloadRow(['status' => 'risky', 'sub_status' => 'ip_address_mx']));
        $verified = VerificationResult::fromArray([
            'email' => 'user@example.org', 'status' => 'risky', 'sub_status' => 'ip_address_mx', 'quality_score' => 40,
            'flags' => ['disposable' => false, 'role_account' => false, 'free_provider' => false],
            'verification' => ['syntax_valid' => true, 'domain_exists' => true, 'mx_found' => true],
        ]);

        foreach ([$row, $verified] as $result) {
            $this->assertSame(VerificationStatus::RISKY, $result->status);
            $this->assertSame('ip_address_mx', $result->subStatus);
        }
    }
}
