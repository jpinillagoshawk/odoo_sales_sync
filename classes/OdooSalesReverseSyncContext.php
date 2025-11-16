<?php
/**
 * Reverse Sync Context - Loop Prevention Mechanism
 *
 * Provides a global flag system to mark operations as "reverse sync"
 * preventing infinite webhook loops when Odoo updates PrestaShop.
 *
 * CRITICAL FOR LOOP PREVENTION:
 * When this flag is set, EventDetector will NOT create outgoing webhooks.
 *
 * Flow:
 * 1. Reverse webhook received from Odoo
 * 2. markAsReverseSync() called before processing
 * 3. Entity updated in PrestaShop (triggers hooks)
 * 4. EventDetector checks isReverseSync()
 * 5. If TRUE: Skip webhook generation (no loop!)
 * 6. clear() called after processing completes
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesReverseSyncContext
{
    /**
     * @var bool Flag indicating if current operation is a reverse sync
     */
    private static $isReverseSyncOperation = false;

    /**
     * @var string|null Current operation ID for tracking
     */
    private static $operationId = null;

    /**
     * @var string|null Entity type being processed
     */
    private static $entityType = null;

    /**
     * @var int|null Entity ID being processed
     */
    private static $entityId = null;

    /**
     * @var float|null Start time for performance tracking
     */
    private static $startTime = null;

    /**
     * Mark current operation as reverse sync (from Odoo)
     *
     * This MUST be called before any PrestaShop entity modifications
     * to prevent webhook loops.
     *
     * @param string $operationId Unique operation identifier (UUID)
     * @param string $entityType Entity type (customer, order, address, coupon)
     * @param int|null $entityId Entity ID (if known)
     * @return void
     */
    public static function markAsReverseSync($operationId, $entityType = null, $entityId = null)
    {
        self::$isReverseSyncOperation = true;
        self::$operationId = $operationId;
        self::$entityType = $entityType;
        self::$entityId = $entityId;
        self::$startTime = microtime(true);
    }

    /**
     * Check if current operation is a reverse sync
     *
     * Called by EventDetector to determine if webhook should be skipped.
     *
     * @return bool TRUE if reverse sync operation, FALSE otherwise
     */
    public static function isReverseSync()
    {
        return self::$isReverseSyncOperation;
    }

    /**
     * Get current operation ID
     *
     * @return string|null Operation ID or NULL if not set
     */
    public static function getOperationId()
    {
        return self::$operationId;
    }

    /**
     * Get current entity type
     *
     * @return string|null Entity type or NULL if not set
     */
    public static function getEntityType()
    {
        return self::$entityType;
    }

    /**
     * Get current entity ID
     *
     * @return int|null Entity ID or NULL if not set
     */
    public static function getEntityId()
    {
        return self::$entityId;
    }

    /**
     * Get processing time in milliseconds
     *
     * @return int|null Processing time in ms or NULL if not started
     */
    public static function getProcessingTimeMs()
    {
        if (self::$startTime === null) {
            return null;
        }

        return (int)((microtime(true) - self::$startTime) * 1000);
    }

    /**
     * Clear reverse sync context
     *
     * MUST be called after operation completes (success or failure)
     * to reset state for next operation.
     *
     * Best practice: Use in finally block to ensure cleanup.
     *
     * @return void
     */
    public static function clear()
    {
        self::$isReverseSyncOperation = false;
        self::$operationId = null;
        self::$entityType = null;
        self::$entityId = null;
        self::$startTime = null;
    }

    /**
     * Get context summary for logging
     *
     * @return array Context data for logging
     */
    public static function getContextSummary()
    {
        return [
            'is_reverse_sync' => self::$isReverseSyncOperation,
            'operation_id' => self::$operationId,
            'entity_type' => self::$entityType,
            'entity_id' => self::$entityId,
            'processing_time_ms' => self::getProcessingTimeMs()
        ];
    }

    /**
     * Generate a unique operation ID (UUID v4)
     *
     * @return string UUID v4
     */
    public static function generateOperationId()
    {
        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
