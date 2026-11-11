<?php

// No declare(strict_types=1) on purpose: this is how most application code
// calls the SDK, and in such a file PHP would coerce a scalar passed to a
// `bool` parameter (the string "false" becomes true). The helpers must refuse
// a non-boolean allowLegacySignature here too.

namespace Kawaa\Tests\Fixtures;

use Kawaa\Webhook;

/**
 * Build or call each verifying helper with $allow as allowLegacySignature,
 * from a caller without strict types.
 *
 * @return array<string, callable(): mixed>
 */
function nonStrictOptInCalls(string $secret, string $body, string $signature, $allow): array
{
    return [
        'parse' => fn () => Webhook::parse($body, $signature, $secret, 300, $allow),
        'laravelHandler' => fn () => Webhook::laravelHandler($secret, 300, $allow),
        'symfonyHandler' => fn () => Webhook::symfonyHandler($secret, 300, $allow),
        'handleRequest' => fn () => Webhook::handleRequest($secret, 300, $allow),
    ];
}
