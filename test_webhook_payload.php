<?php
/**
 * Test Webhook Payload Generator
 *
 * Run this script to see what JSON payload would be generated
 * Usage: php test_webhook_payload.php
 */

// Simulate PrestaShop environment
define('_PS_VERSION_', '8.2.0');

// Include necessary classes
require_once __DIR__ . '/classes/OdooSalesEvent.php';
require_once __DIR__ . '/classes/OdooSalesWebhookClient.php';
require_once __DIR__ . '/classes/OdooSalesLogger.php';

// Create a mock event
$event = new stdClass();
$event->id = 12345;
$event->entity_type = 'customer';
$event->entity_id = 100;
$event->entity_name = 'Test Customer';
$event->action_type = 'created';
$event->transaction_hash = hash('sha256', 'test_' . time());
$event->correlation_id = 'test-corr-123';
$event->hook_name = 'actionCustomerAccountAdd';
$event->hook_timestamp = date('Y-m-d H:i:s');
$event->before_data = null;
$event->after_data = json_encode([
    'id' => 100,
    'firstname' => 'John',
    'lastname' => 'Doe',
    'email' => 'john.doe@example.com'
]);
$event->change_summary = 'Customer created';
$event->context_data = json_encode([
    'source' => 'prestashop',
    'shop_id' => 1
]);

echo "=== MOCK EVENT DATA ===\n";
print_r($event);
echo "\n";

// Prepare event data using the same method as WebhookClient
function prepareEventData($event) {
    // Safely decode JSON fields with validation
    $beforeData = null;
    if (!empty($event->before_data)) {
        $decoded = json_decode($event->before_data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $beforeData = $decoded;
        }
    }

    $afterData = null;
    if (!empty($event->after_data)) {
        $decoded = json_decode($event->after_data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $afterData = $decoded;
        }
    }

    $contextData = null;
    if (!empty($event->context_data)) {
        $decoded = json_decode($event->context_data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $contextData = $decoded;
        }
    }

    return [
        'event_id' => (int)$event->id,
        'entity_type' => (string)$event->entity_type,
        'entity_id' => (int)$event->entity_id,
        'entity_name' => (string)($event->entity_name ?? ''),
        'action_type' => (string)$event->action_type,
        'transaction_hash' => (string)$event->transaction_hash,
        'correlation_id' => (string)($event->correlation_id ?? ''),
        'hook_name' => (string)$event->hook_name,
        'hook_timestamp' => (string)$event->hook_timestamp,
        'before_data' => $beforeData,
        'after_data' => $afterData,
        'change_summary' => (string)($event->change_summary ?? ''),
        'context_data' => $contextData
    ];
}

// Create batch data
$batchData = [
    'batch_id' => 'test_batch_' . time(),
    'timestamp' => date('Y-m-d H:i:s'),
    'events' => [
        prepareEventData($event)
    ]
];

echo "=== BATCH DATA ARRAY ===\n";
print_r($batchData);
echo "\n";

// Encode to JSON
$jsonPayload = json_encode($batchData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ JSON ENCODING ERROR: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "=== JSON PAYLOAD ===\n";
echo $jsonPayload . "\n\n";

echo "=== PAYLOAD INFO ===\n";
echo "Length (strlen): " . strlen($jsonPayload) . " bytes\n";
echo "Length (mb_strlen): " . mb_strlen($jsonPayload, '8bit') . " bytes\n";
echo "Valid JSON: " . (json_decode($jsonPayload) !== null ? 'YES ✓' : 'NO ✗') . "\n";

// Validate JSON by decoding
$decoded = json_decode($jsonPayload, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "Decode test: SUCCESS ✓\n";
    echo "Events count: " . count($decoded['events']) . "\n";
} else {
    echo "Decode test: FAILED ✗\n";
    echo "Error: " . json_last_error_msg() . "\n";
}

echo "\n=== PRETTY PRINTED JSON ===\n";
echo json_encode($batchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n✓ Test completed successfully\n";
