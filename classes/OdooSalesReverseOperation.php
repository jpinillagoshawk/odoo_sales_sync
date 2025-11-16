<?php
/**
 * Reverse Operation Model
 *
 * PrestaShop ObjectModel for tracking reverse synchronization operations.
 * Stores all reverse sync attempts for debugging, auditing, and analytics.
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesReverseOperation extends ObjectModel
{
    /** @var int */
    public $id_reverse_operation;

    /** @var string Unique operation identifier (UUID) */
    public $operation_id;

    /** @var string Entity type: customer, order, address, coupon */
    public $entity_type;

    /** @var int|null PrestaShop entity ID (NULL if creation failed) */
    public $entity_id;

    /** @var string Action type: created, updated, deleted */
    public $action_type;

    /** @var string|null Original webhook payload from Odoo (JSON) */
    public $source_payload;

    /** @var string|null Processing result details (JSON) */
    public $result_data;

    /** @var string Operation status: processing, success, failed */
    public $status;

    /** @var string|null Error message if failed */
    public $error_message;

    /** @var int|null Processing duration in milliseconds */
    public $processing_time_ms;

    /** @var string Creation timestamp */
    public $date_add;

    /** @var string Last update timestamp */
    public $date_upd;

    /**
     * Model definition
     */
    public static $definition = [
        'table' => 'odoo_sales_reverse_operations',
        'primary' => 'id_reverse_operation',
        'fields' => [
            'operation_id' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'size' => 64
            ],
            'entity_type' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'values' => ['customer', 'order', 'address', 'coupon']
            ],
            'entity_id' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedInt',
                'required' => false
            ],
            'action_type' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'values' => ['created', 'updated', 'deleted']
            ],
            'source_payload' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => false
            ],
            'result_data' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => false
            ],
            'status' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => true,
                'values' => ['processing', 'success', 'failed'],
                'default' => 'processing'
            ],
            'error_message' => [
                'type' => self::TYPE_STRING,
                'validate' => 'isString',
                'required' => false
            ],
            'processing_time_ms' => [
                'type' => self::TYPE_INT,
                'validate' => 'isInt',
                'required' => false
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ],
            'date_upd' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ]
        ]
    ];

    /**
     * Track a new reverse operation
     *
     * Static helper to create and save operation record.
     *
     * @param string $operationId Unique operation ID
     * @param string $entityType Entity type
     * @param int|null $entityId Entity ID (may be null initially)
     * @param string $actionType Action type
     * @param array $payload Original payload from Odoo
     * @return OdooSalesReverseOperation Created operation object
     * @throws PrestaShopException
     */
    public static function trackOperation($operationId, $entityType, $entityId, $actionType, $payload)
    {
        $operation = new self();
        $operation->operation_id = $operationId;
        $operation->entity_type = $entityType;
        $operation->entity_id = $entityId;
        $operation->action_type = $actionType;
        $operation->source_payload = json_encode($payload);
        $operation->status = 'processing';
        $operation->date_add = date('Y-m-d H:i:s');
        $operation->date_upd = date('Y-m-d H:i:s');

        if (!$operation->add()) {
            throw new PrestaShopException('Failed to track reverse operation');
        }

        return $operation;
    }

    /**
     * Update operation status
     *
     * @param string $status Status: processing, success, failed
     * @param array|null $resultData Result data to store
     * @param string|null $errorMessage Error message if failed
     * @param int|null $processingTimeMs Processing time in ms
     * @return bool Success
     */
    public function updateStatus($status, $resultData = null, $errorMessage = null, $processingTimeMs = null)
    {
        $this->status = $status;
        $this->date_upd = date('Y-m-d H:i:s');

        if ($resultData !== null) {
            $this->result_data = json_encode($resultData);
        }

        if ($errorMessage !== null) {
            $this->error_message = $errorMessage;
        }

        if ($processingTimeMs !== null) {
            $this->processing_time_ms = $processingTimeMs;
        }

        return $this->update();
    }

    /**
     * Find operation by operation ID
     *
     * @param string $operationId Operation ID to find
     * @return OdooSalesReverseOperation|null Operation or null if not found
     */
    public static function findByOperationId($operationId)
    {
        $sql = 'SELECT `id_reverse_operation`
                FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `operation_id` = \'' . pSQL($operationId) . '\'';

        $id = Db::getInstance()->getValue($sql);

        if (!$id) {
            return null;
        }

        return new self($id);
    }

    /**
     * Get recent operations for an entity
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param int $limit Number of records to return
     * @return array Array of operation records
     */
    public static function getRecentByEntity($entityType, $entityId, $limit = 10)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `entity_type` = \'' . pSQL($entityType) . '\'
                AND `entity_id` = ' . (int)$entityId . '
                ORDER BY `date_add` DESC
                LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get failed operations count
     *
     * @param int $hours Number of hours to look back (default: 24)
     * @return int Count of failed operations
     */
    public static function getFailedCount($hours = 24)
    {
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `status` = \'failed\'
                AND `date_add` >= DATE_SUB(NOW(), INTERVAL ' . (int)$hours . ' HOUR)';

        return (int)Db::getInstance()->getValue($sql);
    }

    /**
     * Get statistics summary
     *
     * @param int $hours Number of hours to analyze (default: 24)
     * @return array Statistics array
     */
    public static function getStatistics($hours = 24)
    {
        $sql = 'SELECT
                    `status`,
                    `entity_type`,
                    COUNT(*) as count,
                    AVG(`processing_time_ms`) as avg_time_ms
                FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `date_add` >= DATE_SUB(NOW(), INTERVAL ' . (int)$hours . ' HOUR)
                GROUP BY `status`, `entity_type`';

        $results = Db::getInstance()->executeS($sql);

        $stats = [
            'total' => 0,
            'by_status' => [],
            'by_entity_type' => [],
            'avg_processing_time_ms' => 0
        ];

        foreach ($results as $row) {
            $stats['total'] += $row['count'];

            if (!isset($stats['by_status'][$row['status']])) {
                $stats['by_status'][$row['status']] = 0;
            }
            $stats['by_status'][$row['status']] += $row['count'];

            if (!isset($stats['by_entity_type'][$row['entity_type']])) {
                $stats['by_entity_type'][$row['entity_type']] = 0;
            }
            $stats['by_entity_type'][$row['entity_type']] += $row['count'];

            if ($row['avg_time_ms']) {
                $stats['avg_processing_time_ms'] += $row['avg_time_ms'];
            }
        }

        if (count($results) > 0) {
            $stats['avg_processing_time_ms'] = (int)($stats['avg_processing_time_ms'] / count($results));
        }

        return $stats;
    }

    /**
     * Clean old operation records
     *
     * Remove successful operations older than specified days.
     * Failed operations are kept longer for troubleshooting.
     *
     * @param int $successfulDays Keep successful for N days (default: 30)
     * @param int $failedDays Keep failed for N days (default: 90)
     * @return int Number of deleted records
     */
    public static function cleanup($successfulDays = 30, $failedDays = 90)
    {
        $deleted = 0;

        // Delete old successful operations
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `status` = \'success\'
                AND `date_add` < DATE_SUB(NOW(), INTERVAL ' . (int)$successfulDays . ' DAY)';

        Db::getInstance()->execute($sql);
        $deleted += Db::getInstance()->Affected_Rows();

        // Delete old failed operations (keep longer for troubleshooting)
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'odoo_sales_reverse_operations`
                WHERE `status` = \'failed\'
                AND `date_add` < DATE_SUB(NOW(), INTERVAL ' . (int)$failedDays . ' DAY)';

        Db::getInstance()->execute($sql);
        $deleted += Db::getInstance()->Affected_Rows();

        return $deleted;
    }
}
