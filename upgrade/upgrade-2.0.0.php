<?php
/**
 * Upgrade from version 1.x to 2.0.0
 *
 * This file will be executed when upgrading from any 1.x version to 2.0.0
 *
 * Major changes:
 * - Added reverse synchronization support
 * - New table: ps_odoo_sales_reverse_operations
 * - New configuration keys for reverse sync
 * - New classes for reverse sync processing
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade function for module version 2.0.0
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    $success = true;
    $db = Db::getInstance();

    // Step 1: Create new database table for reverse operations
    $sql = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "odoo_sales_reverse_operations` (
        `id_reverse_operation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `operation_id` VARCHAR(64) NOT NULL COMMENT 'Unique operation identifier (UUID)',
        `entity_type` ENUM('customer','order','address','coupon') NOT NULL COMMENT 'Entity type being synchronized',
        `entity_id` INT UNSIGNED NULL COMMENT 'PrestaShop entity ID (NULL if creation failed)',
        `action_type` ENUM('created','updated','deleted') NOT NULL COMMENT 'Action performed',
        `source_payload` MEDIUMTEXT NULL COMMENT 'Original webhook payload from Odoo (JSON)',
        `result_data` TEXT NULL COMMENT 'Processing result details (JSON)',
        `status` ENUM('processing','success','failed') DEFAULT 'processing' COMMENT 'Operation status',
        `error_message` TEXT NULL COMMENT 'Error message if failed',
        `processing_time_ms` INT NULL COMMENT 'Processing duration in milliseconds',
        `date_add` DATETIME NOT NULL COMMENT 'Operation created timestamp',
        `date_upd` DATETIME NOT NULL COMMENT 'Operation last updated timestamp',
        PRIMARY KEY (`id_reverse_operation`),
        UNIQUE KEY `operation_id` (`operation_id`),
        KEY `entity_lookup` (`entity_type`, `entity_id`),
        KEY `status` (`status`),
        KEY `date_add` (`date_add`),
        KEY `entity_type_status` (`entity_type`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracks reverse synchronization operations from Odoo';";

    try {
        if (!$db->execute($sql)) {
            $success = false;
            error_log('Upgrade error: Could not create ps_odoo_sales_reverse_operations table');
        }
    } catch (Exception $e) {
        $success = false;
        error_log('Upgrade exception: ' . $e->getMessage());
    }

    // Step 2: Add new configuration keys for reverse sync (disabled by default)
    if ($success) {
        Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ENABLED', 0);
        Configuration::updateValue('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL', '');
        Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS', '');
    }

    // Step 3: Clear cache
    try {
        Tools::clearSmartyCache();
        Tools::clearXMLCache();
    } catch (Exception $e) {
        // Continue even if cache clear fails
        error_log('Upgrade warning: Could not clear cache - ' . $e->getMessage());
    }

    // Step 4: Log upgrade result
    try {
        if (class_exists('FileLogger')) {
            $logger = new FileLogger();
            $logger->setFilename(_PS_MODULE_DIR_ . 'odoo_sales_sync/var/logs/odoo_sales_sync_upgrade.log');
            if ($success) {
                $logger->logInfo('Successfully upgraded to v2.0.0 - Reverse sync added');
            } else {
                $logger->logError('Failed to upgrade to v2.0.0');
            }
        }
    } catch (Exception $e) {
        // Continue even if logging fails
    }

    return $success;
}
