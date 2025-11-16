# Reverse Sync - Detailed Task Breakdown

**Module**: odoo_sales_sync v2.0.0
**Feature**: Reverse Webhook Synchronization
**Total Tasks**: 56
**Estimated Duration**: 10.5 days

---

## Phase 1: Foundation & Infrastructure (2 days)

### Database Schema (3 tasks)

- [ ] **Task 1.1**: Create SQL migration file `sql/reverse_sync_install.sql`
  - Create `ps_odoo_sales_reverse_operations` table
  - Add indexes for performance
  - Add foreign key constraints if needed
  - **Deliverable**: SQL file with complete schema
  - **Time**: 2 hours

- [ ] **Task 1.2**: Update `sql/install.sql` to include reverse sync table
  - Merge reverse_sync_install.sql into main install.sql
  - Test installation from scratch
  - **Deliverable**: Updated install.sql
  - **Time**: 1 hour

- [ ] **Task 1.3**: Create uninstall SQL for reverse sync
  - Add DROP TABLE to `sql/uninstall.sql`
  - **Deliverable**: Updated uninstall.sql
  - **Time**: 0.5 hours

### Context Flag Mechanism (4 tasks)

- [ ] **Task 1.4**: Create `classes/OdooSalesReverseSyncContext.php`
  - Static property for reverse sync flag
  - `markAsReverseSync($operationId)` method
  - `isReverseSync()` method
  - `getOperationId()` method
  - `clear()` method
  - **Deliverable**: Complete context class
  - **Time**: 2 hours

- [ ] **Task 1.5**: Create `classes/OdooSalesReverseOperation.php` model
  - Extends ObjectModel
  - Define all table fields
  - Add validation rules
  - Add `trackOperation()` static method
  - Add `updateStatus()` method
  - **Deliverable**: Complete model class
  - **Time**: 3 hours

- [ ] **Task 1.6**: Modify `classes/OdooSalesEventDetector.php`
  - Import OdooSalesReverseSyncContext
  - Add reverse sync check at start of each detect* method
  - Skip event creation if reverse sync is active
  - Log when skipping due to reverse sync
  - **Deliverable**: Modified detector with loop prevention
  - **Time**: 2 hours

- [ ] **Task 1.7**: Test context flag mechanism
  - Unit test: Flag set/check/clear
  - Unit test: Multiple operations
  - Unit test: Event detector skips when flag set
  - **Deliverable**: Passing unit tests
  - **Time**: 2 hours

### Router & Main Endpoint (5 tasks)

- [ ] **Task 1.8**: Create `classes/OdooSalesReverseWebhookRouter.php`
  - `route($payload)` method
  - Generate operation ID
  - Set reverse sync flag
  - Route to entity processors
  - Clear flag in finally block
  - Error handling and logging
  - **Deliverable**: Complete router class
  - **Time**: 3 hours

- [ ] **Task 1.9**: Create `reverse_webhook.php` endpoint
  - Security check (webhook secret)
  - Load PrestaShop bootstrap
  - Read JSON payload
  - Call router
  - Return JSON response
  - HTTP status codes (200, 400, 403, 500)
  - **Deliverable**: Complete webhook endpoint
  - **Time**: 2 hours

- [ ] **Task 1.10**: Add configuration keys
  - `ODOO_SALES_SYNC_REVERSE_ENABLED`
  - `ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL`
  - `ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS`
  - Add to `install()` method
  - Add to `uninstall()` method
  - **Deliverable**: Config keys defined
  - **Time**: 1 hour

- [ ] **Task 1.11**: Update configuration UI in `odoo_sales_sync.php`
  - Add "Reverse Sync" section
  - Enable/disable toggle
  - Debug webhook URL field
  - Display reverse webhook endpoint URL (read-only)
  - IP whitelist field (optional)
  - **Deliverable**: Updated admin config page
  - **Time**: 2 hours

- [ ] **Task 1.12**: Test foundation components
  - Test webhook endpoint responds
  - Test secret validation (reject invalid)
  - Test JSON parsing
  - Test router routing
  - Test operation tracking
  - **Deliverable**: Integration tests passing
  - **Time**: 2 hours

**Phase 1 Subtotal: 20 hours (2.5 days)**

---

## Phase 2: Customer Processor (1 day)

### Customer Entity Logic (6 tasks)

- [ ] **Task 2.1**: Create `classes/OdooSalesCustomerProcessor.php` structure
  - Class skeleton
  - Static `process()` method
  - Private helper methods structure
  - Error handling framework
  - **Deliverable**: Class structure
  - **Time**: 1 hour

- [ ] **Task 2.2**: Implement `createCustomer($data)` method
  - Validate required fields (email, firstname, lastname)
  - Check if email already exists
  - Create new Customer object
  - Map fields from payload to PrestaShop fields
  - Generate random password
  - Call `Customer::add()`
  - Handle errors
  - **Deliverable**: Working customer creation
  - **Time**: 3 hours

- [ ] **Task 2.3**: Implement `updateCustomer($data)` method
  - Look up customer by ID or email
  - Load Customer object
  - Map and update changed fields only
  - Call `Customer::update()`
  - Handle errors
  - **Deliverable**: Working customer update
  - **Time**: 2 hours

- [ ] **Task 2.4**: Implement `notifyDebugServer($payload, $result, $operationId)` method
  - Check if debug webhook URL configured
  - Build notification payload with reverse_sync flag
  - Send cURL request with short timeout
  - Don't block on failure
  - Log success/failure
  - **Deliverable**: Debug notification working
  - **Time**: 2 hours

- [ ] **Task 2.5**: Add field mapping and validation
  - Email validation
  - Name sanitization
  - Boolean field mapping (newsletter, optin, active)
  - Optional fields (company, siret, website)
  - Default values for missing fields
  - **Deliverable**: Complete field mapping
  - **Time**: 2 hours

- [ ] **Task 2.6**: Test customer processor
  - Unit test: Create customer with valid data
  - Unit test: Create customer with duplicate email (should fail gracefully)
  - Unit test: Update customer by ID
  - Unit test: Update customer by email lookup
  - Integration test: End-to-end via webhook endpoint
  - **Deliverable**: All tests passing
  - **Time**: 3 hours

**Phase 2 Subtotal: 13 hours (1.6 days)**

---

## Phase 3: Order Processor (2 days)

### Order Entity Logic (7 tasks)

- [ ] **Task 3.1**: Create `classes/OdooSalesOrderProcessor.php` structure
  - Class skeleton
  - Static `process()` method
  - Private helper methods
  - **Deliverable**: Class structure
  - **Time**: 1 hour

- [ ] **Task 3.2**: Implement `updateOrder($data)` method
  - Look up order by ID or reference
  - Load Order object
  - Validate order exists
  - Route to specific update methods
  - **Deliverable**: Order update router
  - **Time**: 2 hours

- [ ] **Task 3.3**: Implement `updateOrderStatus($order, $newStateId)` method
  - Validate state ID exists
  - Create OrderHistory record
  - Update order current_state
  - Trigger state-specific actions (email, stock, etc.)
  - Handle errors
  - **Deliverable**: Status update working
  - **Time**: 3 hours

- [ ] **Task 3.4**: Implement `updateTrackingNumber($order, $trackingNumber)` method
  - Update order tracking number
  - Find associated OrderCarrier
  - Update carrier tracking
  - **Deliverable**: Tracking update working
  - **Time**: 2 hours

- [ ] **Task 3.5**: Implement `updateInternalNote($order, $note)` method
  - Update order->note field
  - Validate note length
  - Call update()
  - **Deliverable**: Note update working
  - **Time**: 1 hour

- [ ] **Task 3.6**: Add order lookup by reference
  - Query by order.reference field
  - Handle multiple shop contexts
  - Return order ID if found
  - **Deliverable**: Reference lookup working
  - **Time**: 2 hours

- [ ] **Task 3.7**: Test order processor
  - Unit test: Update status
  - Unit test: Update tracking
  - Unit test: Update note
  - Unit test: Lookup by reference
  - Integration test: End-to-end via webhook
  - **Deliverable**: All tests passing
  - **Time**: 3 hours

**Phase 3 Subtotal: 14 hours (1.75 days)**

---

## Phase 4: Address Processor (1 day)

### Address Entity Logic (6 tasks)

- [ ] **Task 4.1**: Create `classes/OdooSalesAddressProcessor.php` structure
  - Class skeleton
  - Static `process()` method
  - Private helper methods
  - **Deliverable**: Class structure
  - **Time**: 1 hour

- [ ] **Task 4.2**: Implement `createAddress($data)` method
  - Validate required fields (id_customer, address1, city, postcode)
  - Validate customer exists
  - Validate country ID
  - Validate state ID (if applicable)
  - Create new Address object
  - Map fields
  - Call `Address::add()`
  - **Deliverable**: Address creation working
  - **Time**: 3 hours

- [ ] **Task 4.3**: Implement `updateAddress($data)` method
  - Look up address by ID
  - Load Address object
  - Map and update fields
  - Call `Address::update()`
  - **Deliverable**: Address update working
  - **Time**: 2 hours

- [ ] **Task 4.4**: Add address validation
  - Country exists check
  - State exists check (for countries that require it)
  - Postcode format validation
  - Phone format validation
  - **Deliverable**: Validation working
  - **Time**: 2 hours

- [ ] **Task 4.5**: Add customer linkage
  - Verify id_customer exists
  - Handle customer not found error
  - Link address to customer correctly
  - **Deliverable**: Customer link working
  - **Time**: 1 hour

- [ ] **Task 4.6**: Test address processor
  - Unit test: Create address with valid data
  - Unit test: Create address with invalid country (should fail)
  - Unit test: Update address
  - Unit test: Customer linkage
  - Integration test: End-to-end via webhook
  - **Deliverable**: All tests passing
  - **Time**: 2 hours

**Phase 4 Subtotal: 11 hours (1.4 days)**

---

## Phase 5: Coupon Processor (1 day)

### Coupon/CartRule Logic (6 tasks)

- [ ] **Task 5.1**: Create `classes/OdooSalesCouponProcessor.php` structure
  - Class skeleton
  - Static `process()` method
  - Private helper methods
  - **Deliverable**: Class structure
  - **Time**: 1 hour

- [ ] **Task 5.2**: Implement `createCoupon($data)` method
  - Validate required fields (code, name)
  - Check code uniqueness
  - Create new CartRule object
  - Map discount fields (percent vs amount)
  - Set date range
  - Set quantity limits
  - Call `CartRule::add()`
  - **Deliverable**: Coupon creation working
  - **Time**: 4 hours

- [ ] **Task 5.3**: Implement `updateCoupon($data)` method
  - Look up CartRule by ID or code
  - Load CartRule object
  - Update allowed fields
  - Call `CartRule::update()`
  - **Deliverable**: Coupon update working
  - **Time**: 2 hours

- [ ] **Task 5.4**: Map discount types
  - Percentage discount → reduction_percent
  - Fixed amount discount → reduction_amount
  - Free shipping → free_shipping flag
  - Product-specific discounts → reduction_product
  - **Deliverable**: Discount mapping working
  - **Time**: 2 hours

- [ ] **Task 5.5**: Add coupon validation
  - Code uniqueness check
  - Date range validation
  - Quantity validation
  - Discount amount validation
  - **Deliverable**: Validation working
  - **Time**: 2 hours

- [ ] **Task 5.6**: Test coupon processor
  - Unit test: Create percentage coupon
  - Unit test: Create fixed amount coupon
  - Unit test: Duplicate code handling
  - Unit test: Update coupon
  - Integration test: End-to-end via webhook
  - **Deliverable**: All tests passing
  - **Time**: 2 hours

**Phase 5 Subtotal: 13 hours (1.6 days)**

---

## Phase 6: Debug Server Integration (0.5 days)

### Webhook Debug Server Modifications (4 tasks)

- [ ] **Task 6.1**: Modify `webhook_debug_server.py` to handle reverse_sync flag
  - Check for `reverse_sync` field in payload
  - Extract `source` and `destination` fields
  - Add special handling for reverse sync events
  - **Deliverable**: Modified handler
  - **Time**: 1 hour

- [ ] **Task 6.2**: Add colored output for reverse sync events
  - Use different color for reverse sync header
  - Display "🔄 REVERSE SYNC" marker
  - Show source → destination flow
  - Display result status differently
  - **Deliverable**: Colored console output
  - **Time**: 1 hour

- [ ] **Task 6.3**: Update HTML dashboard for reverse sync stats
  - Add reverse sync counter to statistics
  - Show reverse sync events separately
  - Add filter for reverse sync only
  - **Deliverable**: Updated dashboard
  - **Time**: 1 hour

- [ ] **Task 6.4**: Test debug server integration
  - Test receives reverse sync notifications
  - Test displays correct markers
  - Test statistics updated correctly
  - **Deliverable**: Debug server working
  - **Time**: 1 hour

**Phase 6 Subtotal: 4 hours (0.5 days)**

---

## Phase 7: Testing & QA (2 days)

### Unit Tests (5 tasks)

- [ ] **Task 7.1**: Write unit tests for OdooSalesReverseSyncContext
  - Test flag set/get/clear
  - Test operation ID storage
  - Test multiple sequential operations
  - **Deliverable**: Context tests passing
  - **Time**: 2 hours

- [ ] **Task 7.2**: Write unit tests for CustomerProcessor
  - Test createCustomer with valid data
  - Test createCustomer with duplicate email
  - Test updateCustomer by ID
  - Test updateCustomer by email lookup
  - Test field mapping
  - **Deliverable**: Customer tests passing
  - **Time**: 3 hours

- [ ] **Task 7.3**: Write unit tests for OrderProcessor
  - Test updateOrderStatus
  - Test updateTrackingNumber
  - Test updateInternalNote
  - Test order lookup by reference
  - **Deliverable**: Order tests passing
  - **Time**: 2 hours

- [ ] **Task 7.4**: Write unit tests for AddressProcessor
  - Test createAddress with valid data
  - Test address validation
  - Test customer linkage
  - Test updateAddress
  - **Deliverable**: Address tests passing
  - **Time**: 2 hours

- [ ] **Task 7.5**: Write unit tests for CouponProcessor
  - Test createCoupon percentage
  - Test createCoupon fixed amount
  - Test code uniqueness validation
  - Test updateCoupon
  - **Deliverable**: Coupon tests passing
  - **Time**: 2 hours

### Integration Tests (4 tasks)

- [ ] **Task 7.6**: Write loop prevention integration test
  - Send reverse webhook to PrestaShop
  - Verify entity updated
  - Verify NO outgoing webhook sent
  - Verify debug server received notification
  - **Deliverable**: Loop test passing
  - **Time**: 2 hours

- [ ] **Task 7.7**: Write end-to-end customer sync test
  - Send customer create webhook
  - Verify customer created in database
  - Verify debug notification sent
  - Send customer update webhook
  - Verify customer updated
  - **Deliverable**: E2E customer test passing
  - **Time**: 2 hours

- [ ] **Task 7.8**: Write end-to-end order update test
  - Create test order in PrestaShop
  - Send order status update webhook
  - Verify status changed
  - Verify no loop
  - **Deliverable**: E2E order test passing
  - **Time**: 2 hours

- [ ] **Task 7.9**: Write debug server notification test
  - Mock debug server endpoint
  - Send reverse webhooks
  - Verify notifications received with correct flags
  - **Deliverable**: Notification test passing
  - **Time**: 2 hours

### Manual Testing (6 tasks)

- [ ] **Task 7.10**: Manual test - Customer creation from Odoo
  - Use curl/Postman to send webhook
  - Verify customer created
  - Check database records
  - Check debug server output
  - **Deliverable**: Test results documented
  - **Time**: 1 hour

- [ ] **Task 7.11**: Manual test - Customer update from Odoo
  - Send update webhook
  - Verify customer updated
  - Verify no duplicate
  - **Deliverable**: Test results documented
  - **Time**: 1 hour

- [ ] **Task 7.12**: Manual test - Order status update
  - Send order update webhook
  - Verify status changed
  - Check order history
  - **Deliverable**: Test results documented
  - **Time**: 1 hour

- [ ] **Task 7.13**: Manual test - Address creation
  - Send address create webhook
  - Verify address linked to customer
  - Check validation working
  - **Deliverable**: Test results documented
  - **Time**: 1 hour

- [ ] **Task 7.14**: Manual test - Coupon creation
  - Send coupon create webhook
  - Verify cart rule created
  - Test coupon in cart
  - **Deliverable**: Test results documented
  - **Time**: 1 hour

- [ ] **Task 7.15**: Manual test - Security & error handling
  - Test invalid webhook secret (expect 403)
  - Test malformed JSON (expect 400)
  - Test unknown entity type (expect error)
  - Test missing required fields (expect error)
  - **Deliverable**: Security test results
  - **Time**: 1 hour

### Verification Tasks (2 tasks)

- [ ] **Task 7.16**: Verify no infinite loops in any scenario
  - Test: PrestaShop → Odoo → PrestaShop (should stop)
  - Monitor webhook logs
  - Monitor operation tracking table
  - **Deliverable**: Loop prevention verified
  - **Time**: 1 hour

- [ ] **Task 7.17**: Verify debug server receives all notifications
  - Review debug server logs
  - Check all entity types represented
  - Verify reverse_sync flags correct
  - **Deliverable**: Debug integration verified
  - **Time**: 1 hour

**Phase 7 Subtotal: 28 hours (3.5 days)**

---

## Phase 8: Documentation (1 day)

### API Documentation (4 tasks)

- [ ] **Task 8.1**: Create API documentation for reverse webhook payloads
  - Document payload structure for each entity
  - Required vs optional fields
  - Field types and validation rules
  - Example payloads for each scenario
  - **Deliverable**: Complete API docs
  - **Time**: 3 hours

- [ ] **Task 8.2**: Update README.md with reverse sync section
  - Add "Reverse Synchronization" section
  - Configuration instructions
  - Odoo webhook setup guide
  - **Deliverable**: Updated README
  - **Time**: 2 hours

- [ ] **Task 8.3**: Create usage examples for each entity type
  - Customer create example
  - Customer update example
  - Order update example
  - Address create example
  - Coupon create example
  - curl commands for each
  - **Deliverable**: Examples document
  - **Time**: 2 hours

- [ ] **Task 8.4**: Document troubleshooting procedures
  - Common errors and solutions
  - How to check operation tracking table
  - How to check logs
  - How to verify loop prevention
  - **Deliverable**: Troubleshooting guide
  - **Time**: 2 hours

**Phase 8 Subtotal: 9 hours (1.1 days)**

---

## Summary by Phase

| Phase | Tasks | Hours | Days | Status |
|-------|-------|-------|------|--------|
| **Phase 1: Foundation** | 12 | 20 | 2.5 | 📋 Planned |
| **Phase 2: Customer** | 6 | 13 | 1.6 | 📋 Planned |
| **Phase 3: Order** | 7 | 14 | 1.75 | 📋 Planned |
| **Phase 4: Address** | 6 | 11 | 1.4 | 📋 Planned |
| **Phase 5: Coupon** | 6 | 13 | 1.6 | 📋 Planned |
| **Phase 6: Debug** | 4 | 4 | 0.5 | 📋 Planned |
| **Phase 7: Testing** | 17 | 28 | 3.5 | 📋 Planned |
| **Phase 8: Documentation** | 4 | 9 | 1.1 | 📋 Planned |
| **TOTAL** | **62** | **112** | **14** | |

---

## Adjusted Timeline

**Original Estimate**: 10.5 days
**Detailed Breakdown**: 14 days (112 hours)

The detailed breakdown reveals additional tasks not accounted for in the initial estimate:
- More comprehensive testing (17 tasks vs initial estimate)
- Detailed validation logic for each entity
- Debug server integration tasks
- Lookup/fallback logic for entities

**Recommendation**: Plan for **14 working days** to ensure quality implementation and thorough testing.

---

## Critical Path

These tasks MUST be completed in order (dependencies):

1. Database Schema (Tasks 1.1-1.3)
2. Context Flag (Tasks 1.4-1.7)
3. Router & Endpoint (Tasks 1.8-1.12)
4. Entity Processors (Phases 2-5) - Can be parallel
5. Testing (Phase 7) - After processors complete
6. Documentation (Phase 8) - After testing

---

## Risk Mitigation

| Risk | Tasks Affected | Mitigation |
|------|---------------|------------|
| Loop prevention failure | 1.4-1.7, 7.16 | Comprehensive testing, code review |
| Order update complexity | 3.2-3.5 | Start with simple updates, iterate |
| Address validation issues | 4.4 | Thorough country/state validation |
| Coupon mapping errors | 5.4 | Study PrestaShop CartRule thoroughly |
| Test coverage gaps | Phase 7 | Detailed test planning upfront |

---

## Success Metrics

- [ ] All 62 tasks completed
- [ ] All unit tests passing (minimum 90% coverage)
- [ ] All integration tests passing
- [ ] All manual tests successful
- [ ] Zero infinite loops detected
- [ ] Debug server integration working
- [ ] Documentation complete and reviewed
- [ ] Code review completed
- [ ] Performance acceptable (< 2s per webhook)
- [ ] Security review passed

---

## Next Actions

1. **Review this task breakdown** - Stakeholder approval
2. **Adjust timeline** - Account for 14 days instead of 10.5
3. **Assign resources** - Identify who will work on each phase
4. **Create feature branch** - `git checkout -b feature/reverse-sync-v2.0.0`
5. **Set up project tracking** - Move tasks to project management tool
6. **Begin Phase 1** - Start with foundation tasks

---

**Document Status**: ✅ Detailed Task Breakdown Complete
**Total Tasks**: 62
**Revised Estimate**: 14 working days (112 hours)
**Confidence Level**: High (detailed analysis complete)
