<?php
/**
 * Enhanced Event Logger
 *
 * Advanced logging utility for the Odoo Sales Sync module with:
 * - 5 log levels (debug, info, warning, error, critical)
 * - 5 log categories (detection, api, sync, system, performance)
 * - Correlation ID tracking for event chains
 * - Performance profiling (execution time, memory usage)
 * - Context enrichment with environment data
 * - File logging for critical errors
 * - Advanced filtering and export
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesLogger
{
    // Log levels
    const DEBUG = 'debug';
    const INFO = 'info';
    const WARNING = 'warning';
    const ERROR = 'error';
    const CRITICAL = 'critical';

    // Log categories
    const CAT_DETECTION = 'detection';
    const CAT_API = 'api';
    const CAT_SYNC = 'sync';
    const CAT_SYSTEM = 'system';
    const CAT_PERFORMANCE = 'performance';

    /** @var string Module name */
    private $moduleName;

    /** @var bool Enable debug logging */
    private $debugMode;

    /** @var string Correlation ID for this request */
    private $correlationId;

    /** @var float Request start time */
    private $startTime;

    /** @var int Memory usage at start */
    private $memoryStart;

    /**
     * Constructor
     *
     * @param string $moduleName Module name
     * @param bool $debugMode Enable debug logging
     */
    public function __construct($moduleName = 'odoo_sales_sync', $debugMode = false)
    {
        $this->moduleName = $moduleName;
        $this->debugMode = $debugMode;
        $this->correlationId = $this->generateCorrelationId();
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);
    }

    /**
     * Generate correlation ID
     *
     * @return string UUID v4
     */
    private function generateCorrelationId()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Log debug message (only when debug mode enabled)
     *
     * @param string $message Log message
     * @param array $context Context data
     */
    public function debug($message, $context = array())
    {
        if ($this->debugMode) {
            $this->log(self::DEBUG, $message, $context);
        }
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param array $context Context data
     */
    public function info($message, $context = array())
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array $context Context data
     */
    public function warning($message, $context = array())
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param array $context Context data
     */
    public function error($message, $context = array())
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Log critical message (also logs to file)
     *
     * @param string $message Log message
     * @param array $context Context data
     */
    public function critical($message, $context = array())
    {
        $this->log(self::CRITICAL, $message, $context);
        $this->logToFile($message, self::CRITICAL, $context);
    }

    /**
     * Log message with specific category
     *
     * @param string $category Log category
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Context data
     */
    public function logCategory($category, $message, $level = self::INFO, $context = array())
    {
        $context['category'] = $category;
        $this->log($level, $message, $context);
    }

    /**
     * Log performance measurement
     *
     * @param string $operation Operation name
     * @param float $duration Duration in seconds
     * @param array $context Additional context
     */
    public function logPerformance($operation, $duration, $context = array())
    {
        $context['operation'] = $operation;
        $context['duration'] = round($duration, 4);
        $this->logCategory(self::CAT_PERFORMANCE, "Performance: {$operation}", self::INFO, $context);
    }

    /**
     * Write log entry to database
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Context data
     */
    private function log($level, $message, $context = array())
    {
        try {
            // Enrich context with environment data
            $enrichedContext = $this->enrichContext($context);

            // Extract category
            $category = isset($enrichedContext['category']) ? $enrichedContext['category'] : self::CAT_SYSTEM;
            unset($enrichedContext['category']);

            // Extract event_id if present
            $eventId = isset($enrichedContext['event_id']) ? (int)$enrichedContext['event_id'] : null;

            // Get caller information
            $caller = $this->getCallerInfo();

            // Calculate execution metrics
            $executionTime = round(microtime(true) - $this->startTime, 4);
            $memoryPeak = memory_get_peak_usage(true);

            // Prepare SQL
            $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'odoo_sales_logs
                    (level, category, message, context, correlation_id, event_id,
                     file, line, function, execution_time, memory_peak, date_add)
                    VALUES (
                        \'' . pSQL($level) . '\',
                        \'' . pSQL($category) . '\',
                        \'' . pSQL($message) . '\',
                        \'' . pSQL(json_encode($enrichedContext)) . '\',
                        \'' . pSQL($this->correlationId) . '\',
                        ' . ($eventId ? (int)$eventId : 'NULL') . ',
                        \'' . pSQL($caller['file']) . '\',
                        ' . (int)$caller['line'] . ',
                        \'' . pSQL($caller['function']) . '\',
                        ' . (float)$executionTime . ',
                        ' . (int)$memoryPeak . ',
                        \'' . pSQL(date('Y-m-d H:i:s')) . '\'
                    )';

            Db::getInstance()->execute($sql);

            // Also log to PrestaShop logger in dev mode
            if (_PS_MODE_DEV_ && defined('_PS_MODE_DEV_')) {
                PrestaShopLogger::addLog('[' . $this->moduleName . '] ' . $message, $this->getLevelCode($level));
            }

            // Log critical/error to file
            if ($level === self::ERROR || $level === self::CRITICAL) {
                $this->logToFile($message, $level, $enrichedContext);
            }

        } catch (Exception $e) {
            // Emergency logging if database fails
            $this->emergencyLog($message, $level, $context, $e->getMessage());
        }
    }

    /**
     * Enrich context with environment data
     *
     * @param array $context Original context
     * @return array Enriched context
     */
    private function enrichContext($context)
    {
        $enriched = array(
            'correlation_id' => $this->correlationId,
            'timestamp' => date('c'),
            'execution_time' => round(microtime(true) - $this->startTime, 4),
            'memory_used' => memory_get_usage(true) - $this->memoryStart,
            'memory_peak' => memory_get_peak_usage(true),
            'php_version' => PHP_VERSION,
            'prestashop_version' => _PS_VERSION_,
            'module_version' => '1.0.0'
        );

        // Add context data
        if (class_exists('Context') && Context::getContext()) {
            $ctx = Context::getContext();

            if (isset($ctx->shop) && $ctx->shop) {
                $enriched['shop_id'] = $ctx->shop->id;
            }

            if (isset($ctx->employee) && $ctx->employee) {
                $enriched['employee_id'] = $ctx->employee->id;
            }

            if (isset($ctx->customer) && $ctx->customer) {
                $enriched['customer_id'] = $ctx->customer->id;
            }

            if (isset($ctx->controller)) {
                $enriched['controller'] = get_class($ctx->controller);
            }
        }

        return array_merge($enriched, $context);
    }

    /**
     * Get caller information from backtrace
     *
     * @return array File, line, and function
     */
    private function getCallerInfo()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        // Find first caller outside this logger class
        foreach ($trace as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], 'OdooSalesLogger.php') === false) {
                return array(
                    'file' => str_replace(_PS_ROOT_DIR_, '', $frame['file']),
                    'line' => isset($frame['line']) ? $frame['line'] : 0,
                    'function' => isset($frame['function']) ? $frame['function'] : 'unknown'
                );
            }
        }

        return array('file' => 'unknown', 'line' => 0, 'function' => 'unknown');
    }

    /**
     * Log to file (for errors and critical issues)
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Context data
     */
    private function logToFile($message, $level, $context)
    {
        try {
            $logDir = _PS_MODULE_DIR_ . 'odoo_sales_sync/logs';

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logFile = $logDir . '/errors_' . date('Y-m-d') . '.log';
            $timestamp = date('Y-m-d H:i:s');
            $contextStr = json_encode($context, JSON_PRETTY_PRINT);

            $entry = "[{$timestamp}] [{$level}] {$message}\n";
            $entry .= "Context: {$contextStr}\n";
            $entry .= "---\n";

            file_put_contents($logFile, $entry, FILE_APPEND);
        } catch (Exception $e) {
            // Fail silently
        }
    }

    /**
     * Emergency logging when database is unavailable
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Context data
     * @param string $error Database error
     */
    private function emergencyLog($message, $level, $context, $error)
    {
        try {
            $logDir = _PS_MODULE_DIR_ . 'odoo_sales_sync/logs';

            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logFile = $logDir . '/emergency_' . date('Y-m-d') . '.log';
            $timestamp = date('Y-m-d H:i:s');

            $entry = "[{$timestamp}] [EMERGENCY] Database logging failed: {$error}\n";
            $entry .= "Original log: [{$level}] {$message}\n";
            $entry .= "Context: " . json_encode($context) . "\n";
            $entry .= "---\n";

            file_put_contents($logFile, $entry, FILE_APPEND);
        } catch (Exception $e) {
            // Nothing more we can do
            error_log("OdooSalesLogger: Emergency logging failed");
        }
    }

    /**
     * Get PrestaShop log level code
     *
     * @param string $level Log level string
     * @return int PrestaShop log level code
     */
    private function getLevelCode($level)
    {
        switch ($level) {
            case self::DEBUG:
            case self::INFO:
                return 1; // INFO
            case self::WARNING:
                return 2; // WARNING
            case self::ERROR:
            case self::CRITICAL:
                return 3; // ERROR
            default:
                return 1;
        }
    }

    /**
     * Get logs with advanced filtering
     *
     * @param int $page Page number
     * @param int $limit Entries per page
     * @param string $level Filter by level
     * @param string $dateFrom Filter from date
     * @param string $dateTo Filter to date
     * @param string $search Search in message
     * @param string $category Filter by category
     * @param string $correlationId Filter by correlation ID
     * @param string $sortBy Sort column
     * @param string $sortOrder Sort direction
     * @return array Logs and pagination data
     */
    public static function getLogs(
        $page = 1,
        $limit = 100,
        $level = '',
        $dateFrom = '',
        $dateTo = '',
        $search = '',
        $category = '',
        $correlationId = '',
        $sortBy = 'date_add',
        $sortOrder = 'DESC'
    ) {
        $offset = ($page - 1) * $limit;

        // Build WHERE clause
        $where = array();

        if ($level) {
            $where[] = "level = '" . pSQL($level) . "'";
        }

        if ($category) {
            $where[] = "category = '" . pSQL($category) . "'";
        }

        if ($dateFrom) {
            $where[] = "date_add >= '" . pSQL($dateFrom . ' 00:00:00') . "'";
        }

        if ($dateTo) {
            $where[] = "date_add <= '" . pSQL($dateTo . ' 23:59:59') . "'";
        }

        if ($search) {
            $where[] = "(message LIKE '%" . pSQL($search) . "%' OR context LIKE '%" . pSQL($search) . "%')";
        }

        if ($correlationId) {
            $where[] = "correlation_id = '" . pSQL($correlationId) . "'";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $countSql = "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_logs {$whereClause}";
        $total = (int)Db::getInstance()->getValue($countSql);

        // Get logs
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_logs
                {$whereClause}
                ORDER BY " . pSQL($sortBy) . " " . ($sortOrder === 'ASC' ? 'ASC' : 'DESC') . "
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $logs = Db::getInstance()->executeS($sql);

        return array(
            'logs' => $logs ?: array(),
            'page' => $page,
            'pages' => ceil($total / $limit),
            'total' => $total,
            'limit' => $limit
        );
    }

    /**
     * Get recent logs
     *
     * @param int $limit Number of logs to retrieve
     * @param string $level Filter by level (optional)
     * @return array Log entries
     */
    public static function getRecentLogs($limit = 100, $level = null)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'odoo_sales_logs';

        if ($level) {
            $sql .= ' WHERE level = \'' . pSQL($level) . '\'';
        }

        $sql .= ' ORDER BY date_add DESC LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Clean up old logs
     *
     * @param int $daysOld Logs older than this many days
     * @return bool Success
     */
    public static function cleanupOldLogs($daysOld = 30)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_logs
                WHERE date_add < \'' . pSQL($cutoffDate) . '\'';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Get log statistics
     *
     * @return array Statistics by level and category
     */
    public static function getStatistics()
    {
        $sql = 'SELECT level, category, COUNT(*) as count
                FROM ' . _DB_PREFIX_ . 'odoo_sales_logs
                WHERE date_add >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY level, category';

        $results = Db::getInstance()->executeS($sql);

        $stats = array(
            'by_level' => array(
                'debug' => 0,
                'info' => 0,
                'warning' => 0,
                'error' => 0,
                'critical' => 0
            ),
            'by_category' => array(
                'detection' => 0,
                'api' => 0,
                'sync' => 0,
                'system' => 0,
                'performance' => 0
            )
        );

        foreach ($results as $row) {
            $stats['by_level'][$row['level']] = (int)$row['count'];
            $stats['by_category'][$row['category']] = (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Export logs to CSV
     *
     * @param string $level Filter by level
     * @param string $dateFrom Filter from date
     * @param string $dateTo Filter to date
     * @param string $search Search in message
     * @return string CSV content
     */
    public static function exportLogsToCSV($level = '', $dateFrom = '', $dateTo = '', $search = '')
    {
        $result = self::getLogs(1, 10000, $level, $dateFrom, $dateTo, $search);
        $logs = $result['logs'];

        $csv = "ID,Level,Category,Message,Correlation ID,Event ID,File,Line,Function,Execution Time,Memory Peak,Date\n";

        foreach ($logs as $log) {
            $csv .= sprintf(
                "%d,%s,%s,\"%s\",%s,%s,%s,%d,%s,%.4f,%d,%s\n",
                $log['id_log'],
                $log['level'],
                $log['category'],
                str_replace('"', '""', $log['message']),
                $log['correlation_id'],
                $log['event_id'] ?: '',
                $log['file'] ?: '',
                $log['line'] ?: 0,
                $log['function'] ?: '',
                $log['execution_time'] ?: 0,
                $log['memory_peak'] ?: 0,
                $log['date_add']
            );
        }

        return $csv;
    }
}
