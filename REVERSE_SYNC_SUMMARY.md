# Reverse Synchronization - Executive Summary

**Module**: odoo_sales_sync v2.0.0
**Feature**: Bi-directional Webhook Synchronization
**Status**: 📋 Planning Complete - Ready for Implementation

---

## What This Does

Adds **reverse synchronization** capability, allowing Odoo to push data changes back to PrestaShop via webhooks, creating true bi-directional sync.

### Supported Operations

| Entity Type | Create | Update | Delete |
|------------|--------|--------|--------|
| **Customer/Contact** | ✅ | ✅ | ⚠️ Not recommended |
| **Order** | ⚠️ Complex* | ✅ Status, tracking, notes | ⚠️ Not recommended |
| **Address** | ✅ | ✅ | ⚠️ Not recommended |
| **Coupon/Discount** | ✅ | ✅ | ⚠️ Not recommended |

\* Full order creation from Odoo is complex and recommended as Phase 2 enhancement

---

## Critical Feature: Loop Prevention

### The Problem Without Loop Prevention

```
Odoo updates customer → PrestaShop receives webhook → Updates customer
  → PrestaShop fires hook → Sends webhook to Odoo → Odoo receives its own update
    → Sends webhook to PrestaShop → INFINITE LOOP! 🔥
```

### Our Solution: 3-Layer Protection

1. **Reverse Sync Context Flag** - Global flag marks operations as "from Odoo"
2. **Modified Event Detector** - Skips webhook generation when flag is set
3. **Operation Tracking Table** - Database records all reverse operations for debugging

Result: **Zero infinite loops** ✅

---

## Architecture Overview

```
ODOO (External System)
  │
  │ HTTPS POST (reverse webhook)
  ▼
┌─────────────────────────────────────────────────────┐
│ PRESTASHOP                                          │
│                                                      │
│  reverse_webhook.php (new endpoint)                 │
│    │ ✓ Validates webhook secret                     │
│    │ ✓ Parses JSON payload                          │
│    ▼                                                 │
│  OdooSalesReverseWebhookRouter                      │
│    │ ✓ Sets reverse sync flag                       │
│    │ ✓ Routes to entity processor                   │
│    ▼                                                 │
│  Entity Processors:                                 │
│    • CustomerProcessor (create/update customers)    │
│    • OrderProcessor (update order status/tracking)  │
│    • AddressProcessor (create/update addresses)     │
│    • CouponProcessor (create/update coupons)        │
│    │                                                 │
│    │ Uses PrestaShop core classes:                  │
│    │ • Customer::add() / update()                   │
│    │ • Order::update()                              │
│    │ • Address::add() / update()                    │
│    │ • CartRule::add() / update()                   │
│    │                                                 │
│    │ Normally triggers hooks...                     │
│    │ BUT reverse sync flag is set!                  │
│    ▼                                                 │
│  OdooSalesEventDetector (modified)                  │
│    │ Checks: OdooSalesReverseSyncContext::isSet()  │
│    │ → If TRUE: Skip webhook generation ✅          │
│    │ → If FALSE: Generate webhook as normal         │
│    │                                                 │
│    │ Sends notification to debug server:            │
│    ▼                                                 │
│  webhook_debug_server.py (modified)                 │
│    Shows: "🔄 REVERSE SYNC" for these operations    │
└─────────────────────────────────────────────────────┘
```

---

## New Components Created

### PHP Classes (7 new files)

1. **OdooSalesReverseSyncContext.php** - Global context flag for loop prevention
2. **OdooSalesReverseWebhookRouter.php** - Routes incoming webhooks to processors
3. **OdooSalesCustomerProcessor.php** - Handles customer create/update
4. **OdooSalesOrderProcessor.php** - Handles order updates
5. **OdooSalesAddressProcessor.php** - Handles address create/update
6. **OdooSalesCouponProcessor.php** - Handles coupon create/update
7. **OdooSalesReverseOperation.php** - Model for tracking operations

### Endpoints (1 new file)

1. **reverse_webhook.php** - Main entry point for reverse webhooks from Odoo

### Database Tables (1 new table)

1. **ps_odoo_sales_reverse_operations** - Tracks all reverse sync operations

### Modified Files (3 files)

1. **OdooSalesEventDetector.php** - Add loop prevention checks
2. **webhook_debug_server.py** - Handle reverse sync markers
3. **odoo_sales_sync.php** - Add configuration UI for reverse sync

---

## Example Usage

### 1. Configure Reverse Sync in PrestaShop

```
Admin → Odoo Sales Sync → Configuration

✅ Enable Reverse Sync: ON
📝 Debug Webhook URL: http://localhost:5000/webhook
📋 Reverse Sync Endpoint: https://your-shop.com/modules/odoo_sales_sync/reverse_webhook.php
```

### 2. Configure Webhook in Odoo

Point Odoo webhook to:
```
URL: https://your-shop.com/modules/odoo_sales_sync/reverse_webhook.php
Secret: [same as PrestaShop webhook secret]
Method: POST
Content-Type: application/json
```

### 3. Start Debug Server (for monitoring)

```bash
cd odoo_sales_sync
python webhook_debug_server.py --port 5000
```

### 4. Test Customer Update from Odoo

Send POST to reverse_webhook.php:
```json
{
  "event_id": "odoo-customer-123-1699365000",
  "entity_type": "customer",
  "entity_id": 123,
  "action_type": "updated",
  "timestamp": "2025-11-16T10:30:00Z",
  "source": "odoo",
  "data": {
    "id": 67,
    "email": "john.doe@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "newsletter": true
  }
}
```

### 5. Check Debug Server Output

```
🔄 REVERSE SYNC
Source:       odoo
Destination:  prestashop
Entity Type:  customer
Action:       updated
Result:       ✅ Customer updated successfully
```

### 6. Verify No Loop

Check that:
- ✅ Customer updated in PrestaShop
- ✅ Debug server shows ONE reverse sync event
- ✅ NO outgoing webhook sent back to Odoo
- ✅ Database: `ps_odoo_sales_reverse_operations` has record with `status='success'`

---

## API Payload Specifications

### Customer/Contact (Incoming from Odoo)

```json
{
  "event_id": "odoo-customer-123-1699365000",
  "entity_type": "customer",
  "action_type": "updated",
  "data": {
    "id": 67,
    "email": "john.doe@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "active": true,
    "newsletter": true,
    "company": "Acme Corp"
  }
}
```

### Order (Incoming from Odoo)

```json
{
  "event_id": "odoo-order-456-1699365000",
  "entity_type": "order",
  "action_type": "updated",
  "data": {
    "id": 1001,
    "reference": "XKBKNABJK",
    "current_state": 3,
    "tracking_number": "1Z999AA10123456784",
    "note": "Updated from warehouse"
  }
}
```

### Address (Incoming from Odoo)

```json
{
  "event_id": "odoo-address-789-1699365000",
  "entity_type": "address",
  "action_type": "created",
  "data": {
    "id_customer": 67,
    "alias": "Home",
    "firstname": "John",
    "lastname": "Doe",
    "address1": "123 Main St",
    "postcode": "10001",
    "city": "New York",
    "id_country": 21,
    "phone": "+1234567890"
  }
}
```

### Coupon (Incoming from Odoo)

```json
{
  "event_id": "odoo-coupon-999-1699365000",
  "entity_type": "coupon",
  "action_type": "created",
  "data": {
    "code": "SUMMER2025",
    "name": "Summer Sale 2025",
    "reduction_percent": 20.00,
    "quantity": 100,
    "date_from": "2025-06-01 00:00:00",
    "date_to": "2025-08-31 23:59:59",
    "active": true
  }
}
```

---

## Security Features

| Feature | Implementation | Status |
|---------|---------------|--------|
| **Webhook Secret Validation** | Validates `X-Webhook-Secret` header | ✅ Planned |
| **IP Whitelisting** | Optional IP restriction | ✅ Planned |
| **Request Validation** | JSON schema validation | ✅ Planned |
| **Input Sanitization** | All data sanitized before use | ✅ Planned |
| **SQL Injection Prevention** | Prepared statements only | ✅ Planned |
| **Rate Limiting** | Prevent abuse | ⚠️ Optional |

---

## Monitoring & Debugging

### Check Reverse Operations

```sql
-- Show all reverse operations
SELECT
  id_reverse_operation,
  entity_type,
  entity_id,
  action_type,
  status,
  DATE_FORMAT(date_add, '%Y-%m-%d %H:%i:%s') as created_at
FROM ps_odoo_sales_reverse_operations
ORDER BY id_reverse_operation DESC
LIMIT 20;
```

### Check for Failures

```sql
-- Show failed operations
SELECT
  operation_id,
  entity_type,
  error_message,
  source_payload
FROM ps_odoo_sales_reverse_operations
WHERE status = 'failed'
ORDER BY date_add DESC;
```

### Check Logs

```sql
-- Show reverse sync logs
SELECT
  level,
  message,
  context,
  DATE_FORMAT(date_add, '%Y-%m-%d %H:%i:%s') as created_at
FROM ps_odoo_sales_logs
WHERE category = 'reverse_sync'
ORDER BY date_add DESC
LIMIT 50;
```

---

## Implementation Timeline

| Phase | Duration | Key Deliverables |
|-------|----------|------------------|
| **Phase 1: Foundation** | 2 days | Context flag, router, tracking table, SQL schema |
| **Phase 2: Customer Processor** | 1 day | Create/update customer logic |
| **Phase 3: Order Processor** | 2 days | Order status/tracking update logic |
| **Phase 4: Address Processor** | 1 day | Create/update address logic |
| **Phase 5: Coupon Processor** | 1 day | Create/update coupon logic |
| **Phase 6: Debug Integration** | 0.5 days | Modify webhook_debug_server.py |
| **Phase 7: Testing** | 2 days | Unit tests, integration tests, manual QA |
| **Phase 8: Documentation** | 1 day | API docs, README updates |
| **TOTAL** | **10.5 days** | Production-ready v2.0.0 |

---

## Testing Strategy

### Unit Tests
- Loop prevention context flag behavior
- Entity processor create/update logic
- Router entity type routing
- Payload validation

### Integration Tests
- End-to-end customer sync (Odoo → PrestaShop)
- End-to-end order update (Odoo → PrestaShop)
- Loop prevention validation (no infinite loops)
- Debug server notification delivery

### Manual Testing
- [ ] Customer creation from Odoo
- [ ] Customer update from Odoo
- [ ] Order status update from Odoo
- [ ] Address creation from Odoo
- [ ] Coupon creation from Odoo
- [ ] Invalid webhook secret rejected
- [ ] Malformed JSON handled gracefully
- [ ] Debug server shows reverse sync events
- [ ] No infinite loops occur

---

## Success Metrics

- ✅ **Zero infinite loops** during all testing scenarios
- ✅ **100% entity coverage** for supported operations (customer, order, address, coupon)
- ✅ **All operations tracked** in database with success/failure status
- ✅ **Debug server integration** working for all reverse sync events
- ✅ **Comprehensive error handling** with useful error messages
- ✅ **Full documentation** for API and usage

---

## Next Steps

1. **Review Plan** - Stakeholder approval of this plan
2. **Create Branch** - `git checkout -b feature/reverse-sync-v2.0.0`
3. **Phase 1: Foundation** - Implement core infrastructure (2 days)
4. **Phase 2-5: Processors** - Build entity processors (5 days)
5. **Phase 6: Debug** - Modify debug server (0.5 days)
6. **Phase 7: Testing** - Comprehensive testing (2 days)
7. **Phase 8: Docs** - Documentation (1 day)
8. **Release** - Merge and tag v2.0.0

---

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| **Infinite loops** | Medium | Critical | 3-layer loop prevention |
| **Order creation complexity** | High | Medium | Start with updates only |
| **Data conflicts** | Medium | Medium | Last-write-wins strategy |
| **Performance issues** | Low | Medium | Async processing, timeouts |
| **Security vulnerabilities** | Low | High | Multiple security layers |

**Overall Risk Level**: **Medium** (manageable with careful implementation)

---

## Questions to Resolve

1. **Order Creation**: Support full order creation, or only updates?
   - **Recommendation**: Updates only for Phase 1, creation in Phase 2

2. **Customer Password**: How to handle password when creating customer?
   - **Recommendation**: Generate random password, send reset email

3. **Conflict Resolution**: What if entity exists with different data?
   - **Recommendation**: Last-write-wins (timestamp based)

4. **Retry Logic**: Should Odoo retry failed webhooks?
   - **Recommendation**: Yes, with exponential backoff (2s, 4s, 8s, 16s, 32s)

5. **Debug Server**: Required or optional?
   - **Recommendation**: Optional (gracefully skip if not configured)

---

## Files to Create/Modify

### New Files (9)
1. `classes/OdooSalesReverseSyncContext.php`
2. `classes/OdooSalesReverseWebhookRouter.php`
3. `classes/OdooSalesCustomerProcessor.php`
4. `classes/OdooSalesOrderProcessor.php`
5. `classes/OdooSalesAddressProcessor.php`
6. `classes/OdooSalesCouponProcessor.php`
7. `classes/OdooSalesReverseOperation.php`
8. `reverse_webhook.php`
9. `sql/reverse_sync_install.sql`

### Modified Files (4)
1. `classes/OdooSalesEventDetector.php` - Add loop prevention
2. `webhook_debug_server.py` - Handle reverse sync flag
3. `odoo_sales_sync.php` - Add config UI
4. `sql/install.sql` - Add new table

---

## Rollback Plan

If issues occur:

1. **Disable via Config**: Set `ODOO_SALES_SYNC_REVERSE_ENABLED = 0`
2. **Rename Endpoint**: `mv reverse_webhook.php reverse_webhook.php.disabled`
3. **Check Pending**: Review `ps_odoo_sales_reverse_operations` table
4. **Review Logs**: Check `ps_odoo_sales_logs` for errors
5. **Restore Backup**: Database backup before deployment

---

**Document Status**: ✅ Ready for Implementation
**Estimated Effort**: 10.5 working days
**Priority**: High (enables bi-directional sync)
**Complexity**: Medium-High (loop prevention is critical)

---

## Full Documentation

For complete technical details, see: [REVERSE_SYNC_IMPLEMENTATION_PLAN.md](REVERSE_SYNC_IMPLEMENTATION_PLAN.md)
