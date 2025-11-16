# Reverse Sync - Quick Reference Card

## 🎯 What It Does
Odoo → PrestaShop synchronization via webhook (reverse direction)

## 🔄 Supported Entities

| Entity | Create | Update | Key Fields |
|--------|--------|--------|-----------|
| 👤 **Customer** | ✅ | ✅ | email, firstname, lastname, newsletter |
| 📦 **Order** | ⚠️ | ✅ | status, tracking_number, note |
| 📍 **Address** | ✅ | ✅ | address1, city, postcode, country |
| 🎟️ **Coupon** | ✅ | ✅ | code, name, reduction_percent, dates |

⚠️ = Complex, Phase 2

## 🛡️ Loop Prevention (Critical!)

```
┌─────────────────────────────────────────────────────┐
│ WITHOUT Loop Prevention:                            │
│   Odoo → PrestaShop → Hook → Webhook → Odoo        │
│     → PrestaShop → Hook → Webhook → Odoo...        │
│       = INFINITE LOOP! 🔥                           │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│ WITH Loop Prevention (Our Solution):                │
│   Odoo → PrestaShop (Flag: reverse_sync = true)    │
│     → Hook Fired → EventDetector checks flag       │
│       → Flag is TRUE → Skip webhook! ✅             │
│         → No loop! 🎉                               │
└─────────────────────────────────────────────────────┘
```

## 📡 Endpoint Configuration

### PrestaShop Reverse Webhook URL
```
https://your-prestashop.com/modules/odoo_sales_sync/reverse_webhook.php
```

### Required Header
```
X-Webhook-Secret: [your-shared-secret]
```

### Content Type
```
Content-Type: application/json
```

## 📋 Example Payloads

### Customer Update
```json
{
  "event_id": "odoo-customer-123-1699365000",
  "entity_type": "customer",
  "action_type": "updated",
  "data": {
    "id": 67,
    "email": "john@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "newsletter": true
  }
}
```

### Order Update
```json
{
  "event_id": "odoo-order-456-1699365000",
  "entity_type": "order",
  "action_type": "updated",
  "data": {
    "id": 1001,
    "current_state": 3,
    "tracking_number": "1Z999AA10"
  }
}
```

### Address Create
```json
{
  "event_id": "odoo-address-789-1699365000",
  "entity_type": "address",
  "action_type": "created",
  "data": {
    "id_customer": 67,
    "alias": "Home",
    "address1": "123 Main St",
    "city": "New York",
    "postcode": "10001"
  }
}
```

## 🔍 Debugging

### Check Operation Status
```sql
SELECT * FROM ps_odoo_sales_reverse_operations
ORDER BY id_reverse_operation DESC LIMIT 10;
```

### Check Failed Operations
```sql
SELECT operation_id, entity_type, error_message
FROM ps_odoo_sales_reverse_operations
WHERE status = 'failed';
```

### View Logs
```sql
SELECT level, message, context
FROM ps_odoo_sales_logs
WHERE category = 'reverse_sync'
ORDER BY date_add DESC LIMIT 20;
```

### Start Debug Server
```bash
cd odoo_sales_sync
python webhook_debug_server.py --port 5000
```

## ⚙️ Configuration

### Enable Reverse Sync
```
Admin Panel → Odoo Sales Sync → Configuration
✅ Enable Reverse Sync: ON
```

### Debug Webhook URL (Optional)
```
http://localhost:5000/webhook
```

## 🧪 Testing Checklist

- [ ] Customer create from Odoo → PrestaShop
- [ ] Customer update from Odoo → PrestaShop
- [ ] Order status update from Odoo → PrestaShop
- [ ] Address create from Odoo → PrestaShop
- [ ] Coupon create from Odoo → PrestaShop
- [ ] Invalid secret rejected (403 response)
- [ ] Malformed JSON rejected (400 response)
- [ ] Debug server shows events
- [ ] **NO infinite loops** (critical!)

## 🚨 Response Codes

| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | Entity processed ✅ |
| 400 | Bad Request | Check payload format |
| 403 | Forbidden | Check webhook secret |
| 404 | Not Found | Check URL/endpoint |
| 500 | Server Error | Check PrestaShop logs |

## 📊 Success Response
```json
{
  "success": true,
  "entity_id": 67,
  "message": "Customer updated successfully"
}
```

## ❌ Error Response
```json
{
  "success": false,
  "error": "Customer not found"
}
```

## 🔐 Security Checks

1. ✅ Webhook secret validation
2. ✅ Input sanitization
3. ✅ SQL injection prevention (prepared statements)
4. ✅ Request validation (JSON schema)
5. ⚠️ IP whitelisting (optional)
6. ⚠️ Rate limiting (optional)

## 🛠️ Troubleshooting

### Problem: "Invalid webhook secret"
**Solution**: Check `X-Webhook-Secret` header matches PrestaShop config

### Problem: "Customer not found"
**Solution**: Verify customer ID exists, or use email lookup

### Problem: Events going to Odoo in loop
**Solution**: Check `OdooSalesReverseSyncContext` flag is being set

### Problem: Debug server not receiving events
**Solution**: Verify `ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL` is configured

### Problem: "Timeout"
**Solution**: Check PrestaShop server performance, reduce payload size

## 📚 Documentation Links

- **Full Implementation Plan**: [REVERSE_SYNC_IMPLEMENTATION_PLAN.md](REVERSE_SYNC_IMPLEMENTATION_PLAN.md)
- **Executive Summary**: [REVERSE_SYNC_SUMMARY.md](REVERSE_SYNC_SUMMARY.md)
- **Current Webhook Spec**: [WEBHOOK_PAYLOAD_SPECIFICATION.md](WEBHOOK_PAYLOAD_SPECIFICATION.md)

## 🎓 Key Concepts

### Reverse Sync Flag
```php
// Set before processing
OdooSalesReverseSyncContext::markAsReverseSync($operationId);

// Check in hook handlers
if (OdooSalesReverseSyncContext::isReverseSync()) {
    return true; // Skip webhook generation
}

// Always clear after
OdooSalesReverseSyncContext::clear();
```

### Operation Tracking
Every reverse operation is tracked in database:
- operation_id (unique)
- entity_type (customer/order/address/coupon)
- action_type (created/updated)
- status (processing/success/failed)
- source_payload (original JSON)
- error_message (if failed)

### Debug Notification
After processing, sends notification to debug server:
```json
{
  "reverse_sync": true,
  "source": "odoo",
  "destination": "prestashop",
  "result": { "success": true, "entity_id": 67 }
}
```

## ⏱️ Timeline Estimate

| Phase | Duration |
|-------|----------|
| Foundation (context, router, DB) | 2 days |
| Customer processor | 1 day |
| Order processor | 2 days |
| Address processor | 1 day |
| Coupon processor | 1 day |
| Debug integration | 0.5 days |
| Testing | 2 days |
| Documentation | 1 day |
| **TOTAL** | **10.5 days** |

## 🎯 Success Criteria

- ✅ Zero infinite loops
- ✅ All entities supported
- ✅ All operations tracked
- ✅ Debug integration working
- ✅ Comprehensive error handling
- ✅ Full documentation

## 🔄 Version

**Current**: v1.1.0 (outgoing webhooks only)
**Target**: v2.0.0 (bi-directional webhooks)

---

**Last Updated**: 2025-11-16
**Status**: 📋 Planning Complete - Ready for Implementation
