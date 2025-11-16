<?php
/**
 * Upgrade script for odoo_sales_sync v2.0.1
 *
 * Minor update: UI improvements and reverse sync visibility enhancements
 *
 * @return bool Success
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_1($module)
{
    $success = true;

    // Clear cache to ensure new JavaScript and template changes are loaded
    try {
        Tools::clearSmartyCache();
        Tools::clearXMLCache();
    } catch (Exception $e) {
        error_log('Upgrade 2.0.1 warning: Could not clear cache - ' . $e->getMessage());
    }

    return $success;
}
