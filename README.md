# Kawaa PHP SDK

PHP client for the [Kawaa](https://kawaa.com) email verification API.

## Features

- Verify single emails or batch lists
- Domain search (find all emails at a domain)
- Email finder (find email by name + company; Starter plan and up)
- Automatic retries with exponential backoff (see [Retries](#retries)): for rate limits on every method, for connection failures before a request is sent (host name not resolved or connection refused) on every method, and for requests that are safe to repeat. Those are the GETs that charge nothing, and `verify`/`verifyBatch`/`find`, which send one `Idempotency-Key` per call and reuse it after a timeout, dropped response, 5xx or in-flight `409` so a retry is not charged twice (pass the `$idempotencyKey` argument to supply your own). `domainSearch()` is a GET that charges 10 credits and takes no key, so it is never sent twice on the client's own initiative. When the retries run out, the exception carries the key so you can repeat the call with it
- Full type hints for IDE support
- Webhook signature verification
- Framework integrations (Laravel, Symfony)

## Requirements

- PHP 8.1 or higher (the models use readonly properties and enums)
- Guzzle 7.0 or higher

## Installation

```bash
composer require kawaa/kawaa-php
```

## Quick Start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Kawaa\Kawaa;

// Create client with your API key
$client = new Kawaa('your-api-key');

// Verify a single email
$result = $client->verify('john@example.com');
echo "Status: {$result->status->value}\n";  // valid, invalid, risky, unknown, spam_trap, disposable, role, catch_all (or pending on sync timeout)
echo "Score: {$result->score}\n";           // 0-100 quality score

if ($result->isValid()) {
    echo "Email is valid!\n";
}

if ($result->flags->disposable) {
    echo "Warning: disposable email\n";
}
```

## Single Email Verification

```php
use Kawaa\Kawaa;

$client = new Kawaa('your-api-key');

// Basic verification
$result = $client->verify('user@example.com');

// Access results
echo "Email: {$result->email}\n";
echo "Status: {$result->status->value}\n";
echo "Score: {$result->score}\n";
echo "Disposable: " . ($result->flags->disposable ? 'Yes' : 'No') . "\n";
echo "Role account: " . ($result->flags->role ? 'Yes' : 'No') . "\n";
echo "Free provider: " . ($result->flags->freeProvider ? 'Yes' : 'No') . "\n";
echo "Catch-all: " . ($result->flags->catchAll ? 'Yes' : 'No') . "\n"; // Yes when the domain was seen to accept any address
echo "Catch-all measured: " . ($result->flags->catchAllMeasured ? 'Yes' : 'No') . "\n"; // No when nothing measured it

// Kawaa measures catch-all only after the mail server accepts the mailbox.
// When nothing measured it (a disposable address, no usable mail server, a
// server that refused the mailbox or never answered), catchAll is false but
// means "unknown", so test both properties before treating an address as not
// catch-all.
if ($result->flags->catchAllMeasured && !$result->flags->catchAll) {
    echo "Checked: the domain does not accept every address\n";
}

// AI-suggested correction (if typo detected)
if ($result->suggestion) {
    echo "Did you mean: {$result->suggestion}\n";
}

// Customize verification options (the real API options)
$result = $client->verify(
    email: 'user@example.com',
    deepVerify: true,     // Deep (SMTP-level) verification
    includeAi: true,      // AI typo detection / catch-all confidence
    enrich: false,        // Opt-in data enrichment (name, gender, country); Business plan and up
    timeoutMs: 24000,     // Server-side verification/poll budget (ms)
    skipCache: false,     // Bypass the verification cache
);
```

`enrich` needs the Business plan or higher. On a lower plan the API refuses the whole request with `403 PLAN_UPGRADE_REQUIRED` and charges nothing, so leave it out there.

```php
// The enrichment the request asked for: null when not asked for, or when
// nothing is known about the address.
if ($result->enrichment !== null) {
    echo "Country: {$result->enrichment->country}\n";
}

// A cached answer says how old it is.
if ($result->fromCache && $result->cacheAgeSeconds !== null) {
    echo "Cached result, measured {$result->cacheAgeSeconds} seconds ago\n";
}
```

## Domain Search

Find all email addresses at a company domain. Each call charges 10 credits and
the route takes no `Idempotency-Key`, so the client does not repeat it after a
5xx, a timeout or a dropped connection: a repeat could be charged again. When
the exception's `retryMayChargeAgain()` is true, check your balance before
calling again. A rate limit (429) is still retried, because nothing is charged.

```php
$result = $client->domainSearch('stripe.com');

// The first uncached call returns status "processing" with no emails yet;
// re-poll domainSearch() until $result->isProcessing() is false.
echo "Found {$result->emailCount} emails at {$result->domain}\n";
echo "Patterns checked: {$result->patternsChecked}\n";

foreach ($result->emails as $email) {
    echo "{$email->email}: {$email->status}\n";  // a string, e.g. "valid"
}
```

## Email Finder

Find someone's email by name and company. The email finder needs the Starter plan or higher. On the Free plan the API refuses it with `403 PLAN_UPGRADE_REQUIRED` before anything is charged.

```php
$result = $client->find('John', 'Smith', 'company.com');

if ($result->found()) {
    echo "Found: {$result->email}\n";
    echo "Confidence: {$result->confidence}%\n";
    echo "Pattern: {$result->pattern}\n";  // e.g., "first.last"
} elseif ($result->email !== null) {
    echo "Best guess, not confirmed: {$result->email} ({$result->status})\n";
} else {
    echo "Email not found\n";
}
```

`found()` is true only for a confirmed address (`verified`, a `valid`
verdict). On a catch-all, risky or role result the API still returns its best
guess as `email`, with `verified` false; treat that as a guess, not as this
person's address.

## Batch Verification

For verifying many emails at once:

```php
$emails = [
    'john@example.com',
    'jane@example.com',
    'invalid@',
    'john@example.com',  // duplicate - will be removed
];

// Start batch job
$job = $client->verifyBatch($emails);
echo "Job ID: {$job->jobId}\n";
echo "Duplicates removed: {$job->duplicatesRemoved}\n";
// 'invalid@' is malformed: skipped, not verified and not charged
echo "Malformed addresses skipped: " . ($job->invalidEmailsSkipped ?? 'not reported') . "\n";
// null on a replay of a batch already accepted under the same key
echo "Credits used: " . ($job->creditsUsed ?? 'not reported') . "\n";

// Poll for completion
$result = $client->waitForJob($job->jobId, timeout: 300);
echo "Valid: {$result->validCount}\n";
echo "Invalid: {$result->invalidCount}\n";
echo "Risky: {$result->riskyCount}\n";

// $result->results is the first page only (up to 100 results). For every
// result, download them (see Download Results below).
$all = $client->downloadResults($job->jobId);
foreach ($all->results as $r) {
    echo "{$r->email}: {$r->status->value} (score: {$r->score})\n";
}
```

### One-liner with wait

```php
// Start job and wait for completion in one call. Like waitForJob(), it
// returns the first page of results (up to 100); downloadResults() has them all.
$result = $client->verifyBatchAndWait(
    $emails,
    pollInterval: 2.0,  // Check every 2 seconds
    timeout: 600,       // Wait up to 10 minutes
);
```

### Using webhooks

```php
// Get notified when job completes.
// Use your own HTTPS endpoint: the API looks the host up and refuses one that does
// not resolve (400 WEBHOOK_URL_INVALID, nothing charged).
$job = $client->verifyBatch(
    $emails,
    webhookUrl: 'https://hooks.example.com/kawaa',
    webhookSecret: 'your-secret',  // Optional, for signature verification
);
```

## Download Results

```php
// Download as JSON
$results = $client->downloadResults($jobId);
foreach ($results->results as $r) {
    echo "{$r->email}: {$r->status->value}\n";
}

// Download as CSV
$csvData = $client->downloadResults($jobId, format: 'csv');
echo $csvData;  // CSV string

// For large result sets, you'll get a presigned URL
if ($results->downloadUrl) {
    echo "Download from: {$results->downloadUrl}\n";
}
```

Every JSON response and presigned-URL export carries a completeness verdict.
The SDK returns those exports only when the API reports `complete: true`. A
measured-short or unverifiable export throws
`Kawaa\Exceptions\IncompleteExportException`, carrying the completeness
verdict and row counts. Retry after a few seconds only when
`$error->isRetryable()` is true; `getComplete() === null` is not success. A
small inline CSV is raw CSV and carries no verdict; the API refuses a
measured-short inline CSV, but use JSON when you require a positive
completeness proof.
If the API refuses a measured-short inline CSV before it can return an
envelope, the same typed exception has `getComplete() === false`, HTTP code
409, and null row counts; retry it in the same way.

## Webhook Handling

Registered webhooks (`POST /v1/webhooks`, Settings → Webhooks) deliver an
**envelope**: the event-specific fields are nested under `data`:

```json
{
  "event": "job.completed",
  "data": {
    "job_id": "job_abc123",
    "summary": { "valid": 800, "invalid": 150, "risky": 40, "unknown": 10 },
    "download_url": "https://api.kawaa.com/v1/jobs/job_abc123/download"
  },
  "webhook_id": "wh_abc123",
  "timestamp": "2026-01-01T00:30:01Z"
}
```

Supported events: `verification.completed`, `verification.failed`,
`job.completed`, `job.failed`, `credits.low`, `credits.exhausted`,
`blacklist.detected`, `dmarc.failure`. `Webhook::parse()` returns a
`WebhookEvent` envelope (`event`, `data` array, `webhookId`, `timestamp`,
`test`, `rawPayload`) with typed accessors (`jobCompleted()`,
`verificationCompleted()`, ...) that return a data object when the event
matches, else `null`. The legacy per-job `webhookUrl` callback (flat body)
is automatically normalized into a `job.completed`-style envelope.

Verification fails closed. `Webhook::parse()`, `Webhook::handleRequest()`
and the Laravel and Symfony handlers always check the signature: they throw
a `ValidationException` when the `X-Kawaa-Signature-V2` header is missing,
wrong or outside its 300-second window (see
[Replay Protection](#replay-protection-x-kawaa-signature-v2)), and when the
secret is missing or blank (an unset environment variable never means "skip
the check"). `Webhook::laravelHandler()` and `Webhook::symfonyHandler()`
throw an `InvalidArgumentException` when you create them with a blank secret
or a negative tolerance. `Webhook::verifySignature()` returns `false` for a
blank secret.

A per-job `webhookUrl` callback created without a `webhookSecret` is sent
unsigned. To read one, call `Webhook::parseUnverified($payload)`, and
remember that anyone who knows the URL can send a body that parses. Prefer
passing a `webhookSecret` with the batch.

```php
use Kawaa\Exceptions\ValidationException;
use Kawaa\Webhook;

// Vanilla PHP: checks X-Kawaa-Signature-V2 and throws if the secret is blank or
// the signature is missing, wrong or older than 300 seconds. Answer such a
// delivery with 401: left uncaught, the exception makes PHP answer 500.
try {
    $event = Webhook::handleRequest((string) getenv('KAWAA_WEBHOOK_SECRET'));
} catch (ValidationException $e) {
    error_log('Kawaa webhook refused: ' . $e->getMessage()); // a blank secret shows here
    http_response_code(401);
    exit;
}
if ($job = $event->jobCompleted()) {
    echo "Job {$job->jobId} completed!\n";
    echo "Valid: {$job->summary?->valid}, Invalid: {$job->summary?->invalid}\n";
    echo "Download: {$job->downloadUrl}\n";
} elseif ($credits = $event->creditsLow()) {
    echo "Credits low: {$credits->creditsRemaining} remaining\n";
}
```

### Replay Protection (X-Kawaa-Signature-V2)

Every signed delivery carries an `X-Kawaa-Signature-V2` header of the form
`t=<unix seconds>,v1=<hex HMAC-SHA256>`. The HMAC uses your webhook secret
and covers the timestamp, a `.`, and the raw body, so a captured request
cannot be replayed later with a new time.

The helpers (`Webhook::parse()`, `Webhook::laravelHandler()`,
`Webhook::symfonyHandler()` and `Webhook::handleRequest()`) check this header
and its 300-second window by default. To check it yourself, on the raw body
before parsing:

```php
use Kawaa\Webhook;

$payload = (string) file_get_contents('php://input');

if (!Webhook::verifySignatureV2(
    $payload,
    $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] ?? null,
    getenv('KAWAA_WEBHOOK_SECRET') ?: null,
)) {
    http_response_code(401);
    exit;
}

$event = Webhook::parseUnverified($payload);
```

- It returns `false` for a missing or malformed header, a wrong signature, a
  missing or blank secret, or a `t` more than 300 seconds
  (`Webhook::DEFAULT_TOLERANCE_SECONDS`) from your clock in either direction.
  That rejects replayed and stale deliveries.
- Pass `toleranceSeconds` to widen or narrow the window, here and in every
  helper (for example `Webhook::laravelHandler($secret, toleranceSeconds: 600)`
  or `Webhook::parse($payload, $signature, $secret, toleranceSeconds: 600)`).
  A negative value throws an `InvalidArgumentException`; the Laravel and
  Symfony handlers throw it when you create them.
- Every attempt (automatic retries, a manual retry from the delivery log, and
  test deliveries) is signed again with a fresh `t`, so a late retry still
  verifies.
- Pass the body exactly as received. Re-encoded JSON will not match.

In Laravel read the header with `$request->header('X-Kawaa-Signature-V2')`,
and in Symfony with `$request->headers->get('X-Kawaa-Signature-V2')`.

Every delivery also carries the legacy `X-Kawaa-Signature` header
(`sha256=<hex HMAC of the body>`). It signs no time, so a captured request
verifies forever and can be replayed. The helpers refuse a request (or, for
`Webhook::parse()`, a signature value) that has only the legacy signature,
unless you pass `allowLegacySignature: true`. Turn that on only for a sender
that cannot send `X-Kawaa-Signature-V2`, and know that a request accepted
through it can be a replay of any delivery ever captured. A request that
carries an `X-Kawaa-Signature-V2` header is always checked against it, even
with that option on. `Webhook::verifySignature()` still checks the legacy
header on its own; prefer `Webhook::verifySignatureV2()`. A per-job callback
created without a `webhookSecret` is unsigned and carries neither header.

### Laravel Integration

```php
use Kawaa\Webhook;

// In routes/api.php
Route::post('/webhook', function (Request $request) {
    $handler = Webhook::laravelHandler((string) env('KAWAA_WEBHOOK_SECRET'));
    $event = $handler($request);

    if ($job = $event->jobCompleted()) {
        Log::info("Job {$job->jobId} completed!");
    }

    return response()->json(['status' => 'ok']);
});
```

### Symfony Integration

```php
use Kawaa\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    public function handleWebhook(Request $request): Response
    {
        $handler = Webhook::symfonyHandler((string) ($_ENV['KAWAA_WEBHOOK_SECRET'] ?? ''));
        $event = $handler($request);

        // Process event...

        return new Response('OK');
    }
}
```

### Manual Parsing

```php
use Kawaa\Webhook;

// The raw request body, exactly as it arrived.
$payload = (string) file_get_contents('php://input');

// Parse with signature verification: pass the X-Kawaa-Signature-V2 header.
// Throws when the signature is absent, wrong or outside the window.
$event = Webhook::parse(
    payload: $payload,
    signature: $_SERVER['HTTP_X_KAWAA_SIGNATURE_V2'] ?? null,
    secret: (string) getenv('KAWAA_WEBHOOK_SECRET'),
);
```

A per-job callback sent without a `webhookSecret` has no signature, so
`Webhook::parse()` refuses it. Parse it on its own, remembering that anyone who
knows the URL can send one:

```php
use Kawaa\Webhook;

$event = Webhook::parseUnverified((string) file_get_contents('php://input'));
```

## Account Information

```php
$account = $client->getAccount();
echo "Credits remaining: {$account->creditsRemaining}\n";
echo "Plan: {$account->plan}\n";

if ($account->creditsUsed === null) {
    // Omitted when the API key does not have the analytics:read scope ($account->usageWithheld is true).
    echo "Credits used: not reported\n";
} elseif ($account->allowanceBasis === \Kawaa\Models\AccountInfo::ALLOWANCE_BILLING_PERIOD && $account->nextReset !== null) {
    echo "Used {$account->creditsUsed} of {$account->monthlyAllowance} this billing period\n";
} else {
    echo "Used {$account->creditsUsed} in total\n"; // nothing resets this on a schedule
}
```

`allowanceBasis` says whether the allowance renews. `'billing_period'` means
`creditsUsed` is spend in the current billing period and `nextReset` says when
it refills. `'one_time_grant'` (the Free plan's credits, granted once),
`'no_billing_period'` (a paid plan with no billing period on file) and
`'provider_allocation'` (a white-label sub-account) mean nothing resets it on a
schedule, so `creditsUsed` is cumulative and `monthlyAllowance` is not a
monthly figure. The API sends the field for ordinary accounts; for a
sub-account it sends none, so the SDK supplies `'provider_allocation'` from the
plan `'sub_account'`. `null` means the API did not say: treat it as unknown,
not as renewing. Take `'billing_period'` as renewing only when `nextReset` is set too: an API build from
before 2026-09-30 reported a paid plan with no billing period on file as
`'billing_period'` with no `nextReset`. The values are the `Kawaa\Models\AccountInfo::ALLOWANCE_*`
constants.

## Error Handling

```php
use Kawaa\Kawaa;
use Kawaa\Exceptions\{
    KawaaException,
    AuthenticationException,
    RateLimitException,
    InsufficientCreditsException,
    ValidationException,
    NotFoundException,
    ServerException,
    TimeoutException,
};

$client = new Kawaa('your-api-key');

try {
    $result = $client->verify('user@example.com');
} catch (AuthenticationException $e) {
    echo "Invalid API key\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry after {$e->getRetryAfter()}s\n";
} catch (InsufficientCreditsException $e) {
    echo "Not enough credits: {$e->getMessage()}\n";
} catch (ValidationException $e) {
    echo "Invalid request: {$e->getMessage()}\n";
} catch (TimeoutException $e) {
    echo "Request timed out\n";
} catch (ServerException $e) {
    echo "Server error (HTTP {$e->getCode()}): {$e->getMessage()}\n";
} catch (KawaaException $e) {
    echo "API error: {$e->getMessage()}\n";
}
```

Every exception also carries the API's retry guidance:

| Method | Returns |
|--------|---------|
| `getIdempotencyKey()` | The `Idempotency-Key` the call sent (verify, verifyBatch, find), or null |
| `getApiErrorCode()` | The API's stable code, such as `CONFLICT` or `INSUFFICIENT_SCOPE` |
| `getRequestId()` | The API's request id; quote it to support |
| `getRetryable()` | Whether the same request can succeed after a wait; null when the API did not say |
| `getRetryAfterSeconds()` | How long the API asked you to wait, uncapped; null when it did not say. Over `Kawaa::MAX_RETRY_AFTER_SECONDS` (60), the client did not retry the call |
| `retryRequiresSameIdempotencyKey()` | True when the API said to repeat only with the same key |
| `retryMayChargeAgain()` | True when this attempt may have been charged and a repeat may be charged again |

### Retries

`maxRetries` is the number of retries after the first attempt, so `0` sends
each request once. Before a retry the client waits for the `Retry-After` the
API sent, or backs off exponentially when there is none. When the API asks for
longer than `Kawaa::MAX_RETRY_AFTER_SECONDS` (60 seconds), the call is not
retried: the exception is thrown at once with the API's delay on
`getRetryAfterSeconds()`, so you decide whether to wait that long. A `409 CONFLICT` marked `retryable` on a
verify or find means a copy of the same keyed call is still running; the
client repeats it with the same key, and the repeat returns the paid result
without charging again. Nothing is retried when the error says a repeat may
charge again (`retryMayChargeAgain()`) or that the request is not retryable
(`getRetryable()` is false).

When the retries run out on a call that charges, repeat it yourself with the
key from the exception. The API replays an attempt that was already charged
instead of charging again:

```php
try {
    $result = $client->find('John', 'Smith', 'company.com');
} catch (KawaaException $e) {
    $wait = (int) ceil($e->getRetryAfterSeconds() ?? 5);
    // Over a minute (a blocked address is refused for an hour): retry later,
    // for example from a queued job, rather than holding this request.
    if ($e->getIdempotencyKey() !== null && !$e->retryMayChargeAgain() && $wait <= 60) {
        sleep($wait);
        $result = $client->find('John', 'Smith', 'company.com', $e->getIdempotencyKey());
    } else {
        throw $e;
    }
}
```

### Redirects

The client never follows a redirect. Each request carries your API key in
`X-Api-Key`, and Guzzle keeps that header when it follows a redirect to another
host, so following one would send the key to wherever it points. The Kawaa API
never redirects: a `3xx` answer is thrown as a `KawaaException` whose code is
that status and whose message names the redirect's origin, and it is not
retried. Check the base URL when you see one (an `http://` address, for
example). The presigned download of a large export, which carries no key, still
follows redirects.

## Configuration

```php
$client = new Kawaa(
    apiKey: 'your-api-key',
    baseUrl: 'https://api.kawaa.com',  // Custom API URL
    timeout: 30.0,                      // Request timeout in seconds
    maxRetries: 3,                      // Retries after the first attempt (0: send once)
);
```

## Models Reference

### VerificationResult

| Property | Type | Description |
|----------|------|-------------|
| `email` | string | The verified email |
| `status` | VerificationStatus | valid, invalid, risky, unknown, spam_trap, disposable, role, catch_all, pending |
| `score` | int | Quality score 0-100 |
| `flags` | VerificationFlags | Email characteristics |
| `suggestion` | ?string | AI-suggested correction |
| `verification` | ?VerificationDetails | Technical details |
| `jobId` | ?string | Job to poll when `status` is pending |
| `creditsUsed` | ?float | What the verification cost: 1 fresh, 0.5 from cache. On an idempotent replay, what the original request cost (null if the result could not be read back, the job read carried no charge, or the job's cache discount is still being applied; read the job for the charge) |
| `creditsRemaining` | ?float | Balance after the charge; null when the API omitted it |
| `creditsHeld` | ?float | Credits held rather than settled while pending |
| `idempotentReplay` | ?bool | True when this repeated a request already accepted under the same Idempotency-Key, so nothing more was charged |
| `fromCache` | bool | True when the API answered from its verification cache (charged 0.5). `false` also when the answer did not say: read `fromCacheStated` |
| `cacheAgeSeconds` | ?int | How old a cached result is, in seconds. Set on every cache hit; null otherwise |
| `verifiedAt` | ?string | When the verdict was measured. On a cache hit, the time of the original check |
| `enrichment` | ?Enrichment | Name, gender and country hints (`firstName`, `lastName`, `fullName`, `gender`, `country`, `countryCode`) when `enrich` was set on a plan with data enrichment. Null when not asked for or nothing is known |
| `fromCacheStated` | bool | Whether the answer said if it came from the cache. `false` on a download row and on a pending answer, which do not say; `fromCache` is then `false` without meaning "checked fresh" |

Credit amounts are floats throughout the SDK: balances and charges are
routinely fractional (a cached verification costs 0.5, an activity lookup
0.25).

`verify()` retries a lost response under the same Idempotency-Key, and the API
answers a repeat of an already-accepted request with a pending pointer to the
original job. `verify()` reads that job back (a short, bounded poll) and
returns its result with `idempotentReplay` true. If the job is still running,
or the key lacks `verification:read`, the result is pending with
`creditsUsed` null and the `jobId` to poll; the original charge stands on that
job. The API answers a replay with `credits_used` 0, which is what the repeat
cost, not what the verification cost, so it is never reported as the cost.

`verifyBatch()` keeps the replay marker too: a repeat of a batch already
accepted under the same key returns a `BatchJobResult` with `idempotentReplay`
true and `creditsUsed` null. `getJobStatus($jobId)` reports the batch's
`creditsCharged` and `creditsRefunded`. A `creditsRemaining` the API omitted is
null, not 0.

A successful answer that cannot be mapped into a result throws a
`KawaaException` that carries the call's key, like every other failure of
`verify()`, `verifyBatch()` and `find()`: repeat the call with
`getIdempotencyKey()` to replay it instead of paying again. A success status
whose body breaks off partway is retried under the same key, like a dropped
connection.

### VerificationFlags

| Property | Type | Description |
|----------|------|-------------|
| `disposable` | bool | Temporary email service |
| `role` | bool | Role account (info@, support@) |
| `freeProvider` | bool | Free email provider |
| `catchAll` | bool | `true` when the domain was seen to accept mail for any address (or the status is `catch_all`). `false` also when nothing measured it: read `catchAllMeasured` |
| `spamTrap` | bool | Reserved. Kawaa's reviewed trap-domain list is empty today, so it is `false` on every new result |
| `catchAllMeasured` | bool | Whether catch-all was measured. `false` means `catchAll` is unknown, not "not catch-all": no probe ran (a disposable address, no usable mail server, a server that refused the mailbox or never answered) |
| `abuseEmail` | bool | The local part matched an abuse, complaint-handling, feedback-loop, legal or blocklist-removal name (abuse@, postmaster@, feedback@), at any domain |
| `complainer` | bool | A complaint-handling mailbox at a reviewed anti-spam, feedback-loop or consumer-protection organization. Always comes with `abuseEmail` |
| `honeypot` | bool | The API's `honeypot` flag |
| `botGenerated` | bool | The API's `bot_generated` flag |
| `riskFlagsStated` | bool | Whether the answer carried the four flags above. `false` on a download row: there they read `false` without meaning "no" |

These four lower `score` without changing `status`, so read them to see why a
score is low. The download export does not carry them, so on a download row
they read `false` and `riskFlagsStated` is `false`: read them from `verify()`
or from `getJobStatus()` results.

### JobStatusResult

| Property | Type | Description |
|----------|------|-------------|
| `jobId` | string | Unique job identifier |
| `status` | JobStatus | pending, processing, completed, failed, cancelled, enqueue_unknown (accepted, but some queue sends had an unknown outcome; keep polling) |
| `totalEmails` | int | Total emails in job |
| `processedEmails` | int | Emails processed so far |
| `validCount` | int | Valid email count |
| `invalidCount` | int | Invalid email count |
| `riskyCount` | int | Risky email count |
| `progressPercent` | float | Completion percentage |
| `results` | array | The first page of VerificationResult items (up to 100). `hasMore` is true when there are more; `downloadResults()` returns them all |
| `creditsCharged` | ?float | What the job was charged (the job's `credits_used`) |
| `creditsRefunded` | ?float | What has come back since; the job cost `creditsCharged - creditsRefunded` |
| `errorMessage` | ?string | Why the job ended as it did (for example, why it failed). `waitForJob()` puts it in its exception message |

### DomainSearchResult

| Property | Type | Description |
|----------|------|-------------|
| `domain` | string | Searched domain |
| `emailCount` | int | Number of emails found |
| `emails` | array | Array of DomainSearchEmail (email, status, qualityScore, isRoleAccount, isCatchAll, fromCache) |
| `status` | ?string | "processing" while the async discovery job runs (re-poll), "completed" when done |
| `patternsChecked` | int | Number of patterns tested |
| `creditsUsed` | float | Credits consumed |
| `creditsRemaining` | float | Balance after the charge |
| `fromCache` | bool | Whether result was served from cache |

### FindResult

| Property | Type | Description |
|----------|------|-------------|
| `email` | ?string | The address settled on. With `verified` false it is a best guess, not a confirmed address |
| `confidence` | int | Confidence score 0-100 |
| `pattern` | ?string | Email pattern used |
| `verified` | bool | True only on a `valid` verdict; `found()` is true only then |
| `creditsUsed` | float | What the SEARCH cost (5). Not what this call charged: see `idempotentReplay` |
| `creditsRemaining` | ?float | Balance after the charge; null when the API omitted it |
| `idempotentReplay` | ?bool | True when this repeated an identical search already charged for, so this call cost nothing. Null means the API said nothing either way, NOT that it charged |

## Running Tests

```bash
composer install
composer test
```

## License

MIT License - see [LICENSE](LICENSE) for details.

## Support

- Documentation: https://kawaa.com/docs
- Email: support@kawaa.com
- Support: https://kawaa.com/support
