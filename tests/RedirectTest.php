<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use Kawaa\Exceptions\KawaaException;
use Kawaa\Kawaa;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The client never follows a redirect: every request carries the API key in
 * X-Api-Key, and Guzzle keeps that header on a redirect to another host.
 *
 * Two real PHP built-in servers on 127.0.0.1: "the API", which answers every
 * request with a redirect to "the other host", which records the X-Api-Key of
 * every request that reaches it. A different port is a different origin.
 */
class RedirectTest extends TestCase
{
    private string $dir;
    /** @var array<int, resource> */
    private array $servers = [];
    private int $otherPort;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/kawaa-redirect-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->dir = $dir;
        file_put_contents("{$dir}/other.php", <<<'PHP'
<?php
file_put_contents(__DIR__ . '/other.log', ($_SERVER['HTTP_X_API_KEY'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'data' => [
    'job_id' => 'job-1', 'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90,
]]);
PHP);
        file_put_contents("{$dir}/api.php", <<<'PHP'
<?php
file_put_contents(__DIR__ . '/api.log', "request\n", FILE_APPEND | LOCK_EX);
http_response_code((int) file_get_contents(__DIR__ . '/status'));
header('Location: ' . trim((string) file_get_contents(__DIR__ . '/location')));
header('Content-Type: application/json');
echo '{"success":true,"data":{"job_id":"job-1","status":"valid"}}';
PHP);
        $this->otherPort = $this->serve('other.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** Start `php -S` with $router on a free port, and wait until it answers. */
    private function serve(string $router): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $null = ['file', '/dev/null', 'w'];
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$this->dir}/{$router}"],
            [0 => ['pipe', 'r'], 1 => $null, 2 => $null],
            $pipes,
            $this->dir
        );
        $this->assertIsResource($process);
        $this->servers[] = $process;
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return $port;
            }
            usleep(50000);
        }
        $this->fail("php -S did not start on port {$port}");
    }

    /** The API, redirecting every request with $status to the other host. */
    private function redirectingApi(int $status): string
    {
        file_put_contents("{$this->dir}/status", (string) $status);
        file_put_contents("{$this->dir}/location", "http://127.0.0.1:{$this->otherPort}/collect?x=1");
        return 'http://127.0.0.1:' . $this->serve('api.php');
    }

    /** @return array<int, string> the X-Api-Key of each request that reached the other host */
    private function keysThatReachedTheOtherHost(): array
    {
        $log = "{$this->dir}/other.log";
        return is_file($log) ? array_map('rtrim', file($log) ?: []) : [];
    }

    private function apiRequests(): int
    {
        $log = "{$this->dir}/api.log";
        return is_file($log) ? count(file($log) ?: []) : 0;
    }

    private function client(string $baseUrl): Kawaa
    {
        $kawaa = new Kawaa('secret-key', $baseUrl, 5.0, 3);
        $sleeper = new ReflectionProperty(Kawaa::class, 'sleeper');
        $sleeper->setAccessible(true);
        $sleeper->setValue($kawaa, static function (float $seconds): void {
        });
        return $kawaa;
    }

    /** @return array<string, array{int}> */
    public static function redirectStatuses(): array
    {
        return ['301' => [301], '302' => [302], '303' => [303], '307' => [307], '308' => [308]];
    }

    /**
     * @dataProvider redirectStatuses
     */
    public function testARedirectIsRefusedAndTheKeyNeverReachesTheOtherHost(int $status): void
    {
        $kawaa = $this->client($this->redirectingApi($status));

        try {
            $kawaa->verifyBatch(['a@example.com'], idempotencyKey: 'batch-key-1');
            $this->fail('expected the redirect to be refused');
        } catch (KawaaException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertStringContainsString("a redirect to http://127.0.0.1:{$this->otherPort}.", $e->getMessage());
            $this->assertStringNotContainsString('/collect', $e->getMessage(), 'the origin only');
            $this->assertSame('batch-key-1', $e->getIdempotencyKey());
        }

        $this->assertSame([], $this->keysThatReachedTheOtherHost(), 'nothing reaches the other host');
        $this->assertSame(1, $this->apiRequests(), 'a redirect is not retried');
    }

    public function testACsvDownloadIsRefusedToo(): void
    {
        $kawaa = $this->client($this->redirectingApi(307));

        try {
            $kawaa->downloadResults('job-1', 'csv');
            $this->fail('expected the redirect to be refused');
        } catch (KawaaException $e) {
            $this->assertSame(307, $e->getCode());
        }

        $this->assertSame([], $this->keysThatReachedTheOtherHost());
    }

    /**
     * The control: Guzzle's default follows this very redirect and carries
     * the key to the other host, so the tests above would see a leak.
     */
    public function testTheLeakTheRefusalPrevents(): void
    {
        $baseUrl = $this->redirectingApi(307);

        (new Client(['base_uri' => $baseUrl, 'timeout' => 5.0]))
            ->post('/v1/verify', ['headers' => ['X-Api-Key' => 'secret-key'], 'json' => []]);

        $this->assertSame(['secret-key'], $this->keysThatReachedTheOtherHost());
    }
}
