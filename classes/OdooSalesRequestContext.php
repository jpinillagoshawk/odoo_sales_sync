<?php
/**
 * Request Context
 *
 * Tracks correlation IDs for related events within a request.
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooSalesRequestContext
{
    /** @var string Correlation ID for current request */
    private $correlationId;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->correlationId = $this->generateCorrelationId();
    }

    /**
     * Get correlation ID for current request
     *
     * @return string Correlation ID
     */
    public function getCorrelationId()
    {
        return $this->correlationId;
    }

    /**
     * Generate new correlation ID
     *
     * @return string UUID v4
     */
    private function generateCorrelationId()
    {
        // Generate UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Reset correlation ID (for new request context)
     */
    public function reset()
    {
        $this->correlationId = $this->generateCorrelationId();
    }
}
