# Odoo Sales Sync Module - Testing Guide

**Version**: 1.0.0
**Date**: 2025-11-07
**Status**: Production-Ready

---

## Table of Contents

1. [Overview](#overview)
2. [Test Environment Setup](#test-environment-setup)
3. [Test Categories](#test-categories)
4. [Critical Test: Coupon Usage Flow](#critical-test-coupon-usage-flow)
5. [Critical Test: Address Change Detection](#critical-test-address-change-detection)
6. [Automated Test Script](#automated-test-script)
7. [Manual Test Checklist](#manual-test-checklist)
8. [Troubleshooting Tests](#troubleshooting-tests)

---

## Overview

This guide provides comprehensive testing procedures for the Odoo Sales Sync module. The module tracks **23 different PrestaShop hooks** across 5 categories:

- **Customer hooks**: 5
- **Address hooks**: 3 (NEW)
- **Order hooks**: 4
- **Invoice hooks**: 4
- **Coupon/Discount hooks**: 7

### Critical Areas to Test

1. **Coupon Usage Tracking**: Uses snapshot diffing workaround (no native PrestaShop hook exists)
2. **Address Change Normalization**: Address events are normalized to customer updates
3. **Deduplication**: Prevents duplicate events when multiple hooks fire for same entity
4. **Retry Logic**: Failed webhooks should retry with exponential backoff

---

## Test Environment Setup

### Prerequisites

- PrestaShop 8.x instance (8.0.x, 8.1.x, or 8.2.x)
- Python 3.6+ (for webhook receiver)
- Module installed and enabled
- Test customer account
- Test products in catalog
- Test voucher codes created

### Step 1: Install Module

1. Copy `odoo_sales_sync` folder to `modules/` directory
2. Go to Back Office > Modules > Module Manager
3. Search for "Odoo Sales Sync"
4. Click "Install"
5. Verify installation success

### Step 2: Start Webhook Receiver

```bash
cd odoo_sales_sync_implementation
python3 debug_webhook_receiver.py --port 5000 --secret test_secret_123
```

Expected output:
```
================================================================================
   Odoo Sales Sync - Debug Webhook Receiver
================================================================================

✓ Server running on port 5000
✓ Webhook endpoint: http://localhost:5000/webhook
✓ Health check: http://localhost:5000/health
⚠ Secret validation: ENABLED (secret: test_secret_123)

Configure PrestaShop module with:
   Webhook URL: http://localhost:5000/webhook
   Webhook Secret: test_secret_123
```

### Step 3: Configure Module

1. Go to Back Office > Modules > Odoo Sales Sync > Configure
2. Set configuration:
   - **Enable Sync**: Yes
   - **Webhook URL**: `http://localhost:5000/webhook`
   - **Webhook Secret**: `test_secret_123`
   - **Debug Mode**: Yes
3. Click "Test Connection"
4. Verify success message
5. Save settings

### Step 4: Verify Database Tables

Run SQL query:
```sql
SHOW TABLES LIKE 'ps_odoo_sales_%';
```

Expected result (4 tables):
```
ps_odoo_sales_events
ps_odoo_sales_logs
ps_odoo_sales_dedup
ps_odoo_sales_cart_rule_state
```

---

## Test Categories

### Category 1: Customer Lifecycle Tests

**Objective**: Verify all customer create/update/delete events are tracked.

#### Test 1.1: Customer Registration

**Steps**:
1. Go to front office
2. Click "Sign in" > "No account? Create one here"
3. Fill registration form:
   - Email: `test@example.com`
   - First name: `John`
   - Last name: `Doe`
   - Password: `Test123!`
4. Submit form

**Expected Webhooks**: 2 events
```json
// Event 1: actionCustomerAccountAdd
{
  "entity_type": "customer",
  "action_type": "created",
  "hook_name": "actionCustomerAccountAdd"
}

// Event 2: actionObjectCustomerAddAfter
{
  "entity_type": "customer",
  "action_type": "created",
  "hook_name": "actionObjectCustomerAddAfter"
}
```

**Verification**:
- [ ] 2 webhooks received
- [ ] Both have same `entity_id` (customer ID)
- [ ] Deduplication prevents duplicate in database
- [ ] `ps_odoo_sales_events` has 1 row (deduplicated)

---

#### Test 1.2: Customer Login

**Steps**:
1. Log out if logged in
2. Click "Sign in"
3. Enter credentials: `test@example.com` / `Test123!`
4. Submit

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "updated",
  "hook_name": "actionAuthentication"
}
```

---

#### Test 1.3: Customer Profile Update

**Steps**:
1. Log in as customer
2. Go to "My Account" > "Information"
3. Change first name to "Jane"
4. Save

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "updated",
  "hook_name": "actionObjectCustomerUpdateAfter",
  "data": {
    "firstname": "Jane"
  }
}
```

---

#### Test 1.4: Customer Deletion (Admin)

**Steps**:
1. Go to Back Office > Customers > Customers
2. Find test customer
3. Click dropdown > "Delete"
4. Confirm deletion

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "deleted",
  "hook_name": "actionObjectCustomerDeleteAfter"
}
```

---

### Category 2: Address Change Tests (NEW)

**Objective**: Verify address changes are normalized to customer update events.

#### Test 2.1: Add New Address

**Steps**:
1. Log in as customer
2. Go to "My Account" > "Addresses"
3. Click "Create new address"
4. Fill form:
   - Alias: `Home`
   - Address: `123 Main St`
   - City: `New York`
   - Postal Code: `10001`
   - Country: `United States`
5. Save

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "updated",
  "hook_name": "actionObjectAddressAddAfter",
  "context": {
    "change_type": "address",
    "address_action": "created",
    "address_alias": "Home"
  }
}
```

**Verification**:
- [ ] Webhook received
- [ ] `entity_type` is "customer" (not "address")
- [ ] `context_data` contains address details
- [ ] Customer name matches logged-in user

---

#### Test 2.2: Update Address

**Steps**:
1. Go to "My Account" > "Addresses"
2. Click "Update" on "Home" address
3. Change city to "Brooklyn"
4. Save

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "updated",
  "hook_name": "actionObjectAddressUpdateAfter",
  "context": {
    "change_type": "address",
    "address_action": "updated",
    "address_alias": "Home"
  }
}
```

---

#### Test 2.3: Delete Address

**Steps**:
1. Go to "My Account" > "Addresses"
2. Click "Delete" on "Home" address
3. Confirm deletion

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "customer",
  "action_type": "updated",
  "hook_name": "actionObjectAddressDeleteAfter",
  "context": {
    "change_type": "address",
    "address_action": "deleted",
    "address_alias": "Home"
  }
}
```

---

### Category 3: Order Lifecycle Tests

**Objective**: Verify order creation, status changes, and edits are tracked.

#### Test 3.1: Complete Order

**Steps**:
1. Add product to cart
2. Proceed to checkout
3. Select shipping method
4. Select payment method (e.g., "Bank wire")
5. Confirm order

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "order",
  "action_type": "created",
  "hook_name": "actionValidateOrder",
  "data": {
    "reference": "XKBKNABJK",
    "total_paid": "25.00"
  }
}
```

---

#### Test 3.2: Order Status Update

**Steps**:
1. Go to Back Office > Orders > Orders
2. Open test order
3. Change status to "Payment accepted"
4. Save

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "order",
  "action_type": "status_changed",
  "hook_name": "actionOrderStatusUpdate",
  "context": {
    "new_status_name": "Payment accepted"
  }
}
```

---

### Category 4: Invoice Tests

**Objective**: Verify invoice creation, PDF generation, and credit memos.

#### Test 4.1: Generate Invoice

**Steps**:
1. Go to Back Office > Orders > Orders
2. Open test order
3. Change status to "Payment accepted" (if not already)
4. Click "Generate invoice"

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "invoice",
  "action_type": "created",
  "hook_name": "actionObjectOrderInvoiceAddAfter"
}
```

---

#### Test 4.2: Download Invoice PDF

**Steps**:
1. Go to Back Office > Orders > Invoices
2. Select test invoice
3. Click "Download PDF"

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "invoice",
  "action_type": "pdf_rendered",
  "hook_name": "actionPDFInvoiceRender"
}
```

**CRITICAL**: This tests the previously missing `actionPDFInvoiceRender` hook.

---

### Category 5: Coupon CRUD Tests

**Objective**: Verify coupon/discount create/update/delete.

#### Test 5.1: Create Voucher

**Steps**:
1. Go to Back Office > Catalog > Discounts > Cart Rules
2. Click "Add new cart rule"
3. Set:
   - Name: `SUMMER2024`
   - Code: `SUMMER2024`
   - Discount: €10
4. Save

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "coupon",
  "action_type": "created",
  "hook_name": "actionObjectCartRuleAddAfter",
  "data": {
    "code": "SUMMER2024",
    "reduction_amount": 10.00
  }
}
```

---

## Critical Test: Coupon Usage Flow

**Objective**: Verify snapshot diffing correctly detects apply/remove/consume events.

**IMPORTANT**: This is the most critical test because PrestaShop doesn't natively fire hooks for `Cart::addCartRule()` or `Cart::removeCartRule()`. Our workaround uses `actionCartSave` + snapshot diffing.

### Setup

1. Create cart rule:
   - Code: `CODE1`
   - Discount: €10 off
2. Create product: `Product A` - €100
3. Create product: `Product B` - €50

### Test Steps

#### Step 1: Create Cart + Add Product

**Actions**:
1. Add `Product A` to cart
2. View cart

**Expected Webhooks**: 0 (cart creation doesn't fire sales events)

---

#### Step 2: Apply Voucher

**Actions**:
1. In cart, enter code `CODE1`
2. Click "Add"

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "coupon",
  "action_type": "applied",
  "hook_name": "actionCartSave_synthetic",
  "context": {
    "usage_action": "applied",
    "cart_id": 123
  }
}
```

**Database Verification**:
```sql
-- Check snapshot was created
SELECT * FROM ps_odoo_sales_cart_rule_state WHERE id_cart = 123;
-- Expected: cart_rule_ids = [<rule_id>]
```

---

#### Step 3: Add Another Product (No Voucher Change)

**Actions**:
1. Add `Product B` to cart
2. View cart

**Expected Webhooks**: 0 (no voucher change, so no event)

**Critical**: This verifies we don't create duplicate events on every cart save.

---

#### Step 4: Remove Voucher

**Actions**:
1. In cart, click "X" to remove `CODE1`

**Expected Webhooks**: 1 event
```json
{
  "entity_type": "coupon",
  "action_type": "removed",
  "hook_name": "actionCartSave_synthetic",
  "context": {
    "usage_action": "removed",
    "cart_id": 123
  }
}
```

---

#### Step 5: Re-apply Voucher

**Actions**:
1. Enter `CODE1` again
2. Click "Add"

**Expected Webhooks**: 1 event (new event, not duplicate)
```json
{
  "entity_type": "coupon",
  "action_type": "applied",
  "hook_name": "actionCartSave_synthetic"
}
```

**Critical**: This is a NEW event (different timestamp), not a duplicate of Step 2.

---

#### Step 6: Complete Order

**Actions**:
1. Proceed to checkout
2. Complete order

**Expected Webhooks**: 2 events
```json
// Event 1: Order created
{
  "entity_type": "order",
  "action_type": "created",
  "hook_name": "actionValidateOrder"
}

// Event 2: Coupon consumed
{
  "entity_type": "coupon",
  "action_type": "consumed",
  "hook_name": "actionCartSave_synthetic",
  "context": {
    "usage_action": "consumed"
  }
}
```

**Database Verification**:
```sql
-- Check snapshot was deleted after order
SELECT * FROM ps_odoo_sales_cart_rule_state WHERE id_cart = 123;
-- Expected: 0 rows (snapshot cleaned up)

-- Check all events recorded
SELECT entity_type, action_type, hook_name
FROM ps_odoo_sales_events
WHERE entity_type = 'coupon'
ORDER BY date_add;
-- Expected: 3 rows (applied, removed, applied-consumed)
```

---

### Expected Final Event Count

For complete coupon flow test:

| Event | Hook | Action |
|-------|------|--------|
| 1 | `actionCartSave_synthetic` | `applied` |
| 2 | `actionCartSave_synthetic` | `removed` |
| 3 | `actionCartSave_synthetic` | `applied` |
| 4 | `actionValidateOrder` | `created` (order) |
| 5 | `actionCartSave_synthetic` | `consumed` |

**Total**: 5 webhooks (1 order + 4 coupon events)

---

## Critical Test: Address Change Detection

**Objective**: Verify address events are normalized to customer updates.

### Test Steps

1. **Create customer** (if not exists)
2. **Add address**:
   - Expected: Customer update event with `context.change_type = "address"`
3. **Update address**:
   - Expected: Customer update event
4. **Delete address**:
   - Expected: Customer update event

### Verification Checklist

- [ ] All address events show `entity_type = "customer"` (not "address")
- [ ] `context_data` contains `change_type = "address"`
- [ ] `context_data` contains `address_id`
- [ ] `context_data` contains `address_action` (created/updated/deleted)
- [ ] Customer ID matches address owner

---

## Automated Test Script

Save as `test_suite.sh`:

```bash
#!/bin/bash
# Odoo Sales Sync - Automated Test Suite

echo "=== ODOO SALES SYNC MODULE TEST SUITE ==="

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Start webhook receiver
echo -e "${YELLOW}Starting webhook receiver...${NC}"
python3 debug_webhook_receiver.py --port 5000 --secret test_secret_123 &
RECEIVER_PID=$!
sleep 2

# Test database tables
echo -e "\n${YELLOW}Test 1: Verifying database tables...${NC}"
mysql -u root -p prestashop -e "SHOW TABLES LIKE 'ps_odoo_sales_%';" > /tmp/tables.txt
TABLE_COUNT=$(wc -l < /tmp/tables.txt)

if [ "$TABLE_COUNT" -eq "5" ]; then
    echo -e "${GREEN}✓ All 4 tables exist${NC}"
else
    echo -e "${RED}✗ Missing tables (found $TABLE_COUNT, expected 5)${NC}"
fi

# Test module enabled
echo -e "\n${YELLOW}Test 2: Checking module status...${NC}"
# Add your PrestaShop CLI command here

# Manual tests notification
echo -e "\n${YELLOW}=== MANUAL TESTS REQUIRED ===${NC}"
echo "Please perform the following tests manually:"
echo "1. Customer registration"
echo "2. Address creation"
echo "3. Coupon apply/remove/consume flow"
echo "4. Order creation"
echo "5. Invoice PDF generation"

echo -e "\n${YELLOW}Webhook receiver is running. Press Ctrl+C to stop.${NC}"

# Wait for user to stop
wait $RECEIVER_PID

echo -e "\n${GREEN}=== TESTS COMPLETE ===${NC}"
```

Make executable:
```bash
chmod +x test_suite.sh
```

---

## Manual Test Checklist

### Pre-Deployment Checklist

- [ ] All 23 hooks registered in database
- [ ] All 4 database tables created
- [ ] Module configuration accessible
- [ ] Test connection succeeds
- [ ] Debug mode logs to `ps_odoo_sales_logs`

### Functional Tests

**Customer**:
- [ ] Customer registration → webhook received
- [ ] Customer login → webhook received
- [ ] Customer profile update → webhook received
- [ ] Customer deletion → webhook received

**Address**:
- [ ] Address creation → customer update webhook
- [ ] Address update → customer update webhook
- [ ] Address deletion → customer update webhook

**Order**:
- [ ] Order creation → webhook received
- [ ] Order status change → webhook received
- [ ] Order edit → webhook received

**Invoice**:
- [ ] Invoice creation → webhook received
- [ ] Invoice PDF download → webhook received
- [ ] Credit memo → webhook received

**Coupon**:
- [ ] Coupon creation → webhook received
- [ ] Coupon update → webhook received
- [ ] Coupon deletion → webhook received
- [ ] Coupon apply to cart → webhook received
- [ ] Coupon remove from cart → webhook received
- [ ] Coupon consumed in order → webhook received

### Deduplication Tests

- [ ] Customer login doesn't create duplicate with registration
- [ ] Multiple cart saves with same vouchers don't create duplicates
- [ ] Retry events don't create duplicates

### Error Handling Tests

- [ ] Invalid webhook URL → error logged, page doesn't break
- [ ] Webhook timeout → retry scheduled
- [ ] Missing entity → logged, no webhook sent

---

## Troubleshooting Tests

### Issue: No Webhooks Received

**Checklist**:
1. Check webhook receiver is running: `curl http://localhost:5000/health`
2. Check module is enabled: Configuration > Enable Sync = Yes
3. Check hooks registered: `SELECT COUNT(*) FROM ps_hook_module WHERE id_module = <module_id>;` (should be 23)
4. Check logs: `SELECT * FROM ps_odoo_sales_logs ORDER BY date_add DESC LIMIT 10;`
5. Enable debug mode in module config
6. Check PrestaShop error logs

### Issue: Duplicate Events

**Checklist**:
1. Check dedup table: `SELECT * FROM ps_odoo_sales_dedup ORDER BY last_seen DESC LIMIT 10;`
2. Verify transaction hash is unique per event
3. Check if dedup window is too short (default: 5 seconds)

### Issue: Coupon Events Not Firing

**Checklist**:
1. Verify `actionCartSave` is registered
2. Check snapshot table: `SELECT * FROM ps_odoo_sales_cart_rule_state;`
3. Enable CartRuleUsageTracker debug logging
4. Verify voucher is being added via `Cart::addDiscount()`, not direct DB manipulation

---

## Performance Benchmarks

**Expected Performance**:
- Event detection: < 10ms
- Webhook send: < 100ms (local), < 500ms (remote)
- Database write: < 20ms
- Snapshot diff: < 5ms (typical cart with 0-3 vouchers)

**Load Test**:
- 100 customer registrations → All webhooks received
- 1000 cart saves → No memory leaks
- 50 concurrent orders → No race conditions

---

**Document Version**: 1.0.0
**Last Updated**: 2025-11-07
**Status**: ✅ PRODUCTION-READY
