<?php
/**
 * Hook Tracker
 *
 * Deduplication tracker for hook events.
 * Prevents duplicate events from being created when multiple hooks
 * fire for the same entity change.
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesHookTracker
{
    /** @var OdooSalesLogger */
    private $logger;

    /** @var int Deduplication window in seconds */
    private $dedupWindow;

    /**
     * Constructor
     *
     * @param OdooSalesLogger $logger Logger instance
     * @param int $dedupWindow Deduplication window in seconds (default: 5)
     */
    public function __construct($logger, $dedupWindow = 5)
    {
        $this->logger = $logger;
        $this->dedupWindow = $dedupWindow;
    }

    /**
     * Check if event is duplicate
     *
     * @param string $hookName Hook name
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param string $actionType Action type
     * @return bool True if duplicate
     */
    public function isDuplicate($hookName, $entityType, $entityId, $actionType)
    {
        try {
            // Generate event hash
            $eventHash = $this->generateEventHash($hookName, $entityType, $entityId, $actionType);

            // Check if hash exists in recent window
            $cutoffTime = date('Y-m-d H:i:s', time() - $this->dedupWindow);

            $sql = 'SELECT id_dedup, count, first_seen, last_seen
                    FROM ' . _DB_PREFIX_ . 'odoo_sales_dedup
                    WHERE event_hash = \'' . pSQL($eventHash) . '\'
                    AND last_seen > \'' . pSQL($cutoffTime) . '\'';

            $existing = Db::getInstance()->getRow($sql);

            if ($existing) {
                // Duplicate found - update counter
                $this->updateDedupRecord($existing['id_dedup']);

                $this->logger->debug('Duplicate event detected', [
                    'hook' => $hookName,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'action' => $actionType,
                    'count' => $existing['count'] + 1
                ]);

                return true;
            }

            // Not a duplicate - create new dedup record
            $this->createDedupRecord($hookName, $entityType, $entityId, $actionType, $eventHash);

            return false;
        } catch (Exception $e) {
            $this->logger->error('HookTracker::isDuplicate failed', [
                'hook' => $hookName,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage()
            ]);

            // On error, allow event through (fail open)
            return false;
        }
    }

    /**
     * Generate event hash for deduplication
     *
     * @param string $hookName Hook name
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param string $actionType Action type
     * @return string Event hash
     */
    private function generateEventHash($hookName, $entityType, $entityId, $actionType)
    {
        $hashString = $hookName . '_' . $entityType . '_' . $entityId . '_' . $actionType;
        return hash('sha256', $hashString);
    }

    /**
     * Create deduplication record
     *
     * @param string $hookName Hook name
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param string $actionType Action type
     * @param string $eventHash Event hash
     */
    private function createDedupRecord($hookName, $entityType, $entityId, $actionType, $eventHash)
    {
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'odoo_sales_dedup
                (hook_name, entity_type, entity_id, action_type, event_hash, count, first_seen, last_seen)
                VALUES (
                    \'' . pSQL($hookName) . '\',
                    \'' . pSQL($entityType) . '\',
                    ' . (int)$entityId . ',
                    \'' . pSQL($actionType) . '\',
                    \'' . pSQL($eventHash) . '\',
                    1,
                    \'' . pSQL($now) . '\',
                    \'' . pSQL($now) . '\'
                )';

        Db::getInstance()->execute($sql);
    }

    /**
     * Update deduplication record counter
     *
     * @param int $dedupId Dedup record ID
     */
    private function updateDedupRecord($dedupId)
    {
        $now = date('Y-m-d H:i:s');

        $sql = 'UPDATE ' . _DB_PREFIX_ . 'odoo_sales_dedup
                SET count = count + 1,
                    last_seen = \'' . pSQL($now) . '\'
                WHERE id_dedup = ' . (int)$dedupId;

        Db::getInstance()->execute($sql);
    }

    /**
     * Clean up old deduplication records
     *
     * @param int $hoursOld Records older than this many hours
     * @return bool Success
     */
    public function cleanupOldRecords($hoursOld = 24)
    {
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$hoursOld} hours"));

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_dedup
                WHERE last_seen < \'' . pSQL($cutoffTime) . '\'';

        $result = Db::getInstance()->execute($sql);

        if ($result) {
            $affectedRows = Db::getInstance()->Affected_Rows();

            $this->logger->info('Cleaned up old dedup records', [
                'hours_old' => $hoursOld,
                'records_deleted' => $affectedRows
            ]);
        }

        return $result;
    }

    /**
     * Get deduplication statistics
     *
     * @return array Statistics
     */
    public function getStatistics()
    {
        $totalSql = 'SELECT COUNT(*) as total FROM ' . _DB_PREFIX_ . 'odoo_sales_dedup';
        $duplicatesSql = 'SELECT COUNT(*) as duplicates FROM ' . _DB_PREFIX_ . 'odoo_sales_dedup WHERE count > 1';

        $total = (int)Db::getInstance()->getValue($totalSql);
        $duplicates = (int)Db::getInstance()->getValue($duplicatesSql);

        return [
            'total_tracked' => $total,
            'duplicates_prevented' => $duplicates
        ];
    }
}
