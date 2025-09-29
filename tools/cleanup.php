#!/usr/bin/env php
<?php
/**
 * Database Cleanup Script
 * 
 * Manually clean up old database records to prevent database bloat.
 * Can be run manually or scheduled via cron job.
 * 
 * Usage:
 *   php cleanup.php                    # Use default retention periods
 *   php cleanup.php --data-days=14     # Keep 14 days of data
 *   php cleanup.php --alert-days=60    # Keep 60 days of alert records
 *   php cleanup.php --dry-run          # Show what would be deleted without deleting
 *   php cleanup.php --force            # Force cleanup regardless of last run time
 */

require_once dirname(__DIR__) . '/bootstrap.php';

// Parse command line arguments
$options = getopt('', ['data-days:', 'alert-days:', 'dry-run', 'force', 'help']);

if (isset($options['help'])) {
    echo "Database Cleanup Script\n";
    echo "======================\n\n";
    echo "Usage: php " . basename(__FILE__) . " [options]\n\n";
    echo "Options:\n";
    echo "  --data-days=N     Days to keep monitoring data (default: " . (defined('DATA_RETENTION_DAYS') ? DATA_RETENTION_DAYS : 7) . ")\n";
    echo "  --alert-days=N    Days to keep alert records (default: " . (defined('ALERT_RETENTION_DAYS') ? ALERT_RETENTION_DAYS : 30) . ")\n";
    echo "  --dry-run         Show what would be deleted without actually deleting\n";
    echo "  --force           Force cleanup regardless of last run time\n";
    echo "  --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php cleanup.php                      # Use default settings\n";
    echo "  php cleanup.php --data-days=14       # Keep 14 days of data\n";
    echo "  php cleanup.php --dry-run            # Preview what will be deleted\n";
    exit(0);
}

// Get retention periods
$dataRetentionDays = isset($options['data-days']) ? (int)$options['data-days'] : (defined('DATA_RETENTION_DAYS') ? DATA_RETENTION_DAYS : 7);
$alertRetentionDays = isset($options['alert-days']) ? (int)$options['alert-days'] : (defined('ALERT_RETENTION_DAYS') ? ALERT_RETENTION_DAYS : 30);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

// Validate inputs
if ($dataRetentionDays < 1 || $alertRetentionDays < 1) {
    echo "Error: Retention days must be at least 1\n";
    exit(1);
}

if ($dataRetentionDays > $alertRetentionDays) {
    echo "Warning: Data retention period ($dataRetentionDays days) is longer than alert retention period ($alertRetentionDays days)\n";
    echo "This may cause issues with alert cooldown functionality.\n";
    echo "Consider setting alert retention to be longer than data retention.\n\n";
}

echo "Database Cleanup Script\n";
echo "======================\n";
echo "Data retention: $dataRetentionDays days\n";
echo "Alert retention: $alertRetentionDays days\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE (will delete records)") . "\n\n";

// Check if cleanup was run recently (unless forced)
$lastCleanupFile = sys_get_temp_dir() . '/monitor_last_cleanup';
if (!$force && file_exists($lastCleanupFile)) {
    $lastCleanup = (int)file_get_contents($lastCleanupFile);
    $timeSinceLastCleanup = time() - $lastCleanup;
    
    if ($timeSinceLastCleanup < 3600) { // Less than 1 hour ago
        $minutesAgo = round($timeSinceLastCleanup / 60);
        echo "Cleanup was run $minutesAgo minutes ago. Use --force to run again.\n";
        exit(0);
    }
}

try {
    $currentTime = now();
    $dataCutoffTime = $currentTime - ($dataRetentionDays * 24 * 60 * 60);
    $alertCutoffTime = $currentTime - ($alertRetentionDays * 24 * 60 * 60);
    
    echo "Current time: " . date('Y-m-d H:i:s', $currentTime) . "\n";
    echo "Data cutoff:  " . date('Y-m-d H:i:s', $dataCutoffTime) . " (older records will be deleted)\n";
    echo "Alert cutoff: " . date('Y-m-d H:i:s', $alertCutoffTime) . " (older records will be deleted)\n\n";
    
    // Count records that will be affected
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE ts < ?');
    $stmt->execute([$dataCutoffTime]);
    $oldReports = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM external_ports WHERE last_checked < ?');
    $stmt->execute([$dataCutoffTime]);
    $oldPorts = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM alerts WHERE last_sent < ?');
    $stmt->execute([$alertCutoffTime]);
    $oldAlerts = $stmt->fetchColumn();
    
    echo "Records to be deleted:\n";
    echo "  Reports: $oldReports\n";
    echo "  External ports: $oldPorts\n"; 
    echo "  Alerts: $oldAlerts\n";
    echo "  Total: " . ($oldReports + $oldPorts + $oldAlerts) . "\n\n";
    
    if ($oldReports === 0 && $oldPorts === 0 && $oldAlerts === 0) {
        echo "No old records found. Database is already clean!\n";
        exit(0);
    }
    
    if ($dryRun) {
        echo "DRY RUN complete. No records were deleted.\n";
        echo "Run without --dry-run to actually delete these records.\n";
        exit(0);
    }
    
    // Confirm deletion
    echo "Are you sure you want to delete these records? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'yes') {
        echo "Cleanup cancelled.\n";
        exit(0);
    }
    
    echo "\nDeleting old records...\n";
    
    // Delete old reports
    if ($oldReports > 0) {
        $stmt = $pdo->prepare('DELETE FROM reports WHERE ts < ?');
        $stmt->execute([$dataCutoffTime]);
        echo "✓ Deleted $oldReports old report records\n";
    }
    
    // Delete old external port data
    if ($oldPorts > 0) {
        $stmt = $pdo->prepare('DELETE FROM external_ports WHERE last_checked < ?');
        $stmt->execute([$dataCutoffTime]);
        echo "✓ Deleted $oldPorts old external port records\n";
    }
    
    // Delete old alert records
    if ($oldAlerts > 0) {
        $stmt = $pdo->prepare('DELETE FROM alerts WHERE last_sent < ?');
        $stmt->execute([$alertCutoffTime]);
        echo "✓ Deleted $oldAlerts old alert records\n";
    }
    
    // Optimize database
    echo "\nOptimizing database (VACUUM)...\n";
    $pdo->exec('VACUUM');
    echo "✓ Database optimized\n";
    
    // Record cleanup time
    file_put_contents($lastCleanupFile, time(), LOCK_EX);
    
    echo "\nCleanup completed successfully!\n";
    
    // Show database file size
    if (file_exists(DB_FILE)) {
        $size = filesize(DB_FILE);
        $sizeFormatted = $size < 1024 * 1024 ? 
            round($size / 1024, 1) . ' KB' : 
            round($size / (1024 * 1024), 1) . ' MB';
        echo "Database file size: $sizeFormatted\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}