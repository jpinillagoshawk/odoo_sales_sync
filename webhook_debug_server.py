#!/usr/bin/env python3
"""
Odoo Sales Sync - Local Debug Webhook Server
Accessible from both WSL and Windows, with file logging and real-time display

Usage:
    python webhook_debug_server.py [--port PORT] [--secret SECRET] [--log-file PATH]

Features:
    - Real-time colored console output
    - Detailed file logging with rotation
    - Accessible from Windows host (localhost) and WSL
    - Event counter and statistics
    - JSON pretty-printing
    - Webhook secret validation
    - Health check endpoint
    - CORS enabled for testing

Author: Odoo Sales Sync Module
Version: 1.0.0
"""

import json
import argparse
import logging
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse
from logging.handlers import RotatingFileHandler
import os
import sys

# ANSI color codes
class Colors:
    HEADER = '\033[95m'
    OKBLUE = '\033[94m'
    OKCYAN = '\033[96m'
    OKGREEN = '\033[92m'
    WARNING = '\033[93m'
    FAIL = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'
    UNDERLINE = '\033[4m'

class WebhookStats:
    """Track webhook statistics"""
    def __init__(self):
        self.total_requests = 0
        self.successful = 0
        self.failed = 0
        self.by_entity_type = {}
        self.by_action_type = {}
        self.start_time = datetime.now()

    def record_success(self, entity_type, action_type):
        self.total_requests += 1
        self.successful += 1
        self.by_entity_type[entity_type] = self.by_entity_type.get(entity_type, 0) + 1
        self.by_action_type[action_type] = self.by_action_type.get(action_type, 0) + 1

    def record_failure(self, reason):
        self.total_requests += 1
        self.failed += 1

    def get_summary(self):
        uptime = datetime.now() - self.start_time
        return {
            'uptime_seconds': uptime.total_seconds(),
            'total_requests': self.total_requests,
            'successful': self.successful,
            'failed': self.failed,
            'by_entity_type': self.by_entity_type,
            'by_action_type': self.by_action_type
        }

class WebhookHandler(BaseHTTPRequestHandler):
    """HTTP request handler for webhook receiver"""

    webhook_secret = None
    stats = WebhookStats()
    file_logger = None
    log_file_path = None

    def do_OPTIONS(self):
        """Handle CORS preflight requests"""
        self.send_response(200)
        self.send_cors_headers()
        self.end_headers()

    def do_GET(self):
        """Handle GET requests (health check and stats)"""
        parsed = urlparse(self.path)

        if parsed.path == '/health':
            self.handle_health_check()
        elif parsed.path == '/stats':
            self.handle_stats()
        else:
            self.handle_info_page()

    def do_POST(self):
        """Handle POST requests (webhook payloads)"""
        if self.path == '/webhook':
            self.handle_webhook()
        else:
            self.send_error(404, f"Endpoint not found: {self.path}")

    def handle_health_check(self):
        """Health check endpoint"""
        self.send_response(200)
        self.send_cors_headers()
        self.send_header('Content-Type', 'application/json')
        self.end_headers()

        response = {
            'status': 'ok',
            'message': 'Webhook receiver is running',
            'stats': self.stats.get_summary()
        }
        self.wfile.write(json.dumps(response, indent=2).encode())

    def handle_stats(self):
        """Statistics endpoint"""
        self.send_response(200)
        self.send_cors_headers()
        self.send_header('Content-Type', 'application/json')
        self.end_headers()

        stats = self.stats.get_summary()
        self.wfile.write(json.dumps(stats, indent=2).encode())

    def handle_info_page(self):
        """Info page with usage instructions"""
        self.send_response(200)
        self.send_cors_headers()
        self.send_header('Content-Type', 'text/html')
        self.end_headers()

        stats = self.stats.get_summary()
        uptime_minutes = int(stats['uptime_seconds'] / 60)

        html = f"""
        <!DOCTYPE html>
        <html>
        <head>
            <title>Odoo Sales Sync - Debug Webhook Server</title>
            <meta charset="utf-8">
            <meta http-equiv="refresh" content="5">
            <style>
                body {{ font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }}
                .container {{ max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }}
                h1 {{ color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }}
                .status {{ background: #4CAF50; color: white; padding: 10px; border-radius: 4px; display: inline-block; }}
                .stats {{ background: #f9f9f9; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }}
                .endpoint {{ background: #e8f5e9; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace; }}
                code {{ background: #eee; padding: 2px 6px; border-radius: 3px; }}
                table {{ width: 100%; border-collapse: collapse; margin: 15px 0; }}
                th, td {{ padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }}
                th {{ background: #f5f5f5; font-weight: bold; }}
                .metric {{ font-size: 24px; font-weight: bold; color: #2196F3; }}
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🔔 Odoo Sales Sync - Debug Webhook Server</h1>

                <p class="status">✓ Server Running</p>
                <p>Uptime: {uptime_minutes} minutes</p>

                <div class="stats">
                    <h3>📊 Statistics</h3>
                    <table>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                        </tr>
                        <tr>
                            <td>Total Requests</td>
                            <td class="metric">{stats['total_requests']}</td>
                        </tr>
                        <tr>
                            <td>Successful</td>
                            <td style="color: green;">{stats['successful']}</td>
                        </tr>
                        <tr>
                            <td>Failed</td>
                            <td style="color: red;">{stats['failed']}</td>
                        </tr>
                    </table>

                    <h4>By Entity Type:</h4>
                    <ul>
                        {self._format_dict_as_list(stats['by_entity_type'])}
                    </ul>

                    <h4>By Action Type:</h4>
                    <ul>
                        {self._format_dict_as_list(stats['by_action_type'])}
                    </ul>
                </div>

                <h3>📡 Endpoints</h3>
                <div class="endpoint">
                    <strong>POST</strong> /webhook - Receive webhooks from PrestaShop
                </div>
                <div class="endpoint">
                    <strong>GET</strong> /health - Health check
                </div>
                <div class="endpoint">
                    <strong>GET</strong> /stats - Statistics (JSON)
                </div>

                <h3>🔧 Configuration</h3>
                <p><strong>Webhook URL for PrestaShop:</strong></p>
                <code>http://localhost:{self.server.server_port}/webhook</code>

                {f'<p><strong>Webhook Secret:</strong> <code>{self.webhook_secret}</code></p>' if self.webhook_secret else ''}

                {f'<p><strong>Log File:</strong> <code>{self.log_file_path}</code></p>' if self.log_file_path else ''}

                <p style="margin-top: 30px; color: #666; font-size: 12px;">
                    Auto-refreshes every 5 seconds • Check console for real-time webhook output
                </p>
            </div>
        </body>
        </html>
        """
        self.wfile.write(html.encode())

    def _format_dict_as_list(self, d):
        """Format dictionary as HTML list"""
        if not d:
            return '<li><em>No data yet</em></li>'
        return ''.join([f'<li>{k}: {v}</li>' for k, v in d.items()])

    def handle_webhook(self):
        """Handle webhook POST request"""
        # Read request body
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length)

        # Get headers
        secret_header = self.headers.get('X-Webhook-Secret', '')
        content_type = self.headers.get('Content-Type', '')

        # Validate secret if configured
        if self.webhook_secret and secret_header != self.webhook_secret:
            self.log_to_file('ERROR', 'Invalid webhook secret', {
                'expected': self.webhook_secret,
                'received': secret_header
            })
            self.print_error("❌ WEBHOOK REJECTED - Invalid Secret")
            self.stats.record_failure('invalid_secret')

            self.send_response(403)
            self.send_cors_headers()
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            error = {'error': 'Invalid webhook secret'}
            self.wfile.write(json.dumps(error).encode())
            return

        # Parse JSON payload
        try:
            payload = json.loads(body.decode('utf-8'))
        except json.JSONDecodeError as e:
            self.log_to_file('ERROR', 'Invalid JSON', {'error': str(e), 'body': body.decode('utf-8')[:200]})
            self.print_error(f"❌ INVALID JSON: {e}")
            self.stats.record_failure('invalid_json')

            self.send_response(400)
            self.send_cors_headers()
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            error = {'error': 'Invalid JSON'}
            self.wfile.write(json.dumps(error).encode())
            return

        # Check if this is a BATCH payload or single event
        is_batch = 'batch_id' in payload and 'events' in payload

        if is_batch:
            # Handle batch payload
            batch_id = payload.get('batch_id', 'unknown')
            events = payload.get('events', [])

            print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}")
            print(f"{Colors.BOLD}{Colors.HEADER}🔔 BATCH WEBHOOK #{self.stats.total_requests + 1}{Colors.ENDC}")
            print(f"{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}")
            print(f"{Colors.OKCYAN}Batch ID:{Colors.ENDC} {batch_id}")
            print(f"{Colors.OKCYAN}Event Count:{Colors.ENDC} {len(events)}")
            print(f"{Colors.OKCYAN}Timestamp:{Colors.ENDC} {payload.get('timestamp', 'N/A')}")

            # Display each event in the batch
            for idx, event in enumerate(events, 1):
                entity_type = event.get('entity_type', 'unknown')
                action_type = event.get('action_type', 'unknown')

                # Update stats
                self.stats.record_success(entity_type, action_type)

                # Display event
                print(f"\n{Colors.BOLD}📋 Event {idx}/{len(events)}:{Colors.ENDC}")
                self.display_event_summary(event)

            print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}\n")

            # Log to file
            self.log_to_file('INFO', 'Batch webhook received', payload)

            # Send success response for batch
            self.send_response(200)
            self.send_cors_headers()
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            response = {
                'status': 'success',
                'message': f'Batch received with {len(events)} events',
                'batch_id': batch_id,
                'events_processed': len(events),
                'received_at': datetime.now().isoformat(),
                'results': [{'success': True} for _ in events]
            }
            self.wfile.write(json.dumps(response).encode())
        else:
            # Handle single event payload (old format)
            entity_type = payload.get('entity_type', 'unknown')
            action_type = payload.get('action_type', 'unknown')

            # Display webhook
            self.display_webhook(payload)

            # Log to file
            self.log_to_file('INFO', 'Webhook received', payload)

            # Update stats
            self.stats.record_success(entity_type, action_type)

            # Send success response
            self.send_response(200)
            self.send_cors_headers()
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            response = {
                'status': 'success',
                'message': 'Webhook received',
                'event_id': payload.get('event_id'),
                'received_at': datetime.now().isoformat()
            }
            self.wfile.write(json.dumps(response).encode())

    def display_event_summary(self, event):
        """Display compact event summary (for batch events)"""
        event_id = event.get('event_id', 'N/A')
        entity_type = event.get('entity_type', 'unknown')
        entity_id = event.get('entity_id', 'N/A')
        entity_name = event.get('entity_name', 'N/A')
        action_type = event.get('action_type', 'unknown')
        hook_name = event.get('hook_name', 'N/A')

        print(f"   Event ID:     {Colors.WARNING}{event_id}{Colors.ENDC}")
        print(f"   Entity Type:  {Colors.OKBLUE}{entity_type}{Colors.ENDC}")
        print(f"   Entity ID:    {entity_id}")
        print(f"   Entity Name:  {entity_name}")
        print(f"   Action:       {Colors.OKGREEN}{action_type}{Colors.ENDC}")
        print(f"   Hook:         {hook_name}")

        # Show key data fields for orders
        if 'after_data' in event and event['after_data']:
            data = event['after_data']
            if entity_type == 'order':
                # Show order details count
                order_details_count = len(data.get('order_details', []))
                order_history_count = len(data.get('order_history', []))
                order_payments_count = len(data.get('order_payments', []))
                messages_count = len(data.get('messages', []))

                print(f"   {Colors.OKCYAN}Order Details:{Colors.ENDC} {order_details_count} products, " +
                      f"{order_history_count} history, {order_payments_count} payments, {messages_count} messages")

                # Show first product details as sample
                if order_details_count > 0:
                    first_product = data['order_details'][0]
                    product_name = first_product.get('product_name', 'N/A')
                    product_qty = first_product.get('product_quantity', 'N/A')
                    product_price = first_product.get('total_price_tax_excl', 'N/A')
                    print(f"   {Colors.OKCYAN}Sample Product:{Colors.ENDC} {product_name} (Qty: {product_qty}, Price: {product_price})")

        # Show change summary
        if 'change_summary' in event:
            print(f"   {Colors.BOLD}Summary:{Colors.ENDC} {event['change_summary']}")

    def send_cors_headers(self):
        """Send CORS headers for cross-origin requests"""
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, X-Webhook-Secret')

    def display_webhook(self, payload):
        """Display webhook payload in formatted output"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

        print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}")
        print(f"{Colors.BOLD}{Colors.HEADER}🔔 WEBHOOK #{self.stats.total_requests + 1}{Colors.ENDC}")
        print(f"{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}")
        print(f"{Colors.OKCYAN}Timestamp:{Colors.ENDC} {timestamp}")

        # Extract key fields
        entity_type = payload.get('entity_type', 'unknown')
        entity_id = payload.get('entity_id', 'N/A')
        entity_name = payload.get('entity_name', 'N/A')
        action_type = payload.get('action_type', 'unknown')
        hook_name = payload.get('hook_name', 'N/A')
        event_id = payload.get('event_id', 'N/A')

        # Display summary
        print(f"\n{Colors.BOLD}📋 Event Summary:{Colors.ENDC}")
        print(f"   Event ID:     {Colors.WARNING}{event_id}{Colors.ENDC}")
        print(f"   Entity Type:  {Colors.OKBLUE}{entity_type}{Colors.ENDC}")
        print(f"   Entity ID:    {entity_id}")
        print(f"   Entity Name:  {entity_name}")
        print(f"   Action:       {Colors.OKGREEN}{action_type}{Colors.ENDC}")
        print(f"   Hook:         {hook_name}")

        # Display data payload
        if 'data' in payload and payload['data']:
            print(f"\n{Colors.BOLD}📦 Data Payload:{Colors.ENDC}")
            print(self.indent_json(payload['data'], 3))

        # Display context
        if 'context' in payload and payload['context']:
            print(f"\n{Colors.BOLD}🔍 Context:{Colors.ENDC}")
            print(self.indent_json(payload['context'], 3))

        # Display change summary
        if 'change_summary' in payload:
            print(f"\n{Colors.BOLD}📝 Summary:{Colors.ENDC} {payload['change_summary']}")

        print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}\n")

    def print_error(self, message):
        """Print error message in red"""
        print(f"\n{Colors.FAIL}{message}{Colors.ENDC}\n")

    def indent_json(self, data, spaces=2):
        """Format JSON with indentation"""
        json_str = json.dumps(data, indent=2)
        indent = ' ' * spaces
        return '\n'.join([indent + line for line in json_str.split('\n')])

    def log_to_file(self, level, message, data=None):
        """Log to file if file logger is configured (unbuffered)"""
        if self.file_logger:
            log_entry = {
                'timestamp': datetime.now().isoformat(),
                'level': level,
                'message': message,
                'data': data
            }
            self.file_logger.info(json.dumps(log_entry, indent=2))
            # Force flush for immediate write
            for handler in self.file_logger.handlers:
                handler.flush()

    def log_message(self, format, *args):
        """Override to suppress default request logging"""
        # Only log errors
        if args[1] != '200':
            super().log_message(format, *args)

def setup_file_logging(log_file_path):
    """Setup rotating file logger with unbuffered output"""
    logger = logging.getLogger('webhook_logger')
    logger.setLevel(logging.INFO)

    # Create directory if it doesn't exist
    log_dir = os.path.dirname(log_file_path)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir)

    # Rotating file handler (max 10MB, keep 5 backups)
    handler = RotatingFileHandler(
        log_file_path,
        maxBytes=10*1024*1024,  # 10MB
        backupCount=5
    )
    handler.setLevel(logging.INFO)

    # Set formatter for better readability
    formatter = logging.Formatter('%(message)s')
    handler.setFormatter(formatter)

    # Force flush after each write (unbuffered)
    handler.stream.reconfigure(line_buffering=True) if hasattr(handler.stream, 'reconfigure') else None

    logger.addHandler(handler)
    return logger

def get_local_ip():
    """Get local IP address for display"""
    import socket
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except:
        return "localhost"

def run_server(port=5000, secret=None, log_file=None):
    """Run webhook receiver server"""

    WebhookHandler.webhook_secret = secret

    # Setup file logging if specified
    if log_file:
        WebhookHandler.file_logger = setup_file_logging(log_file)
        WebhookHandler.log_file_path = os.path.abspath(log_file)
        print(f"{Colors.OKGREEN}✓{Colors.ENDC} Logging to file: {Colors.BOLD}{WebhookHandler.log_file_path}{Colors.ENDC}")

    server_address = ('0.0.0.0', port)  # Listen on all interfaces
    httpd = HTTPServer(server_address, WebhookHandler)

    local_ip = get_local_ip()

    print(f"\n{Colors.BOLD}{Colors.HEADER}{'='*80}{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.HEADER}   Odoo Sales Sync - Debug Webhook Server{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.HEADER}{'='*80}{Colors.ENDC}\n")

    print(f"{Colors.OKGREEN}✓{Colors.ENDC} Server running on port {Colors.BOLD}{port}{Colors.ENDC}")
    print(f"\n{Colors.BOLD}Access from:{Colors.ENDC}")
    print(f"  • Windows (localhost): {Colors.BOLD}http://localhost:{port}{Colors.ENDC}")
    print(f"  • WSL: {Colors.BOLD}http://localhost:{port}{Colors.ENDC}")
    print(f"  • Local network: {Colors.BOLD}http://{local_ip}:{port}{Colors.ENDC}")

    print(f"\n{Colors.BOLD}Endpoints:{Colors.ENDC}")
    print(f"  • Webhook: {Colors.BOLD}http://localhost:{port}/webhook{Colors.ENDC}")
    print(f"  • Health check: {Colors.BOLD}http://localhost:{port}/health{Colors.ENDC}")
    print(f"  • Statistics: {Colors.BOLD}http://localhost:{port}/stats{Colors.ENDC}")
    print(f"  • Info page: {Colors.BOLD}http://localhost:{port}/{Colors.ENDC}")

    if secret:
        print(f"\n{Colors.WARNING}⚠{Colors.ENDC}  Secret validation: {Colors.BOLD}ENABLED{Colors.ENDC}")
        print(f"   Secret: {Colors.BOLD}{secret}{Colors.ENDC}")
    else:
        print(f"\n{Colors.OKCYAN}ℹ{Colors.ENDC}  Secret validation: {Colors.BOLD}DISABLED{Colors.ENDC}")

    print(f"\n{Colors.BOLD}Configure PrestaShop module with:{Colors.ENDC}")
    print(f"   Webhook URL: http://localhost:{port}/webhook")
    if secret:
        print(f"   Webhook Secret: {secret}")

    print(f"\n{Colors.BOLD}Press Ctrl+C to stop{Colors.ENDC}\n")
    print(f"{Colors.BOLD}{Colors.HEADER}{'='*80}{Colors.ENDC}\n")

    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print(f"\n\n{Colors.WARNING}Shutting down server...{Colors.ENDC}")
        httpd.shutdown()
        print(f"{Colors.OKGREEN}✓ Server stopped{Colors.ENDC}")
        stats = WebhookHandler.stats.get_summary()
        print(f"{Colors.OKGREEN}✓ Total webhooks received: {stats['total_requests']}{Colors.ENDC}")
        print(f"{Colors.OKGREEN}✓ Successful: {stats['successful']}{Colors.ENDC}")
        print(f"{Colors.FAIL}✗ Failed: {stats['failed']}{Colors.ENDC}\n")

def main():
    """Main entry point"""
    parser = argparse.ArgumentParser(
        description='Debug webhook receiver for Odoo Sales Sync module',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s
  %(prog)s --port 8000
  %(prog)s --port 5000 --secret my_secret_key
  %(prog)s --port 5000 --log-file webhooks.log

The server will:
  - Display all received webhooks in colored, formatted output
  - Log all webhooks to file (if --log-file specified)
  - Provide statistics via /stats endpoint
  - Be accessible from both Windows and WSL
        """
    )

    parser.add_argument(
        '--port',
        type=int,
        default=5000,
        help='Port to listen on (default: 5000)'
    )

    parser.add_argument(
        '--secret',
        type=str,
        default=None,
        help='Webhook secret for validation (optional)'
    )

    parser.add_argument(
        '--log-file',
        type=str,
        default=None,
        help='Log file path for webhook data (optional, with rotation)'
    )

    args = parser.parse_args()

    run_server(port=args.port, secret=args.secret, log_file=args.log_file)

if __name__ == '__main__':
    main()
