<?php
/**
 * Odoo Sales Sync Module
 *
 * Tracks sales-related events (customers, orders, invoices, coupons) and
 * sends them to Odoo via webhook for synchronization.
 *
 * CRITICAL IMPLEMENTATION NOTES:
 * - 23 hooks registered (verified against PrestaShop 8.2.x source)
 * - Includes workaround for missing actionCartRuleApplied hook
 * - Address changes normalized to customer updates
 * - Deduplication prevents duplicate events
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoload classes
require_once dirname(__FILE__) . '/classes/OdooSalesEvent.php';
require_once dirname(__FILE__) . '/classes/OdooSalesEventDetector.php';
require_once dirname(__FILE__) . '/classes/OdooSalesWebhookClient.php';
require_once dirname(__FILE__) . '/classes/OdooSalesCartRuleUsageTracker.php';
require_once dirname(__FILE__) . '/classes/OdooSalesCartRuleStateRepository.php';
require_once dirname(__FILE__) . '/classes/OdooSalesLogger.php';
require_once dirname(__FILE__) . '/classes/OdooSalesHookTracker.php';
require_once dirname(__FILE__) . '/classes/OdooSalesRequestContext.php';

class odoo_sales_sync extends Module
{
    /** @var OdooSalesLogger */
    private $logger;

    /** @var OdooSalesHookTracker */
    private $hookTracker;

    /** @var OdooSalesRequestContext */
    private $requestContext;

    /** @var OdooSalesEventDetector */
    private $detector;

    /** @var OdooSalesCartRuleUsageTracker */
    private $cartRuleTracker;

    /** @var OdooSalesWebhookClient */
    private $webhookClient;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'odoo_sales_sync';
        $this->tab = 'administration';
        $this->version = '2.0.1';
        $this->author = 'Azor Data SL';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '8.99.99');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Odoo Sales Sync');
        $this->description = $this->l('Bi-directional synchronization with Odoo. Sends customer, order, invoice, and coupon events to Odoo. NEW v2.0: Receives updates FROM Odoo (reverse sync).');
        $this->author_email = 'info@azordata.com';

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall? All event data will be deleted.');

        // Initialize components only if module is already installed
        if (Module::isInstalled($this->name)) {
            $this->initializeComponents();
        }
    }

    /**
     * Initialize module components
     */
    private function initializeComponents()
    {
        $debugMode = Configuration::get('ODOO_SALES_SYNC_DEBUG', false);

        $this->logger = new OdooSalesLogger('odoo_sales_sync', $debugMode);
        $this->hookTracker = new OdooSalesHookTracker($this->logger);
        $this->requestContext = new OdooSalesRequestContext();
        $this->detector = new OdooSalesEventDetector($this->hookTracker, $this->logger, $this->requestContext);

        $cartRuleRepository = new OdooSalesCartRuleStateRepository($this->logger);
        $this->cartRuleTracker = new OdooSalesCartRuleUsageTracker($this->detector, $cartRuleRepository, $this->logger);

        $webhookUrl = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL', '');
        $webhookSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET', '');
        $this->webhookClient = new OdooSalesWebhookClient($webhookUrl, $webhookSecret, $this->logger);
    }

    /**
     * Module installation
     *
     * Registers all 23 hooks and creates database tables.
     *
     * @return bool Success
     */
    public function install()
    {
        // Run SQL installation
        if (!$this->installSQL()) {
            return false;
        }

        // Install admin tab
        if (!$this->installTab()) {
            return false;
        }

        return parent::install()
            // Customer hooks (5)
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('actionObjectCustomerAddAfter')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('actionObjectCustomerDeleteAfter')

            // Address hooks (3) - NEW
            && $this->registerHook('actionObjectAddressAddAfter')
            && $this->registerHook('actionObjectAddressUpdateAfter')
            && $this->registerHook('actionObjectAddressDeleteAfter')

            // Order hooks (9)
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionValidateOrderAfter')
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionObjectOrderUpdateAfter')
            && $this->registerHook('actionOrderEdited')
            && $this->registerHook('actionProductCancel')
            && $this->registerHook('actionAdminOrdersTrackingNumberUpdate')
            && $this->registerHook('actionOrderHistoryAddAfter')

            // Invoice hooks (5)
            && $this->registerHook('actionObjectOrderInvoiceAddAfter')
            && $this->registerHook('actionObjectOrderInvoiceUpdateAfter')
            && $this->registerHook('actionPDFInvoiceRender')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('actionSetInvoice')

            // Payment hooks (3)
            && $this->registerHook('actionPaymentCCAdd')
            && $this->registerHook('actionObjectOrderPaymentAddAfter')
            && $this->registerHook('actionPaymentConfirmation')

            // Coupon/Discount hooks (7)
            && $this->registerHook('actionObjectCartRuleAddAfter')
            && $this->registerHook('actionObjectCartRuleUpdateAfter')
            && $this->registerHook('actionObjectCartRuleDeleteAfter')
            && $this->registerHook('actionObjectSpecificPriceAddAfter')
            && $this->registerHook('actionObjectSpecificPriceUpdateAfter')
            && $this->registerHook('actionObjectSpecificPriceDeleteAfter')
            && $this->registerHook('actionCartSave'); // CRITICAL - for coupon usage tracking workaround
    }

    /**
     * Module uninstallation
     *
     * @return bool Success
     */
    public function uninstall()
    {
        // Run SQL uninstallation
        if (!$this->uninstallSQL()) {
            return false;
        }

        // Uninstall admin tab
        if (!$this->uninstallTab()) {
            return false;
        }

        // Remove configuration
        Configuration::deleteByName('ODOO_SALES_SYNC_ENABLED');
        Configuration::deleteByName('ODOO_SALES_SYNC_WEBHOOK_URL');
        Configuration::deleteByName('ODOO_SALES_SYNC_WEBHOOK_SECRET');
        Configuration::deleteByName('ODOO_SALES_SYNC_DEBUG');

        // v2.0.0 - Remove reverse sync configuration
        Configuration::deleteByName('ODOO_SALES_SYNC_REVERSE_ENABLED');
        Configuration::deleteByName('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');
        Configuration::deleteByName('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS');

        return parent::uninstall();
    }

    /**
     * Install SQL tables
     *
     * @return bool Success
     */
    private function installSQL()
    {
        $sqlFile = dirname(__FILE__) . '/sql/install.sql';

        if (!file_exists($sqlFile)) {
            // Cannot use logger during installation - logger requires database tables
            return false;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $queries = array_filter(explode(';', $sql));

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!Db::getInstance()->execute($query)) {
                    // Cannot use logger during installation
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Uninstall SQL tables
     *
     * @return bool Success
     */
    private function uninstallSQL()
    {
        $sqlFile = dirname(__FILE__) . '/sql/uninstall.sql';

        if (!file_exists($sqlFile)) {
            return true; // Not critical if missing
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $queries = array_filter(explode(';', $sql));

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                Db::getInstance()->execute($query);
            }
        }

        return true;
    }

    /**
     * Module configuration page
     *
     * @return string HTML content
     */
    public function getContent()
    {
        // Initialize components if not already done
        if (!$this->logger) {
            $this->initializeComponents();
        }

        // Ensure context is properly initialized
        if (!$this->context) {
            $this->context = Context::getContext();
        }

        // Handle AJAX requests
        if (Tools::getValue('ajax')) {
            $this->handleAjaxRequest();
            exit;
        }

        $output = '';

        // Handle form submission
        if (Tools::isSubmit('submitOdooSalesSync')) {
            $enabled = Tools::getValue('ODOO_SALES_SYNC_ENABLED');
            $webhookUrl = Tools::getValue('ODOO_SALES_SYNC_WEBHOOK_URL');
            $webhookSecret = Tools::getValue('ODOO_SALES_SYNC_WEBHOOK_SECRET');
            $debug = Tools::getValue('ODOO_SALES_SYNC_DEBUG');

            Configuration::updateValue('ODOO_SALES_SYNC_ENABLED', $enabled);
            Configuration::updateValue('ODOO_SALES_SYNC_WEBHOOK_URL', $webhookUrl);
            Configuration::updateValue('ODOO_SALES_SYNC_WEBHOOK_SECRET', $webhookSecret);
            Configuration::updateValue('ODOO_SALES_SYNC_DEBUG', $debug);

            // v2.0.0 - Reverse sync configuration
            $reverseEnabled = Tools::getValue('ODOO_SALES_SYNC_REVERSE_ENABLED');
            $debugWebhookUrl = Tools::getValue('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');
            $allowedIps = Tools::getValue('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS');

            Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ENABLED', $reverseEnabled);
            Configuration::updateValue('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL', $debugWebhookUrl);
            Configuration::updateValue('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS', $allowedIps);

            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        // Handle test connection
        if (Tools::isSubmit('testConnection')) {
            $testResult = $this->webhookClient->testConnection();

            if ($testResult['success']) {
                $output .= $this->displayConfirmation($this->l('Connection test successful!'));
            } else {
                $output .= $this->displayError($this->l('Connection test failed: ') . $testResult['message']);
            }
        }

        // Handle retry failed events
        if (Tools::isSubmit('retryFailed')) {
            $this->retryFailedEvents();
            $output .= $this->displayConfirmation($this->l('Retrying failed events...'));
        }

        // Get active tab from request
        $activeTab = Tools::getValue('active_tab', 'configuration');

        // Add CSS and JS
        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        // Assign variables for template
        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'active_tab' => $activeTab,
            'config_content' => $this->getConfigurationContent(),
            'events_content' => $this->getEventsContent(),
            'failed_content' => $this->getFailedContent(),
            'logs_content' => $this->getLogsContent(),
            'ajax_url' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
            'token' => Tools::getAdminTokenLite('AdminModules'),
            'link' => $this->context->link
        ));

        return $output . $this->display(__FILE__, 'views/templates/admin/main.tpl');
    }

    /**
     * Get configuration tab content
     *
     * @return string HTML content
     */
    private function getConfigurationContent()
    {
        // Build reverse webhook URL
        $reverseWebhookUrl = $this->context->link->getModuleLink(
            'odoo_sales_sync',
            'reverse_webhook',
            [],
            true
        );
        // Manual fallback if module link doesn't work
        if (empty($reverseWebhookUrl) || strpos($reverseWebhookUrl, 'reverse_webhook') === false) {
            $shopUrl = Tools::getShopDomainSsl(true);
            $reverseWebhookUrl = $shopUrl . __PS_BASE_URI__ . 'modules/odoo_sales_sync/reverse_webhook.php';
        }

        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Odoo Sales Sync Configuration'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable Sync'),
                        'name' => 'ODOO_SALES_SYNC_ENABLED',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('No'))
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook URL'),
                        'name' => 'ODOO_SALES_SYNC_WEBHOOK_URL',
                        'size' => 64,
                        'required' => true,
                        'desc' => $this->l('Full URL to Odoo webhook endpoint (e.g., https://your-odoo.com/api/prestashop/sales)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook Secret'),
                        'name' => 'ODOO_SALES_SYNC_WEBHOOK_SECRET',
                        'size' => 64,
                        'required' => true,
                        'desc' => $this->l('Secret key for webhook authentication')
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Debug Mode'),
                        'name' => 'ODOO_SALES_SYNC_DEBUG',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'debug_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'debug_off', 'value' => 0, 'label' => $this->l('No'))
                        ),
                        'desc' => $this->l('Enable debug logging (verbose)')
                    ),
                    array(
                        'type' => 'html',
                        'name' => 'reverse_sync_section_header',
                        'html_content' => '<hr><h3 style="margin-top:20px;">' . $this->l('🔄 Reverse Synchronization (v2.0.0)') . '</h3>'
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable Reverse Sync'),
                        'name' => 'ODOO_SALES_SYNC_REVERSE_ENABLED',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'reverse_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'reverse_off', 'value' => 0, 'label' => $this->l('No'))
                        ),
                        'desc' => $this->l('Allow Odoo to send updates back to PrestaShop (customers, orders, addresses, coupons)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Reverse Webhook URL'),
                        'name' => 'ODOO_SALES_SYNC_REVERSE_WEBHOOK_URL_DISPLAY',
                        'size' => 64,
                        'disabled' => true,
                        'readonly' => true,
                        'desc' => $this->l('Configure this URL in Odoo to enable reverse sync. Copy and paste into Odoo webhook settings.')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Debug Webhook URL'),
                        'name' => 'ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL',
                        'size' => 64,
                        'desc' => $this->l('Optional: URL for webhook debug server (e.g., http://localhost:8000/webhook)')
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Allowed IPs (Optional)'),
                        'name' => 'ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS',
                        'size' => 64,
                        'desc' => $this->l('Comma-separated list of allowed IP addresses for reverse webhooks (leave empty to allow all)')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                )
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitOdooSalesSync';

        $helper->fields_value['ODOO_SALES_SYNC_ENABLED'] = Configuration::get('ODOO_SALES_SYNC_ENABLED');
        $helper->fields_value['ODOO_SALES_SYNC_WEBHOOK_URL'] = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL');
        $helper->fields_value['ODOO_SALES_SYNC_WEBHOOK_SECRET'] = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET');
        $helper->fields_value['ODOO_SALES_SYNC_DEBUG'] = Configuration::get('ODOO_SALES_SYNC_DEBUG');

        // v2.0.0 - Reverse sync fields
        $helper->fields_value['ODOO_SALES_SYNC_REVERSE_ENABLED'] = Configuration::get('ODOO_SALES_SYNC_REVERSE_ENABLED');
        $helper->fields_value['ODOO_SALES_SYNC_REVERSE_WEBHOOK_URL_DISPLAY'] = $reverseWebhookUrl;
        $helper->fields_value['ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL'] = Configuration::get('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');
        $helper->fields_value['ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS'] = Configuration::get('ODOO_SALES_SYNC_REVERSE_ALLOWED_IPS');

        $formHtml = $helper->generateForm(array($fieldsForm));

        // Add test connection button and result area (matching reference module pattern)
        $testButtonHtml = '
        <div class="panel">
            <div class="panel-heading">' . $this->l('Connection Test') . '</div>
            <div class="panel-body">
                <button type="button" id="test-connection-btn" class="btn btn-primary" onclick="testOdooConnection(); return false;">
                    <i class="icon-refresh"></i> ' . $this->l('Test Connection') . '
                </button>
                <span id="test-connection-result" style="margin-left: 10px;"></span>
            </div>
        </div>';

        return $formHtml . $testButtonHtml;
    }

    /**
     * Get events tab content (using comprehensive controller)
     *
     * @return string HTML content
     */
    private function getEventsContent()
    {
        // Load the controller to handle complex filtering and batch entity loading
        require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/controllers/admin/AdminOdooSalesSyncEventsController.php';

        $controller = new AdminOdooSalesSyncEventsController();

        // Handle AJAX requests
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $controller->ajaxProcess();
            exit;
        }

        // Render the comprehensive events tab
        return $controller->renderView();
    }

    /**
     * Get failed events tab content
     *
     * @return string HTML content
     */
    private function getFailedContent()
    {
        $page = (int)Tools::getValue('failed_page', 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get failed events
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events
                WHERE sync_status = 'failed'
                ORDER BY id_event DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        $failedEvents = Db::getInstance()->executeS($sql);

        // Get total count
        $totalSql = "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events
                     WHERE sync_status = 'failed'";
        $totalFailed = (int)Db::getInstance()->getValue($totalSql);
        $totalPages = ceil($totalFailed / $limit);

        $this->context->smarty->assign(array(
            'failed_events' => $failedEvents ?: array(),
            'pagination' => array(
                'page' => $page,
                'pages' => $totalPages,
                'total' => $totalFailed,
                'limit' => $limit
            ),
            'link' => $this->context->link
        ));

        return $this->display(__FILE__, 'views/templates/admin/failed_tab.tpl');
    }

    /**
     * Get logs tab content with advanced filtering
     *
     * @return string HTML content
     */
    private function getLogsContent()
    {
        // Get filter parameters
        $page = (int)Tools::getValue('logs_page', 1);
        $limit = (int)Tools::getValue('logs_per_page', 100);
        $level = Tools::getValue('logs_level', '');
        $category = Tools::getValue('logs_category', '');
        $dateFrom = Tools::getValue('logs_date_from', '');
        $dateTo = Tools::getValue('logs_date_to', '');
        $search = Tools::getValue('logs_search', '');

        // Use enhanced getLogs method from logger
        $result = OdooSalesLogger::getLogs(
            $page,
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

        $this->context->smarty->assign(array(
            'logs' => $result['logs'],
            'pagination' => array(
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'limit' => $result['limit']
            ),
            'filters' => array(
                'level' => $level,
                'category' => $category,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
                'per_page' => $limit
            ),
            'link' => $this->context->link
        ));

        return $this->display(__FILE__, 'views/templates/admin/logs_tab.tpl');
    }

    /**
     * Handle AJAX requests
     */
    private function handleAjaxRequest()
    {
        $action = Tools::getValue('action');

        // Ensure components are initialized (including logger)
        if (!$this->logger) {
            $this->initializeComponents();
        }

        $this->logger->info('[AJAX] Request received in main module', [
            'action' => $action,
            'all_params' => $_REQUEST
        ]);

        $response = ['success' => false];

        try {
            switch ($action) {
                case 'testConnection':
                    $this->logger->info('[AJAX] Test connection action triggered');

                    // Ensure webhook client is initialized
                    if (!$this->webhookClient) {
                        $this->initializeComponents();
                    }

                    $result = $this->webhookClient->testConnection();
                    $this->logger->info('[AJAX] Test connection result', $result);
                    $response = $result;
                    break;

                case 'retryFailed':
                    $this->logger->info('[AJAX] Retry failed events action triggered');
                    $this->retryFailedEvents();
                    $response = ['success' => true, 'message' => $this->l('Retry initiated successfully')];
                    break;

                case 'getSyncStatus':
                    // Get sync status and event counts for status indicator
                    $db = Db::getInstance();

                    $pendingCount = (int)$db->getValue(
                        "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'pending'"
                    );

                    $failedCount = (int)$db->getValue(
                        "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_events WHERE sync_status = 'failed'"
                    );

                    $errorLogsCount = (int)$db->getValue(
                        "SELECT COUNT(*) FROM " . _DB_PREFIX_ . "odoo_sales_logs
                         WHERE level IN ('error', 'critical')
                         AND date_add >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                    );

                    $response = [
                        'success' => true,
                        'pending_events' => $pendingCount,
                        'failed_events' => $failedCount,
                        'error_logs' => $errorLogsCount
                    ];
                    break;

                // Delegate events-related actions to controller
                case 'retryEvent':
                case 'bulkRetryEvents':
                case 'bulkMarkAsSent':
                case 'viewEventDetails':
                case 'exportEvents':
                    $this->logger->debug('[AJAX] Delegating to events controller', ['action' => $action]);
                    require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/controllers/admin/AdminOdooSalesSyncEventsController.php';
                    $controller = new AdminOdooSalesSyncEventsController();
                    $controller->ajaxProcess();
                    exit; // Controller handles response
                    break;

                default:
                    $this->logger->warning('[AJAX] Unknown action requested', ['action' => $action]);
                    $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
                    break;
            }
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('[AJAX] Exception during AJAX handling', [
                    'action' => $action,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            $response = [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }

        die(json_encode($response));
    }

    /**
     * Retry failed events
     */
    private function retryFailedEvents()
    {
        // Get all failed events
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events
                WHERE sync_status = 'failed'
                LIMIT 100";

        $events = Db::getInstance()->executeS($sql);

        foreach ($events as $eventData) {
            $event = new OdooSalesEvent($eventData['id_event']);
            $this->webhookClient->sendEvent($event);
        }
    }

    // ========================================================================
    // CUSTOMER HOOKS (5)
    // ========================================================================

    public function hookActionCustomerAccountAdd($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCustomerChange('actionCustomerAccountAdd', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionCustomerAccountAdd', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionAuthentication($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCustomerChange('actionAuthentication', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionAuthentication', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectCustomerAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCustomerChange('actionObjectCustomerAddAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCustomerAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectCustomerUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCustomerChange('actionObjectCustomerUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCustomerUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectCustomerDeleteAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCustomerChange('actionObjectCustomerDeleteAfter', $params, 'deleted');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCustomerDeleteAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // ADDRESS HOOKS (3) - NEW
    // ========================================================================

    public function hookActionObjectAddressAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectAddressChange('actionObjectAddressAddAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectAddressAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectAddressUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectAddressChange('actionObjectAddressUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectAddressUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectAddressDeleteAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectAddressChange('actionObjectAddressDeleteAfter', $params, 'deleted');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectAddressDeleteAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // ORDER HOOKS (4)
    // ========================================================================

    public function hookActionValidateOrder($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionValidateOrder', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionValidateOrder', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionOrderStatusUpdate($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionOrderStatusUpdate', $params, 'status_changed');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionOrderStatusUpdate', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectOrderUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionObjectOrderUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectOrderUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionOrderEdited($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionOrderEdited', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionOrderEdited', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionValidateOrderAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionValidateOrderAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionValidateOrderAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionOrderStatusPostUpdate', $params, 'status_changed');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionOrderStatusPostUpdate', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionProductCancel($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectProductCancellation('actionProductCancel', $params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionProductCancel', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionAdminOrdersTrackingNumberUpdate($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderChange('actionAdminOrdersTrackingNumberUpdate', $params, 'tracking_updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionAdminOrdersTrackingNumberUpdate', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionOrderHistoryAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectOrderHistoryChange('actionOrderHistoryAddAfter', $params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionOrderHistoryAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // INVOICE HOOKS (5)
    // ========================================================================

    public function hookActionObjectOrderInvoiceAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectInvoiceChange('actionObjectOrderInvoiceAddAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectOrderInvoiceAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectOrderInvoiceUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectInvoiceChange('actionObjectOrderInvoiceUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectOrderInvoiceUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionPDFInvoiceRender($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectInvoiceChange('actionPDFInvoiceRender', $params, 'pdf_rendered');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionPDFInvoiceRender', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionOrderSlipAdd($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectInvoiceChange('actionOrderSlipAdd', $params, 'credit_memo_created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionOrderSlipAdd', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionSetInvoice($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectInvoiceNumberAssignment('actionSetInvoice', $params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionSetInvoice', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // PAYMENT HOOKS (3)
    // ========================================================================

    public function hookActionPaymentCCAdd($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectPaymentEvent('actionPaymentCCAdd', $params, 'confirmed');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionPaymentCCAdd', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectOrderPaymentAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectPaymentEvent('actionObjectOrderPaymentAddAfter', $params, 'received');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectOrderPaymentAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionPaymentConfirmation($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectPaymentConfirmation('actionPaymentConfirmation', $params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionPaymentConfirmation', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // COUPON/DISCOUNT HOOKS (7)
    // ========================================================================

    public function hookActionObjectCartRuleAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectCartRuleAddAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCartRuleAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectCartRuleUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCartRuleUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectCartRuleDeleteAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectCartRuleDeleteAfter', $params, 'deleted');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectCartRuleDeleteAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectSpecificPriceAddAfter', $params, 'created');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectSpecificPriceAddAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectSpecificPriceUpdateAfter', $params, 'updated');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectSpecificPriceUpdateAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->detector->detectCouponChange('actionObjectSpecificPriceDeleteAfter', $params, 'deleted');
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionObjectSpecificPriceDeleteAfter', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    /**
     * CRITICAL HOOK: actionCartSave
     *
     * Used for coupon usage tracking workaround.
     * PrestaShop does NOT fire hooks when Cart::addCartRule() or Cart::removeCartRule()
     * are called, so we must diff cart state on every save.
     */
    public function hookActionCartSave($params)
    {
        try {
            if (!$this->logger) {
                $this->initializeComponents();
            }
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }
            return $this->cartRuleTracker->handleCartSave($params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook failed', array('hook' => 'hookActionCartSave', 'error' => $e->getMessage()));
            }
            return false;
        }
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Handle hook with error protection
     *
     * @param string $hookName Hook name
     * @param array $params Hook parameters
     * @param callable $handler Handler function
     * @return bool Success
     */
    private function handleHook($hookName, $params, $handler)
    {
        try {
            // Initialize components if not already done
            if (!$this->logger) {
                $this->initializeComponents();
            }

            // Check if module is enabled
            if (!Configuration::get('ODOO_SALES_SYNC_ENABLED')) {
                return true;
            }

            // Execute handler
            return $handler($params);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Hook handler failed', [
                    'hook' => $hookName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // Never break page load due to webhook module
            return false;
        }
    }

    /**
     * Install admin tab
     *
     * @return bool Success
     */
    private function installTab()
    {
        // Create main tab (Dashboard) as the parent - it will be clickable
        $dashboardTab = new Tab();
        $existingId = (int)Tab::getIdFromClassName('AdminOdooSalesSync');
        if ($existingId) {
            $dashboardTab = new Tab($existingId);
        }

        $dashboardTab->active = 1;
        $dashboardTab->class_name = 'AdminOdooSalesSync';
        $dashboardTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $dashboardTab->name[$lang['id_lang']] = 'Odoo Sales Sync';
        }

        $dashboardTab->id_parent = 0;  // Root level - creates independent section
        $dashboardTab->module = $this->name;
        $dashboardTab->icon = 'sync';

        if (!$dashboardTab->save()) {
            return false;
        }

        $parentTabId = $dashboardTab->id;

        // Create Events tab
        $eventsTab = new Tab();
        $existingId = (int)Tab::getIdFromClassName('AdminOdooSalesSyncEvents');
        if ($existingId) {
            $eventsTab = new Tab($existingId);
        }

        $eventsTab->active = 1;
        $eventsTab->class_name = 'AdminOdooSalesSyncEvents';
        $eventsTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $eventsTab->name[$lang['id_lang']] = 'Events';
        }

        $eventsTab->id_parent = $parentTabId;
        $eventsTab->module = $this->name;

        if (!$eventsTab->save()) {
            return false;
        }

        // Create Logs tab
        $logsTab = new Tab();
        $existingId = (int)Tab::getIdFromClassName('AdminOdooSalesSyncLogs');
        if ($existingId) {
            $logsTab = new Tab($existingId);
        }

        $logsTab->active = 1;
        $logsTab->class_name = 'AdminOdooSalesSyncLogs';
        $logsTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $logsTab->name[$lang['id_lang']] = 'Logs';
        }

        $logsTab->id_parent = $parentTabId;
        $logsTab->module = $this->name;

        if (!$logsTab->save()) {
            return false;
        }

        // Create Failed Events tab
        $failedTab = new Tab();
        $existingId = (int)Tab::getIdFromClassName('AdminOdooSalesSyncFailed');
        if ($existingId) {
            $failedTab = new Tab($existingId);
        }

        $failedTab->active = 1;
        $failedTab->class_name = 'AdminOdooSalesSyncFailed';
        $failedTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $failedTab->name[$lang['id_lang']] = 'Failed Events';
        }

        $failedTab->id_parent = $parentTabId;
        $failedTab->module = $this->name;

        if (!$failedTab->save()) {
            return false;
        }

        // Create Configuration tab
        $configTab = new Tab();
        $existingId = (int)Tab::getIdFromClassName('AdminOdooSalesSyncConfig');
        if ($existingId) {
            $configTab = new Tab($existingId);
        }

        $configTab->active = 1;
        $configTab->class_name = 'AdminOdooSalesSyncConfig';
        $configTab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $configTab->name[$lang['id_lang']] = 'Configuration';
        }

        $configTab->id_parent = $parentTabId;
        $configTab->module = $this->name;

        if (!$configTab->save()) {
            return false;
        }

        return true;
    }

    /**
     * Uninstall admin tab
     *
     * @return bool Success
     */
    private function uninstallTab()
    {
        $tabs = [
            'AdminOdooSalesSyncConfig',
            'AdminOdooSalesSyncFailed',
            'AdminOdooSalesSyncLogs',
            'AdminOdooSalesSyncEvents',
            'AdminOdooSalesSync'
        ];

        foreach ($tabs as $className) {
            $tabId = (int)Tab::getIdFromClassName($className);
            if ($tabId) {
                $tab = new Tab($tabId);
                $tab->delete();
            }
        }

        return true;
    }
}
