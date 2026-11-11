<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * X-Kawaa-Signature-V2: t=<unix seconds>,v1=<hex HMAC-SHA256 of
 * "<t>.<raw body>">, checked against a replay window.
 *
 * testdata/signatures/webhook-signature-v2.json holds known-answer
 * vectors every SDK verifies the same way. Its timestamps are fixed, so they
 * are checked with the fixture's own wide tolerance; the default 300-second
 * window is exercised with headers signed at the current time.
 */
class WebhookSignatureV2Test extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $raw = file_get_contents(SharedFixture::path('signatures/webhook-signature-v2.json'));
        $this->assertIsString($raw);
        $fixture = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);

        return $fixture;
    }

    /** Build a header with hash_hmac directly, never through the verifier. */
    private static function signV2(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public function testDefaultToleranceIsFiveMinutes(): void
    {
        $this->assertSame(300, Webhook::DEFAULT_TOLERANCE_SECONDS);
    }

    public function testFixtureBodyIsHashedAsUtf8Bytes(): void
    {
        $f = $this->fixture();

        $this->assertNotSame(1, preg_match('/\A[\x00-\x7F]*\z/', $f['body']), 'the fixture body should carry a non-ASCII character');
        $this->assertSame($f['header'], self::signV2($f['body'], $f['secret'], $f['timestamp']));
    }

    public function testEveryValidFixtureHeaderVerifies(): void
    {
        $f = $this->fixture();
        $tolerance = $f['tolerance_seconds_for_fixed_vectors'];

        $this->assertNotEmpty($f['valid_headers']);
        foreach ($f['valid_headers'] as $header) {
            $this->assertTrue(
                Webhook::verifySignatureV2($f['body'], $header, $f['secret'], $tolerance),
                'valid header refused: ' . var_export($header, true),
            );
        }
        $this->assertTrue(Webhook::verifySignatureV2($f['body'], $f['header'], $f['secret'], $tolerance));
    }

    public function testEveryInvalidFixtureHeaderIsRejected(): void
    {
        $f = $this->fixture();
        $tolerance = $f['tolerance_seconds_for_fixed_vectors'];

        $this->assertNotEmpty($f['invalid_headers']);
        foreach ($f['invalid_headers'] as $header) {
            $this->assertFalse(
                Webhook::verifySignatureV2($f['body'], $header, $f['secret'], $tolerance),
                'invalid header accepted: ' . var_export($header, true),
            );
        }
    }

    public function testFixedVectorIsStaleUnderTheDefaultWindow(): void
    {
        $f = $this->fixture();

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], $f['header'], $f['secret']));
    }

    public function testHeaderSignedNowVerifiesWithTheDefaultTolerance(): void
    {
        $f = $this->fixture();

        $this->assertTrue(Webhook::verifySignatureV2($f['body'], self::signV2($f['body'], $f['secret'], time()), $f['secret']));
    }

    public function testHeaderSigned600SecondsAgoNeedsAWiderTolerance(): void
    {
        $f = $this->fixture();
        $header = self::signV2($f['body'], $f['secret'], time() - 600);

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], $header, $f['secret']));
        $this->assertTrue(Webhook::verifySignatureV2($f['body'], $header, $f['secret'], 3600));
    }

    public function testHeaderSigned600SecondsAheadIsRejected(): void
    {
        $f = $this->fixture();
        $header = self::signV2($f['body'], $f['secret'], time() + 600);

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], $header, $f['secret']));
        $this->assertTrue(Webhook::verifySignatureV2($f['body'], $header, $f['secret'], 3600));
    }

    public function testWrongSecretIsRejected(): void
    {
        $f = $this->fixture();
        $header = self::signV2($f['body'], $f['secret'], time());

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], $header, 'whsec_some_other_secret'));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $f = $this->fixture();
        $header = self::signV2($f['body'], $f['secret'], time());
        $tampered = str_replace('job_v2_vector', 'job_v2_vectoR', $f['body']);

        $this->assertNotSame($f['body'], $tampered);
        $this->assertFalse(Webhook::verifySignatureV2($tampered, $header, $f['secret']));
    }

    public function testMissingOrBlankSecretIsRejected(): void
    {
        $f = $this->fixture();

        foreach ([null, '', '   '] as $secret) {
            $header = self::signV2($f['body'], (string) $secret, time());
            $this->assertFalse(
                Webhook::verifySignatureV2($f['body'], $header, $secret),
                'secret ' . var_export($secret, true),
            );
        }
    }

    public function testTimestampChangedAfterSigningIsRejected(): void
    {
        $f = $this->fixture();
        $now = time();
        $signature = hash_hmac('sha256', "{$now}.{$f['body']}", $f['secret']);

        $this->assertTrue(Webhook::verifySignatureV2($f['body'], "t={$now},v1={$signature}", $f['secret']));
        $this->assertFalse(Webhook::verifySignatureV2($f['body'], 't=' . ($now - 1) . ",v1={$signature}", $f['secret']));
    }

    public function testMissingOrNonUtf8HeaderReturnsFalse(): void
    {
        $f = $this->fixture();

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], null, $f['secret']));
        $this->assertFalse(Webhook::verifySignatureV2(
            $f['body'],
            "t=1790000000,v1=\xff\xfe",
            $f['secret'],
            $f['tolerance_seconds_for_fixed_vectors'],
        ));
    }

    public function testNegativeToleranceThrows(): void
    {
        $f = $this->fixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toleranceSeconds');

        Webhook::verifySignatureV2($f['body'], self::signV2($f['body'], $f['secret'], time()), $f['secret'], -1);
    }

    public function testZeroToleranceIsAValidArgument(): void
    {
        $f = $this->fixture();

        $this->assertFalse(Webhook::verifySignatureV2($f['body'], self::signV2($f['body'], $f['secret'], time() - 600), $f['secret'], 0));
    }

    public function testLegacyHelperStillVerifiesTheLegacyHeader(): void
    {
        $f = $this->fixture();

        $this->assertTrue(Webhook::verifySignature($f['body'], $f['legacy_header'], $f['secret']));
        $this->assertFalse(Webhook::verifySignature($f['body'], $f['header'], $f['secret']));
    }
}
