-- Odoo Sales Sync Module - Database Uninstallation
-- Drops all module tables

DROP TABLE IF EXISTS `PREFIX_odoo_sales_events`;
DROP TABLE IF EXISTS `PREFIX_odoo_sales_logs`;
DROP TABLE IF EXISTS `PREFIX_odoo_sales_dedup`;
DROP TABLE IF EXISTS `PREFIX_odoo_sales_cart_rule_state`;
