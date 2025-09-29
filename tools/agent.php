#!/usr/bin/env php
<?php
/**
 * Server Monitor Agent (PHP CLI)
 *
 * This script collects server health metrics and sends them to the monitoring dashboard.
 * Designed to be run via cron every minute.
 *
 * Dependencies: PHP CLI, curl extension, shell_exec enabled
 * Optional: systemctl (for service status checks)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Health.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$hostname = parse_url(CLIENT_URL, PHP_URL_HOST);

// Configuration - CHANGE THESE VALUES
$PANEL_URL = SERVER_URL . "ingest.php";
$EXTERNAL_PORT_URL = SERVER_URL . "external_ports.php";
$SECRET = MONITOR_SECRET; 
$CHECK_EXTERNAL_PORTS = true; // Enable/disable external port checking

// NOTE: The ports to check are defined in the server's config.php file

// Auto-generate server ID if not set via environment variable
$SERVER_ID = md5(MONITOR_SECRET.$hostname);

/**
 * Check if command exists
 */
function commandExists(string $command): bool {
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    if ($isWindows) {
        $check = shell_exec("where $command 2>nul");
    } else {
        $check = shell_exec("which $command 2>/dev/null");
    }
    
    return $check && !empty(trim($check));
}

/**
 * Get disk usage percentage for root filesystem
 */
function getDiskUsage(): int {
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    if ($isWindows) {
        // Windows: Get C: drive usage using wmic
        $output = shell_exec('wmic logicaldisk where size!=0 get size,freespace,caption 2>nul | findstr "C:"');
        if ($output && preg_match('/C:\s+(\d+)\s+(\d+)/', $output, $matches)) {
            $freeSpace = (float)$matches[1];
            $totalSpace = (float)$matches[2];
            $usedSpace = $totalSpace - $freeSpace;
            $diskPct = (int)(($usedSpace / $totalSpace) * 100);
        } else {
            return 0;
        }
    } else {
        // Unix/Linux: Use df command
        $output = shell_exec("df -P / 2>/dev/null | awk 'NR==2 {gsub(\"%\",\"\",\$5); print \$5}'");
        if (!$output) {
            return 0;
        }
        $diskPct = (int)trim($output);
    }
    
    // Validate percentage
    if ($diskPct < 0 || $diskPct > 100) {
        return 0;
    }
    
    return $diskPct;
}

/**
 * Check Apache status
 */
function checkApache(): int {
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    // Method 1: Check service status using systemctl (Ubuntu/Linux preferred method)
    if (!$isWindows && commandExists('systemctl')) {
        $services = ['apache2', 'httpd', 'apache'];
        foreach ($services as $service) {
            // Check if service is active
            $output = shell_exec("systemctl is-active $service 2>/dev/null");
            if ($output && trim($output) === 'active') {
                return 1;
            }
            
            // Alternative: Check status with exit code
            $output = shell_exec("systemctl is-active --quiet $service 2>/dev/null; echo \$?");
            if ($output && trim($output) === '0') {
                return 1;
            }
        }
    }
    
    // Method 2: Windows service check
    if ($isWindows) {
        $services = ['Apache2.4', 'Apache2.2', 'Apache', 'httpd'];
        foreach ($services as $service) {
            $output = shell_exec("sc query \"$service\" 2>nul");
            if ($output && strpos($output, 'RUNNING') !== false) {
                return 1;
            }
        }
    }
    
    // Method 4: Try command line curl as final fallback
    if (commandExists('curl')) {
        if ($isWindows) {
            $output = shell_exec('curl -fsS --max-time 2 http://127.0.0.1/ >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('curl -fsS --max-time 2 http://127.0.0.1/ >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            return 1;
        }
    }
    
    return 0;
}

/**
 * Check MySQL status
 */
function checkMySQL(): int {
    // Method 1: Try mysqladmin ping
    if (commandExists('mysqladmin')) {
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $output = shell_exec('mysqladmin ping --silent --connect-timeout=2 >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('mysqladmin ping --silent --connect-timeout=2 >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            return 1;
        }
    }
    
    // Method 2: Try mysql command
    if (commandExists('mysql')) {
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $output = shell_exec('mysql -e "SELECT 1;" --connect-timeout=2 >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('mysql -e "SELECT 1;" --connect-timeout=2 >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            return 1;
        }
    }
    
    // Method 3: Check service status (OS-specific)
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    if ($isWindows) {
        // Windows service check
        $services = ['MySQL80', 'MySQL57', 'MySQL56', 'MySQL', 'MariaDB'];
        foreach ($services as $service) {
            $output = shell_exec("sc query \"$service\" 2>nul");
            if ($output && strpos($output, 'RUNNING') !== false) {
                return 1;
            }
        }
    } elseif (commandExists('systemctl')) {
        // Linux systemctl check
        $services = ['mysql', 'mariadb', 'mysqld'];
        foreach ($services as $service) {
            $output = shell_exec("systemctl is-active --quiet $service 2>/dev/null; echo \$?");
            if (trim($output) === '0') {
                return 1;
            }
        }
    }
    
    return 0;
}

/**
 * Execute cURL request with common settings
 */
function executeCurl(string $url, array $data, int $timeout = 10): array {
    $jsonData = json_encode($data);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ServerMonitorAgent/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => ($httpCode >= 200 && $httpCode < 300 && !$error),
        'httpCode' => $httpCode,
        'error' => $error,
        'response' => $result
    ];
}

/**
 * Send data to monitoring server
 */
function sendData(array $data): bool {
    global $PANEL_URL;
    
    // Method 1: Use curl extension if available
    if (function_exists('curl_init')) {
        $result = executeCurl($PANEL_URL, $data, 10);
        
        // Log errors for debugging
        if ($result['error']) {
            error_log("Agent cURL Error: " . $result['error']);
        }
        if (!$result['success']) {
            error_log("Agent HTTP Error: {$PANEL_URL} Code " . $result['httpCode'] . ", ".json_encode($data)." Response: " . substr($result['response'], 0, 500));
        }
        
        return $result['success'];
    }
    
    // Method 2: Use command line curl as fallback
    if (commandExists('curl')) {
        $jsonData = json_encode($data);
        $tempFile = tempnam(sys_get_temp_dir(), 'monitor_data');
        file_put_contents($tempFile, $jsonData);
        
        $cmd = sprintf(
            'curl -fsS --max-time 10 -k -H "Content-Type: application/json" -d @%s %s >/dev/null 2>&1',
            escapeshellarg($tempFile),
            escapeshellarg($PANEL_URL)
        );
        
        $output = shell_exec($cmd . '; echo $?');
        unlink($tempFile);
        
        $success = trim($output) === '0';
        if (!$success) {
            error_log("Agent Command-line cURL failed with exit code: " . trim($output));
        }
        
        return $success;
    }
    
    return false;
}

/**
 * Send external port check data to monitoring server
 */
function sendExternalPortData(string $hostname): void {
    global $SECRET, $SERVER_ID, $EXTERNAL_PORT_URL, $CHECK_EXTERNAL_PORTS;
    
    if (!$CHECK_EXTERNAL_PORTS) {
        return;
    }
    
    $portsToCheck = [];
    foreach (Health::$PORT_SERVICES as $port => $service) {
        $portsToCheck[] = $port;
    }
    
    $payload = [
        'secret' => $SECRET,
        'server_id' => $SERVER_ID,
        'hostname' => $hostname,
        'ports' => $portsToCheck,
        'time' => time(),
        'group_name' => GROUP_NAME
    ];
    
    // Use curl to send data with longer timeout for external checks
    if (function_exists('curl_init')) {
        $result = executeCurl($EXTERNAL_PORT_URL, $payload, 30);
        
        // Log errors for debugging external port checks
        if ($result['error']) {
            error_log("Agent External Port cURL Error: " . $result['error']);
        }
        if (!$result['success']) {
            error_log("Agent External Port HTTP Error: Code " . $result['httpCode'] . ", Response: " . substr($result['response'], 0, 500));
        }
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $SECRET, $SERVER_ID, $hostname;
    
    // Check for required dependencies
    if (!function_exists('shell_exec')) {
        // Silently exit if shell_exec is disabled
        exit(0);
    }
    
    // Collect metrics
    $diskPct = getDiskUsage();
    $apacheOk = checkApache();
    $mysqlOk = checkMySQL();
    $timestamp = time();
    
    // Build payload
    $payload = [
        'secret' => $SECRET,
        'server_id' => $SERVER_ID,
        'hostname' => $hostname,
        'disk_pct' => $diskPct,
        'apache_ok' => $apacheOk,
        'mysql_ok' => $mysqlOk,
        'time' => $timestamp,
        'group_name' => GROUP_NAME
    ];
    
    // Send data to monitoring server
    sendData($payload);
    
    // Send external port check data (runs less frequently)
    // Only check external ports every 5 minutes to avoid overloading external service
    $currentMinute = (int)date('i');
    if ($currentMinute % 5 === 0) {
        sendExternalPortData($hostname);
    }
    
    // Always exit successfully (don't spam cron logs)
    exit(0);
}

// Run main function
main();