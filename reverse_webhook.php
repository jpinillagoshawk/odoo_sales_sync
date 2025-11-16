<?php
/**
 * Reverse Webhook Endpoint
 *
 * Receives webhooks FROM Odoo TO PrestaShop for reverse synchronization.
 * Supports: customer, order, address, coupon entities.
 *
 * Security:
 * - Webhook secret validation (X-Webhook-Secret header)
 * - Optional IP whitelist
 * - Request validation
 *
 * Usage:
 * Configure this URL in Odoo webhook:
 * https://your-prestashop.com/modules/odoo_sales_sync/reverse_webhook.php
 *
 * Headers required:
 * - Content-Type: application/json
 * - X-Webhook-Secret: [your-secret]
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

// ============================================================================
// STEP 1: Security - Check BEFORE loading PrestaShop
// ============================================================================

// Set response headers early
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Emergency logging function (before PrestaShop is loaded)
function reverseWebhookEmergencyLog($message, $data = [])
{
    $logFile = dirname(__FILE__) . '/reverse_webhook.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] %s %s\n",
        $timestamp,
        $message,
        !empty($data) ? json_encode($data) : ''
    );
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Log incoming request
reverseWebhookEmergencyLog('[REVERSE_WEBHOOK] Request received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
]);

// Check HTTP method (must be POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reverseWebhookEmergencyLog('[REVERSE_WEBHOOK] Invalid method', [
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]));
}

// ============================================================================
// STEP 2: Load PrestaShop
// ============================================================================

// Find PrestaShop config
$configFile = dirname(__FILE__) . '/../../config/config.inc.php';

if (!file_exists($configFile)) {
    reverseWebhookEmergencyLog('[REVERSE_WEBHOOK] PrestaShop config not found', [
        'path' => $configFile
    ]);
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Server configuration error'
    ]));
}

// Load PrestaShop
require_once $configFile;

// Load required classes
require_once dirname(__FILE__) . '/classes/OdooSalesReverseSyncContext.php';
require_once dirname(__FILE__) . '/classes/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/classes/OdooSalesReverseWebhookRouter.php';
require_once dirname(__FILE__) . '/classes/OdooSalesLogger.php';

// ============================================================================
// STEP 3: Check if reverse sync is enabled
// ============================================================================

$logger = new OdooSalesLogger('reverse_webhook');

if (!Configuration::get('ODOO_SALES_SYNC_REVERSE_ENABLED')) {
    $logger->warning('[REVERSE_WEBHOOK] Reverse sync is disabled');
    http_response_code(403);
    die(json_encode([
        'success' => false,
        'error' => 'Reverse synchronization is disabled'
    ]));
}

// ============================================================================
// STEP 4: Validate webhook secret
// ============================================================================

$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
$configuredSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET');

if (empty($configuredSecret)) {
    $logger->error('[REVERSE_WEBHOOK] Webhook secret not configured');
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Server configuration error: webhook secret not configured'
    ]));
}

if ($receivedSecret !== $configuredSecret) {
    $logger->warning('[REVERSE_WEBHOOK] Invalid webhook secret', [
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
        'received_secret_length' => strlen($receivedSecret)
    ]);
    http_response_code(403);
    die(json_encode([
        'success' => false,
        'error' => 'Invalid webhook secret'
    ]));
}

// ============================================================================
// STEP 5: Optional IP whitelist check
// ============================================================================

$allowedIps = Configuration::get('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS');
if (!empty($allowedIps)) {
    $allowedIpList = array_map('trim', explode(',', $allowedIps));
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($remoteIp, $allowedIpList)) {
        $logger->warning('[REVERSE_WEBHOOK] IP not whitelisted', [
            'remote_ip' => $remoteIp,
            'allowed_ips' => $allowedIpList
        ]);
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'error' => 'IP address not whitelisted'
        ]));
    }
}

// ============================================================================
// STEP 6: Parse JSON payload
// ============================================================================

$rawPayload = file_get_contents('php://input');

if (empty($rawPayload)) {
    $logger->warning('[REVERSE_WEBHOOK] Empty payload');
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Empty payload'
    ]));
}

$payload = json_decode($rawPayload, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $logger->error('[REVERSE_WEBHOOK] Invalid JSON', [
        'error' => json_last_error_msg(),
        'payload_preview' => substr($rawPayload, 0, 200)
    ]);
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Invalid JSON: ' . json_last_error_msg()
    ]));
}

// Log received payload
$logger->info('[REVERSE_WEBHOOK] Payload received', [
    'entity_type' => $payload['entity_type'] ?? 'unknown',
    'action_type' => $payload['action_type'] ?? 'unknown',
    'event_id' => $payload['event_id'] ?? null,
    'payload_size' => strlen($rawPayload)
]);

// ============================================================================
// STEP 7: Route to processor
// ============================================================================

$startTime = microtime(true);

try {
    $result = OdooSalesReverseWebhookRouter::route($payload);

    $processingTime = microtime(true) - $startTime;

    $logger->info('[REVERSE_WEBHOOK] Processing completed', [
        'success' => $result['success'],
        'processing_time_seconds' => round($processingTime, 3),
        'entity_id' => $result['entity_id'] ?? null
    ]);

    // Return appropriate HTTP status code
    $httpCode = $result['success'] ? 200 : 500;
    http_response_code($httpCode);

    // Add metadata to response
    $result['received_at'] = date('c');
    $result['processing_time_seconds'] = round($processingTime, 3);

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    $logger->error('[REVERSE_WEBHOOK] Exception during processing', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'received_at' => date('c')
    ], JSON_PRETTY_PRINT);
}

exit(0);
