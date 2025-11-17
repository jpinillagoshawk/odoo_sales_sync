# Odoo Sales Sync - Field Reference

Complete reference of all fields detected and synchronized by the module, organized by entity type.

**Legend:**
- 🔄 **Bi-directional**: Field is synchronized both ways (PrestaShop ↔ Odoo)
- ⬆️ **Outbound only**: Field is only sent FROM PrestaShop TO Odoo
- ⬇️ **Inbound only**: Field is only received FROM Odoo TO PrestaShop

---

## 1. CUSTOMER / CONTACT

### 1.1 Customer Fields - Bi-directional 🔄

| Field Name | Technical Name | Direction | Type | Required | Notes |
|------------|----------------|-----------|------|----------|-------|
| Customer ID | `id` | 🔄 | integer | Yes | PrestaShop customer ID |
| Email Address | `email` | 🔄 | string | Yes | Must be valid email format, unique |
| First Name | `firstname` | 🔄 | string | Yes | Customer's first name |
| Last Name | `lastname` | 🔄 | string | Yes | Customer's last name |
| Active Status | `active` | 🔄 | boolean | No | Whether customer account is enabled (default: true) |
| Newsletter Subscription | `newsletter` | 🔄 | boolean | No | Newsletter opt-in status (default: false) |
| Marketing Opt-in | `optin` | 🔄 | boolean | No | General marketing communications opt-in (default: false) |
| Company Name | `company` | 🔄 | string | No | Business/company name if applicable |
| SIRET Number | `siret` | 🔄 | string | No | French business registration number |
| Website | `website` | 🔄 | string | No | Customer's website URL |
| Birthday | `birthday` | 🔄 | date | No | Customer's date of birth (YYYY-MM-DD) |
| Gender ID | `id_gender` | 🔄 | integer | No | 1=Mr, 2=Mrs, 9=Not specified |

### 1.2 Customer Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Default Group ID | `id_default_group` | ⬆️ | integer | Customer's default group in PrestaShop |
| Date Added | `date_add` | ⬆️ | datetime | When customer was created in PrestaShop |
| Date Updated | `date_upd` | ⬆️ | datetime | Last modification timestamp |

### 1.3 Customer Fields - Inbound Only ⬇️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| *(No inbound-only fields)* | - | - | - | All customer fields are bi-directional |

---

## 2. ADDRESS

**NOTE:** Address changes are normalized as **customer update** events in outbound sync.

### 2.1 Address Fields - Bi-directional 🔄

| Field Name | Technical Name | Direction | Type | Required | Notes |
|------------|----------------|-----------|------|----------|-------|
| Address ID | `id` | 🔄 | integer | Yes | PrestaShop address ID |
| Customer ID | `id_customer` | 🔄 | integer | Yes | Owner of this address |
| Address Alias | `alias` | 🔄 | string | Yes | User-friendly name (e.g., "Home", "Office") |
| First Name | `firstname` | 🔄 | string | Yes | Recipient first name |
| Last Name | `lastname` | 🔄 | string | Yes | Recipient last name |
| Company Name | `company` | 🔄 | string | No | Company name at this address |
| Address Line 1 | `address1` | 🔄 | string | Yes | Primary address line |
| Address Line 2 | `address2` | 🔄 | string | No | Secondary address line (apt, suite, etc.) |
| Postal Code | `postcode` | 🔄 | string | Yes* | Required if country requires it |
| City | `city` | 🔄 | string | Yes | City name |
| Country ID | `id_country` | 🔄 | integer | Yes | PrestaShop country ID |
| State/Province ID | `id_state` | 🔄 | integer | Yes* | Required if country has states |
| Phone | `phone` | 🔄 | string | No | Landline phone number |
| Mobile Phone | `phone_mobile` | 🔄 | string | No | Mobile phone number |
| VAT Number | `vat_number` | 🔄 | string | No | Tax identification number |
| DNI | `dni` | 🔄 | string | No | National ID number (Spain, some LATAM) |

### 2.2 Address Context (Metadata)

When an address change is detected, these fields are included in `context_data`:

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Change Type | `change_type` | string | Always "address" |
| Address ID | `address_id` | integer | The address that changed |
| Address Action | `address_action` | string | created/updated/deleted |
| Address Alias | `address_alias` | string | Human-readable address name |

---

## 3. ORDER

### 3.1 Order Header Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Order ID | `id_order` | ⬆️ | integer | PrestaShop order ID |
| Order Reference | `reference` | ⬆️ | string | Unique order reference (e.g., "ABCDEFGHIJ") |
| Order State ID | `current_state` / `id_order_state` | ⬆️ | integer | Current order status ID |
| Customer ID | `id_customer` | ⬆️ | integer | Customer who placed the order |
| Cart ID | `id_cart` | ⬆️ | integer | Shopping cart ID |
| Date Created | `date_add` | ⬆️ | datetime | When order was placed |
| Date Updated | `date_upd` | ⬆️ | datetime | Last modification timestamp |

### 3.2 Order Amount Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Total Paid | `total_paid` | ⬆️ | decimal | Final amount paid by customer |
| Total Paid (Tax Incl) | `total_paid_tax_incl` | ⬆️ | decimal | Total including tax |
| Total Paid (Tax Excl) | `total_paid_tax_excl` | ⬆️ | decimal | Total excluding tax |
| Total Products | `total_products` | ⬆️ | decimal | Products subtotal (no tax) |
| Total Products (Tax Incl) | `total_products_wt` | ⬆️ | decimal | Products subtotal with tax |
| Total Discounts | `total_discounts` | ⬆️ | decimal | Total discounts applied |
| Total Discounts (Tax Incl) | `total_discounts_tax_incl` | ⬆️ | decimal | Discounts with tax impact |
| Total Discounts (Tax Excl) | `total_discounts_tax_excl` | ⬆️ | decimal | Discounts without tax |
| Total Shipping | `total_shipping` | ⬆️ | decimal | Shipping cost |
| Total Shipping (Tax Incl) | `total_shipping_tax_incl` | ⬆️ | decimal | Shipping with tax |
| Total Shipping (Tax Excl) | `total_shipping_tax_excl` | ⬆️ | decimal | Shipping without tax |
| Total Wrapping | `total_wrapping` | ⬆️ | decimal | Gift wrapping cost |
| Total Wrapping (Tax Incl) | `total_wrapping_tax_incl` | ⬆️ | decimal | Wrapping with tax |
| Total Wrapping (Tax Excl) | `total_wrapping_tax_excl` | ⬆️ | decimal | Wrapping without tax |

### 3.3 Order Payment Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Payment Method | `payment` | ⬆️ | string | Payment method name |
| Payment Module | `module` | ⬆️ | string | Technical payment module name |
| Carrier ID | `id_carrier` | ⬆️ | integer | Shipping carrier ID |
| Tracking Number | `shipping_number` | ⬆️ | string | Package tracking number |

### 3.4 Order Detail Lines - Outbound Only ⬆️

Each order includes an array of `order_lines` with:

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Order Detail ID | `id_order_detail` | integer | Line item ID |
| Product ID | `product_id` | integer | PrestaShop product ID |
| Product Attribute ID | `product_attribute_id` | integer | Variation/combination ID (0 if none) |
| Product Name | `product_name` | string | Product display name |
| Product Reference | `product_reference` | string | SKU/reference code |
| Product EAN13 | `product_ean13` | string | Barcode |
| Product UPC | `product_upc` | string | Universal Product Code |
| Product Quantity | `product_quantity` | integer | Quantity ordered |
| Unit Price (Tax Incl) | `unit_price_tax_incl` | decimal | Price per unit with tax |
| Unit Price (Tax Excl) | `unit_price_tax_excl` | decimal | Price per unit without tax |
| Total Price (Tax Incl) | `total_price_tax_incl` | decimal | Line total with tax |
| Total Price (Tax Excl) | `total_price_tax_excl` | decimal | Line total without tax |

### 3.5 Order Inbound Fields ⬇️

Only these fields can be updated FROM Odoo:

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Order ID | `id` | ⬇️ | integer | Required to identify the order |
| Order State ID | `id_order_state` | ⬇️ | integer | Update order status |
| Tracking Number | `tracking_number` | ⬇️ | string | Update shipment tracking |
| Internal Note | `note` | ⬇️ | string | Private order note (not shown to customer) |

**IMPORTANT:** Order **creation** from Odoo is not supported in v2.0. Only updates are allowed.

---

## 4. INVOICE

### 4.1 Invoice Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Invoice ID | `id` | ⬆️ | integer | PrestaShop invoice ID |
| Invoice Number | `number` | ⬆️ | string | Sequential invoice number |
| Order ID | `id_order` | ⬆️ | integer | Related order ID |
| Total Paid (Tax Incl) | `total_paid_tax_incl` | ⬆️ | decimal | Invoice total with tax |
| Total Paid (Tax Excl) | `total_paid_tax_excl` | ⬆️ | decimal | Invoice total without tax |
| Date Created | `date_add` | ⬆️ | datetime | Invoice generation date |

### 4.2 Invoice Line Items - Outbound Only ⬆️

Each invoice includes `invoice_lines`:

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Invoice Detail ID | `id_order_invoice_detail` | integer | Line item ID |
| Product ID | `id_order_detail` | integer | Reference to order detail |
| Product Name | `product_name` | string | Product description |
| Product Quantity | `product_quantity` | integer | Quantity invoiced |
| Unit Price (Tax Incl) | `unit_price_tax_incl` | decimal | Price per unit with tax |
| Unit Price (Tax Excl) | `unit_price_tax_excl` | decimal | Price per unit without tax |
| Total Price (Tax Incl) | `total_price_tax_incl` | decimal | Line total with tax |
| Total Price (Tax Excl) | `total_price_tax_excl` | decimal | Line total without tax |

---

## 5. PAYMENT

### 5.1 Payment Fields - Outbound Only ⬆️

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Payment ID | `id_order_payment` | ⬆️ | integer | Payment transaction ID |
| Order Reference | `order_reference` | ⬆️ | string | Related order reference |
| Payment Method | `payment_method` | ⬆️ | string | Payment method name |
| Amount | `amount` | ⬆️ | decimal | Payment amount |
| Currency ID | `id_currency` | ⬆️ | integer | Currency used |
| Transaction ID | `transaction_id` | ⬆️ | string | External payment reference |
| Card Brand | `card_brand` | ⬆️ | string | Credit card type (Visa, MC, etc.) |
| Card Number | `card_number` | ⬆️ | string | Masked card number (last 4 digits) |
| Card Holder | `card_holder` | ⬆️ | string | Cardholder name |
| Date Added | `date_add` | ⬆️ | datetime | Payment date |

---

## 6. COUPON / CART RULE

### 6.1 Coupon Fields - Bi-directional 🔄

| Field Name | Technical Name | Direction | Type | Required | Notes |
|------------|----------------|-----------|------|----------|-------|
| Cart Rule ID | `id` | 🔄 | integer | Yes | PrestaShop cart rule ID |
| Coupon Code | `code` | 🔄 | string | Yes | Unique coupon code |
| Coupon Name | `name` | 🔄 | string | Yes | Internal description (multi-language) |
| Discount Percentage | `reduction_percent` | 🔄 | decimal | No | Percentage discount (0-100) |
| Discount Amount | `reduction_amount` | 🔄 | decimal | No | Fixed amount discount |
| Free Shipping | `free_shipping` | 🔄 | boolean | No | Whether shipping is free |
| Active Status | `active` | 🔄 | boolean | No | Whether coupon can be used |
| Quantity | `quantity` | 🔄 | integer | No | Total number of times code can be used |
| Quantity Per User | `quantity_per_user` | 🔄 | integer | No | Uses per customer |
| Priority | `priority` | 🔄 | integer | No | Evaluation priority (1-99, lower = higher priority) |
| Date From | `date_from` | 🔄 | datetime | No | Coupon validity start |
| Date To | `date_to` | 🔄 | datetime | No | Coupon validity end |

### 6.2 Coupon Usage Tracking - Outbound Only ⬆️

When a coupon is **applied** or **removed** from a cart:

| Field Name | Technical Name | Direction | Type | Notes |
|------------|----------------|-----------|------|-------|
| Cart Rule ID | `id_cart_rule` | ⬆️ | integer | The coupon ID |
| Code | `code` | ⬆️ | string | Coupon code |
| Cart ID | `id_cart` | ⬆️ | integer | Which cart it was applied to |
| Customer ID | `id_customer` | ⬆️ | integer | Customer who used it |
| Initial Quantity | `initial_quantity` | ⬆️ | integer | Total available |
| Current Quantity | `current_quantity` | ⬆️ | integer | Remaining uses |
| Reduction Amount | `reduction_amount` | ⬆️ | decimal | Discount applied to this cart |

### 6.3 Coupon Inbound Fields ⬇️

All bi-directional fields above can be set from Odoo when creating/updating coupons.

---

## 7. EVENT METADATA

These fields are included with **every** event regardless of entity type:

### 7.1 Event Tracking Fields

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Event ID | `event_id` | integer | Unique event identifier |
| Entity Type | `entity_type` | string | customer, order, invoice, payment, coupon |
| Entity ID | `entity_id` | integer | ID of the affected entity |
| Entity Name | `entity_name` | string | Human-readable name |
| Action Type | `action_type` | string | created, updated, deleted, applied, removed, status_changed |
| Transaction Hash | `transaction_hash` | string | SHA-256 hash for deduplication |
| Correlation ID | `correlation_id` | string | UUID to group related events |
| Hook Name | `hook_name` | string | PrestaShop hook that triggered the event |
| Hook Timestamp | `hook_timestamp` | datetime | When the hook fired |

### 7.2 Event Data Fields

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Before Data | `before_data` | JSON | Entity state before change (if available) |
| After Data | `after_data` | JSON | Entity state after change |
| Change Summary | `change_summary` | string | Human-readable description |
| Context Data | `context_data` | JSON | Additional metadata specific to event type |

---

## 8. REVERSE SYNC SPECIAL FIELDS

When receiving webhooks FROM Odoo, these additional fields are used:

### 8.1 Reverse Webhook Payload Structure

| Field Name | Technical Name | Type | Required | Notes |
|------------|----------------|------|----------|-------|
| Entity Type | `entity_type` | string | Yes | customer, order, address, coupon |
| Action Type | `action_type` | string | No | created, updated, deleted (default: updated) |
| Event ID | `event_id` | string | No | Odoo's event identifier (UUID) |
| Data | `data` | object | Yes | Entity data (see entity-specific fields above) |

### 8.2 Reverse Sync Operation Tracking

Tracked in `ps_odoo_sales_reverse_operations` table:

| Field Name | Technical Name | Type | Notes |
|------------|----------------|------|-------|
| Operation ID | `operation_id` | string | UUID v4 |
| Entity Type | `entity_type` | string | customer, order, address, coupon |
| Entity ID | `entity_id` | integer | PrestaShop entity ID after processing |
| Action Type | `action_type` | string | created, updated, deleted |
| Status | `status` | string | processing, success, failed |
| Source Payload | `source_payload` | JSON | Complete webhook payload from Odoo |
| Result Data | `result_data` | JSON | Processing result |
| Error Message | `error_message` | text | Error details if failed |
| Processing Time | `processing_time_ms` | integer | Milliseconds to process |
| Date Added | `date_add` | datetime | When received |

---

## 9. VALIDATION RULES

### 9.1 Customer Validation

- **Email**: Must be valid email format, unique across all customers
- **First Name / Last Name**: Required, must pass `Validate::isName()`
- **Birthday**: Must be in YYYY-MM-DD format
- **Website**: Must be valid URL format

### 9.2 Address Validation

- **Country ID**: Must exist in PrestaShop
- **State/Province ID**: Required if `Country::contains_states` is true
- **Postal Code**: Required if country requires it
- **Phone Numbers**: Must pass `Validate::isPhoneNumber()`

### 9.3 Order Validation

- **Order State ID**: Must exist in `ps_order_state` table
- **All amounts**: Must be decimal values
- **Tracking Number**: Free text, no specific format required

### 9.4 Coupon Validation

- **Code**: Must be unique, alphanumeric
- **Reduction Percent**: 0-100 range
- **Dates**: `date_from` must be before `date_to`
- **Priority**: 1-99 range

---

## 10. FIELD MAPPING NOTES

### 10.1 Multi-language Fields

These fields support multiple languages in PrestaShop (currently only default language is synced):

- Customer: *(none - customer data is language-independent)*
- Address: *(none - addresses are language-independent)*
- Order: *(inherits language from customer)*
- Coupon: `name` (cart rule name is multi-language)

### 10.2 Computed Fields

These fields are calculated, not directly stored:

- **Order**: `total_paid` = `total_paid_tax_incl`
- **Customer full name**: `firstname + ' ' + lastname`
- **Address display name**: Uses `alias`

### 10.3 Default Values

When creating entities from Odoo, these defaults apply:

- **Customer**:
  - `active`: true
  - `newsletter`: false
  - `optin`: false
  - `firstname`: "Unknown" (if not provided)
  - `lastname`: "Customer" (if not provided)
  - `passwd`: Random 16-character hash

- **Address**:
  - *(No defaults - all required fields must be provided)*

- **Coupon**:
  - `active`: true
  - `free_shipping`: false
  - `quantity`: 1
  - `quantity_per_user`: 1
  - `priority`: 1

---

## 11. FREQUENTLY ASKED QUESTIONS

### Q: Can I sync customer passwords?

**A:** No. Passwords are never synced for security reasons. When a customer is created from Odoo, a random secure password is generated automatically.

### Q: Why are addresses sent as customer updates?

**A:** Odoo typically stores addresses as part of the customer/contact record (res.partner), so address changes are normalized to customer update events with address data in `context_data`.

### Q: Can orders be created from Odoo?

**A:** Not in v2.0. Order creation from Odoo is too complex (requires products, stock, prices, tax rules, etc.). Only order **updates** (status, tracking, notes) are supported inbound.

### Q: What happens to multi-language fields?

**A:** Currently only the default shop language is synchronized. Multi-language support is planned for a future version.

### Q: Are product details sent with orders?

**A:** Yes, complete product information is included in `order_lines`, including product ID, name, reference, quantity, and prices.

---

## 12. VERSION HISTORY

- **v2.0.1** (2025-01-16): Enhanced UI, reverse sync visibility improvements
- **v2.0.0** (2025-01-16): Initial bi-directional sync implementation
- **v1.0.0** (2024): Initial outbound sync only (PrestaShop → Odoo)

---

**Last Updated:** 2025-01-16
**Module Version:** 2.0.1
**Documentation Version:** 1.0
