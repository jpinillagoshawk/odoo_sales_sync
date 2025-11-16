# Changelog - Odoo Sales Sync Module

## Version 2.0.1 - 2025-01-16

### 🐛 Bug Fixes

- **Fixed**: Missing `changeLogsLimit()` JavaScript function for logs per page selector
- **Fixed**: Missing `clearEventFilters()` function for Events tab filter reset
- **Fixed**: Logs per page dropdown now works correctly
- **Fixed**: Page navigation properly updates with filter changes

### ✨ Improvements

- **Enhanced**: Event detail modal now shows "Odoo Inbound Event" indicator for reverse webhooks
- **Enhanced**: Added sync direction labels with icons (PrestaShop → Odoo vs Odoo → PrestaShop)
- **Enhanced**: Reverse sync events now create tracking entries in `ps_odoo_sales_events` table
- **Enhanced**: Events tab displays both forward and reverse sync operations with visual distinction

### 🔧 Technical Changes

- Added `is_reverse_sync` flag detection (hook_name === 'reverse_webhook')
- Enhanced `OdooSalesReverseWebhookRouter` with `createEventRecord()` method
- Added `getEntityName()` helper for better entity display names
- Modified `AdminOdooSalesSyncEventsController::enrichEventsWithEntityData()` to mark reverse sync events
- Updated `renderEventDetailModal()` to show alert and direction for inbound Odoo events

### 📝 Files Changed

- `views/js/admin.js` - Added missing JavaScript functions
- `controllers/admin/AdminOdooSalesSyncEventsController.php` - Enhanced event display
- `classes/OdooSalesReverseWebhookRouter.php` - Added event tracking

---

## Version 2.0.0 - 2025-01-16

### 🚀 MAJOR FEATURE: Bi-Directional Synchronization (Reverse Sync)

This release introduces **reverse synchronization**, allowing Odoo to send updates BACK to PrestaShop for customers, orders, addresses, and coupons. The module now supports true bi-directional data flow.

### Added

#### Reverse Webhook Endpoint
- **NEW**: `reverse_webhook.php` - Main endpoint for receiving webhooks FROM Odoo
- **NEW**: Security validation with webhook secret and optional IP whitelisting
- **NEW**: JSON payload validation and entity type filtering
- **NEW**: Complete error handling and HTTP status code responses (200, 400, 403, 500)

#### Entity Processors (4 New Classes)
- **NEW**: `OdooSalesCustomerProcessor` - Create/update customers from Odoo
  - Email uniqueness validation (updates existing instead of duplicating)
  - Support for all customer fields (name, company, newsletter, active status, etc.)
  - Secure password generation for new customers
- **NEW**: `OdooSalesOrderProcessor` - Update orders from Odoo
  - Update order status with OrderHistory creation
  - Update tracking numbers
  - Update internal order notes
  - Order creation deferred to future phase (too complex for v2.0)
- **NEW**: `OdooSalesAddressProcessor` - Create/update addresses from Odoo
  - Customer existence validation
  - Country and state validation
  - Support for all address fields (phone, company, DNI, VAT, etc.)
- **NEW**: `OdooSalesCouponProcessor` - Create/update cart rules/coupons from Odoo
  - Percentage discount support
  - Fixed amount discount support
  - Free shipping support
  - Code uniqueness validation
  - Date range, quantity limits, priority settings

#### Loop Prevention System (3-Layer Strategy)
- **NEW**: `OdooSalesReverseSyncContext` - Global flag system for loop prevention
  - `markAsReverseSync()` - Mark operation as coming from Odoo
  - `isReverseSync()` - Check if current operation is reverse sync
  - `getProcessingTimeMs()` - Performance tracking
  - `generateOperationId()` - UUID v4 generation
- **MODIFIED**: `OdooSalesEventDetector` - All 4 detect methods enhanced with loop prevention
  - `detectCustomerChange()` - Skips webhook creation during reverse sync
  - `detectAddressChange()` - Skips webhook creation during reverse sync
  - `detectOrderChange()` - Skips webhook creation during reverse sync
  - `detectCouponChange()` - Skips webhook creation during reverse sync
- **NEW**: Database tracking in `ps_odoo_sales_reverse_operations` table

#### Database Schema
- **NEW**: Table `ps_odoo_sales_reverse_operations` for operation tracking
  - Tracks operation_id (UUID), entity_type, entity_id, action_type
  - Stores source_payload (full JSON from Odoo)
  - Stores result_data and error_message
  - Tracks status (processing/success/failed)
  - Records processing_time_ms for performance monitoring
  - Indexes for performance on entity_type, status, date_add

#### Routing and Architecture
- **NEW**: `OdooSalesReverseWebhookRouter` - Routes webhooks to entity processors
  - Payload validation (structure, entity type, action type)
  - Operation ID generation or extraction from payload
  - Sets reverse sync context flag before processing
  - Clears context flag in finally block (ensures cleanup)
  - Error handling with detailed logging
- **NEW**: `OdooSalesReverseOperation` ObjectModel for database operations
  - `trackOperation()` - Create tracking record
  - `updateStatus()` - Update operation result
  - `findByOperationId()` - Lookup by UUID
  - `getStatistics()` - Performance analytics
  - `cleanup()` - Old record cleanup (30 days success, 90 days failed)

#### Configuration UI Enhancements
- **NEW**: "Reverse Synchronization" section in module configuration
- **NEW**: Enable/disable toggle for reverse sync (disabled by default)
- **NEW**: Auto-generated reverse webhook URL (read-only display field)
- **NEW**: Debug webhook URL configuration (for local debug server)
- **NEW**: IP whitelist configuration (optional security)
- **NEW**: Configuration keys:
  - `ODOO_SALES_SYNC_REVERSE_ENABLED`
  - `ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL`
  - `ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS`

#### Debug Server Enhancements
- **MODIFIED**: `webhook_debug_server.py` - Enhanced to distinguish reverse sync events
  - New `reverse_sync_count` statistic
  - Displays flow direction: "odoo → prestashop" vs "prestashop → odoo"
  - Shows result status inline (✓ success / ✗ failure)
  - Cyan color for reverse sync events
  - Added reverse sync row to HTML dashboard

#### Documentation
- **NEW**: `REVERSE_SYNC_IMPLEMENTATION_PLAN.md` - Complete technical specification (864 lines)
- **NEW**: `REVERSE_SYNC_SUMMARY.md` - Executive summary with API examples
- **NEW**: `REVERSE_SYNC_QUICK_REFERENCE.md` - Developer quick reference
- **NEW**: `REVERSE_SYNC_DETAILED_TASKS.md` - 62 task breakdown with estimates
- **NEW**: `upgrade/upgrade-2.0.0.php` - Automated upgrade script

### Changed

- **MODIFIED**: Module version updated to `2.0.0` in `odoo_sales_sync.php` and `config.xml`
- **MODIFIED**: Module description updated to reflect bi-directional sync capability
- **MODIFIED**: Form submission handling to save reverse sync configuration
- **MODIFIED**: Uninstall cleanup to remove reverse sync configuration keys

### Security

- Webhook secret validation via `X-Webhook-Secret` header
- Optional IP whitelisting for incoming webhooks
- Request method validation (POST only)
- JSON payload validation with detailed error messages
- Entity type whitelisting (customer, order, address, coupon only)

### Database Migrations

Run the upgrade script or manually execute:
```sql
-- Create reverse operations tracking table
CREATE TABLE IF NOT EXISTS `ps_odoo_sales_reverse_operations` (
  `id_reverse_operation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation_id` VARCHAR(64) NOT NULL,
  `entity_type` ENUM('customer','order','address','coupon') NOT NULL,
  `entity_id` INT UNSIGNED NULL,
  `action_type` ENUM('created','updated','deleted') NOT NULL,
  `source_payload` MEDIUMTEXT NULL,
  `result_data` TEXT NULL,
  `status` ENUM('processing','success','failed') DEFAULT 'processing',
  `error_message` TEXT NULL,
  `processing_time_ms` INT NULL,
  `date_add` DATETIME NOT NULL,
  `date_upd` DATETIME NOT NULL,
  PRIMARY KEY (`id_reverse_operation`),
  UNIQUE KEY `operation_id` (`operation_id`),
  KEY `entity_lookup` (`entity_type`, `entity_id`),
  KEY `status` (`status`),
  KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### API Examples

**Customer Creation:**
```bash
curl -X POST https://your-prestashop.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your-secret" \
  -d '{"entity_type":"customer","action_type":"created","data":{"email":"test@example.com","firstname":"John","lastname":"Doe"}}'
```

**Order Status Update:**
```bash
curl -X POST https://your-prestashop.com/modules/odoo_sales_sync/reverse_webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your-secret" \
  -d '{"entity_type":"order","action_type":"updated","data":{"id":123,"id_order_state":3,"tracking_number":"TRACK123"}}'
```

See `REVERSE_SYNC_SUMMARY.md` for complete API documentation.

### Backward Compatibility

✅ **100% Backward Compatible** - Reverse sync is DISABLED by default. Existing installations continue working without any changes. Enable reverse sync via module configuration when ready.

### Breaking Changes

None. All changes are additive and opt-in.

### Performance

Expected processing times for reverse sync operations:
- Customer create: 30-60ms
- Customer update: 25-45ms
- Address create: 35-65ms
- Order update: 40-70ms
- Coupon create: 45-80ms

### Testing Environment

- **Test URL**: dev.elultimokoala.com
- **Webhook Secret**: test_secret
- **Reverse Endpoint**: https://dev.elultimokoala.com/modules/odoo_sales_sync/reverse_webhook.php

### Upgrade Instructions

1. **Backup database** before upgrading
2. Upload new module files (overwrite existing)
3. Go to Modules > Module Manager
4. Find "Odoo Sales Sync"
5. Click "Upgrade" (this runs `upgrade/upgrade-2.0.0.php`)
6. Verify new table exists: `SELECT * FROM ps_odoo_sales_reverse_operations LIMIT 1;`
7. Configure reverse sync in module settings (optional - disabled by default)

### Contributors

🤖 Generated with [Claude Code](https://claude.com/claude-code)
Co-Authored-By: Claude <noreply@anthropic.com>

---

## Version 1.1.0 - 2025-11-15

### 🚀 Major Enhancement: Complete Order Data for Migration

This release significantly enhances order webhook payloads with comprehensive, in-depth data specifically designed for PrestaShop → Odoo migration scenarios.

### Added

#### Enhanced Order Product Details (30+ Fields per Product)
- **NEW**: `product_upc`, `product_isbn` - Additional product identification codes
- **NEW**: `product_quantity_in_stock` - Stock level at order time
- **NEW**: `product_quantity_refunded` - Quantity refunded
- **NEW**: `product_quantity_return` - Quantity returned
- **NEW**: `product_quantity_reinjected` - Quantity reinjected to stock
- **NEW**: `product_quantity_remaining` - Calculated remaining quantity not yet fulfilled
- **NEW**: `original_product_price` - Base unit price (critical for Odoo)
- **NEW**: `product_tax` - Calculated VAT amount (total_incl - total_excl)
- **NEW**: `tax_rate` - Tax percentage
- **NEW**: `group_reduction` - Group-specific discount
- **NEW**: `ecotax`, `ecotax_tax_rate` - Eco-tax information
- **NEW**: `discount_quantity_applied` - Discount application tracking
- **NEW**: `download_hash`, `download_deadline` - Virtual product download info
- **NEW**: `customization`, `id_customization` - Product customization details

#### Order Status History
- **NEW**: `extractOrderHistory()` method captures complete order status history
- **NEW**: Webhook includes `order_history` array with all status changes
- **NEW**: Each history entry includes:
  - `id_order_history` - History record ID
  - `id_order_state` - Status ID
  - `status_name` - Localized status name
  - `id_employee` - Employee who made the change
  - `date_add` - Change timestamp
- **Benefits**: Complete audit trail for migration, employee tracking

#### Order Payment Records
- **NEW**: `extractOrderPayments()` method captures all payment records
- **NEW**: Webhook includes `order_payments` array with all payments
- **NEW**: Each payment record includes:
  - `id_order_payment` - Payment ID
  - `order_reference` - Order reference
  - `payment_method` - Payment method name
  - `amount` - Payment amount
  - `transaction_id` - External transaction ID
  - `card_number` - Masked card number
  - `card_brand`, `card_expiration`, `card_holder` - Card details
  - `date_add` - Payment timestamp
  - `conversion_rate` - Currency conversion rate
- **Benefits**: Payment reconciliation, multi-payment support

#### Customer Messages
- **NEW**: `extractOrderMessages()` method captures customer notes
- **NEW**: Webhook includes `messages` array with all customer messages
- **NEW**: Each message includes:
  - `id_message` - Message ID
  - `id_customer` - Customer ID
  - `message` - Message content
  - `private` - Private/public flag
  - `date_add` - Message timestamp
- **Benefits**: Customer communication context preserved in migration

#### Internal Order Notes
- **NEW**: `note` field in order data (from `ps_order.note`)
- **Benefits**: Internal notes for fulfillment teams preserved

#### Enhanced Order Header Fields
- **NEW**: `total_products`, `total_products_wt` - Product totals
- **NEW**: `total_discounts_tax_incl`, `total_discounts_tax_excl` - Detailed discounts
- **NEW**: `total_shipping_tax_incl`, `total_shipping_tax_excl` - Shipping breakdown
- **NEW**: `total_wrapping_tax_incl`, `total_wrapping_tax_excl` - Gift wrapping
- **NEW**: `id_carrier`, `shipping_number` - Carrier and tracking number
- **NEW**: `id_currency`, `conversion_rate` - Currency details
- **NEW**: `module` - Payment module identifier

### Changed

#### Enhanced Methods
- **ENHANCED**: `extractOrderLines()` method
  - Increased from 15 fields to 30+ fields per product
  - Added comprehensive quantity tracking
  - Added complete pricing breakdown
  - Added tax calculations
  - Added discount details
  - Added product customization support
  - Added virtual product download support

- **ENHANCED**: `detectOrderChange()` method
  - Now captures complete order header (25+ fields)
  - Calls all new extraction methods
  - Includes order_details, order_history, order_payments, messages
  - Enhanced logging with counts of each data type

### Documentation

- **NEW**: `WEBHOOK_PAYLOAD_SPECIFICATION.md` - Complete webhook payload documentation
- **NEW**: `UPGRADE_NOTES_v1.1.0.md` - Detailed upgrade guide and migration benefits

### Migration Benefits

#### Before v1.1.0 (Issues)
- ❌ Limited product data (15 fields)
- ❌ No order history → No audit trail
- ❌ No payment records → Manual reconciliation required
- ❌ No customer messages → Lost communication context
- ❌ No internal notes → Fulfillment teams lack context

#### After v1.1.0 (Solutions)
- ✅ Complete product data (30+ fields per line)
- ✅ Complete order history → Full audit trail
- ✅ All payment records → Automatic reconciliation
- ✅ Customer messages → Communication context preserved
- ✅ Internal notes → Fulfillment context preserved
- ✅ Tax calculations → Easier Odoo import
- ✅ Quantity tracking → Fulfillment status visible

### Technical Details

#### Files Modified
1. `classes/OdooSalesEventDetector.php`
   - Enhanced `extractOrderLines()` - Lines 793-911 (119 lines, was 65 lines)
   - Added `extractOrderHistory()` - Lines 913-959 (47 lines)
   - Added `extractOrderPayments()` - Lines 961-1012 (52 lines)
   - Added `extractOrderMessages()` - Lines 1014-1057 (44 lines)
   - Enhanced `detectOrderChange()` - Lines 296-462 (167 lines, was 105 lines)

#### Files Created
1. `WEBHOOK_PAYLOAD_SPECIFICATION.md` - Complete payload documentation
2. `UPGRADE_NOTES_v1.1.0.md` - Upgrade instructions and benefits

### Performance Impact

#### Payload Size
- **Before**: 2-5 KB per order event
- **After**: 8-15 KB per order event
- **Reason**: Complete order history, payments, messages, enhanced product data
- **Mitigation**: Product lines limited to 100 to prevent excessive payloads

#### Database Impact
- **No database schema changes** - Fully backward compatible
- **No migration required** - Drop-in replacement

### Breaking Changes

**None!** This is a fully backward-compatible upgrade:
- ✅ All existing fields unchanged
- ✅ New fields are additions only
- ✅ No fields removed or renamed
- ✅ No configuration changes required
- ✅ Existing Odoo integrations continue to work

### Upgrade Instructions

```bash
# 1. Backup current module
cp -r modules/odoo_sales_sync modules/odoo_sales_sync.backup.v1.0.0

# 2. Replace files
cp -r /path/to/new/odoo_sales_sync/* modules/odoo_sales_sync/

# 3. Clear cache
rm -rf var/cache/*
```

**No module reinstallation required!**

### Testing Checklist

- [x] Order creation includes all new data
- [x] Order update includes all new data
- [x] Status change includes order history
- [x] Payment records captured
- [x] Customer messages captured
- [x] Internal notes captured
- [x] Product variants tracked correctly
- [x] Tax calculations correct
- [x] Quantity remaining calculated correctly
- [x] Multi-payment orders handled
- [x] Backward compatibility verified

### Next Steps After Upgrade

1. Update Odoo webhook handler to process new fields (optional)
2. Test complete migration workflow with real orders
3. Verify payment reconciliation in Odoo
4. Validate order history import in Odoo

---

## Version 1.0.0 - 2025-11-09

### Fixed
- **Critical**: Fixed SQL installation error caused by logger calls during installation
  - Removed `$this->logger` references in `installSQL()` method
  - Logger cannot be used during installation as database tables don't exist yet

- **Critical**: Changed PHP array syntax from short (`[]`) to long (`array()`) for better compatibility
  - All arrays now use `array()` syntax compatible with PHP 5.4+

### Added
- **Admin Interface**: Complete tabbed interface similar to `odoo_direct_stock_sync` module
  - Configuration tab with form
  - Events tab with pagination
  - Failed Events tab with retry functionality
  - Logs tab with context viewer

- **Templates**: Created 4 Smarty templates
  - `views/templates/admin/main.tpl` - Main tabbed interface
  - `views/templates/admin/events_tab.tpl` - Events list with pagination
  - `views/templates/admin/failed_tab.tpl` - Failed events with retry button
  - `views/templates/admin/logs_tab.tpl` - System logs with modal context viewer

- **Assets**: Added CSS and JavaScript files
  - `views/css/admin.css` - Admin panel styling
  - `views/js/admin.js` - Tab persistence and auto-refresh functionality

- **Retry Functionality**:
  - Manual retry button in Failed Events tab
  - AJAX-based retry mechanism
  - `retryFailedEvents()` method to reprocess failed events
  - `handleAjaxRequest()` method for AJAX operations

- **Helper Methods**:
  - `getConfigurationContent()` - Renders configuration form
  - `getEventsContent()` - Fetches and displays all events
  - `getFailedContent()` - Fetches and displays failed events
  - `getLogsContent()` - Fetches and displays system logs

### Changed
- **getContent() method**: Completely refactored
  - Now uses tabbed interface instead of single form
  - Added AJAX handling
  - Added retry handling
  - Loads content for all 4 tabs

- **renderForm() renamed**: Changed to `getConfigurationContent()`
  - Better naming consistency with other content methods
  - Returns configuration form HTML

### Technical Details

#### Files Modified
1. `odoo_sales_sync.php`
   - Fixed `installSQL()` method (lines 174-200)
   - Updated `getContent()` method (lines 235-308)
   - Renamed and updated `getConfigurationContent()` (lines 310-391)
   - Added `getEventsContent()` (lines 393-428)
   - Added `getFailedContent()` (lines 430-467)
   - Added `getLogsContent()` (lines 469-504)
   - Added `handleAjaxRequest()` (lines 506-519)
   - Added `retryFailedEvents()` (lines 521-537)

#### Files Created
1. `views/templates/admin/main.tpl` - 76 lines
2. `views/templates/admin/events_tab.tpl` - 85 lines
3. `views/templates/admin/failed_tab.tpl` - 125 lines
4. `views/templates/admin/logs_tab.tpl` - 151 lines
5. `views/css/admin.css` - 62 lines
6. `views/js/admin.js` - 36 lines

### Installation Instructions

1. **Uninstall old version** (if installed):
   - Go to Modules > Module Manager
   - Find "Odoo Sales Sync"
   - Click Uninstall
   - Confirm (this will delete all data)

2. **Upload new version**:
   - Compress `odoo_sales_sync` folder to ZIP
   - Upload via Modules > Module Manager > Upload a Module

3. **Install module**:
   - Click Install
   - Module will create 4 database tables automatically

4. **Configure**:
   - Click Configure
   - Go to Configuration tab
   - Set Webhook URL (e.g., ngrok URL)
   - Set Webhook Secret
   - Enable sync
   - Click Save
   - Click Test Connection

### Testing

With webhook debug server:
```bash
python3 webhook_debug_server.py --port 5000 --secret test_secret
```

With ngrok:
```bash
ngrok http 5000
```

Module configuration:
- Webhook URL: `https://xxxxx.ngrok-free.dev/webhook`
- Webhook Secret: `test_secret`

Test actions:
- Create customer account → Check Events tab
- Add address → Check Events tab
- Apply coupon → Check Events tab
- View failed events → Check Failed tab
- View system logs → Check Logs tab
- Test retry → Click "Retry All Failed" in Failed tab

### Next Steps

1. Test module installation from scratch
2. Verify all 4 tabs display correctly
3. Test webhook connectivity
4. Test event creation for all 23 hooks
5. Test failed event retry functionality
6. Monitor logs for any errors

### Known Issues

None at this time.

### Notes

- Version kept at 1.0.0 as requested
- All code uses PHP 5.4+ compatible syntax
- Class name remains `odoo_sales_sync` (lowercase with underscores)
- Module structure matches `odoo_direct_stock_sync` reference module
- Ready for production testing
