<?php
/**
 * Configuration controller for Odoo Sales Sync module
 */

class AdminOdooSalesSyncConfigController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';
        $this->display = 'view';

        parent::__construct();

        $this->meta_title = $this->l('Odoo Sales Sync Configuration');
    }

    public function init()
    {
        parent::init();

        // Redirect to module configuration via AdminModules
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => 'odoo_sales_sync',
            'tab_module' => 'administration',
            'module_name' => 'odoo_sales_sync'
        ]));
    }
}
