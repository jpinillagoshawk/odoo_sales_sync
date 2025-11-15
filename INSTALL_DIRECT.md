# Direct Installation Method

If ZIP upload continues to fail, try direct file copy:

## Method: Direct FTP/SFTP Upload

1. **Upload the entire folder** to your PrestaShop server:
   ```
   Local: odoo_sales_sync/
   Server: /var/www/vhosts/dev.elultimokoala.com/httpdocs/modules/odoo_sales_sync/
   ```

2. **Set permissions**:
   ```bash
   chmod -R 755 /var/www/vhosts/dev.elultimokoala.com/httpdocs/modules/odoo_sales_sync
   ```

3. **Clear PrestaShop cache**:
   ```bash
   rm -rf /var/www/vhosts/dev.elultimokoala.com/httpdocs/var/cache/*
   ```

4. **Install via Back Office**:
   - Go to Modules > Module Manager
   - Search for "Odoo Sales Sync"
   - Click "Install"

## Troubleshooting

If module doesn't appear after upload:
```bash
# Reset modules list
php /var/www/vhosts/dev.elultimokoala.com/httpdocs/bin/console prestashop:module reset odoo_sales_sync
```

Check PrestaShop logs:
```bash
tail -f /var/www/vhosts/dev.elultimokoala.com/httpdocs/var/logs/dev.log
```

Check PHP error log for actual error message.
