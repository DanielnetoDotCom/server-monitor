<?php
/**
 * External Port Check endpoint - Receives external port check requests from agents
 * 
 * Checks external accessibility of ports and stores results
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Health.php';

// Security headers
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// Check if external port checking is enabled
if (!defined('ENABLE_EXTERNAL_PORT_CHECK') || !ENABLE_EXTERNAL_PORT_CHECK) {
    http_response_code(503);
    echo 'External port checking disabled';
    exit;
}

// Basic rate limiting (simple IP-based)
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = 10; // Max 10 requests per minute per IP for external port checks
$rateLimitKey = 'ext_port_rate_limit_' . md5($clientIP);
$rateLimitFile = sys_get_temp_dir() . '/' . $rateLimitKey;

if (file_exists($rateLimitFile)) {
    $requests = json_decode(file_get_contents($rateLimitFile), true);
    $requests = array_filter($requests, function($time) {
        return $time > (time() - 60);
    });
    
    if (count($requests) >= $rateLimit) {
        http_response_code(429);
        echo 'Rate limit exceeded';
        exit;
    }
    
    $requests[] = time();
} else {
    $requests = [time()];
}

file_put_contents($rateLimitFile, json_encode($requests), LOCK_EX);

// Check content length
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
if ($contentLength > 5120) { // 5KB limit for port check requests
    http_response_code(413);
    echo 'Payload too large';
    exit;
}

// Validate content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== 0) {
    http_response_code(415);
    echo 'Content type must be application/json';
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
if (!$input) {
    http_response_code(400);
    echo 'No input data';
    exit;
}

// Parse JSON
$data = json_decode($input, true);
if (!$data) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

// Validate required fields
$required = ['secret', 'server_id', 'hostname', 'ports', 'time'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo "Missing field: $field";
        exit;
    }
}

// Validate secret (timing attack safe)
if (!hash_equals(MONITOR_SECRET, $data['secret'])) {
    http_response_code(403);
    echo 'Invalid secret';
    exit;
}

// Validate and sanitize data
$serverId = trim($data['server_id']);
$hostname = trim($data['hostname']);
$ports = $data['ports'];
$timestamp = (int)$data['time'];

// Get group name (optional field, defaults to 'default')
$groupName = isset($data['group_name']) ? $data['group_name'] : 'default';

// Sanitize group name
$groupName = preg_replace('/[^a-zA-Z0-9_-]/', '', $groupName);
if (empty($groupName) || strlen($groupName) > 50) {
    $groupName = 'default';
}

// Enhanced validation
if (empty($serverId) || empty($hostname)) {
    http_response_code(400);
    echo 'Invalid server identification data';
    exit;
}

if (!is_array($ports) || empty($ports)) {
    http_response_code(400);
    echo 'Invalid ports data';
    exit;
}

// Validate server_id format
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $serverId) || strlen($serverId) > 100) {
    http_response_code(400);
    echo 'Invalid server ID format';
    exit;
}

// Validate hostname format
if (!preg_match('/^[a-zA-Z0-9.-]+$/', $hostname) || strlen($hostname) > 255) {
    http_response_code(400);
    echo 'Invalid hostname format';
    exit;
}

// Validate ports (must be integers in valid range and in allowed list)
$allowedPorts = [];
foreach (Health::$PORT_SERVICES as $port => $service) {
    $allowedPorts[] = $port;
}
foreach ($ports as $port) {
    if (!is_numeric($port) || $port < 1 || $port > 65535) {
        http_response_code(400);
        echo 'Invalid port number';
        exit;
    }
    
    // Only allow ports that are configured for monitoring
    if (!in_array((int)$port, $allowedPorts)) {
        http_response_code(400);
        echo 'Port not configured for monitoring';
        exit;
    }
}

// Limit number of ports to check (prevent abuse)
if (count($ports) > count($allowedPorts)) {
    http_response_code(400);
    echo 'Too many ports requested';
    exit;
}

// Occasionally clean up old database records (0.5% chance, less frequent than ingest)
if (mt_rand(1, 200) === 1) {
    cleanup_old_data($pdo);
}

try {
    $currentTime = now();
    $processedPorts = 0;
    
    // Check each port externally and store results
    foreach ($ports as $port) {
        $port = (int)$port;
        
        // Check if we recently checked this port for this server (avoid spam)
        $stmt = $pdo->prepare('
            SELECT last_checked FROM external_ports 
            WHERE server_id = ? AND port = ? AND group_name = ?
            ORDER BY last_checked DESC LIMIT 1
        ');
        $stmt->execute([$serverId, $port, $groupName]);
        $lastChecked = $stmt->fetchColumn();
        
        // Only check if it hasn't been checked in the last 5 minutes
        if ($lastChecked && ($currentTime - $lastChecked) < 300) {
            continue;
        }
        
        // Perform external port check
        $portResult = Health::isPortOpenExternal($hostname, $port, EXTERNAL_PORT_TIMEOUT);
        
        // Store or update the result (including group name)
        $stmt = $pdo->prepare('
            INSERT OR REPLACE INTO external_ports 
            (server_id, hostname, group_name, port, is_open, service_name, last_checked, response_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $serverId,
            $hostname,
            $groupName,
            $port,
            $portResult['isOpen'] ? 1 : 0,
            $portResult['service'],
            $currentTime,
            json_encode($portResult['response'])
        ]);
        
        $processedPorts++;
        
        // Add small delay between checks to be nice to external service
        if ($processedPorts < count($ports)) {
            usleep(500000); // 0.5 second delay
        }
    }
    
    // Return success
    echo 'ok';
    
} catch (PDOException $e) {
    error_log('Database error in external_ports: ' . $e->getMessage());
    http_response_code(500);
    echo 'Database error';
} catch (Exception $e) {
    error_log('Error in external_ports: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}