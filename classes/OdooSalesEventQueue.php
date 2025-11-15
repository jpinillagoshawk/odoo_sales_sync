<?php
/**
 * Event Queue for Deferred Webhook Calls
 *
 * Collects all sales events during a request and sends them in a single batch
 * at the end of the request execution via background webhook processing.
 *
 * This prevents blocking the user interface during event synchronization.
 *
 * Adapted from odoo_direct_stock_sync EventQueue.php
 * - Changed: stock events → sales events
 * - Changed: StockEvent → SalesEvent
 * - Changed: ApiClient → WebhookClient
 * - Kept: Same async webhook pattern, retry logic, consolidation
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesEventQueue
{
    /**
     * Queue of events waiting to be sent
     * @var array
     */
    private static $events = [];

    /**
     * Whether shutdown function has been registered
     * @var bool
     */
    private static $shutdownRegistered = false;

    /**
     * Logger instance
     * @var OdooSalesLogger
     */
    private static $logger = null;

    /**
     * Webhook client instance
     * @var OdooSalesWebhookClient
     */
    private static $webhookClient = null;

    /**
     * Module instance
     * @var Odoo_sales_sync
     */
    private static $module = null;

    /**
     * Request start time for correlation
     * @var float
     */
    private static $requestStartTime = null;

    /**
     * Queue an event for later sending
     *
     * CRITICAL FIX: Schedule events IMMEDIATELY instead of waiting for shutdown
     * Why: PrestaShop can take 7+ seconds before reaching shutdown handler
     * Result: Event scheduled instantly, user doesn't wait
     *
     * @param OdooSalesEvent $event Event to queue
     * @return void
     */
    public static function queueEvent($event)
    {
        // Initialize request tracking
        if (self::$requestStartTime === null) {
            self::$requestStartTime = microtime(true);
        }

        self::getLogger()->info('[EVENT_QUEUE] Event queued', [
            'queue_size' => count(self::$events) + 1,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id,
            'action_type' => $event->action_type
        ]);

        // CRITICAL: ALWAYS register shutdown handler
        if (!self::$shutdownRegistered) {
            register_shutdown_function([__CLASS__, 'sendQueuedEvents']);
            self::$shutdownRegistered = true;
        }

        // Add event to queue for shutdown processing
        // The shutdown handler will send immediately (user already disconnected)
        // Event already saved to DB with status='pending'
        self::$events[] = $event;

        self::getLogger()->info('[EVENT_QUEUE] Event queued for shutdown send', [
            'event_id' => $event->id,
            'queue_size' => count(self::$events)
        ]);
    }

    /**
     * Send all queued events in a single batch
     * Called automatically at request shutdown
     *
     * Webhook-based async processing:
     * - Calls internal webhook with event IDs
     * - Webhook returns 200 OK instantly, processes in background
     * - Solves cold-start problem: Even if Odoo takes 10s to wake up, user doesn't wait
     *
     * @return void
     */
    public static function sendQueuedEvents()
    {
        self::getLogger()->debug('[EVENT_QUEUE] sendQueuedEvents called', [
            'queue_size' => count(self::$events)
        ]);

        // If no events in queue, we're done
        if (empty(self::$events)) {
            self::getLogger()->debug('[EVENT_QUEUE] No events in queue, returning');
            return;
        }

        $startTime = microtime(true);
        $eventCount = count(self::$events);

        self::getLogger()->info('[EVENT_QUEUE] Processing queued events in shutdown', [
            'total_events' => $eventCount,
            'request_duration_ms' => round((microtime(true) - self::$requestStartTime) * 1000, 2)
        ]);

        // Call internal webhook (fire-and-forget)
        // Webhook returns 200 OK instantly, processes in background
        // Even if Odoo takes 10s to wake up, user doesn't wait
        self::triggerWebhookProcessing($eventCount, $startTime);
    }

    /**
     * Trigger webhook processing
     * Calls internal webhook with fire-and-forget HTTP request
     *
     * Strategy:
     * 1. Extract event IDs from queue
     * 2. Try multiple webhook URLs (fallback chain)
     * 3. Webhook returns 200 OK instantly
     * 4. Webhook processes in background after response sent
     * 5. If webhook fails, schedule for retry (NON-BLOCKING)
     *
     * @param int $eventCount Original event count
     * @param float $startTime Processing start time
     * @return void
     */
    private static function triggerWebhookProcessing($eventCount, $startTime)
    {
        // Extract event IDs from queue
        $eventIds = array_map(function($event) {
            return $event->id;
        }, self::$events);

        $eventIdsJson = json_encode($eventIds);

        // Try multiple webhook URLs (fallback chain)
        $webhookUrls = self::getWebhookUrls();

        self::getLogger()->debug('[EVENT_QUEUE] Attempting webhook trigger', [
            'event_count' => $eventCount,
            'event_ids' => $eventIds,
            'webhook_urls' => $webhookUrls
        ]);

        $success = false;
        $lastError = null;

        // Try each URL until one succeeds
        foreach ($webhookUrls as $webhookUrl) {
            $result = self::attemptWebhookCall($webhookUrl, $eventIdsJson);

            if ($result['success']) {
                self::getLogger()->info('[EVENT_QUEUE] Webhook triggered successfully', [
                    'webhook_url' => $webhookUrl,
                    'event_count' => $eventCount,
                    'response' => $result['response']
                ]);

                // Clear queue - webhook will handle processing
                self::$events = [];
                $success = true;
                break;
            } else {
                $lastError = $result['error'];
                self::getLogger()->debug('[EVENT_QUEUE] Webhook attempt failed', [
                    'webhook_url' => $webhookUrl,
                    'error' => $lastError
                ]);
            }
        }

        if (!$success) {
            // DON'T fallback to direct processing - schedule for retry instead
            self::getLogger()->warning('[EVENT_QUEUE] All webhook attempts failed, scheduling events for retry', [
                'event_count' => $eventCount,
                'last_error' => $lastError
            ]);

            // Schedule events for retry (non-blocking)
            self::scheduleEventsForRetry(self::$events, 10); // Retry in 10 seconds

            // Clear queue
            self::$events = [];
        }
    }

    /**
     * Get list of webhook URLs to try (in order of preference)
     *
     * @return array List of webhook URLs
     */
    private static function getWebhookUrls()
    {
        $webhookPath = __PS_BASE_URI__ . 'modules/odoo_sales_sync/webhook.php';

        $urls = [];

        // 1. Try actual server name/IP first (most reliable)
        if (!empty($_SERVER['SERVER_NAME'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $urls[] = $protocol . '://' . $_SERVER['SERVER_NAME'] . $webhookPath;
        }

        // 2. Try SERVER_ADDR if available
        if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
            $urls[] = 'http://' . $_SERVER['SERVER_ADDR'] . $webhookPath;
        }

        // 3. Try localhost (may work in some environments)
        $urls[] = 'http://localhost' . $webhookPath;

        // 4. Last resort: 127.0.0.1 (known to fail in WSL)
        $urls[] = 'http://127.0.0.1' . $webhookPath;

        // Remove duplicates while preserving order
        $urls = array_values(array_unique($urls));

        return $urls;
    }

    /**
     * Attempt webhook call with improved timeout handling
     *
     * @param string $webhookUrl URL to call
     * @param string $eventIdsJson Event IDs as JSON
     * @return array ['success' => bool, 'response' => string, 'error' => string]
     */
    private static function attemptWebhookCall($webhookUrl, $eventIdsJson)
    {
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $eventIdsJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500,       // 500ms timeout
            CURLOPT_CONNECTTIMEOUT_MS => 250, // 250ms connect timeout
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($eventIdsJson)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'success' => ($httpCode === 200),
            'response' => $response,
            'error' => $curlError,
            'http_code' => $httpCode
        ];
    }

    /**
     * Schedule events for future retry (non-blocking)
     *
     * @param array $events Events to schedule
     * @param int $delaySeconds Delay in seconds
     */
    private static function scheduleEventsForRetry($events, $delaySeconds)
    {
        $scheduledTime = date('Y-m-d H:i:s', time() + $delaySeconds);
        $scheduledCount = 0;

        foreach ($events as $event) {
            // Event is already saved to database (from OdooSalesEventDetector)
            // Update its retry status
            if (!empty($event->id)) {
                $event->sync_status = 'failed';
                $event->sync_attempts = 1;
                $event->sync_next_retry = $scheduledTime;
                $event->sync_error = 'Webhook unavailable, scheduled for retry';

                // Use save() instead of update() to ensure proper persistence
                if ($event->save()) {
                    $scheduledCount++;
                }
            }
        }

        self::getLogger()->info('[EVENT_QUEUE] Events scheduled for retry', [
            'scheduled_count' => $scheduledCount,
            'total_count' => count($events),
            'scheduled_time' => $scheduledTime,
            'delay_seconds' => $delaySeconds
        ]);
    }

    /**
     * Consolidate events by entity, action, and time window
     * Merges duplicate or related events from the same user action
     *
     * Made public so other processors can reuse this logic
     *
     * @param array $events Array of OdooSalesEvent objects
     * @return array Consolidated events
     */
    public static function consolidateEvents($events)
    {
        if (count($events) <= 1) {
            return $events;
        }

        // Get consolidation window from configuration (default 5 seconds)
        $consolidationWindow = (float)Configuration::get('ODOO_SALES_SYNC_CONSOLIDATION_WINDOW', 5);

        self::getLogger()->debug('[EVENT_QUEUE] Starting consolidation', [
            'event_count' => count($events),
            'window_seconds' => $consolidationWindow
        ]);

        // Group events by entity_type + entity_id + action_type
        $groups = [];
        foreach ($events as $event) {
            $key = $event->entity_type . '_' . $event->entity_id . '_' . $event->action_type;

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }

            $groups[$key][] = $event;
        }

        // Consolidate each group
        $consolidated = [];
        foreach ($groups as $key => $groupEvents) {
            if (count($groupEvents) === 1) {
                // Single event, no consolidation needed
                $consolidated[] = $groupEvents[0];
                continue;
            }

            // Sort by timestamp
            usort($groupEvents, function($a, $b) {
                $timeA = strtotime($a->date_add);
                $timeB = strtotime($b->date_add);
                return $timeA - $timeB;
            });

            // Check if events are within consolidation window
            $firstTime = strtotime($groupEvents[0]->date_add);
            $lastTime = strtotime($groupEvents[count($groupEvents) - 1]->date_add);
            $timeDiff = $lastTime - $firstTime;

            if ($timeDiff <= $consolidationWindow) {
                // Consolidate into single event (keep first, discard duplicates)
                $consolidated[] = $groupEvents[0];

                self::getLogger()->debug('[EVENT_QUEUE] Merged events', [
                    'event_key' => $key,
                    'event_count' => count($groupEvents),
                    'time_span_seconds' => $timeDiff,
                    'kept_event_id' => $groupEvents[0]->id
                ]);
            } else {
                // Time window exceeded, keep as separate events
                $consolidated = array_merge($consolidated, $groupEvents);

                self::getLogger()->debug('[EVENT_QUEUE] Time window exceeded, keeping separate', [
                    'event_key' => $key,
                    'event_count' => count($groupEvents),
                    'time_span_seconds' => $timeDiff,
                    'window_seconds' => $consolidationWindow
                ]);
            }
        }

        return $consolidated;
    }

    /**
     * Get logger instance (lazy initialization)
     *
     * @return OdooSalesLogger
     */
    private static function getLogger()
    {
        if (self::$logger === null) {
            require_once(dirname(__FILE__) . '/OdooSalesLogger.php');
            self::$logger = new OdooSalesLogger();
        }
        return self::$logger;
    }

    /**
     * Get webhook client instance (lazy initialization)
     *
     * @return OdooSalesWebhookClient
     */
    private static function getWebhookClient()
    {
        if (self::$webhookClient === null) {
            require_once(dirname(__FILE__) . '/OdooSalesWebhookClient.php');
            self::$webhookClient = new OdooSalesWebhookClient();
        }
        return self::$webhookClient;
    }

    /**
     * Get current queue size (for testing/debugging)
     *
     * @return int
     */
    public static function getQueueSize()
    {
        return count(self::$events);
    }

    /**
     * Clear queue (for testing)
     *
     * @return void
     */
    public static function clearQueue()
    {
        self::$events = [];
        self::$shutdownRegistered = false;
    }
}
