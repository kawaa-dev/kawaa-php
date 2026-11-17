<?php

declare(strict_types=1);

namespace Kawaa\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kawaa\Exceptions\KawaaException;
use Kawaa\Exceptions\RateLimitException;
use Kawaa\Exceptions\ServerException;
use Kawaa\Exceptions\TimeoutException;
use Kawaa\Kawaa;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Retry policy: a charging POST is never re-sent after it may have
 * reached the API; GETs and the Idempotency-Key-carrying verify routes are.
 */
class RetryPolicyTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /** @var array<int, float> Seconds the client asked to wait before each retry. */
    private array $sleeps = [];

    /**
     * A client whose Guzzle handler answers from $queue and records requests.
     * Waits between retries are recorded instead of slept.
     *
     * @param array<int, mixed> $queue
     */
    private function clientWith(array $queue, int $maxRetries = 2): Kawaa
    {
        $this->history = [];
        $this->sleeps = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $kawaa = new Kawaa('test-key', 'https://api.kawaa.test', 5.0, $maxRetries);
        if (property_exists(Kawaa::class, 'sleeper')) {
            $sleeper = new ReflectionProperty(Kawaa::class, 'sleeper');
            $sleeper->setAccessible(true);
            $sleeper->setValue($kawaa, function (float $seconds): void {
                $this->sleeps[] = $seconds;
            });
        }
        $property = new ReflectionProperty(Kawaa::class, 'client');
        $property->setAccessible(true);
        $property->setValue($kawaa, new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.kawaa.test',
            'headers' => ['X-Api-Key' => 'test-key', 'Content-Type' => 'application/json'],
        ]));

        return $kawaa;
    }

    private static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($body));
    }

    private static function serverError(int $status = 500): Response
    {
        return self::json($status, ['success' => false, 'error' => ['code' => 'INTERNAL', 'message' => 'boom']]);
    }

    /** @return array<int, string> */
    private function sentKeys(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getHeaderLine('Idempotency-Key'),
            $this->history
        );
    }

    public function testFindRetriesServerErrorWithOneIdempotencyKey(): void
    {
        // find charges 5 credits and carries a key on every call now, so a
        // 5xx retry replays or finishes the paid search instead of buying it
        // again.
        $kawaa = $this->clientWith([
            self::serverError(),
            self::json(200, ['email' => 'a@b.com']),
        ]);

        $result = $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertSame('a@b.com', $result->email);
        $this->assertCount(2, $this->history);
        $keys = $this->sentKeys();
        $this->assertNotSame('', $keys[0]);
        $this->assertSame($keys[0], $keys[1], 'every attempt must carry the same key');
    }

    public function testFindSendsTheCallerKeyVerbatim(): void
    {
        $kawaa = $this->clientWith([
            self::json(200, ['email' => 'a@b.com']),
        ]);

        $kawaa->find('Jane', 'Doe', 'example.com', 'order-7');

        $this->assertSame(['order-7'], $this->sentKeys());
    }

    public function testFindIsRetriedAfterTimeoutWithSameKey(): void
    {
        $request = new Request('POST', '/v1/find');
        $kawaa = $this->clientWith([
            // curl reports a timeout after the request went out as a
            // ConnectException with errno 28.
            new ConnectException('Operation timed out', $request, null, ['errno' => 28]),
            self::json(200, ['email' => 'a@b.com']),
        ]);

        $result = $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertSame('a@b.com', $result->email);
        $this->assertCount(2, $this->history);
        $keys = $this->sentKeys();
        $this->assertNotSame('', $keys[0]);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function testFindIsRetriedAfterDroppedConnectionWithSameKey(): void
    {
        $request = new Request('POST', '/v1/find');
        $kawaa = $this->clientWith([
            new RequestException('Recv failure: Connection reset by peer', $request, null, null, ['errno' => 56]),
            self::json(200, ['email' => 'a@b.com']),
        ]);

        $result = $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertSame('a@b.com', $result->email);
        $this->assertCount(2, $this->history);
        $keys = $this->sentKeys();
        $this->assertNotSame('', $keys[0]);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function testFindIsRetriedWhenTheConnectionWasNeverOpened(): void
    {
        $request = new Request('POST', '/v1/find');
        $kawaa = $this->clientWith([
            new ConnectException('Failed to connect: Connection refused', $request, null, ['errno' => 7]),
            self::json(200, ['email' => 'a@b.com', 'confidence' => 90]),
        ]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertCount(2, $this->history);
    }

    public function testVerifyRetriesServerErrorWithOneIdempotencyKey(): void
    {
        $kawaa = $this->clientWith([
            self::serverError(503),
            self::json(200, ['email' => 'a@b.com', 'status' => 'valid', 'quality_score' => 90]),
            self::json(200, ['email' => 'a@b.com', 'status' => 'valid', 'quality_score' => 90]),
        ]);

        $result = $kawaa->verify('a@b.com');
        $this->assertTrue($result->isValid());

        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertMatchesRegularExpression(self::UUID_V4, $keys[0]);
        $this->assertSame($keys[0], $keys[1], 'every attempt must carry the same key');

        $kawaa->verify('a@b.com');
        $keys = $this->sentKeys();
        $this->assertCount(3, $keys);
        $this->assertMatchesRegularExpression(self::UUID_V4, $keys[2]);
        $this->assertNotSame($keys[0], $keys[2], 'separate calls must use separate keys');
    }

    public function testVerifyBatchSendsCallerKeyOnEveryAttempt(): void
    {
        $kawaa = $this->clientWith([
            self::serverError(),
            self::json(200, ['job_id' => 'job-1', 'status' => 'pending']),
        ]);

        $job = $kawaa->verifyBatch(['a@b.com'], idempotencyKey: 'caller-key-1');

        $this->assertSame('job-1', $job->jobId);
        $this->assertSame(['caller-key-1', 'caller-key-1'], $this->sentKeys());
    }

    public function testVerifyGivesUpReportingAttemptsMade(): void
    {
        // maxRetries counts retries: 2 retries after the first attempt.
        $kawaa = $this->clientWith([self::serverError(), self::serverError(), self::serverError()]);

        try {
            $kawaa->verify('a@b.com');
            $this->fail('expected the 5xx to surface');
        } catch (KawaaException $e) {
            $this->assertStringContainsString('after 3 attempts', $e->getMessage());
        }
        $this->assertCount(3, $this->history);
    }

    public function testGetRetriesAfterServerError(): void
    {
        $kawaa = $this->clientWith([
            self::serverError(),
            self::json(200, ['email' => 'a@b.com']),
        ]);

        $kawaa->getAccount();

        $this->assertCount(2, $this->history);
        $this->assertSame(['', ''], $this->sentKeys());
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function answersMarkedNotRetryable(): array
    {
        return [
            '503' => [503, 'INTEGRATION_NOT_CONFIGURED'],
            '501' => [501, 'NOT_IMPLEMENTED'],
            '429' => [429, 'TWO_FACTOR_ACCOUNT_LOCKED'],
        ];
    }

    /**
     * @dataProvider answersMarkedNotRetryable
     */
    public function testAnAnswerMarkedNotRetryableIsNotRetried(int $status, string $code): void
    {
        $kawaa = $this->clientWith([
            self::json($status, ['success' => false, 'error' => ['code' => $code, 'message' => 'no', 'retryable' => false]]),
            self::json(200, ['email' => 'a@b.com']),
        ], 3);

        try {
            $kawaa->getAccount();
            $this->fail('expected the refusal to surface');
        } catch (KawaaException $e) {
            $this->assertFalse($e->getRetryable());
        }
        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    public function testRateLimitIsRetriedForChargingPost(): void
    {
        $kawaa = $this->clientWith([
            self::json(429, ['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'slow']], ['Retry-After' => '0']),
            self::json(200, ['email' => 'a@b.com', 'confidence' => 90]),
        ]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertCount(2, $this->history);
    }

    // -------------------------------------------------------------------------
    // Fractional credit amounts
    // -------------------------------------------------------------------------

    /**
     * Balances and charges are fractional in production (a cached verify costs
     * 0.5, an activity lookup 0.25). With strict_types an int-typed model
     * threw a TypeError on them, after the call had been charged.
     */
    public function testFractionalCreditAmountsParseFromLiveShapedResponses(): void
    {
        $kawaa = $this->clientWith([
            self::json(200, ['success' => true, 'data' => [
                'account' => ['user_id' => 'usr_1', 'email' => 'a@example.com', 'plan' => 'starter', 'status' => 'active', 'created_at' => '2026-01-01T00:00:00.000Z'],
                'credits' => ['balance' => 260.25, 'used_this_month' => 39.75, 'monthly_allowance' => 300, 'next_reset' => '2026-10-01T00:00:00.000Z', 'allowance_basis' => 'monthly_reset'],
            ]]),
            self::json(202, ['success' => true, 'data' => [
                'job_id' => 'job_b', 'status' => 'processing', 'total_submitted' => 3, 'duplicates_removed' => 0,
                'unique_emails' => 3, 'credits_used' => 2.5, 'credits_remaining' => 257.75, 'credits_held' => 0.5,
            ]]),
            self::json(200, ['success' => true, 'data' => [
                'job_id' => 'job_b', 'status' => 'completed', 'total_emails' => 3, 'processed_emails' => 3,
                'progress_percent' => 100, 'summary' => ['valid' => 3], 'credits_used' => 3,
                'credits_refunded' => 0.5, 'billing_status' => 'charged',
            ]]),
            self::json(200, ['success' => true, 'data' => [
                'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 90, 'flags' => [],
                'from_cache' => true, 'credits_used' => 0.5, 'credits_remaining' => 259.75,
            ]]),
            self::json(200, ['success' => true, 'data' => [
                'first_name' => 'John', 'last_name' => 'Smith', 'email' => 'john@company.com', 'confidence' => 90,
                'pattern' => 'first', 'verified' => true, 'status' => 'valid', 'credits_used' => 5, 'credits_remaining' => 254.25,
            ]]),
        ]);

        $account = $kawaa->getAccount();
        $this->assertSame(260.25, $account->creditsRemaining);
        $this->assertSame(39.75, $account->creditsUsed);
        $this->assertSame(300.0, $account->monthlyAllowance);

        $batch = $kawaa->verifyBatch(['a@example.com', 'b@example.com', 'c@example.com']);
        $this->assertSame(2.5, $batch->creditsUsed);
        $this->assertSame(257.75, $batch->creditsRemaining);
        $this->assertSame(0.5, $batch->creditsHeld);

        $job = $kawaa->getJobStatus('job_b');
        $this->assertSame(3.0, $job->creditsCharged);
        $this->assertSame(0.5, $job->creditsRefunded);

        $verified = $kawaa->verify('a@example.com');
        $this->assertSame(0.5, $verified->creditsUsed);
        $this->assertSame(259.75, $verified->creditsRemaining);
        $this->assertNull($verified->idempotentReplay);

        $found = $kawaa->find('John', 'Smith', 'company.com');
        $this->assertSame(5.0, $found->creditsUsed);
        $this->assertSame(254.25, $found->creditsRemaining);
    }

    // -------------------------------------------------------------------------
    // verify(): an Idempotency-Key replay is read back from its job
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function replayBody(): array
    {
        return ['success' => true, 'data' => [
            'job_id' => 'job_single_1',
            'email' => 'a@example.com',
            'status' => 'pending',
            'idempotent_replay' => true,
            'message' => 'This request was already accepted under the same Idempotency-Key; nothing more was charged. Use GET /v1/jobs/{job_id} to read the result.',
            'credits_used' => 0,
            'credits_remaining' => 259.75,
            'request_id' => 'req_2',
        ]];
    }

    private static function completedJob(): Response
    {
        return self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job_single_1',
            'status' => 'completed',
            'total_emails' => 1,
            'processed_emails' => 1,
            'progress_percent' => 100,
            'summary' => ['valid' => 1, 'invalid' => 0, 'risky' => 0, 'unknown' => 0],
            'results' => [[
                'email' => 'a@example.com',
                'status' => 'valid',
                'sub_status' => 'mailbox_exists',
                'quality_score' => 92,
                'flags' => ['disposable' => false, 'role_account' => false, 'free_provider' => true, 'catch_all' => false],
                'verification' => ['syntax_valid' => true, 'mx_found' => true, 'smtp_valid' => true, 'catch_all' => false],
                'from_cache' => true,
            ]],
            'next_key' => null,
            'has_more' => false,
            'credits_used' => 1,
            'credits_refunded' => 0.5,
            'billing_status' => 'charged',
            'completed_at' => '2026-09-25T10:00:00.000Z',
        ]]);
    }

    private static function processingJob(): Response
    {
        return self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job_single_1', 'status' => 'processing', 'total_emails' => 1, 'processed_emails' => 0,
            'progress_percent' => 0, 'credits_used' => 1, 'credits_refunded' => 0, 'billing_status' => 'charged',
        ]]);
    }

    private static function fastReplayPolling(Kawaa $kawaa): Kawaa
    {
        $property = new ReflectionProperty(Kawaa::class, 'replayPollInterval');
        $property->setAccessible(true);
        $property->setValue($kawaa, 0.001);

        return $kawaa;
    }

    public function testVerifyReplayIsReadBackFromItsJob(): void
    {
        $kawaa = $this->clientWith([self::json(200, self::replayBody()), self::completedJob()]);

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertSame(92, $result->score);
        $this->assertSame('mailbox_exists', $result->subStatus);
        $this->assertTrue($result->flags->freeProvider);
        $this->assertSame('job_single_1', $result->jobId);
        $this->assertTrue($result->idempotentReplay);
        // What the original verification cost: charged 1, cache discount 0.5.
        $this->assertSame(0.5, $result->creditsUsed);
        $this->assertSame(259.75, $result->creditsRemaining);
        $this->assertNull($result->message);

        $this->assertCount(2, $this->history);
        $read = $this->history[1]['request'];
        $this->assertSame('GET', $read->getMethod());
        $this->assertSame('/v1/jobs/job_single_1', $read->getUri()->getPath());
        $this->assertSame('include_results=true', $read->getUri()->getQuery());
    }

    public function testVerifyReplayPollsARunningJobBriefly(): void
    {
        $kawaa = self::fastReplayPolling($this->clientWith([
            self::json(200, self::replayBody()),
            self::processingJob(),
            self::completedJob(),
        ]));

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->idempotentReplay);
        $this->assertSame(0.5, $result->creditsUsed);
        $this->assertCount(3, $this->history);
    }

    public function testVerifyReplayStillRunningAfterThePollIsReturnedPending(): void
    {
        $kawaa = self::fastReplayPolling($this->clientWith([
            self::json(200, self::replayBody()),
            self::processingJob(),
            self::processingJob(),
            self::processingJob(),
            self::completedJob(),
        ]));

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isPending());
        $this->assertSame('job_single_1', $result->jobId);
        $this->assertTrue($result->idempotentReplay);
        // The replay's 0 is what the repeat cost; the verification's cost is
        // on the job, which was not read to the end.
        $this->assertNull($result->creditsUsed);
        $this->assertSame(259.75, $result->creditsRemaining);
        // One POST and three reads; the completed job is never asked for.
        $this->assertCount(4, $this->history);
    }

    public function testVerifyReplayThatCannotReadTheJobIsReturnedPendingNotThrown(): void
    {
        $kawaa = $this->clientWith([
            self::json(200, self::replayBody()),
            self::json(403, ['success' => false, 'error' => [
                'code' => 'INSUFFICIENT_SCOPE',
                'message' => 'This API key does not have the verification:read scope',
            ]]),
        ]);

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isPending());
        $this->assertSame('job_single_1', $result->jobId);
        $this->assertTrue($result->idempotentReplay);
        $this->assertNull($result->creditsUsed);
        $this->assertStringContainsString('GET /v1/jobs/{job_id}', (string) $result->message);
        $this->assertCount(2, $this->history);
    }

    public function testVerifyReplayOfAJobReadWithoutAChargeLeavesTheCostUnknown(): void
    {
        $job = json_decode((string) self::completedJob()->getBody(), true);
        unset($job['data']['credits_used'], $job['data']['credits_refunded']);
        $kawaa = $this->clientWith([self::json(200, self::replayBody()), self::json(200, $job)]);

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->idempotentReplay);
        $this->assertNull($result->creditsUsed, "not the replay's 0");
    }

    // --- verifyBatch: a replay is kept as one, and its 0 is not the cost ---

    public function testVerifyBatchRecoveredLostAnswerKeepsTheReplayAndNoCost(): void
    {
        $kawaa = $this->clientWith([
            self::json(502, ['message' => 'Bad Gateway']),
            self::json(200, ['success' => true, 'data' => [
                'job_id' => 'job-1', 'status' => 'processing', 'total_submitted' => 2, 'duplicates_removed' => 0,
                'invalid_emails_skipped' => 0, 'unique_emails' => 2, 'idempotent_replay' => true,
                'credits_used' => 0, 'credits_remaining' => 98,
                'message' => 'This batch was already accepted under the same Idempotency-Key; nothing more was charged.',
            ]]),
        ]);

        $job = $kawaa->verifyBatch(['a@b.com', 'c@d.com']);

        $this->assertSame('job-1', $job->jobId);
        $this->assertTrue($job->idempotentReplay);
        $this->assertNull($job->creditsUsed, "the replay's 0 is not the batch's cost");
        $this->assertSame(98.0, $job->creditsRemaining);
        $this->assertTrue($job->toArray()['idempotent_replay']);
        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1], 'the retry repeats the batch under the same key');
    }

    public function testVerifyBatchFiguresItCannotReadAreUnknownNotZero(): void
    {
        $kawaa = $this->clientWith([self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job-1', 'status' => 'processing', 'unique_emails' => 1,
            'credits_used' => '1', 'credits_remaining' => null, 'idempotent_replay' => 'true',
        ]])]);

        $job = $kawaa->verifyBatch(['a@b.com']);

        $this->assertNull($job->creditsUsed);
        $this->assertNull($job->creditsRemaining, 'an omitted balance is unknown, not 0');
        $this->assertNull($job->idempotentReplay);
    }

    public function testVerifyBatchFirstAnswerKeepsAZeroCharge(): void
    {
        $kawaa = $this->clientWith([self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job-1', 'status' => 'processing', 'unique_emails' => 1,
            'credits_used' => 0, 'credits_remaining' => 10, 'idempotent_replay' => false,
        ]])]);

        $job = $kawaa->verifyBatch(['a@b.com']);

        $this->assertSame(0.0, $job->creditsUsed);
        $this->assertFalse($job->idempotentReplay);
    }

    // --- a charged answer that cannot be mapped still carries the key ---

    public function testAMappingFailureAfterAChargeCarriesTheKey(): void
    {
        // total_submitted is an int property: a string fails the mapping
        // after the batch was accepted and charged.
        $kawaa = $this->clientWith([self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job-1', 'status' => 'processing', 'total_submitted' => 'two', 'credits_used' => 2,
        ]])]);

        try {
            $kawaa->verifyBatch(['a@b.com'], idempotencyKey: 'batch-key-1');
            $this->fail('expected the mapping to fail');
        } catch (KawaaException $e) {
            $this->assertSame('batch-key-1', $e->getIdempotencyKey());
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
        }
        $this->assertCount(1, $this->history, 'an answer that was read is not sent again');
    }

    public function testAFindMappingFailureCarriesTheGeneratedKey(): void
    {
        $kawaa = $this->clientWith([self::json(200, ['success' => true, 'data' => [
            'email' => 'a@b.com', 'confidence' => 'high', 'credits_used' => 5,
        ]])]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the mapping to fail');
        } catch (KawaaException $e) {
            $this->assertNotNull($e->getIdempotencyKey());
            $this->assertSame($this->sentKeys()[0], $e->getIdempotencyKey());
        }
    }

    // --- a 200 whose body fails partway is retried under the same key ---

    public function testASuccessStatusWhoseBodyFailsPartwayIsRetriedUnderTheSameKey(): void
    {
        $request = new Request('POST', '/v1/verify/batch');
        $kawaa = $this->clientWith([
            // curl after the headers: errno 18 (partial file) with the 200
            // response Guzzle had already started.
            new RequestException(
                'cURL error 18: transfer closed with outstanding read data remaining',
                $request,
                new Response(200, ['Content-Type' => 'application/json'], '{"success":true,"da'),
                null,
                ['errno' => 18]
            ),
            self::json(200, ['success' => true, 'data' => [
                'job_id' => 'job-1', 'status' => 'processing', 'unique_emails' => 1,
                'idempotent_replay' => true, 'credits_used' => 0,
            ]]),
        ]);

        $job = $kawaa->verifyBatch(['a@b.com']);

        $this->assertSame('job-1', $job->jobId);
        $this->assertTrue($job->idempotentReplay);
        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
    }

    public function testASuccessStatusWhoseBodyFailsPartwayOnTheLastAttemptKeepsTheKey(): void
    {
        $request = new Request('POST', '/v1/verify/batch');
        $partial = static fn (): RequestException => new RequestException(
            'cURL error 56: Recv failure: Connection reset by peer',
            $request,
            new Response(200, [], '{"succ'),
            null,
            ['errno' => 56]
        );
        $kawaa = $this->clientWith([$partial(), $partial()], 1);

        try {
            $kawaa->verifyBatch(['a@b.com'], idempotencyKey: 'batch-key-1');
            $this->fail('expected the transfer to fail');
        } catch (KawaaException $e) {
            $this->assertNotSame(200, $e->getCode(), 'not reported as an answer with status 200');
            $this->assertSame('batch-key-1', $e->getIdempotencyKey());
        }
        $this->assertCount(2, $this->history);
    }

    public function testVerifyWithoutAReplayMarkerReadsNoJob(): void
    {
        $oddMarker = self::replayBody();
        $oddMarker['data']['idempotent_replay'] = 'true';
        $kawaa = $this->clientWith([
            self::json(200, ['success' => true, 'data' => [
                'email' => 'a@example.com', 'status' => 'valid', 'quality_score' => 92, 'credits_used' => 1, 'credits_remaining' => 259,
            ]]),
            self::json(200, $oddMarker),
        ]);

        $ordinary = $kawaa->verify('a@example.com');
        $this->assertTrue($ordinary->isValid());
        $this->assertNull($ordinary->idempotentReplay);
        $this->assertSame(1.0, $ordinary->creditsUsed);

        $odd = $kawaa->verify('a@example.com');
        $this->assertTrue($odd->isPending());
        $this->assertNull($odd->idempotentReplay);
        $this->assertCount(2, $this->history);
    }

    /**
     * A worker cache hit is billed 0.5 by a refund the job read applies; a
     * read that could not apply it says cache_discount_pending and still
     * carries the pre-discount charge.
     */
    private static function discountPendingJob(): Response
    {
        $body = json_decode((string) self::completedJob()->getBody(), true);
        $body['data']['credits_used'] = 1;
        $body['data']['credits_refunded'] = 0;
        $body['data']['cache_discount_pending'] = true;

        return self::json(200, $body);
    }

    public function testVerifyReplayRereadsAJobWhoseCacheDiscountIsPending(): void
    {
        $kawaa = self::fastReplayPolling($this->clientWith([
            self::json(200, self::replayBody()),
            self::discountPendingJob(),
            self::completedJob(),
        ]));

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->idempotentReplay);
        $this->assertSame(0.5, $result->creditsUsed);
        $this->assertCount(3, $this->history);
    }

    public function testVerifyReplayLeavesTheCostOutWhileTheDiscountIsStillPending(): void
    {
        $kawaa = self::fastReplayPolling($this->clientWith([
            self::json(200, self::replayBody()),
            self::discountPendingJob(),
            self::discountPendingJob(),
            self::discountPendingJob(),
            self::completedJob(),
        ]));

        $result = $kawaa->verify('a@example.com');

        // The verdict is final; the bill is not, so no figure stands in for it.
        $this->assertTrue($result->isValid());
        $this->assertSame('job_single_1', $result->jobId);
        $this->assertTrue($result->idempotentReplay);
        $this->assertNull($result->creditsUsed);
        $this->assertSame(259.75, $result->creditsRemaining);
        $this->assertCount(4, $this->history);
    }

    public function testVerifyReplayKeepsAVerdictItHasReadWhenALaterReadFails(): void
    {
        $kawaa = self::fastReplayPolling($this->clientWith([
            self::json(200, self::replayBody()),
            self::discountPendingJob(),
            self::json(403, ['success' => false, 'error' => ['code' => 'INSUFFICIENT_SCOPE', 'message' => 'no']]),
        ]));

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->idempotentReplay);
        $this->assertNull($result->creditsUsed);
        $this->assertCount(3, $this->history);
    }

    public function testVerifyRetryAfterALostResponseResolvesTheReplay(): void
    {
        // The first attempt was accepted and charged, but its answer was lost
        // to a 503; the retry under the same key gets the replay.
        $kawaa = $this->clientWith([
            self::serverError(503),
            self::json(200, self::replayBody()),
            self::completedJob(),
        ]);

        $result = $kawaa->verify('a@example.com');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->idempotentReplay);
        $this->assertSame(0.5, $result->creditsUsed);
        $keys = $this->sentKeys();
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame('/v1/jobs/job_single_1', $this->history[2]['request']->getUri()->getPath());
    }

    // -------------------------------------------------------------------------
    // Giving up keeps the Idempotency-Key, and the error says how to retry
    // -------------------------------------------------------------------------

    /**
     * What the API answers when a keyed request to a charging route fails:
     * repeat it only with the same key.
     *
     * @param array<string, mixed> $extra
     */
    private static function keyedServerError(int $status = 500, array $extra = []): Response
    {
        return self::json($status, ['success' => false, 'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'Something went wrong',
            'request_id' => 'req_500',
            'retry_requires_same_idempotency_key' => true,
        ] + $extra]);
    }

    private static function conflict(bool $retryable, string $code = 'CONFLICT', array $headers = []): Response
    {
        $error = [
            'code' => $code,
            'message' => $retryable
                ? 'A matching email finder request is already running under this Idempotency-Key. Retry shortly with the same key.'
                : 'This Idempotency-Key was already used for a different request.',
            'request_id' => 'req_409',
            'retryable' => $retryable,
        ];
        if ($retryable) {
            $error['retry_requires_same_idempotency_key'] = true;
        }

        return self::json(409, ['success' => false, 'error' => $error], $headers);
    }

    private static function found(): Response
    {
        return self::json(200, ['success' => true, 'data' => [
            'email' => 'jane@example.com', 'confidence' => 90, 'credits_used' => 5,
        ]]);
    }

    private static function rateLimited(?string $retryAfter, array $extra = []): Response
    {
        return self::json(
            429,
            ['success' => false, 'error' => ['code' => 'RATE_LIMITED', 'message' => 'slow down', 'retryable' => true] + $extra],
            $retryAfter === null ? [] : ['Retry-After' => $retryAfter]
        );
    }

    public function testKeyedCallThatGivesUpOnServerErrorsKeepsItsKey(): void
    {
        $kawaa = $this->clientWith([self::keyedServerError(), self::keyedServerError(), self::keyedServerError(503, ['retryable' => true])]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 5xx to surface');
        } catch (KawaaException $e) {
            $keys = $this->sentKeys();
            $this->assertCount(3, $keys, 'maxRetries 2 is the first attempt and two retries');
            $this->assertMatchesRegularExpression(self::UUID_V4, $keys[0]);
            $this->assertSame([$keys[0], $keys[0], $keys[0]], $keys);
            // The key the attempts carried, so the caller's own retry replays
            // the paid search instead of buying it again.
            $this->assertSame($keys[0], $e->getIdempotencyKey());
            $this->assertTrue($e->retryRequiresSameIdempotencyKey());
            $this->assertFalse($e->retryMayChargeAgain());
            $this->assertTrue($e->getRetryable());
            $this->assertSame('INTERNAL_ERROR', $e->getApiErrorCode());
            $this->assertSame('req_500', $e->getRequestId());
            $this->assertSame(503, $e->getCode());
            $this->assertInstanceOf(\Kawaa\Exceptions\ServerException::class, $e);
        }
    }

    public function testCallerKeyIsOnTheErrorAndCanBePassedBack(): void
    {
        $kawaa = $this->clientWith([self::keyedServerError(), self::found()], 0);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com', 'order-7');
            $this->fail('expected the 5xx to surface');
        } catch (KawaaException $e) {
            $this->assertSame('order-7', $e->getIdempotencyKey());
            $result = $kawaa->find('Jane', 'Doe', 'example.com', $e->getIdempotencyKey());
            $this->assertSame('jane@example.com', $result->email);
        }
        $this->assertSame(['order-7', 'order-7'], $this->sentKeys());
    }

    public function testServerErrorOnTheLastAttemptIsAServerExceptionWithItsStatus(): void
    {
        $kawaa = $this->clientWith([self::serverError(502)], 0);

        try {
            $kawaa->getAccount();
            $this->fail('expected the 5xx to surface');
        } catch (\Kawaa\Exceptions\ServerException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('boom', $e->getMessage());
            $this->assertNull($e->getIdempotencyKey(), 'an unkeyed call has no key to report');
        }
    }

    public function testGatewayBodyMessageSurfaces(): void
    {
        $kawaa = $this->clientWith([self::json(503, ['message' => 'Service Unavailable'])], 0);

        try {
            $kawaa->verify('a@b.com');
            $this->fail('expected the 503 to surface');
        } catch (KawaaException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame('Service Unavailable', $e->getMessage());
            $this->assertNotNull($e->getIdempotencyKey());
            $this->assertNull($e->getRetryable());
        }
    }

    public function testKeyedCallThatTimesOutKeepsItsKey(): void
    {
        $request = new Request('POST', '/v1/find');
        $timeout = static fn (): ConnectException => new ConnectException('Operation timed out', $request, null, ['errno' => 28]);
        $kawaa = $this->clientWith([$timeout(), $timeout(), $timeout()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the timeout to surface');
        } catch (TimeoutException $e) {
            $keys = $this->sentKeys();
            $this->assertCount(3, $keys);
            $this->assertSame($keys[0], $e->getIdempotencyKey());
            $this->assertSame(408, $e->getCode());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function testKeyedCallThatLosesTheConnectionKeepsItsKey(): void
    {
        $request = new Request('POST', '/v1/verify');
        $reset = static fn (): RequestException => new RequestException('Recv failure: Connection reset by peer', $request, null, null, ['errno' => 56]);
        $kawaa = $this->clientWith([$reset(), $reset()], 1);

        try {
            $kawaa->verify('a@b.com', idempotencyKey: 'verify-1');
            $this->fail('expected the dropped connection to surface');
        } catch (KawaaException $e) {
            $this->assertSame('verify-1', $e->getIdempotencyKey());
        }
        $this->assertSame(['verify-1', 'verify-1'], $this->sentKeys());
    }

    public function testKeyedCallThatCannotConnectKeepsItsKey(): void
    {
        $request = new Request('POST', '/v1/verify/batch');
        $refused = static fn (): ConnectException => new ConnectException('Failed to connect: Connection refused', $request, null, ['errno' => 7]);
        $kawaa = $this->clientWith([$refused(), $refused()], 1);

        try {
            $kawaa->verifyBatch(['a@b.com']);
            $this->fail('expected the connection failure to surface');
        } catch (KawaaException $e) {
            $keys = $this->sentKeys();
            $this->assertCount(2, $keys);
            $this->assertSame($keys[0], $e->getIdempotencyKey());
            $this->assertStringContainsString('after 2 attempts', $e->getMessage());
        }
    }

    public function testRetryableConflictIsRetriedWithTheSameKey(): void
    {
        $kawaa = $this->clientWith([self::conflict(true), self::found()]);

        $result = $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertSame('jane@example.com', $result->email);
        $keys = $this->sentKeys();
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        // No Retry-After: the first backoff step.
        $this->assertSame([1.0], $this->sleeps);
    }

    public function testRetryableConflictHonoursRetryAfter(): void
    {
        $kawaa = $this->clientWith([
            self::conflict(true, 'CONFLICT', ['Retry-After' => '2']),
            self::json(200, ['success' => true, 'data' => ['email' => 'a@b.com', 'status' => 'valid', 'quality_score' => 90]]),
        ]);

        $this->assertTrue($kawaa->verify('a@b.com')->isValid());
        $this->assertSame([2.0], $this->sleeps);
        $keys = $this->sentKeys();
        $this->assertSame($keys[0], $keys[1]);
    }

    public function testRetryableConflictThatOutlastsTheRetriesKeepsItsKey(): void
    {
        $kawaa = $this->clientWith([self::conflict(true), self::conflict(true)], 1);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 409 to surface');
        } catch (KawaaException $e) {
            $keys = $this->sentKeys();
            $this->assertCount(2, $keys);
            $this->assertSame(409, $e->getCode());
            $this->assertSame('CONFLICT', $e->getApiErrorCode());
            $this->assertTrue($e->getRetryable());
            $this->assertTrue($e->retryRequiresSameIdempotencyKey());
            $this->assertSame($keys[0], $e->getIdempotencyKey());
        }
    }

    public function testNonRetryableConflictIsNotRetried(): void
    {
        $kawaa = $this->clientWith([self::conflict(false, 'IDEMPOTENCY_KEY_CONFLICT'), self::found()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com', 'order-8');
            $this->fail('expected the 409 to surface');
        } catch (KawaaException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertFalse($e->getRetryable());
            $this->assertSame('order-8', $e->getIdempotencyKey());
        }
        $this->assertCount(1, $this->history);
    }

    public function testRetryableConflictWithoutAKeyIsNotRetried(): void
    {
        // Only a keyed request is retried on a 409: an unkeyed one (here a
        // plain read) surfaces as before.
        $kawaa = $this->clientWith([self::conflict(true), self::json(200, ['success' => true, 'data' => []])]);

        try {
            $kawaa->getAccount();
            $this->fail('expected the 409 to surface');
        } catch (KawaaException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testMaxRetriesZeroSendsExactlyOneRequest(): void
    {
        $kawaa = $this->clientWith([self::found()], 0);
        $this->assertSame('jane@example.com', $kawaa->find('Jane', 'Doe', 'example.com')->email);
        $this->assertCount(1, $this->history);

        $kawaa = $this->clientWith([self::serverError(), self::found()], 0);
        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 5xx to surface');
        } catch (KawaaException $e) {
            $this->assertSame(500, $e->getCode());
        }
        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    public function testNegativeMaxRetriesStillSendsOneRequest(): void
    {
        $kawaa = $this->clientWith([self::found()], -3);
        $kawaa->find('Jane', 'Doe', 'example.com');
        $this->assertCount(1, $this->history);
    }

    public function testRetryAfterUpToSixtySecondsIsWaitedAndRetried(): void
    {
        $this->assertSame(60, Kawaa::MAX_RETRY_AFTER_SECONDS);
        $kawaa = $this->clientWith([self::rateLimited('60'), self::found()]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertCount(2, $this->history);
        $this->assertSame([60.0], $this->sleeps);
    }

    /**
     * The API edge answers an address it has blocked with this 429. Up to
     * 0.3.1 the client waited 60 seconds and tried again, maxRetries times,
     * so each call hung about three minutes before the same exception.
     */
    private static function edgeBlock(): Response
    {
        return self::rateLimited('3900', ['retry_after_seconds' => 3900]);
    }

    public function testALongerRetryAfterThanTheCapIsThrownAtOnce(): void
    {
        $kawaa = $this->clientWith([self::edgeBlock(), self::found()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 429 to surface');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertSame(3900, $e->getRetryAfter());
            $this->assertSame(3900, $e->getRetryAfterSeconds());
            $this->assertTrue($e->getRetryable());
            // The key the one attempt sent, for the caller's own retry later.
            $this->assertSame($this->sentKeys()[0], $e->getIdempotencyKey());
        }
        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    /** @return array<string, array{0: Response}> */
    public static function longerThanTheCap(): array
    {
        return [
            'the header' => [self::rateLimited('61')],
            'the body alone' => [self::rateLimited(null, ['retry_after_seconds' => 61])],
            'a fraction above' => [self::rateLimited('60.5')],
            'a keyed 5xx' => [self::json(503, ['success' => false, 'error' => [
                'code' => 'SERVICE_UNAVAILABLE', 'message' => 'later', 'retryable' => true,
                'retry_requires_same_idempotency_key' => true,
            ]], ['Retry-After' => '3900'])],
            'a keyed 409 in flight' => [self::conflict(true, 'CONFLICT', ['Retry-After' => '120'])],
        ];
    }

    /**
     * @dataProvider longerThanTheCap
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('longerThanTheCap')]
    public function testNoAnswerAskingForLongerThanTheCapIsRetried(Response $answer): void
    {
        $kawaa = $this->clientWith([$answer, self::found()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the answer to surface');
        } catch (KawaaException $e) {
            $this->assertNotNull($e->getRetryAfterSeconds());
            $this->assertGreaterThan(Kawaa::MAX_RETRY_AFTER_SECONDS, $e->getRetryAfterSeconds());
        }
        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    public function testALongDelayAfterAShortOneStopsAndKeepsTheKey(): void
    {
        $kawaa = $this->clientWith([self::keyedServerError(503, ['retryable' => true]), self::edgeBlock(), self::found()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 429 to surface');
        } catch (RateLimitException $e) {
            $this->assertSame(3900, $e->getRetryAfter());
            $keys = $this->sentKeys();
            $this->assertCount(2, $keys);
            $this->assertSame($keys[0], $keys[1]);
            $this->assertSame($keys[0], $e->getIdempotencyKey());
        }
        $this->assertCount(1, $this->sleeps);
    }

    public function testShortRetryAftersAreStillRetried(): void
    {
        $kawaa = $this->clientWith([self::rateLimited('2'), self::rateLimited('2'), self::found()]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertCount(3, $this->history);
        $this->assertSame([2.0, 2.0], $this->sleeps);
    }

    public function testRetryAfterOnTheLastAttemptIsReportedUnclamped(): void
    {
        $kawaa = $this->clientWith([self::rateLimited('3000000', ['retry_after_seconds' => 3000000])], 0);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 429 to surface');
        } catch (RateLimitException $e) {
            $this->assertSame(3000000, $e->getRetryAfter());
            $this->assertSame(3000000, $e->getRetryAfterSeconds());
            $this->assertTrue($e->getRetryable());
            $this->assertNotNull($e->getIdempotencyKey());
        }
        $this->assertSame([], $this->sleeps);
    }

    /** @return array<string, array{0: string}> */
    public static function unusableRetryAfter(): array
    {
        return [
            'negative' => ['-5'],
            'infinite' => ['inf'],
            'overflowing' => ['1e400'],
            'http date' => ['Wed, 21 Oct 2026 07:28:00 GMT'],
            'text' => ['soon'],
        ];
    }

    /**
     * @dataProvider unusableRetryAfter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableRetryAfter')]
    public function testUnusableRetryAfterFallsBackToBackoff(string $retryAfter): void
    {
        $kawaa = $this->clientWith([self::rateLimited($retryAfter), self::found()]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertCount(2, $this->history);
        $this->assertSame([1.0], $this->sleeps);
    }

    public function testBodyRetryAfterIsUsedWithoutTheHeader(): void
    {
        $kawaa = $this->clientWith([
            self::rateLimited(null, ['retry_after_seconds' => 7]),
            self::rateLimited(null, ['retry_after_seconds' => 60]),
            self::found(),
        ]);

        $kawaa->find('Jane', 'Doe', 'example.com');

        $this->assertSame([7.0, 60.0], $this->sleeps);
    }

    public function testServerErrorRetryHonoursRetryAfter(): void
    {
        $kawaa = $this->clientWith([
            self::json(503, ['success' => false, 'error' => ['code' => 'SERVICE_UNAVAILABLE', 'message' => 'later', 'retryable' => true, 'retry_after_seconds' => 3]], ['Retry-After' => '3']),
            self::json(200, ['success' => true, 'data' => []]),
        ]);

        $kawaa->getAccount();

        $this->assertSame([3.0], $this->sleeps);
    }

    public function testNonJsonSuccessBodyOfAKeyedCallKeepsItsKey(): void
    {
        $kawaa = $this->clientWith([new Response(200, ['Content-Type' => 'text/html'], '<html>gateway</html>')], 0);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com', 'order-9');
            $this->fail('expected the unreadable body to surface');
        } catch (KawaaException $e) {
            $this->assertSame('order-9', $e->getIdempotencyKey());
            $this->assertSame(200, $e->getCode());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bodiesThatAreNotAJsonObject(): array
    {
        return ['null' => ['null'], 'a list' => ['[1,2,3]'], 'a string' => ['"ok"'], 'empty' => ['']];
    }

    /**
     * @dataProvider bodiesThatAreNotAJsonObject
     */
    public function testSuccessBodyThatIsNotAJsonObjectKeepsItsKey(string $body): void
    {
        $kawaa = $this->clientWith([new Response(200, ['Content-Type' => 'application/json'], $body)], 0);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com', 'order-9');
            $this->fail('expected the unreadable body to surface');
        } catch (KawaaException $e) {
            $this->assertSame('order-9', $e->getIdempotencyKey());
            $this->assertSame(200, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }

    // -------------------------------------------------------------------------
    // Domain search charges 10 credits and takes no key: never re-sent blind
    // -------------------------------------------------------------------------

    public function testDomainSearchIsNotRetriedWhenTheBodySaysARetryMayCharge(): void
    {
        $kawaa = $this->clientWith([
            self::json(500, ['success' => false, 'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'Domain search failed to start',
                'retry_may_charge_again' => true,
            ]]),
            self::json(200, ['success' => true, 'data' => ['domain' => 'example.com', 'emails' => []]]),
        ]);

        try {
            $kawaa->domainSearch('example.com');
            $this->fail('expected the 5xx to surface');
        } catch (KawaaException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertTrue($e->retryMayChargeAgain());
            $this->assertFalse($e->retryRequiresSameIdempotencyKey());
            $this->assertNull($e->getIdempotencyKey());
        }
        $this->assertCount(1, $this->history);
    }

    public function testDomainSearchIsNotRetriedAfterABareServerError(): void
    {
        $kawaa = $this->clientWith([
            self::json(503, ['message' => 'Service Unavailable']),
            self::json(200, ['success' => true, 'data' => ['domain' => 'example.com', 'emails' => []]]),
        ]);

        try {
            $kawaa->domainSearch('example.com');
            $this->fail('expected the 503 to surface');
        } catch (KawaaException $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testDomainSearchIsNotRetriedAfterATimeout(): void
    {
        $request = new Request('GET', '/v1/domain/example.com');
        $kawaa = $this->clientWith([
            new ConnectException('Operation timed out', $request, null, ['errno' => 28]),
            self::json(200, ['success' => true, 'data' => ['domain' => 'example.com', 'emails' => []]]),
        ]);

        try {
            $kawaa->domainSearch('example.com');
            $this->fail('expected the timeout to surface');
        } catch (TimeoutException $e) {
            $this->assertSame(408, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testDomainSearchStillRetriesARateLimit(): void
    {
        $kawaa = $this->clientWith([
            self::rateLimited('0'),
            self::json(200, ['success' => true, 'data' => ['domain' => 'example.com', 'emails' => []]]),
        ]);

        $result = $kawaa->domainSearch('example.com');

        $this->assertSame('example.com', $result->domain);
        $this->assertCount(2, $this->history);
    }

    public function testNothingIsRetriedWhenTheBodySaysARetryMayCharge(): void
    {
        // Even a 429, which is normally refused before any charge.
        $kawaa = $this->clientWith([self::rateLimited('0', ['retry_may_charge_again' => true]), self::found()]);

        try {
            $kawaa->find('Jane', 'Doe', 'example.com');
            $this->fail('expected the 429 to surface');
        } catch (RateLimitException $e) {
            $this->assertTrue($e->retryMayChargeAgain());
        }
        $this->assertCount(1, $this->history);
    }

    // -------------------------------------------------------------------------
    // verifyBatchAndWait: the batch is paid for before the wait, so a failure
    // while waiting must carry the batch's key, or repeating the convenience
    // call buys the batch again under a fresh one.
    // -------------------------------------------------------------------------

    private static function batchAccepted(): Response
    {
        return self::json(200, ['success' => true, 'data' => [
            'job_id' => 'job_1', 'status' => 'processing', 'total_emails' => 1,
        ]]);
    }

    public function testVerifyBatchAndWaitPutsTheBatchKeyOnAFailedJobRead(): void
    {
        $kawaa = $this->clientWith([self::batchAccepted(), self::serverError()], 0);

        try {
            $kawaa->verifyBatchAndWait(['a@b.com'], 0.001, 5.0);
            $this->fail('expected the job read to fail');
        } catch (ServerException $e) {
            $keys = $this->sentKeys();
            $this->assertCount(2, $keys);
            $this->assertNotSame('', $keys[0]);
            $this->assertSame('', $keys[1], 'the job read carries no key of its own');
            $this->assertSame($keys[0], $e->getIdempotencyKey());
        }
    }

    public function testVerifyBatchAndWaitPutsTheBatchKeyOnAWaitThatRunsOut(): void
    {
        $kawaa = $this->clientWith([self::batchAccepted(), self::processingJob()], 0);

        try {
            $kawaa->verifyBatchAndWait(['a@b.com'], 0.001, 0.0);
            $this->fail('expected the wait to run out');
        } catch (TimeoutException $e) {
            $this->assertSame($this->sentKeys()[0], $e->getIdempotencyKey());
        }
    }

    public function testVerifyBatchAndWaitSendsAndCarriesACallersOwnKey(): void
    {
        $kawaa = $this->clientWith([self::batchAccepted(), self::serverError()], 0);

        try {
            $kawaa->verifyBatchAndWait(['a@b.com'], 0.001, 5.0, idempotencyKey: 'batch-key-1');
            $this->fail('expected the job read to fail');
        } catch (ServerException $e) {
            $this->assertSame('batch-key-1', $this->sentKeys()[0]);
            $this->assertSame('batch-key-1', $e->getIdempotencyKey());
        }
    }
}
