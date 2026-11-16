# Changelog

All notable changes to this SDK are documented in this file. It was started at 0.2.0 and does not
yet list every change made since 0.1.1.

## [Unreleased]

## [0.3.0] - 2026-11-16

### Breaking changes

- `AccountInfo::$creditsUsed` is `?float`, and null when the API omitted usage (such as for a
  scoped key without `analytics:read`, where `$account->usageWithheld` is `true`). `AccountInfo::$freeGrant`
  is `"withheld"` when `credits.free_grant` reports that another account already claimed the
  mailbox's one-time Free grant.

### Added

- `VerificationResult::$enrichment` (a new `Enrichment` model with `firstName`, `lastName`,
  `fullName`, `gender`, `country` and `countryCode`): the hints the API attaches when `enrich` is
  set on a plan with data enrichment. 0.2.0 sent the option and dropped the answer.
- `VerificationResult::$fromCache`, `$cacheAgeSeconds` and `$verifiedAt`, so a cached verdict says
  how old it is. Download rows carry `verifiedAt` too.
- `VerificationFlags::$abuseEmail`, `$complainer`, `$honeypot` and `$botGenerated`. The API lowers
  the score for each without changing the status, so these say why a score is low.
- `BatchJobResult::$invalidEmailsSkipped` (malformed addresses refused and not charged) and
  `$enqueueUnknown` (queue sends that could not be confirmed; null means not reported, so read
  the status for whether any are unconfirmed).
- `JobStatusResult::$errorMessage`, the API's reason a job ended as it did. `waitForJob()` puts it
  in the exception message for a failed job.

All of them are new last constructor arguments with defaults, appear in `toArray()`, and are read
leniently: a value of the wrong type reads as not stated and never fails the result.

### Fixed

- `downloadResults()` now preserves the actual job status from `"Job is still <status>."` on
  `JobNotCompleteException` (for example `'pending'`) instead of hardcoding `'processing'`.
- The `verifyBatch()` and `verifyBatchAndWait()` documentation offered `$timeoutMs` as a batch
  option. The API refuses it on a batch with `400 INVALID_OPTION` before anything is charged; it
  applies to `verify()` only.

## [0.2.0] - 2026-11-11

### Added

- `VerificationFlags::$catchAllMeasured` says whether the API measured catch-all for the
  address. Kawaa measures it only after the mail server accepts the mailbox, and the API leaves
  its finding out when nothing measured it: a disposable address, a domain with no usable mail
  server, a server that refused the mailbox or never answered. `$catchAll` is `false` in those
  cases too, so on its own it could not tell "checked, not catch-all" from "not checked". Treat
  an address as not catch-all only when `$catchAllMeasured` is `true` and `$catchAll` is
  `false`. It is the last constructor argument, defaults to `false`, and is in `toArray()` as
  `catch_all_measured`. `VerificationFlags::fromArray()` takes it as an optional second
  argument.
- `VerificationDetails::$catchAll` is the API's catch-all finding as stated: `true`, `false`, or
  `null` when nothing measured it. `toArray()` includes `catch_all` only when it is not `null`,
  and a flat download row that states `catch_all` gets details, so
  `VerificationResult::fromArray($result->toArray())` keeps what was measured.

### Security

- The client never follows a redirect. Guzzle follows them by default and keeps `X-Api-Key` when
  the redirect goes to another host, so a redirect from the API's address sent the API key to
  wherever it pointed. The Kawaa API never redirects; a `3xx` answer is now thrown as a
  `KawaaException` whose code is that status and whose message names the redirect's origin, and
  it is not retried. The presigned download of a large export, which carries no key, still
  follows redirects.

- **Breaking:** `Webhook::parse()`, `Webhook::laravelHandler()`, `Webhook::symfonyHandler()` and
  `Webhook::handleRequest()` now verify the `X-Kawaa-Signature-V2` header and its 300-second replay
  window by default, so a captured delivery can no longer be replayed through them. Set the window
  with the new `toleranceSeconds` argument (for example `toleranceSeconds: 600`); a negative value
  throws an `InvalidArgumentException`, and the Laravel and Symfony handlers throw it when you
  create them. The legacy `X-Kawaa-Signature` header (or a `sha256=...` value passed to
  `Webhook::parse()`) is accepted only with the new `allowLegacySignature: true` argument. That
  header signs no time, so a request accepted through it can be a replay of any delivery ever
  captured: turn it on only for a sender that cannot send `X-Kawaa-Signature-V2`. A request that
  carries an `X-Kawaa-Signature-V2` header is always checked against it, even with that option on.
  `allowLegacySignature` must be a real boolean: any other value (`null`, `1`, or a string such as
  `"false"` from `getenv()`) throws a `TypeError`, with or without `strict_types`, instead of
  being coerced to `true`.
  To migrate, pass the `X-Kawaa-Signature-V2` header value
  (`$_SERVER['HTTP_X_KAWAA_SIGNATURE_V2']`, `$request->header('X-Kawaa-Signature-V2')`) to
  `Webhook::parse()` instead of `X-Kawaa-Signature`; the handlers read it themselves. Every Kawaa
  delivery already sends both headers. `Webhook::verifySignature()` is unchanged and still checks
  the legacy header alone; prefer `Webhook::verifySignatureV2()`.

### Breaking changes

- `FindResult::found()` is true only for a confirmed address: `email` set and `verified` true (a
  `valid` verdict). It used to be true for any `email` with a `confidence` above 0, so a
  catch-all, risky or role result, where the API returns its best guess with `verified` false,
  read as found. Check `$result->email` and `$result->status` to use such a guess knowingly.
  `verified` is true only for a JSON `true`.
- `composer.json` requires PHP 8.1, the version the code has always needed: the models use
  readonly properties and enums, so on PHP 8.0 every model file is a parse error. It said 8.0.
- `BatchJobResult::$creditsUsed` and `$creditsRemaining` are `?float`, and null when the API did
  not send a number. They used to fall back to `0`, which read as a free batch or an empty
  balance. A replay of a batch already accepted under the same `Idempotency-Key` (most often this
  client's own retry after a lost answer) used to report `creditsUsed` `0`, the API's figure for
  what the repeat cost; it is now null, and the new `BatchJobResult::$idempotentReplay` (also in
  `toArray()`) says the response was a replay. The batch's charge and refunds are on its job:
  `getJobStatus()` reports `creditsCharged` and `creditsRefunded`.

### Fixed

- A successful answer that cannot be mapped into a result (a field of the wrong type) throws a
  `KawaaException` carrying the call's `Idempotency-Key`. The mapping in `verify()`,
  `verifyBatch()` and `find()` ran after the code that attaches the key, so the failure escaped
  as a bare `TypeError` without it, and a repeat under a new key could pay again.
  `KawaaException`'s `$previous` now accepts any `Throwable`.
- A success status whose body breaks off partway (curl errors 18 and 56 after the headers) is
  treated as a dropped connection: a call that carries a key is sent again under the same key.
  It used to surface at once as an error with status 200.
- `verify()` no longer reports a replay's `credits_used` of `0` as the verification's cost. When
  the job behind a replay cannot be read back, or its read carries no charge, `creditsUsed` is
  null, and `getJobStatus()` has the charge.
