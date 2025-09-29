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

// Ensure this script can only be run from command line
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be executed from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Health.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Check for verbose flag
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

$checkExternalPorts = in_array('--check-ports', $argv) || in_array('-c', $argv);

/**
 * Output message if verbose mode is enabled
 */
function verboseLog(string $message): void {
    global $verbose;
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    }
}

$hostname = parse_url(CLIENT_URL, PHP_URL_HOST);

// Configuration - CHANGE THESE VALUES
$PANEL_URL = SERVER_URL . "ingest.php";
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
    verboseLog("Checking disk usage...");
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    if ($isWindows) {
        verboseLog("Using Windows WMIC command to check C: drive");
        // Windows: Get C: drive usage using wmic
        $output = shell_exec('wmic logicaldisk where size!=0 get size,freespace,caption 2>nul | findstr "C:"');
        if ($output && preg_match('/C:\s+(\d+)\s+(\d+)/', $output, $matches)) {
            $freeSpace = (float)$matches[1];
            $totalSpace = (float)$matches[2];
            $usedSpace = $totalSpace - $freeSpace;
            $diskPct = (int)(($usedSpace / $totalSpace) * 100);
            verboseLog("Disk usage: {$diskPct}% (Used: " . round($usedSpace/1024/1024/1024, 2) . "GB, Total: " . round($totalSpace/1024/1024/1024, 2) . "GB)");
        } else {
            verboseLog("ERROR: Failed to get Windows disk usage");
            return 0;
        }
    } else {
        verboseLog("Using df command to check root filesystem");
        // Unix/Linux: Use df command
        $output = shell_exec("df -P / 2>/dev/null | awk 'NR==2 {gsub(\"%\",\"\",\$5); print \$5}'");
        if (!$output) {
            verboseLog("ERROR: Failed to get Unix/Linux disk usage");
            return 0;
        }
        $diskPct = (int)trim($output);
        verboseLog("Disk usage: {$diskPct}%");
    }
    
    // Validate percentage
    if ($diskPct < 0 || $diskPct > 100) {
        verboseLog("ERROR: Invalid disk percentage: {$diskPct}%");
        return 0;
    }
    
    return $diskPct;
}

/**
 * Check Apache status
 */
function checkApache(): int {
    verboseLog("Checking Apache status...");
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    // Method 1: Check service status using systemctl (Ubuntu/Linux preferred method)
    if (!$isWindows && commandExists('systemctl')) {
        verboseLog("Using systemctl to check Apache services");
        $services = ['apache2', 'httpd', 'apache'];
        foreach ($services as $service) {
            verboseLog("Checking service: {$service}");
            // Check if service is active
            $output = shell_exec("systemctl is-active $service 2>/dev/null");
            if ($output && trim($output) === 'active') {
                verboseLog("Apache is RUNNING (service: {$service})");
                return 1;
            }
            
            // Alternative: Check status with exit code
            $output = shell_exec("systemctl is-active --quiet $service 2>/dev/null; echo \$?");
            if ($output && trim($output) === '0') {
                verboseLog("Apache is RUNNING (service: {$service})");
                return 1;
            }
        }
        verboseLog("No Apache services found running via systemctl");
    }
    
    // Method 2: Windows service check
    if ($isWindows) {
        verboseLog("Using Windows SC command to check Apache services");
        $services = ['Apache2.4', 'Apache2.2', 'Apache', 'httpd'];
        foreach ($services as $service) {
            verboseLog("Checking Windows service: {$service}");
            $output = shell_exec("sc query \"$service\" 2>nul");
            if ($output && strpos($output, 'RUNNING') !== false) {
                verboseLog("Apache is RUNNING (Windows service: {$service})");
                return 1;
            }
        }
        verboseLog("No Apache Windows services found running");
    }
    
    // Method 3: Try command line curl as final fallback
    if (commandExists('curl')) {
        verboseLog("Using curl to test local Apache connection");
        if ($isWindows) {
            $output = shell_exec('curl -fsS --max-time 2 http://127.0.0.1/ >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('curl -fsS --max-time 2 http://127.0.0.1/ >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            verboseLog("Apache is RUNNING (curl test successful)");
            return 1;
        } else {
            verboseLog("Apache curl test failed");
        }
    } else {
        verboseLog("curl command not available for Apache testing");
    }
    
    verboseLog("Apache is NOT RUNNING");
    return 0;
}

/**
 * Check MySQL status
 */
function checkMySQL(): int {
    verboseLog("Checking MySQL status...");
    
    // Method 1: Try mysqladmin ping
    if (commandExists('mysqladmin')) {
        verboseLog("Using mysqladmin ping to test MySQL connection");
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $output = shell_exec('mysqladmin ping --silent --connect-timeout=2 >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('mysqladmin ping --silent --connect-timeout=2 >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            verboseLog("MySQL is RUNNING (mysqladmin ping successful)");
            return 1;
        } else {
            verboseLog("mysqladmin ping failed");
        }
    } else {
        verboseLog("mysqladmin command not available");
    }
    
    // Method 2: Try mysql command
    if (commandExists('mysql')) {
        verboseLog("Using mysql command to test MySQL connection");
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWindows) {
            $output = shell_exec('mysql -e "SELECT 1;" --connect-timeout=2 >nul 2>&1 && echo 0 || echo 1');
        } else {
            $output = shell_exec('mysql -e "SELECT 1;" --connect-timeout=2 >/dev/null 2>&1; echo $?');
        }
        if ($output && trim($output) === '0') {
            verboseLog("MySQL is RUNNING (mysql command successful)");
            return 1;
        } else {
            verboseLog("mysql command test failed");
        }
    } else {
        verboseLog("mysql command not available");
    }
    
    // Method 3: Check service status (OS-specific)
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    
    if ($isWindows) {
        verboseLog("Using Windows SC command to check MySQL services");
        // Windows service check
        $services = ['MySQL80', 'MySQL57', 'MySQL56', 'MySQL', 'MariaDB'];
        foreach ($services as $service) {
            verboseLog("Checking Windows service: {$service}");
            $output = shell_exec("sc query \"$service\" 2>nul");
            if ($output && strpos($output, 'RUNNING') !== false) {
                verboseLog("MySQL is RUNNING (Windows service: {$service})");
                return 1;
            }
        }
        verboseLog("No MySQL Windows services found running");
    } elseif (commandExists('systemctl')) {
        verboseLog("Using systemctl to check MySQL services");
        // Linux systemctl check
        $services = ['mysql', 'mariadb', 'mysqld'];
        foreach ($services as $service) {
            verboseLog("Checking service: {$service}");
            $output = shell_exec("systemctl is-active --quiet $service 2>/dev/null; echo \$?");
            if (trim($output) === '0') {
                verboseLog("MySQL is RUNNING (service: {$service})");
                return 1;
            }
        }
        verboseLog("No MySQL services found running via systemctl");
    } else {
        verboseLog("systemctl not available for MySQL service checking");
    }
    
    verboseLog("MySQL is NOT RUNNING");
    return 0;
}

/**
 * Send data to monitoring server
 */
function sendData(array $data): bool {
    global $PANEL_URL;
    
    verboseLog("Sending data to monitoring server: {$PANEL_URL}");
    verboseLog("Payload: " . json_encode($data, JSON_PRETTY_PRINT));
    
    // Method 1: Use curl extension if available
    if (function_exists('curl_init')) {
        verboseLog("Using PHP cURL extension");
        $result = Health::executeCurl($PANEL_URL, $data, 10);
        
        // Parse and display server response
        if ($result['response']) {
            verboseLog("Server Response: " . $result['response']);
            
            // Try to parse JSON response
            $responseData = json_decode($result['response'], true);
            if ($responseData) {
                if (isset($responseData['status'])) {
                    verboseLog("Response Status: " . $responseData['status']);
                }
                if (isset($responseData['message'])) {
                    verboseLog("Response Message: " . $responseData['message']);
                }
                if (isset($responseData['report_id'])) {
                    verboseLog("Report ID: " . $responseData['report_id']);
                }
                if (isset($responseData['error_type'])) {
                    verboseLog("Error Type: " . $responseData['error_type']);
                }
            }
        }
        
        // Log errors for debugging
        if ($result['error']) {
            $errorMsg = "Agent cURL Error: " . $result['error'];
            error_log($errorMsg);
            verboseLog("ERROR: " . $errorMsg);
        }
        if (!$result['success']) {
            $errorMsg = "Agent HTTP Error: {$PANEL_URL} Code " . $result['httpCode'];
            error_log($errorMsg);
            verboseLog("ERROR: " . $errorMsg);
        } else {
            verboseLog("SUCCESS: Data sent successfully (HTTP {$result['httpCode']})");
        }
        
        return $result['success'];
    }
    
    // Method 2: Use command line curl as fallback
    if (commandExists('curl')) {
        verboseLog("Using command-line curl as fallback");
        $jsonData = json_encode($data);
        $tempFile = tempnam(sys_get_temp_dir(), 'monitor_data');
        $responseFile = tempnam(sys_get_temp_dir(), 'monitor_response');
        file_put_contents($tempFile, $jsonData);
        
        $cmd = sprintf(
            'curl -fsS --max-time 10 -k -H "Content-Type: application/json" -d @%s %s -o %s',
            escapeshellarg($tempFile),
            escapeshellarg($PANEL_URL),
            escapeshellarg($responseFile)
        );
        
        $output = shell_exec($cmd . '; echo $?');
        $exitCode = trim($output);
        
        // Read response if available
        if (file_exists($responseFile)) {
            $response = file_get_contents($responseFile);
            if ($response) {
                verboseLog("Server Response: " . $response);
                
                // Try to parse JSON response
                $responseData = json_decode($response, true);
                if ($responseData) {
                    if (isset($responseData['status'])) {
                        verboseLog("Response Status: " . $responseData['status']);
                    }
                    if (isset($responseData['message'])) {
                        verboseLog("Response Message: " . $responseData['message']);
                    }
                    if (isset($responseData['report_id'])) {
                        verboseLog("Report ID: " . $responseData['report_id']);
                    }
                }
            }
            unlink($responseFile);
        }
        
        unlink($tempFile);
        
        $success = $exitCode === '0';
        if (!$success) {
            $errorMsg = "Agent Command-line cURL failed with exit code: " . $exitCode;
            error_log($errorMsg);
            verboseLog("ERROR: " . $errorMsg);
        } else {
            verboseLog("SUCCESS: Data sent successfully via command-line curl");
        }
        
        return $success;
    }
    
    verboseLog("ERROR: No cURL method available (neither PHP extension nor command-line)");
    return false;
}

/**
 * Send external port check data to monitoring server
 */
function sendExternalPortData(string $hostname): void {
    global $SECRET, $SERVER_ID, $CHECK_EXTERNAL_PORTS;
    global $PANEL_URL;
    
    if (!$CHECK_EXTERNAL_PORTS) {
        verboseLog("External port checking is disabled");
        return;
    }
    
    verboseLog("Sending external port check data...");
    
    $portsToCheck = [];
    foreach (Health::$PORT_SERVICES as $port => $service) {
        $portsToCheck[] = $port;
    }
    
    verboseLog("Ports to check: " . implode(', ', $portsToCheck));
    verboseLog("Port services mapping:");
    foreach (Health::$PORT_SERVICES as $port => $service) {
        verboseLog("  Port {$port}: {$service}");
    }

    // Send external port check data
    $postData = [
        'secret' => $SECRET,
        'server_id' => $SERVER_ID,
        'hostname' => $hostname,
        'ports' => $portsToCheck
    ];

    $response = Health::executeCurl($PANEL_URL . '/api/external_ports', $postData);
    if (!$response['success']) {
        verboseLog("ERROR: Failed to send external port check data");
        error_log("Agent Error: Failed to send external port check data");
    }

    // use the isPortOpenExternal function to check each port and log results
    foreach ($portsToCheck as $port) {  
        $result = Health::isPortOpenExternal($hostname, $port);
        if ($result['isOpen']) {
            verboseLog("Port {$port} ({$result['service']}) is OPEN");
        } else {
            verboseLog("Port {$port} ({$result['service']}) is CLOSED");
            if ($result['error']) {
                verboseLog("  Error: " . $result['error']);
            }
        }
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $SECRET, $SERVER_ID, $hostname, $verbose, $checkExternalPorts;
    
    verboseLog("=== Server Monitor Agent Starting ===");
    verboseLog("Server ID: {$SERVER_ID}");
    verboseLog("Hostname: {$hostname}");
    verboseLog("Group: " . GROUP_NAME);
    verboseLog("OS: " . PHP_OS . " (" . php_uname('s') . " " . php_uname('r') . ")");
    
    // Check for required dependencies
    if (!function_exists('shell_exec')) {
        $errorMsg = "shell_exec function is disabled - cannot collect system metrics";
        verboseLog("ERROR: " . $errorMsg);
        error_log("Agent Error: " . $errorMsg);
        exit(1);
    }
    
    verboseLog("=== Collecting System Metrics ===");
    
    // Collect metrics
    $diskPct = getDiskUsage();
    $apacheOk = checkApache();
    $mysqlOk = checkMySQL();
    $timestamp = time();
    
    verboseLog("=== Metrics Summary ===");
    verboseLog("Disk Usage: {$diskPct}%");
    verboseLog("Apache Status: " . ($apacheOk ? 'RUNNING' : 'NOT RUNNING'));
    verboseLog("MySQL Status: " . ($mysqlOk ? 'RUNNING' : 'NOT RUNNING'));
    verboseLog("Timestamp: {$timestamp} (" . date('Y-m-d H:i:s', $timestamp) . ")");
    
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
    
    verboseLog("=== Sending Data to Monitoring Server ===");
    
    // Send data to monitoring server
    $success = sendData($payload);
    
    if (!$success) {
        verboseLog("ERROR: Failed to send monitoring data");
        error_log("Agent Error: Failed to send monitoring data to server");
        exit(1);
    }
    
    // Send external port check data (runs less frequently)
    // Only check external ports every 5 minutes to avoid overloading external service
    $currentMinute = (int)date('i');
    verboseLog("Current minute: {$currentMinute}");
    
    if ($currentMinute % 5 === 0 || $checkExternalPorts) {
        verboseLog("=== External Port Check Time ===");
        sendExternalPortData($hostname);
    } else {
        verboseLog("Skipping external port check (runs every 5 minutes)");
    }
    
    verboseLog("=== Agent Execution Completed Successfully ===");
    
    // Exit successfully
    exit(0);
}

// Display usage information if requested
if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo "Server Monitor Agent - PHP CLI Tool\n";
    echo "Usage: php agent.php [options]\n\n";
    echo "Options:\n";
    echo "  -v, --verbose    Enable verbose output\n";
    echo "  -h, --help       Show this help message\n\n";
    echo "This script collects server health metrics and sends them to the monitoring dashboard.\n";
    echo "Designed to be run via cron every minute.\n\n";
    echo "Example cron entry:\n";
    echo "* * * * * /usr/bin/php " . __FILE__ . " >/dev/null 2>&1\n";
    echo "\nFor verbose output during testing:\n";
    echo "php " . basename(__FILE__) . " --verbose\n";
    exit(0);
}

// Run main function
try {
    main();
} catch (Exception $e) {
    $errorMsg = "Agent fatal error: " . $e->getMessage();
    error_log($errorMsg);
    verboseLog("FATAL ERROR: " . $errorMsg);
    verboseLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
} catch (Error $e) {
    $errorMsg = "Agent fatal error: " . $e->getMessage();
    error_log($errorMsg);
    verboseLog("FATAL ERROR: " . $errorMsg);
    verboseLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}