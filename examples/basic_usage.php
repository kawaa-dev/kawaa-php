<?php

/**
 * Kawaa PHP SDK - Basic Usage Examples
 *
 * Run with: php examples/basic_usage.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kawaa\Kawaa;
use Kawaa\Exceptions\KawaaException;

// Replace with your API key
$apiKey = getenv('KAWAA_API_KEY') ?: 'your-api-key';

$client = new Kawaa($apiKey);

echo "=== Kawaa PHP SDK Examples ===\n\n";

// Example 1: Single Email Verification
echo "1. Single Email Verification\n";
echo str_repeat('-', 40) . "\n";

try {
    $result = $client->verify('test@example.com');

    echo "Email: {$result->email}\n";
    echo "Status: {$result->status->value}\n";
    echo "Score: {$result->score}\n";
    echo "Valid: " . ($result->isValid() ? 'Yes' : 'No') . "\n";
    echo "Deliverable: " . ($result->isDeliverable() ? 'Yes' : 'No') . "\n";

    echo "\nFlags:\n";
    echo "  - Disposable: " . ($result->flags->disposable ? 'Yes' : 'No') . "\n";
    echo "  - Role: " . ($result->flags->role ? 'Yes' : 'No') . "\n";
    echo "  - Free Provider: " . ($result->flags->freeProvider ? 'Yes' : 'No') . "\n";
    $catchAll = $result->flags->catchAllMeasured
        ? ($result->flags->catchAll ? 'Yes' : 'No')
        : 'not measured';
    echo "  - Catch-all: {$catchAll}\n";

    if ($result->suggestion) {
        echo "\nSuggestion: Did you mean {$result->suggestion}?\n";
    }
} catch (KawaaException $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n";

// Example 2: Email Finder
echo "2. Email Finder\n";
echo str_repeat('-', 40) . "\n";

try {
    $result = $client->find('John', 'Smith', 'example.com');

    if ($result->found()) {
        echo "Found: {$result->email}\n";
        echo "Confidence: {$result->confidence}%\n";
        echo "Pattern: {$result->pattern}\n";
        echo "Verified: " . ($result->verified ? 'Yes' : 'No') . "\n";
    } else {
        echo "Email not found\n";
    }

    echo "Credits used: {$result->creditsUsed}\n";
} catch (KawaaException $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n";

// Example 3: Domain Search
echo "3. Domain Search\n";
echo str_repeat('-', 40) . "\n";

try {
    $result = $client->domainSearch('example.com');

    echo "Domain: {$result->domain}\n";
    echo "Emails found: {$result->emailCount}\n";
    echo "Patterns checked: {$result->patternsChecked}\n";
    echo "Cached: " . ($result->fromCache ? 'Yes' : 'No') . "\n";

    if (count($result->emails) > 0) {
        echo "\nEmails:\n";
        foreach ($result->emails as $email) {
            echo "  - {$email->email}: {$email->status}\n";
        }
    }

    echo "Credits used: {$result->creditsUsed}\n";
} catch (KawaaException $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n";

// Example 4: Account Info
echo "4. Account Info\n";
echo str_repeat('-', 40) . "\n";

try {
    $account = $client->getAccount();

    echo "User ID: {$account->userId}\n";
    echo "Email: {$account->email}\n";
    echo "Plan: {$account->plan}\n";
    echo "Credits remaining: {$account->creditsRemaining}\n";
    echo "Credits used: " . ($account->creditsUsed ?? 'not reported') . "\n";
} catch (KawaaException $e) {
    echo "Error: {$e->getMessage()}\n";
}

echo "\n=== Examples Complete ===\n";
