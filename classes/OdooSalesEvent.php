<?php
/**
 * Sales Event ObjectModel
 *
 * Represents a tracked sales event (customer, order, invoice, coupon change)
 * to be synced with Odoo via webhook.
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesEvent extends ObjectModel
{
    /** @var int Event ID */
    public $id_event;

    /** @var string Entity type (customer, order, invoice, coupon) */
    public $entity_type;

    /** @var int Entity ID */
    public $entity_id;

    /** @var string Entity display name */
    public $entity_name;

    /** @var string Action type (created, updated, deleted, applied, removed, consumed) */
    public $action_type;

    /** @var string Transaction hash for deduplication */
    public $transaction_hash;

    /** @var string Correlation ID for related events */
    public $correlation_id;

    /** @var string PrestaShop hook name that triggered this event */
    public $hook_name;

    /** @var string Timestamp when hook was fired */
    public $hook_timestamp;

    /** @var string JSON before data */
    public $before_data;

    /** @var string JSON after data */
    public $after_data;

    /** @var string Human-readable change summary */
    public $change_summary;

    /** @var string Additional context data (JSON) */
    public $context_data;

    /** @var string Sync status (pending, sending, sent, success, failed, retry) */
    public $sync_status;

    /** @var int Number of sync attempts */
    public $sync_attempts;

    /** @var string Last sync attempt timestamp */
    public $sync_last_attempt;

    /** @var string Next retry timestamp */
    public $sync_next_retry;

    /** @var string Last sync error message */
    public $sync_error;

    /** @var int HTTP response code from webhook */
    public $webhook_response_code;

    /** @var string HTTP response body from webhook */
    public $webhook_response_body;

    /** @var float Response time in seconds */
    public $webhook_response_time;

    /** @var string Date created */
    public $date_add;

    /** @var string Date updated */
    public $date_upd;

    /**
     * ObjectModel definition
     */
    public static $definition = [
        'table' => 'odoo_sales_events',
        'primary' => 'id_event',
        'fields' => [
            'entity_type' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'size' => 50,
                'validate' => 'isGenericName'
            ],
            'entity_id' => [
                'type' => self::TYPE_INT,
                'required' => true,
                'validate' => 'isUnsignedId'
            ],
            'entity_name' => [
                'type' => self::TYPE_STRING,
                'size' => 255,
                'validate' => 'isGenericName'
            ],
            'action_type' => [
                'type' => self::TYPE_STRING,
                'required' => true,
                'size' => 50,
                'validate' => 'isGenericName'
            ],
            'transaction_hash' => [
                'type' => self::TYPE_STRING,
                'size' => 64,
                'required' => true,
                'validate' => 'isString'
            ],
            'correlation_id' => [
                'type' => self::TYPE_STRING,
                'size' => 36,
                'validate' => 'isString'
            ],
            'hook_name' => [
                'type' => self::TYPE_STRING,
                'size' => 100,
                'required' => true,
                'validate' => 'isHookName'
            ],
            'hook_timestamp' => [
                'type' => self::TYPE_DATE,
                'required' => true,
                'validate' => 'isDate'
            ],
            'before_data' => [
                'type' => self::TYPE_STRING
            ],
            'after_data' => [
                'type' => self::TYPE_STRING
            ],
            'change_summary' => [
                'type' => self::TYPE_STRING,
                'size' => 1000
            ],
            'context_data' => [
                'type' => self::TYPE_STRING
            ],
            'sync_status' => [
                'type' => self::TYPE_STRING,
                'size' => 20,
                'default' => 'pending',
                'validate' => 'isGenericName'
            ],
            'sync_attempts' => [
                'type' => self::TYPE_INT,
                'default' => 0,
                'validate' => 'isUnsignedInt'
            ],
            'sync_last_attempt' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ],
            'sync_next_retry' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ],
            'sync_error' => [
                'type' => self::TYPE_STRING
            ],
            'webhook_response_code' => [
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedInt'
            ],
            'webhook_response_body' => [
                'type' => self::TYPE_STRING
            ],
            'webhook_response_time' => [
                'type' => self::TYPE_FLOAT,
                'validate' => 'isFloat'
            ],
            'date_add' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ],
            'date_upd' => [
                'type' => self::TYPE_DATE,
                'validate' => 'isDate'
            ],
        ],
    ];

    /**
     * Constructor
     */
    public function __construct($id = null)
    {
        parent::__construct($id);
    }

    /**
     * Get events pending sync
     *
     * @param int $limit Maximum number of events to retrieve
     * @return array Array of OdooSalesEvent objects
     */
    public static function getPendingEvents($limit = 100)
    {
        $sql = 'SELECT id_event FROM ' . _DB_PREFIX_ . 'odoo_sales_events
                WHERE sync_status IN (\'pending\', \'retry\')
                AND (sync_next_retry IS NULL OR sync_next_retry <= NOW())
                ORDER BY date_add ASC
                LIMIT ' . (int)$limit;

        $ids = Db::getInstance()->executeS($sql);
        $events = [];

        foreach ($ids as $row) {
            $events[] = new OdooSalesEvent($row['id_event']);
        }

        return $events;
    }

    /**
     * Get recent events for monitoring
     *
     * @param int $limit Number of events to retrieve
     * @return array Array of event data
     */
    public static function getRecentEvents($limit = 50)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'odoo_sales_events
                ORDER BY date_add DESC
                LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get failed events count
     *
     * @return int Number of failed events
     */
    public static function getFailedCount()
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'odoo_sales_events
                WHERE sync_status = \'failed\'';

        return (int)Db::getInstance()->getValue($sql);
    }

    /**
     * Delete old events (cleanup)
     *
     * @param int $daysOld Events older than this many days
     * @return bool Success
     */
    public static function deleteOldEvents($daysOld = 90)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_events
                WHERE date_add < \'' . pSQL($cutoffDate) . '\'
                AND sync_status = \'success\'';

        return Db::getInstance()->execute($sql);
    }
}
