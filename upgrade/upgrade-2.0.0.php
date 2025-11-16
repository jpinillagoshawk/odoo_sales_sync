<?php
/**
 * Upgrade script for Odoo Sales Sync v2.0.0
 *
 * This script handles the upgrade from v1.x to v2.0.0
 * 
 * Major changes:
 * - Added reverse synchronization support
 * - New table: ps_odoo_sales_reverse_operations
 * - New configuration keys for reverse sync
 * - New classes for reverse sync processing
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to version 2.0.0
 *
 * @param object $module Module instance
 * @return bool Success
 */
function upgrade_module_2_0_0($module)
{
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

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    // Step 2: Add new configuration keys for reverse sync (disabled by default)
    Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ENABLED', 0);
    Configuration::updateValue('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL', '');
    Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS', '');

    // Step 3: Log upgrade success
    $logger = new FileLogger();
    $logger->setFilename(_PS_MODULE_DIR_ . 'odoo_sales_sync/var/logs/odoo_sales_sync_upgrade.log');
    $logger->logInfo('Successfully upgraded to v2.0.0 - Reverse sync enabled');

    return true;
}
