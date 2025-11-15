<?php
/**
 * Odoo Sales Webhook Client
 *
 * Sends batch of sales events to Odoo webhook endpoint.
 * Loads configuration from database on-demand.
 *
 * Adapted from odoo_direct_stock_sync OdooApiClient.php
 * - Simplified: Removed circuit breaker, request queue, priority levels
 * - Kept: Batch sending, configuration loading, isConfigured check
 * - Changed: Stock events → Sales events
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesWebhookClient
{
    private $webhookUrl;
    private $webhookSecret;
    private $timeout;
    private $logger;

    /**
     * Constructor - loads logger only
     * Configuration loaded on-demand to ensure fresh values
     */
    public function __construct()
    {
        require_once(dirname(__FILE__) . '/OdooSalesLogger.php');
        $this->logger = new OdooSalesLogger();
    }

    /**
     * Load configuration from database
     * Called before each request to ensure fresh configuration
     */
    private function loadConfiguration()
    {
        $this->webhookUrl = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_URL');
        $this->webhookSecret = Configuration::get('ODOO_SALES_SYNC_WEBHOOK_SECRET');
        $this->timeout = (int)Configuration::get('ODOO_SALES_SYNC_TIMEOUT', 30);
    }

    /**
     * Send batch of sales events to Odoo webhook
     *
     * @param array $events Array of OdooSalesEvent objects
     * @return array Batch processing results
     */
    public function sendBatchSalesEvents($events)
    {
        // Load fresh configuration before sending
        $this->loadConfiguration();

        if (empty($events)) {
            return [
                'success' => true,
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0
                ]
            ];
        }

        // Validate webhook URL is configured
        if (empty($this->webhookUrl)) {
            $this->logger->error('[WEBHOOK_CLIENT] Webhook URL not configured', [
                'event_count' => count($events)
            ]);

            return [
                'success' => false,
                'error' => 'Webhook URL not configured',
                'results' => [],
                'summary' => [
                    'total' => count($events),
                    'successful' => 0,
                    'failed' => count($events)
                ]
            ];
        }

        $startTime = microtime(true);
        $successful = 0;
        $failed = 0;
        $results = [];

        // Generate batch ID
        $batchId = $this->generateBatchId($events);

        $this->logger->info('[WEBHOOK_CLIENT] Sending batch to Odoo', [
            'batch_id' => $batchId,
            'event_count' => count($events),
            'webhook_url' => $this->webhookUrl
        ]);

        // Prepare batch request data
        $batchData = [
            'batch_id' => $batchId,
            'timestamp' => date('Y-m-d H:i:s'),
            'events' => []
        ];

        foreach ($events as $event) {
            $batchData['events'][] = $this->prepareEventData($event);
        }

        try {
            // Send batch request
            $response = $this->sendBatchRequest($batchData);

            // Process results
            if (isset($response['data']['results']) && is_array($response['data']['results'])) {
                foreach ($response['data']['results'] as $index => $result) {
                    if (isset($events[$index])) {
                        $event = $events[$index];

                        if ($result['success']) {
                            $event->sync_status = 'success';
                            $event->sync_error = null;
                            $event->sync_next_retry = null;
                            $successful++;
                        } else {
                            $event->sync_status = 'failed';
                            $event->sync_error = $result['error'] ?? 'Unknown error';

                            // Schedule retry
                            $retryDelay = $this->calculateRetryDelay($event->sync_attempts);
                            $event->sync_next_retry = date('Y-m-d H:i:s', time() + $retryDelay);
                            $failed++;
                        }

                        $event->sync_attempts++;
                        $event->sync_last_attempt = date('Y-m-d H:i:s');
                        $event->webhook_response_code = $response['http_code'];
                        $event->save();

                        $results[] = $result;
                    }
                }
            } else {
                // No individual results - mark all as successful if response was successful
                if ($response['http_code'] >= 200 && $response['http_code'] < 300) {
                    foreach ($events as $event) {
                        $event->sync_status = 'success';
                        $event->sync_error = null;
                        $event->sync_next_retry = null;
                        $event->sync_attempts++;
                        $event->sync_last_attempt = date('Y-m-d H:i:s');
                        $event->webhook_response_code = $response['http_code'];
                        $event->save();
                        $successful++;

                        $results[] = ['success' => true];
                    }
                } else {
                    // All failed
                    foreach ($events as $event) {
                        $event->sync_status = 'failed';
                        $event->sync_error = 'Batch request failed: HTTP ' . $response['http_code'];
                        $event->sync_attempts++;
                        $event->sync_last_attempt = date('Y-m-d H:i:s');
                        $event->webhook_response_code = $response['http_code'];

                        $retryDelay = $this->calculateRetryDelay($event->sync_attempts);
                        $event->sync_next_retry = date('Y-m-d H:i:s', time() + $retryDelay);
                        $event->save();
                        $failed++;

                        $results[] = ['success' => false, 'error' => 'HTTP ' . $response['http_code']];
                    }
                }
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info('[WEBHOOK_CLIENT] Batch processing completed', [
                'batch_id' => $batchId,
                'total' => count($events),
                'successful' => $successful,
                'failed' => $failed,
                'execution_time_ms' => $executionTime
            ]);

            return [
                'success' => ($failed === 0),
                'results' => $results,
                'summary' => [
                    'total' => count($events),
                    'successful' => $successful,
                    'failed' => $failed
                ],
                'batch_id' => $batchId,
                'response_time' => $executionTime
            ];

        } catch (Exception $e) {
            $this->logger->error('[WEBHOOK_CLIENT] Batch send exception', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark all events as failed
            foreach ($events as $event) {
                $event->sync_status = 'failed';
                $event->sync_error = 'Exception: ' . $e->getMessage();
                $event->sync_attempts++;
                $event->sync_last_attempt = date('Y-m-d H:i:s');

                $retryDelay = $this->calculateRetryDelay($event->sync_attempts);
                $event->sync_next_retry = date('Y-m-d H:i:s', time() + $retryDelay);
                $event->save();
                $failed++;
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => [],
                'summary' => [
                    'total' => count($events),
                    'successful' => 0,
                    'failed' => $failed
                ],
                'batch_id' => $batchId
            ];
        }
    }

    /**
     * Send batch HTTP request to Odoo webhook
     *
     * @param array $batchData Batch data with events
     * @return array Response data
     */
    private function sendBatchRequest($batchData)
    {
        $jsonPayload = json_encode($batchData);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
            'X-Webhook-Secret: ' . $this->webhookSecret,
            'X-Batch-ID: ' . $batchData['batch_id'],
            'User-Agent: PrestaShop-Odoo-Sales-Sync/1.0'
        ];

        $this->logger->debug('[WEBHOOK_CLIENT] Sending HTTP request', [
            'url' => $this->webhookUrl,
            'method' => 'POST',
            'payload_size' => strlen($jsonPayload),
            'event_count' => count($batchData['events'])
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // TODO: Set to true in production
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);     // TODO: Set to 2 in production

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $executionTime = microtime(true) - $startTime;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('[WEBHOOK_CLIENT] cURL error', [
                'error' => $curlError,
                'errno' => $curlErrno,
                'url' => $this->webhookUrl
            ]);

            throw new Exception('cURL error: ' . $curlError);
        }

        $decodedResponse = json_decode($response, true);

        $this->logger->debug('[WEBHOOK_CLIENT] HTTP response received', [
            'http_code' => $httpCode,
            'response_size' => strlen($response),
            'execution_time_seconds' => round($executionTime, 3)
        ]);

        if ($httpCode >= 400) {
            $errorMsg = isset($decodedResponse['error']) ? $decodedResponse['error'] : 'HTTP ' . $httpCode;
            $this->logger->error('[WEBHOOK_CLIENT] HTTP error response', [
                'http_code' => $httpCode,
                'error' => $errorMsg,
                'response' => substr($response, 0, 500)
            ]);

            throw new Exception('HTTP ' . $httpCode . ': ' . $errorMsg);
        }

        return [
            'http_code' => $httpCode,
            'data' => $decodedResponse,
            'execution_time' => $executionTime
        ];
    }

    /**
     * Prepare event data for API transmission
     *
     * @param OdooSalesEvent $event
     * @return array Formatted event data
     */
    private function prepareEventData($event)
    {
        return [
            'event_id' => $event->id,
            'entity_type' => $event->entity_type,
            'entity_id' => (string)$event->entity_id,
            'entity_name' => $event->entity_name,
            'action_type' => $event->action_type,
            'transaction_hash' => $event->transaction_hash,
            'correlation_id' => $event->correlation_id,
            'hook_name' => $event->hook_name,
            'hook_timestamp' => $event->hook_timestamp,
            'before_data' => $event->before_data ? json_decode($event->before_data, true) : null,
            'after_data' => $event->after_data ? json_decode($event->after_data, true) : null,
            'change_summary' => $event->change_summary,
            'context_data' => $event->context_data ? json_decode($event->context_data, true) : null
        ];
    }

    /**
     * Generate stable batch ID based on event IDs
     *
     * @param array $events Array of OdooSalesEvent objects
     * @return string Batch ID
     */
    private function generateBatchId($events)
    {
        $eventIds = [];
        foreach ($events as $event) {
            if (isset($event->id)) {
                $eventIds[] = (int)$event->id;
            }
        }

        if (empty($eventIds)) {
            return 'batch_' . date('YmdHis') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        }

        sort($eventIds);
        $eventHash = substr(md5(implode(',', $eventIds)), 0, 8);

        return 'batch_' . date('YmdHis') . '_' . $eventHash;
    }

    /**
     * Calculate retry delay in seconds based on attempt count
     *
     * @param int $attempts Number of previous attempts
     * @return int Delay in seconds
     */
    private function calculateRetryDelay($attempts)
    {
        // Exponential backoff: 10s, 60s, 300s, 900s, 3600s, 86400s
        $delays = [10, 60, 300, 900, 3600, 86400];
        $index = min($attempts, count($delays) - 1);
        return $delays[$index];
    }

    /**
     * Test webhook connection
     *
     * @return array Test result
     */
    public function testConnection()
    {
        $this->loadConfiguration();

        try {
            $testPayload = [
                'test' => true,
                'message' => 'Test connection from PrestaShop Odoo Sales Sync',
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $jsonPayload = json_encode($testPayload);

            $headers = [
                'Content-Type: application/json',
                'X-Webhook-Secret: ' . $this->webhookSecret,
                'User-Agent: PrestaShop-Odoo-Sales-Sync/1.0'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->webhookUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return [
                    'success' => false,
                    'message' => 'Connection test failed: ' . $curlError,
                    'response_code' => 0
                ];
            }

            $success = ($httpCode >= 200 && $httpCode < 300);

            return [
                'success' => $success,
                'message' => $success
                    ? 'Connection successful (HTTP ' . $httpCode . ')'
                    : 'Connection failed: HTTP ' . $httpCode,
                'response_code' => $httpCode
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'response_code' => 0
            ];
        }
    }

    /**
     * Check if webhook client is configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        $this->loadConfiguration();
        return !empty($this->webhookUrl) && !empty($this->webhookSecret);
    }
}
