<?php
/**
 * Bootstrap file - Database initialization and shared helpers
 * 
 * This file is included by all other PHP files to set up the database
 * and provide common functionality.
 */

require_once __DIR__ . '/config.php';

// Initialize SQLite database
try {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables if they don't exist
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id TEXT NOT NULL,
            hostname TEXT NOT NULL,
            disk_pct INTEGER NOT NULL,
            apache_ok INTEGER NOT NULL,
            mysql_ok INTEGER NOT NULL,
            ts INTEGER NOT NULL
        )
    ');
    
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id TEXT NOT NULL,
            kind TEXT NOT NULL,
            level TEXT NOT NULL,
            last_sent INTEGER NOT NULL,
            UNIQUE(server_id, kind, level)
        )
    ');
    
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS external_ports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_id TEXT NOT NULL,
            hostname TEXT NOT NULL,
            port INTEGER NOT NULL,
            is_open INTEGER NOT NULL,
            service_name TEXT,
            last_checked INTEGER NOT NULL,
            response_data TEXT,
            UNIQUE(server_id, port)
        )
    ');
    
    // Create indexes for better performance
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_server_ts ON reports(server_id, ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alerts_lookup ON alerts(server_id, kind, level)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_external_ports_server ON external_ports(server_id, last_checked)');
    
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "Database error: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        echo "Database unavailable";
    }
    exit(1);
}

/**
 * Get current Unix timestamp
 */
function now(): int {
    return time();
}

/**
 * Sanitize output for HTML display
 */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Format timestamp for display
 */
function format_time(int $timestamp): string {
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Check if a timestamp is considered stale
 */
function is_stale(int $timestamp): bool {
    return ($timestamp < (now() - (STALE_MINUTES * 60)));
}

/**
 * Clean up old rate limit files (call periodically)
 */
function cleanup_rate_limits(): void {
    $tempDir = sys_get_temp_dir();
    $pattern = $tempDir . '/rate_limit_*';
    
    foreach (glob($pattern) as $file) {
        if (!is_file($file)) continue;
        
        // Delete files older than 2 hours
        if (filemtime($file) < (time() - 7200)) {
            unlink($file);
        }
    }
}