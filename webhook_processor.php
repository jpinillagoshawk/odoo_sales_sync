<?php
/**
 * Background Webhook Processor
 *
 * This script runs as a detached CLI process to send sales events to Odoo
 * without blocking the PrestaShop frontend.
 *
 * Called by webhook.php with event IDs as a temp file parameter.
 *
 * Adapted from odoo_direct_stock_sync webhook_processor.php
 * - Changed: stock events → sales events
 * - Changed: StockEvent → SalesEvent
 * - Changed: ApiClient → WebhookClient
 * - Kept: Same consolidation logic, same batch sending pattern
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

// ============================================================================
// EMERGENCY LOGGING (before PrestaShop loads)
// ============================================================================

function emergencyLog($message, $data = []) {
    $logFile = dirname(__FILE__) . '/webhook_processing.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] %s %s\n",
        $timestamp,
        $message,
        !empty($data) ? json_encode($data) : ''
    );
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

emergencyLog('[PROCESSOR] Script started', [
    'pid' => getmypid(),
    'args' => $argv ?? [],
    'cwd' => getcwd()
]);

// ============================================================================
// LOAD EVENT IDS FROM TEMP FILE
// ============================================================================

if (!isset($argv[1])) {
    emergencyLog('[PROCESSOR] ERROR: No temp file parameter provided');
    exit(1);
}

$tempFile = $argv[1];

if (!file_exists($tempFile)) {
    emergencyLog('[PROCESSOR] ERROR: Temp file not found', ['temp_file' => $tempFile]);
    exit(1);
}

$eventIdsJson = file_get_contents($tempFile);
$eventIds = json_decode($eventIdsJson, true);

if (!is_array($eventIds) || empty($eventIds)) {
    emergencyLog('[PROCESSOR] ERROR: Invalid event IDs', ['content' => $eventIdsJson]);
    @unlink($tempFile);
    exit(1);
}

emergencyLog('[PROCESSOR] Event IDs loaded', [
    'event_ids' => $eventIds,
    'count' => count($eventIds)
]);

// ============================================================================
// LOAD PRESTASHOP
// ============================================================================

emergencyLog('[PROCESSOR] Loading PrestaShop');

$configPath = dirname(__FILE__) . '/../../config/config.inc.php';

if (!file_exists($configPath)) {
    emergencyLog('[PROCESSOR] ERROR: PrestaShop config not found', ['expected_path' => $configPath]);
    @unlink($tempFile);
    exit(1);
}

require_once($configPath);

emergencyLog('[PROCESSOR] PrestaShop loaded', [
    '_PS_VERSION_' => defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown',
    '_DB_PREFIX_' => defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'unknown'
]);

// ============================================================================
// LOAD MODULE CLASSES (in dependency order)
// ============================================================================

emergencyLog('[PROCESSOR] Loading module classes');

$classesDir = dirname(__FILE__) . '/classes/';

// Load in dependency order
$classFiles = [
    'OdooSalesLogger.php',       // No dependencies
    'OdooSalesLog.php',          // Depends on ObjectModel (PrestaShop core)
    'OdooSalesEvent.php',        // Depends on ObjectModel
    'OdooSalesWebhookClient.php', // Depends on Logger
    'OdooSalesEventQueue.php',   // Depends on Logger, WebhookClient
];

foreach ($classFiles as $classFile) {
    $classPath = $classesDir . $classFile;

    if (!file_exists($classPath)) {
        emergencyLog('[PROCESSOR] ERROR: Class file not found', [
            'class_file' => $classFile,
            'expected_path' => $classPath
        ]);
        @unlink($tempFile);
        exit(1);
    }

    require_once($classPath);
    emergencyLog('[PROCESSOR] Loaded class file', ['file' => $classFile]);
}

// ============================================================================
// INITIALIZE LOGGER
// ============================================================================

try {
    $logger = new OdooSalesLogger();
    emergencyLog('[PROCESSOR] Logger initialized');
} catch (Exception $e) {
    emergencyLog('[PROCESSOR] ERROR: Failed to initialize logger', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    @unlink($tempFile);
    exit(1);
}

// ============================================================================
// LOAD EVENTS FROM DATABASE
// ============================================================================

$logger->info('[PROCESSOR] Loading events from database', [
    'event_ids' => $eventIds
]);

$events = [];
$notFoundIds = [];

foreach ($eventIds as $eventId) {
    try {
        $event = new OdooSalesEvent((int)$eventId);

        if (Validate::isLoadedObject($event)) {
            $events[] = $event;
            $logger->debug('[PROCESSOR] Event loaded', [
                'event_id' => $eventId,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'action_type' => $event->action_type,
                'sync_status' => $event->sync_status
            ]);
        } else {
            $notFoundIds[] = $eventId;
            $logger->warning('[PROCESSOR] Event not found or invalid', [
                'event_id' => $eventId
            ]);
        }
    } catch (Exception $e) {
        $notFoundIds[] = $eventId;
        $logger->error('[PROCESSOR] Exception loading event', [
            'event_id' => $eventId,
            'error' => $e->getMessage()
        ]);
    }
}

if (empty($events)) {
    $logger->error('[PROCESSOR] No valid events found', [
        'requested_ids' => $eventIds,
        'not_found_ids' => $notFoundIds
    ]);
    @unlink($tempFile);
    exit(1);
}

$logger->info('[PROCESSOR] Events loaded successfully', [
    'total_requested' => count($eventIds),
    'loaded' => count($events),
    'not_found' => count($notFoundIds)
]);

// ============================================================================
// CONSOLIDATE EVENTS (reduce duplicates)
// ============================================================================

$logger->info('[PROCESSOR] Starting event consolidation', [
    'original_count' => count($events)
]);

$consolidated = OdooSalesEventQueue::consolidateEvents($events);

$logger->info('[PROCESSOR] Event consolidation completed', [
    'original_count' => count($events),
    'consolidated_count' => count($consolidated),
    'reduction' => count($events) - count($consolidated)
]);

// ============================================================================
// SEND EVENTS TO ODOO WEBHOOK
// ============================================================================

$logger->info('[PROCESSOR] Initializing webhook client');

try {
    $webhookClient = new OdooSalesWebhookClient();

    if (!$webhookClient->isConfigured()) {
        $logger->error('[PROCESSOR] Webhook client not configured', [
            'check_configuration' => 'ODOO_SALES_SYNC_WEBHOOK_URL and ODOO_SALES_SYNC_WEBHOOK_SECRET'
        ]);

        // Mark events as failed
        foreach ($consolidated as $event) {
            $event->sync_status = 'failed';
            $event->sync_error = 'Webhook not configured';
            $event->sync_attempts = ($event->sync_attempts ?? 0) + 1;
            $event->sync_last_attempt = date('Y-m-d H:i:s');
            $event->save();
        }

        @unlink($tempFile);
        exit(1);
    }
} catch (Exception $e) {
    $logger->error('[PROCESSOR] ERROR: Failed to initialize webhook client', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    @unlink($tempFile);
    exit(1);
}

$logger->info('[PROCESSOR] Sending events to Odoo', [
    'event_count' => count($consolidated),
    'webhook_url' => Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL')
]);

$startTime = microtime(true);

// Send batch to webhook client (matches reference module pattern)
// The client handles individual event status updates
$result = $webhookClient->sendBatchSalesEvents($consolidated);

$executionTime = round((microtime(true) - $startTime) * 1000, 2);

$logger->info('[PROCESSOR] Batch processing completed', [
    'total_events' => count($consolidated),
    'successful' => $result['summary']['successful'] ?? 0,
    'failed' => $result['summary']['failed'] ?? 0,
    'execution_time_ms' => $executionTime,
    'batch_success' => $result['success']
]);

emergencyLog('[PROCESSOR] Processing completed successfully', [
    'total_events' => count($consolidated),
    'successful' => $result['summary']['successful'] ?? 0,
    'failed' => $result['summary']['failed'] ?? 0,
    'execution_time_ms' => $executionTime
]);

// ============================================================================
// CLEANUP
// ============================================================================

@unlink($tempFile);
emergencyLog('[PROCESSOR] Temp file cleaned up');

exit(0);
