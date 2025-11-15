<?php
/**
 * Cart Rule Usage Tracker
 *
 * Tracks coupon apply/remove events by diffing cart voucher snapshots.
 *
 * CRITICAL WORKAROUND: PrestaShop does NOT fire hooks when Cart::addCartRule()
 * or Cart::removeCartRule() are called. We must listen to actionCartSave and
 * detect changes by comparing current vs previous voucher lists.
 *
 * Source verification:
 * - PrestaShop-8.2.x/classes/Cart.php:1310-1364 (addCartRule - no hooks)
 * - PrestaShop-8.2.x/classes/Cart.php:1830-1857 (removeCartRule - no hooks)
 * - PrestaShop-8.2.x/classes/Cart.php:252-305 (add/update - DO fire actionCartSave)
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesCartRuleUsageTracker
{
    /** @var OdooSalesEventDetector */
    private $detector;

    /** @var OdooSalesCartRuleStateRepository */
    private $repository;

    /** @var OdooSalesLogger */
    private $logger;

    /**
     * Constructor
     *
     * @param OdooSalesEventDetector $detector Event detector
     * @param OdooSalesCartRuleStateRepository $repository Snapshot repository
     * @param OdooSalesLogger $logger Logger instance
     */
    public function __construct($detector, $repository, $logger)
    {
        $this->detector = $detector;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Handle actionCartSave to detect voucher apply/remove
     *
     * @param array $params Hook parameters containing 'cart'
     * @return bool Success
     */
    public function handleCartSave($params)
    {
        try {
            if (!isset($params['cart']) || !Validate::isLoadedObject($params['cart'])) {
                $this->logger->debug('CartRuleUsageTracker: Invalid cart in actionCartSave (expected for some calls)', [
                    'params_keys' => array_keys($params)
                ]);
                return false;
            }

            $cart = $params['cart'];

            // Get current vouchers in cart
            $currentRuleIds = $this->getCurrentCartRules($cart->id);

            // Get previous snapshot
            $previousRuleIds = $this->repository->getSnapshot($cart->id);

            // Detect added vouchers
            $addedRules = array_diff($currentRuleIds, $previousRuleIds);
            foreach ($addedRules as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'applied');
            }

            // Detect removed vouchers
            $removedRules = array_diff($previousRuleIds, $currentRuleIds);
            foreach ($removedRules as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'removed');
            }

            // Save new snapshot
            $this->repository->saveSnapshot($cart->id, $currentRuleIds);

            if (count($addedRules) > 0 || count($removedRules) > 0) {
                $this->logger->info('CartRuleUsageTracker: Detected voucher changes', [
                    'cart_id' => $cart->id,
                    'added' => $addedRules,
                    'removed' => $removedRules
                ]);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('CartRuleUsageTracker::handleCartSave failed', [
                'cart_id' => isset($cart) ? $cart->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get current cart rule IDs in a cart
     *
     * @param int $idCart Cart ID
     * @return array Array of cart rule IDs
     */
    private function getCurrentCartRules($idCart)
    {
        $sql = 'SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'cart_cart_rule
                WHERE id_cart = ' . (int)$idCart;

        $rows = Db::getInstance()->executeS($sql);

        if (!$rows) {
            return [];
        }

        return array_map(function($row) {
            return (int)$row['id_cart_rule'];
        }, $rows);
    }

    /**
     * Generate voucher usage event
     *
     * @param int $ruleId Cart rule ID
     * @param int $cartId Cart ID
     * @param string $action Action type (applied, removed, consumed)
     * @return bool Success
     */
    private function detectCartRuleChange($ruleId, $cartId, $action)
    {
        $cartRule = new CartRule($ruleId);

        if (!Validate::isLoadedObject($cartRule)) {
            $this->logger->warning('Failed to load CartRule for usage tracking', [
                'id_cart_rule' => $ruleId,
                'cart_id' => $cartId,
                'action' => $action
            ]);
            return false;
        }

        // Create synthetic event parameters
        $syntheticParams = [
            'cart_rule' => $cartRule,
            'cart_id' => $cartId,
            'usage_action' => $action
        ];

        // Delegate to detector
        return $this->detector->detectCouponUsage($syntheticParams);
    }

    /**
     * Handle actionValidateOrder to reconcile final voucher usage
     *
     * @param array $params Hook parameters containing 'order'
     * @return bool Success
     */
    public function handleOrderValidation($params)
    {
        try {
            if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
                $this->logger->warning('CartRuleUsageTracker: Invalid order in actionValidateOrder', [
                    'params_keys' => array_keys($params)
                ]);
                return false;
            }

            $order = $params['order'];
            $cart = new Cart($order->id_cart);

            if (!Validate::isLoadedObject($cart)) {
                $this->logger->error('Failed to load cart for order', [
                    'order_id' => $order->id,
                    'cart_id' => $order->id_cart
                ]);
                return false;
            }

            // Get final vouchers used in order
            $finalRuleIds = $this->getOrderCartRules($order->id);

            // Generate 'consumed' events for each voucher
            foreach ($finalRuleIds as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'consumed');
            }

            // Clean up snapshot (cart is now converted to order)
            $this->repository->deleteSnapshot($cart->id);

            $this->logger->info('CartRuleUsageTracker: Reconciled order vouchers', [
                'order_id' => $order->id,
                'cart_id' => $cart->id,
                'vouchers_consumed' => $finalRuleIds
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('CartRuleUsageTracker::handleOrderValidation failed', [
                'order_id' => isset($order) ? $order->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get cart rules used in an order
     *
     * @param int $idOrder Order ID
     * @return array Array of cart rule IDs
     */
    private function getOrderCartRules($idOrder)
    {
        $sql = 'SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'order_cart_rule
                WHERE id_order = ' . (int)$idOrder;

        $rows = Db::getInstance()->executeS($sql);

        if (!$rows) {
            return [];
        }

        return array_map(function($row) {
            return (int)$row['id_cart_rule'];
        }, $rows);
    }
}
