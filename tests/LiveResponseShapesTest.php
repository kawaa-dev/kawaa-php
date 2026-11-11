<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Kawaa;
use Kawaa\Models\JobStatusResult;
use Kawaa\Models\VerificationDetails;
use Kawaa\Models\VerificationResult;
use PHPUnit\Framework\TestCase;

/**
 * The fields the API actually sends survive parsing.
 *
 * The fixtures in testdata/responses are production response bodies
 * (captured 2026-10-01 with test accounts and test addresses). A
 * verification's SMTP reply is verification.smtp_message and
 * verification.smtp_code; VerificationDetails read smtp_response, which only
 * the flat download rows carry, so every single verification and every job
 * result row parsed with a null smtpResponse.
 */
class LiveResponseShapesTest extends TestCase
{
    private function body(string $name): array
    {
        $raw = file_get_contents(SharedFixture::path('responses/' . $name));
        $this->assertIsString($raw, "fixture {$name} is readable");
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testASingleVerificationKeepsTheSmtpReply(): void
    {
        $result = VerificationResult::fromArray($this->body('verify-catch-all.json')['data']);

        $this->assertSame('catch_all', $result->subStatus);
        $this->assertNotNull($result->verification);
        $this->assertSame(['smtp.google.com'], $result->verification->mxRecords);
        $this->assertSame(
            '250 2.1.5 OK d75a77b69052e-53398dad470si11368041cf.274 - gsmtp',
            $result->verification->smtpResponse,
        );
        $this->assertSame(250, $result->verification->smtpCode);
    }

    public function testJobResultRowsKeepItToo(): void
    {
        $job = JobStatusResult::fromArray($this->body('job-completed-with-results.json')['data']);
        [$disposable, $catchAll] = $job->results;

        // No mailbox probe ran for the disposable address: nothing to report.
        $this->assertSame(['mail2.mailinator.com', 'mail.mailinator.com'], $disposable->verification->mxRecords);
        $this->assertNull($disposable->verification->smtpResponse);
        $this->assertNull($disposable->verification->smtpCode);

        $this->assertSame('catch_all', $catchAll->subStatus);
        $this->assertSame(
            '250 2.1.5 OK 6a1803df08f44-917edbc1a7fsi7030706d6.537 - gsmtp',
            $catchAll->verification->smtpResponse,
        );
        $this->assertSame(250, $catchAll->verification->smtpCode);
    }

    public function testFlatDownloadRowsStillReadSmtpResponse(): void
    {
        $rows = array_map(
            fn(array $row) => VerificationResult::fromArray($row),
            $this->body('download-json.json')['results'],
        );

        $this->assertNull($rows[0]->verification->smtpResponse);
        $this->assertSame(
            '250 2.1.5 OK 6a1803df08f44-917edbc1a7fsi7030706d6.537 - gsmtp',
            $rows[1]->verification->smtpResponse,
        );
        $this->assertNull($rows[1]->verification->smtpCode);
    }

    /**
     * The API states its catch-all finding as verification.catch_all (a flat
     * download row: catch_all) and leaves it out when nothing measured it: a
     * disposable verdict, no usable mail server, a refused or unanswered
     * mailbox. flags->catchAll reads that absence as false;
     * flags->catchAllMeasured tells the two apart.
     */
    public function testRowsSayWhetherCatchAllWasMeasured(): void
    {
        $single = VerificationResult::fromArray($this->body('verify-catch-all.json')['data']);
        $this->assertTrue($single->flags->catchAll);
        $this->assertTrue($single->flags->catchAllMeasured);

        [$disposable, $catchAll] = JobStatusResult::fromArray(
            $this->body('job-completed-with-results.json')['data']
        )->results;
        $this->assertFalse($disposable->flags->catchAll);
        $this->assertFalse($disposable->flags->catchAllMeasured, 'the disposable row was never probed');
        $this->assertTrue($catchAll->flags->catchAll);
        $this->assertTrue($catchAll->flags->catchAllMeasured);

        [$disposableRow, $catchAllRow] = array_map(
            fn(array $row) => VerificationResult::fromArray($row),
            $this->body('download-json.json')['results'],
        );
        $this->assertFalse($disposableRow->flags->catchAll);
        $this->assertFalse($disposableRow->flags->catchAllMeasured, 'the disposable row has no catch_all value');
        $this->assertTrue($catchAllRow->flags->catchAll);
        $this->assertTrue($catchAllRow->flags->catchAllMeasured);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function catchAllCases(): array
    {
        return [
            'stated false' => [['status' => 'valid', 'verification' => ['smtp_check' => true, 'catch_all' => false]], false, true],
            'stated true' => [['status' => 'catch_all', 'verification' => ['catch_all' => true]], true, true],
            'catch_all status alone' => [['status' => 'catch_all', 'verification' => []], true, true],
            'omitted' => [['status' => 'unknown', 'verification' => ['syntax_valid' => true]], false, false],
            'no verification' => [['status' => 'unknown'], false, false],
            'pending' => [['status' => 'pending', 'job_id' => 'job-1'], false, false],
            'flags false is not a measurement' => [['status' => 'unknown', 'flags' => ['catch_all' => false], 'verification' => []], false, false],
            'flat row, stated false' => [['status' => 'valid', 'disposable' => false, 'catch_all' => false], false, true],
            'flat row, left empty' => [['status' => 'unknown', 'disposable' => false], false, false],
        ];
    }

    /**
     * @dataProvider catchAllCases
     * @param array<string, mixed> $body
     */
    public function testCatchAllIsMeasuredOnlyWhenStated(array $body, bool $catchAll, bool $measured): void
    {
        $result = VerificationResult::fromArray(['email' => 'a@example.com'] + $body);

        $this->assertSame($catchAll, $result->flags->catchAll);
        $this->assertSame($measured, $result->flags->catchAllMeasured);
        $this->assertSame($measured, $result->flags->toArray()['catch_all_measured']);
    }

    /**
     * @dataProvider catchAllCases
     * @param array<string, mixed> $body
     */
    public function testToArrayKeepsWhatWasMeasured(array $body, bool $catchAll, bool $measured): void
    {
        $result = VerificationResult::fromArray(['email' => 'a@example.com'] + $body);
        $again = VerificationResult::fromArray($result->toArray());

        $this->assertSame($catchAll, $again->flags->catchAll);
        $this->assertSame($measured, $again->flags->catchAllMeasured);
    }

    public function testDetailsCarryTheStatedFinding(): void
    {
        $this->assertFalse(VerificationDetails::fromArray(['catch_all' => false])->catchAll);
        $this->assertTrue(VerificationDetails::fromArray(['catch_all' => true])->catchAll);
        $this->assertNull(VerificationDetails::fromArray([])->catchAll);
        $this->assertNull(VerificationDetails::fromArray(['catch_all' => 'no'])->catchAll);
        $this->assertArrayNotHasKey('catch_all', VerificationDetails::fromArray([])->toArray());
    }

    public function testAWrongTypedCodeIsNotACode(): void
    {
        $this->assertNull(VerificationDetails::fromArray(['smtp_code' => '250'])->smtpCode);
    }

    public function testUserAgentNamesTheSdkVersion(): void
    {
        $client = new Kawaa('test-api-key');
        $getClient = (new \ReflectionClass($client))->getMethod('getClient');
        $getClient->setAccessible(true);
        /** @var \GuzzleHttp\Client $http */
        $http = $getClient->invoke($client);

        $this->assertSame('kawaa-php/' . Kawaa::VERSION, $http->getConfig('headers')['User-Agent']);
    }

    public function testTheSdkVersionIsTheReleaseItShipsIn(): void
    {
        // Composer takes a package's version from its release tag, so the PHP
        // SDK has no manifest of its own to read. Its changelog names the
        // release this source is for, in its first `## [x.y.z]` heading. This
        // used to read sdks/nodejs/package.json, but the Node.js and Python
        // packages now release on their own: 0.2.1 and 0.2.2 are theirs alone,
        // and this SDK is still the unreleased 0.2.0.
        $changelog = __DIR__ . '/../CHANGELOG.md';
        if (!is_file($changelog)) {
            $this->markTestSkipped('no CHANGELOG.md beside the package');
        }
        $this->assertSame(
            1,
            preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', (string)file_get_contents($changelog), $release),
            'CHANGELOG.md names no release'
        );

        $this->assertSame($release[1], Kawaa::VERSION);
    }
}
