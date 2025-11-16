# Reverse Sync Testing Guide v2.0.0

**Environment**: dev.elultimokoala.com  
**Webhook Secret**: test_secret  
**Reverse Webhook URL**: https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php

---

## 🚀 Quick Start Tests

### Test 1: Create Customer

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-customer-001",
    "entity_type": "customer",
    "action_type": "created",
    "data": {
      "email": "testcustomer@example.com",
      "firstname": "John",
      "lastname": "Doe"
    }
  }'
```

**Expected**: HTTP 200, `{"success": true, "entity_id": <id>, "message": "Customer created successfully"}`

---

### Test 2: Update Customer

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-customer-002",
    "entity_type": "customer",
    "action_type": "updated",
    "data": {
      "email": "testcustomer@example.com",
      "firstname": "Jane",
      "newsletter": true
    }
  }'
```

---

### Test 3: Create Address

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-address-001",
    "entity_type": "address",
    "action_type": "created",
    "data": {
      "id_customer": 1,
      "alias": "Home",
      "firstname": "John",
      "lastname": "Doe",
      "address1": "123 Main Street",
      "postcode": "28001",
      "city": "Madrid",
      "id_country": 6,
      "phone": "+34600123456"
    }
  }'
```

**Note**: Replace `id_customer` with actual customer ID from PrestaShop.  
**Spain Country ID**: 6

---

### Test 4: Update Order Status

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-order-001",
    "entity_type": "order",
    "action_type": "updated",
    "data": {
      "id": 1,
      "id_order_state": 3,
      "tracking_number": "TRACK123456"
    }
  }'
```

**Note**: Replace `id` with actual order ID. Common states: 2=Payment accepted, 3=Processing, 4=Shipped

---

### Test 5: Create Coupon (Percentage)

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-coupon-001",
    "entity_type": "coupon",
    "action_type": "created",
    "data": {
      "code": "TESTDISCOUNT10",
      "name": "Test 10% Discount",
      "reduction_percent": 10,
      "date_from": "2025-01-01 00:00:00",
      "date_to": "2025-12-31 23:59:59",
      "quantity": 100,
      "active": true
    }
  }'
```

---

### Test 6: Create Coupon (Fixed Amount)

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "event_id": "test-coupon-002",
    "entity_type": "coupon",
    "action_type": "created",
    "data": {
      "code": "FIXED5EUR",
      "name": "5 EUR Discount",
      "reduction_amount": 5.00,
      "reduction_tax": false,
      "reduction_currency": 1,
      "date_from": "2025-01-01 00:00:00",
      "date_to": "2025-12-31 23:59:59",
      "quantity": 50,
      "active": true
    }
  }'
```

---

## ❌ Error Tests

### Invalid Secret

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: wrong_secret" \
  -d '{"entity_type": "customer", "action_type": "created", "data": {}}'
```

**Expected**: HTTP 403, `{"success": false, "error": "Invalid webhook secret"}`

---

### Missing Email

```bash
curl -X POST https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: test_secret" \
  -d '{
    "entity_type": "customer",
    "action_type": "created",
    "data": {"firstname": "John"}
  }'
```

**Expected**: HTTP 200, `{"success": false, "error": "Email is required for customer creation"}`

---

## 🔍 Verification

### Check Database

```sql
-- View all reverse operations
SELECT operation_id, entity_type, entity_id, action_type, status, processing_time_ms, date_add
FROM ps_odoo_sales_reverse_operations
ORDER BY date_add DESC
LIMIT 20;

-- Check failed operations
SELECT operation_id, entity_type, error_message, date_add
FROM ps_odoo_sales_reverse_operations
WHERE status = 'failed';

-- Statistics
SELECT entity_type, status, COUNT(*) as count, AVG(processing_time_ms) as avg_ms
FROM ps_odoo_sales_reverse_operations
GROUP BY entity_type, status;
```

### Check PrestaShop Admin

1. **Customers**: Customers > Customers
2. **Addresses**: Customers > Addresses  
3. **Orders**: Orders > Orders
4. **Coupons**: Catalog > Discounts > Cart Rules

---

## 🔒 Configuration

1. Go to: Modules > Odoo Sales Sync > Configure
2. Ensure:
   - ✅ Enable Reverse Sync: **Yes**
   - ✅ Webhook Secret: `test_secret`
   - ✅ Debug Webhook URL: (optional, for local debug server)

---

## 🐛 Debug Server

```bash
cd odoo_sales_sync
python webhook_debug_server.py --port 8000
```

Configure Debug Webhook URL: `http://localhost:8000/webhook`

**What to verify:**
- 🔄 Reverse sync events shown in cyan
- Flow: `odoo → prestashop`
- Result status: ✓ or ✗

---

## ✅ Loop Prevention Test

**CRITICAL**: Verify reverse sync does NOT trigger outgoing webhooks

1. Send customer update from Odoo (using test above)
2. Check logs: `tail -f modules/odoo_sales_sync/var/logs/odoo_sales_sync_reverse_sync.log`
3. Should see: `[LOOP_PREVENTION] Skipping customer event`
4. NO webhook should be sent back to Odoo

---

## 📊 Expected Performance

- Customer create: 30-60ms
- Customer update: 25-45ms  
- Address create: 35-65ms
- Order update: 40-70ms
- Coupon create: 45-80ms

---

**Version**: 2.0.0  
**Last Updated**: 2025-11-16  
**Status**: Ready for Testing
