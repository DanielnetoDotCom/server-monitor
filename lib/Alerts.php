<?php
/**
 * Alerts management class
 * 
 * Handles alert cooldown logic and email sending
 */

class Alerts {
    
    /**
     * Check if an alert can be sent based on cooldown period
     * 
     * @param PDO $db Database connection
     * @param string $serverId Server identifier
     * @param string $kind Alert kind (disk, apache, mysql)
     * @param string $level Alert level (warn, crit, down)
     * @param int $cooldownMinutes Cooldown period in minutes
     * @return bool True if alert can be sent
     */
    public static function maySend(PDO $db, string $serverId, string $kind, string $level, int $cooldownMinutes): bool {
        $cooldownSeconds = $cooldownMinutes * 60;
        $cutoffTime = now() - $cooldownSeconds;
        
        try {
            $stmt = $db->prepare('SELECT last_sent FROM alerts WHERE server_id = ? AND kind = ? AND level = ?');
            $stmt->execute([$serverId, $kind, $level]);
            $lastSent = $stmt->fetchColumn();
            
            if ($lastSent === false || $lastSent < $cutoffTime) {
                // Update or insert the alert record
                $stmt = $db->prepare('
                    INSERT OR REPLACE INTO alerts (server_id, kind, level, last_sent) 
                    VALUES (?, ?, ?, ?)
                ');
                $stmt->execute([$serverId, $kind, $level, now()]);
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log('Alert check error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send an alert email
     * 
     * @param string $subject Email subject
     * @param string $body Email body
     */
    public static function send_alert(string $subject, string $body): void {
        // TODO: To use SMTP/PHPMailer instead of mail(), implement here:
        // 1. Change ALERT_DRIVER in config.php to 'smtp'
        // 2. Add PHPMailer dependency
        // 3. Replace the mail() call below with PHPMailer code
        // 4. Configure SMTP settings in config.php
        
        if (ALERT_DRIVER === 'mail') {
            $headers = [
                'From: ' . ALERT_FROM,
                'Reply-To: ' . ALERT_FROM,
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: Server Monitor'
            ];
            
            $success = mail(ALERT_TO, $subject, $body, implode("\r\n", $headers));
            
            if (!$success) {
                error_log("Failed to send alert email: $subject");
            } else {
                error_log("Alert sent: $subject");
            }
        } else {
            // Placeholder for SMTP implementation
            error_log("SMTP not implemented yet. Alert: $subject");
        }
    }
}