-- ============================================================================
-- Odoo Sales Sync - Reverse Synchronization Tables
-- ============================================================================
-- Version: 2.0.0
-- Purpose: Track reverse synchronization operations from Odoo to PrestaShop
-- ============================================================================

-- Table: Reverse Operations Tracking
-- Stores all reverse sync operations for debugging, auditing, and loop prevention
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_reverse_operations` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Tracks reverse synchronization operations from Odoo';

-- ============================================================================
-- Configuration Values
-- ============================================================================
-- Note: These are added programmatically during module installation
-- Documented here for reference:
--
-- ODOO_SALES_SYNC_REVERSE_ENABLED (0/1)
--   - Enable/disable reverse synchronization
--
-- ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL (string)
--   - URL for debug webhook server (e.g., http://localhost:5000/webhook)
--
-- ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS (string)
--   - Comma-separated list of allowed IPs for reverse webhooks (optional)
--
-- ODOO_SALES_SYNC_REVERSE_TIMEOUT (int)
--   - Timeout in seconds for reverse webhook processing (default: 30)
-- ============================================================================
