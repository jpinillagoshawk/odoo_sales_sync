<?php
/**
 * Coupon Processor - Reverse Sync
 *
 * Handles coupon/cart rule creation and updates from Odoo webhooks.
 *
 * Supported operations:
 * - Create new coupon (CartRule)
 * - Update existing coupon
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesCouponProcessor
{
    /**
     * Process coupon webhook from Odoo
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

        $logger->info('[COUPON_PROCESSOR] Processing coupon webhook', [
            'operation_id' => $operationId,
            'action_type' => $actionType,
            'coupon_id' => $data['id'] ?? null,
            'code' => $data['code'] ?? null
        ]);

        // Track operation
        try {
            $operation = OdooSalesReverseOperation::trackOperation(
                $operationId,
                'coupon',
                $data['id'] ?? null,
                $actionType,
                $payload
            );
        } catch (Exception $e) {
            $logger->error('[COUPON_PROCESSOR] Failed to track operation', [
                'error' => $e->getMessage()
            ]);
        }

        try {
            // Route based on action type
            if ($actionType === 'created') {
                $result = self::createCoupon($data, $logger);
            } else {
                $result = self::updateCoupon($data, $logger);
            }

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
            $logger->error('[COUPON_PROCESSOR] Exception during processing', [
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
     * Create new coupon from Odoo data
     *
     * @param array $data Coupon data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function createCoupon($data, $logger)
    {
        // Validate required fields
        $validation = self::validateCouponData($data, true);
        if (!$validation['valid']) {
            $logger->warning('[COUPON_PROCESSOR] Invalid coupon data', [
                'error' => $validation['error']
            ]);
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        // Check if code already exists
        if (self::codeExists($data['code'])) {
            $logger->warning('[COUPON_PROCESSOR] Coupon code already exists', [
                'code' => $data['code']
            ]);
            return [
                'success' => false,
                'error' => 'Coupon code already exists: ' . $data['code']
            ];
        }

        // Create new cart rule
        $cartRule = new CartRule();

        // Required fields
        $cartRule->code = pSQL($data['code']);
        $cartRule->name = Context::getContext()->language->id ?
            [Context::getContext()->language->id => pSQL($data['name'] ?? $data['code'])] :
            [1 => pSQL($data['name'] ?? $data['code'])];

        // Discount type
        if (isset($data['reduction_percent']) && $data['reduction_percent'] > 0) {
            $cartRule->reduction_percent = (float)$data['reduction_percent'];
            $cartRule->reduction_amount = 0;
        } elseif (isset($data['reduction_amount']) && $data['reduction_amount'] > 0) {
            $cartRule->reduction_amount = (float)$data['reduction_amount'];
            $cartRule->reduction_percent = 0;
        }

        // Tax handling
        $cartRule->reduction_tax = isset($data['reduction_tax']) ? (bool)$data['reduction_tax'] : true;

        // Free shipping
        $cartRule->free_shipping = isset($data['free_shipping']) ? (bool)$data['free_shipping'] : false;

        // Quantity and usage limits
        $cartRule->quantity = isset($data['quantity']) ? (int)$data['quantity'] : 1;
        $cartRule->quantity_per_user = isset($data['quantity_per_user']) ? (int)$data['quantity_per_user'] : 1;

        // Date range
        $cartRule->date_from = isset($data['date_from']) ? pSQL($data['date_from']) : date('Y-m-d H:i:s');
        $cartRule->date_to = isset($data['date_to']) ? pSQL($data['date_to']) : date('Y-m-d H:i:s', strtotime('+1 year'));

        // Active status
        $cartRule->active = isset($data['active']) ? (bool)$data['active'] : true;

        // Optional: minimum amount
        if (isset($data['minimum_amount'])) {
            $cartRule->minimum_amount = (float)$data['minimum_amount'];
            $cartRule->minimum_amount_tax = isset($data['minimum_amount_tax']) ? (bool)$data['minimum_amount_tax'] : true;
            $cartRule->minimum_amount_currency = isset($data['minimum_amount_currency']) ? (int)$data['minimum_amount_currency'] : 1;
            $cartRule->minimum_amount_shipping = isset($data['minimum_amount_shipping']) ? (bool)$data['minimum_amount_shipping'] : false;
        }

        // Highlight and partial use
        $cartRule->highlight = isset($data['highlight']) ? (bool)$data['highlight'] : false;
        $cartRule->partial_use = isset($data['partial_use']) ? (bool)$data['partial_use'] : true;

        // Priority
        $cartRule->priority = isset($data['priority']) ? (int)$data['priority'] : 1;

        // Add cart rule
        if (!$cartRule->add()) {
            $logger->error('[COUPON_PROCESSOR] Failed to create coupon', [
                'code' => $data['code'],
                'validation_errors' => $cartRule->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to create coupon: ' . implode(', ', $cartRule->getErrors())
            ];
        }

        $logger->info('[COUPON_PROCESSOR] Coupon created successfully', [
            'coupon_id' => $cartRule->id,
            'code' => $cartRule->code
        ]);

        return [
            'success' => true,
            'entity_id' => $cartRule->id,
            'message' => 'Coupon created successfully',
            'code' => $cartRule->code
        ];
    }

    /**
     * Update existing coupon from Odoo data
     *
     * @param array $data Coupon data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function updateCoupon($data, $logger)
    {
        // Find coupon by ID or code
        $cartRuleId = self::findCouponId($data);

        if (!$cartRuleId) {
            $logger->warning('[COUPON_PROCESSOR] Coupon not found', [
                'id' => $data['id'] ?? null,
                'code' => $data['code'] ?? null
            ]);
            return [
                'success' => false,
                'error' => 'Coupon not found. Provide valid id or code.'
            ];
        }

        // Load cart rule
        $cartRule = new CartRule($cartRuleId);

        if (!Validate::isLoadedObject($cartRule)) {
            $logger->error('[COUPON_PROCESSOR] Failed to load coupon', [
                'coupon_id' => $cartRuleId
            ]);
            return [
                'success' => false,
                'error' => 'Failed to load coupon with ID: ' . $cartRuleId
            ];
        }

        // Update fields (only if provided)
        if (isset($data['name'])) {
            $cartRule->name = Context::getContext()->language->id ?
                [Context::getContext()->language->id => pSQL($data['name'])] :
                [1 => pSQL($data['name'])];
        }
        if (isset($data['description'])) {
            $cartRule->description = pSQL($data['description']);
        }
        if (isset($data['reduction_percent'])) {
            $cartRule->reduction_percent = (float)$data['reduction_percent'];
            $cartRule->reduction_amount = 0;
        }
        if (isset($data['reduction_amount'])) {
            $cartRule->reduction_amount = (float)$data['reduction_amount'];
            $cartRule->reduction_percent = 0;
        }
        if (isset($data['reduction_tax'])) {
            $cartRule->reduction_tax = (bool)$data['reduction_tax'];
        }
        if (isset($data['free_shipping'])) {
            $cartRule->free_shipping = (bool)$data['free_shipping'];
        }
        if (isset($data['quantity'])) {
            $cartRule->quantity = (int)$data['quantity'];
        }
        if (isset($data['quantity_per_user'])) {
            $cartRule->quantity_per_user = (int)$data['quantity_per_user'];
        }
        if (isset($data['date_from'])) {
            $cartRule->date_from = pSQL($data['date_from']);
        }
        if (isset($data['date_to'])) {
            $cartRule->date_to = pSQL($data['date_to']);
        }
        if (isset($data['active'])) {
            $cartRule->active = (bool)$data['active'];
        }
        if (isset($data['priority'])) {
            $cartRule->priority = (int)$data['priority'];
        }

        // Update cart rule
        if (!$cartRule->update()) {
            $logger->error('[COUPON_PROCESSOR] Failed to update coupon', [
                'coupon_id' => $cartRule->id,
                'validation_errors' => $cartRule->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to update coupon: ' . implode(', ', $cartRule->getErrors())
            ];
        }

        $logger->info('[COUPON_PROCESSOR] Coupon updated successfully', [
            'coupon_id' => $cartRule->id,
            'code' => $cartRule->code
        ]);

        return [
            'success' => true,
            'entity_id' => $cartRule->id,
            'message' => 'Coupon updated successfully',
            'code' => $cartRule->code
        ];
    }

    /**
     * Check if coupon code exists
     *
     * @param string $code Coupon code
     * @return bool True if exists
     */
    private static function codeExists($code)
    {
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cart_rule`
                WHERE `code` = \'' . pSQL($code) . '\'';

        return (int)Db::getInstance()->getValue($sql) > 0;
    }

    /**
     * Find coupon ID by ID or code
     *
     * @param array $data Coupon data
     * @return int|null Coupon ID or null
     */
    private static function findCouponId($data)
    {
        // Try ID first
        if (isset($data['id']) && $data['id']) {
            return (int)$data['id'];
        }

        // Try code
        if (isset($data['code']) && $data['code']) {
            $sql = 'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule`
                    WHERE `code` = \'' . pSQL($data['code']) . '\'';

            return (int)Db::getInstance()->getValue($sql);
        }

        return null;
    }

    /**
     * Validate coupon data
     *
     * @param array $data Coupon data
     * @param bool $requireAll Require all fields for creation
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private static function validateCouponData($data, $requireAll = false)
    {
        if ($requireAll) {
            // For creation, code is required
            if (empty($data['code'])) {
                return [
                    'valid' => false,
                    'error' => 'Coupon code is required'
                ];
            }

            // Either reduction_percent or reduction_amount or free_shipping must be set
            $hasDiscount = (isset($data['reduction_percent']) && $data['reduction_percent'] > 0) ||
                          (isset($data['reduction_amount']) && $data['reduction_amount'] > 0) ||
                          (isset($data['free_shipping']) && $data['free_shipping']);

            if (!$hasDiscount) {
                return [
                    'valid' => false,
                    'error' => 'Coupon must have a discount (reduction_percent, reduction_amount, or free_shipping)'
                ];
            }
        }

        return ['valid' => true, 'error' => null];
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
            'entity_type' => 'coupon',
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
        $actionType = $payload['action_type'] ?? 'updated';
        $data = $payload['data'] ?? [];

        if (!$result['success']) {
            return ucfirst($actionType) . ' coupon failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $code = $data['code'] ?? ($result['code'] ?? 'unknown');
        return ucfirst($actionType) . ' coupon: ' . $code;
    }
}
