# Server Monitor

A lightweight, production-ready server monitoring system with a centralized dashboard and distributed agents. Monitors disk usage, Apache, and MySQL health across multiple servers with email alerts.

## Features

- **Centralized Dashboard**: Web-based overview of all monitored servers
- **Push-based Monitoring**: Lightweight PHP CLI agents report to central endpoint
- **Cross-Platform**: Works on Linux, Unix, and Windows systems with automatic OS detection

- **Email Alerts**: Configurable thresholds with cooldown periods
- **Zero Dependencies**: Uses only PHP, SQLite, and standard system tools
- **Production Ready**: Proper error handling, security, and performance considerations

## Requirements

- **Server (Dashboard)**:
  - PHP 8.1+ with SQLite extension (php-sqlite3)
  - Web server (Apache/Nginx)
  - Mail configuration (mail() function or SMTP)

- **Monitored Servers (Agents)**:
  - PHP CLI (7.4+)
  - curl extension or curl command
  - shell_exec enabled
  - Task scheduler (Linux: cron, Windows: Task Scheduler)
  - **Cross-Platform**: Works on Linux, Unix, and Windows systems

## Installation

### 1. Server Setup (Dashboard)

1. **Deploy Files**:
   ```bash
   # Copy the monitor/ directory to your web server
   cp -r monitor/ /var/www/html/monitor/
   ```

2. **Configure Settings**:
   Edit `config.php` and update the **REQUIRED SETTINGS** section:
   ```php
   // REQUIRED - Change these values:
   define('MONITOR_SECRET', 'your-strong-secret-key-here');
   define('ALERT_FROM', 'monitor@yourdomain.com');
   define('ALERT_TO', 'admin@yourdomain.com');
   define('SITE_TITLE', 'Your Server Monitor');
   
   ```

3. **Set Permissions**:
   ```bash
   # Make sure web server can write to the directory
   chown -R www-data:www-data /var/www/html/monitor/
   chmod 755 /var/www/html/monitor/
   ```

4. **Test Installation**:
   - Visit `https://yourdomain.com/monitor/` to see the dashboard
   - The database will be created automatically on first access

### 2. Agent Setup (Each Monitored Server)

The agent is cross-platform and works on Linux, Unix, and Windows systems.

#### Linux/Unix Installation

1. **Install Agent**:
   ```bash
   # Copy agent script
   sudo cp monitor/tools/agent.php /usr/local/bin/monitor_agent.php
   sudo chmod +x /usr/local/bin/monitor_agent.php
   ```

2. **Configure Agent**:
   ```bash
   sudo nano /usr/local/bin/monitor_agent.php
   ```
   
   Update these lines:
   ```php
   $SECRET = "your-strong-secret-key-here";  // Same as config.php
   ```

3. **Setup Cron Job**:
   ```bash
   # Edit crontab
   sudo crontab -e
   
   # Add this line to run every minute:
   * * * * * /usr/local/bin/monitor_agent.php
   ```

#### Windows Installation

1. **Install Agent**:
   ```cmd
   # Copy agent script to a suitable location
   copy monitor\tools\agent.php C:\Scripts\monitor_agent.php
   ```

2. **Configure Agent**:
   Edit `C:\Scripts\monitor_agent.php` and update the same configuration lines as above.

3. **Setup Task Scheduler**:
   ```cmd
   # Create a scheduled task to run every minute
   schtasks /create /tn "ServerMonitor" /tr "php C:\Scripts\monitor_agent.php" /sc minute /mo 1
   ```
   
   Or use Windows Task Scheduler GUI:
   - Open Task Scheduler
   - Create Basic Task → "Server Monitor"
   - Trigger: Daily, repeat every 1 minute
   - Action: Start a program → `php.exe` with arguments `C:\Scripts\monitor_agent.php`

#### Test Agent (All Platforms)

```bash
# Linux/Unix
/usr/local/bin/monitor_agent.php

# Windows
php C:\Scripts\monitor_agent.php

# Check if data appears in dashboard within a minute
```

## Configuration

### Alert Thresholds

In `config.php`:

- `DISK_WARN_PCT`: Warning threshold for disk usage (default: 85%)
- `DISK_CRIT_PCT`: Critical threshold for disk usage (default: 95%)
- `STALE_MINUTES`: Minutes before server shows "No signal" (default: 5)
- `ALERT_COOLDOWN_M`: Minutes between duplicate alerts (default: 60)



### Email Settings

Default uses PHP's `mail()` function. To use SMTP:

1. Install PHPMailer or similar
2. Modify `lib/Alerts.php::send_alert()` method
3. Change `ALERT_DRIVER` in config.php to 'smtp'

### Security

- **Use HTTPS**: Always deploy over HTTPS in production
- **Strong Secret**: Use a long, random string for `MONITOR_SECRET`
- **IP Restrictions**: Consider restricting `ingest.php` to known server IPs
- **File Permissions**: Ensure SQLite database is not web-accessible

## API Reference

### Ingest Endpoint

**POST** `/monitor/ingest.php`

**Headers**:
```
Content-Type: application/json
```

**Payload**:
```json
{
    "secret": "your-secret-key",
    "server_id": "web1-192.168.1.10",
    "hostname": "web1.example.com",
    "ip": "192.168.1.10",
    "disk_pct": 45,
    "apache_ok": 1,
    "mysql_ok": 1,
    "time": 1640995200
}
```

**Responses**:
- `200 ok`: Data accepted successfully
- `400`: Invalid request data  
- `403`: Invalid secret
- `405`: Wrong HTTP method
- `413`: Payload too large (>10KB)
- `415`: Wrong content type
- `429`: Rate limit exceeded
- `500`: Server error

**Headers**:
```
Content-Type: application/json
```

**Payload**:
```json
{
    "secret": "your-secret-key",
    "server_id": "web1-192.168.1.10", 
    "hostname": "web1.example.com",
    "ip": "192.168.1.10",
    "ports": [80, 443, 8080, 8443, 2053, 1935],
    "time": 1640995200
}
```

**Responses**:
- `200 ok`: Port checks completed successfully
- `400`: Invalid request data
- `403`: Invalid secret
- `405`: Wrong HTTP method
- `413`: Payload too large (>5KB)
- `415`: Wrong content type
- `429`: Rate limit exceeded (10 req/min)
- `500`: Server error


## Security Features

The monitoring system includes multiple layers of security protection:

### Authentication & Authorization
- **Secret-based Authentication**: Shared secret key prevents unauthorized data submission
- **Timing Attack Protection**: Uses `hash_equals()` for secure secret comparison
- **Method Restriction**: Only accepts POST requests

### Input Validation & Sanitization
- **JSON Schema Validation**: Strict validation of all required fields
- **Data Type Enforcement**: Type casting and format validation
- **Input Size Limits**: 10KB maximum payload size to prevent DoS
- **Content-Type Validation**: Only accepts `application/json`
- **Regular Expression Filtering**: Server IDs, hostnames, and IPs are validated with regex
- **SQL Injection Prevention**: Uses prepared statements with parameter binding

### Rate Limiting & DoS Protection
- **IP-based Rate Limiting**: Maximum 60 requests per minute per IP
- **Payload Size Limits**: Prevents large data attacks
- **Automatic Cleanup**: Old rate limit files are automatically purged

### Security Headers
- **Content-Type Options**: `X-Content-Type-Options: nosniff`
- **Frame Options**: `X-Frame-Options: DENY` 
- **XSS Protection**: `X-XSS-Protection: 1; mode=block`

### Database Security
- **SQLite with Prepared Statements**: Prevents SQL injection
- **Error Mode Exception**: Proper error handling without information disclosure
- **File Permissions**: Database file should not be web-accessible

### Recommended Security Practices
1. **Use HTTPS**: Always deploy over HTTPS in production
2. **Strong Secrets**: Use long, random strings for `MONITOR_SECRET`
3. **IP Restrictions**: Consider restricting `ingest.php` to known server IPs
4. **File Permissions**: Ensure SQLite database is not web-accessible
5. **Regular Updates**: Keep PHP and web server updated
6. **Log Monitoring**: Monitor error logs for suspicious activity

## Testing

### Test Ingest Endpoint

```bash
# Replace values with your configuration
curl -X POST https://yourdomain.com/monitor/ingest.php \
  -H "Content-Type: application/json" \
  -d '{
    "secret": "your-secret-key",
    "server_id": "test-server",
    "hostname": "test.example.com",
    "ip": "192.168.1.100",
    "disk_pct": 95,
    "apache_ok": 0,
    "mysql_ok": 1,
    "time": '$(date +%s)'
  }'
```

### Test Agent

```bash
# Run agent manually 
php /usr/local/bin/monitor_agent.php

# For debugging, add error reporting
php -d display_errors=1 /usr/local/bin/monitor_agent.php
```

### Verify Database

```bash
# Check SQLite database (if accessible)
sqlite3 /var/www/html/monitor/monitor.sqlite "SELECT * FROM reports ORDER BY ts DESC LIMIT 5;"
```

## Troubleshooting

### Common Issues

1. **No data in dashboard**:
   - Check agent cron job: `sudo crontab -l`
   - Test agent manually: `/usr/local/bin/monitor_agent.php`
   - Check web server error logs
   - Ensure PHP CLI is installed: `php --version`

2. **Database errors**:
   - Ensure php-sqlite3 extension is installed
   - Check file permissions on monitor directory
   - Verify web server can write to directory

3. **Email alerts not working**:
   - Test PHP mail() function: `php -r "mail('test@example.com', 'Test', 'Test');"`
   - Check mail server logs
   - Verify ALERT_FROM and ALERT_TO in config.php

4. **Permission denied errors**:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/monitor/
   sudo chmod -R 755 /var/www/html/monitor/
   ```

5. **Agent not reporting**:
   - Check PHP CLI: `php --version`
   - Check if curl extension is loaded: `php -m | grep curl`
   - Test network connectivity: `curl -I https://yourdomain.com`
   - Check cron service: `systemctl status cron`
   - Verify shell_exec is enabled: `php -r "echo shell_exec('echo test') ? 'OK' : 'Disabled';"`

### Log Files

- Web server error log (usually `/var/log/apache2/error.log` or `/var/log/nginx/error.log`)
- System mail log (`/var/log/mail.log`)
- Cron log (`/var/log/cron.log` or `journalctl -u cron`)

## File Structure

```
monitor/
├── config.php              # Configuration settings
├── bootstrap.php           # Database initialization
├── ingest.php              # API endpoint for agents
├── index.php               # Dashboard interface
├── monitor.sqlite          # SQLite database (created automatically)
├── lib/
│   ├── Alerts.php          # Alert management
│   └── Health.php          # Health status helpers
└── tools/
    ├── agent.php           # PHP monitoring agent script  
    └── agent.sh            # Legacy bash agent (deprecated)
```

## Database Schema

### reports
- `id`: Primary key
- `server_id`: Unique server identifier
- `hostname`: Server hostname
- `ip`: Server IP address
- `disk_pct`: Disk usage percentage
- `apache_ok`: Apache status (1=OK, 0=DOWN)
- `mysql_ok`: MySQL status (1=OK, 0=DOWN)
- `ts`: Unix timestamp

### alerts
- `id`: Primary key
- `server_id`: Server identifier
- `kind`: Alert type (disk, apache, mysql)
- `level`: Alert level (warn, crit, down)
- `last_sent`: Last alert timestamp



## License

This project is provided as-is for production use. Modify as needed for your environment.