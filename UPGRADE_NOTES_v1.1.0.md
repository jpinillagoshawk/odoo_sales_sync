# Upgrade Notes: odoo_sales_sync v1.1.0

**Release Date**: 2025-11-15
**Previous Version**: 1.0.0
**New Version**: 1.1.0

---

## Overview

This release significantly enhances the order webhook payload with comprehensive, in-depth data for all order-related events. The module now sends complete order information including detailed product data, order history, payment records, and customer messages.

---

## What's New

### ✨ Enhanced Order Data Capture

#### 1. **Complete Product Information (30+ Fields per Product)**

Previously sent 15 fields per product line. Now sends **30+ fields** including:

**NEW Product Fields**:
- `product_upc`, `product_isbn` - Additional product codes
- `product_quantity_in_stock` - Stock level at order time
- `product_quantity_refunded` - Refunded quantity
- `product_quantity_return` - Returned quantity
- `product_quantity_reinjected` - Reinjected to stock
- `product_quantity_remaining` - **Calculated**: Quantity not yet fulfilled
- `original_product_price` - Base unit price (important for Odoo)
- `product_tax` - **Calculated**: VAT amount
- `tax_rate` - Tax percentage
- `group_reduction` - Group-specific discount
- `ecotax`, `ecotax_tax_rate` - Eco-tax information
- `discount_quantity_applied` - Discount application details
- `download_hash`, `download_deadline` - Virtual product downloads
- `customization`, `id_customization` - Product customizations

**Enhanced Pricing Fields**:
- Better separation of tax-included vs tax-excluded amounts
- Calculated `product_tax` for easy Odoo import
- Complete discount breakdown (amount + percentage)

#### 2. **Order Status History** 📜

**NEW Feature**: Complete order status history

```json
"order_history": [
  {
    "id_order_history": 1,
    "id_order_state": 1,
    "status_name": "Awaiting payment",
    "id_employee": 0,
    "date_add": "2025-11-15 14:30:00"
  },
  {
    "id_order_history": 2,
    "id_order_state": 2,
    "status_name": "Payment accepted",
    "id_employee": 1,
    "date_add": "2025-11-15 14:31:00"
  }
]
```

**Benefits**:
- ✅ Complete audit trail for migration
- ✅ Employee tracking for status changes
- ✅ Localized status names
- ✅ Timestamp for each change

#### 3. **Order Payment Records** 💳

**NEW Feature**: Complete payment records

```json
"order_payments": [
  {
    "id_order_payment": 1,
    "order_reference": "XKBKNABJK",
    "payment_method": "Credit Card",
    "amount": 150.00,
    "transaction_id": "ch_3N1234567890",
    "card_number": "XXXX-XXXX-XXXX-1234",
    "card_brand": "Visa",
    "card_expiration": "12/2027",
    "card_holder": "John Doe",
    "date_add": "2025-11-15 14:31:00",
    "conversion_rate": 1.0
  }
]
```

**Benefits**:
- ✅ Track multiple payments per order
- ✅ Transaction ID for payment reconciliation
- ✅ Card details (already masked by PrestaShop)
- ✅ Payment timestamps

#### 4. **Customer Messages** 💬

**NEW Feature**: Customer notes and messages

```json
"messages": [
  {
    "id_message": 1,
    "id_customer": 67,
    "message": "Please gift wrap this order",
    "private": false,
    "date_add": "2025-11-15 14:29:00"
  }
]
```

**Benefits**:
- ✅ Customer-facing messages (from `ps_message`)
- ✅ Private/public flag
- ✅ Important for customer service in Odoo

#### 5. **Internal Notes**

**NEW Feature**: Internal/private order notes

```json
"note": "Handle with care - fragile items"
```

**Benefits**:
- ✅ Internal notes from `ps_order.note`
- ✅ Not visible to customer
- ✅ Important for fulfillment teams

#### 6. **Enhanced Order Header Fields**

**NEW Fields**:
- `total_products`, `total_products_wt` - Product totals
- `total_discounts_tax_incl`, `total_discounts_tax_excl` - Discount amounts
- `total_shipping_tax_incl`, `total_shipping_tax_excl` - Shipping amounts
- `total_wrapping_tax_incl`, `total_wrapping_tax_excl` - Gift wrapping
- `id_carrier`, `shipping_number` - Carrier and tracking
- `id_currency`, `conversion_rate` - Currency details
- `module` - Payment module name

---

## Technical Changes

### Modified Files

1. **`classes/OdooSalesEventDetector.php`**
   - ✅ Enhanced `extractOrderLines()` method (30+ fields per product)
   - ✅ Added `extractOrderHistory()` method
   - ✅ Added `extractOrderPayments()` method
   - ✅ Added `extractOrderMessages()` method
   - ✅ Updated `detectOrderChange()` to include all new data

### New Files

1. **`WEBHOOK_PAYLOAD_SPECIFICATION.md`**
   - Complete webhook payload documentation
   - Field mapping reference
   - Comparison with requirements
   - Testing procedures

2. **`UPGRADE_NOTES_v1.1.0.md`** (this file)
   - Upgrade instructions
   - Breaking changes (none!)
   - New features overview

---

## Upgrade Instructions

### Automatic Upgrade (Recommended)

This is a **non-breaking upgrade**. No database changes required.

1. **Backup your current module**:
```bash
cd /path/to/prestashop/modules
cp -r odoo_sales_sync odoo_sales_sync.backup.v1.0.0
```

2. **Replace module files**:
```bash
cp -r /path/to/new/odoo_sales_sync/* /path/to/prestashop/modules/odoo_sales_sync/
```

3. **Clear PrestaShop cache**:
```bash
# Via Back Office
Advanced Parameters > Performance > Clear Cache

# Via command line
rm -rf /path/to/prestashop/var/cache/*
```

4. **Verify upgrade**:
   - Go to Modules > Module Manager
   - Search for "Odoo Sales Sync"
   - Verify version shows **1.1.0**

5. **Test webhook payload**:
   - Create a test order
   - Check webhook receiver logs
   - Verify enhanced data is present

### No Database Migration Required ✅

This upgrade does **NOT** require:
- ❌ Database schema changes
- ❌ Module reinstallation
- ❌ Configuration changes
- ❌ Data migration

---

## Breaking Changes

### None! 🎉

This is a **fully backward-compatible** upgrade:
- ✅ All existing webhook fields remain unchanged
- ✅ New fields are **additions** only
- ✅ No fields removed or renamed
- ✅ No configuration changes required
- ✅ Existing Odoo integrations will continue to work

**Your existing Odoo webhook handler will continue to receive all the data it expects**, plus additional fields that can be safely ignored until you're ready to use them.

---

## Migration Benefits

### For Data Migration Projects

This upgrade is **specifically designed** for PrestaShop → Odoo migration scenarios:

#### Before v1.1.0 (Problems)
- ❌ Order history missing → No audit trail
- ❌ Payment records missing → Manual payment reconciliation
- ❌ Customer messages missing → Lost customer communication context
- ❌ Internal notes missing → Fulfillment team lacks context
- ❌ Limited product data → Missing variant/customization details

#### After v1.1.0 (Solutions)
- ✅ Complete order history → Full audit trail preserved
- ✅ All payment records → Automatic payment reconciliation
- ✅ Customer messages → Customer service context preserved
- ✅ Internal notes → Fulfillment context preserved
- ✅ Complete product data → Variants, customizations, tax details

---

## Testing Recommendations

### 1. Test Order Creation

Create test order with:
- [x] Multiple products (different variants)
- [x] Discount code applied
- [x] Customer message ("Please gift wrap")
- [x] Internal note (add in back office)
- [x] Multiple status changes

**Verify webhook includes**:
- [x] All product details (30+ fields per line)
- [x] Complete order_history array
- [x] Payment record in order_payments array
- [x] Customer message in messages array
- [x] Internal note in note field

### 2. Test Order Update

Update existing order:
- [x] Add product line
- [x] Change order status
- [x] Add payment

**Verify webhook includes**:
- [x] Updated order_details array
- [x] Updated order_history array (new status entry)
- [x] Updated order_payments array

### 3. Test Status Change

Change order status multiple times:
- [x] Payment accepted → Processing
- [x] Processing → Shipped
- [x] Shipped → Delivered

**Verify**:
- [x] Each status change creates webhook
- [x] order_history array grows with each change
- [x] Context includes new_status_id, id_employee

---

## Performance Considerations

### Payload Size Increase

**Before v1.1.0**:
- Typical order payload: ~2-5 KB

**After v1.1.0**:
- Typical order payload: ~8-15 KB

**Why the increase?**
- Order history (2-5 records): +1 KB
- Payment records (1-2 records): +0.5 KB
- Customer messages (0-3 records): +0.5 KB
- Enhanced product fields: +3-5 KB

**Mitigation**:
- ✅ Product lines limited to 100 (prevents huge orders)
- ✅ Message content not truncated (usually short)
- ✅ All data essential for migration

### Database Impact

**No database changes**, so no performance impact on PrestaShop database.

---

## Rollback Instructions

If you need to rollback to v1.0.0:

```bash
# Stop module
cd /path/to/prestashop/modules
mv odoo_sales_sync odoo_sales_sync.v1.1.0

# Restore backup
mv odoo_sales_sync.backup.v1.0.0 odoo_sales_sync

# Clear cache
rm -rf /path/to/prestashop/var/cache/*
```

**Note**: Since there are no database changes, rollback is safe and instant.

---

## Next Steps

After upgrading:

1. **Update Odoo webhook handler** (optional)
   - Add support for new fields
   - Implement order history import
   - Implement payment record import
   - Implement customer message import

2. **Test migration workflow**
   - Export historical orders
   - Verify complete data in Odoo
   - Validate payment reconciliation

3. **Monitor logs**
   - Check `ps_odoo_sales_logs` for any warnings
   - Verify all orders sync successfully
   - Monitor payload sizes

---

## Support

### Documentation

- **Webhook Payload Spec**: [WEBHOOK_PAYLOAD_SPECIFICATION.md](WEBHOOK_PAYLOAD_SPECIFICATION.md)
- **Implementation Guide**: [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)
- **Testing Guide**: [TESTING_GUIDE.md](TESTING_GUIDE.md)

### Troubleshooting

**Issue**: Webhooks not sending enhanced data

**Solution**:
1. Verify module version: Back Office > Modules > Odoo Sales Sync
2. Clear PrestaShop cache
3. Check logs: `SELECT * FROM ps_odoo_sales_logs ORDER BY date_add DESC LIMIT 10`
4. Enable debug mode in module configuration

**Issue**: Payload too large

**Solution**:
- Check for orders with >100 product lines (these are logged as warnings)
- Consider splitting large orders in PrestaShop before sync

---

## Conclusion

Version 1.1.0 is a **major enhancement** for migration scenarios while remaining **100% backward compatible** for existing integrations.

**Upgrade Difficulty**: ⭐ Very Easy (just copy files)
**Risk Level**: 🟢 Low (no breaking changes)
**Migration Value**: 🌟🌟🌟🌟🌟 Extremely High

---

**Status**: ✅ Production-Ready
**Tested**: PrestaShop 8.0.x, 8.1.x, 8.2.x
**Upgrade Time**: < 5 minutes
