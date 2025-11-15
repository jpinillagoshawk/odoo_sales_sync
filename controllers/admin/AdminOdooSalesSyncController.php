<?php
/**
 * Admin Controller for Odoo Sales Sync
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Ensure all required classes are loaded
require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesEvent.php';
require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesLogger.php';
require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesWebhookClient.php';

class AdminOdooSalesSyncController extends ModuleAdminController
{
    public function __construct()
    {
        $this->module = 'odoo_sales_sync';
        $this->bootstrap = true;
        $this->table = 'odoo_sales_events';
        $this->className = 'OdooSalesEvent';
        $this->identifier = 'id_event';
        $this->lang = false;
        $this->display = 'view';

        parent::__construct();

        $this->_orderBy = 'date_add';
        $this->_orderWay = 'DESC';

        $this->context = Context::getContext();

        // Load module instance
        if (!$this->module) {
            $this->module = Module::getInstanceByName('odoo_sales_sync');
        }

        // Add CSS and JS
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js');
    }

    public function initContent()
    {
        error_log('[INIT_CONTENT] Called - ajax param: ' . (Tools::getValue('ajax') ? 'YES' : 'NO'));

        // Handle AJAX requests before parent to prevent interference
        if (Tools::getValue('ajax')) {
            error_log('[INIT_CONTENT] AJAX detected, calling ajaxProcess()');
            $this->ajaxProcess();
            exit;
        }

        error_log('[INIT_CONTENT] Not AJAX, continuing with normal content rendering');

        parent::initContent();

        // Get active tab
        $tab = Tools::getValue('tab', 'configuration');

        // Get statistics
        $stats = $this->getStatistics();

        // Get events with pagination
        $page = (int)Tools::getValue('page', 1);
        $limit = (int)Tools::getValue('limit', 100);
        $allowedLimits = [50, 100, 300, 500];
        if (!in_array($limit, $allowedLimits)) {
            $limit = 100;
        }
        $offset = ($page - 1) * $limit;

        $events = $this->getEvents($limit, $offset);
        $totalEvents = $this->getTotalEvents();

        // Get logs with pagination
        $logsPage = (int)Tools::getValue('logs_page', 1);
        $logsLimit = (int)Tools::getValue('logs_per_page', 100);
        if (!in_array($logsLimit, $allowedLimits)) {
            $logsLimit = 100;
        }
        $logsOffset = ($logsPage - 1) * $logsLimit;

        $logs = $this->getLogs($logsLimit, $logsOffset);
        $totalLogs = $this->getTotalLogs();

        // Prepare template variables
        $this->context->smarty->assign([
            'module_name' => $this->module->name,
            'module_display' => $this->module->displayName,
            'active_tab' => $tab,
            'statistics' => $stats,
            'events' => $events,
            'events_pagination' => [
                'page' => $page,
                'pages' => ceil($totalEvents / $limit),
                'limit' => $limit,
                'total' => $totalEvents
            ],
            'logs' => $logs,
            'logs_pagination' => [
                'page' => $logsPage,
                'pages' => ceil($totalLogs / $logsLimit),
                'limit' => $logsLimit,
                'total' => $totalLogs
            ],
            'logs_filters' => [
                'level' => Tools::getValue('logs_level', ''),
                'category' => Tools::getValue('logs_category', ''),
                'date_from' => Tools::getValue('logs_date_from', ''),
                'date_to' => Tools::getValue('logs_date_to', ''),
                'search' => Tools::getValue('logs_search', ''),
                'per_page' => $logsLimit
            ],
            'link' => $this->context->link,
            'token' => Tools::getAdminTokenLite('AdminOdooSalesSync'),
            'ajax_url' => $this->context->link->getAdminLink('AdminOdooSalesSync'),
            'current_url' => $this->context->link->getAdminLink('AdminOdooSalesSync')
        ]);

        // Render configuration tab
        $configForm = $this->renderConfigurationForm();

        // Render events tab
        $this->context->smarty->assign([
            'events' => $events,
            'pagination' => [
                'page' => $page,
                'pages' => ceil($totalEvents / $limit),
                'limit' => $limit,
                'total' => $totalEvents
            ]
        ]);
        $eventsContent = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/events_tab.tpl');

        // Render failed events tab
        $failedEvents = $this->getFailedEvents();
        $this->context->smarty->assign([
            'failed_events' => $failedEvents
        ]);
        $failedContent = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/failed_tab.tpl');

        // Render logs tab
        $logsContent = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/logs_tab.tpl');

        // Assign tab content to template
        $this->context->smarty->assign([
            'config_form' => $configForm,
            'events_content' => $eventsContent,
            'failed_content' => $failedContent,
            'logs_content' => $logsContent
        ]);

        // Display the main template
        $this->content = $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/main.tpl');
    }

    /**
     * Process AJAX requests
     */
    public function ajaxProcess()
    {
        // Add error handler to catch ANY error and log it
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            error_log("[AJAX_ERROR_HANDLER] PHP Error: [$errno] $errstr in $errfile:$errline");

            // Try to log to our system too
            try {
                $logger = new OdooSalesLogger();
                $logger->error('PHP Error in AJAX handler', [
                    'errno' => $errno,
                    'errstr' => $errstr,
                    'errfile' => $errfile,
                    'errline' => $errline
                ]);
            } catch (Exception $e) {
                error_log("[AJAX_ERROR_HANDLER] Could not log to OdooSalesLogger: " . $e->getMessage());
            }

            return false; // Let default error handler run
        });

        error_log('[AJAX_PROCESS] Method called');
        error_log('[AJAX_PROCESS] REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        error_log('[AJAX_PROCESS] $_POST: ' . print_r($_POST, true));
        error_log('[AJAX_PROCESS] $_GET: ' . print_r($_GET, true));

        // Disable default AJAX die behavior
        $this->ajax = true;

        $action = Tools::getValue('action');
        error_log('[AJAX_PROCESS] Action extracted: ' . ($action ? $action : 'NULL/EMPTY'));

        switch ($action) {
            case 'testConnection':
                error_log('[AJAX_PROCESS] Routing to ajaxProcessTestConnection()');
                $this->ajaxProcessTestConnection();
                break;

            case 'retryFailedEvents':
                error_log('[AJAX_PROCESS] Routing to ajaxProcessRetryFailedEvents()');
                $this->ajaxProcessRetryFailedEvents();
                break;

            case 'refreshLogs':
                error_log('[AJAX_PROCESS] Routing to ajaxProcessRefreshLogs()');
                $this->ajaxProcessRefreshLogs();
                break;

            default:
                error_log('[AJAX_PROCESS] Unknown action, returning error');
                die(json_encode(['error' => 'Unknown action', 'received_action' => $action]));
        }
    }

    protected function ajaxProcessTestConnection()
    {
        error_log('[TEST_CONNECTION] Method called');

        // Initialize logger for debugging
        $logger = new OdooSalesLogger();
        error_log('[TEST_CONNECTION] Logger initialized');

        $webhookUrl = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL', '');
        $webhookSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET', '');

        error_log('[TEST_CONNECTION] Configuration loaded - URL: ' . $webhookUrl);
        error_log('[TEST_CONNECTION] Secret length: ' . strlen($webhookSecret));

        $logger->info('Test connection button pressed', [
            'webhook_url' => $webhookUrl,
            'secret_set' => !empty($webhookSecret)
        ]);

        try {
            error_log('[TEST_CONNECTION] Requiring OdooSalesWebhookClient.php');
            require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesWebhookClient.php';
            error_log('[TEST_CONNECTION] OdooSalesWebhookClient.php required successfully');

            error_log('[TEST_CONNECTION] Instantiating OdooSalesWebhookClient');
            $webhookClient = new OdooSalesWebhookClient($webhookUrl, $webhookSecret, $logger);
            error_log('[TEST_CONNECTION] OdooSalesWebhookClient instantiated successfully');

            $logger->info('OdooSalesWebhookClient instantiated, calling testConnection()');

            error_log('[TEST_CONNECTION] Calling testConnection() method');
            $result = $webhookClient->testConnection();
            error_log('[TEST_CONNECTION] testConnection() returned: ' . json_encode($result));

            $logger->info('Test connection result', $result);

            error_log('[TEST_CONNECTION] Sending JSON response');
            die(json_encode($result));
        } catch (Exception $e) {
            error_log('[TEST_CONNECTION] Exception caught: ' . $e->getMessage());
            error_log('[TEST_CONNECTION] Exception file: ' . $e->getFile());
            error_log('[TEST_CONNECTION] Exception line: ' . $e->getLine());
            error_log('[TEST_CONNECTION] Exception trace: ' . $e->getTraceAsString());

            $logger->error('Test connection exception caught', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorResponse = [
                'success' => false,
                'error' => 'Test connection failed: ' . $e->getMessage()
            ];
            error_log('[TEST_CONNECTION] Sending error JSON response: ' . json_encode($errorResponse));
            die(json_encode($errorResponse));
        }
    }

    protected function ajaxProcessRetryFailedEvents()
    {
        try {
            $logger = new OdooSalesLogger();
            require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesWebhookClient.php';

            $webhookUrl = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL', '');
            $webhookSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET', '');
            $webhookClient = new OdooSalesWebhookClient($webhookUrl, $webhookSecret, $logger);

            // Get all failed events
            $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events
                    WHERE sync_status = 'failed'
                    LIMIT 100";

            $events = Db::getInstance()->executeS($sql);

            $retryCount = 0;
            foreach ($events as $eventData) {
                $event = new OdooSalesEvent($eventData['id_event']);
                if ($webhookClient->sendEvent($event)) {
                    $retryCount++;
                }
            }

            $logger->info('Retry failed events completed', [
                'total_failed' => count($events),
                'successful_retries' => $retryCount
            ]);

            die(json_encode([
                'success' => true,
                'message' => sprintf('%d of %d events retried successfully', $retryCount, count($events))
            ]));
        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'message' => 'Retry failed: ' . $e->getMessage()
            ]));
        }
    }

    protected function ajaxProcessRefreshLogs()
    {
        try {
            // Get logs with filters
            $logsPage = (int)Tools::getValue('logs_page', 1);
            $logsLimit = (int)Tools::getValue('logs_per_page', 100);
            $logsOffset = ($logsPage - 1) * $logsLimit;

            $logs = $this->getLogs($logsLimit, $logsOffset);
            $totalLogs = $this->getTotalLogs();

            // Assign template variables
            $this->context->smarty->assign([
                'logs' => $logs,
                'pagination' => [
                    'page' => $logsPage,
                    'pages' => ceil($totalLogs / $logsLimit),
                    'limit' => $logsLimit,
                    'total' => $totalLogs
                ],
                'filters' => [
                    'level' => Tools::getValue('logs_level', ''),
                    'category' => Tools::getValue('logs_category', ''),
                    'date_from' => Tools::getValue('logs_date_from', ''),
                    'date_to' => Tools::getValue('logs_date_to', ''),
                    'search' => Tools::getValue('logs_search', '')
                ],
                'ajax_url' => $this->context->link->getAdminLink('AdminOdooSalesSync'),
                'current_url' => $this->context->link->getAdminLink('AdminOdooSalesSync'),
                'link' => $this->context->link,
                'token' => Tools::getAdminTokenLite('AdminOdooSalesSync')
            ]);

            // Check if template file exists
            $templatePath = $this->module->getLocalPath() . 'views/templates/admin/logs_tab.tpl';
            if (!file_exists($templatePath)) {
                throw new Exception('Template file not found: ' . $templatePath);
            }

            // Render and return only the logs tab content
            $html = $this->context->smarty->fetch($templatePath);

            // Set proper content type header
            header('Content-Type: text/html; charset=utf-8');
            die($html);

        } catch (Exception $e) {
            // Return error as HTML
            die('<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
    }

    private function getStatistics()
    {
        $db = Db::getInstance();

        return [
            'total_events' => $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events"),
            'pending_events' => $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'pending'"),
            'failed_events' => $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'failed'"),
            'success_events' => $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'success'"),
            'last_sync' => $db->getValue("SELECT MAX(date_sync) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'success'")
        ];
    }

    private function getEvents($limit, $offset)
    {
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events";

        // Apply filters
        $where = [];

        $minutes = Tools::getValue('events_filter_minutes');
        if ($minutes) {
            $where[] = "date_add >= DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)";
        }

        $type = Tools::getValue('events_filter_type');
        if ($type) {
            $where[] = "event_type = '" . pSQL($type) . "'";
        }

        $status = Tools::getValue('events_filter_status');
        if ($status) {
            $where[] = "sync_status = '" . pSQL($status) . "'";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY date_add DESC
                  LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        return Db::getInstance()->executeS($sql);
    }

    private function getTotalEvents()
    {
        $sql = "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events";

        // Apply same filters as getEvents
        $where = [];

        $minutes = Tools::getValue('events_filter_minutes');
        if ($minutes) {
            $where[] = "date_add >= DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)";
        }

        $type = Tools::getValue('events_filter_type');
        if ($type) {
            $where[] = "event_type = '" . pSQL($type) . "'";
        }

        $status = Tools::getValue('events_filter_status');
        if ($status) {
            $where[] = "sync_status = '" . pSQL($status) . "'";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        return (int)Db::getInstance()->getValue($sql);
    }

    private function getFailedEvents()
    {
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events
                WHERE sync_status = 'failed'
                ORDER BY date_add DESC
                LIMIT 100";

        return Db::getInstance()->executeS($sql);
    }

    private function getLogs($limit, $offset)
    {
        $level = Tools::getValue('logs_level');
        $category = Tools::getValue('logs_category');
        $dateFrom = Tools::getValue('logs_date_from');
        $dateTo = Tools::getValue('logs_date_to');
        $search = Tools::getValue('logs_search');

        $result = OdooSalesLogger::getLogs(
            ceil($offset / $limit) + 1,  // page
            $limit,
            $level,
            $dateFrom,
            $dateTo,
            $search,
            $category,
            '',  // correlation_id
            'date_add',
            'DESC'
        );

        return $result['logs'];
    }

    private function getTotalLogs()
    {
        $level = Tools::getValue('logs_level');
        $category = Tools::getValue('logs_category');
        $dateFrom = Tools::getValue('logs_date_from');
        $dateTo = Tools::getValue('logs_date_to');
        $search = Tools::getValue('logs_search');

        $result = OdooSalesLogger::getLogs(
            1,  // page
            1,  // limit
            $level,
            $dateFrom,
            $dateTo,
            $search,
            $category,
            '',  // correlation_id
            'date_add',
            'DESC'
        );

        return $result['total'];
    }

    /**
     * Render configuration form
     */
    private function renderConfigurationForm()
    {
        // Handle form submission
        if (Tools::isSubmit('submitOdooSalesSync')) {
            Configuration::updateValue('ODOO_SALES_SYNC_ENABLED', Tools::getValue('ODOO_SALES_SYNC_ENABLED'));
            Configuration::updateValue('ODOO_SALES_SYNC_WEBHOOK_URL', Tools::getValue('ODOO_SALES_SYNC_WEBHOOK_URL'));
            Configuration::updateValue('ODOO_SALES_SYNC_WEBHOOK_SECRET', Tools::getValue('ODOO_SALES_SYNC_WEBHOOK_SECRET'));
            Configuration::updateValue('ODOO_SALES_SYNC_DEBUG', Tools::getValue('ODOO_SALES_SYNC_DEBUG'));

            $this->confirmations[] = $this->l('Settings updated successfully');
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Odoo Sales Sync Configuration'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enable Sync'),
                        'name' => 'ODOO_SALES_SYNC_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook URL'),
                        'name' => 'ODOO_SALES_SYNC_WEBHOOK_URL',
                        'size' => 64,
                        'required' => true,
                        'desc' => $this->l('The Odoo webhook endpoint URL')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook Secret'),
                        'name' => 'ODOO_SALES_SYNC_WEBHOOK_SECRET',
                        'size' => 64,
                        'required' => true,
                        'desc' => $this->l('Secret key for webhook authentication')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Debug Mode'),
                        'name' => 'ODOO_SALES_SYNC_DEBUG',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'debug_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'debug_off', 'value' => 0, 'label' => $this->l('No')]
                        ],
                        'desc' => $this->l('Enable verbose logging for debugging')
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Save Configuration'),
                    'class' => 'btn btn-primary pull-right'
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = 'odoo_sales_sync';
        $helper->module = $this->module;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = 'id';
        $helper->submit_action = 'submitOdooSalesSync';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminOdooSalesSync', false);
        $helper->token = Tools::getAdminTokenLite('AdminOdooSalesSync');
        $helper->tpl_vars = [
            'fields_value' => [
                'ODOO_SALES_SYNC_ENABLED' => Configuration::get('ODOO_SALES_SYNC_ENABLED', 0),
                'ODOO_SALES_SYNC_WEBHOOK_URL' => Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL', ''),
                'ODOO_SALES_SYNC_WEBHOOK_SECRET' => Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET', ''),
                'ODOO_SALES_SYNC_DEBUG' => Configuration::get('ODOO_SALES_SYNC_DEBUG', 0)
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];

        $formHtml = $helper->generateForm([$fieldsForm]);

        // Add Test Connection panel
        $testConnectionPanel = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-plug"></i> ' . $this->l('Test Connection') . '
            </div>
            <div class="panel-body">
                <p>' . $this->l('Click the button below to test the webhook connection to Odoo.') . '</p>
                <button type="button" id="test-connection-btn" class="btn btn-info" onclick="testOdooConnection(); return false;">
                    <i class="icon-exchange"></i> ' . $this->l('Test Connection') . '
                </button>
                <div id="test-connection-result" style="margin-top: 15px;"></div>
            </div>
        </div>';

        return $formHtml . $testConnectionPanel;
    }
}
