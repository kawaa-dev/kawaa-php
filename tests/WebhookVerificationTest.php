<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Exceptions\ValidationException;
use Kawaa\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook verification fails closed: no secret never means "skip the check".
 */
class WebhookVerificationTest extends TestCase
{
    private const SECRET = 'whsec_correct-horse';
    private const FORGED = '{"event":"job.completed","data":{"job_id":"job_forged","status":"completed"},'
        . '"webhook_id":"wh_forged","timestamp":"2026-10-01T00:00:00Z"}';

    private static function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    private static function signV2(string $payload, string $secret): string
    {
        $t = time();

        return "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret);
    }

    /** @return array<string, array{0: ?string}> */
    public static function blankSecrets(): array
    {
        return ['empty' => [''], 'whitespace' => ['   '], 'null' => [null]];
    }

    /** @return array<string, array{0: string}> */
    public static function blankStringSecrets(): array
    {
        return ['empty' => [''], 'whitespace' => ['   ']];
    }

    #[DataProvider('blankStringSecrets')]
    public function testEmptyKeyHmacNeverVerifies(string $secret): void
    {
        $this->assertFalse(Webhook::verifySignature(self::FORGED, self::sign(self::FORGED, $secret), $secret));
        $this->assertFalse(Webhook::verifySignature(self::FORGED, '', $secret));
    }

    public function testCorrectSecretAndSignatureVerifies(): void
    {
        $this->assertTrue(Webhook::verifySignature(self::FORGED, self::sign(self::FORGED, self::SECRET), self::SECRET));
        $this->assertFalse(Webhook::verifySignature(self::FORGED, '', self::SECRET));
    }

    #[DataProvider('blankSecrets')]
    public function testBlankSecretAndNoSignatureIsRefused(?string $secret): void
    {
        foreach ([null, ''] as $signature) {
            try {
                Webhook::parse(self::FORGED, $signature, $secret);
                $this->fail('parse accepted an unsigned webhook with a blank secret');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('secret is missing', $e->getMessage());
            }
        }
    }

    #[DataProvider('blankSecrets')]
    public function testBlankSecretAndEmptyKeyHmacIsRefused(?string $secret): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('secret is missing');

        Webhook::parse(self::FORGED, self::sign(self::FORGED, ''), $secret);
    }

    public function testParseWithoutVerificationArgumentsNamesTheUnverifiedParser(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parseUnverified');

        Webhook::parse(self::FORGED);
    }

    public function testCorrectSecretAndValidSignatureIsAccepted(): void
    {
        $event = Webhook::parse(self::FORGED, self::signV2(self::FORGED, self::SECRET), self::SECRET);

        $this->assertSame('job_forged', $event->jobCompleted()?->jobId);
    }

    public function testUnsignedBodyParsesOnlyThroughTheExplicitFunction(): void
    {
        $this->assertSame('job.completed', Webhook::parseUnverified(self::FORGED)->event);
    }

    #[DataProvider('blankStringSecrets')]
    public function testLaravelHandlerRefusesBlankSecretAtConstruction(string $secret): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secret is missing or blank');

        Webhook::laravelHandler($secret);
    }

    #[DataProvider('blankStringSecrets')]
    public function testSymfonyHandlerRefusesBlankSecretAtConstruction(string $secret): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secret is missing or blank');

        Webhook::symfonyHandler($secret);
    }

    #[DataProvider('blankStringSecrets')]
    public function testHandleRequestRefusesBlankSecret(string $secret): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('secret is missing');

        // php://input is empty under the CLI; the secret check comes first.
        Webhook::handleRequest($secret);
    }

    public function testLaravelHandlerRequests(): void
    {
        $handler = Webhook::laravelHandler(self::SECRET);

        $this->assertRefused(fn () => $handler($this->laravelRequest(self::FORGED, null)));
        $this->assertRefused(fn () => $handler($this->laravelRequest(self::FORGED, self::signV2(self::FORGED, ''))));

        $event = $handler($this->laravelRequest(self::FORGED, self::signV2(self::FORGED, self::SECRET)));
        $this->assertSame('job_forged', $event->jobCompleted()?->jobId);
    }

    public function testLaravelHandlerWithTheLegacyOptIn(): void
    {
        $handler = Webhook::laravelHandler(self::SECRET, allowLegacySignature: true);

        $this->assertRefused(fn () => $handler($this->laravelRequest(self::FORGED, null, 'x-kawaa-signature')));
        $this->assertRefused(fn () => $handler(
            $this->laravelRequest(self::FORGED, self::sign(self::FORGED, ''), 'x-kawaa-signature')
        ));

        $event = $handler($this->laravelRequest(self::FORGED, self::sign(self::FORGED, self::SECRET), 'x-kawaa-signature'));
        $this->assertSame('job_forged', $event->jobCompleted()?->jobId);
    }

    public function testSymfonyHandlerRequests(): void
    {
        $handler = Webhook::symfonyHandler(self::SECRET);

        $this->assertRefused(fn () => $handler($this->symfonyRequest(self::FORGED, null)));
        $this->assertRefused(fn () => $handler($this->symfonyRequest(self::FORGED, self::signV2(self::FORGED, ''))));

        $event = $handler($this->symfonyRequest(self::FORGED, self::signV2(self::FORGED, self::SECRET)));
        $this->assertSame('job_forged', $event->jobCompleted()?->jobId);
    }

    public function testSymfonyHandlerWithTheLegacyOptIn(): void
    {
        $handler = Webhook::symfonyHandler(self::SECRET, allowLegacySignature: true);

        $this->assertRefused(fn () => $handler($this->symfonyRequest(self::FORGED, null, 'x-kawaa-signature')));
        $this->assertRefused(fn () => $handler(
            $this->symfonyRequest(self::FORGED, self::sign(self::FORGED, ''), 'x-kawaa-signature')
        ));

        $event = $handler($this->symfonyRequest(self::FORGED, self::sign(self::FORGED, self::SECRET), 'x-kawaa-signature'));
        $this->assertSame('job_forged', $event->jobCompleted()?->jobId);
    }

    private function assertRefused(callable $call): void
    {
        try {
            $call();
            $this->fail('handler accepted a webhook without a valid signature');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ignature', $e->getMessage());
        }
    }

    private function laravelRequest(string $body, ?string $signature, string $header = 'x-kawaa-signature-v2'): object
    {
        return new class ($body, $signature, $header) {
            public function __construct(private string $body, private ?string $signature, private string $header)
            {
            }

            public function getContent(): string
            {
                return $this->body;
            }

            public function header(string $name): ?string
            {
                return strtolower($name) === $this->header ? $this->signature : null;
            }
        };
    }

    private function symfonyRequest(string $body, ?string $signature, string $header = 'x-kawaa-signature-v2'): object
    {
        $headers = new class ($signature, $header) {
            public function __construct(private ?string $signature, private string $header)
            {
            }

            public function get(string $name): ?string
            {
                return strtolower($name) === $this->header ? $this->signature : null;
            }
        };

        return new class ($body, $headers) {
            public function __construct(private string $body, public object $headers)
            {
            }

            public function getContent(): string
            {
                return $this->body;
            }
        };
    }
}
