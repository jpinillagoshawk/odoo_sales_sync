<?php
/**
 * Internal Webhook for Async Event Processing
 *
 * This endpoint receives event IDs, returns 200 OK immediately,
 * then processes events in background without blocking the caller.
 *
 * Solves cold-start problem: Even if Odoo takes 10s to wake up,
 * the cURL caller gets response in ~10ms.
 *
 * Adapted from odoo_direct_stock_sync webhook.php
 * - Changed: stock events → sales events
 * - Kept: Same security, same async pattern, same shell wrapper
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

// ============================================================================
// STEP 1: Security check - BEFORE any processing
// ============================================================================

// Get remote address (handle reverse proxy scenarios)
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

// Build whitelist: localhost IPs + server's own IP
$allowedIPs = ['127.0.0.1', '::1'];

// Allow server's own IP (for self-webhook when not behind proxy)
if (!empty($serverAddr) && $serverAddr !== '127.0.0.1') {
    $allowedIPs[] = $serverAddr;
}

// Check direct REMOTE_ADDR first
$isAllowed = in_array($remoteAddr, $allowedIPs);

// If behind reverse proxy, check if X-Real-IP or X-Forwarded-For matches server IP
// This handles: Browser -> Proxy -> PHP (REMOTE_ADDR = proxy IP, X-Real-IP = server IP for self-calls)
if (!$isAllowed && !empty($serverAddr)) {
    $xRealIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    $xForwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

    // Check if X-Real-IP is the server's own IP
    if ($xRealIp === $serverAddr) {
        $isAllowed = true;
    }

    // Check if first X-Forwarded-For IP is the server's own IP
    if (!$isAllowed && !empty($xForwardedFor)) {
        $forwardedIPs = array_map('trim', explode(',', $xForwardedFor));
        if (isset($forwardedIPs[0]) && $forwardedIPs[0] === $serverAddr) {
            $isAllowed = true;
        }
    }
}

if (!$isAllowed) {
    http_response_code(403);
    die('Forbidden');
}

// ============================================================================
// STEP 2: Return 200 OK IMMEDIATELY - before loading PrestaShop
// ============================================================================

// Get event IDs from request body
$eventIdsJson = file_get_contents('php://input');
$eventIds = json_decode($eventIdsJson, true);

// Basic validation
if (!is_array($eventIds) || empty($eventIds)) {
    http_response_code(400);
    die('Bad Request');
}

// ============================================================================
// STEP 3: Spawn detached background process (works with PHP-FPM restrictions)
// ============================================================================

// Emergency logging function
function webhookEmergencyLog($message, $data = []) {
    $logFile = dirname(__FILE__) . '/webhook_processing.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = sprintf(
        "[%s] %s %s\n",
        $timestamp,
        $message,
        !empty($data) ? json_encode($data) : ''
    );
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

webhookEmergencyLog('[WEBHOOK] Environment check', [
    'cwd' => getcwd(),
    'script_path' => __FILE__,
    'open_basedir' => ini_get('open_basedir') ?: 'none',
    'disable_functions' => ini_get('disable_functions') ?: 'none',
    'safe_mode' => ini_get('safe_mode') ? 'yes' : 'no'
]);

webhookEmergencyLog('[WEBHOOK] Spawning background process', [
    'event_ids' => $eventIds,
    'parent_pid' => getmypid()
]);

// Save event IDs to temp file for background process to read
$tempFile = sys_get_temp_dir() . '/odoo_sales_webhook_' . uniqid() . '.json';
file_put_contents($tempFile, $eventIdsJson);

// Spawn detached background process
$processorScript = dirname(__FILE__) . '/webhook_processor.php';

// Create a shell script wrapper that uses 'php' from PATH
// This works around open_basedir restrictions and php-fpm CLI incompatibility
$shellScript = $tempFile . '.sh';
$logFile = dirname(__FILE__) . '/webhook_processing.log';

// Build shell script with PHP path detection
$shellLines = [
    "#!/bin/sh",
    "echo \"[SHELL] Script started at \$(date)\" >> " . escapeshellarg($logFile),
    "cd " . escapeshellarg(dirname($processorScript)),
    "echo \"[SHELL] Changed to directory: \$(pwd)\" >> " . escapeshellarg($logFile),
    "",
    "# Try to find PHP binary",
    "PHP_BIN=\"\"",
    "for path in /usr/bin/php /opt/plesk/php/7.4/bin/php /opt/plesk/php/8.0/bin/php /opt/plesk/php/8.1/bin/php /usr/local/bin/php /bin/php; do",
    "  if [ -x \"\$path\" ]; then",
    "    PHP_BIN=\"\$path\"",
    "    echo \"[SHELL] Found PHP at: \$path\" >> " . escapeshellarg($logFile),
    "    break",
    "  fi",
    "done",
    "",
    "if [ -z \"\$PHP_BIN\" ]; then",
    "  echo \"[SHELL] ERROR: PHP binary not found\" >> " . escapeshellarg($logFile),
    "  exit 127",
    "fi",
    "",
    "echo \"[SHELL] Executing: \$PHP_BIN " . escapeshellarg($processorScript) . " " . escapeshellarg($tempFile) . "\" >> " . escapeshellarg($logFile),
    "\$PHP_BIN " . escapeshellarg($processorScript) . " " . escapeshellarg($tempFile) . " 2>&1 >> " . escapeshellarg($logFile),
    "EXIT_CODE=\$?",
    "echo \"[SHELL] PHP exit code: \$EXIT_CODE\" >> " . escapeshellarg($logFile),
    "rm -f " . escapeshellarg($tempFile) . " " . escapeshellarg($shellScript),
    "echo \"[SHELL] Cleanup completed\" >> " . escapeshellarg($logFile),
];

$shellContent = implode("\n", $shellLines) . "\n";
file_put_contents($shellScript, $shellContent);
chmod($shellScript, 0755);

webhookEmergencyLog('[WEBHOOK] Created shell script', [
    'shell_script' => $shellScript,
    'content' => $shellContent
]);

// Execute the shell script in background
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows: Use start /B to detach
    $cmd = sprintf('start /B %s > NUL 2>&1', escapeshellarg($shellScript));
    pclose(popen($cmd, 'r'));
} else {
    // Linux: Use nohup and & to detach
    $cmd = sprintf('nohup %s > /dev/null 2>&1 &', escapeshellarg($shellScript));
    exec($cmd);
}

webhookEmergencyLog('[WEBHOOK] Background process spawned', [
    'temp_file' => $tempFile,
    'command' => $cmd
]);

// Return success response immediately
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
exit(0);
