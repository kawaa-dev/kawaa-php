<?php

/**
 * Kawaa PHP SDK - Webhook Handler Example
 *
 * This file demonstrates how to handle Kawaa webhooks.
 * Deploy this as an endpoint (e.g., /webhook.php) to receive batch completion notifications.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kawaa\Webhook;
use Kawaa\Exceptions\ValidationException;

header('Content-Type: application/json');

// Your webhook signing secret. Never fall back to a placeholder: anyone could
// sign a forged webhook with it.
$webhookSecret = getenv('KAWAA_WEBHOOK_SECRET');
if ($webhookSecret === false || trim($webhookSecret) === '') {
    error_log('KAWAA_WEBHOOK_SECRET is not set. Set it to your webhook signing secret.');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Webhook endpoint is not configured']);
    exit(1);
}

try {
    // Parse and verify the webhook: handleRequest checks the
    // X-Kawaa-Signature-V2 header and its 300-second replay window, and
    // refuses a request signed only with the legacy X-Kawaa-Signature header
    // (pass allowLegacySignature: true to accept that replayable header).
    // Registered webhooks deliver an envelope
    // {event, data: {...}, webhook_id, timestamp}; the event-specific fields
    // live under `data` and are exposed via the typed accessors.
    $event = Webhook::handleRequest($webhookSecret);

    // Log the event
    error_log("Kawaa webhook received: {$event->event} (webhook {$event->webhookId})");

    // Process the event
    if ($job = $event->jobCompleted()) {
        error_log("Job {$job->jobId} completed with {$job->summary?->valid} valid emails");
        echo json_encode([
            'status' => 'ok',
            'message' => 'Webhook processed',
            'job_id' => $job->jobId,
        ]);
    } elseif ($verification = $event->verificationCompleted()) {
        error_log("Verification {$verification->jobId} finished: {$verification->status}");
        echo json_encode(['status' => 'ok']);
    } elseif ($credits = $event->creditsLow()) {
        error_log("Credits low: {$credits->creditsRemaining} remaining");
        echo json_encode(['status' => 'ok']);
    } else {
        // Unknown/unhandled event; raw fields stay available on $event->data
        echo json_encode(['status' => 'ok']);
    }

    // Example: Do something with the results
    // - Update your database
    // - Send notification to user
    // - Download and process results

    /*
    Envelope properties:
    - $event->event        // "job.completed"
    - $event->data         // raw event data array
    - $event->webhookId    // "wh_abc123" (null on legacy per-job callbacks)
    - $event->timestamp    // "2024-01-15T10:30:01Z"
    - $event->test         // true for dashboard Test deliveries
    - $event->rawPayload   // complete decoded body

    job.completed data ($event->jobCompleted()):
    - $job->jobId             // "job-abc123"
    - $job->status            // "completed"
    - $job->totalEmails       // 1000
    - $job->processedEmails   // 1000
    - $job->summary->valid    // 800
    - $job->summary->invalid  // 150
    - $job->summary->risky    // 40
    - $job->summary->unknown  // 10
    - $job->downloadUrl       // "https://..."
    - $job->createdAt         // "2024-01-15T10:00:00Z"
    - $job->completedAt       // "2024-01-15T10:30:00Z"
    */

    // If you need to download results:
    /*
    $client = new Kawaa('your-api-key');
    if ($job = $event->jobCompleted()) {
        $results = $client->downloadResults($job->jobId);
        foreach ($results->results as $result) {
            // Process each result
            saveToDatabase($result->email, $result->status->value, $result->score);
        }
    }
    */

} catch (ValidationException $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
    error_log("Kawaa webhook validation failed: {$e->getMessage()}");
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error',
    ]);
    error_log("Kawaa webhook error: {$e->getMessage()}");
}
