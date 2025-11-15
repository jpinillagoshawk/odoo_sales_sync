-- Odoo Sales Sync Module - Database Installation
-- Creates 4 tables for event tracking, logging, deduplication, and cart rule snapshots

-- Table 1: Sales Events (Main event log)
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_events` (
  `id_event` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL COMMENT 'customer, order, invoice, coupon, discount',
  `entity_id` int(11) unsigned NOT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL COMMENT 'created, updated, deleted, applied, removed, consumed',
  `transaction_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash for deduplication',
  `correlation_id` varchar(36) DEFAULT NULL COMMENT 'UUID for related events',
  `hook_name` varchar(100) NOT NULL COMMENT 'PrestaShop hook that triggered event',
  `hook_timestamp` datetime NOT NULL COMMENT 'When hook was fired',
  `before_data` text COMMENT 'JSON snapshot before change',
  `after_data` text COMMENT 'JSON snapshot after change',
  `change_summary` varchar(1000) DEFAULT NULL COMMENT 'Human-readable change description',
  `context_data` text COMMENT 'Additional context (JSON)',
  `sync_status` varchar(20) DEFAULT 'pending' COMMENT 'pending, sending, sent, success, failed, retry',
  `sync_attempts` int(11) DEFAULT 0 COMMENT 'Number of sync attempts',
  `sync_last_attempt` datetime DEFAULT NULL COMMENT 'Last sync attempt timestamp',
  `sync_next_retry` datetime DEFAULT NULL COMMENT 'Next retry timestamp',
  `sync_error` text COMMENT 'Last sync error message',
  `webhook_response_code` int(11) DEFAULT NULL COMMENT 'HTTP response code from Odoo',
  `webhook_response_body` text COMMENT 'HTTP response body from Odoo',
  `webhook_response_time` float DEFAULT NULL COMMENT 'Response time in seconds',
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_event`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `entity` (`entity_type`, `entity_id`),
  KEY `sync_status` (`sync_status`, `sync_next_retry`),
  KEY `date_add` (`date_add`),
  KEY `hook_name` (`hook_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Sales events to sync with Odoo';

-- Table 2: Module Logs
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_logs` (
  `id_log` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `level` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info' COMMENT 'Log severity level',
  `category` varchar(50) NOT NULL DEFAULT 'system' COMMENT 'Log category: detection, api, sync, system, performance',
  `message` text NOT NULL COMMENT 'Log message',
  `context` text COMMENT 'JSON context data',
  `correlation_id` varchar(36) DEFAULT NULL COMMENT 'UUID to correlate related events',
  `event_id` int(11) unsigned DEFAULT NULL COMMENT 'Related event ID',
  `file` varchar(255) DEFAULT NULL COMMENT 'Source file path',
  `line` int(11) DEFAULT NULL COMMENT 'Source line number',
  `function` varchar(255) DEFAULT NULL COMMENT 'Source function name',
  `execution_time` decimal(10,4) DEFAULT NULL COMMENT 'Execution time in seconds',
  `memory_peak` int(11) DEFAULT NULL COMMENT 'Peak memory usage in bytes',
  `date_add` datetime NOT NULL COMMENT 'Log creation timestamp',
  PRIMARY KEY (`id_log`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_date_add` (`date_add`),
  KEY `idx_correlation_id` (`correlation_id`),
  KEY `idx_event_id` (`event_id`),
  KEY `idx_composite_filter` (`level`, `category`, `date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Module debug and error logs with correlation tracking';

-- Table 3: Hook Deduplication
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_dedup` (
  `id_dedup` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `hook_name` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) unsigned NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `event_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash of hook+entity+action',
  `count` int(11) DEFAULT 1 COMMENT 'Number of times this event was seen',
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  PRIMARY KEY (`id_dedup`),
  UNIQUE KEY `event_hash` (`event_hash`),
  KEY `cleanup` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Deduplication tracker for hook events';

-- Table 4: Cart Rule State (NEW - for coupon tracking workaround)
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_cart_rule_state` (
  `id_state` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_cart` int(11) unsigned NOT NULL,
  `cart_rule_ids` text NOT NULL COMMENT 'JSON array of cart rule IDs in cart',
  `last_event_hash` varchar(64) DEFAULT NULL COMMENT 'Hash of last detected event',
  `last_detected_action` varchar(50) DEFAULT NULL COMMENT 'applied, removed, snapshot',
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_state`),
  UNIQUE KEY `id_cart` (`id_cart`),
  KEY `date_upd` (`date_upd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cart voucher snapshots for detecting apply/remove';
