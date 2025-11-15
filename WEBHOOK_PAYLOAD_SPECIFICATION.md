# Odoo Sales Sync - Enhanced Webhook Payload Specification

**Version**: 2.0
**Date**: 2025-11-15
**Module**: odoo_sales_sync v1.1.0
**Status**: ✅ Enhanced with Complete Order Data

---

## Overview

This document specifies the complete webhook payload structure sent by the odoo_sales_sync module to Odoo for order-related events. The payload now includes comprehensive order details including:

- Complete product information per order line
- Order status history
- Payment records
- Customer messages
- Internal notes

---

## Order Event Webhook Payload

### Complete Payload Structure

```json
{
  "event_id": 12345,
  "entity_type": "order",
  "entity_id": 1001,
  "entity_name": "XKBKNABJK",
  "action_type": "created",
  "hook_name": "actionValidateOrder",
  "timestamp": "2025-11-15T14:30:00Z",
  "transaction_hash": "order_1001_created_1699365000",
  "correlation_id": "uuid-v4-here",
  "change_summary": "Created order: XKBKNABJK",

  "data": {
    // ========== ORDER HEADER FIELDS ==========
    "id_order": 1001,
    "reference": "XKBKNABJK",
    "date_add": "2025-11-15 14:30:00",
    "date_upd": "2025-11-15 14:30:00",
    "current_state": 2,
    "id_order_state": 2,

    // Customer information
    "id_customer": 67,
    "id_cart": 89,

    // ========== ORDER AMOUNTS ==========
    "total_paid": 150.00,
    "total_paid_tax_incl": 150.00,
    "total_paid_tax_excl": 125.00,
    "total_products": 100.00,
    "total_products_wt": 121.00,
    "total_discounts": 10.00,
    "total_discounts_tax_incl": 12.10,
    "total_discounts_tax_excl": 10.00,
    "total_shipping": 25.00,
    "total_shipping_tax_incl": 30.25,
    "total_shipping_tax_excl": 25.00,
    "total_wrapping": 5.00,
    "total_wrapping_tax_incl": 6.05,
    "total_wrapping_tax_excl": 5.00,

    // ========== PAYMENT INFORMATION ==========
    "payment": "Credit Card",
    "module": "stripe_official",

    // ========== NOTES AND MESSAGES ==========
    "note": "Internal processing notes - handle with care",

    // ========== SHIPPING INFORMATION ==========
    "id_carrier": 2,
    "shipping_number": "1ZE1234567890",

    // ========== CURRENCY ==========
    "id_currency": 1,
    "conversion_rate": 1.0,

    // ========== ORDER DETAILS (PRODUCTS) ==========
    "order_details": [
      {
        // Order detail identifiers
        "id_order_detail": 501,

        // Product identifiers
        "product_id": 5,
        "product_attribute_id": 23,

        // Product display and reference info
        "product_name": "T-Shirt - Blue - Size M",
        "product_reference": "TS-BLUE-M",
        "product_ean13": "1234567890123",
        "product_upc": "",
        "product_isbn": "",

        // Quantity information
        "product_quantity": 2,
        "product_quantity_in_stock": 2,
        "product_quantity_refunded": 0,
        "product_quantity_return": 0,
        "product_quantity_reinjected": 0,
        "product_quantity_remaining": 2,

        // Pricing - Unit prices
        "unit_price_tax_incl": 30.25,
        "unit_price_tax_excl": 25.00,
        "original_product_price": 25.00,

        // Pricing - Total prices
        "total_price_tax_incl": 60.50,
        "total_price_tax_excl": 50.00,

        // Tax information
        "product_tax": 10.50,
        "tax_rate": 21.00,

        // Discounts and reductions
        "reduction_percent": 0.00,
        "reduction_amount": 0.00,
        "reduction_amount_tax_incl": 0.00,
        "reduction_amount_tax_excl": 0.00,
        "group_reduction": 0.00,

        // Additional product attributes
        "product_weight": 0.250,
        "ecotax": 0.00,
        "ecotax_tax_rate": 0.00,

        // Discount details
        "discount_quantity_applied": 0,

        // Download/virtual product info
        "download_hash": null,
        "download_deadline": null,

        // Customization
        "customization": null,
        "id_customization": 0
      }
    ],

    // ========== ORDER STATUS HISTORY ==========
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
    ],

    // ========== ORDER PAYMENTS ==========
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
    ],

    // ========== CUSTOMER MESSAGES ==========
    "messages": [
      {
        "id_message": 1,
        "id_customer": 67,
        "message": "Please gift wrap this order",
        "private": false,
        "date_add": "2025-11-15 14:29:00"
      }
    ]
  },

  "context": {
    "shop_id": 1,
    "language_id": 1,
    "id_employee_context": 1
  }
}
```

---

## Field Mapping Reference

### Order Header Fields

| Webhook Field | PrestaShop Field | Type | Description |
|--------------|------------------|------|-------------|
| `id_order` | `ps_order.id_order` | Integer | Primary identifier |
| `reference` | `ps_order.reference` | String | Order reference number |
| `date_add` | `ps_order.date_add` | DateTime | Creation date/time |
| `date_upd` | `ps_order.date_upd` | DateTime | Last update date/time |
| `current_state` | `ps_order.current_state` | Integer | Current order status ID |
| `id_order_state` | `ps_order.current_state` | Integer | Alias for current_state |
| `note` | `ps_order.note` | Text | Internal/private notes |

### Order Line (Product) Fields

| Webhook Field | PrestaShop Field | Type | Description |
|--------------|------------------|------|-------------|
| `product_name` | `product_name` | String | Full product name as displayed |
| `product_id` | `product_id` | Integer | Base product identifier |
| `product_attribute_id` | `product_attribute_id` | Integer | Variant ID (0 if no variant) |
| `product_reference` | `product_reference` | String | Product SKU/reference |
| `product_quantity` | `product_quantity` | Integer | Ordered quantity |
| `product_quantity_remaining` | Calculated | Integer | Quantity not yet fulfilled/refunded |
| `original_product_price` | `original_product_price` | Decimal | Base unit price excluding tax |
| `total_price_tax_excl` | `total_price_tax_excl` | Decimal | Line total excluding tax |
| `product_tax` | Calculated | Decimal | VAT amount (total_incl - total_excl) |
| `reduction_percent` | `reduction_percent` | Decimal | Percentage discount applied |

### Order History Fields

| Webhook Field | PrestaShop Field | Type | Description |
|--------------|------------------|------|-------------|
| `id_order_history` | `ps_order_history.id_order_history` | Integer | History record ID |
| `id_order_state` | `ps_order_history.id_order_state` | Integer | Status ID |
| `status_name` | `ps_order_state_lang.name` | String | Status name (localized) |
| `id_employee` | `ps_order_history.id_employee` | Integer | Employee who made change |
| `date_add` | `ps_order_history.date_add` | DateTime | Change timestamp |

### Order Payment Fields

| Webhook Field | PrestaShop Field | Type | Description |
|--------------|------------------|------|-------------|
| `id_order_payment` | `ps_order_payment.id_order_payment` | Integer | Payment record ID |
| `order_reference` | `ps_order_payment.order_reference` | String | Order reference |
| `payment_method` | `ps_order_payment.payment_method` | String | Payment method name |
| `amount` | `ps_order_payment.amount` | Decimal | Payment amount |
| `transaction_id` | `ps_order_payment.transaction_id` | String | External transaction ID |
| `date_add` | `ps_order_payment.date_add` | DateTime | Payment timestamp |

---

## Event Types

### Order Created

**Hook**: `actionValidateOrder`
**Action Type**: `created`
**Description**: Fired when a new order is validated

**Payload Includes**:
- All order header fields
- Complete order_details array with all products
- Initial order_history entry (first status)
- order_payments array (may be empty if payment pending)
- messages array (customer notes from cart)

---

### Order Updated

**Hook**: `actionObjectOrderUpdateAfter`, `actionOrderEdited`
**Action Type**: `updated`
**Description**: Fired when order is modified

**Payload Includes**:
- All order header fields (updated values)
- Complete order_details array (may include new/removed/modified lines)
- Complete order_history array
- Complete order_payments array
- Complete messages array

---

### Order Status Changed

**Hook**: `actionOrderStatusUpdate`
**Action Type**: `status_changed`
**Description**: Fired when order status changes

**Payload Includes**:
- All standard order data
- Additional context field with status change details:

```json
"context": {
  "new_status_id": 3,
  "new_status_name": "Processing in progress",
  "id_employee": 1,
  "id_employee_context": 1
}
```

**Note**: New status will also appear as latest entry in `order_history` array

---

## Usage Notes

### For Migration

When using this webhook for data migration, the following fields are particularly important:

1. **Order History**: Complete status history for audit trail
2. **Messages**: Customer-facing notes and communications
3. **Note**: Internal/private notes not visible to customer
4. **Order Payments**: Payment records with transaction IDs

### For Real-time Sync

For real-time synchronization, focus on:

1. **order_details**: Product quantities and remaining quantities
2. **order_history**: Latest status change
3. **order_payments**: New payments added
4. **current_state**: Current order status

### Data Optimization

- **Product lines limited to 100**: Orders with >100 lines will be truncated (logged as warning)
- **All monetary values**: Decimals with 2 decimal places
- **All IDs**: Integers
- **Card numbers**: Already masked by PrestaShop (XXXX-XXXX-XXXX-1234)

---

## Comparison with Requirements

### Required Fields ✅ All Implemented

| Requirement | Implementation | Status |
|------------|----------------|--------|
| Order ID | `id_order` | ✅ |
| Order Reference | `reference` | ✅ |
| Creation DateTime | `date_add` | ✅ |
| Order Status | `current_state`, `id_order_state` | ✅ |
| Status History | `order_history` array | ✅ |
| Order Payments | `order_payments` array | ✅ |
| Customer Notes | `messages` array | ✅ |
| Internal Notes | `note` field | ✅ |
| Employee (Status Change) | `id_employee` in history + context | ✅ |
| Product Name | `product_name` in order_details | ✅ |
| Product ID | `product_id` in order_details | ✅ |
| Variant ID | `product_attribute_id` in order_details | ✅ |
| Product Reference | `product_reference` in order_details | ✅ |
| Quantity | `product_quantity` in order_details | ✅ |
| Remaining Quantity | `product_quantity_remaining` in order_details | ✅ |
| Unit Price (excl. VAT) | `original_product_price` in order_details | ✅ |
| Total Price (excl. VAT) | `total_price_tax_excl` in order_details | ✅ |
| VAT Amount | `product_tax` in order_details | ✅ |
| Line Discount % | `reduction_percent` in order_details | ✅ |

---

## Version History

### Version 2.0 (2025-11-15)

**Enhancements**:
- ✅ Added complete order product details (30+ fields per line)
- ✅ Added `extractOrderHistory()` method
- ✅ Added `extractOrderPayments()` method
- ✅ Added `extractOrderMessages()` method
- ✅ Enhanced `extractOrderLines()` with all product attributes
- ✅ Added `order_quantity_remaining` calculation
- ✅ Added support for product variants, customizations, downloads
- ✅ Added comprehensive order header fields (shipping, wrapping, etc.)
- ✅ All fields match webhook requirements specification

### Version 1.0 (2025-11-09)

**Initial Implementation**:
- Basic order data capture
- Basic product lines (15 fields)
- No order history
- No payment records
- No customer messages

---

## Testing

### Validate Complete Payload

To test the enhanced webhook payload:

1. **Start webhook receiver**:
```bash
python3 odoo_sales_sync/debug_webhook_receiver.py --port 5000
```

2. **Create test order** with:
   - Multiple products
   - Product with variant
   - Discount applied
   - Customer message
   - Internal note

3. **Verify webhook payload** includes:
   - ✅ All order header fields
   - ✅ Complete order_details array with 30+ fields per product
   - ✅ order_history array with status records
   - ✅ order_payments array (if payment completed)
   - ✅ messages array (customer note)
   - ✅ note field (internal note)

4. **Change order status** and verify:
   - ✅ Updated order_history array
   - ✅ Context includes new_status_id, new_status_name, id_employee

---

## Support

For questions or issues with the webhook payload structure:

1. Check logs: `SELECT * FROM ps_odoo_sales_logs WHERE level = 'error'`
2. Check event data: `SELECT after_data FROM ps_odoo_sales_events WHERE id_event = X`
3. Enable debug mode in module configuration for verbose logging

---

**Document Status**: ✅ Production-Ready
**Module Version**: 1.1.0
**Last Updated**: 2025-11-15
