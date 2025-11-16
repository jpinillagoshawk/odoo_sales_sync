<?php
/**
 * Sales Event Detector
 *
 * Main detection logic for normalizing PrestaShop hooks into standardized
 * sales events for Odoo synchronization.
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesEventDetector
{
    /** @var OdooSalesHookTracker */
    private $hookTracker;

    /** @var OdooSalesLogger */
    private $logger;

    /** @var OdooSalesRequestContext */
    private $context;

    /**
     * Constructor
     *
     * @param OdooSalesHookTracker $hookTracker Hook deduplication tracker
     * @param OdooSalesLogger $logger Logger instance
     * @param OdooSalesRequestContext $context Request context
     */
    public function __construct($hookTracker, $logger, $context)
    {
        $this->hookTracker = $hookTracker;
        $this->logger = $logger;
        $this->context = $context;
    }

    /**
     * Detect customer change (create/update/delete)
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type (created, updated, deleted)
     * @return bool Success
     */
    public function detectCustomerChange($hookName, $params, $action)
    {
        try {
            // CRITICAL: Check if this is a reverse sync operation (from Odoo)
            // If yes, skip event creation to prevent infinite webhook loops
            if (class_exists('OdooSalesReverseSyncContext') && OdooSalesReverseSyncContext::isReverseSync()) {
                $this->logger->debug('[LOOP_PREVENTION] Skipping customer event - reverse sync operation', [
                    'hook' => $hookName,
                    'operation_id' => OdooSalesReverseSyncContext::getOperationId(),
                    'entity_type' => 'customer'
                ]);
                return true; // Return true to not break the hook chain
            }

            if (!isset($params['object'])) {
                $this->logger->debug('Customer hook missing object parameter (expected for some hooks)', [
                    'hook' => $hookName,
                    'params_keys' => array_keys($params)
                ]);
                return false;
            }

            $customer = $params['object'];

            if (!Validate::isLoadedObject($customer)) {
                $this->logger->error('Invalid customer object', [
                    'hook' => $hookName,
                    'customer_id' => isset($customer->id) ? $customer->id : null
                ]);
                return false;
            }

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = 'customer';
            $event->entity_id = $customer->id;
            $event->entity_name = $customer->firstname . ' ' . $customer->lastname;
            $event->action_type = $action;
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('customer', $customer->id, $action);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture data
            $customerData = [
                'id' => $customer->id,
                'email' => $customer->email,
                'firstname' => $customer->firstname,
                'lastname' => $customer->lastname,
                'id_default_group' => $customer->id_default_group,
                'active' => $customer->active,
                'newsletter' => $customer->newsletter,
                'optin' => $customer->optin,
                'date_add' => $customer->date_add,
                'date_upd' => $customer->date_upd
            ];

            $event->after_data = json_encode($customerData);
            $event->change_summary = ucfirst($action) . ' customer: ' . $event->entity_name;

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, 'customer', $customer->id, $action)) {
                $this->logger->debug('Duplicate customer event prevented', [
                    'customer_id' => $customer->id,
                    'action' => $action,
                    'hook' => $hookName
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $errorMessages = method_exists($event, 'getErrors') ? $event->getErrors() : [];

                // Get database error
                $db = Db::getInstance();
                $dbError = $db->getMsgError();
                $dbErrno = $db->getNumberError();

                // Check if table exists
                $tableExists = $db->executeS("SHOW TABLES LIKE '" . _DB_PREFIX_ . "odoo_sales_events'");

                $this->logger->error('Failed to save customer event', [
                    'customer_id' => $customer->id,
                    'action' => $action,
                    'validation_errors' => $errorMessages,
                    'db_error' => $dbError,
                    'db_errno' => $dbErrno,
                    'table_exists' => !empty($tableExists),
                    'event_data' => [
                        'entity_type' => $event->entity_type,
                        'entity_id' => $event->entity_id,
                        'entity_name' => $event->entity_name,
                        'action_type' => $event->action_type,
                        'transaction_hash' => $event->transaction_hash,
                        'hook_name' => $event->hook_name,
                        'hook_timestamp' => $event->hook_timestamp,
                        'correlation_id' => $event->correlation_id,
                        'after_data_length' => strlen($event->after_data),
                        'change_summary' => $event->change_summary
                    ]
                ]);
                return false;
            }

            $this->logger->info('Customer event detected', [
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'action' => $action,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectCustomerChange failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect address change (normalized to customer update)
     *
     * Address changes are normalized into customer update events because
     * Odoo tracks addresses as part of customer records.
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type (created, updated, deleted)
     * @return bool Success
     */
    public function detectAddressChange($hookName, $params, $action)
    {
        try {
            // CRITICAL: Loop prevention for reverse sync operations
            if (class_exists('OdooSalesReverseSyncContext') && OdooSalesReverseSyncContext::isReverseSync()) {
                $this->logger->debug('[LOOP_PREVENTION] Skipping address event - reverse sync operation', [
                    'hook' => $hookName,
                    'operation_id' => OdooSalesReverseSyncContext::getOperationId()
                ]);
                return true;
            }

            if (!isset($params['object'])) {
                $this->logger->warning('Address hook missing object parameter', [
                    'hook' => $hookName
                ]);
                return false;
            }

            $address = $params['object'];

            if (!Validate::isLoadedObject($address)) {
                $this->logger->error('Invalid address object', [
                    'hook' => $hookName,
                    'address_id' => isset($address->id) ? $address->id : null
                ]);
                return false;
            }

            // Defensive: Check if address has customer
            if (!$address->id_customer) {
                $this->logger->warning('Address has no customer', [
                    'address_id' => $address->id,
                    'hook' => $hookName
                ]);
                return false;
            }

            // Load customer
            $customer = new Customer($address->id_customer);

            if (!Validate::isLoadedObject($customer)) {
                $this->logger->error('Failed to load customer for address', [
                    'customer_id' => $address->id_customer,
                    'address_id' => $address->id
                ]);
                return false;
            }

            // Create customer event with address context
            $event = new OdooSalesEvent();
            $event->entity_type = 'customer';
            $event->entity_id = $customer->id;
            $event->entity_name = $customer->firstname . ' ' . $customer->lastname;
            $event->action_type = 'updated'; // Address change is customer update
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('customer', $customer->id, 'updated_address_' . $address->id);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture address data
            $addressData = [
                'id' => $address->id,
                'alias' => $address->alias,
                'company' => $address->company,
                'firstname' => $address->firstname,
                'lastname' => $address->lastname,
                'address1' => $address->address1,
                'address2' => $address->address2,
                'postcode' => $address->postcode,
                'city' => $address->city,
                'id_country' => $address->id_country,
                'id_state' => $address->id_state,
                'phone' => $address->phone,
                'phone_mobile' => $address->phone_mobile
            ];

            $event->after_data = json_encode($addressData);
            $event->context_data = json_encode([
                'change_type' => 'address',
                'address_id' => $address->id,
                'address_action' => $action,
                'address_alias' => $address->alias
            ]);
            $event->change_summary = 'Customer address ' . $action . ': ' . $address->alias;

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, 'customer', $customer->id, 'updated_address')) {
                $this->logger->debug('Duplicate address event prevented', [
                    'customer_id' => $customer->id,
                    'address_id' => $address->id,
                    'action' => $action
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save address event', [
                    'customer_id' => $customer->id,
                    'address_id' => $address->id,
                    'action' => $action
                ]);
                return false;
            }

            $this->logger->info('Address event detected (normalized to customer)', [
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'address_id' => $address->id,
                'action' => $action,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectAddressChange failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect order change (create/update/status change) with complete order data
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type (created, updated, status_changed)
     * @return bool Success
     */
    public function detectOrderChange($hookName, $params, $action)
    {
        try {
            // CRITICAL: Loop prevention for reverse sync operations
            if (class_exists('OdooSalesReverseSyncContext') && OdooSalesReverseSyncContext::isReverseSync()) {
                $this->logger->debug('[LOOP_PREVENTION] Skipping order event - reverse sync operation', [
                    'hook' => $hookName,
                    'operation_id' => OdooSalesReverseSyncContext::getOperationId()
                ]);
                return true;
            }

            $order = null;

            // Extract order from different hook parameters
            if (isset($params['order'])) {
                $order = $params['order'];
            } elseif (isset($params['object'])) {
                $order = $params['object'];
            } elseif (isset($params['id_order'])) {
                // actionOrderStatusUpdate provides id_order instead of object
                $orderId = (int)$params['id_order'];
                $this->logger->debug('Loading order from id_order parameter', [
                    'hook' => $hookName,
                    'id_order' => $orderId
                ]);
                $order = new Order($orderId);
            }

            if (!$order || !Validate::isLoadedObject($order)) {
                $this->logger->error('Invalid order object', [
                    'hook' => $hookName,
                    'params_keys' => array_keys($params),
                    'id_order' => isset($params['id_order']) ? $params['id_order'] : null,
                    'order_loaded' => $order ? 'object_exists' : 'null',
                    'order_id' => ($order && isset($order->id)) ? $order->id : 'no_id'
                ]);
                return false;
            }

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = 'order';
            $event->entity_id = $order->id;
            $event->entity_name = $order->reference;
            $event->action_type = $action;
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('order', $order->id, $action);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture comprehensive order data
            $orderData = [
                // Order Header Fields (matching webhook requirements)
                'id_order' => (int)$order->id,
                'reference' => $order->reference,
                'date_add' => $order->date_add,
                'date_upd' => $order->date_upd,
                'current_state' => (int)$order->current_state,
                'id_order_state' => (int)$order->current_state,

                // Customer information
                'id_customer' => (int)$order->id_customer,
                'id_cart' => (int)$order->id_cart,

                // Order amounts
                'total_paid' => (float)$order->total_paid,
                'total_paid_tax_incl' => (float)$order->total_paid_tax_incl,
                'total_paid_tax_excl' => (float)$order->total_paid_tax_excl,
                'total_products' => (float)$order->total_products,
                'total_products_wt' => (float)$order->total_products_wt,
                'total_discounts' => (float)$order->total_discounts,
                'total_discounts_tax_incl' => (float)$order->total_discounts_tax_incl,
                'total_discounts_tax_excl' => (float)$order->total_discounts_tax_excl,
                'total_shipping' => (float)$order->total_shipping,
                'total_shipping_tax_incl' => (float)$order->total_shipping_tax_incl,
                'total_shipping_tax_excl' => (float)$order->total_shipping_tax_excl,
                'total_wrapping' => (float)$order->total_wrapping,
                'total_wrapping_tax_incl' => (float)$order->total_wrapping_tax_incl,
                'total_wrapping_tax_excl' => (float)$order->total_wrapping_tax_excl,

                // Payment information
                'payment' => $order->payment,
                'module' => $order->module,

                // Notes and messages (for migration)
                'note' => $order->note, // Internal/private notes

                // Shipping information
                'id_carrier' => (int)$order->id_carrier,
                'shipping_number' => $order->shipping_number,

                // Currency
                'id_currency' => (int)$order->id_currency,
                'conversion_rate' => (float)$order->conversion_rate,

                // Order details with complete product information
                'order_details' => $this->extractOrderLines($order),

                // Order status history
                'order_history' => $this->extractOrderHistory($order),

                // Order payments
                'order_payments' => $this->extractOrderPayments($order),

                // Customer messages (for migration)
                'messages' => $this->extractOrderMessages($order)
            ];

            $event->after_data = json_encode($orderData);
            $event->change_summary = ucfirst($action) . ' order: ' . $order->reference;

            // Add status change context
            $contextData = [];
            if ($action === 'status_changed' && isset($params['newOrderStatus'])) {
                $newStatus = $params['newOrderStatus'];
                $contextData = [
                    'new_status_id' => $newStatus->id,
                    'new_status_name' => isset($newStatus->name) ? $newStatus->name : '',
                    'id_employee' => isset($params['id_employee']) ? (int)$params['id_employee'] : 0
                ];
            }

            // Add employee context if available (for status changes)
            if (isset(Context::getContext()->employee) && Validate::isLoadedObject(Context::getContext()->employee)) {
                $contextData['id_employee_context'] = (int)Context::getContext()->employee->id;
            }

            if (!empty($contextData)) {
                $event->context_data = json_encode($contextData);
            }

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, 'order', $order->id, $action)) {
                $this->logger->debug('Duplicate order event prevented', [
                    'order_id' => $order->id,
                    'action' => $action
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save order event', [
                    'order_id' => $order->id,
                    'action' => $action
                ]);
                return false;
            }

            $this->logger->info('Order event detected with complete data', [
                'event_id' => $event->id,
                'order_id' => $order->id,
                'action' => $action,
                'correlation_id' => $event->correlation_id,
                'order_lines_count' => count($orderData['order_details']),
                'history_count' => count($orderData['order_history']),
                'payments_count' => count($orderData['order_payments']),
                'messages_count' => count($orderData['messages'])
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectOrderChange failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect invoice change (invoice created/updated, PDF rendered, credit memo)
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type
     * @return bool Success
     */
    public function detectInvoiceChange($hookName, $params, $action)
    {
        try {
            $invoice = null;

            // Extract invoice from parameters
            if (isset($params['object'])) {
                $invoice = $params['object'];
            } elseif (isset($params['order_invoice_list']) && is_array($params['order_invoice_list']) && !empty($params['order_invoice_list'])) {
                // actionPDFInvoiceRender provides array of invoices - take first valid one
                $invoice = reset($params['order_invoice_list']);
            }

            if (!$invoice || !Validate::isLoadedObject($invoice)) {
                // Not necessarily an error - some hooks fire with empty arrays
                $this->logger->debug('Invalid or missing invoice object (expected for some hooks)', [
                    'hook' => $hookName,
                    'params_keys' => array_keys($params),
                    'has_invoice_list' => isset($params['order_invoice_list']),
                    'invoice_list_count' => isset($params['order_invoice_list']) && is_array($params['order_invoice_list']) ? count($params['order_invoice_list']) : 0
                ]);
                return false;
            }

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = 'invoice';
            $event->entity_id = $invoice->id;
            $event->entity_name = $invoice->number ? $invoice->number : 'Invoice #' . $invoice->id;
            $event->action_type = $action;
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('invoice', $invoice->id, $action);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture invoice data
            $invoiceData = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'id_order' => $invoice->id_order,
                'total_paid_tax_incl' => $invoice->total_paid_tax_incl,
                'total_paid_tax_excl' => $invoice->total_paid_tax_excl,
                'date_add' => $invoice->date_add,
                'invoice_lines' => $this->extractInvoiceLines($invoice)
            ];

            $event->after_data = json_encode($invoiceData);
            $event->change_summary = ucfirst($action) . ' invoice: ' . $event->entity_name;

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, 'invoice', $invoice->id, $action)) {
                $this->logger->debug('Duplicate invoice event prevented', [
                    'invoice_id' => $invoice->id,
                    'action' => $action
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save invoice event', [
                    'invoice_id' => $invoice->id,
                    'action' => $action
                ]);
                return false;
            }

            $this->logger->info('Invoice event detected', [
                'event_id' => $event->id,
                'invoice_id' => $invoice->id,
                'action' => $action,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectInvoiceChange failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect payment event (payment received/confirmed)
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type (received, confirmed, refunded)
     * @return bool Success
     */
    public function detectPaymentEvent($hookName, $params, $action)
    {
        try {
            $payment = null;

            // Extract payment from parameters
            if (isset($params['paymentCC'])) {
                // actionPaymentCCAdd provides paymentCC
                $payment = $params['paymentCC'];
            } elseif (isset($params['object'])) {
                // actionObjectOrderPaymentAddAfter provides object
                $payment = $params['object'];
            }

            if (!$payment || !Validate::isLoadedObject($payment)) {
                $this->logger->error('Invalid payment object', [
                    'hook' => $hookName,
                    'params_keys' => array_keys($params)
                ]);
                return false;
            }

            // Get order reference for context
            $orderReference = $payment->order_reference;

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = 'payment';
            $event->entity_id = $payment->id;
            $event->entity_name = 'Payment #' . ($payment->transaction_id ? $payment->transaction_id : $payment->id);
            $event->action_type = $action;
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('payment', $payment->id, $action);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture payment data
            $paymentData = [
                'id' => $payment->id,
                'order_reference' => $payment->order_reference,
                'id_currency' => $payment->id_currency,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'transaction_id' => $payment->transaction_id,
                'card_number' => $payment->card_number, // Already masked by PrestaShop
                'card_brand' => $payment->card_brand,
                'card_expiration' => $payment->card_expiration,
                'card_holder' => $payment->card_holder,
                'date_add' => $payment->date_add,
                'conversion_rate' => $payment->conversion_rate
            ];

            $event->after_data = json_encode($paymentData);
            $event->context_data = json_encode([
                'order_reference' => $orderReference,
                'payment_method' => $payment->payment_method
            ]);
            $event->change_summary = ucfirst($action) . ' payment: ' . $event->entity_name . ' (' . $payment->payment_method . ')';

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, 'payment', $payment->id, $action)) {
                $this->logger->debug('Duplicate payment event prevented', [
                    'payment_id' => $payment->id,
                    'action' => $action,
                    'transaction_id' => $payment->transaction_id
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save payment event', [
                    'payment_id' => $payment->id,
                    'action' => $action,
                    'transaction_id' => $payment->transaction_id
                ]);
                return false;
            }

            $this->logger->info('Payment event detected', [
                'event_id' => $event->id,
                'payment_id' => $payment->id,
                'action' => $action,
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectPaymentEvent failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect coupon/discount CRUD operations
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param string $action Action type (created, updated, deleted)
     * @return bool Success
     */
    public function detectCouponChange($hookName, $params, $action)
    {
        try {
            // CRITICAL: Loop prevention for reverse sync operations
            if (class_exists('OdooSalesReverseSyncContext') && OdooSalesReverseSyncContext::isReverseSync()) {
                $this->logger->debug('[LOOP_PREVENTION] Skipping coupon event - reverse sync operation', [
                    'hook' => $hookName,
                    'operation_id' => OdooSalesReverseSyncContext::getOperationId()
                ]);
                return true;
            }

            if (!isset($params['object'])) {
                $this->logger->warning('Coupon hook missing object parameter', [
                    'hook' => $hookName
                ]);
                return false;
            }

            $coupon = $params['object'];

            if (!Validate::isLoadedObject($coupon)) {
                $this->logger->error('Invalid coupon object', [
                    'hook' => $hookName
                ]);
                return false;
            }

            // Determine entity type based on class
            $entityType = (get_class($coupon) === 'CartRule') ? 'coupon' : 'discount';

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = $entityType;
            $event->entity_id = $coupon->id;
            $event->entity_name = isset($coupon->code) ? $coupon->code : ($coupon->name ? $coupon->name : 'Discount #' . $coupon->id);
            $event->action_type = $action;
            $event->hook_name = $hookName;
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash($entityType, $coupon->id, $action);
            $event->correlation_id = $this->context->getCorrelationId();

            // Capture complete coupon/cart rule data with ALL fields from PrestaShop CartRule class
            $couponData = [
                // ===== CART RULE IDENTIFIERS =====
                'id_cart_rule' => (int)$coupon->id,
                'id' => (int)$coupon->id,
                'code' => isset($coupon->code) ? $coupon->code : '',
                'name' => isset($coupon->name) ? $coupon->name : [],
                'description' => isset($coupon->description) ? $coupon->description : '',

                // ===== CUSTOMER RESTRICTION =====
                'id_customer' => isset($coupon->id_customer) ? (int)$coupon->id_customer : 0,

                // ===== VALIDITY DATES =====
                'date_from' => isset($coupon->date_from) ? $coupon->date_from : null,
                'date_to' => isset($coupon->date_to) ? $coupon->date_to : null,
                'date_add' => isset($coupon->date_add) ? $coupon->date_add : null,
                'date_upd' => isset($coupon->date_upd) ? $coupon->date_upd : null,

                // ===== USAGE LIMITS =====
                'quantity' => isset($coupon->quantity) ? (int)$coupon->quantity : 1,
                'quantity_per_user' => isset($coupon->quantity_per_user) ? (int)$coupon->quantity_per_user : 1,
                'priority' => isset($coupon->priority) ? (int)$coupon->priority : 1,
                'partial_use' => isset($coupon->partial_use) ? (bool)$coupon->partial_use : true,

                // ===== MINIMUM PURCHASE CONDITIONS =====
                'minimum_amount' => isset($coupon->minimum_amount) ? (float)$coupon->minimum_amount : 0.0,
                'minimum_amount_tax' => isset($coupon->minimum_amount_tax) ? (bool)$coupon->minimum_amount_tax : false,
                'minimum_amount_currency' => isset($coupon->minimum_amount_currency) ? (int)$coupon->minimum_amount_currency : 0,
                'minimum_amount_shipping' => isset($coupon->minimum_amount_shipping) ? (bool)$coupon->minimum_amount_shipping : false,

                // ===== RESTRICTIONS =====
                'country_restriction' => isset($coupon->country_restriction) ? (bool)$coupon->country_restriction : false,
                'carrier_restriction' => isset($coupon->carrier_restriction) ? (bool)$coupon->carrier_restriction : false,
                'group_restriction' => isset($coupon->group_restriction) ? (bool)$coupon->group_restriction : false,
                'cart_rule_restriction' => isset($coupon->cart_rule_restriction) ? (bool)$coupon->cart_rule_restriction : false,
                'product_restriction' => isset($coupon->product_restriction) ? (bool)$coupon->product_restriction : false,
                'shop_restriction' => isset($coupon->shop_restriction) ? (bool)$coupon->shop_restriction : false,

                // ===== DISCOUNT TYPE - FREE SHIPPING =====
                'free_shipping' => isset($coupon->free_shipping) ? (bool)$coupon->free_shipping : false,

                // ===== DISCOUNT TYPE - PERCENTAGE =====
                'reduction_percent' => isset($coupon->reduction_percent) ? (float)$coupon->reduction_percent : 0.0,

                // ===== DISCOUNT TYPE - AMOUNT =====
                'reduction_amount' => isset($coupon->reduction_amount) ? (float)$coupon->reduction_amount : 0.0,
                'reduction_tax' => isset($coupon->reduction_tax) ? (bool)$coupon->reduction_tax : false,
                'reduction_currency' => isset($coupon->reduction_currency) ? (int)$coupon->reduction_currency : 0,

                // ===== DISCOUNT TYPE - SPECIFIC PRODUCT =====
                'reduction_product' => isset($coupon->reduction_product) ? (int)$coupon->reduction_product : 0,
                'reduction_exclude_special' => isset($coupon->reduction_exclude_special) ? (bool)$coupon->reduction_exclude_special : false,

                // ===== DISCOUNT TYPE - FREE GIFT =====
                'gift_product' => isset($coupon->gift_product) ? (int)$coupon->gift_product : 0,
                'gift_product_attribute' => isset($coupon->gift_product_attribute) ? (int)$coupon->gift_product_attribute : 0,

                // ===== STATUS =====
                'highlight' => isset($coupon->highlight) ? (bool)$coupon->highlight : false,
                'active' => isset($coupon->active) ? (bool)$coupon->active : true
            ];

            $event->after_data = json_encode($couponData);
            $event->change_summary = ucfirst($action) . ' ' . $entityType . ': ' . $event->entity_name;

            // Check for duplicates
            if ($this->hookTracker->isDuplicate($hookName, $entityType, $coupon->id, $action)) {
                $this->logger->debug('Duplicate coupon event prevented', [
                    'coupon_id' => $coupon->id,
                    'action' => $action
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save coupon event', [
                    'coupon_id' => $coupon->id,
                    'action' => $action
                ]);
                return false;
            }

            $this->logger->info('Coupon event detected', [
                'event_id' => $event->id,
                'coupon_id' => $coupon->id,
                'action' => $action,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectCouponChange failed', [
                'hook' => $hookName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Detect coupon usage (applied/removed/consumed)
     *
     * Called by CartRuleUsageTracker for synthetic events.
     *
     * @param array $params Synthetic parameters with cart_rule, cart_id, usage_action
     * @return bool Success
     */
    public function detectCouponUsage($params)
    {
        try {
            $cartRule = $params['cart_rule'];
            $cartId = $params['cart_id'];
            $usageAction = $params['usage_action'];

            // Create event
            $event = new OdooSalesEvent();
            $event->entity_type = 'coupon';
            $event->entity_id = $cartRule->id;
            $event->entity_name = $cartRule->code ? $cartRule->code : $cartRule->name;
            $event->action_type = $usageAction; // applied, removed, consumed
            $event->hook_name = 'actionCartSave_synthetic';
            $event->hook_timestamp = date('Y-m-d H:i:s');
            $event->transaction_hash = $this->generateTransactionHash('coupon_usage', $cartRule->id, $usageAction . '_' . $cartId);
            $event->correlation_id = $this->context->getCorrelationId();

            // Try to get order information if cart has been converted to order
            $orderId = null;
            $orderReference = null;
            $orderTotal = null;

            if ($cartId) {
                $sql = 'SELECT id_order, reference, total_paid_tax_incl
                        FROM ' . _DB_PREFIX_ . 'orders
                        WHERE id_cart = ' . (int)$cartId . '
                        LIMIT 1';
                $orderInfo = Db::getInstance()->getRow($sql);
                if ($orderInfo) {
                    $orderId = (int)$orderInfo['id_order'];
                    $orderReference = $orderInfo['reference'];
                    $orderTotal = (float)$orderInfo['total_paid_tax_incl'];
                }
            }

            // Capture complete usage data with order relationship
            $usageData = [
                // Cart rule info
                'id_cart_rule' => (int)$cartRule->id,
                'code' => $cartRule->code,
                'name' => isset($cartRule->name) ? $cartRule->name : [],

                // Discount details
                'reduction_amount' => isset($cartRule->reduction_amount) ? (float)$cartRule->reduction_amount : 0.0,
                'reduction_percent' => isset($cartRule->reduction_percent) ? (float)$cartRule->reduction_percent : 0.0,
                'reduction_tax' => isset($cartRule->reduction_tax) ? (bool)$cartRule->reduction_tax : false,
                'reduction_currency' => isset($cartRule->reduction_currency) ? (int)$cartRule->reduction_currency : 0,
                'free_shipping' => isset($cartRule->free_shipping) ? (bool)$cartRule->free_shipping : false,

                // Usage context
                'cart_id' => (int)$cartId,
                'usage_action' => $usageAction,

                // Order relationship (if cart converted to order)
                'order_id' => $orderId,
                'order_reference' => $orderReference,
                'order_total' => $orderTotal,

                // Customer info
                'id_customer' => isset($cartRule->id_customer) ? (int)$cartRule->id_customer : 0,

                // Validity
                'date_from' => isset($cartRule->date_from) ? $cartRule->date_from : null,
                'date_to' => isset($cartRule->date_to) ? $cartRule->date_to : null,

                // Restrictions
                'minimum_amount' => isset($cartRule->minimum_amount) ? (float)$cartRule->minimum_amount : 0.0,
                'minimum_amount_tax' => isset($cartRule->minimum_amount_tax) ? (bool)$cartRule->minimum_amount_tax : false
            ];

            $event->after_data = json_encode($usageData);
            $event->context_data = json_encode([
                'usage_action' => $usageAction,
                'cart_id' => $cartId,
                'order_id' => $orderId,
                'order_reference' => $orderReference
            ]);

            $changeSummary = 'Coupon ' . $usageAction . ': ' . $event->entity_name . ' (cart #' . $cartId;
            if ($orderId) {
                $changeSummary .= ', order #' . $orderId . ' ' . $orderReference;
            }
            $changeSummary .= ')';
            $event->change_summary = $changeSummary;

            // Check for duplicates
            if ($this->hookTracker->isDuplicate('coupon_usage', 'coupon', $cartRule->id, $usageAction . '_cart_' . $cartId)) {
                $this->logger->debug('Duplicate coupon usage event prevented', [
                    'coupon_id' => $cartRule->id,
                    'action' => $usageAction,
                    'cart_id' => $cartId
                ]);
                return false;
            }

            // Save event
            if (!$event->save()) {
                $this->logger->error('Failed to save coupon usage event', [
                    'coupon_id' => $cartRule->id,
                    'action' => $usageAction
                ]);
                return false;
            }

            $this->logger->info('Coupon usage event detected', [
                'event_id' => $event->id,
                'coupon_id' => $cartRule->id,
                'action' => $usageAction,
                'cart_id' => $cartId,
                'correlation_id' => $event->correlation_id
            ]);

            // Queue event for async sending
            require_once(dirname(__FILE__) . '/OdooSalesEventQueue.php');
            OdooSalesEventQueue::queueEvent($event);

            return true;
        } catch (Exception $e) {
            $this->logger->error('detectCouponUsage failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Extract order lines (products) from order with complete product details
     *
     * @param Order $order Order object
     * @return array Order lines data with all available product information
     */
    private function extractOrderLines($order)
    {
        try {
            $orderLines = [];
            $products = $order->getProducts();

            if (!$products || !is_array($products)) {
                $this->logger->debug('No products found for order', [
                    'order_id' => $order->id,
                    'order_reference' => $order->reference
                ]);
                return [];
            }

            // Limit to 100 lines to avoid excessively large JSON payloads
            $lineCount = 0;
            $maxLines = 100;

            foreach ($products as $product) {
                if ($lineCount >= $maxLines) {
                    $this->logger->warning('Order has too many lines, truncating', [
                        'order_id' => $order->id,
                        'total_lines' => count($products),
                        'max_lines' => $maxLines
                    ]);
                    break;
                }

                // Complete product line data with ALL fields from PrestaShop Order class
                $orderLines[] = [
                    // ===== ORDER DETAIL IDENTIFIERS =====
                    'id_order_detail' => isset($product['id_order_detail']) ? (int)$product['id_order_detail'] : null,
                    'id_order' => isset($product['id_order']) ? (int)$product['id_order'] : (int)$order->id,
                    'id_order_invoice' => isset($product['id_order_invoice']) ? (int)$product['id_order_invoice'] : 0,
                    'id_shop' => isset($product['id_shop']) ? (int)$product['id_shop'] : null,
                    'id_warehouse' => isset($product['id_warehouse']) ? (int)$product['id_warehouse'] : 0,

                    // ===== PRODUCT IDENTIFIERS =====
                    'product_id' => isset($product['product_id']) ? (int)$product['product_id'] : null,
                    'product_attribute_id' => isset($product['product_attribute_id']) ? (int)$product['product_attribute_id'] : 0,
                    'id_product_attribute' => isset($product['id_product_attribute']) ? (int)$product['id_product_attribute'] :
                        (isset($product['product_attribute_id']) ? (int)$product['product_attribute_id'] : 0),

                    // ===== PRODUCT DISPLAY AND REFERENCE INFO =====
                    'product_name' => isset($product['product_name']) ? $product['product_name'] : '',
                    'product_reference' => isset($product['product_reference']) ? $product['product_reference'] : '',
                    'product_supplier_reference' => isset($product['product_supplier_reference']) ? $product['product_supplier_reference'] : '',
                    'product_ean13' => isset($product['product_ean13']) ? $product['product_ean13'] : '',
                    'product_upc' => isset($product['product_upc']) ? $product['product_upc'] : '',
                    'product_isbn' => isset($product['product_isbn']) ? $product['product_isbn'] : '',
                    'product_mpn' => isset($product['product_mpn']) ? $product['product_mpn'] : '',

                    // ===== QUANTITY INFORMATION =====
                    'product_quantity' => isset($product['product_quantity']) ? (int)$product['product_quantity'] : 0,
                    'cart_quantity' => isset($product['cart_quantity']) ? (int)$product['cart_quantity'] :
                        (isset($product['product_quantity']) ? (int)$product['product_quantity'] : 0),
                    'product_quantity_in_stock' => isset($product['product_quantity_in_stock']) ? (int)$product['product_quantity_in_stock'] : 0,
                    'product_quantity_refunded' => isset($product['product_quantity_refunded']) ? (int)$product['product_quantity_refunded'] : 0,
                    'product_quantity_return' => isset($product['product_quantity_return']) ? (int)$product['product_quantity_return'] : 0,
                    'product_quantity_reinjected' => isset($product['product_quantity_reinjected']) ? (int)$product['product_quantity_reinjected'] : 0,
                    'product_quantity_remaining' => isset($product['product_quantity']) && isset($product['product_quantity_refunded'])
                        ? (int)$product['product_quantity'] - (int)$product['product_quantity_refunded']
                        : (isset($product['product_quantity']) ? (int)$product['product_quantity'] : 0),
                    'product_quantity_discount' => isset($product['product_quantity_discount']) ? (float)$product['product_quantity_discount'] : 0.0,

                    // ===== STOCK INFORMATION (from getProducts with fullInfos=true) =====
                    'current_stock' => isset($product['current_stock']) ? (int)$product['current_stock'] : null,
                    'location' => isset($product['location']) ? $product['location'] : '',
                    'advanced_stock_management' => isset($product['advanced_stock_management']) ? (int)$product['advanced_stock_management'] : 0,

                    // ===== PRICING - UNIT PRICES =====
                    'unit_price_tax_incl' => isset($product['unit_price_tax_incl']) ? (float)$product['unit_price_tax_incl'] : 0.0,
                    'unit_price_tax_excl' => isset($product['unit_price_tax_excl']) ? (float)$product['unit_price_tax_excl'] : 0.0,
                    'product_price' => isset($product['product_price']) ? (float)$product['product_price'] :
                        (isset($product['unit_price_tax_excl']) ? (float)$product['unit_price_tax_excl'] : 0.0),
                    'product_price_wt' => isset($product['product_price_wt']) ? (float)$product['product_price_wt'] :
                        (isset($product['unit_price_tax_incl']) ? (float)$product['unit_price_tax_incl'] : 0.0),
                    'original_product_price' => isset($product['original_product_price']) ? (float)$product['original_product_price'] :
                        (isset($product['unit_price_tax_excl']) ? (float)$product['unit_price_tax_excl'] : 0.0),
                    'product_price_wt_but_ecotax' => isset($product['product_price_wt_but_ecotax']) ? (float)$product['product_price_wt_but_ecotax'] : 0.0,

                    // ===== PRICING - TOTAL PRICES =====
                    'total_price_tax_incl' => isset($product['total_price_tax_incl']) ? (float)$product['total_price_tax_incl'] : 0.0,
                    'total_price_tax_excl' => isset($product['total_price_tax_excl']) ? (float)$product['total_price_tax_excl'] : 0.0,
                    'total_wt' => isset($product['total_wt']) ? (float)$product['total_wt'] :
                        (isset($product['total_price_tax_incl']) ? (float)$product['total_price_tax_incl'] : 0.0),
                    'total_price' => isset($product['total_price']) ? (float)$product['total_price'] :
                        (isset($product['total_price_tax_excl']) ? (float)$product['total_price_tax_excl'] : 0.0),

                    // ===== TAX INFORMATION =====
                    'product_tax' => isset($product['total_price_tax_incl']) && isset($product['total_price_tax_excl'])
                        ? (float)$product['total_price_tax_incl'] - (float)$product['total_price_tax_excl']
                        : 0.0,
                    'tax_rate' => isset($product['tax_rate']) ? (float)$product['tax_rate'] : 0.0,
                    'tax_calculator' => isset($product['tax_calculator']) ? 'object' : null, // Skip object

                    // ===== DISCOUNTS AND REDUCTIONS =====
                    'reduction_percent' => isset($product['reduction_percent']) ? (float)$product['reduction_percent'] : 0.0,
                    'reduction_amount' => isset($product['reduction_amount']) ? (float)$product['reduction_amount'] : 0.0,
                    'reduction_amount_tax_incl' => isset($product['reduction_amount_tax_incl']) ? (float)$product['reduction_amount_tax_incl'] : 0.0,
                    'reduction_amount_tax_excl' => isset($product['reduction_amount_tax_excl']) ? (float)$product['reduction_amount_tax_excl'] : 0.0,
                    'group_reduction' => isset($product['group_reduction']) ? (float)$product['group_reduction'] : 0.0,
                    'reduction_type' => isset($product['reduction_type']) ? (int)$product['reduction_type'] : 0,
                    'reduction_applies' => isset($product['reduction_applies']) ? (float)$product['reduction_applies'] : 0.0,
                    'discount_quantity_applied' => isset($product['discount_quantity_applied']) ? (int)$product['discount_quantity_applied'] : 0,

                    // ===== PRODUCT ATTRIBUTES =====
                    'product_weight' => isset($product['product_weight']) ? (float)$product['product_weight'] : 0.0,
                    'ecotax' => isset($product['ecotax']) ? (float)$product['ecotax'] : 0.0,
                    'ecotax_tax_rate' => isset($product['ecotax_tax_rate']) ? (float)$product['ecotax_tax_rate'] : 0.0,
                    'is_virtual' => isset($product['is_virtual']) ? (bool)$product['is_virtual'] : false,

                    // ===== DOWNLOAD/VIRTUAL PRODUCT INFO =====
                    'download_hash' => isset($product['download_hash']) ? $product['download_hash'] : null,
                    'download_deadline' => isset($product['download_deadline']) ? $product['download_deadline'] : null,
                    'download_nb' => isset($product['download_nb']) ? (int)$product['download_nb'] : 0,
                    'filename' => isset($product['filename']) ? $product['filename'] : null,
                    'display_filename' => isset($product['display_filename']) ? $product['display_filename'] : null,

                    // ===== CUSTOMIZATION =====
                    'id_customization' => isset($product['id_customization']) ? (int)$product['id_customization'] : 0,
                    'customization' => isset($product['customization']) ? $product['customization'] : null,
                    'customizedDatas' => isset($product['customizedDatas']) ? $product['customizedDatas'] : null,
                    'customizationQuantityTotal' => isset($product['customizationQuantityTotal']) ? (int)$product['customizationQuantityTotal'] : 0,

                    // ===== IMAGE INFORMATION =====
                    'image' => isset($product['image']) && is_object($product['image']) && isset($product['image']->id) ?
                        ['id_image' => (int)$product['image']->id] : null,
                    'image_size' => isset($product['image_size']) ? $product['image_size'] : null,
                    'id_address_delivery' => isset($product['id_address_delivery']) ? (int)$product['id_address_delivery'] :
                        (int)$order->id_address_delivery
                ];

                $lineCount++;
            }

            $this->logger->debug('Extracted order lines with complete product details', [
                'order_id' => $order->id,
                'line_count' => count($orderLines),
                'total_products' => count($products)
            ]);

            return $orderLines;
        } catch (Exception $e) {
            $this->logger->error('Failed to extract order lines', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Extract order status history
     *
     * @param Order $order Order object
     * @return array Order history records
     */
    private function extractOrderHistory($order)
    {
        try {
            $history = [];

            $sql = 'SELECT oh.id_order_history, oh.id_order_state, oh.id_employee, oh.date_add,
                           osl.name as status_name
                    FROM ' . _DB_PREFIX_ . 'order_history oh
                    LEFT JOIN ' . _DB_PREFIX_ . 'order_state_lang osl
                        ON (oh.id_order_state = osl.id_order_state AND osl.id_lang = ' . (int)Context::getContext()->language->id . ')
                    WHERE oh.id_order = ' . (int)$order->id . '
                    ORDER BY oh.date_add ASC';

            $results = Db::getInstance()->executeS($sql);

            if ($results && is_array($results)) {
                foreach ($results as $row) {
                    $history[] = [
                        'id_order_history' => (int)$row['id_order_history'],
                        'id_order_state' => (int)$row['id_order_state'],
                        'status_name' => isset($row['status_name']) ? $row['status_name'] : '',
                        'id_employee' => (int)$row['id_employee'],
                        'date_add' => $row['date_add']
                    ];
                }
            }

            $this->logger->debug('Extracted order history', [
                'order_id' => $order->id,
                'history_count' => count($history)
            ]);

            return $history;
        } catch (Exception $e) {
            $this->logger->error('Failed to extract order history', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract order payments
     *
     * @param Order $order Order object
     * @return array Order payment records
     */
    private function extractOrderPayments($order)
    {
        try {
            $payments = [];

            $sql = 'SELECT id_order_payment, order_reference, payment_method, amount,
                           transaction_id, card_number, card_brand, card_expiration,
                           card_holder, date_add, conversion_rate
                    FROM ' . _DB_PREFIX_ . 'order_payment
                    WHERE order_reference = \'' . pSQL($order->reference) . '\'
                    ORDER BY date_add ASC';

            $results = Db::getInstance()->executeS($sql);

            if ($results && is_array($results)) {
                foreach ($results as $row) {
                    $payments[] = [
                        'id_order_payment' => (int)$row['id_order_payment'],
                        'order_reference' => $row['order_reference'],
                        'payment_method' => $row['payment_method'],
                        'amount' => (float)$row['amount'],
                        'transaction_id' => $row['transaction_id'],
                        'card_number' => $row['card_number'], // Already masked by PrestaShop
                        'card_brand' => $row['card_brand'],
                        'card_expiration' => $row['card_expiration'],
                        'card_holder' => $row['card_holder'],
                        'date_add' => $row['date_add'],
                        'conversion_rate' => (float)$row['conversion_rate']
                    ];
                }
            }

            $this->logger->debug('Extracted order payments', [
                'order_id' => $order->id,
                'payment_count' => count($payments)
            ]);

            return $payments;
        } catch (Exception $e) {
            $this->logger->error('Failed to extract order payments', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract customer messages for order
     *
     * @param Order $order Order object
     * @return array Customer messages
     */
    private function extractOrderMessages($order)
    {
        try {
            $messages = [];

            $sql = 'SELECT id_message, id_customer, message, private, date_add
                    FROM ' . _DB_PREFIX_ . 'message
                    WHERE id_order = ' . (int)$order->id . '
                    ORDER BY date_add ASC';

            $results = Db::getInstance()->executeS($sql);

            if ($results && is_array($results)) {
                foreach ($results as $row) {
                    $messages[] = [
                        'id_message' => (int)$row['id_message'],
                        'id_customer' => (int)$row['id_customer'],
                        'message' => $row['message'],
                        'private' => (bool)$row['private'],
                        'date_add' => $row['date_add']
                    ];
                }
            }

            $this->logger->debug('Extracted order messages', [
                'order_id' => $order->id,
                'message_count' => count($messages)
            ]);

            return $messages;
        } catch (Exception $e) {
            $this->logger->error('Failed to extract order messages', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extract invoice lines (products) from invoice
     *
     * @param OrderInvoice $invoice Invoice object
     * @return array Invoice lines data
     */
    private function extractInvoiceLines($invoice)
    {
        try {
            $invoiceLines = [];
            $products = $invoice->getProducts();

            if (!$products || !is_array($products)) {
                $this->logger->debug('No products found for invoice', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number
                ]);
                return [];
            }

            // Limit to 100 lines to avoid excessively large JSON payloads
            $lineCount = 0;
            $maxLines = 100;

            foreach ($products as $product) {
                if ($lineCount >= $maxLines) {
                    $this->logger->warning('Invoice has too many lines, truncating', [
                        'invoice_id' => $invoice->id,
                        'total_lines' => count($products),
                        'max_lines' => $maxLines
                    ]);
                    break;
                }

                $invoiceLines[] = [
                    'id_order_detail' => isset($product['id_order_detail']) ? (int)$product['id_order_detail'] : null,
                    'product_id' => isset($product['product_id']) ? (int)$product['product_id'] : null,
                    'product_attribute_id' => isset($product['product_attribute_id']) ? (int)$product['product_attribute_id'] : null,
                    'product_name' => isset($product['product_name']) ? $product['product_name'] : '',
                    'product_reference' => isset($product['product_reference']) ? $product['product_reference'] : '',
                    'product_ean13' => isset($product['product_ean13']) ? $product['product_ean13'] : '',
                    'product_quantity' => isset($product['product_quantity']) ? (int)$product['product_quantity'] : 0,
                    'unit_price_tax_incl' => isset($product['unit_price_tax_incl']) ? (float)$product['unit_price_tax_incl'] : 0.0,
                    'unit_price_tax_excl' => isset($product['unit_price_tax_excl']) ? (float)$product['unit_price_tax_excl'] : 0.0,
                    'total_price_tax_incl' => isset($product['total_price_tax_incl']) ? (float)$product['total_price_tax_incl'] : 0.0,
                    'total_price_tax_excl' => isset($product['total_price_tax_excl']) ? (float)$product['total_price_tax_excl'] : 0.0,
                    'reduction_percent' => isset($product['reduction_percent']) ? (float)$product['reduction_percent'] : 0.0,
                    'reduction_amount' => isset($product['reduction_amount']) ? (float)$product['reduction_amount'] : 0.0,
                    'reduction_amount_tax_incl' => isset($product['reduction_amount_tax_incl']) ? (float)$product['reduction_amount_tax_incl'] : 0.0,
                    'reduction_amount_tax_excl' => isset($product['reduction_amount_tax_excl']) ? (float)$product['reduction_amount_tax_excl'] : 0.0,
                    'product_weight' => isset($product['product_weight']) ? (float)$product['product_weight'] : 0.0,
                    'ecotax' => isset($product['ecotax']) ? (float)$product['ecotax'] : 0.0
                ];

                $lineCount++;
            }

            $this->logger->debug('Extracted invoice lines', [
                'invoice_id' => $invoice->id,
                'line_count' => count($invoiceLines),
                'total_products' => count($products)
            ]);

            return $invoiceLines;
        } catch (Exception $e) {
            $this->logger->error('Failed to extract invoice lines', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Detect product cancellation events (refunds, returns, cancellations)
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @return bool Success
     */
    public function detectProductCancellation($hookName, $params)
    {
        try {
            // Extract order and cancellation details
            $order = isset($params['order']) ? $params['order'] : null;
            $idOrderDetail = isset($params['id_order_detail']) ? (int)$params['id_order_detail'] : null;
            $cancelQuantity = isset($params['cancel_quantity']) ? (int)$params['cancel_quantity'] : 0;
            $cancelAmount = isset($params['cancel_amount']) ? (float)$params['cancel_amount'] : 0.0;
            $action = isset($params['action']) ? $params['action'] : 'cancel';

            if (!$order || !$idOrderDetail) {
                return false;
            }

            // Determine action type based on action parameter
            $actionType = 'product_canceled';
            if (isset($params['action'])) {
                switch ($params['action']) {
                    case 'partial_refund':
                    case 'standard_refund':
                        $actionType = 'product_refunded';
                        break;
                    case 'return_product':
                        $actionType = 'product_returned';
                        break;
                }
            }

            // Build order data with cancellation context
            $afterData = $this->extractOrderData($order);

            $contextData = [
                'id_order_detail' => $idOrderDetail,
                'cancel_quantity' => $cancelQuantity,
                'cancel_amount' => $cancelAmount,
                'action' => $action
            ];

            // Queue the event
            $this->queue->queueEvent(
                'order',
                (int)$order->id,
                isset($order->reference) ? $order->reference : '',
                $actionType,
                null,
                $afterData,
                'Order product cancellation: ' . $actionType,
                $hookName,
                $contextData
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to detect product cancellation', [
                'hook' => $hookName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Detect order history addition events
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @return bool Success
     */
    public function detectOrderHistoryChange($hookName, $params)
    {
        try {
            $orderHistory = isset($params['order_history']) ? $params['order_history'] : null;

            if (!$orderHistory || !isset($orderHistory->id_order)) {
                return false;
            }

            // Load the order
            $order = new Order((int)$orderHistory->id_order);
            if (!Validate::isLoadedObject($order)) {
                return false;
            }

            // This is redundant with actionOrderStatusUpdate, so we'll skip it
            // to avoid duplicate events. Just return true to not break the hook.
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to detect order history change', [
                'hook' => $hookName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Detect invoice number assignment
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @return bool Success
     */
    public function detectInvoiceNumberAssignment($hookName, $params)
    {
        try {
            $order = isset($params['Order']) ? $params['Order'] : null;
            $orderInvoice = isset($params['OrderInvoice']) ? $params['OrderInvoice'] : null;

            if (!$order || !$orderInvoice) {
                return false;
            }

            // Extract invoice data
            $invoiceData = [
                'id' => isset($orderInvoice->id) ? (int)$orderInvoice->id : null,
                'number' => isset($orderInvoice->number) ? (int)$orderInvoice->number : null,
                'id_order' => isset($order->id) ? (int)$order->id : null,
                'order_reference' => isset($order->reference) ? $order->reference : '',
                'use_existing_payment' => isset($params['use_existing_payment']) ? (bool)$params['use_existing_payment'] : false
            ];

            // Queue the event as an invoice update
            $this->queue->queueEvent(
                'invoice',
                (int)$orderInvoice->id,
                isset($orderInvoice->number) ? 'INV-' . $orderInvoice->number : '',
                'invoice_number_assigned',
                null,
                $invoiceData,
                'Invoice number assigned',
                $hookName,
                null
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to detect invoice number assignment', [
                'hook' => $hookName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Detect payment confirmation
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @return bool Success
     */
    public function detectPaymentConfirmation($hookName, $params)
    {
        try {
            $idOrder = isset($params['id_order']) ? (int)$params['id_order'] : null;

            if (!$idOrder) {
                return false;
            }

            // Load the order
            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order)) {
                return false;
            }

            // Extract order data with payment confirmed context
            $afterData = $this->extractOrderData($order);

            $contextData = [
                'payment_confirmed' => true,
                'payment_status' => 'accepted'
            ];

            // Queue the event
            $this->queue->queueEvent(
                'order',
                (int)$order->id,
                isset($order->reference) ? $order->reference : '',
                'payment_confirmed',
                null,
                $afterData,
                'Payment confirmed for order: ' . $order->reference,
                $hookName,
                $contextData
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to detect payment confirmation', [
                'hook' => $hookName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate transaction hash for deduplication
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param string $action Action type
     * @return string Transaction hash
     */
    private function generateTransactionHash($entityType, $entityId, $action)
    {
        // Include 5-second bucket to allow near-duplicate events to be deduplicated
        $timeBucket = floor(time() / 5) * 5;

        $hashString = $entityType . '_' . $entityId . '_' . $action . '_' . $timeBucket;

        return hash('sha256', $hashString);
    }
}
