# Reverse Synchronization Implementation Plan

**Module**: odoo_sales_sync
**Feature**: Reverse Webhook Synchronization (Odoo → PrestaShop)
**Version**: 2.0.0
**Date**: 2025-11-16
**Status**: 📋 Planning Phase

---

## Overview

This document outlines the implementation plan for adding **reverse synchronization** capabilities to the odoo_sales_sync module. This will enable Odoo to push data changes back to PrestaShop via webhook, creating true bi-directional synchronization.

### Current State (v1.1.0)
- ✅ PrestaShop → Odoo synchronization (outgoing webhooks)
- ✅ Comprehensive event detection for customers, orders, invoices, coupons
- ✅ Webhook batching and retry logic
- ✅ Debug webhook server for testing

### Target State (v2.0.0)
- ✅ All features from v1.1.0
- 🎯 **NEW**: Odoo → PrestaShop synchronization (incoming webhooks)
- 🎯 **NEW**: Loop prevention mechanism to avoid infinite webhook cycles
- 🎯 **NEW**: Support for creating/updating: contacts, orders, addresses, coupons
- 🎯 **NEW**: Automatic webhook notifications to debug server for tracking

---

## Architecture Overview

### Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                         PRESTASHOP                               │
│                                                                   │
│  ┌────────────┐                              ┌──────────────┐   │
│  │ Customer   │ ──── Hook Fired ────────────>│ EventDetector│   │
│  │ Order      │                              │   (Current)  │   │
│  │ Coupon     │                              └──────┬───────┘   │
│  └────────────┘                                     │           │
│                                                     ▼           │
│                                              ┌──────────────┐   │
│                                              │ Webhook      │   │
│                                              │ Client       │   │
│                                              └──────┬───────┘   │
└───────────────────────────────────────────────────┼─────────────┘
                                                     │
                                                     │ HTTPS POST
                                                     ▼
                                              ┌─────────────┐
                                              │    ODOO     │
                                              │  (External) │
                                              └─────────────┘
                                                     │
                                                     │ HTTPS POST
                                                     ▼ (NEW!)
┌─────────────────────────────────────────────────┼───────────────┐
│                         PRESTASHOP              │               │
│                                                 ▼               │
│  ┌────────────────────────────────────────────────────────┐    │
│  │        NEW: Reverse Webhook Receiver                    │    │
│  │  - Authentication                                       │    │
│  │  - Request validation                                   │    │
│  │  - Entity routing                                       │    │
│  │  - Loop detection                                       │    │
│  └─────────────────────┬──────────────────────────────────┘    │
│                        │                                        │
│                        ▼                                        │
│  ┌────────────────────────────────────────────────────────┐    │
│  │        NEW: Entity Processors                           │    │
│  │  - CustomerProcessor (create/update)                    │    │
│  │  - OrderProcessor (create/update)                       │    │
│  │  - AddressProcessor (create/update)                     │    │
│  │  - CouponProcessor (create/update)                      │    │
│  └─────────────────────┬──────────────────────────────────┘    │
│                        │                                        │
│                        ▼                                        │
│  ┌────────────────────────────────────────────────────────┐    │
│  │    PrestaShop Core Objects                              │    │
│  │    - Customer::add() / Customer::update()               │    │
│  │    - Order::create() / Order::update()                  │    │
│  │    - Address::add() / Address::update()                 │    │
│  │    - CartRule::add() / CartRule::update()               │    │
│  └─────────────────────┬──────────────────────────────────┘    │
│                        │                                        │
│                        │ (Normally would trigger hooks)         │
│                        │                                        │
│  ┌────────────────────┼───────────────────────────────────┐    │
│  │   NEW: Reverse Sync Context Flag                       │    │
│  │   - Marks operations as "from reverse sync"            │    │
│  │   - EventDetector checks this flag                     │    │
│  │   - Skips webhook generation if flag is set            │    │
│  └────────────────────┬───────────────────────────────────┘    │
│                       │                                         │
│                       │ (But we DO want to notify debug!)       │
│                       ▼                                         │
│  ┌────────────────────────────────────────────────────────┐    │
│  │   NEW: Reverse Sync Notifier                            │    │
│  │   - Sends notification to webhook_debug_server.py       │    │
│  │   - Includes special marker: "reverse_sync": true       │    │
│  └─────────────────────┬──────────────────────────────────┘    │
└────────────────────────┼────────────────────────────────────────┘
                         │
                         │ HTTPS POST
                         ▼
                  ┌─────────────────┐
                  │  webhook_debug  │
                  │  _server.py     │
                  │  (localhost)    │
                  └─────────────────┘
```

---

## Critical Challenge: Loop Prevention

### The Problem

Without proper loop prevention, we would have:

```
Odoo updates customer
  → Sends webhook to PrestaShop
    → PrestaShop updates Customer object
      → Fires hookActionObjectCustomerUpdateAfter
        → EventDetector creates event
          → WebhookClient sends to Odoo
            → Odoo receives update it just made
              → Sends webhook to PrestaShop again
                → INFINITE LOOP! 🔥
```

### The Solution: Multi-Layer Loop Prevention

We'll implement **3 layers** of protection:

#### Layer 1: Reverse Sync Context Flag

```php
// NEW class: OdooSalesReverseSyncContext
class OdooSalesReverseSyncContext
{
    private static $isReverseSyncOperation = false;
    private static $operationId = null;

    public static function markAsReverseSync($operationId) {
        self::$isReverseSyncOperation = true;
        self::$operationId = $operationId;
    }

    public static function isReverseSync() {
        return self::$isReverseSyncOperation;
    }

    public static function clear() {
        self::$isReverseSyncOperation = false;
        self::$operationId = null;
    }
}
```

#### Layer 2: Modified Event Detector

```php
// MODIFY: OdooSalesEventDetector::detectCustomerChange()
public function detectCustomerChange($hookName, $params, $action)
{
    // NEW: Check if this is a reverse sync operation
    if (OdooSalesReverseSyncContext::isReverseSync()) {
        $this->logger->debug('Skipping event creation - reverse sync operation', [
            'hook' => $hookName,
            'operation_id' => OdooSalesReverseSyncContext::getOperationId(),
            'entity_type' => 'customer'
        ]);
        return true; // Don't create event, don't send webhook to Odoo
    }

    // ... existing event creation logic ...
}
```

#### Layer 3: Operation Tracking Table

```sql
-- NEW table to track reverse sync operations
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_reverse_operations` (
  `id_reverse_operation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_id` VARCHAR(64) NOT NULL,
  `entity_type` ENUM('customer','order','address','coupon') NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `action_type` ENUM('created','updated','deleted') NOT NULL,
  `source_payload` TEXT NULL COMMENT 'Original webhook payload from Odoo',
  `status` ENUM('processing','success','failed') DEFAULT 'processing',
  `error_message` TEXT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_reverse_operation`),
  UNIQUE KEY `operation_id` (`operation_id`),
  KEY `entity_lookup` (`entity_type`, `entity_id`),
  KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Implementation Components

### 1. Reverse Webhook Receiver Endpoint

**File**: `odoo_sales_sync/reverse_webhook.php`

```php
<?php
/**
 * Reverse Webhook Receiver
 *
 * Receives webhooks FROM Odoo TO PrestaShop
 * Supports: customer, order, address, coupon entities
 *
 * Security:
 * - Webhook secret validation
 * - IP whitelist (optional)
 * - Request signature verification
 *
 * @version 2.0.0
 */

// Security check - validate webhook secret
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
$configuredSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET');

if ($receivedSecret !== $configuredSecret) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid webhook secret']));
}

// Parse incoming payload
$payload = json_decode(file_get_contents('php://input'), true);

if (!$payload) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON payload']));
}

// Route to appropriate entity processor
$result = OdooSalesReverseWebhookRouter::route($payload);

// Return result
http_response_code($result['success'] ? 200 : 500);
echo json_encode($result);
```

### 2. Reverse Webhook Router

**File**: `odoo_sales_sync/classes/OdooSalesReverseWebhookRouter.php`

```php
<?php
class OdooSalesReverseWebhookRouter
{
    public static function route($payload)
    {
        $entityType = $payload['entity_type'] ?? null;
        $actionType = $payload['action_type'] ?? null;

        // Generate operation ID for tracking
        $operationId = self::generateOperationId($payload);

        // Mark as reverse sync operation
        OdooSalesReverseSyncContext::markAsReverseSync($operationId);

        try {
            // Route to appropriate processor
            switch ($entityType) {
                case 'customer':
                case 'contact':
                    return OdooSalesCustomerProcessor::process($payload, $operationId);

                case 'order':
                    return OdooSalesOrderProcessor::process($payload, $operationId);

                case 'address':
                    return OdooSalesAddressProcessor::process($payload, $operationId);

                case 'coupon':
                case 'discount':
                    return OdooSalesCouponProcessor::process($payload, $operationId);

                default:
                    return [
                        'success' => false,
                        'error' => 'Unknown entity type: ' . $entityType
                    ];
            }
        } finally {
            // Always clear reverse sync flag
            OdooSalesReverseSyncContext::clear();
        }
    }
}
```

### 3. Customer Processor

**File**: `odoo_sales_sync/classes/OdooSalesCustomerProcessor.php`

```php
<?php
class OdooSalesCustomerProcessor
{
    public static function process($payload, $operationId)
    {
        $logger = new OdooSalesLogger();
        $data = $payload['data'] ?? [];
        $actionType = $payload['action_type'] ?? 'updated';

        // Track operation
        self::trackOperation($operationId, 'customer', $data['id'] ?? null, $actionType, $payload);

        try {
            if ($actionType === 'created') {
                $result = self::createCustomer($data);
            } else {
                $result = self::updateCustomer($data);
            }

            // Send notification to debug server
            self::notifyDebugServer($payload, $result, $operationId);

            // Mark operation as success
            self::updateOperationStatus($operationId, 'success');

            return $result;

        } catch (Exception $e) {
            $logger->error('[REVERSE_SYNC] Customer processing failed', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);

            self::updateOperationStatus($operationId, 'failed', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private static function createCustomer($data)
    {
        $customer = new Customer();

        // Map fields from Odoo to PrestaShop
        $customer->email = $data['email'];
        $customer->firstname = $data['firstname'] ?? '';
        $customer->lastname = $data['lastname'] ?? '';
        $customer->passwd = Tools::hash(Tools::passwdGen()); // Generate random password
        $customer->active = $data['active'] ?? true;
        $customer->newsletter = $data['newsletter'] ?? false;
        $customer->optin = $data['optin'] ?? false;

        if (!$customer->add()) {
            throw new Exception('Failed to create customer');
        }

        return [
            'success' => true,
            'entity_id' => $customer->id,
            'message' => 'Customer created successfully'
        ];
    }

    private static function updateCustomer($data)
    {
        $customerId = $data['id'] ?? null;

        if (!$customerId) {
            // Try to find by email
            $customerId = Customer::customerExists($data['email'], true);
        }

        if (!$customerId) {
            throw new Exception('Customer not found');
        }

        $customer = new Customer($customerId);

        if (!Validate::isLoadedObject($customer)) {
            throw new Exception('Invalid customer ID');
        }

        // Update fields
        if (isset($data['firstname'])) $customer->firstname = $data['firstname'];
        if (isset($data['lastname'])) $customer->lastname = $data['lastname'];
        if (isset($data['email'])) $customer->email = $data['email'];
        if (isset($data['active'])) $customer->active = $data['active'];
        if (isset($data['newsletter'])) $customer->newsletter = $data['newsletter'];
        if (isset($data['optin'])) $customer->optin = $data['optin'];

        if (!$customer->update()) {
            throw new Exception('Failed to update customer');
        }

        return [
            'success' => true,
            'entity_id' => $customer->id,
            'message' => 'Customer updated successfully'
        ];
    }

    private static function notifyDebugServer($payload, $result, $operationId)
    {
        $webhookUrl = Configuration::get('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');

        if (empty($webhookUrl)) {
            return; // Debug webhook not configured
        }

        $notification = [
            'event_id' => $operationId,
            'entity_type' => $payload['entity_type'],
            'entity_id' => $result['entity_id'] ?? null,
            'action_type' => $payload['action_type'],
            'hook_name' => 'reverseWebhookReceived',
            'timestamp' => date('c'),
            'reverse_sync' => true, // CRITICAL FLAG
            'source' => 'odoo',
            'destination' => 'prestashop',
            'result' => $result,
            'original_payload' => $payload
        ];

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Short timeout - don't block
        curl_exec($ch);
        curl_close($ch);
    }
}
```

### 4. Order Processor

**File**: `odoo_sales_sync/classes/OdooSalesOrderProcessor.php`

Similar structure to CustomerProcessor, but handles Order entities:

- `createOrder()` - Creates new order from Odoo data
- `updateOrder()` - Updates existing order (status, tracking, notes)
- Maps Odoo order structure to PrestaShop Order model
- Handles order details (products), payments, status history

**Key Challenges**:
- Order creation is complex in PrestaShop (requires validated cart)
- May need to use Order WebService API instead of direct object creation
- Handle product stock updates
- Handle order state transitions

### 5. Address Processor

**File**: `odoo_sales_sync/classes/OdooSalesAddressProcessor.php`

- `createAddress()` - Creates new customer address
- `updateAddress()` - Updates existing address
- Maps Odoo address fields to PrestaShop Address model
- Links address to customer (`id_customer`)

### 6. Coupon Processor

**File**: `odoo_sales_sync/classes/OdooSalesCouponProcessor.php`

- `createCoupon()` - Creates new cart rule (coupon/discount)
- `updateCoupon()` - Updates existing cart rule
- Maps Odoo coupon data to PrestaShop CartRule model
- Handles discount types, conditions, restrictions

---

## Webhook Payload Specification (Incoming)

### Customer/Contact Webhook (Odoo → PrestaShop)

```json
{
  "event_id": "odoo-customer-12345-1699365000",
  "entity_type": "customer",
  "entity_id": 12345,
  "action_type": "updated",
  "timestamp": "2025-11-16T10:30:00Z",
  "source": "odoo",
  "data": {
    "id": 12345,
    "email": "john.doe@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "active": true,
    "newsletter": true,
    "optin": false,
    "company": "Acme Corp",
    "note": "VIP customer"
  }
}
```

### Order Webhook (Odoo → PrestaShop)

```json
{
  "event_id": "odoo-order-5678-1699365000",
  "entity_type": "order",
  "entity_id": 5678,
  "action_type": "updated",
  "timestamp": "2025-11-16T10:30:00Z",
  "source": "odoo",
  "data": {
    "id": 5678,
    "reference": "XKBKNABJK",
    "current_state": 3,
    "tracking_number": "1Z999AA10123456784",
    "note": "Updated shipping info from warehouse"
  }
}
```

### Address Webhook (Odoo → PrestaShop)

```json
{
  "event_id": "odoo-address-9999-1699365000",
  "entity_type": "address",
  "entity_id": 9999,
  "action_type": "created",
  "timestamp": "2025-11-16T10:30:00Z",
  "source": "odoo",
  "data": {
    "id_customer": 12345,
    "alias": "Home",
    "firstname": "John",
    "lastname": "Doe",
    "address1": "123 Main St",
    "address2": "Apt 4B",
    "postcode": "10001",
    "city": "New York",
    "id_country": 21,
    "id_state": 32,
    "phone": "+1234567890"
  }
}
```

### Coupon/Discount Webhook (Odoo → PrestaShop)

```json
{
  "event_id": "odoo-coupon-7777-1699365000",
  "entity_type": "coupon",
  "entity_id": 7777,
  "action_type": "created",
  "timestamp": "2025-11-16T10:30:00Z",
  "source": "odoo",
  "data": {
    "code": "SUMMER2025",
    "name": "Summer Sale 2025",
    "description": "20% off summer collection",
    "reduction_percent": 20.00,
    "reduction_amount": 0.00,
    "reduction_tax": true,
    "quantity": 100,
    "quantity_per_user": 1,
    "date_from": "2025-06-01 00:00:00",
    "date_to": "2025-08-31 23:59:59",
    "active": true
  }
}
```

---

## Database Schema Changes

### New Table: Reverse Operations Tracking

```sql
-- Track all reverse sync operations for debugging and loop prevention
CREATE TABLE IF NOT EXISTS `PREFIX_odoo_sales_reverse_operations` (
  `id_reverse_operation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_id` VARCHAR(64) NOT NULL COMMENT 'Unique operation identifier',
  `entity_type` ENUM('customer','order','address','coupon') NOT NULL,
  `entity_id` INT UNSIGNED NULL COMMENT 'PrestaShop entity ID (after creation)',
  `action_type` ENUM('created','updated','deleted') NOT NULL,
  `source_payload` MEDIUMTEXT NULL COMMENT 'Original webhook payload from Odoo (JSON)',
  `result_data` TEXT NULL COMMENT 'Processing result (JSON)',
  `status` ENUM('processing','success','failed') DEFAULT 'processing',
  `error_message` TEXT NULL,
  `processing_time_ms` INT NULL COMMENT 'Processing duration in milliseconds',
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_reverse_operation`),
  UNIQUE KEY `operation_id` (`operation_id`),
  KEY `entity_lookup` (`entity_type`, `entity_id`),
  KEY `status` (`status`),
  KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tracks reverse sync operations from Odoo';
```

### New Configuration Keys

```sql
-- Add new configuration options
INSERT INTO `PREFIX_configuration` (`name`, `value`) VALUES
('ODOO_SALES_SYNC_REVERSE_ENABLED', '0'),
('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL', 'http://localhost:5000/webhook'),
('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS', ''),  -- Comma-separated IPs
('ODOO_SALES_SYNC_REVERSE_TIMEOUT', '30');     -- Seconds
```

---

## Modified webhook_debug_server.py

The debug server needs to handle the new `reverse_sync` flag:

```python
def display_event_summary(self, event):
    """Display compact event summary (for batch events)"""
    event_id = event.get('event_id', 'N/A')
    entity_type = event.get('entity_type', 'unknown')
    action_type = event.get('action_type', 'unknown')

    # NEW: Check if this is a reverse sync operation
    is_reverse_sync = event.get('reverse_sync', False)
    source = event.get('source', 'prestashop')
    destination = event.get('destination', 'odoo')

    # Display different header for reverse sync
    if is_reverse_sync:
        print(f"   {Colors.WARNING}🔄 REVERSE SYNC{Colors.ENDC}")
        print(f"   Source:       {Colors.OKBLUE}{source}{Colors.ENDC}")
        print(f"   Destination:  {Colors.OKBLUE}{destination}{Colors.ENDC}")

    print(f"   Event ID:     {Colors.WARNING}{event_id}{Colors.ENDC}")
    print(f"   Entity Type:  {Colors.OKBLUE}{entity_type}{Colors.ENDC}")
    print(f"   Action:       {Colors.OKGREEN}{action_type}{Colors.ENDC}")

    # Show result if available
    if 'result' in event:
        result = event['result']
        success = result.get('success', False)
        status_color = Colors.OKGREEN if success else Colors.FAIL
        print(f"   Result:       {status_color}{result.get('message', 'N/A')}{Colors.ENDC}")
```

---

## Configuration UI Updates

Add new section to module configuration page:

```php
// In odoo_sales_sync.php::getConfigurationContent()

array(
    'type' => 'switch',
    'label' => $this->l('Enable Reverse Sync'),
    'name' => 'ODOO_SALES_SYNC_REVERSE_ENABLED',
    'is_bool' => true,
    'desc' => $this->l('Allow Odoo to send updates back to PrestaShop'),
    'values' => array(
        array('id' => 'reverse_on', 'value' => 1, 'label' => $this->l('Yes')),
        array('id' => 'reverse_off', 'value' => 0, 'label' => $this->l('No'))
    )
),
array(
    'type' => 'text',
    'label' => $this->l('Debug Webhook URL'),
    'name' => 'ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL',
    'size' => 64,
    'desc' => $this->l('URL for debug webhook server (e.g., http://localhost:5000/webhook)')
),
array(
    'type' => 'textarea',
    'label' => $this->l('Reverse Sync Endpoint'),
    'name' => 'reverse_webhook_url_display',
    'disabled' => true,
    'readonly' => true,
    'desc' => $this->l('Use this URL in Odoo webhook configuration'),
    'default_value' => _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/odoo_sales_sync/reverse_webhook.php'
),
```

---

## Testing Plan

### Unit Tests

1. **Loop Prevention Test**
   ```php
   testReverseSyncContextPreventsWebhookGeneration()
   testOperationTrackingStoresCorrectData()
   testMultipleOperationsHandledCorrectly()
   ```

2. **Entity Processor Tests**
   ```php
   testCustomerCreationFromOdooPayload()
   testCustomerUpdateFromOdooPayload()
   testOrderStatusUpdateFromOdoo()
   testAddressCreationLinksToCustomer()
   testCouponCreationWithValidData()
   ```

### Integration Tests

1. **End-to-End Flow**
   - Start webhook_debug_server.py
   - Send reverse webhook to PrestaShop
   - Verify entity created/updated
   - Verify NO outgoing webhook sent back to Odoo
   - Verify debug notification received

2. **Loop Prevention Validation**
   - Create customer in PrestaShop → webhook sent to Odoo ✅
   - Odoo sends webhook back → customer updated in PrestaShop ✅
   - PrestaShop does NOT send webhook back to Odoo ✅
   - Debug server shows both events (marked differently) ✅

### Manual Testing Checklist

- [ ] Customer creation from Odoo
- [ ] Customer update from Odoo
- [ ] Order status update from Odoo
- [ ] Order tracking number update from Odoo
- [ ] Address creation from Odoo
- [ ] Coupon creation from Odoo
- [ ] Invalid webhook secret rejected
- [ ] Malformed JSON rejected
- [ ] Unknown entity type handled gracefully
- [ ] Debug server receives all notifications
- [ ] No infinite loops occur
- [ ] Operation tracking table populated correctly

---

## Security Considerations

1. **Webhook Secret Validation**: Always validate `X-Webhook-Secret` header
2. **IP Whitelisting**: Optional IP restriction for reverse webhooks
3. **Request Signature**: Consider adding HMAC signature validation
4. **Rate Limiting**: Prevent abuse by limiting requests per minute
5. **Input Validation**: Sanitize all incoming data before processing
6. **SQL Injection**: Use prepared statements for all database queries
7. **XSS Prevention**: Never output raw payload data without escaping

---

## Performance Considerations

1. **Async Processing**: Consider queuing reverse webhook processing
2. **Batch Operations**: Support batch updates when possible
3. **Database Indexes**: Ensure proper indexing on tracking table
4. **Caching**: Cache configuration values to reduce DB queries
5. **Timeouts**: Set reasonable timeouts for debug notifications
6. **Memory Limits**: Handle large payloads without exhausting memory

---

## Rollback Plan

If reverse sync causes issues:

1. **Disable via Configuration**:
   ```sql
   UPDATE ps_configuration SET value = '0' WHERE name = 'ODOO_SALES_SYNC_REVERSE_ENABLED';
   ```

2. **Remove Endpoint Access**:
   ```bash
   mv reverse_webhook.php reverse_webhook.php.disabled
   ```

3. **Check for Pending Operations**:
   ```sql
   SELECT * FROM ps_odoo_sales_reverse_operations WHERE status = 'processing';
   ```

4. **Review Logs**:
   ```sql
   SELECT * FROM ps_odoo_sales_logs WHERE category = 'reverse_sync' ORDER BY date_add DESC LIMIT 100;
   ```

---

## Implementation Timeline

| Phase | Tasks | Duration | Status |
|-------|-------|----------|--------|
| **Phase 1: Foundation** | Context flag, tracking table, router | 2 days | 📋 Planned |
| **Phase 2: Customer Processor** | Create/update customer logic | 1 day | 📋 Planned |
| **Phase 3: Order Processor** | Order update logic (limited) | 2 days | 📋 Planned |
| **Phase 4: Address Processor** | Address create/update | 1 day | 📋 Planned |
| **Phase 5: Coupon Processor** | Coupon create/update | 1 day | 📋 Planned |
| **Phase 6: Debug Integration** | Modify webhook_debug_server.py | 0.5 days | 📋 Planned |
| **Phase 7: Testing** | Unit + integration tests | 2 days | 📋 Planned |
| **Phase 8: Documentation** | API docs, usage guide | 1 day | 📋 Planned |
| **Total** | | **10.5 days** | |

---

## Success Criteria

- ✅ Reverse webhooks successfully create/update all entity types
- ✅ Zero infinite loops detected during testing
- ✅ All operations tracked in database
- ✅ Debug server receives all notifications with correct flags
- ✅ Configuration UI allows enabling/disabling reverse sync
- ✅ Comprehensive error handling and logging
- ✅ API documentation complete
- ✅ All tests passing

---

## Next Steps

1. **Review and Approval**: Review this plan with stakeholders
2. **Create Feature Branch**: `git checkout -b feature/reverse-sync-v2.0.0`
3. **Start Phase 1**: Implement foundation components
4. **Iterative Development**: Build and test each phase
5. **Documentation**: Update README and API docs
6. **Release**: Merge to main and tag v2.0.0

---

**Document Status**: 📋 Ready for Implementation
**Estimated Completion**: 10.5 working days
**Risk Level**: Medium (complex logic, loop prevention critical)
**Priority**: High (enables bi-directional sync)

---

## Questions/Clarifications Needed

1. **Order Creation**: Should we support full order creation from Odoo, or only updates?
   - Recommendation: Start with updates only (status, tracking, notes)
   - Full creation can be Phase 2 enhancement

2. **Customer Password**: When creating customer from Odoo, how to handle password?
   - Recommendation: Generate random password, send reset email

3. **Conflict Resolution**: If entity exists in both systems with different data, which wins?
   - Recommendation: Last-write-wins (timestamp based)

4. **Webhook Retry**: If reverse webhook fails, should Odoo retry?
   - Recommendation: Yes, with exponential backoff

5. **Debug Server**: Should it be required or optional?
   - Recommendation: Optional (gracefully skip if not configured)
