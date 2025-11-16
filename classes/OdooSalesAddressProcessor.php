<?php
/**
 * Address Processor - Reverse Sync
 *
 * Handles address creation and updates from Odoo webhooks.
 *
 * Supported operations:
 * - Create new address (linked to customer)
 * - Update existing address
 *
 * @author Odoo Sales Sync Module
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/OdooSalesReverseOperation.php';
require_once dirname(__FILE__) . '/OdooSalesLogger.php';

class OdooSalesAddressProcessor
{
    /**
     * Process address webhook from Odoo
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

        $logger->info('[ADDRESS_PROCESSOR] Processing address webhook', [
            'operation_id' => $operationId,
            'action_type' => $actionType,
            'address_id' => $data['id'] ?? null,
            'customer_id' => $data['id_customer'] ?? null
        ]);

        // Track operation
        try {
            $operation = OdooSalesReverseOperation::trackOperation(
                $operationId,
                'address',
                $data['id'] ?? null,
                $actionType,
                $payload
            );
        } catch (Exception $e) {
            $logger->error('[ADDRESS_PROCESSOR] Failed to track operation', [
                'error' => $e->getMessage()
            ]);
        }

        try {
            // Route based on action type
            if ($actionType === 'created') {
                $result = self::createAddress($data, $logger);
            } else {
                $result = self::updateAddress($data, $logger);
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
            $logger->error('[ADDRESS_PROCESSOR] Exception during processing', [
                'operation_id' => $operationId,
                'error' => $e->getMessage()
            ]);

            $result = [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage()
            ];

            if (isset($operation)) {
                $operation->updateStatus('failed', null, $e->getMessage(), OdooSalesReverseSyncContext::getProcessingTimeMs());
            }

            return $result;
        }
    }

    /**
     * Create new address from Odoo data
     *
     * @param array $data Address data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function createAddress($data, $logger)
    {
        // Validate required fields
        $validation = self::validateAddressData($data, true);
        if (!$validation['valid']) {
            $logger->warning('[ADDRESS_PROCESSOR] Invalid address data', [
                'error' => $validation['error']
            ]);
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        // Verify customer exists
        $customer = new Customer($data['id_customer']);
        if (!Validate::isLoadedObject($customer)) {
            $logger->error('[ADDRESS_PROCESSOR] Customer not found', [
                'id_customer' => $data['id_customer']
            ]);
            return [
                'success' => false,
                'error' => 'Customer not found: ' . $data['id_customer']
            ];
        }

        // Create new address
        $address = new Address();

        // Link to customer
        $address->id_customer = (int)$data['id_customer'];

        // Required fields
        $address->alias = pSQL($data['alias'] ?? 'Address');
        $address->firstname = pSQL($data['firstname'] ?? $customer->firstname);
        $address->lastname = pSQL($data['lastname'] ?? $customer->lastname);
        $address->address1 = pSQL($data['address1']);
        $address->city = pSQL($data['city']);
        $address->postcode = pSQL($data['postcode']);
        $address->id_country = (int)$data['id_country'];

        // Optional fields
        if (isset($data['address2'])) {
            $address->address2 = pSQL($data['address2']);
        }
        if (isset($data['company'])) {
            $address->company = pSQL($data['company']);
        }
        if (isset($data['phone'])) {
            $address->phone = pSQL($data['phone']);
        }
        if (isset($data['phone_mobile'])) {
            $address->phone_mobile = pSQL($data['phone_mobile']);
        }
        if (isset($data['id_state'])) {
            $address->id_state = (int)$data['id_state'];
        }
        if (isset($data['other'])) {
            $address->other = pSQL($data['other']);
        }
        if (isset($data['dni'])) {
            $address->dni = pSQL($data['dni']);
        }
        if (isset($data['vat_number'])) {
            $address->vat_number = pSQL($data['vat_number']);
        }

        // Validate country
        $country = new Country($address->id_country);
        if (!Validate::isLoadedObject($country)) {
            $logger->error('[ADDRESS_PROCESSOR] Invalid country', [
                'id_country' => $address->id_country
            ]);
            return [
                'success' => false,
                'error' => 'Invalid country ID: ' . $address->id_country
            ];
        }

        // Validate state if country requires it
        if ($country->contains_states && !$address->id_state) {
            $logger->warning('[ADDRESS_PROCESSOR] State required for country', [
                'id_country' => $address->id_country,
                'country' => $country->name
            ]);
        }

        // Add address
        if (!$address->add()) {
            $logger->error('[ADDRESS_PROCESSOR] Failed to create address', [
                'validation_errors' => $address->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to create address: ' . implode(', ', $address->getErrors())
            ];
        }

        $logger->info('[ADDRESS_PROCESSOR] Address created successfully', [
            'address_id' => $address->id,
            'customer_id' => $address->id_customer
        ]);

        return [
            'success' => true,
            'entity_id' => $address->id,
            'message' => 'Address created successfully',
            'customer_id' => $address->id_customer
        ];
    }

    /**
     * Update existing address from Odoo data
     *
     * @param array $data Address data
     * @param OdooSalesLogger $logger Logger instance
     * @return array Result
     */
    private static function updateAddress($data, $logger)
    {
        // Find address by ID
        if (!isset($data['id']) || !$data['id']) {
            return [
                'success' => false,
                'error' => 'Address ID is required for update'
            ];
        }

        $addressId = (int)$data['id'];
        $address = new Address($addressId);

        if (!Validate::isLoadedObject($address)) {
            $logger->error('[ADDRESS_PROCESSOR] Address not found', [
                'address_id' => $addressId
            ]);
            return [
                'success' => false,
                'error' => 'Address not found: ' . $addressId
            ];
        }

        // Update fields (only if provided)
        if (isset($data['alias'])) {
            $address->alias = pSQL($data['alias']);
        }
        if (isset($data['firstname'])) {
            $address->firstname = pSQL($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $address->lastname = pSQL($data['lastname']);
        }
        if (isset($data['company'])) {
            $address->company = pSQL($data['company']);
        }
        if (isset($data['address1'])) {
            $address->address1 = pSQL($data['address1']);
        }
        if (isset($data['address2'])) {
            $address->address2 = pSQL($data['address2']);
        }
        if (isset($data['postcode'])) {
            $address->postcode = pSQL($data['postcode']);
        }
        if (isset($data['city'])) {
            $address->city = pSQL($data['city']);
        }
        if (isset($data['id_country'])) {
            $address->id_country = (int)$data['id_country'];
        }
        if (isset($data['id_state'])) {
            $address->id_state = (int)$data['id_state'];
        }
        if (isset($data['phone'])) {
            $address->phone = pSQL($data['phone']);
        }
        if (isset($data['phone_mobile'])) {
            $address->phone_mobile = pSQL($data['phone_mobile']);
        }
        if (isset($data['other'])) {
            $address->other = pSQL($data['other']);
        }
        if (isset($data['dni'])) {
            $address->dni = pSQL($data['dni']);
        }
        if (isset($data['vat_number'])) {
            $address->vat_number = pSQL($data['vat_number']);
        }

        // Update address
        if (!$address->update()) {
            $logger->error('[ADDRESS_PROCESSOR] Failed to update address', [
                'address_id' => $address->id,
                'validation_errors' => $address->getErrors()
            ]);
            return [
                'success' => false,
                'error' => 'Failed to update address: ' . implode(', ', $address->getErrors())
            ];
        }

        $logger->info('[ADDRESS_PROCESSOR] Address updated successfully', [
            'address_id' => $address->id
        ]);

        return [
            'success' => true,
            'entity_id' => $address->id,
            'message' => 'Address updated successfully'
        ];
    }

    /**
     * Validate address data
     *
     * @param array $data Address data
     * @param bool $requireAll Require all fields for creation
     * @return array ['valid' => bool, 'error' => string|null]
     */
    private static function validateAddressData($data, $requireAll = false)
    {
        if ($requireAll) {
            // For creation, these are required
            $requiredFields = ['id_customer', 'address1', 'city', 'postcode', 'id_country'];

            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'valid' => false,
                        'error' => 'Required field missing: ' . $field
                    ];
                }
            }
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
            return;
        }

        $notification = [
            'event_id' => $operationId,
            'entity_type' => 'address',
            'entity_id' => $result['entity_id'] ?? null,
            'action_type' => $payload['action_type'] ?? 'updated',
            'hook_name' => 'reverseWebhookReceived',
            'timestamp' => date('c'),
            'reverse_sync' => true,
            'source' => 'odoo',
            'destination' => 'prestashop',
            'result' => $result,
            'change_summary' => self::buildChangeSummary($payload, $result)
        ];

        $ch = curl_init($debugWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notification));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

        @curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Build change summary
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
            return ucfirst($actionType) . ' address failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $alias = $data['alias'] ?? 'address';
        return ucfirst($actionType) . ' address: ' . $alias;
    }
}
