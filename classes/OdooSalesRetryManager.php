<?php
/**
 * Automatic Retry Manager for Sales Events
 *
 * Handles automatic retry logic for failed sales synchronization events.
 * Implements an escalating retry schedule with exponential backoff.
 *
 * Retry Schedule:
 * - 1st failure: retry_count = 1, retry immediately (10 seconds)
 * - 2nd failure: retry_count = 2, wait 1 minute
 * - 3rd failure: retry_count = 3, wait 5 minutes
 * - 4th failure: retry_count = 4, wait 15 minutes
 * - 5th failure: retry_count = 5, wait 1 hour
 * - 6th+ failure: retry_count = 6+, wait 24 hours
 *
 * Adapted from odoo_direct_stock_sync RetryManager.php
 * - Changed: stock events → sales events
 * - Simplified: Removed some advanced features (circuit breaker, permanent failures)
 * - Kept: Core retry logic, exponential backoff, zombie recovery
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesEvent.php';
require_once dirname(__FILE__) . '/OdooSalesWebhookClient.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesRetryManager
{
    // Escalating wait times in seconds
    const WAIT_TIME_1ST_FAILURE = 10;    // 10 seconds (immediate retry)
    const WAIT_TIME_2ND_FAILURE = 60;    // 1 minute
    const WAIT_TIME_3RD_FAILURE = 300;   // 5 minutes
    const WAIT_TIME_4TH_FAILURE = 900;   // 15 minutes
    const WAIT_TIME_5TH_FAILURE = 3600;  // 1 hour
    const WAIT_TIME_6TH_PLUS = 86400;    // 24 hours

    // Maximum retry attempts before giving up
    const MAX_RETRY_ATTEMPTS = 10;

    private $logger;
    private $webhookClient;

    public function __construct()
    {
        $this->logger = new OdooSalesLogger();
        $this->webhookClient = new OdooSalesWebhookClient();
    }

    /**
     * Main retry execution method
     * Triggers automatic retry of pending failed events
     *
     * @return array Results summary
     */
    public function executeRetry()
    {
        $batchStartTime = microtime(true);

        $this->logger->info('[RETRY] Starting automatic retry execution');

        // Get all pending retry events
        $pendingEvents = $this->getPendingRetryEvents();

        if (empty($pendingEvents)) {
            $this->logger->info('[RETRY] No pending events to retry');
            return [
                'success' => true,
                'message' => 'No events to retry',
                'retried' => 0,
                'succeeded' => 0,
                'failed' => 0
            ];
        }

        $this->logger->info('[RETRY] Found ' . count($pendingEvents) . ' events to retry', [
            'event_ids' => array_column($pendingEvents, 'id_event')
        ]);

        // Retry events and track results
        $succeeded = 0;
        $failed = 0;
        $eventResults = [];

        foreach ($pendingEvents as $eventData) {
            try {
                $event = new OdooSalesEvent($eventData['id_event']);

                if (!Validate::isLoadedObject($event)) {
                    $this->logger->warning('[RETRY] Event not loaded', [
                        'event_id' => $eventData['id_event']
                    ]);
                    continue;
                }

                // Attempt retry
                $result = $this->retryEvent($event);

                $eventResults[] = [
                    'event_id' => $event->id,
                    'entity' => $event->entity_type . ':' . $event->entity_id,
                    'success' => $result['success']
                ];

                if ($result['success']) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                $this->logger->error('[RETRY] Exception in retry loop', [
                    'event_id' => $eventData['id_event'],
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }

        $batchExecutionTime = microtime(true) - $batchStartTime;

        $this->logger->info('[RETRY] Automatic retry batch completed', [
            'total_attempted' => $succeeded + $failed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'batch_execution_time_seconds' => round($batchExecutionTime, 3),
            'event_results' => $eventResults
        ]);

        return [
            'success' => true,
            'message' => sprintf('Retried %d events: %d succeeded, %d failed',
                                $succeeded + $failed, $succeeded, $failed),
            'retried' => $succeeded + $failed,
            'succeeded' => $succeeded,
            'failed' => $failed
        ];
    }

    /**
     * Retry a single event
     *
     * @param OdooSalesEvent $event
     * @return array Result
     */
    private function retryEvent($event)
    {
        $startTime = microtime(true);

        $this->logger->debug('[RETRY] Retrying event', [
            'event_id' => $event->id,
            'sync_attempts' => $event->sync_attempts,
            'entity' => $event->entity_type . ':' . $event->entity_id,
            'current_attempt' => $event->sync_attempts + 1
        ]);

        // Update last retry attempt timestamp
        $event->sync_last_attempt = date('Y-m-d H:i:s');
        $event->save();

        // Attempt to send to Odoo using batch method (matches reference pattern)
        try {
            // Send single event as batch of 1
            $result = $this->webhookClient->sendBatchSalesEvents([$event]);

            $executionTime = microtime(true) - $startTime;

            // Check if event was successful
            $eventSuccess = false;
            if ($result['success'] && isset($result['summary']['successful']) && $result['summary']['successful'] > 0) {
                $eventSuccess = true;
            }

            if ($eventSuccess) {
                // Success - event status already updated by client
                $this->logger->info('[RETRY] Event retry succeeded', [
                    'event_id' => $event->id,
                    'execution_time_seconds' => round($executionTime, 3)
                ]);

                return ['success' => true];
            } else {
                // Failed - event status already updated by client
                $error = $result['error'] ?? 'Unknown error';

                $this->logger->warning('[RETRY] Event retry failed', [
                    'event_id' => $event->id,
                    'error' => $error,
                    'execution_time_seconds' => round($executionTime, 3)
                ]);

                // Reload event to get updated status from client
                $event = new OdooSalesEvent($event->id);

                // Check max retries
                if ($event->sync_attempts >= self::MAX_RETRY_ATTEMPTS) {
                    $event->sync_status = 'failed';
                    $event->sync_error = $error . ' (Max retries exceeded)';
                    $event->sync_next_retry = null;
                    $event->save();

                    $this->logger->warning('[RETRY] Max retry count exceeded', [
                        'event_id' => $event->id,
                        'sync_attempts' => $event->sync_attempts,
                        'max_allowed' => self::MAX_RETRY_ATTEMPTS
                    ]);

                    return [
                        'success' => false,
                        'max_retries_exceeded' => true,
                        'error' => $error
                    ];
                }

                return [
                    'success' => false,
                    'error' => $error
                ];
            }
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $this->logger->error('[RETRY] Event retry exception', [
                'event_id' => $event->id,
                'exception' => $e->getMessage(),
                'execution_time_seconds' => round($executionTime, 3)
            ]);

            // Exception - escalate retry schedule
            $this->escalateRetrySchedule($event, $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Escalate retry schedule based on failure count
     *
     * @param OdooSalesEvent $event
     * @param string $error Error message
     */
    private function escalateRetrySchedule($event, $error)
    {
        $event->sync_attempts++;
        $event->sync_error = $error;

        // Determine new wait time based on retry count
        switch ($event->sync_attempts) {
            case 1:
                $waitSeconds = self::WAIT_TIME_1ST_FAILURE;
                break;
            case 2:
                $waitSeconds = self::WAIT_TIME_2ND_FAILURE;
                break;
            case 3:
                $waitSeconds = self::WAIT_TIME_3RD_FAILURE;
                break;
            case 4:
                $waitSeconds = self::WAIT_TIME_4TH_FAILURE;
                break;
            case 5:
                $waitSeconds = self::WAIT_TIME_5TH_FAILURE;
                break;
            default:
                $waitSeconds = self::WAIT_TIME_6TH_PLUS;
                break;
        }

        $event->sync_status = 'failed';
        $event->sync_next_retry = date('Y-m-d H:i:s', time() + $waitSeconds);
        $event->save();

        $this->logger->warning('[RETRY] Event retry failed - escalated', [
            'event_id' => $event->id,
            'sync_attempts' => $event->sync_attempts,
            'sync_status' => $event->sync_status,
            'sync_next_retry' => $event->sync_next_retry,
            'wait_seconds' => $waitSeconds,
            'error' => substr($error, 0, 200)
        ]);
    }

    /**
     * Get all events with sync_status = 'failed' and sync_next_retry <= NOW()
     *
     * @return array Event data
     */
    private function getPendingRetryEvents()
    {
        $sql = "SELECT * FROM " . _DB_PREFIX_ . "odoo_sales_events
                WHERE sync_status = 'failed'
                AND (sync_next_retry IS NULL OR sync_next_retry <= NOW())
                ORDER BY sync_attempts ASC, date_add ASC
                LIMIT 100"; // Process max 100 events per run

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Recover zombie events (stuck in 'sending' status)
     * These are events that were marked as sending but never completed
     * (usually due to server crash, timeout, or other fatal error)
     *
     * @param int $timeoutMinutes Events stuck for longer than this are considered zombies
     * @return int Number of recovered events
     */
    public function recoverZombieEvents($timeoutMinutes = 10)
    {
        $this->logger->info('[RETRY] Starting zombie recovery', [
            'timeout_minutes' => $timeoutMinutes
        ]);

        // Find events stuck in 'sending' status
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

        $sql = "UPDATE " . _DB_PREFIX_ . "odoo_sales_events
                SET sync_status = 'failed',
                    sync_error = 'Recovered from stuck status (zombie)',
                    sync_next_retry = NOW()
                WHERE sync_status = 'sending'
                AND sync_last_attempt < '" . pSQL($cutoffTime) . "'";

        Db::getInstance()->execute($sql);
        $recoveredCount = Db::getInstance()->Affected_Rows();

        if ($recoveredCount > 0) {
            $this->logger->info('[RETRY] Zombie events recovered', [
                'recovered_count' => $recoveredCount,
                'timeout_minutes' => $timeoutMinutes
            ]);
        }

        return $recoveredCount;
    }

    /**
     * Mark event as failed for the first time
     * Called when an event fails initial sync
     *
     * @param OdooSalesEvent $event
     * @param string $error Error message
     */
    public static function markEventAsFailed($event, $error)
    {
        $event->sync_status = 'failed';
        $event->sync_attempts = ($event->sync_attempts ?? 0) + 1;
        $event->sync_next_retry = date('Y-m-d H:i:s', time() + self::WAIT_TIME_1ST_FAILURE);
        $event->sync_error = $error;
        $event->save();
    }
}
