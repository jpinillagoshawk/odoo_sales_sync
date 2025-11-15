<?php
/**
 * Failed Events controller for Odoo Sales Sync module
 */

require_once _PS_MODULE_DIR_ . 'odoo_sales_sync/classes/OdooSalesEvent.php';

class AdminOdooSalesSyncFailedController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'odoo_sales_events';
        $this->className = 'OdooSalesEvent';
        $this->lang = false;
        $this->bootstrap = true;
        $this->context = Context::getContext();

        parent::__construct();

        $this->identifier = 'id_event';

        // Define list fields (same as Events but filtered to failed only)
        $this->fields_list = array(
            'id_event' => array(
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ),
            'event_type' => array(
                'title' => $this->l('Type'),
                'filter_key' => 'event_type'
            ),
            'entity_type' => array(
                'title' => $this->l('Entity'),
                'filter_key' => 'entity_type'
            ),
            'entity_id' => array(
                'title' => $this->l('Entity ID'),
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ),
            'action_type' => array(
                'title' => $this->l('Action'),
                'filter_key' => 'action_type'
            ),
            'sync_error' => array(
                'title' => $this->l('Error'),
                'filter_key' => 'sync_error'
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
        $this->_defaultOrderBy = 'id_event';
        $this->_defaultOrderWay = 'DESC';

        // Filter to show only failed events
        $this->_where = "AND a.sync_status = 'failed'";
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
        if (!($event = $this->loadObject())) {
            return;
        }

        $this->tpl_view_vars = array(
            'event' => $event,
            'link' => $this->context->link
        );

        return parent::renderView();
    }
}
