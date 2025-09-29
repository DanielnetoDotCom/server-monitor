<?php
/**
 * Server Monitor Configuration
 * 
 * All settings for the monitoring system.
 * 
 * =======================================================
 * REQUIRED SETTINGS - MUST BE CHANGED FOR PRODUCTION
 * =======================================================
 */

// Security - CHANGE THIS SECRET KEY!
define('MONITOR_SECRET', 'CHANGE_ME_TO_A_STRONG_SECRET_KEY');

// Email settings - CHANGE THESE EMAIL ADDRESSES!
define('ALERT_FROM', 'monitor@yoursite.com');
define('ALERT_TO', 'admin@yoursite.com');

// Site branding
define('CLIENT_URL', 'https://yourdomain.com');
define('SITE_TITLE', 'Server Monitor Dashboard');

/**
 * =======================================================
 * PORT MONITORING CONFIGURATION
 * =======================================================
 */

// External port checking
define('ENABLE_EXTERNAL_PORT_CHECK', true);  // Enable/disable external port checking

/**
 * =======================================================
 * OPTIONAL SETTINGS - ADJUST AS NEEDED
 * =======================================================
 */

// Disk usage thresholds (percentage)
define('DISK_WARN_PCT', 85);    // Warning threshold
define('DISK_CRIT_PCT', 95);    // Critical threshold

// Monitoring timing settings
define('STALE_MINUTES', 5);      // Minutes after which a server is considered "stale"
define('ALERT_COOLDOWN_M', 60);  // Minutes between alerts for same server/kind/level

// Health check timeouts
define('APACHE_CHECK_TIMEOUT', 2);    // seconds
define('MYSQL_TIMEOUT', 2);           // seconds
define('EXTERNAL_PORT_TIMEOUT', 10);  // seconds - Timeout for external port checks

// Database location
define('DB_FILE', __DIR__ . '/monitor.sqlite');

// Alert system configuration
define('ALERT_DRIVER', 'mail');  // 'mail' or 'smtp' (requires additional setup)