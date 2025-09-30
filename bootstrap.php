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
            group_name TEXT NOT NULL DEFAULT "default",
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
            group_name TEXT NOT NULL DEFAULT "default",
            kind TEXT NOT NULL,
            level TEXT NOT NULL,
            last_sent INTEGER NOT NULL,
            UNIQUE(server_id, kind, level, group_name)
        )
    ');
    

    
    // Add group_name column to existing tables if it doesn't exist
    try {
        $pdo->exec('ALTER TABLE reports ADD COLUMN group_name TEXT NOT NULL DEFAULT "default"');
    } catch (PDOException $e) {
        // Column already exists, ignore
    }
    
    try {
        $pdo->exec('ALTER TABLE alerts ADD COLUMN group_name TEXT NOT NULL DEFAULT "default"');
    } catch (PDOException $e) {
        // Column already exists, ignore
    }
    

    
    // Create indexes for better performance
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_server_ts ON reports(server_id, ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_group_server_ts ON reports(group_name, server_id, ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alerts_lookup ON alerts(server_id, kind, level)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alerts_group_lookup ON alerts(group_name, server_id, kind, level)');

    
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

/**
 * Clean up old database records (call periodically to prevent database bloat)
 * Deletes records older than the configured retention periods
 */
function cleanup_old_data(PDO $pdo): void {
    try {
        $dataRetentionDays = defined('DATA_RETENTION_DAYS') ? DATA_RETENTION_DAYS : 7;
        $alertRetentionDays = defined('ALERT_RETENTION_DAYS') ? ALERT_RETENTION_DAYS : 30;
        
        $dataCutoffTime = now() - ($dataRetentionDays * 24 * 60 * 60);
        $alertCutoffTime = now() - ($alertRetentionDays * 24 * 60 * 60);
        
        // Clean up old reports
        $stmt = $pdo->prepare('DELETE FROM reports WHERE ts < ?');
        $stmt->execute([$dataCutoffTime]);
        $deletedReports = $stmt->rowCount();
        

        
        // Clean up old alert records (keeping longer for cooldown functionality)
        $stmt = $pdo->prepare('DELETE FROM alerts WHERE last_sent < ?');
        $stmt->execute([$alertCutoffTime]);
        $deletedAlerts = $stmt->rowCount();
        
        // Log cleanup activity (only if records were deleted)
        if ($deletedReports > 0 || $deletedAlerts > 0) {
            error_log("Database cleanup completed: {$deletedReports} reports (>{$dataRetentionDays}d), {$deletedAlerts} alert records (>{$alertRetentionDays}d) deleted");
        }
        
        // Optimize database after cleanup (SQLite specific - reclaims space)
        if ($deletedReports > 0 || $deletedAlerts > 0) {
            $pdo->exec('VACUUM');
        }
        
    } catch (PDOException $e) {
        error_log('Database cleanup error: ' . $e->getMessage());
    }
}

/**
 * Get and validate group name from URL parameter or default
 */
function get_group_name(): string {
    $group = $_GET['group'] ?? 'default';
    
    // Sanitize group name - only allow alphanumeric, underscore, and hyphen
    $group = preg_replace('/[^a-zA-Z0-9_-]/', '', $group);
    
    // Ensure it's not empty and not too long
    if (empty($group) || strlen($group) > 50) {
        $group = 'default';
    }
    
    return $group;
}

/**
 * Get all available groups from the database
 */
function get_available_groups(): array {
    global $pdo;
    
    try {
        $stmt = $pdo->query('
            SELECT DISTINCT group_name 
            FROM reports 
            WHERE group_name IS NOT NULL 
            ORDER BY group_name ASC
        ');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('Error fetching groups: ' . $e->getMessage());
        return ['default'];
    }
}