# Debugging NGROK "Unexpected End of JSON Input" Issue

## Problem
Ngrok shows:
```
3668 bytes application/json; charset=utf-8 (0 bytes captured)
unexpected end of JSON input
```

This means the Content-Length header says 3668 bytes, but ngrok captured 0 bytes, suggesting the request body is not being sent or received properly.

## Steps to Debug

### 1. Run the Test Script

```bash
cd /mnt/c/Trabajo/GOSHAWK/Clientes/02.Odoo/16. El último Koala/Módulos Integración Prestashop/odoo_sales_sync
php test_webhook_payload.php
```

This will show you exactly what JSON is being generated and validate it.

### 2. Enable Debug Mode in PrestaShop

Go to the module configuration and enable debug mode to see full payloads in logs:
- Set `ODOO_SALES_SYNC_DEBUG` to `1`

### 3. Check the Logs

After enabling debug mode, check `ps_odoo_sales_logs` table for entries with:
- `[WEBHOOK_CLIENT] Full JSON payload` - Shows complete payload
- `[WEBHOOK_CLIENT] Sending HTTP request` - Shows headers and size
- `[WEBHOOK_CLIENT] cURL request completed` - Shows size_upload

Look for:
```sql
SELECT * FROM ps_odoo_sales_sync_logs
WHERE message LIKE '%WEBHOOK_CLIENT%'
ORDER BY date_add DESC
LIMIT 20;
```

### 4. Manual cURL Test

Create a test JSON file and send it manually:

```bash
# Create test payload
cat > /tmp/test_payload.json <<'EOF'
{
  "batch_id": "manual_test_123",
  "timestamp": "2025-01-16 12:00:00",
  "events": [
    {
      "event_id": 1,
      "entity_type": "customer",
      "entity_id": 100,
      "entity_name": "Test Customer",
      "action_type": "created",
      "transaction_hash": "abc123",
      "correlation_id": "corr-123",
      "hook_name": "actionCustomerAccountAdd",
      "hook_timestamp": "2025-01-16 12:00:00",
      "before_data": null,
      "after_data": {
        "id": 100,
        "firstname": "John",
        "lastname": "Doe",
        "email": "john@example.com"
      },
      "change_summary": "Customer created",
      "context_data": {
        "source": "prestashop"
      }
    }
  ]
}
EOF

# Send to ngrok
curl -v \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "X-Webhook-Secret: test_secret" \
  -H "X-Batch-ID: manual_test_123" \
  -H "User-Agent: PrestaShop-Odoo-Sales-Sync/2.0" \
  -d @/tmp/test_payload.json \
  https://your-ngrok-url.ngrok.io/webhook
```

If this works, the issue is with how PrestaShop is generating/sending the JSON.
If this fails, the issue is with ngrok or the receiving endpoint.

### 5. Check for Special Characters

The JSON might contain characters that break when sent via cURL. Look for:
- Null bytes (\\x00)
- Invalid UTF-8 sequences
- Unescaped quotes in strings

### 6. Verify Content-Length

The enhanced logging now shows:
- `payload_size` (strlen)
- `payload_bytes` (mb_strlen)
- `size_upload` (actual bytes sent by cURL)

All three should match. If they don't, there's an encoding issue.

## Possible Causes

### A. Database Encoding Issue
If `before_data`, `after_data`, or `context_data` in the database contain invalid UTF-8:
- Check: `SELECT HEX(after_data) FROM ps_odoo_sales_events WHERE id_event = X;`
- Look for sequences that aren't valid UTF-8

### B. PHP String Encoding
- PrestaShop might be using ISO-8859-1 instead of UTF-8
- Check: `mb_detect_encoding($jsonPayload)`

### C. cURL Transfer Issue
- cURL might not be sending the body
- Check: `size_upload` in logs should equal payload size

### D. ngrok Body Limit
- ngrok might have a body capture limit
- Try smaller payloads (single event instead of batch)

### E. Webhook URL Issue
- If the URL has redirects, POST body might be lost
- Check: Final URL should not redirect

## Quick Diagnostic Commands

```sql
-- Find events with potentially problematic data
SELECT id_event, entity_type,
       LENGTH(before_data) as before_len,
       LENGTH(after_data) as after_len,
       LENGTH(context_data) as context_len,
       CHAR_LENGTH(before_data) as before_chars,
       CHAR_LENGTH(after_data) as after_chars
FROM ps_odoo_sales_events
WHERE sync_status = 'failed'
LIMIT 10;

-- Check for non-UTF8 characters
SELECT id_event, entity_type,
       CONVERT(after_data USING utf8) = after_data as is_utf8_after,
       CONVERT(before_data USING utf8) = before_data as is_utf8_before
FROM ps_odoo_sales_events
WHERE sync_status = 'failed'
LIMIT 10;
```

## Expected Log Output

With debug mode enabled, you should see:

```
[WEBHOOK_CLIENT] Full JSON payload
  payload: {"batch_id":"batch_20250116_abc123",...}

[WEBHOOK_CLIENT] Sending HTTP request
  payload_size: 3668
  payload_bytes: 3668
  event_count: 5

[WEBHOOK_CLIENT] cURL request completed
  size_upload: 3668
  size_download: 234
```

If `size_upload` is 0 or doesn't match `payload_size`, that's your problem.

## Solution Paths

### If size_upload = 0:
- cURL is not sending the body
- Check CURLOPT_POSTFIELDS is set correctly
- Try CURLOPT_CUSTOMREQUEST instead of CURLOPT_POST

### If payload contains invalid JSON:
- Add JSON validation in prepareEventData()
- Strip invalid UTF-8 sequences
- Use mb_convert_encoding()

### If ngrok shows 0 bytes captured:
- ngrok might not be intercepting POST body
- Try a different ngrok plan or setup
- Use webhook.site for comparison

## Test with webhook.site

1. Go to https://webhook.site
2. Copy your unique URL
3. Update ODOO_SALES_SYNC_WEBHOOK_URL to webhook.site URL
4. Trigger an event
5. Check webhook.site - you'll see the EXACT request received

This will definitively show whether PrestaShop is sending the data correctly.
