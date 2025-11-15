<?php
/**
 * Log ObjectModel for Odoo Sales Sync module
 */

class OdooSalesLog extends ObjectModel
{
    public $id_log;
    public $level;
    public $category;
    public $message;
    public $context;
    public $correlation_id;
    public $event_id;
    public $file;
    public $line;
    public $function;
    public $execution_time;
    public $memory_peak;
    public $date_add;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = [
        'table' => 'odoo_sales_logs',
        'primary' => 'id_log',
        'fields' => [
            'level' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 20],
            'category' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50],
            'message' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true],
            'context' => ['type' => self::TYPE_STRING, 'validate' => 'isString'],
            'correlation_id' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 36],
            'event_id' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'file' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255],
            'line' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'function' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255],
            'execution_time' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'memory_peak' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Get context data as array
     *
     * @return array
     */
    public function getContextData()
    {
        if (empty($this->context)) {
            return [];
        }

        $data = json_decode($this->context, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get formatted level with color
     *
     * @return string
     */
    public function getFormattedLevel()
    {
        switch ($this->level) {
            case 'debug':
                return '<span class="label label-default">' . $this->level . '</span>';
            case 'info':
                return '<span class="label label-info">' . $this->level . '</span>';
            case 'warning':
                return '<span class="label label-warning">' . $this->level . '</span>';
            case 'error':
                return '<span class="label label-danger">' . $this->level . '</span>';
            case 'critical':
                return '<span class="label label-danger"><strong>' . $this->level . '</strong></span>';
            default:
                return $this->level;
        }
    }
}
