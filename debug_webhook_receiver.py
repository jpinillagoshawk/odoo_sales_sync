#!/usr/bin/env python3
"""
Debug Webhook Receiver for Odoo Sales Sync Module

Simple HTTP server that receives and displays webhook payloads
for testing and debugging the PrestaShop Odoo Sales Sync module.

Usage:
    python3 debug_webhook_receiver.py [--port PORT] [--secret SECRET]

Examples:
    python3 debug_webhook_receiver.py
    python3 debug_webhook_receiver.py --port 8000
    python3 debug_webhook_receiver.py --port 5000 --secret my_secret_key

Author: Odoo Sales Sync Module
Version: 1.0.0
"""

import json
import argparse
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

# ANSI color codes for terminal output
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

class WebhookHandler(BaseHTTPRequestHandler):
    """HTTP request handler for webhook receiver"""

    webhook_secret = None
    request_count = 0

    def do_GET(self):
        """Handle GET requests (health check)"""
        if self.path == '/health':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            response = {
                'status': 'ok',
                'message': 'Webhook receiver is running',
                'requests_received': WebhookHandler.request_count
            }
            self.wfile.write(json.dumps(response).encode())
        else:
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            html = f"""
            <html>
            <head><title>Odoo Sales Sync - Webhook Receiver</title></head>
            <body>
                <h1>Odoo Sales Sync - Debug Webhook Receiver</h1>
                <p>Status: <strong style="color: green;">Running</strong></p>
                <p>Webhooks received: <strong>{WebhookHandler.request_count}</strong></p>
                <p>POST webhooks to: <code>/webhook</code></p>
                <p>Health check: <code>/health</code></p>
            </body>
            </html>
            """
            self.wfile.write(html.encode())

    def do_POST(self):
        """Handle POST requests (webhook payloads)"""
        WebhookHandler.request_count += 1

        # Parse URL
        parsed_path = urlparse(self.path)

        # Read request body
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length)

        # Get headers
        secret_header = self.headers.get('X-Webhook-Secret', '')
        content_type = self.headers.get('Content-Type', '')

        # Validate secret if configured
        if WebhookHandler.webhook_secret and secret_header != WebhookHandler.webhook_secret:
            print(f"\n{Colors.FAIL}❌ WEBHOOK REJECTED - Invalid Secret{Colors.ENDC}")
            print(f"   Expected: {WebhookHandler.webhook_secret}")
            print(f"   Received: {secret_header}")

            self.send_response(403)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            error = {'error': 'Invalid webhook secret'}
            self.wfile.write(json.dumps(error).encode())
            return

        # Parse JSON payload
        try:
            payload = json.loads(body.decode('utf-8'))
        except json.JSONDecodeError as e:
            print(f"\n{Colors.FAIL}❌ INVALID JSON{Colors.ENDC}")
            print(f"   Error: {e}")
            print(f"   Body: {body.decode('utf-8')[:200]}")

            self.send_response(400)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            error = {'error': 'Invalid JSON'}
            self.wfile.write(json.dumps(error).encode())
            return

        # Display webhook
        self.display_webhook(payload)

        # Send success response
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        response = {
            'status': 'success',
            'message': 'Webhook received',
            'event_id': payload.get('event_id')
        }
        self.wfile.write(json.dumps(response).encode())

    def display_webhook(self, payload):
        """Display webhook payload in formatted output"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

        print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}")
        print(f"{Colors.BOLD}{Colors.HEADER}🔔 WEBHOOK RECEIVED #{WebhookHandler.request_count}{Colors.ENDC}")
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
            print(f"   {json.dumps(payload['data'], indent=3)}")

        # Display context
        if 'context' in payload and payload['context']:
            print(f"\n{Colors.BOLD}🔍 Context:{Colors.ENDC}")
            print(f"   {json.dumps(payload['context'], indent=3)}")

        # Display change summary
        if 'change_summary' in payload:
            print(f"\n{Colors.BOLD}📝 Summary:{Colors.ENDC} {payload['change_summary']}")

        # Display full payload
        print(f"\n{Colors.BOLD}📄 Full Payload:{Colors.ENDC}")
        print(json.dumps(payload, indent=2))

        print(f"\n{Colors.BOLD}{Colors.OKGREEN}{'='*80}{Colors.ENDC}\n")

    def log_message(self, format, *args):
        """Override to suppress default request logging"""
        # Only log errors
        if args[1] != '200':
            super().log_message(format, *args)

def run_server(port=5000, secret=None):
    """Run webhook receiver server"""

    WebhookHandler.webhook_secret = secret

    server_address = ('', port)
    httpd = HTTPServer(server_address, WebhookHandler)

    print(f"\n{Colors.BOLD}{Colors.HEADER}{'='*80}{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.HEADER}   Odoo Sales Sync - Debug Webhook Receiver{Colors.ENDC}")
    print(f"{Colors.BOLD}{Colors.HEADER}{'='*80}{Colors.ENDC}\n")
    print(f"{Colors.OKGREEN}✓{Colors.ENDC} Server running on port {Colors.BOLD}{port}{Colors.ENDC}")
    print(f"{Colors.OKGREEN}✓{Colors.ENDC} Webhook endpoint: {Colors.BOLD}http://localhost:{port}/webhook{Colors.ENDC}")
    print(f"{Colors.OKGREEN}✓{Colors.ENDC} Health check: {Colors.BOLD}http://localhost:{port}/health{Colors.ENDC}")

    if secret:
        print(f"{Colors.WARNING}⚠{Colors.ENDC}  Secret validation: {Colors.BOLD}ENABLED{Colors.ENDC} (secret: {secret})")
    else:
        print(f"{Colors.OKCYAN}ℹ{Colors.ENDC}  Secret validation: {Colors.BOLD}DISABLED{Colors.ENDC}")

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
        print(f"{Colors.OKGREEN}✓ Total webhooks received: {WebhookHandler.request_count}{Colors.ENDC}\n")

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

The server will display all received webhooks in a formatted, colorized output.
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

    args = parser.parse_args()

    run_server(port=args.port, secret=args.secret)

if __name__ == '__main__':
    main()
