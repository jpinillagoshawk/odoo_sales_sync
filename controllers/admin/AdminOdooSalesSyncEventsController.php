<?php
/**
 * Sales Events Controller for Odoo Sales Sync Module
 *
 * Handles the Sales Events tab display and AJAX actions:
 * - Event filtering (time range, entity type, action type, sync status)
 * - Batch entity data loading (avoids N+1 queries)
 * - Event retry/bulk retry
 * - Event detail modal (entity-specific)
 * - Export functionality
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesEvent.php';
require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesRetryManager.php';

class AdminOdooSalesSyncEventsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        $this->table = 'odoo_sales_events';
        $this->className = 'OdooSalesEvent';
        $this->identifier = 'id_event';
        $this->lang = false;

        parent::__construct();
    }

    /**
     * Render the sales events tab content
     */
    public function renderView()
    {
        // Get filter parameters
        $minutes = (int)Tools::getValue('events_filter_minutes', 60);
        $entityType = Tools::getValue('events_filter_entity', '');
        $actionType = Tools::getValue('events_filter_action', '');
        $syncStatus = Tools::getValue('events_filter_status', '');
        $limit = (int)Tools::getValue('events_limit', 100);
        $offset = (int)Tools::getValue('events_offset', 0);

        // Load events with filters
        $events = $this->getEvents($minutes, $entityType, $actionType, $syncStatus, $limit, $offset);
        $totalEvents = $this->getEventsCount($minutes, $entityType, $actionType, $syncStatus);

        // Calculate pagination
        $totalPages = ceil($totalEvents / $limit);
        $currentPage = floor($offset / $limit) + 1;

        // Assign to Smarty
        $this->context->smarty->assign([
            'events' => $events,
            'total_events' => $totalEvents,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'limit' => $limit,
            'offset' => $offset,
            'filter_minutes' => $minutes,
            'filter_entity' => $entityType,
            'filter_action' => $actionType,
            'filter_status' => $syncStatus,
            'link' => $this->context->link,
            'current_url' => $_SERVER['REQUEST_URI']
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'odoo_sales_sync/views/templates/admin/sales_events_tab.tpl'
        );
    }

    /**
     * Get events with filters and enriched entity data
     *
     * @param int $minutes Time range in minutes
     * @param string $entityType Filter by entity type
     * @param string $actionType Filter by action type
     * @param string $syncStatus Filter by sync status
     * @param int $limit Results per page
     * @param int $offset Starting offset
     * @return array Events with enriched entity data
     */
    private function getEvents($minutes, $entityType, $actionType, $syncStatus, $limit, $offset)
    {
        $dbPrefix = _DB_PREFIX_;
        $where = [];

        // Build WHERE conditions
        if ($minutes > 0) {
            $where[] = "date_add >= DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)";
        }

        if (!empty($entityType)) {
            $where[] = "entity_type = '" . pSQL($entityType) . "'";
        }

        if (!empty($actionType)) {
            $where[] = "action_type = '" . pSQL($actionType) . "'";
        }

        if (!empty($syncStatus)) {
            $where[] = "sync_status = '" . pSQL($syncStatus) . "'";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT *
                FROM `{$dbPrefix}odoo_sales_events`
                {$whereClause}
                ORDER BY id_event DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $events = Db::getInstance()->executeS($sql);

        if (!$events) {
            return [];
        }

        // Enrich events with entity data
        return $this->enrichEventsWithEntityData($events);
    }

    /**
     * Get total count of events matching filters
     *
     * @param int $minutes Time range in minutes
     * @param string $entityType Filter by entity type
     * @param string $actionType Filter by action type
     * @param string $syncStatus Filter by sync status
     * @return int Total count
     */
    private function getEventsCount($minutes, $entityType, $actionType, $syncStatus)
    {
        $dbPrefix = _DB_PREFIX_;
        $where = [];

        if ($minutes > 0) {
            $where[] = "date_add >= DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)";
        }

        if (!empty($entityType)) {
            $where[] = "entity_type = '" . pSQL($entityType) . "'";
        }

        if (!empty($actionType)) {
            $where[] = "action_type = '" . pSQL($actionType) . "'";
        }

        if (!empty($syncStatus)) {
            $where[] = "sync_status = '" . pSQL($syncStatus) . "'";
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT COUNT(*) as total
                FROM `{$dbPrefix}odoo_sales_events`
                {$whereClause}";

        $result = Db::getInstance()->getRow($sql);
        return (int)$result['total'];
    }

    /**
     * Enrich events with entity data using batch loading
     * Avoids N+1 query problem by loading all entities of same type at once
     *
     * @param array $events Raw event data
     * @return array Events with entity_data field populated
     */
    private function enrichEventsWithEntityData($events)
    {
        // Group entity IDs by type
        $customerIds = [];
        $orderIds = [];
        $invoiceIds = [];
        $couponIds = [];
        $paymentIds = [];

        foreach ($events as $event) {
            $entityId = (int)$event['entity_id'];
            switch ($event['entity_type']) {
                case 'customer':
                    $customerIds[] = $entityId;
                    break;
                case 'order':
                    $orderIds[] = $entityId;
                    break;
                case 'invoice':
                    $invoiceIds[] = $entityId;
                    break;
                case 'coupon':
                    $couponIds[] = $entityId;
                    break;
                case 'payment':
                    $paymentIds[] = $entityId;
                    break;
            }
        }

        // Batch load entities
        $customers = $this->batchLoadCustomers($customerIds);
        $orders = $this->batchLoadOrders($orderIds);
        $invoices = $this->batchLoadInvoices($invoiceIds);
        $coupons = $this->batchLoadCoupons($couponIds);
        $payments = $this->batchLoadPayments($paymentIds);

        // Merge entity data back into events
        foreach ($events as &$event) {
            $entityId = (int)$event['entity_id'];

            switch ($event['entity_type']) {
                case 'customer':
                    $event['entity_data'] = isset($customers[$entityId]) ? $customers[$entityId] : null;
                    break;
                case 'order':
                    $event['entity_data'] = isset($orders[$entityId]) ? $orders[$entityId] : null;
                    break;
                case 'invoice':
                    $event['entity_data'] = isset($invoices[$entityId]) ? $invoices[$entityId] : null;
                    break;
                case 'coupon':
                    $event['entity_data'] = isset($coupons[$entityId]) ? $coupons[$entityId] : null;
                    break;
                case 'payment':
                    $event['entity_data'] = isset($payments[$entityId]) ? $payments[$entityId] : null;
                    break;
            }

            // Decode JSON fields
            $event['before_data_decoded'] = json_decode($event['before_data'], true);
            $event['after_data_decoded'] = json_decode($event['after_data'], true);
            $event['context_data_decoded'] = json_decode($event['context_data'], true);
        }

        return $events;
    }

    /**
     * Batch load customer data
     *
     * @param array $customerIds Array of customer IDs
     * @return array Customer data indexed by ID
     */
    private function batchLoadCustomers($customerIds)
    {
        if (empty($customerIds)) {
            return [];
        }

        $customerIds = array_unique($customerIds);
        $dbPrefix = _DB_PREFIX_;

        $sql = "SELECT c.id_customer, c.firstname, c.lastname, c.email, c.active, c.date_add
                FROM `{$dbPrefix}customer` c
                WHERE c.id_customer IN (" . implode(',', array_map('intval', $customerIds)) . ")";

        $results = Db::getInstance()->executeS($sql);
        $customers = [];

        foreach ($results as $row) {
            $customers[(int)$row['id_customer']] = $row;
        }

        return $customers;
    }

    /**
     * Batch load order data
     *
     * @param array $orderIds Array of order IDs
     * @return array Order data indexed by ID
     */
    private function batchLoadOrders($orderIds)
    {
        if (empty($orderIds)) {
            return [];
        }

        $orderIds = array_unique($orderIds);
        $dbPrefix = _DB_PREFIX_;

        $sql = "SELECT o.id_order, o.reference, o.total_paid, o.payment, o.module,
                       o.current_state, o.date_add,
                       c.firstname, c.lastname, c.email,
                       os.name as status_name, os.color
                FROM `{$dbPrefix}orders` o
                LEFT JOIN `{$dbPrefix}customer` c ON o.id_customer = c.id_customer
                LEFT JOIN `{$dbPrefix}order_state` os ON o.current_state = os.id_order_state
                WHERE o.id_order IN (" . implode(',', array_map('intval', $orderIds)) . ")";

        $results = Db::getInstance()->executeS($sql);
        $orders = [];

        foreach ($results as $row) {
            $row['customer_name'] = $row['firstname'] . ' ' . $row['lastname'];
            $orders[(int)$row['id_order']] = $row;
        }

        return $orders;
    }

    /**
     * Batch load invoice data
     *
     * @param array $invoiceIds Array of invoice IDs
     * @return array Invoice data indexed by ID
     */
    private function batchLoadInvoices($invoiceIds)
    {
        if (empty($invoiceIds)) {
            return [];
        }

        $invoiceIds = array_unique($invoiceIds);
        $dbPrefix = _DB_PREFIX_;

        $sql = "SELECT oi.id_order_invoice, oi.number, oi.total_paid_tax_incl,
                       oi.date_add, o.id_order, o.reference,
                       c.firstname, c.lastname, c.email
                FROM `{$dbPrefix}order_invoice` oi
                LEFT JOIN `{$dbPrefix}orders` o ON oi.id_order = o.id_order
                LEFT JOIN `{$dbPrefix}customer` c ON o.id_customer = c.id_customer
                WHERE oi.id_order_invoice IN (" . implode(',', array_map('intval', $invoiceIds)) . ")";

        $results = Db::getInstance()->executeS($sql);
        $invoices = [];

        foreach ($results as $row) {
            $row['customer_name'] = $row['firstname'] . ' ' . $row['lastname'];
            $invoices[(int)$row['id_order_invoice']] = $row;
        }

        return $invoices;
    }

    /**
     * Batch load coupon data
     *
     * @param array $couponIds Array of cart rule IDs
     * @return array Coupon data indexed by ID
     */
    private function batchLoadCoupons($couponIds)
    {
        if (empty($couponIds)) {
            return [];
        }

        $couponIds = array_unique($couponIds);
        $dbPrefix = _DB_PREFIX_;

        $sql = "SELECT cr.id_cart_rule, cr.code, cr.name, cr.reduction_percent,
                       cr.reduction_amount, cr.reduction_tax, cr.active,
                       cr.quantity, cr.date_from, cr.date_to
                FROM `{$dbPrefix}cart_rule` cr
                WHERE cr.id_cart_rule IN (" . implode(',', array_map('intval', $couponIds)) . ")";

        $results = Db::getInstance()->executeS($sql);
        $coupons = [];

        foreach ($results as $row) {
            $coupons[(int)$row['id_cart_rule']] = $row;
        }

        return $coupons;
    }

    /**
     * Batch load payment data
     *
     * @param array $paymentIds Array of payment IDs
     * @return array Payment data indexed by ID
     */
    private function batchLoadPayments($paymentIds)
    {
        if (empty($paymentIds)) {
            return [];
        }

        $paymentIds = array_unique($paymentIds);
        $dbPrefix = _DB_PREFIX_;

        $sql = "SELECT op.id_order_payment, op.order_reference, op.id_currency,
                       op.amount, op.payment_method, op.transaction_id,
                       op.card_number, op.card_brand, op.card_expiration,
                       op.card_holder, op.date_add, op.conversion_rate
                FROM `{$dbPrefix}order_payment` op
                WHERE op.id_order_payment IN (" . implode(',', array_map('intval', $paymentIds)) . ")";

        $results = Db::getInstance()->executeS($sql);
        $payments = [];

        foreach ($results as $row) {
            $payments[(int)$row['id_order_payment']] = $row;
        }

        return $payments;
    }

    /**
     * Handle AJAX requests
     */
    public function ajaxProcess()
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'retryEvent':
                $this->ajaxProcessRetryEvent();
                break;
            case 'bulkRetryEvents':
                $this->ajaxProcessBulkRetryEvents();
                break;
            case 'bulkMarkAsSent':
                $this->ajaxProcessBulkMarkAsSent();
                break;
            case 'viewEventDetails':
                $this->ajaxProcessViewEventDetails();
                break;
            case 'exportEvents':
                $this->ajaxProcessExportEvents();
                break;
            default:
                die(json_encode(['success' => false, 'error' => 'Unknown action']));
        }
    }

    /**
     * AJAX: Retry single event
     */
    private function ajaxProcessRetryEvent()
    {
        $eventId = (int)Tools::getValue('event_id');

        if (!$eventId) {
            die(json_encode(['success' => false, 'error' => 'Invalid event ID']));
        }

        $event = new OdooSalesEvent($eventId);

        if (!Validate::isLoadedObject($event)) {
            die(json_encode(['success' => false, 'error' => 'Event not found']));
        }

        // Reset sync status to trigger retry
        $event->sync_status = 'pending';
        $event->sync_error = null;
        $event->sync_next_retry = null;
        $event->save();

        // Queue for immediate sync
        require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesEventQueue.php';
        $queue = new OdooSalesEventQueue();
        $result = $queue->processQueue();

        die(json_encode([
            'success' => true,
            'message' => 'Event queued for retry',
            'result' => $result
        ]));
    }

    /**
     * AJAX: Bulk retry events
     */
    private function ajaxProcessBulkRetryEvents()
    {
        $eventIds = Tools::getValue('event_ids');

        if (!is_array($eventIds) || empty($eventIds)) {
            die(json_encode(['success' => false, 'error' => 'No events selected']));
        }

        $retryCount = 0;

        foreach ($eventIds as $eventId) {
            $event = new OdooSalesEvent((int)$eventId);

            if (Validate::isLoadedObject($event)) {
                $event->sync_status = 'pending';
                $event->sync_error = null;
                $event->sync_next_retry = null;
                $event->save();
                $retryCount++;
            }
        }

        // Process queue
        require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesEventQueue.php';
        $queue = new OdooSalesEventQueue();
        $result = $queue->processQueue();

        die(json_encode([
            'success' => true,
            'message' => $retryCount . ' events queued for retry',
            'retry_count' => $retryCount,
            'result' => $result
        ]));
    }

    /**
     * AJAX: Bulk mark as sent
     */
    private function ajaxProcessBulkMarkAsSent()
    {
        $eventIds = Tools::getValue('event_ids');

        if (!is_array($eventIds) || empty($eventIds)) {
            die(json_encode(['success' => false, 'error' => 'No events selected']));
        }

        $markedCount = 0;

        foreach ($eventIds as $eventId) {
            $event = new OdooSalesEvent((int)$eventId);

            if (Validate::isLoadedObject($event)) {
                $event->sync_status = 'success';
                $event->sync_error = null;
                $event->sync_next_retry = null;
                $event->save();
                $markedCount++;
            }
        }

        die(json_encode([
            'success' => true,
            'message' => $markedCount . ' events marked as sent',
            'marked_count' => $markedCount
        ]));
    }

    /**
     * AJAX: View event details (entity-specific modal)
     */
    private function ajaxProcessViewEventDetails()
    {
        $eventId = (int)Tools::getValue('event_id');

        if (!$eventId) {
            die(json_encode(['success' => false, 'error' => 'Invalid event ID']));
        }

        $event = new OdooSalesEvent($eventId);

        if (!Validate::isLoadedObject($event)) {
            die(json_encode(['success' => false, 'error' => 'Event not found']));
        }

        // Enrich with entity data
        $eventData = [
            'id_event' => $event->id,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id,
            'entity_name' => $event->entity_name,
            'action_type' => $event->action_type,
            'hook_name' => $event->hook_name,
            'hook_timestamp' => $event->hook_timestamp,
            'sync_status' => $event->sync_status,
            'sync_attempts' => $event->sync_attempts,
            'sync_error' => $event->sync_error,
            'date_add' => $event->date_add,
            'before_data' => json_decode($event->before_data, true),
            'after_data' => json_decode($event->after_data, true),
            'context_data' => json_decode($event->context_data, true),
            'change_summary' => $event->change_summary
        ];

        $enrichedEvents = $this->enrichEventsWithEntityData([$eventData]);
        $enrichedEvent = $enrichedEvents[0];

        // Render entity-specific detail HTML
        $html = $this->renderEventDetailModal($enrichedEvent);

        die(json_encode([
            'success' => true,
            'html' => $html,
            'event' => $enrichedEvent
        ]));
    }

    /**
     * Render entity-specific detail modal HTML
     *
     * @param array $event Enriched event data
     * @return string HTML content
     */
    private function renderEventDetailModal($event)
    {
        $html = '<div class="event-detail-modal">';
        $html .= '<h4>Event #' . (int)$event['id_event'] . ' - ' . ucfirst($event['entity_type']) . ' ' . ucfirst($event['action_type']) . '</h4>';
        $html .= '<hr>';

        // Common event information
        $html .= '<div class="row">';
        $html .= '<div class="col-md-6">';
        $html .= '<dl class="dl-horizontal">';
        $html .= '<dt>Entity:</dt><dd>' . htmlspecialchars($event['entity_name']) . ' (#' . (int)$event['entity_id'] . ')</dd>';
        $html .= '<dt>Action:</dt><dd>' . htmlspecialchars($event['action_type']) . '</dd>';
        $html .= '<dt>Hook:</dt><dd>' . htmlspecialchars($event['hook_name']) . '</dd>';
        $html .= '<dt>Date:</dt><dd>' . htmlspecialchars($event['date_add']) . '</dd>';
        $html .= '</dl>';
        $html .= '</div>';

        $html .= '<div class="col-md-6">';
        $html .= '<dl class="dl-horizontal">';
        $html .= '<dt>Sync Status:</dt><dd><span class="badge badge-' . ($event['sync_status'] == 'success' ? 'success' : ($event['sync_status'] == 'failed' ? 'danger' : 'warning')) . '">' . htmlspecialchars($event['sync_status']) . '</span></dd>';
        $html .= '<dt>Attempts:</dt><dd>' . (int)$event['sync_attempts'] . '</dd>';
        if (!empty($event['sync_error'])) {
            $html .= '<dt>Error:</dt><dd class="text-danger">' . htmlspecialchars($event['sync_error']) . '</dd>';
        }
        $html .= '</dl>';
        $html .= '</div>';
        $html .= '</div>';

        // Entity-specific details
        $html .= '<hr>';
        $html .= '<h5>' . ucfirst($event['entity_type']) . ' Details</h5>';

        switch ($event['entity_type']) {
            case 'customer':
                $html .= $this->renderCustomerDetails($event);
                break;
            case 'order':
                $html .= $this->renderOrderDetails($event);
                break;
            case 'invoice':
                $html .= $this->renderInvoiceDetails($event);
                break;
            case 'payment':
                $html .= $this->renderPaymentDetails($event);
                break;
            case 'coupon':
                $html .= $this->renderCouponDetails($event);
                break;
        }

        // Change summary
        if (!empty($event['change_summary'])) {
            $html .= '<hr>';
            $html .= '<h5>Changes</h5>';
            $html .= '<p>' . nl2br(htmlspecialchars($event['change_summary'])) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    private function renderCustomerDetails($event)
    {
        $html = '<dl class="dl-horizontal">';

        if (isset($event['entity_data'])) {
            $customer = $event['entity_data'];
            $html .= '<dt>Name:</dt><dd>' . htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']) . '</dd>';
            $html .= '<dt>Email:</dt><dd>' . htmlspecialchars($customer['email']) . '</dd>';
            $html .= '<dt>Active:</dt><dd>' . ($customer['active'] ? 'Yes' : 'No') . '</dd>';
            $html .= '<dt>Registered:</dt><dd>' . htmlspecialchars($customer['date_add']) . '</dd>';
        }

        $html .= '</dl>';
        return $html;
    }

    private function renderOrderDetails($event)
    {
        $html = '<dl class="dl-horizontal">';

        if (isset($event['entity_data'])) {
            $order = $event['entity_data'];
            $html .= '<dt>Reference:</dt><dd>' . htmlspecialchars($order['reference']) . '</dd>';
            $html .= '<dt>Customer:</dt><dd>' . htmlspecialchars($order['customer_name']) . '</dd>';
            $html .= '<dt>Total:</dt><dd>' . number_format($order['total_paid'], 2) . ' €</dd>';
            $html .= '<dt>Payment:</dt><dd>' . htmlspecialchars($order['payment']) . '</dd>';
            $html .= '<dt>Status:</dt><dd><span class="badge" style="background-color: ' . htmlspecialchars($order['color']) . '">' . htmlspecialchars($order['status_name']) . '</span></dd>';
        }

        $html .= '</dl>';
        return $html;
    }

    private function renderInvoiceDetails($event)
    {
        $html = '<dl class="dl-horizontal">';

        if (isset($event['entity_data'])) {
            $invoice = $event['entity_data'];
            $html .= '<dt>Number:</dt><dd>' . htmlspecialchars($invoice['number']) . '</dd>';
            $html .= '<dt>Order:</dt><dd>' . htmlspecialchars($invoice['reference']) . '</dd>';
            $html .= '<dt>Customer:</dt><dd>' . htmlspecialchars($invoice['customer_name']) . '</dd>';
            $html .= '<dt>Total:</dt><dd>' . number_format($invoice['total_paid_tax_incl'], 2) . ' €</dd>';
            $html .= '<dt>Date:</dt><dd>' . htmlspecialchars($invoice['date_add']) . '</dd>';
        }

        $html .= '</dl>';
        return $html;
    }

    private function renderPaymentDetails($event)
    {
        $html = '<dl class="dl-horizontal">';

        if (isset($event['context_data_decoded'])) {
            $payment = $event['context_data_decoded'];
            $html .= '<dt>Amount:</dt><dd>' . (isset($payment['amount']) ? number_format($payment['amount'], 2) . ' €' : 'N/A') . '</dd>';
            $html .= '<dt>Method:</dt><dd>' . (isset($payment['payment_method']) ? htmlspecialchars($payment['payment_method']) : 'N/A') . '</dd>';
            $html .= '<dt>Transaction ID:</dt><dd>' . (isset($payment['transaction_id']) ? htmlspecialchars($payment['transaction_id']) : 'N/A') . '</dd>';
        }

        $html .= '</dl>';
        return $html;
    }

    private function renderCouponDetails($event)
    {
        $html = '<dl class="dl-horizontal">';

        if (isset($event['entity_data'])) {
            $coupon = $event['entity_data'];
            $html .= '<dt>Code:</dt><dd><strong>' . htmlspecialchars($coupon['code']) . '</strong></dd>';
            $html .= '<dt>Name:</dt><dd>' . htmlspecialchars($coupon['name']) . '</dd>';

            if ($coupon['reduction_percent'] > 0) {
                $html .= '<dt>Discount:</dt><dd>' . number_format($coupon['reduction_percent'], 2) . '%</dd>';
            } elseif ($coupon['reduction_amount'] > 0) {
                $html .= '<dt>Discount:</dt><dd>' . number_format($coupon['reduction_amount'], 2) . ' €</dd>';
            }

            $html .= '<dt>Active:</dt><dd>' . ($coupon['active'] ? 'Yes' : 'No') . '</dd>';
            $html .= '<dt>Quantity:</dt><dd>' . (int)$coupon['quantity'] . '</dd>';
            $html .= '<dt>Valid:</dt><dd>' . htmlspecialchars($coupon['date_from']) . ' to ' . htmlspecialchars($coupon['date_to']) . '</dd>';
        }

        $html .= '</dl>';
        return $html;
    }

    /**
     * AJAX: Export events to CSV
     */
    private function ajaxProcessExportEvents()
    {
        // Get filter parameters
        $minutes = (int)Tools::getValue('events_filter_minutes', 0);
        $entityType = Tools::getValue('events_filter_entity', '');
        $actionType = Tools::getValue('events_filter_action', '');
        $syncStatus = Tools::getValue('events_filter_status', '');

        // Load ALL matching events (no pagination for export)
        $events = $this->getEvents($minutes, $entityType, $actionType, $syncStatus, 100000, 0);

        // Generate CSV
        $csv = "ID,Date,Entity Type,Entity ID,Entity Name,Action,Sync Status,Attempts,Error\n";

        foreach ($events as $event) {
            $csv .= sprintf(
                "%d,%s,%s,%d,%s,%s,%s,%d,%s\n",
                $event['id_event'],
                $event['date_add'],
                $event['entity_type'],
                $event['entity_id'],
                str_replace('"', '""', $event['entity_name']),
                $event['action_type'],
                $event['sync_status'],
                $event['sync_attempts'],
                str_replace('"', '""', $event['sync_error'] ?? '')
            );
        }

        // Send CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="odoo_sales_events_' . date('Y-m-d_His') . '.csv"');
        die($csv);
    }
}
