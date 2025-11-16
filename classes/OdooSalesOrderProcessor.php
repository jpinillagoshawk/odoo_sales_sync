<?php
/**
 * Order Processor - Reverse Sync
 *
 * Handles order updates from Odoo webhooks.
 *
 * Supported operations:
 * - Update order status
 * - Update tracking number
 * - Update internal notes
 *
 * Note: Full order creation from Odoo is complex and not supported in v2.0.0
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesOrderProcessor
{
    /**
     * Process order webhook from Odoo
     *
     * @param array $payload Full webhook payload
     * @param string $operationId Operation ID for tracking
     * @return array Result array
     */
    public static function process($payload, $operationId)
    {
        $logger = new OdooSalesLogger('reverse_sync');
        $data = $payload['data'] ?? [];
        $actionType = $payload['action_type'] ?? 'updated';

        $logger->info('[ORDER_PROCESSOR] Processing order webhook', [
            'operation_id' => $operationId,
            'action_type' => $actionType,
            'order_id' => $data['id'] ?? null,
            'reference' => $data['reference'] ?? null
        ]);

        // Only updates are supported for now
        if ($actionType === 'created') {
            $logger->warning('[ORDER_PROCESSOR] Order creation not supported', [
                'operation_id' => $operationId
            ]);
            return [
                'success' => false,
                'error' => 'Order creation from Odoo not supported. Use order updates only.'
            ];
        }

        // Track operation
        try {
            $operation = OdooSalesReverseOperation::trackOperation(
                $operationId,
                'order',
                $data['id'] ?? null,
                $actionType,
                $payload
            );
        } catch (Exception $e) {
            $logger->error('[ORDER_PROCESSOR] Failed to track operation', [
                'error' => $e->getMessage()
            ]);
        }

        try {
            $result = self::updateOrder($data, $logger);

            // Send notification to debug server
            self::notifyDebugServer($payload, $result, $operationId);

            // Update operation status
            if (isset($operation)) {
                $operation->updateStatus(
                    $result['success'] ? 'success' : 'failed',
                    $result,
                    $result['error'] ?? null,
                    OdooSalesReverseSyncContext::getProcessingTimeMs()
                );
            }

            return $result;

        } catch (Exception $e) {
            $logger->error('[ORDER_PROCESSOR] Exception during processing', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);

            $result = [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage()
            ];

            if (isset($operation)) {
                $operation->updateStatus('failed', null, $e->getMessage(), OdooSalesReverseSyncContext::getProcessingTimeMs());
            }

            return $result;
        }
    }

    /**
     * Update existing order from Odoo data
     *
     * @param array $data Order data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function updateOrder($data, $logger)
    {
        // Find order by ID or reference
        $orderId = self::findOrderId($data);

        if (!$orderId) {
            $logger->warning('[ORDER_PROCESSOR] Order not found', [
                'id' => $data['id'] ?? null,
                'reference' => $data['reference'] ?? null
            ]);
            return [
                'success' => false,
                'error' => 'Order not found. Provide valid id or reference.'
            ];
        }

        // Load order
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order)) {
            $logger->error('[ORDER_PROCESSOR] Failed to load order', [
                'order_id' => $orderId
            ]);
            return [
                'success' => false,
                'error' => 'Failed to load order with ID: ' . $orderId
            ];
        }

        $updatedFields = [];

        // Update order status if provided
        if (isset($data['current_state']) || isset($data['id_order_state'])) {
            $newState = $data['current_state'] ?? $data['id_order_state'];
            $statusResult = self::updateOrderStatus($order, $newState, $logger);
            if (!$statusResult['success']) {
                return $statusResult;
            }
            $updatedFields[] = 'status';
        }

        // Update tracking number if provided
        if (isset($data['tracking_number']) || isset($data['shipping_number'])) {
            $trackingNumber = $data['tracking_number'] ?? $data['shipping_number'];
            $trackingResult = self::updateTrackingNumber($order, $trackingNumber, $logger);
            if ($trackingResult['success']) {
                $updatedFields[] = 'tracking_number';
            }
        }

        // Update internal note if provided
        if (isset($data['note'])) {
            $noteResult = self::updateInternalNote($order, $data['note'], $logger);
            if ($noteResult['success']) {
                $updatedFields[] = 'note';
            }
        }

        $logger->info('[ORDER_PROCESSOR] Order updated successfully', [
            'order_id' => $order->id,
            'reference' => $order->reference,
            'updated_fields' => $updatedFields
        ]);

        return [
            'success' => true,
            'entity_id' => $order->id,
            'message' => 'Order updated successfully',
            'reference' => $order->reference,
            'updated_fields' => $updatedFields
        ];
    }

    /**
     * Update order status
     *
     * @param Order $order Order object
     * @param int $newStateId New state ID
     * @param OdooSalesLogger $logger Logger
     * @return array Result
     */
    private static function updateOrderStatus($order, $newStateId, $logger)
    {
        $newStateId = (int)$newStateId;

        // Validate state exists
        $orderState = new OrderState($newStateId);
        if (!Validate::isLoadedObject($orderState)) {
            $logger->error('[ORDER_PROCESSOR] Invalid order state', [
                'state_id' => $newStateId
            ]);
            return [
                'success' => false,
                'error' => 'Invalid order state ID: ' . $newStateId
            ];
        }

        // Don't update if already in this state
        if ($order->current_state == $newStateId) {
            $logger->debug('[ORDER_PROCESSOR] Order already in requested state', [
                'order_id' => $order->id,
                'state_id' => $newStateId
            ]);
            return ['success' => true];
        }

        // Create order history record
        $history = new OrderHistory();
        $history->id_order = $order->id;
        $history->id_order_state = $newStateId;
        $history->id_employee = 0; // System update
        $history->date_add = date('Y-m-d H:i:s');

        // Add history (this triggers state change actions)
        if (!$history->add()) {
            $logger->error('[ORDER_PROCESSOR] Failed to add order history', [
                'order_id' => $order->id,
                'state_id' => $newStateId
            ]);
            return [
                'success' => false,
                'error' => 'Failed to update order status'
            ];
        }

        $logger->info('[ORDER_PROCESSOR] Order status updated', [
            'order_id' => $order->id,
            'old_state' => $order->current_state,
            'new_state' => $newStateId
        ]);

        return ['success' => true];
    }

    /**
     * Update tracking number
     *
     * @param Order $order Order object
     * @param string $trackingNumber Tracking number
     * @param OdooSalesLogger $logger Logger
     * @return array Result
     */
    private static function updateTrackingNumber($order, $trackingNumber, $logger)
    {
        $trackingNumber = pSQL($trackingNumber);

        // Update order carrier tracking
        $orderCarrier = new OrderCarrier($order->getIdOrderCarrier());

        if (Validate::isLoadedObject($orderCarrier)) {
            $orderCarrier->tracking_number = $trackingNumber;
            if ($orderCarrier->update()) {
                $logger->info('[ORDER_PROCESSOR] Tracking number updated', [
                    'order_id' => $order->id,
                    'tracking_number' => $trackingNumber
                ]);
                return ['success' => true];
            }
        }

        $logger->warning('[ORDER_PROCESSOR] Could not update tracking number', [
            'order_id' => $order->id
        ]);

        return ['success' => false];
    }

    /**
     * Update internal note
     *
     * @param Order $order Order object
     * @param string $note Internal note
     * @param OdooSalesLogger $logger Logger
     * @return array Result
     */
    private static function updateInternalNote($order, $note, $logger)
    {
        $order->note = pSQL($note);

        if ($order->update()) {
            $logger->info('[ORDER_PROCESSOR] Internal note updated', [
                'order_id' => $order->id
            ]);
            return ['success' => true];
        }

        $logger->warning('[ORDER_PROCESSOR] Failed to update internal note', [
            'order_id' => $order->id
        ]);

        return ['success' => false];
    }

    /**
     * Find order ID by ID or reference
     *
     * @param array $data Order data
     * @return int|null Order ID or null
     */
    private static function findOrderId($data)
    {
        // Try ID first
        if (isset($data['id']) && $data['id']) {
            return (int)$data['id'];
        }

        // Try reference
        if (isset($data['reference']) && $data['reference']) {
            $sql = 'SELECT `id_order`
                    FROM `' . _DB_PREFIX_ . 'orders`
                    WHERE `reference` = \'' . pSQL($data['reference']) . '\'';

            return (int)Db::getInstance()->getValue($sql);
        }

        return null;
    }

    /**
     * Send notification to debug webhook server
     *
     * @param array $payload Original payload
     * @param array $result Processing result
     * @param string $operationId Operation ID
     * @return void
     */
    private static function notifyDebugServer($payload, $result, $operationId)
    {
        $debugWebhookUrl = Configuration::get('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');

        if (empty($debugWebhookUrl)) {
            return;
        }

        $notification = [
            'event_id' => $operationId,
            'entity_type' => 'order',
            'entity_id' => $result['entity_id'] ?? null,
            'action_type' => $payload['action_type'] ?? 'updated',
            'hook_name' => 'reverseWebhookReceived',
            'timestamp' => date('c'),
            'reverse_sync' => true,
            'source' => 'odoo',
            'destination' => 'prestashop',
            'result' => $result,
            'change_summary' => self::buildChangeSummary($payload, $result)
        ];

        $ch = curl_init($debugWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

        @curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Build change summary
     *
     * @param array $payload Original payload
     * @param array $result Processing result
     * @return string Summary
     */
    private static function buildChangeSummary($payload, $result)
    {
        $data = $payload['data'] ?? [];

        if (!$result['success']) {
            return 'Update order failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $reference = $data['reference'] ?? ($result['reference'] ?? 'unknown');
        $fields = $result['updated_fields'] ?? [];
        return 'Updated order ' . $reference . ': ' . implode(', ', $fields);
    }
}
