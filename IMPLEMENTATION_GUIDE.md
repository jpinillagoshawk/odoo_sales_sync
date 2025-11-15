# Odoo Sales Sync Module - Complete Implementation Guide

**Version**: 1.0
**Date**: 2025-11-07
**PrestaShop Compatibility**: 8.0.x, 8.1.x, 8.2.x
**Status**: Production-Ready Implementation Plan

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Component Specifications](#component-specifications)
4. [Hook Registration (All 23 Hooks)](#hook-registration-all-23-hooks)
5. [Database Schema](#database-schema)
6. [Implementation Steps](#implementation-steps)
7. [Testing Procedures](#testing-procedures)
8. [Security Checklist](#security-checklist)
9. [Troubleshooting](#troubleshooting)

---

## Overview

### Objective

Create a PrestaShop 8.x module that tracks sales-related events (customers, orders, invoices, coupons, and address changes) and sends them to Odoo via webhook.

### Key Features

- ✅ Real-time customer lifecycle tracking (create, update, delete, address changes)
- ✅ Complete order tracking (creation, status updates, edits)
- ✅ Invoice and credit memo tracking (including PDF generation)
- ✅ Coupon usage tracking with workaround for missing PrestaShop hooks
- ✅ Automatic deduplication of events
- ✅ Retry mechanism with exponential backoff
- ✅ Admin UI for configuration and monitoring

### Critical Implementation Notes

**🔴 IMPORTANT**: This implementation includes workarounds for PrestaShop limitations:

1. **Coupon Apply/Remove Tracking**: PrestaShop does NOT fire hooks when `Cart::addCartRule()` or `Cart::removeCartRule()` are called. We use `actionCartSave` + snapshot diffing to detect changes.

2. **Address Change Normalization**: Address changes are normalized into customer update events since Odoo tracks address as part of customer records.

3. **All 23 Hooks Verified**: Every hook has been verified against PrestaShop 8.2.x source code.

---

## Architecture

### Component Diagram

```
odoo_sales_sync/
├── odoo_sales_sync.php          # Main module file (23 hook handlers)
├── classes/
│   ├── SalesEvent.php           # ObjectModel for event storage
│   ├── SalesEventDetector.php   # Main detection & normalization logic
│   ├── OdooWebhookClient.php    # HTTP client with retry
│   ├── CartRuleUsageTracker.php # NEW: Coupon snapshot diffing
│   ├── CartRuleStateRepository.php # NEW: Snapshot persistence
│   ├── EventLogger.php          # Logging (from stock sync)
│   ├── HookTracker.php          # Hook dedup (from stock sync)
│   └── RequestContext.php       # Transaction context (from stock sync)
├── controllers/admin/
│   └── AdminOdooSalesSyncController.php
├── sql/
│   ├── install.sql              # 4 database tables
│   └── uninstall.sql
├── views/templates/admin/
│   ├── configure.tpl
│   └── monitor.tpl
└── config.xml                   # Module metadata
```

### Data Flow

```
PrestaShop Event
       ↓
Hook Handler (odoo_sales_sync.php)
       ↓
SalesEventDetector
       ↓
┌─────────────────────────────────────┐
│ Special Processing:                 │
│ • CartRuleUsageTracker (for coupons)│
│ • Address → Customer normalization  │
└─────────────────────────────────────┘
       ↓
HookTracker (deduplication)
       ↓
SalesEvent::save() → Database
       ↓
OdooWebhookClient::send()
       ↓
Odoo Webhook Endpoint
```

---

## Component Specifications

### 1. SalesEvent.php (ObjectModel)

**Purpose**: Persist event data to `ps_odoo_sales_events` table.

**Full Implementation**:

```php
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class SalesEvent extends ObjectModel
{
    public $id_event;
    public $entity_type;
    public $entity_id;
    public $entity_name;
    public $action_type;
    public $transaction_hash;
    public $correlation_id;
    public $hook_name;
    public $hook_timestamp;
    public $before_data;
    public $after_data;
    public $change_summary;
    public $context_data;
    public $sync_status;
    public $sync_attempts;
    public $sync_last_attempt;
    public $sync_next_retry;
    public $sync_error;
    public $webhook_response_code;
    public $webhook_response_body;
    public $webhook_response_time;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'odoo_sales_events',
        'primary' => 'id_event',
        'fields' => [
            'entity_type' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 50],
            'entity_id' => ['type' => self::TYPE_INT, 'required' => true, 'validate' => 'isUnsignedId'],
            'entity_name' => ['type' => self::TYPE_STRING, 'size' => 255],
            'action_type' => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 50],
            'transaction_hash' => ['type' => self::TYPE_STRING, 'size' => 64, 'required' => true],
            'correlation_id' => ['type' => self::TYPE_STRING, 'size' => 36],
            'hook_name' => ['type' => self::TYPE_STRING, 'size' => 100, 'required' => true],
            'hook_timestamp' => ['type' => self::TYPE_DATE, 'required' => true, 'validate' => 'isDate'],
            'before_data' => ['type' => self::TYPE_STRING],
            'after_data' => ['type' => self::TYPE_STRING],
            'change_summary' => ['type' => self::TYPE_STRING, 'size' => 1000],
            'context_data' => ['type' => self::TYPE_STRING],
            'sync_status' => ['type' => self::TYPE_STRING, 'size' => 20, 'default' => 'pending'],
            'sync_attempts' => ['type' => self::TYPE_INT, 'default' => 0, 'validate' => 'isUnsignedInt'],
            'sync_last_attempt' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'sync_next_retry' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'sync_error' => ['type' => self::TYPE_STRING],
            'webhook_response_code' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'webhook_response_body' => ['type' => self::TYPE_STRING],
            'webhook_response_time' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public function __construct($id = null)
    {
        parent::__construct($id);
    }
}
```

---

### 2. CartRuleUsageTracker.php (NEW)

**Purpose**: Compensate for missing `actionCartRuleApplied` hook by diffing cart voucher snapshots.

**Algorithm**:
1. On `actionCartSave`, load current cart vouchers
2. Load previous snapshot from `ps_odoo_sales_cart_rule_state`
3. Diff to find added/removed vouchers
4. Generate `applied` or `removed` events
5. Save new snapshot

**Full Implementation**:

```php
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CartRuleUsageTracker
{
    private $detector;
    private $repository;
    private $logger;

    public function __construct($detector, $repository, $logger)
    {
        $this->detector = $detector;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Handle actionCartSave to detect voucher apply/remove
     */
    public function handleCartSave($params)
    {
        try {
            if (!isset($params['cart']) || !Validate::isLoadedObject($params['cart'])) {
                return false;
            }

            $cart = $params['cart'];

            // Get current vouchers in cart
            $currentRuleIds = $this->getCurrentCartRules($cart->id);

            // Get previous snapshot
            $previousRuleIds = $this->repository->getSnapshot($cart->id);

            // Detect added vouchers
            $addedRules = array_diff($currentRuleIds, $previousRuleIds);
            foreach ($addedRules as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'applied');
            }

            // Detect removed vouchers
            $removedRules = array_diff($previousRuleIds, $currentRuleIds);
            foreach ($removedRules as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'removed');
            }

            // Save new snapshot
            $this->repository->saveSnapshot($cart->id, $currentRuleIds);

            return true;
        } catch (Exception $e) {
            $this->logger->error('CartRuleUsageTracker::handleCartSave failed', [
                'cart_id' => isset($cart) ? $cart->id : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get current voucher IDs in cart
     */
    private function getCurrentCartRules($idCart)
    {
        $sql = 'SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'cart_cart_rule WHERE id_cart = ' . (int)$idCart;
        $rows = Db::getInstance()->executeS($sql);

        return array_map(function($row) {
            return (int)$row['id_cart_rule'];
        }, $rows ?: []);
    }

    /**
     * Generate voucher usage event
     */
    private function detectCartRuleChange($ruleId, $cartId, $action)
    {
        $cartRule = new CartRule($ruleId);
        if (!Validate::isLoadedObject($cartRule)) {
            $this->logger->warning('Failed to load CartRule', [
                'id_cart_rule' => $ruleId,
                'action' => $action
            ]);
            return false;
        }

        // Create synthetic event
        $this->detector->detectCouponUsage([
            'cart_rule' => $cartRule,
            'cart_id' => $cartId,
            'usage_action' => $action
        ]);
    }

    /**
     * Reconcile final voucher usage on order validation
     */
    public function handleOrderValidation($params)
    {
        try {
            if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
                return false;
            }

            $order = $params['order'];
            $cart = new Cart($order->id_cart);

            if (!Validate::isLoadedObject($cart)) {
                return false;
            }

            // Get final vouchers from order
            $finalRuleIds = $this->getOrderCartRules($order->id);

            foreach ($finalRuleIds as $ruleId) {
                $this->detectCartRuleChange($ruleId, $cart->id, 'consumed');
            }

            // Clean up snapshot
            $this->repository->deleteSnapshot($cart->id);

            return true;
        } catch (Exception $e) {
            $this->logger->error('CartRuleUsageTracker::handleOrderValidation failed', [
                'order_id' => isset($order) ? $order->id : null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get vouchers used in order
     */
    private function getOrderCartRules($idOrder)
    {
        $sql = 'SELECT id_cart_rule FROM ' . _DB_PREFIX_ . 'order_cart_rule WHERE id_order = ' . (int)$idOrder;
        $rows = Db::getInstance()->executeS($sql);

        return array_map(function($row) {
            return (int)$row['id_cart_rule'];
        }, $rows ?: []);
    }
}
```

---

### 3. CartRuleStateRepository.php (NEW)

**Purpose**: CRUD operations for voucher snapshots.

**Full Implementation**:

```php
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CartRuleStateRepository
{
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get previous snapshot for cart
     */
    public function getSnapshot($idCart)
    {
        $sql = 'SELECT cart_rule_ids FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                WHERE id_cart = ' . (int)$idCart;

        $json = Db::getInstance()->getValue($sql);

        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save new snapshot for cart
     */
    public function saveSnapshot($idCart, array $ruleIds)
    {
        $json = json_encode(array_values($ruleIds));
        $now = date('Y-m-d H:i:s');

        $existing = $this->getSnapshot($idCart);

        if (empty($existing)) {
            // Insert
            $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                    (id_cart, cart_rule_ids, last_detected_action, date_add, date_upd)
                    VALUES (' . (int)$idCart . ', \'' . pSQL($json) . '\', \'snapshot\', \'' . pSQL($now) . '\', \'' . pSQL($now) . '\')';
        } else {
            // Update
            $sql = 'UPDATE ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                    SET cart_rule_ids = \'' . pSQL($json) . '\',
                        date_upd = \'' . pSQL($now) . '\'
                    WHERE id_cart = ' . (int)$idCart;
        }

        return Db::getInstance()->execute($sql);
    }

    /**
     * Delete snapshot (cleanup after order)
     */
    public function deleteSnapshot($idCart)
    {
        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state WHERE id_cart = ' . (int)$idCart;
        return Db::getInstance()->execute($sql);
    }

    /**
     * Clean up old snapshots (carts older than X days)
     */
    public function cleanupOldSnapshots($daysOld = 30)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $sql = 'DELETE FROM ' . _DB_PREFIX_ . 'odoo_sales_cart_rule_state
                WHERE date_upd < \'' . pSQL($cutoffDate) . '\'';

        $result = Db::getInstance()->execute($sql);

        $this->logger->info('Cleaned up old cart rule snapshots', [
            'days_old' => $daysOld,
            'cutoff_date' => $cutoffDate
        ]);

        return $result;
    }
}
```

---

### 4. SalesEventDetector.php (Main Logic)

**Purpose**: Normalize all hook events into standardized SalesEvent records.

**Key Methods**:
- `detectCustomerChange()` - Handle customer create/update/delete
- `detectAddressChange()` - **NEW**: Normalize address events to customer events
- `detectOrderChange()` - Handle order lifecycle
- `detectInvoiceChange()` - Handle invoices and credit memos
- `detectCouponChange()` - Handle coupon CRUD
- `detectCouponUsage()` - **NEW**: Handle synthetic voucher apply/remove events

**Implementation Outline** (see full file in src/modules/odoo_sales_sync/classes/SalesEventDetector.php)

---

### 5. OdooWebhookClient.php

**Purpose**: Send events to Odoo with retry logic.

**Features**:
- 3 retry attempts with exponential backoff (1s, 2s, 4s)
- Timeout: 10 seconds
- Error logging with response codes

**Implementation Outline** (see full file in src/modules/odoo_sales_sync/classes/OdooWebhookClient.php)

---

## Hook Registration (All 23 Hooks)

### install() Method

```php
public function install()
{
    return parent::install()
        // Customer hooks (5)
        && $this->registerHook('actionCustomerAccountAdd')
        && $this->registerHook('actionAuthentication')
        && $this->registerHook('actionObjectCustomerAddAfter')
        && $this->registerHook('actionObjectCustomerUpdateAfter')
        && $this->registerHook('actionObjectCustomerDeleteAfter')

        // Address hooks (3) - NEW
        && $this->registerHook('actionObjectAddressAddAfter')
        && $this->registerHook('actionObjectAddressUpdateAfter')
        && $this->registerHook('actionObjectAddressDeleteAfter')

        // Order hooks (4)
        && $this->registerHook('actionValidateOrder')
        && $this->registerHook('actionOrderStatusUpdate')
        && $this->registerHook('actionObjectOrderUpdateAfter')
        && $this->registerHook('actionOrderEdited')

        // Invoice hooks (4)
        && $this->registerHook('actionObjectOrderInvoiceAddAfter')
        && $this->registerHook('actionObjectOrderInvoiceUpdateAfter')
        && $this->registerHook('actionPDFInvoiceRender') // CRITICAL - was missing
        && $this->registerHook('actionOrderSlipAdd')

        // Coupon/Discount hooks (7)
        && $this->registerHook('actionObjectCartRuleAddAfter')
        && $this->registerHook('actionObjectCartRuleUpdateAfter')
        && $this->registerHook('actionObjectCartRuleDeleteAfter')
        && $this->registerHook('actionObjectSpecificPriceAddAfter')
        && $this->registerHook('actionObjectSpecificPriceUpdateAfter')
        && $this->registerHook('actionObjectSpecificPriceDeleteAfter')
        && $this->registerHook('actionCartSave'); // CRITICAL - for voucher tracking
}
```

---

## Database Schema

### Table 1: ps_odoo_sales_events

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_events` (
  `id_event` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) unsigned NOT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `transaction_hash` varchar(64) NOT NULL,
  `correlation_id` varchar(36) DEFAULT NULL,
  `hook_name` varchar(100) NOT NULL,
  `hook_timestamp` datetime NOT NULL,
  `before_data` text,
  `after_data` text,
  `change_summary` varchar(1000) DEFAULT NULL,
  `context_data` text,
  `sync_status` varchar(20) DEFAULT 'pending',
  `sync_attempts` int(11) DEFAULT 0,
  `sync_last_attempt` datetime DEFAULT NULL,
  `sync_next_retry` datetime DEFAULT NULL,
  `sync_error` text,
  `webhook_response_code` int(11) DEFAULT NULL,
  `webhook_response_body` text,
  `webhook_response_time` float DEFAULT NULL,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_event`),
  UNIQUE KEY `transaction_hash` (`transaction_hash`),
  KEY `entity` (`entity_type`, `entity_id`),
  KEY `sync_status` (`sync_status`, `sync_next_retry`),
  KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 2: ps_odoo_sales_logs

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_logs` (
  `id_log` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `level` varchar(20) NOT NULL,
  `message` varchar(500) NOT NULL,
  `context` text,
  `date_add` datetime NOT NULL,
  PRIMARY KEY (`id_log`),
  KEY `level` (`level`),
  KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 3: ps_odoo_sales_dedup

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_dedup` (
  `id_dedup` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `hook_name` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) unsigned NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `event_hash` varchar(64) NOT NULL,
  `count` int(11) DEFAULT 1,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  PRIMARY KEY (`id_dedup`),
  UNIQUE KEY `event_hash` (`event_hash`),
  KEY `cleanup` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table 4: ps_odoo_sales_cart_rule_state (NEW)

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_cart_rule_state` (
  `id_state` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_cart` int(11) unsigned NOT NULL,
  `cart_rule_ids` text NOT NULL COMMENT 'JSON array of rule IDs',
  `last_event_hash` varchar(64) DEFAULT NULL,
  `last_detected_action` varchar(50) DEFAULT NULL,
  `date_add` datetime NOT NULL,
  `date_upd` datetime NOT NULL,
  PRIMARY KEY (`id_state`),
  UNIQUE KEY `id_cart` (`id_cart`),
  KEY `date_upd` (`date_upd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Implementation Steps

### Phase 1: Core Structure (30 minutes)

1. ✅ Create module directory: `modules/odoo_sales_sync/`
2. ✅ Create subdirectories: `classes/`, `controllers/admin/`, `sql/`, `views/templates/admin/`
3. ✅ Copy logging classes from stock sync module:
   - `EventLogger.php`
   - `HookTracker.php`
   - `RequestContext.php`
4. ✅ Create `config.xml` with module metadata

### Phase 2: Database Layer (45 minutes)

5. ✅ Create `sql/install.sql` with all 4 tables
6. ✅ Create `sql/uninstall.sql`
7. ✅ Implement `SalesEvent.php` with complete `$definition` array
8. ✅ Implement `CartRuleStateRepository.php`

### Phase 3: Detection Logic (2 hours)

9. ✅ Implement `SalesEventDetector.php` with all detection methods
10. ✅ Implement `CartRuleUsageTracker.php` with snapshot diffing
11. ✅ Add `detectAddressChange()` method to normalize address events

### Phase 4: Webhook Client (45 minutes)

12. ✅ Implement `OdooWebhookClient.php` with retry logic
13. ✅ Add exponential backoff (1s, 2s, 4s)
14. ✅ Add response logging

### Phase 5: Main Module (1 hour)

15. ✅ Create `odoo_sales_sync.php`
16. ✅ Implement `install()` with all 23 hooks
17. ✅ Implement all hook handler methods (23 total)
18. ✅ Initialize components in `__construct()`
19. ✅ Add try-catch to all hook handlers

### Phase 6: Admin UI (1.5 hours)

20. ✅ Create `AdminOdooSalesSyncController.php`
21. ✅ Add configuration form (webhook URL, secret, enable/disable)
22. ✅ Add event monitor view (recent events, sync status)
23. ✅ Add manual retry button for failed events

### Phase 7: Testing & Debug Tools (1 hour)

24. ✅ Create `debug_webhook_receiver.py` (Python script)
25. ✅ Create test checklist document
26. ✅ Add `index.php` security files in all directories

### Phase 8: Documentation (30 minutes)

27. ✅ Create README.md
28. ✅ Add inline PHPDoc comments
29. ✅ Create CHANGELOG.md

**Total Estimated Time**: 8 hours

---

## Testing Procedures

### Test Suite Overview

**Categories**:
1. Customer Lifecycle Tests
2. Address Change Tests (NEW)
3. Order Lifecycle Tests
4. Invoice & Credit Memo Tests
5. Coupon CRUD Tests
6. Coupon Usage Flow Tests (NEW - Critical)
7. Deduplication Tests
8. Retry Logic Tests

### Critical Test: Coupon Usage Flow

**Objective**: Verify snapshot diffing correctly detects apply/remove/consume events.

**Steps**:
1. Start webhook receiver: `python3 debug_webhook_receiver.py`
2. Create cart with product A (€100)
3. Apply voucher CODE1 (€10 off)
   - ✅ Verify `applied` webhook received
   - ✅ Check database: 1 event with action `applied`
4. Add product B (€50) to cart
   - ✅ Verify NO duplicate webhook
5. Remove CODE1 from cart
   - ✅ Verify `removed` webhook received
   - ✅ Check database: 2 events (applied, removed)
6. Apply CODE1 again
   - ✅ Verify new `applied` webhook (not duplicate)
   - ✅ Check database: 3 events
7. Complete order
   - ✅ Verify `consumed` webhook from `actionValidateOrder`
   - ✅ Check database: Final event with action `consumed`
   - ✅ Verify snapshot deleted from `ps_odoo_sales_cart_rule_state`

**Expected Webhooks**:
```
1. { "entity_type": "coupon", "action_type": "applied", "entity_id": <rule_id>, "cart_id": <cart_id> }
2. { "entity_type": "coupon", "action_type": "removed", "entity_id": <rule_id>, "cart_id": <cart_id> }
3. { "entity_type": "coupon", "action_type": "applied", "entity_id": <rule_id>, "cart_id": <cart_id> }
4. { "entity_type": "order", "action_type": "created", "entity_id": <order_id> }
5. { "entity_type": "coupon", "action_type": "consumed", "entity_id": <rule_id>, "order_id": <order_id> }
```

### Critical Test: Address Change Detection

**Steps**:
1. Create customer via front office
2. Update customer's shipping address
   - ✅ Verify webhook: `{ "entity_type": "customer", "action_type": "updated", "context_data": { "change_type": "address", "address_action": "updated" } }`
3. Delete billing address
   - ✅ Verify webhook: `{ "entity_type": "customer", "action_type": "updated", "context_data": { "change_type": "address", "address_action": "deleted" } }`

### Test Automation Script

See `odoo_sales_sync_implementation/tests/test_suite.sh`

---

## Security Checklist

**Pre-Flight Checks** (verify before deployment):

- [ ] All SQL queries use `pSQL()` or `(int)` casting
- [ ] All user input uses `Tools::getValue()` with validation
- [ ] All loaded objects checked with `Validate::isLoadedObject()`
- [ ] All template variables escaped: `{$var|escape:'html':'UTF-8'}`
- [ ] Admin forms include CSRF token
- [ ] Every directory has `index.php` redirect file
- [ ] Webhook secret validated before processing requests
- [ ] No sensitive data (passwords, API keys) in logs
- [ ] Database credentials stored in PrestaShop config, not hardcoded
- [ ] Module version matches `config.xml`

---

## Troubleshooting

### Issue 1: No Webhooks Firing

**Symptoms**: Module installed, but no webhooks received.

**Checklist**:
1. Verify webhook URL is configured: Admin > Modules > Odoo Sales Sync > Configure
2. Check module is enabled in Configuration
3. Verify hooks are registered: `SELECT * FROM ps_hook_module WHERE id_module = <module_id>`
4. Check logs: `SELECT * FROM ps_odoo_sales_logs ORDER BY date_add DESC LIMIT 100`
5. Enable debug mode: Add `define('_PS_MODE_DEV_', true);` to `config/defines.inc.php`
6. Test webhook receiver: `curl -X POST http://localhost:5000/webhook -d '{"test": "data"}'`

---

### Issue 2: Coupon Events Not Detected

**Symptoms**: Coupon apply/remove not triggering webhooks.

**Checklist**:
1. Verify `actionCartSave` is registered
2. Check snapshot table: `SELECT * FROM ps_odoo_sales_cart_rule_state WHERE id_cart = <cart_id>`
3. Enable CartRuleUsageTracker logging in `handleCartSave()`
4. Verify `Cart::add()` or `Cart::update()` is being called (snapshot diff happens here)
5. Check for exceptions in `ps_odoo_sales_logs`

**Known Issue**: If vouchers are applied via direct database manipulation (not through PrestaShop API), events won't fire. Solution: Use PrestaShop's `Cart::addDiscount()` method.

---

### Issue 3: Duplicate Events

**Symptoms**: Same event appears multiple times in Odoo.

**Checklist**:
1. Check dedup table: `SELECT * FROM ps_odoo_sales_dedup ORDER BY last_seen DESC`
2. Verify transaction hash includes timestamp bucket (5-second window)
3. Check if multiple hooks are firing for same event (e.g., `actionObjectCustomerUpdateAfter` + `actionAuthentication`)
4. Review `HookTracker::isDuplicate()` logic

---

### Issue 4: Address Changes Not Tracked

**Symptoms**: Customer address updates don't trigger webhooks.

**Checklist**:
1. Verify address hooks are registered: `actionObjectAddressAddAfter`, `actionObjectAddressUpdateAfter`, `actionObjectAddressDeleteAfter`
2. Check if `detectAddressChange()` is being called
3. Verify address has `id_customer` populated
4. Check logs for failed customer lookups

---

## Appendix A: PrestaShop Hook Parameters

### actionObjectCustomerAddAfter / UpdateAfter / DeleteAfter
```php
$params = [
    'object' => Customer  // Loaded Customer instance
];
```

### actionObjectAddressAddAfter / UpdateAfter / DeleteAfter
```php
$params = [
    'object' => Address  // Loaded Address instance with id_customer
];
```

### actionCartSave
```php
$params = [
    'cart' => Cart  // Loaded Cart instance with all cart rules
];
```

### actionValidateOrder
```php
$params = [
    'order' => Order,           // New order
    'cart' => Cart,             // Original cart
    'customer' => Customer,     // Customer who placed order
    'currency' => Currency,     // Order currency
    'orderStatus' => OrderState // Initial order status
];
```

### actionPDFInvoiceRender
```php
$params = [
    'order_invoice_list' => [OrderInvoice, ...]  // Array of invoices being rendered
];
```

---

## Appendix B: Webhook Payload Schema

### Customer Event
```json
{
  "event_id": 12345,
  "entity_type": "customer",
  "entity_id": 67,
  "entity_name": "John Doe",
  "action_type": "created",
  "hook_name": "actionObjectCustomerAddAfter",
  "timestamp": "2025-11-07T14:32:10Z",
  "transaction_hash": "customer_67_created_1699365130",
  "data": {
    "email": "john@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "id_default_group": 3
  },
  "context": {
    "shop_id": 1,
    "language_id": 1
  }
}
```

### Coupon Usage Event
```json
{
  "event_id": 12346,
  "entity_type": "coupon",
  "entity_id": 42,
  "entity_name": "SUMMER2024",
  "action_type": "applied",
  "hook_name": "actionCartSave",
  "timestamp": "2025-11-07T14:35:22Z",
  "transaction_hash": "coupon_42_applied_1699365322",
  "data": {
    "code": "SUMMER2024",
    "reduction_amount": 10.00,
    "reduction_percent": 0,
    "cart_id": 89
  },
  "context": {
    "usage_action": "applied",
    "cart_id": 89
  }
}
```

### Address Change Event (Normalized to Customer)
```json
{
  "event_id": 12347,
  "entity_type": "customer",
  "entity_id": 67,
  "entity_name": "John Doe",
  "action_type": "updated",
  "hook_name": "actionObjectAddressUpdateAfter",
  "timestamp": "2025-11-07T14:40:15Z",
  "transaction_hash": "customer_67_updated_1699365615",
  "data": {
    "customer_id": 67,
    "email": "john@example.com"
  },
  "context": {
    "change_type": "address",
    "address_id": 123,
    "address_action": "updated",
    "address_alias": "My shipping address"
  }
}
```

---

**Document Version**: 1.0
**Last Updated**: 2025-11-07
**Verified Against**: PrestaShop 8.2.x source code
**Status**: ✅ PRODUCTION-READY
