<?php
/**
 * Customer Processor - Reverse Sync
 *
 * Handles customer/contact creation and updates from Odoo webhooks.
 *
 * Supported operations:
 * - Create new customer
 * - Update existing customer (by ID or email lookup)
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesCustomerProcessor
{
    /**
     * Process customer webhook from Odoo
     *
     * @param array $payload Full webhook payload
     * @param string $operationId Operation ID for tracking
     * @return array Result array
     */
    public static function process($payload, $operationId)
    {
        $logger = new OdooSalesLogger('reverse_sync');
        $data = $payload['data'] ?? [];
        $actionType = $payload['action_type'] ?? 'updated';

        $logger->info('[CUSTOMER_PROCESSOR] Processing customer webhook', [
            'operation_id' => $operationId,
            'action_type' => $actionType,
            'customer_id' => $data['id'] ?? null,
            'email' => $data['email'] ?? null
        ]);

        // Track operation in database
        try {
            $operation = OdooSalesReverseOperation::trackOperation(
                $operationId,
                'customer',
                $data['id'] ?? null,
                $actionType,
                $payload
            );
        } catch (Exception $e) {
            $logger->error('[CUSTOMER_PROCESSOR] Failed to track operation', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);
            // Continue processing even if tracking fails
        }

        try {
            // Route based on action type
            if ($actionType === 'created') {
                $result = self::createCustomer($data, $logger);
            } else {
                $result = self::updateCustomer($data, $logger);
            }

            // Send notification to debug server
            self::notifyDebugServer($payload, $result, $operationId);

            // Update operation status
            if (isset($operation)) {
                $operation->updateStatus(
                    $result['success'] ? 'success' : 'failed',
                    $result,
                    $result['error'] ?? null,
                    OdooSalesReverseSyncContext::getProcessingTimeMs()
                );
            }

            return $result;

        } catch (Exception $e) {
            $logger->error('[CUSTOMER_PROCESSOR] Exception during processing', [
                'operation_id' => $operationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $result = [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage()
            ];

            // Update operation as failed
            if (isset($operation)) {
                $operation->updateStatus(
                    'failed',
                    null,
                    $e->getMessage(),
                    OdooSalesReverseSyncContext::getProcessingTimeMs()
                );
            }

            return $result;
        }
    }

    /**
     * Create new customer from Odoo data
     *
     * @param array $data Customer data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function createCustomer($data, $logger)
    {
        // Validate required fields
        $validation = self::validateCustomerData($data, true);
        if (!$validation['valid']) {
            $logger->warning('[CUSTOMER_PROCESSOR] Invalid customer data for creation', [
                'error' => $validation['error'],
                'data' => $data
            ]);
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        // Check if customer already exists by email
        $existingId = Customer::customerExists($data['email'], true);
        if ($existingId) {
            $logger->info('[CUSTOMER_PROCESSOR] Customer already exists, updating instead', [
                'email' => $data['email'],
                'existing_id' => $existingId
            ]);
            // Update instead of creating
            $data['id'] = $existingId;
            return self::updateCustomer($data, $logger);
        }

        // Create new customer
        $customer = new Customer();

        // Map required fields
        $customer->email = $data['email'];
        $customer->firstname = $data['firstname'] ?? 'Unknown';
        $customer->lastname = $data['lastname'] ?? 'Customer';

        // Generate secure random password
        $customer->passwd = Tools::hash(Tools::passwdGen(16));

        // Map optional fields
        $customer->active = isset($data['active']) ? (bool)$data['active'] : true;
        $customer->newsletter = isset($data['newsletter']) ? (bool)$data['newsletter'] : false;
        $customer->optin = isset($data['optin']) ? (bool)$data['optin'] : false;

        if (isset($data['company'])) {
            $customer->company = pSQL($data['company']);
        }
        if (isset($data['siret'])) {
            $customer->siret = pSQL($data['siret']);
        }
        if (isset($data['website'])) {
            $customer->website = pSQL($data['website']);
        }
        if (isset($data['birthday'])) {
            $customer->birthday = pSQL($data['birthday']);
        }
        if (isset($data['id_gender'])) {
            $customer->id_gender = (int)$data['id_gender'];
        }

        // Attempt to add customer
        if (!$customer->add()) {
            $logger->error('[CUSTOMER_PROCESSOR] Failed to create customer', [
                'email' => $data['email'],
                'validation_errors' => $customer->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to create customer: ' . implode(', ', $customer->getErrors())
            ];
        }

        $logger->info('[CUSTOMER_PROCESSOR] Customer created successfully', [
            'customer_id' => $customer->id,
            'email' => $customer->email
        ]);

        return [
            'success' => true,
            'entity_id' => $customer->id,
            'message' => 'Customer created successfully',
            'email' => $customer->email
        ];
    }

    /**
     * Update existing customer from Odoo data
     *
     * @param array $data Customer data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function updateCustomer($data, $logger)
    {
        // Find customer by ID or email
        $customerId = null;

        if (isset($data['id']) && $data['id']) {
            $customerId = (int)$data['id'];
        } elseif (isset($data['email'])) {
            $customerId = Customer::customerExists($data['email'], true);
        }

        if (!$customerId) {
            $logger->warning('[CUSTOMER_PROCESSOR] Customer not found', [
                'id' => $data['id'] ?? null,
                'email' => $data['email'] ?? null
            ]);
            return [
                'success' => false,
                'error' => 'Customer not found. Provide valid id or email.'
            ];
        }

        // Load customer
        $customer = new Customer($customerId);

        if (!Validate::isLoadedObject($customer)) {
            $logger->error('[CUSTOMER_PROCESSOR] Failed to load customer', [
                'customer_id' => $customerId
            ]);
            return [
                'success' => false,
                'error' => 'Failed to load customer with ID: ' . $customerId
            ];
        }

        // Update fields (only if provided in payload)
        if (isset($data['firstname'])) {
            $customer->firstname = pSQL($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $customer->lastname = pSQL($data['lastname']);
        }
        if (isset($data['email'])) {
            // Check if new email already exists for another customer
            $existingId = Customer::customerExists($data['email'], true);
            if ($existingId && $existingId != $customer->id) {
                return [
                    'success' => false,
                    'error' => 'Email already exists for another customer'
                ];
            }
            $customer->email = pSQL($data['email']);
        }
        if (isset($data['active'])) {
            $customer->active = (bool)$data['active'];
        }
        if (isset($data['newsletter'])) {
            $customer->newsletter = (bool)$data['newsletter'];
        }
        if (isset($data['optin'])) {
            $customer->optin = (bool)$data['optin'];
        }
        if (isset($data['company'])) {
            $customer->company = pSQL($data['company']);
        }
        if (isset($data['siret'])) {
            $customer->siret = pSQL($data['siret']);
        }
        if (isset($data['website'])) {
            $customer->website = pSQL($data['website']);
        }
        if (isset($data['birthday'])) {
            $customer->birthday = pSQL($data['birthday']);
        }
        if (isset($data['id_gender'])) {
            $customer->id_gender = (int)$data['id_gender'];
        }

        // Update customer
        if (!$customer->update()) {
            $logger->error('[CUSTOMER_PROCESSOR] Failed to update customer', [
                'customer_id' => $customer->id,
                'validation_errors' => $customer->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to update customer: ' . implode(', ', $customer->getErrors())
            ];
        }

        $logger->info('[CUSTOMER_PROCESSOR] Customer updated successfully', [
            'customer_id' => $customer->id,
            'email' => $customer->email
        ]);

        return [
            'success' => true,
            'entity_id' => $customer->id,
            'message' => 'Customer updated successfully',
            'email' => $customer->email
        ];
    }

    /**
     * Validate customer data
     *
     * @param array $data Customer data
     * @param bool $requireAll Require all fields for creation
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private static function validateCustomerData($data, $requireAll = false)
    {
        if ($requireAll) {
            // For creation, email is required
            if (empty($data['email'])) {
                return [
                    'valid' => false,
                    'error' => 'Email is required for customer creation'
                ];
            }

            // Validate email format
            if (!Validate::isEmail($data['email'])) {
                return [
                    'valid' => false,
                    'error' => 'Invalid email format: ' . $data['email']
                ];
            }
        }

        // Validate email if provided
        if (isset($data['email']) && !Validate::isEmail($data['email'])) {
            return [
                'valid' => false,
                'error' => 'Invalid email format'
            ];
        }

        // Validate names if provided
        if (isset($data['firstname']) && !Validate::isName($data['firstname'])) {
            return [
                'valid' => false,
                'error' => 'Invalid firstname'
            ];
        }
        if (isset($data['lastname']) && !Validate::isName($data['lastname'])) {
            return [
                'valid' => false,
                'error' => 'Invalid lastname'
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Send notification to debug webhook server
     *
     * @param array $payload Original payload
     * @param array $result Processing result
     * @param string $operationId Operation ID
     * @return void
     */
    private static function notifyDebugServer($payload, $result, $operationId)
    {
        $debugWebhookUrl = Configuration::get('ODOO_SALES_SYNC_DEBUG_WEBHOOK_URL');

        if (empty($debugWebhookUrl)) {
            return; // Debug webhook not configured
        }

        $notification = [
            'event_id' => $operationId,
            'entity_type' => 'customer',
            'entity_id' => $result['entity_id'] ?? null,
            'action_type' => $payload['action_type'] ?? 'updated',
            'hook_name' => 'reverseWebhookReceived',
            'timestamp' => date('c'),
            'reverse_sync' => true, // CRITICAL FLAG for debug server
            'source' => 'odoo',
            'destination' => 'prestashop',
            'result' => $result,
            'change_summary' => self::buildChangeSummary($payload, $result)
        ];

        // Send async notification (don't block on failure)
        $ch = curl_init($debugWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Short timeout - don't block
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

        @curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Build change summary for logging
     *
     * @param array $payload Original payload
     * @param array $result Processing result
     * @return string Summary
     */
    private static function buildChangeSummary($payload, $result)
    {
        $actionType = $payload['action_type'] ?? 'updated';
        $data = $payload['data'] ?? [];

        if (!$result['success']) {
            return ucfirst($actionType) . ' customer failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $email = $data['email'] ?? ($result['email'] ?? 'unknown');
        return ucfirst($actionType) . ' customer: ' . $email;
    }
}
