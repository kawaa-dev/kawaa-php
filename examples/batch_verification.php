<?php

/**
 * Kawaa PHP SDK - Batch Verification Example
 *
 * Run with: php examples/batch_verification.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kawaa\Kawaa;
use Kawaa\Exceptions\KawaaException;
use Kawaa\Exceptions\TimeoutException;

// Replace with your API key
$apiKey = getenv('KAWAA_API_KEY') ?: 'your-api-key';

$client = new Kawaa($apiKey);

echo "=== Batch Verification Example ===\n\n";

$emails = [
    'john@example.com',
    'jane@example.com',
    'support@example.com',
    'invalid-email',
    'john@example.com',  // Duplicate
];

echo "Submitting " . count($emails) . " emails for verification...\n\n";

try {
    // Option 1: Start job and poll separately
    echo "Starting batch job...\n";
    $job = $client->verifyBatch($emails);

    echo "Job ID: {$job->jobId}\n";
    echo "Status: {$job->status->value}\n";
    echo "Total submitted: {$job->totalSubmitted}\n";
    echo "Duplicates removed: {$job->duplicatesRemoved}\n";
    echo "Unique emails: {$job->uniqueEmails}\n";
    // null when not known: a replay of a batch already accepted, or a figure the API omitted
    echo "Credits used: " . ($job->creditsUsed ?? 'not reported') . "\n";
    echo "Credits remaining: " . ($job->creditsRemaining ?? 'not reported') . "\n";

    echo "\nWaiting for completion...\n";

    $result = $client->waitForJob(
        $job->jobId,
        pollInterval: 2.0,
        timeout: 60.0
    );

    echo "\n=== Results ===\n";
    echo "Status: {$result->status->value}\n";
    echo "Progress: {$result->progressPercent}%\n";
    echo "Valid: {$result->validCount}\n";
    echo "Invalid: {$result->invalidCount}\n";
    echo "Risky: {$result->riskyCount}\n";
    echo "Unknown: {$result->unknownCount}\n";

    echo "\nIndividual Results:\n";
    foreach ($result->results as $r) {
        $status = str_pad($r->status->value, 8);
        echo "  {$r->email}: {$status} (score: {$r->score})\n";
    }

    // Option 2: One-liner with verifyBatchAndWait
    // Uncomment to use:
    // $result = $client->verifyBatchAndWait($emails, pollInterval: 2.0, timeout: 60.0);

} catch (TimeoutException $e) {
    echo "Job timed out: {$e->getMessage()}\n";
} catch (KawaaException $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n=== Example Complete ===\n";
