-- Odoo Sales Sync - Logs Table Upgrade Script
-- Upgrades existing odoo_sales_logs table to enhanced schema
-- Run this if you already have the module installed

-- Step 1: Add new columns
ALTER TABLE `PREFIX_odoo_sales_logs`
  -- Add category field
  ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'system' COMMENT 'Log category: detection, api, sync, system, performance' AFTER `level`,

  -- Add correlation tracking
  ADD COLUMN `correlation_id` VARCHAR(36) DEFAULT NULL COMMENT 'UUID to correlate related events' AFTER `context`,
  ADD COLUMN `event_id` INT(11) UNSIGNED DEFAULT NULL COMMENT 'Related event ID' AFTER `correlation_id`,

  -- Add debug source tracking
  ADD COLUMN `file` VARCHAR(255) DEFAULT NULL COMMENT 'Source file path' AFTER `event_id`,
  ADD COLUMN `line` INT(11) DEFAULT NULL COMMENT 'Source line number' AFTER `file`,
  ADD COLUMN `function` VARCHAR(255) DEFAULT NULL COMMENT 'Source function name' AFTER `line`,

  -- Add performance tracking
  ADD COLUMN `execution_time` DECIMAL(10,4) DEFAULT NULL COMMENT 'Execution time in seconds' AFTER `function`,
  ADD COLUMN `memory_peak` INT(11) DEFAULT NULL COMMENT 'Peak memory usage in bytes' AFTER `execution_time`;

-- Step 2: Modify existing columns
ALTER TABLE `PREFIX_odoo_sales_logs`
  -- Change level to ENUM and add 'critical'
  MODIFY COLUMN `level` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info' COMMENT 'Log severity level',

  -- Change message to TEXT (remove 500 char limit)
  MODIFY COLUMN `message` TEXT NOT NULL COMMENT 'Log message';

-- Step 3: Add new indexes
ALTER TABLE `PREFIX_odoo_sales_logs`
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_correlation_id` (`correlation_id`),
  ADD KEY `idx_event_id` (`event_id`),
  ADD KEY `idx_composite_filter` (`level`, `category`, `date_add`);

-- Step 4: Update charset/collation if needed
ALTER TABLE `PREFIX_odoo_sales_logs`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
