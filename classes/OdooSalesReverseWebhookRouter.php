<?php
/**
 * Reverse Webhook Router
 *
 * Routes incoming webhooks from Odoo to appropriate entity processors.
 * Handles loop prevention by setting reverse sync context flag.
 *
 * Flow:
 * 1. Receive webhook payload
 * 2. Generate operation ID
 * 3. Set reverse sync context flag
 * 4. Route to entity processor
 * 5. Clear context flag (in finally block)
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesReverseSyncContext.php';
require_once dirname(__FILE__) . '/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesReverseWebhookRouter
{
    /** @var OdooSalesLogger */
    private static $logger;

    /**
     * Route webhook payload to appropriate entity processor
     *
     * @param array $payload Webhook payload from Odoo
     * @return array Result array with success status and data
     */
    public static function route($payload)
    {
        self::$logger = new OdooSalesLogger('reverse_sync');

        // Validate payload structure
        $validation = self::validatePayload($payload);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        $entityType = $payload['entity_type'];
        $actionType = $payload['action_type'] ?? 'updated';
        $data = $payload['data'] ?? [];

        // Generate operation ID (use provided event_id or generate new)
        $operationId = $payload['event_id'] ?? OdooSalesReverseSyncContext::generateOperationId();

        // Extract entity ID if available
        $entityId = $data['id'] ?? null;

        self::$logger->info('[REVERSE_WEBHOOK_ROUTER] Routing incoming webhook', [
            'operation_id' => $operationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action_type' => $actionType
        ]);

        // Mark as reverse sync operation (CRITICAL for loop prevention)
        OdooSalesReverseSyncContext::markAsReverseSync($operationId, $entityType, $entityId);

        try {
            // Route to appropriate processor
            $result = self::processEntity($entityType, $actionType, $data, $payload, $operationId);

            // Add processing time to result
            $result['processing_time_ms'] = OdooSalesReverseSyncContext::getProcessingTimeMs();

            self::$logger->info('[REVERSE_WEBHOOK_ROUTER] Processing completed', [
                'operation_id' => $operationId,
                'success' => $result['success'],
                'processing_time_ms' => $result['processing_time_ms']
            ]);

            return $result;

        } catch (Exception $e) {
            self::$logger->error('[REVERSE_WEBHOOK_ROUTER] Exception during processing', [
                'operation_id' => $operationId,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage(),
                'processing_time_ms' => OdooSalesReverseSyncContext::getProcessingTimeMs()
            ];

        } finally {
            // ALWAYS clear reverse sync context (even on error)
            OdooSalesReverseSyncContext::clear();
        }
    }

    /**
     * Process entity based on type
     *
     * @param string $entityType Entity type
     * @param string $actionType Action type
     * @param array $data Entity data
     * @param array $payload Full payload
     * @param string $operationId Operation ID
     * @return array Processing result
     */
    private static function processEntity($entityType, $actionType, $data, $payload, $operationId)
    {
        // Load appropriate processor
        switch ($entityType) {
            case 'customer':
            case 'contact':
                require_once dirname(__FILE__) . '/OdooSalesCustomerProcessor.php';
                return OdooSalesCustomerProcessor::process($payload, $operationId);

            case 'order':
                require_once dirname(__FILE__) . '/OdooSalesOrderProcessor.php';
                return OdooSalesOrderProcessor::process($payload, $operationId);

            case 'address':
                require_once dirname(__FILE__) . '/OdooSalesAddressProcessor.php';
                return OdooSalesAddressProcessor::process($payload, $operationId);

            case 'coupon':
            case 'discount':
            case 'cart_rule':
                require_once dirname(__FILE__) . '/OdooSalesCouponProcessor.php';
                return OdooSalesCouponProcessor::process($payload, $operationId);

            default:
                self::$logger->warning('[REVERSE_WEBHOOK_ROUTER] Unknown entity type', [
                    'entity_type' => $entityType,
                    'operation_id' => $operationId
                ]);

                return [
                    'success' => false,
                    'error' => 'Unknown entity type: ' . $entityType,
                    'supported_types' => ['customer', 'order', 'address', 'coupon']
                ];
        }
    }

    /**
     * Validate webhook payload structure
     *
     * @param array $payload Payload to validate
     * @return array Validation result ['valid' => bool, 'error' => string|null]
     */
    private static function validatePayload($payload)
    {
        // Check if payload is array
        if (!is_array($payload)) {
            return [
                'valid' => false,
                'error' => 'Payload must be a JSON object'
            ];
        }

        // Check required fields
        if (!isset($payload['entity_type'])) {
            return [
                'valid' => false,
                'error' => 'Missing required field: entity_type'
            ];
        }

        if (!isset($payload['data'])) {
            return [
                'valid' => false,
                'error' => 'Missing required field: data'
            ];
        }

        // Validate entity_type is known
        $validEntityTypes = ['customer', 'contact', 'order', 'address', 'coupon', 'discount', 'cart_rule'];
        if (!in_array($payload['entity_type'], $validEntityTypes)) {
            return [
                'valid' => false,
                'error' => 'Invalid entity_type. Must be one of: ' . implode(', ', $validEntityTypes)
            ];
        }

        // Validate action_type if present
        if (isset($payload['action_type'])) {
            $validActions = ['created', 'updated', 'deleted'];
            if (!in_array($payload['action_type'], $validActions)) {
                return [
                    'valid' => false,
                    'error' => 'Invalid action_type. Must be one of: ' . implode(', ', $validActions)
                ];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Get router statistics
     *
     * @param int $hours Hours to look back (default: 24)
     * @return array Statistics
     */
    public static function getStatistics($hours = 24)
    {
        return OdooSalesReverseOperation::getStatistics($hours);
    }

    /**
     * Health check for reverse webhook system
     *
     * @return array Health status
     */
    public static function healthCheck()
    {
        $health = [
            'status' => 'ok',
            'reverse_sync_enabled' => (bool)Configuration::get('ODOO_SALES_SYNC_REVERSE_ENABLED'),
            'table_exists' => false,
            'recent_failures' => 0,
            'statistics' => []
        ];

        // Check if table exists
        $sql = "SHOW TABLES LIKE '" . _DB_PREFIX_ . "odoo_sales_reverse_operations'";
        $result = Db::getInstance()->executeS($sql);
        $health['table_exists'] = !empty($result);

        if ($health['table_exists']) {
            // Get failed count in last hour
            $health['recent_failures'] = OdooSalesReverseOperation::getFailedCount(1);

            // Get statistics
            $health['statistics'] = OdooSalesReverseOperation::getStatistics(24);

            // Determine overall health
            if ($health['recent_failures'] > 10) {
                $health['status'] = 'degraded';
            }
        } else {
            $health['status'] = 'error';
            $health['error'] = 'Reverse operations table does not exist';
        }

        return $health;
    }
}
