<?php
/**
 * Cart Rule State Repository
 *
 * Manages cart voucher snapshots for detecting apply/remove events.
 * Required because PrestaShop doesn't fire hooks when Cart::addCartRule()
 * or Cart::removeCartRule() are called.
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesCartRuleStateRepository
{
    /** @var OdooSalesLogger */
    private $logger;

    /**
     * Constructor
     *
     * @param OdooSalesLogger $logger Logger instance
     */
    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get previous cart rule snapshot for a cart
     *
     * @param int $idCart Cart ID
     * @return array Array of cart rule IDs (empty if no snapshot exists)
     */
    public function getSnapshot($idCart)
    {
        $sql = 'SELECT cart_rule_ids FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                WHERE id_cart = ' . (int)$idCart;

        $json = Db::getInstance()->getValue($sql);

        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $this->logger->warning('Invalid cart rule snapshot JSON', [
                'id_cart' => $idCart,
                'json' => $json
            ]);
            return [];
        }

        return $decoded;
    }

    /**
     * Save cart rule snapshot for a cart
     *
     * @param int $idCart Cart ID
     * @param array $ruleIds Array of cart rule IDs
     * @return bool Success
     */
    public function saveSnapshot($idCart, array $ruleIds)
    {
        $json = json_encode(array_values(array_map('intval', $ruleIds)));
        $now = date('Y-m-d H:i:s');

        // Check if snapshot exists
        $exists = $this->getSnapshot($idCart);

        if (empty($exists) && count($ruleIds) === 0) {
            // No snapshot and no rules - nothing to save
            return true;
        }

        if (empty($exists)) {
            // Insert new snapshot
            $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                    (id_cart, cart_rule_ids, last_detected_action, date_add, date_upd)
                    VALUES (
                        ' . (int)$idCart . ',
                        \'' . pSQL($json) . '\',
                        \'snapshot\',
                        \'' . pSQL($now) . '\',
                        \'' . pSQL($now) . '\'
                    )';
        } else {
            // Update existing snapshot
            $sql = 'UPDATE ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                    SET cart_rule_ids = \'' . pSQL($json) . '\',
                        date_upd = \'' . pSQL($now) . '\'
                    WHERE id_cart = ' . (int)$idCart;
        }

        $result = Db::getInstance()->execute($sql);

        if (!$result) {
            $this->logger->error('Failed to save cart rule snapshot', [
                'id_cart' => $idCart,
                'rule_ids' => $ruleIds,
                'error' => Db::getInstance()->getMsgError()
            ]);
        }

        return $result;
    }

    /**
     * Delete cart rule snapshot (cleanup after order)
     *
     * @param int $idCart Cart ID
     * @return bool Success
     */
    public function deleteSnapshot($idCart)
    {
        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                WHERE id_cart = ' . (int)$idCart;

        return Db::getInstance()->execute($sql);
    }

    /**
     * Clean up old cart rule snapshots
     *
     * Removes snapshots for carts that haven't been updated in X days.
     * This prevents the table from growing indefinitely for abandoned carts.
     *
     * @param int $daysOld Age threshold in days
     * @return bool Success
     */
    public function cleanupOldSnapshots($daysOld = 30)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                WHERE date_upd < \'' . pSQL($cutoffDate) . '\'';

        $result = Db::getInstance()->execute($sql);

        if ($result) {
            $affectedRows = Db::getInstance()->Affected_Rows();

            $this->logger->info('Cleaned up old cart rule snapshots', [
                'days_old' => $daysOld,
                'cutoff_date' => $cutoffDate,
                'snapshots_deleted' => $affectedRows
            ]);
        } else {
            $this->logger->error('Failed to cleanup old cart rule snapshots', [
                'error' => Db::getInstance()->getMsgError()
            ]);
        }

        return $result;
    }

    /**
     * Get snapshot statistics (for monitoring)
     *
     * @return array Statistics data
     */
    public function getStatistics()
    {
        $totalSql = 'SELECT COUNT(*) as total FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state';
        $oldSql = 'SELECT COUNT(*) as old FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                   WHERE date_upd < \'' . pSQL(date('Y-m-d H:i:s', strtotime('-30 days'))) . '\'';

        $total = (int)Db::getInstance()->getValue($totalSql);
        $old = (int)Db::getInstance()->getValue($oldSql);

        return [
            'total_snapshots' => $total,
            'old_snapshots' => $old,
            'active_snapshots' => $total - $old
        ];
    }
}
