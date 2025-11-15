<?php
/**
 * Logs controller for Odoo Sales Sync module
 */

require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesLogger.php';
require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesLog.php';

class AdminOdooSalesSyncLogsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'odoo_sales_logs';
        $this->className = 'OdooSalesLog';
        $this->lang = false;
        $this->bootstrap = true;
        $this->context = Context::getContext();

        parent::__construct();

        $this->identifier = 'id_log';

        // Define list fields
        $this->fields_list = array(
            'id_log' => array(
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ),
            'level' => array(
                'title' => $this->l('Level'),
                'type' => 'select',
                'list' => array(
                    'debug' => 'Debug',
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'error' => 'Error',
                    'critical' => 'Critical'
                ),
                'filter_key' => 'level',
                'filter_type' => 'string',
                'color' => 'color',
                'class' => 'fixed-width-sm'
            ),
            'category' => array(
                'title' => $this->l('Category'),
                'type' => 'select',
                'list' => array(
                    'detection' => 'Detection',
                    'api' => 'API',
                    'sync' => 'Sync',
                    'system' => 'System',
                    'performance' => 'Performance'
                ),
                'filter_key' => 'category',
                'class' => 'fixed-width-sm'
            ),
            'message' => array(
                'title' => $this->l('Message'),
                'filter_key' => 'message'
            ),
            'correlation_id' => array(
                'title' => $this->l('Correlation ID'),
                'class' => 'fixed-width-lg'
            ),
            'date_add' => array(
                'title' => $this->l('Date'),
                'type' => 'datetime',
                'filter_key' => 'date_add'
            )
        );

        // Add bulk actions
        $this->bulk_actions = array(
            'delete' => array(
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon' => 'icon-trash'
            )
        );

        // Set default order
        $this->_defaultOrderBy = 'id_log';
        $this->_defaultOrderWay = 'DESC';

        // Set pagination limit
        $this->_pagination = [20, 50, 100, 300];
        $this->_default_pagination = 300;

        // Add custom CSS/JS
        if ($this->module) {
            $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        }
    }

    public function renderList()
    {
        // Add row actions
        $this->addRowAction('view');
        $this->addRowAction('delete');

        return parent::renderList();
    }

    public function renderView()
    {
        if (!($log = $this->loadObject())) {
            return;
        }

        $this->tpl_view_vars = array(
            'log' => $log,
            'link' => $this->context->link
        );

        return parent::renderView();
    }

    public function displayColorColor($value, $row)
    {
        switch ($row['level']) {
            case 'debug':
                return '<span class="label label-default">' . $value . '</span>';
            case 'info':
                return '<span class="label label-info">' . $value . '</span>';
            case 'warning':
                return '<span class="label label-warning">' . $value . '</span>';
            case 'error':
                return '<span class="label label-danger">' . $value . '</span>';
            case 'critical':
                return '<span class="label label-danger"><strong>' . $value . '</strong></span>';
            default:
                return $value;
        }
    }
}
