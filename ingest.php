<?php
/**
 * Ingest endpoint - Receives health reports from monitoring agents
 * 
 * Accepts JSON payload with server health data and triggers alerts
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Alerts.php';

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

// Basic rate limiting (simple IP-based)
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = 60; // Max requests per minute per IP
$rateLimitKey = 'rate_limit_' . md5($clientIP);
$rateLimitFile = sys_get_temp_dir() . '/' . $rateLimitKey;

if (file_exists($rateLimitFile)) {
    $requests = json_decode(file_get_contents($rateLimitFile), true);
    $requests = array_filter($requests, function($time) {
        return $time > (time() - 60); // Keep only requests from last minute
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

// Occasionally clean up old rate limit files (1% chance)
if (mt_rand(1, 100) === 1) {
    cleanup_rate_limits();
}

// Check content length (prevent large payload attacks)
$contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
if ($contentLength > 10240) { // 10KB limit
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
$required = ['secret', 'server_id', 'hostname', 'disk_pct', 'apache_ok', 'mysql_ok', 'time'];
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
$diskPct = (int)$data['disk_pct'];
$apacheOk = (int)$data['apache_ok'];
$mysqlOk = (int)$data['mysql_ok'];
$timestamp = (int)$data['time'];

// Enhanced validation
if (empty($serverId) || empty($hostname)) {
    http_response_code(400);
    echo 'Invalid server identification data';
    exit;
}

// Validate server_id format (alphanumeric, hyphens, dots, underscores only)
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $serverId) || strlen($serverId) > 100) {
    http_response_code(400);
    echo 'Invalid server ID format';
    exit;
}

// Validate hostname format (basic hostname validation)
if (!preg_match('/^[a-zA-Z0-9.-]+$/', $hostname) || strlen($hostname) > 255) {
    http_response_code(400);
    echo 'Invalid hostname format';
    exit;
}

if ($diskPct < 0 || $diskPct > 100) {
    http_response_code(400);
    echo 'Invalid disk percentage';
    exit;
}

if (!in_array($apacheOk, [0, 1]) || !in_array($mysqlOk, [0, 1])) {
    http_response_code(400);
    echo 'Invalid service status values';
    exit;
}

// Use current time if timestamp is too far off (more than 5 minutes)
$currentTime = now();
if (abs($timestamp - $currentTime) > 300) {
    $timestamp = $currentTime;
}

try {
    // Insert the report (no longer storing IP address)
    $stmt = $pdo->prepare('
        INSERT INTO reports (server_id, hostname, disk_pct, apache_ok, mysql_ok, ts)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$serverId, $hostname, $diskPct, $apacheOk, $mysqlOk, $timestamp]);
    
    // Check for alert conditions and send alerts if needed
    
    // Disk usage alerts
    if ($diskPct >= DISK_CRIT_PCT) {
        if (Alerts::maySend($pdo, $serverId, 'disk', 'crit', ALERT_COOLDOWN_M)) {
            $subject = "[CRITICAL] Disk {$diskPct}% - {$hostname}";
            $body = "CRITICAL: Disk usage on {$hostname} is at {$diskPct}%\n\n";
            $body .= "Server ID: {$serverId}\n";
            $body .= "Threshold: " . DISK_CRIT_PCT . "%\n";
            $body .= "Time: " . format_time($timestamp) . "\n";
            Alerts::send_alert($subject, $body);
        }
    } elseif ($diskPct >= DISK_WARN_PCT) {
        if (Alerts::maySend($pdo, $serverId, 'disk', 'warn', ALERT_COOLDOWN_M)) {
            $subject = "[WARNING] Disk {$diskPct}% - {$hostname}";
            $body = "WARNING: Disk usage on {$hostname} is at {$diskPct}%\n\n";
            $body .= "Server ID: {$serverId}\n";
            $body .= "Warning threshold: " . DISK_WARN_PCT . "%\n";
            $body .= "Critical threshold: " . DISK_CRIT_PCT . "%\n";
            $body .= "Time: " . format_time($timestamp) . "\n";
            Alerts::send_alert($subject, $body);
        }
    }
    
    // Apache status alerts
    if ($apacheOk == 0) {
        if (Alerts::maySend($pdo, $serverId, 'apache', 'down', ALERT_COOLDOWN_M)) {
            $subject = "[DOWN] Apache - {$hostname}";
            $body = "ALERT: Apache web server is DOWN on {$hostname}\n\n";
            $body .= "Server ID: {$serverId}\n";
            $body .= "Time: " . format_time($timestamp) . "\n";
            $body .= "Please check the Apache service status immediately.\n";
            Alerts::send_alert($subject, $body);
        }
    }
    
    // MySQL status alerts
    if ($mysqlOk == 0) {
        if (Alerts::maySend($pdo, $serverId, 'mysql', 'down', ALERT_COOLDOWN_M)) {
            $subject = "[DOWN] MySQL - {$hostname}";
            $body = "ALERT: MySQL database server is DOWN on {$hostname}\n\n";
            $body .= "Server ID: {$serverId}\n";
            $body .= "Time: " . format_time($timestamp) . "\n";
            $body .= "Please check the MySQL service status immediately.\n";
            Alerts::send_alert($subject, $body);
        }
    }
    
    // Return success
    echo 'ok';
    
} catch (PDOException $e) {
    error_log('Database error in ingest: ' . $e->getMessage());
    http_response_code(500);
    echo 'Database error';
} catch (Exception $e) {
    error_log('Error in ingest: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}