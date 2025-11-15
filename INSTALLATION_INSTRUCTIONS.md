# Odoo Sales Sync - Installation Instructions

**Version**: 1.0.0
**PrestaShop**: 8.0.x, 8.1.x, 8.2.x

---

## 📦 Creating the ZIP File (Manual)

### Option 1: Direct Copy (Recommended)

1. Copy the entire `odoo_sales_sync` folder to your PrestaShop:
   ```
   src/modules/odoo_sales_sync/ → /path/to/prestashop/modules/odoo_sales_sync/
   ```

2. Go to Back Office > Modules > Module Manager

3. Search for "Odoo Sales Sync"

4. Click "Install"

### Option 2: ZIP Upload

1. **Create ZIP file** from `src/modules/odoo_sales_sync/`:
   - On Windows: Right-click folder > Send to > Compressed (zipped) folder
   - On Mac: Right-click folder > Compress "odoo_sales_sync"
   - On Linux: `cd src/modules && zip -r odoo_sales_sync.zip odoo_sales_sync/`

2. **Important**: The ZIP structure must be:
   ```
   odoo_sales_sync.zip
   └── odoo_sales_sync/
       ├── odoo_sales_sync.php
       ├── config.xml
       ├── logo.png
       ├── classes/
       ├── controllers/
       ├── sql/
       └── views/
   ```

   ⚠️ **NOT** like this:
   ```
   odoo_sales_sync.zip
   └── modules/
       └── odoo_sales_sync/
   ```

3. Go to Back Office > Modules > Upload a module

4. Drop the ZIP file or click to browse

5. Click "Install"

---

## ✅ Module Structure Verification

Before creating ZIP, verify this structure exists:

```
odoo_sales_sync/
├── odoo_sales_sync.php          ✅ Main module file (class OdooSalesSync)
├── config.xml                   ✅ Module metadata
├── logo.png                     ✅ Module icon (32x32)
├── index.php                    ✅ Security file
├── README.txt                   ✅ Quick reference
│
├── classes/                     ✅ 8 PHP classes
│   ├── SalesEvent.php
│   ├── SalesEventDetector.php
│   ├── OdooWebhookClient.php
│   ├── CartRuleUsageTracker.php
│   ├── CartRuleStateRepository.php
│   ├── EventLogger.php
│   ├── HookTracker.php
│   ├── RequestContext.php
│   └── index.php
│
├── sql/                         ✅ Database scripts
│   ├── install.sql
│   ├── uninstall.sql
│   └── index.php
│
├── controllers/
│   ├── admin/
│   │   └── index.php
│   └── index.php
│
├── views/
│   ├── templates/
│   │   ├── admin/
│   │   │   └── index.php
│   │   └── index.php
│   └── index.php
│
├── translations/                (empty, for future use)
└── upgrade/                     (empty, for future use)
```

---

## 🔧 Post-Installation Configuration

### 1. Start Webhook Receiver (For Testing)

```bash
cd odoo_sales_sync_implementation
python3 debug_webhook_receiver.py --port 5000 --secret test_secret_123
```

### 2. Configure Module

1. Go to **Back Office > Modules > Odoo Sales Sync > Configure**

2. Set configuration:
   - **Enable Sync**: Yes
   - **Webhook URL**: `http://localhost:5000/webhook` (or your Odoo URL)
   - **Webhook Secret**: `test_secret_123`
   - **Debug Mode**: Yes (for testing, disable in production)

3. Click **"Test Connection"** button

4. You should see success message

5. Click **"Save"**

### 3. Verify Installation

Run this SQL query in your database:

```sql
SHOW TABLES LIKE 'ps_odoo_sales_%';
```

Expected result (4 tables):
```
ps_odoo_sales_events
ps_odoo_sales_logs
ps_odoo_sales_dedup
ps_odoo_sales_cart_rule_state
```

---

## 🧪 Quick Test

### Test 1: Register New Customer

1. Go to front office
2. Register new customer account
3. Check webhook receiver output - should see webhook
4. Check database:
   ```sql
   SELECT * FROM ps_odoo_sales_events ORDER BY date_add DESC LIMIT 1;
   ```

### Test 2: Create Order

1. Add product to cart
2. Complete checkout
3. Check webhook receiver - should see order webhook

### Test 3: Apply Coupon

1. Create a cart rule in Back Office
2. Apply to cart
3. Check webhook receiver - should see "applied" webhook

---

## 🐛 Troubleshooting Installation

### Error: "This file is not a valid ZIP module"

**Causes**:
- ZIP structure is wrong (has extra parent folder)
- Module folder name doesn't match module name in PHP
- Missing required files

**Solution**:
1. Verify ZIP structure (see above)
2. Ensure `odoo_sales_sync.php` contains `class OdooSalesSync`
3. Ensure `config.xml` has `<name>odoo_sales_sync</name>`
4. Ensure all required files present

### Error: "Cannot install module"

**Check**:
1. PHP version >= 7.2
2. PrestaShop version >= 8.0
3. Database permissions (module needs to create tables)
4. Check PrestaShop error logs

### Error: "Module installed but not showing in list"

**Solution**:
1. Clear PrestaShop cache: Back Office > Advanced Parameters > Performance > Clear cache
2. Regenerate modules list: Back Office > Modules > Module Manager > Click refresh icon

---

## 📋 Installation Checklist

- [ ] Module folder copied to `modules/odoo_sales_sync/`
- [ ] Main file `odoo_sales_sync.php` present
- [ ] Class name is `OdooSalesSync` (camelCase, no underscores)
- [ ] `config.xml` present with correct module name
- [ ] `logo.png` present (32x32 pixels)
- [ ] All 8 PHP classes in `classes/` folder
- [ ] SQL files in `sql/` folder
- [ ] All `index.php` security files present
- [ ] Module installs without errors
- [ ] 4 database tables created
- [ ] Module appears in Module Manager
- [ ] Configuration page accessible
- [ ] Test connection works

---

## 🚀 Next Steps After Installation

1. **Configure webhook URL** with your Odoo endpoint
2. **Run test suite** (see TESTING_GUIDE.md)
3. **Verify critical features**:
   - Customer registration → webhook
   - Address change → webhook
   - Coupon apply/remove → webhooks
   - Order creation → webhook
   - Invoice PDF → webhook
4. **Monitor logs**: Check `ps_odoo_sales_logs` table
5. **Check sync status**: `SELECT * FROM ps_odoo_sales_events WHERE sync_status = 'failed';`

---

## 📞 Support

If installation fails, check:

1. **PrestaShop logs**: `var/logs/`
2. **Module logs**: `SELECT * FROM ps_odoo_sales_logs WHERE level = 'error';`
3. **PHP error logs**: Check your server's error log
4. **Browser console**: For JavaScript errors

For detailed troubleshooting, see:
- **IMPLEMENTATION_GUIDE.md** - Technical details
- **TESTING_GUIDE.md** - Testing procedures
- **README.md** - Overview and features

---

## ⚠️ Important Notes

### Class Naming
- ✅ Class name: `OdooSalesSync` (camelCase)
- ❌ NOT: `Odoo_Sales_Sync` (underscores break PrestaShop autoloader)

### File Naming
- ✅ File name: `odoo_sales_sync.php` (underscores OK in filename)
- ✅ Module name: `odoo_sales_sync` (in config.xml and __construct)

### Required Files
PrestaShop requires these files for module to be valid:
- `odoo_sales_sync.php` (main module class)
- `config.xml` (module metadata)
- `logo.png` (module icon, minimum 32x32px)

Missing any of these = installation fails

---

**Installation Status**: ✅ Ready
**Manual ZIP Creation**: Required (compress `odoo_sales_sync/` folder)
**Installation Method**: Direct copy OR ZIP upload via Back Office
