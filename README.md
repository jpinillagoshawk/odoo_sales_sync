# Odoo Sales Sync

**PrestaShop to Odoo Sales Synchronization Module**

A robust, webhook-based synchronization module for PrestaShop that captures and transmits sales events (customers, orders, invoices, coupons, payments) to Odoo via webhooks.

[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.0+-blue.svg)](https://www.prestashop.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)]()

---

## 🎯 Features

### Complete Event Detection
- ✅ **Customer Events**: Create, update, delete
- ✅ **Address Events**: Create, update, delete (normalized to customer updates)
- ✅ **Order Events**: Create, update, status changes
- ✅ **Invoice Events**: Create, update, PDF rendering
- ✅ **Payment Events**: Payment received, confirmed
- ✅ **Coupon Events**: Create, update, delete, usage tracking

### Comprehensive Data Capture
- 📦 **70+ fields per product line** in orders
- 🎫 **40+ fields per coupon/cart rule**
- 📊 **Complete order history** with status changes
- 💳 **All payment records** with transaction IDs
- 💬 **Customer messages** preserved
- 📝 **Internal notes** included
- 🔗 **Order-coupon relationships** automatically detected

### Enterprise-Grade Reliability
- 🔄 **Batch webhook delivery** for efficiency
- ♻️ **Automatic retry** with exponential backoff
- 🔐 **Webhook secret validation**
- 📝 **Comprehensive logging** with context
- 🎯 **Duplicate prevention** with hash-based tracking
- ⚡ **Async processing** via shutdown hooks
- 🔍 **Event consolidation** to reduce redundant webhooks

### Admin Interface
- 📊 **Event monitoring** with pagination
- ❌ **Failed event tracking** with manual retry
- 📋 **System logs** with context viewer
- ⚙️ **Configuration management**
- 🧪 **Connection testing**

---

## 📋 Requirements

- **PrestaShop**: 8.0.0 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.7 or higher
- **Network**: Outbound HTTPS access for webhooks
- **Odoo**: Webhook receiver endpoint

---

## 🚀 Installation

### 1. Download Module

```bash
# Clone repository
git clone https://github.com/jpinillagoshawk/odoo_sales_sync.git

# Or download ZIP and extract
```

### 2. Upload to PrestaShop

```bash
# Copy to modules directory
cp -r odoo_sales_sync /var/www/html/prestashop/modules/

# Set permissions
chown -R www-data:www-data /var/www/html/prestashop/modules/odoo_sales_sync
chmod -R 755 /var/www/html/prestashop/modules/odoo_sales_sync
```

### 3. Install Module

1. Go to **Back Office** → **Modules** → **Module Manager**
2. Search for "Odoo Sales Sync"
3. Click **Install**
4. Module will automatically create database tables

### 4. Configure Module

1. Click **Configure** after installation
2. Go to **Configuration** tab
3. Set webhook URL (e.g., `https://your-odoo.com/webhook`)
4. Set webhook secret (for security)
5. Set timeout (default: 30 seconds)
6. Enable sync
7. Click **Save**
8. Click **Test Connection** to verify

---

## ⚙️ Configuration

### Webhook Settings

```
Webhook URL: https://your-odoo-instance.com/prestashop/webhook
Webhook Secret: your-secure-secret-key
Timeout: 30 (seconds)
Enable Sync: Yes
```

### Advanced Settings (Optional)

Edit `classes/OdooSalesWebhookClient.php` to customize:
- Retry delays
- Maximum payload size
- Batch size limits

---

## 📊 Webhook Payload Structure

### Order Event Example

```json
{
  "batch_id": "batch_20251115114300_9958d37c",
  "timestamp": "2025-11-15 11:43:00",
  "events": [
    {
      "event_id": 163,
      "entity_type": "order",
      "entity_id": "999946",
      "entity_name": "XKBKNABJK",
      "action_type": "created",
      "hook_name": "actionValidateOrder",
      "after_data": {
        "id_order": 999946,
        "reference": "XKBKNABJK",
        "date_add": "2025-11-15 11:42:53",
        "current_state": 2,
        "order_details": [
          {
            "product_id": 5,
            "product_name": "T-Shirt - Blue - Size M",
            "product_quantity": 2,
            "unit_price_tax_excl": 25.00,
            "total_price_tax_excl": 50.00,
            "product_tax": 10.50,
            // ... 60+ more fields per product
          }
        ],
        "order_history": [ /* status changes */ ],
        "order_payments": [ /* payment records */ ],
        "messages": [ /* customer messages */ ]
      }
    }
  ]
}
```

See [WEBHOOK_PAYLOAD_SPECIFICATION.md](WEBHOOK_PAYLOAD_SPECIFICATION.md) for complete payload documentation.

---

## 🔧 Usage

### Monitor Events

1. Go to **Modules** → **Odoo Sales Sync** → **Configure**
2. Click **Events** tab
3. View all detected events with status
4. Filter by entity type or action

### Retry Failed Events

1. Go to **Failed Events** tab
2. Review errors
3. Click **Retry All Failed** to resend

### View Logs

1. Go to **Logs** tab
2. Filter by level (ERROR, WARNING, INFO, DEBUG)
3. Click **View Context** for detailed information

### Database Queries

```sql
-- View recent events
SELECT * FROM ps_odoo_sales_events
ORDER BY id_event DESC LIMIT 10;

-- View failed events
SELECT * FROM ps_odoo_sales_events
WHERE sync_status = 'failed'
ORDER BY id_event DESC;

-- View logs
SELECT * FROM ps_odoo_sales_logs
WHERE level = 'ERROR'
ORDER BY id_log DESC LIMIT 50;
```

---

## 📁 Module Structure

```
odoo_sales_sync/
├── odoo_sales_sync.php          # Main module file
├── classes/
│   ├── OdooSalesEvent.php       # Event object model
│   ├── OdooSalesEventDetector.php # Event detection logic
│   ├── OdooSalesEventQueue.php  # Async event queue
│   ├── OdooSalesWebhookClient.php # Webhook HTTP client
│   ├── OdooSalesLogger.php      # Logging system
│   ├── OdooSalesHookTracker.php # Duplicate prevention
│   ├── OdooSalesRequestContext.php # Request context
│   └── OdooSalesRetryManager.php # Retry logic
├── views/
│   ├── templates/admin/         # Admin UI templates
│   ├── css/admin.css           # Admin styles
│   └── js/admin.js             # Admin JavaScript
├── webhook_processor.php        # Webhook batch processor
├── webhook.php                  # Webhook trigger endpoint
├── cron.php                    # Cron job for retries
└── docs/
    ├── README.md               # Main documentation
    ├── IMPLEMENTATION_GUIDE.md # Setup guide
    ├── TESTING_GUIDE.md        # Testing instructions
    ├── WEBHOOK_PAYLOAD_SPECIFICATION.md # Payload docs
    ├── UPGRADE_NOTES_v1.1.0.md # Upgrade guide
    └── FIELD_ENHANCEMENTS_v1.1.0.md # Field mapping
```

---

## 🧪 Testing

### Debug Webhook Server

The module includes a Python debug server for testing:

```bash
cd odoo_sales_sync
python3 webhook_debug_server.py --port 5000 --log-file webhooks.log
```

### Using ngrok for Testing

```bash
# Start ngrok
ngrok http 5000

# Configure module with ngrok URL
# Webhook URL: https://xxxxx.ngrok-free.dev/webhook
```

### Test Events

1. Create customer account → Check webhook
2. Add address → Check webhook
3. Create order → Check webhook
4. Change order status → Check webhook
5. Apply coupon → Check webhook

---

## 🔄 Webhook Retry Logic

Failed webhooks are automatically retried with exponential backoff:

| Attempt | Delay |
|---------|-------|
| 1 | 10 seconds |
| 2 | 1 minute |
| 3 | 5 minutes |
| 4 | 15 minutes |
| 5 | 1 hour |
| 6+ | 24 hours |

Maximum retry attempts: Unlimited (until manual intervention)

---

## 🔐 Security

### Webhook Secret Validation

All webhooks include `X-Webhook-Secret` header for validation:

```http
POST /webhook HTTP/1.1
Host: your-odoo.com
Content-Type: application/json
X-Webhook-Secret: your-secret-key
X-Batch-ID: batch_20251115114300_9958d37c
```

### Sensitive Data

- ❌ **DO NOT** commit `.env` files
- ❌ **DO NOT** commit configuration with real secrets
- ✅ **DO** use environment variables for secrets
- ✅ **DO** use HTTPS for webhook URLs
- ✅ **DO** rotate webhook secrets regularly

### Database Security

- All SQL queries use PrestaShop's `pSQL()` for sanitization
- No direct user input in queries
- Prepared statements where applicable

---

## 📈 Performance

### Optimization Features

- ✅ **Batch processing**: Multiple events sent in one HTTP request
- ✅ **Event consolidation**: Reduces duplicate events
- ✅ **Async processing**: Events queued for shutdown processing
- ✅ **Indexed tables**: Fast event lookups
- ✅ **Payload limits**: Max 100 product lines per order

### Expected Performance

- Event detection: <10ms per hook
- Batch processing: ~100-200ms for 10 events
- Database inserts: ~5ms per event
- Webhook delivery: ~500ms-2s (network dependent)

---

## 🐛 Troubleshooting

### Webhooks Not Sending

1. Check module is enabled: **Configuration** → **Enable Sync** = Yes
2. Check webhook URL is correct
3. Check network connectivity: **Test Connection**
4. Review logs: **Logs** tab
5. Check failed events: **Failed Events** tab

### Events Not Detected

1. Verify hooks are registered: Check `ps_hook` table
2. Enable debug mode in module
3. Check logs for errors
4. Verify PrestaShop version compatibility

### Performance Issues

1. Check batch size (default: unlimited, max recommended: 50)
2. Increase webhook timeout if slow network
3. Review database indexes
4. Monitor `ps_odoo_sales_events` table size (clean old events)

### Common Errors

**HTTP 400 Bad Request**
- Check webhook payload format
- Verify Odoo endpoint expects batch format
- Check webhook secret matches

**HTTP 401/403 Unauthorized**
- Verify webhook secret is correct
- Check Odoo endpoint authentication

**HTTP 500 Server Error**
- Check Odoo logs for errors
- Verify Odoo can handle payload size
- Check Odoo endpoint is working

---

## 📚 Documentation

- **[README.md](README.md)** - This file
- **[IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)** - Detailed setup guide
- **[TESTING_GUIDE.md](TESTING_GUIDE.md)** - Testing procedures
- **[WEBHOOK_PAYLOAD_SPECIFICATION.md](WEBHOOK_PAYLOAD_SPECIFICATION.md)** - Complete payload docs
- **[UPGRADE_NOTES_v1.1.0.md](UPGRADE_NOTES_v1.1.0.md)** - Upgrade instructions
- **[FIELD_ENHANCEMENTS_v1.1.0.md](FIELD_ENHANCEMENTS_v1.1.0.md)** - Field mapping documentation
- **[CHANGELOG.md](CHANGELOG.md)** - Version history

---

## 🔄 Version History

### v1.1.0 (2025-11-15)
- ✅ **Enhanced**: 70+ fields per product line (was 30)
- ✅ **Enhanced**: 40+ fields per coupon/cart rule (was 6)
- ✅ **NEW**: Order history tracking
- ✅ **NEW**: Payment records
- ✅ **NEW**: Customer messages
- ✅ **NEW**: Order-coupon relationships
- ✅ **Fixed**: Batch webhook handling
- ✅ **Fixed**: Method naming (sendBatchSalesEvents)

### v1.0.0 (2025-11-09)
- ✅ Initial release
- ✅ Customer, address, order, invoice, payment, coupon events
- ✅ Admin interface
- ✅ Webhook batch delivery
- ✅ Retry logic
- ✅ Comprehensive logging

---

## 🤝 Support

For issues, questions, or feature requests:

1. Check [Documentation](docs/)
2. Review [Common Issues](#-troubleshooting)
3. Check database logs: `SELECT * FROM ps_odoo_sales_logs`
4. Enable debug mode for detailed logging

---

## 📄 License

Proprietary - All Rights Reserved

This module is proprietary software developed for specific PrestaShop to Odoo integration projects.

---

## 👥 Authors

- **Development Team** - Azor Data SL
- **Contact**: info@azordata.com

---

## 🙏 Acknowledgments

- PrestaShop community for hooks documentation
- Odoo integration patterns and best practices
- Reference module: `odoo_direct_stock_sync`

---

**Made with ❤️ for PrestaShop → Odoo migrations**
