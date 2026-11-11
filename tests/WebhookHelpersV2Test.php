<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use Kawaa\Exceptions\ValidationException;
use Kawaa\Webhook;
use Kawaa\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The verifying helpers (parse, laravelHandler, symfonyHandler, handleRequest)
 * check X-Kawaa-Signature-V2 and its replay window by default. The legacy
 * X-Kawaa-Signature, which signs no time, is accepted only with
 * allowLegacySignature: true, and never in place of a V2 header the request
 * carries.
 */
class WebhookHelpersV2Test extends TestCase
{
    private const SECRET = 'whsec_helpers-v2';
    private const BODY = '{"event":"job.completed","data":{"job_id":"job_v2_helpers","status":"completed",'
        . '"note":"Zoë"},"webhook_id":"wh_helpers","timestamp":"2026-10-06T00:00:00.000Z"}';
    private const V2 = 'X-Kawaa-Signature-V2';
    private const LEGACY = 'X-Kawaa-Signature';

    private const OK = ['parse' => 'ok', 'laravel' => 'ok', 'symfony' => 'ok', 'handleRequest' => 'ok'];
    private const REFUSED = [
        'parse' => 'refused',
        'laravel' => 'refused',
        'symfony' => 'refused',
        'handleRequest' => 'refused',
    ];

    private static function tampered(): string
    {
        return str_replace('job_v2_helpers', 'job_v2_hijacked', self::BODY);
    }

    /** Signed with hash_hmac directly, never with the verifier under test. */
    private static function signV2(string $body, string $secret, int $offsetSeconds = 0): string
    {
        $t = time() + $offsetSeconds;

        return "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    private static function signLegacy(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Run one request through every helper; each answers "ok" (the event
     * parsed), "refused" (a signature ValidationException, which an app maps
     * to 401) or "json" (the body did not parse). Anything else is thrown.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function outcomes(
        string $body,
        array $headers,
        int $toleranceSeconds = Webhook::DEFAULT_TOLERANCE_SECONDS,
        bool $allowLegacySignature = false,
    ): array {
        // parse takes one value: the V2 header when present, else the legacy one.
        $value = $headers[self::V2] ?? $headers[self::LEGACY] ?? null;

        return [
            'parse' => self::outcome(fn () => Webhook::parse(
                $body,
                $value,
                self::SECRET,
                $toleranceSeconds,
                $allowLegacySignature,
            )),
            'laravel' => self::outcome(fn () => Webhook::laravelHandler(
                self::SECRET,
                $toleranceSeconds,
                $allowLegacySignature,
            )(self::laravelRequest($body, $headers))),
            'symfony' => self::outcome(fn () => Webhook::symfonyHandler(
                self::SECRET,
                $toleranceSeconds,
                $allowLegacySignature,
            )(self::symfonyRequest($body, $headers))),
            'handleRequest' => self::outcome(fn () => self::withRawRequest(
                $body,
                $headers,
                fn () => Webhook::handleRequest(self::SECRET, $toleranceSeconds, $allowLegacySignature),
            )),
        ];
    }

    private static function outcome(callable $call): string
    {
        try {
            $event = $call();
        } catch (ValidationException $e) {
            if (str_contains($e->getMessage(), 'not valid JSON')) {
                return 'json';
            }
            self::assertStringContainsString('ignature', $e->getMessage());
            self::assertSame(400, $e->getCode());

            return 'refused';
        }
        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('job_v2_helpers', $event->jobCompleted()?->jobId);

        return 'ok';
    }

    /** The message each helper gives, keyed by helper. */
    private function messages(string $body, array $headers, bool $allowLegacySignature = false): array
    {
        $value = $headers[self::V2] ?? $headers[self::LEGACY] ?? null;
        $calls = [
            'parse' => fn () => Webhook::parse($body, $value, self::SECRET, allowLegacySignature: $allowLegacySignature),
            'laravel' => fn () => Webhook::laravelHandler(self::SECRET, allowLegacySignature: $allowLegacySignature)(
                self::laravelRequest($body, $headers)
            ),
            'symfony' => fn () => Webhook::symfonyHandler(self::SECRET, allowLegacySignature: $allowLegacySignature)(
                self::symfonyRequest($body, $headers)
            ),
            'handleRequest' => fn () => self::withRawRequest(
                $body,
                $headers,
                fn () => Webhook::handleRequest(self::SECRET, allowLegacySignature: $allowLegacySignature),
            ),
        ];
        $messages = [];
        foreach ($calls as $helper => $call) {
            try {
                $call();
                $messages[$helper] = 'accepted';
            } catch (ValidationException $e) {
                $messages[$helper] = $e->getMessage();
            }
        }

        return $messages;
    }

    public function testFreshV2DeliveryIsAccepted(): void
    {
        $this->assertSame(self::OK, $this->outcomes(self::BODY, [self::V2 => self::signV2(self::BODY, self::SECRET)]));
    }

    public function testV2DeliverySigned290SecondsAgoIsInsideTheWindow(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, self::SECRET, -290)];

        $this->assertSame(self::OK, $this->outcomes(self::BODY, $headers));
    }

    public function testV2DeliverySigned310SecondsAgoIsAReplay(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, self::SECRET, -310)];

        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $headers));
        $this->assertSame(
            array_fill_keys(array_keys(self::OK), 'Invalid or expired webhook signature'),
            $this->messages(self::BODY, $headers),
        );
    }

    public function testV2DeliverySigned310SecondsAheadIsRefused(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, self::SECRET, 310)];

        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $headers));
    }

    public function testTamperedBodyIsRefused(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, self::SECRET)];

        $this->assertSame(self::REFUSED, $this->outcomes(self::tampered(), $headers));
    }

    public function testWrongSecretIsRefused(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, 'whsec_other')];

        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $headers));
    }

    public function testConfiguredToleranceIsHonoured(): void
    {
        $stale = [self::V2 => self::signV2(self::BODY, self::SECRET, -310)];
        $this->assertSame(self::OK, $this->outcomes(self::BODY, $stale, 600));

        $recent = [self::V2 => self::signV2(self::BODY, self::SECRET, -20)];
        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $recent, 10));
    }

    public function testLegacyOnlyRequestIsRefusedByDefault(): void
    {
        $legacyOnly = [self::LEGACY => self::signLegacy(self::BODY, self::SECRET)];

        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $legacyOnly));
        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $legacyOnly, allowLegacySignature: false));
    }

    public function testRefusedLegacySignatureExplainsHowToOptIn(): void
    {
        $legacyOnly = [self::LEGACY => self::signLegacy(self::BODY, self::SECRET)];
        $messages = $this->messages(self::BODY, $legacyOnly);

        $this->assertStringStartsWith('Webhook signature is a legacy X-Kawaa-Signature value', $messages['parse']);
        foreach (['laravel', 'symfony', 'handleRequest'] as $helper) {
            $this->assertStringStartsWith('Missing X-Kawaa-Signature-V2 header.', $messages[$helper], $helper);
        }
        foreach ($messages as $helper => $message) {
            $this->assertStringContainsString('allowLegacySignature: true', $message, $helper);
            $this->assertStringContainsString('replayed', $message, $helper);
        }
    }

    public function testBareHexLegacyValueIsLegacyToo(): void
    {
        $bare = hash_hmac('sha256', self::BODY, self::SECRET);

        foreach ([$bare, strtoupper($bare)] as $value) {
            try {
                Webhook::parse(self::BODY, $value, self::SECRET);
                $this->fail('parse accepted a bare legacy digest without the opt-in');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('legacy X-Kawaa-Signature value', $e->getMessage());
            }
        }

        $event = Webhook::parse(self::BODY, $bare, self::SECRET, allowLegacySignature: true);
        $this->assertSame('job_v2_helpers', $event->jobCompleted()?->jobId);
    }

    public function testOptInAcceptsAValidLegacyOnlyRequest(): void
    {
        $legacyOnly = [self::LEGACY => self::signLegacy(self::BODY, self::SECRET)];

        $this->assertSame(self::OK, $this->outcomes(self::BODY, $legacyOnly, allowLegacySignature: true));
    }

    public function testOptInStillRefusesAWrongLegacySignatureOrATamperedBody(): void
    {
        $wrong = [self::LEGACY => self::signLegacy(self::BODY, 'whsec_other')];
        $this->assertSame(self::REFUSED, $this->outcomes(self::BODY, $wrong, allowLegacySignature: true));
        $this->assertSame(
            array_fill_keys(array_keys(self::OK), 'Invalid webhook signature'),
            $this->messages(self::BODY, $wrong, true),
        );

        $signed = [self::LEGACY => self::signLegacy(self::BODY, self::SECRET)];
        $this->assertSame(self::REFUSED, $this->outcomes(self::tampered(), $signed, allowLegacySignature: true));
    }

    public function testOptInStillChecksAV2HeaderAndNeverFallsBackToLegacy(): void
    {
        $legacy = self::signLegacy(self::BODY, self::SECRET);

        foreach ([self::signV2(self::BODY, self::SECRET, -310), 'garbage', ''] as $v2) {
            $headers = [self::V2 => $v2, self::LEGACY => $legacy];
            $outcomes = $this->outcomes(self::BODY, $headers, allowLegacySignature: true);

            // parse is handed the V2 value; '' is a missing signature there.
            $this->assertSame(self::REFUSED, $outcomes, var_export($v2, true));
            $messages = $this->messages(self::BODY, $headers, true);
            foreach (['laravel', 'symfony', 'handleRequest'] as $helper) {
                $this->assertSame('Invalid or expired webhook signature', $messages[$helper], $helper);
            }
        }
    }

    public function testOptInAcceptsAFreshV2DeliveryAsBefore(): void
    {
        $headers = [self::V2 => self::signV2(self::BODY, self::SECRET)];

        $this->assertSame(self::OK, $this->outcomes(self::BODY, $headers, allowLegacySignature: true));
    }

    public function testRequestWithNoSignatureHeaderKeepsTheMissingSignatureMessage(): void
    {
        foreach ([[], [self::LEGACY => '']] as $headers) {
            foreach ([false, true] as $allowLegacySignature) {
                $this->assertSame(
                    array_fill_keys(array_keys(self::OK), 'Webhook signature missing'),
                    $this->messages(self::BODY, $headers, $allowLegacySignature),
                );
            }
        }
    }

    public function testBodyThatIsNotJsonKeepsItsError(): void
    {
        $body = 'not json';
        $headers = [self::V2 => self::signV2($body, self::SECRET)];

        $this->assertSame(
            ['parse' => 'json', 'laravel' => 'json', 'symfony' => 'json', 'handleRequest' => 'json'],
            $this->outcomes($body, $headers),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function malformedSignatures(): array
    {
        return [
            'non-ASCII in a legacy digest' => ['sha256=é' . str_repeat('a', 63)],
            'all non-ASCII legacy digest' => ['sha256=' . str_repeat('é', 64)],
            'non-ASCII v1' => ['t=1790000000,v1=' . str_repeat('é', 64)],
            'full-width digits in t' => ['t=１７９０００００００,v1=' . str_repeat('a', 64)],
            'emoji' => ["\u{1F600}"],
            'invalid UTF-8' => ["sha256=\xff\xfe"],
            'trailing junk after a valid digest' => [self::signLegacy(self::BODY, self::SECRET) . 'zz'],
        ];
    }

    #[DataProvider('malformedSignatures')]
    public function testLegacyVerifierReturnsFalseForAMalformedSignature(string $signature): void
    {
        $this->assertFalse(Webhook::verifySignature(self::BODY, $signature, self::SECRET));
    }

    #[DataProvider('malformedSignatures')]
    public function testHelpersRefuseAMalformedSignatureInEitherHeader(string $signature): void
    {
        foreach ([false, true] as $allowLegacySignature) {
            foreach ([self::V2, self::LEGACY] as $header) {
                $this->assertSame(
                    self::REFUSED,
                    $this->outcomes(self::BODY, [$header => $signature], allowLegacySignature: $allowLegacySignature),
                    "{$header}, allowLegacySignature " . var_export($allowLegacySignature, true),
                );
            }
        }
    }

    public function testNegativeToleranceFailsWhenTheHandlerIsBuilt(): void
    {
        $builders = [
            'laravelHandler' => fn () => Webhook::laravelHandler(self::SECRET, -1),
            'symfonyHandler' => fn () => Webhook::symfonyHandler(self::SECRET, -1),
            // handleRequest is the handler: it refuses before reading the request.
            'handleRequest' => fn () => Webhook::handleRequest(self::SECRET, -1),
            'parse' => fn () => Webhook::parse(self::BODY, self::signV2(self::BODY, self::SECRET), self::SECRET, -1),
        ];
        foreach ($builders as $name => $build) {
            try {
                $build();
                $this->fail("{$name} accepted a negative tolerance");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('toleranceSeconds', $e->getMessage(), $name);
            }
        }
    }

    public function testNegativeToleranceIsRefusedEvenWithoutASecretOrSignature(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Webhook::parse(self::BODY, null, null, -1);
    }

    public function testZeroToleranceIsAValidArgument(): void
    {
        $handler = Webhook::laravelHandler(self::SECRET, 0);
        $stale = [self::V2 => self::signV2(self::BODY, self::SECRET, -600)];

        $this->assertSame('refused', self::outcome(fn () => $handler(self::laravelRequest(self::BODY, $stale))));
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonBooleanOptIns(): array
    {
        return ['null' => [null], 'string' => ['true'], 'int' => [1]];
    }

    /**
     * A non-boolean opt-in is a TypeError (this file uses strict types), never
     * read as "off".
     */
    #[DataProvider('nonBooleanOptIns')]
    public function testNonBooleanOptInIsRefusedWhenTheHandlerIsBuilt(mixed $allow): void
    {
        $builders = [
            'laravelHandler' => fn () => Webhook::laravelHandler(self::SECRET, 300, $allow),
            'symfonyHandler' => fn () => Webhook::symfonyHandler(self::SECRET, 300, $allow),
            'handleRequest' => fn () => Webhook::handleRequest(self::SECRET, 300, $allow),
            'parse' => fn () => Webhook::parse(self::BODY, self::signV2(self::BODY, self::SECRET), self::SECRET, 300, $allow),
        ];
        foreach ($builders as $name => $build) {
            try {
                $build();
                $this->fail("{$name} accepted allowLegacySignature " . var_export($allow, true));
            } catch (\TypeError $e) {
                $this->assertStringContainsString('allowLegacySignature', $e->getMessage(), $name);
            }
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function coercibleOptIns(): array
    {
        return ['string false' => ['false'], 'string 0' => ['0'], 'empty string' => [''], 'string 1' => ['1'], 'int 1' => [1]];
    }

    /**
     * From a caller without strict types, PHP would coerce these to a bool
     * ("false" to true) if the parameter were typed bool. Each is refused
     * instead, so a getenv() value can never switch the legacy opt-in on.
     */
    #[DataProvider('coercibleOptIns')]
    public function testCoercibleOptInFromANonStrictCallerIsRefused(mixed $allow): void
    {
        require_once __DIR__ . '/Fixtures/non_strict_opt_in_caller.php';
        $legacy = 'sha256=' . hash_hmac('sha256', self::BODY, self::SECRET);
        $calls = \Kawaa\Tests\Fixtures\nonStrictOptInCalls(self::SECRET, self::BODY, $legacy, $allow);
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted allowLegacySignature " . var_export($allow, true));
            } catch (\TypeError $e) {
                $this->assertStringContainsString('allowLegacySignature must be true or false', $e->getMessage(), $name);
            }
        }
    }

    /** @param array<string, string> $headers */
    private static function laravelRequest(string $body, array $headers): object
    {
        return new class ($body, $headers) {
            /** @param array<string, string> $headers */
            public function __construct(private string $body, private array $headers)
            {
            }

            public function getContent(): string
            {
                return $this->body;
            }

            public function header(string $name): ?string
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }

                return null;
            }
        };
    }

    /** @param array<string, string> $headers */
    private static function symfonyRequest(string $body, array $headers): object
    {
        $bag = new class ($headers) {
            /** @param array<string, string> $headers */
            public function __construct(private array $headers)
            {
            }

            public function get(string $name): ?string
            {
                foreach ($this->headers as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }

                return null;
            }
        };

        return new class ($body, $bag) {
            public function __construct(private string $body, public object $headers)
            {
            }

            public function getContent(): string
            {
                return $this->body;
            }
        };
    }

    /**
     * Call $call as if PHP were serving a request with this body and these
     * headers: php://input reads $body and $_SERVER carries the headers.
     *
     * @param array<string, string> $headers
     */
    private static function withRawRequest(string $body, array $headers, callable $call): mixed
    {
        $saved = $_SERVER;
        unset($_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'], $_SERVER['HTTP_X_KAWAA_SIGNATURE']);
        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        FakePhpInputStream::$body = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', FakePhpInputStream::class);
        try {
            return $call();
        } finally {
            stream_wrapper_restore('php');
            $_SERVER = $saved;
        }
    }

    public function testFakeRequestReachesHandleRequest(): void
    {
        // Proves the harness: handleRequest really reads the faked body and headers.
        $event = self::withRawRequest(
            self::BODY,
            [self::V2 => self::signV2(self::BODY, self::SECRET)],
            fn () => Webhook::handleRequest(self::SECRET),
        );

        $this->assertSame('job_v2_helpers', $event->jobCompleted()?->jobId);
        $this->assertArrayNotHasKey('HTTP_X_KAWAA_SIGNATURE_V2', $_SERVER);
        $this->assertSame('', (string) file_get_contents('php://input'));
    }

    public function testHandleRequestWithTheRealEmptyInputStillChecksTheV2Header(): void
    {
        // Under the CLI php://input is empty: a V2 header signed over '' passes
        // the signature check and stops at the JSON parse, a stale one does not.
        $saved = $_SERVER;
        try {
            $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] = self::signV2('', self::SECRET);
            $this->assertSame('json', self::outcome(fn () => Webhook::handleRequest(self::SECRET)));

            $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] = self::signV2('', self::SECRET, -310);
            $this->assertSame('refused', self::outcome(fn () => Webhook::handleRequest(self::SECRET)));
        } finally {
            $_SERVER = $saved;
        }
    }
}

/**
 * Stands in for the php:// stream wrapper while a test calls handleRequest,
 * so php://input returns a body the test chose.
 */
final class FakePhpInputStream
{
    public static string $body = '';

    /** @var resource|null */
    public $context;

    private string $data = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (strcasecmp($path, 'php://input') !== 0) {
            return false;
        }
        $this->data = self::$body;
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = (string) substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->data)];
    }
}
