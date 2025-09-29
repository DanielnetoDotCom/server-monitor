<?php

/**
 * Health status helpers
 * 
 * Functions for determining and displaying server health status
 */

class Health
{

    // Service names for common ports
    public static $PORT_SERVICES = [
        80   => 'HTTP',
        443  => 'HTTPS',
        8080 => 'HTTP Nginx',
        8443 => 'HTTPS Nginx',
        2053 => 'Sockets',
        1935 => 'RTMP',
    ];

    /**
     * Generate a colored badge for display
     * 
     * @param string $text Badge text
     * @param string $hexColor Hex color code (without #)
     * @return string HTML for badge
     */
    public static function badge(string $text, string $hexColor): string
    {
        return sprintf(
            '<span class="badge" style="background-color: #%s; color: %s;">%s</span>',
            $hexColor,
            self::getContrastColor($hexColor),
            h($text)
        );
    }

    /**
     * Get overall status label for a server row
     * 
     * Priority: stale > problem (services down) > disk critical > disk warning > normal
     * 
     * @param array $row Database row with server data
     * @return string Status label
     */
    public static function statusLabelForRow(array $row): string
    {
        // Check if data is stale
        if (is_stale($row['ts'])) {
            return 'No signal';
        }

        // Check for service problems
        if ($row['apache_ok'] == 0 || $row['mysql_ok'] == 0) {
            return 'Problem';
        }

        // Check disk usage
        if ($row['disk_pct'] >= DISK_CRIT_PCT) {
            return 'Critical';
        }

        if ($row['disk_pct'] >= DISK_WARN_PCT) {
            return 'Attention';
        }

        return 'Normal';
    }

    /**
     * Get status color based on label
     * 
     * @param string $status Status label
     * @return string Hex color code
     */
    public static function getStatusColor(string $status): string
    {
        switch ($status) {
            case 'Normal':
                return '28a745';  // Green
            case 'Attention':
                return 'ffc107';  // Yellow
            case 'Critical':
                return 'dc3545';  // Red
            case 'Problem':
                return 'dc3545';  // Red
            case 'No signal':
                return '6c757d';  // Gray
            default:
                return '6c757d';  // Gray
        }
    }

    /**
     * Get disk usage badge
     * 
     * @param int $diskPct Disk usage percentage
     * @return string HTML badge
     */
    public static function diskBadge(int $diskPct): string
    {
        if ($diskPct >= DISK_CRIT_PCT) {
            $color = 'dc3545'; // Red
        } elseif ($diskPct >= DISK_WARN_PCT) {
            $color = 'ffc107'; // Yellow
        } else {
            $color = '28a745'; // Green
        }

        return self::badge($diskPct . '%', $color);
    }

    /**
     * Get service status badge
     * 
     * @param bool $isOk Service status (1 = OK, 0 = DOWN)
     * @param string $serviceName Service name for display
     * @return string HTML badge
     */
    public static function serviceBadge(bool $isOk, string $serviceName = ''): string
    {
        if ($isOk) {
            return self::badge('OK', '28a745'); // Green
        } else {
            return self::badge('DOWN', 'dc3545'); // Red
        }
    }

    /**
     * Determine text color based on background color for contrast
     * 
     * @param string $hexColor Background color (without #)
     * @return string 'white' or 'black'
     */
    private static function getContrastColor(string $hexColor): string
    {
        // Convert hex to RGB
        $r = hexdec(substr($hexColor, 0, 2));
        $g = hexdec(substr($hexColor, 2, 2));
        $b = hexdec(substr($hexColor, 4, 2));

        // Calculate luminance
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? 'black' : 'white';
    }

    /**
     * Check if a port is externally accessible via external service
     * 
     * @param string $host The hostname or IP to check
     * @param int $port The port number to check
     * @param int $timeout Timeout in seconds (default: 10)
     * @return array Result with status and response data
     */
    public static function isPortOpenExternal(string $host, int $port): array
    {
        $postURL = 'https://search.ypt.me/checkPorts.json.php';
        $postURL = self::addQueryParam($postURL, 'host', $host);
        $response = self::executeCurl($postURL, ['port' => $port]);
        $result = [
            'isOpen' => false,
            'port' => $port,
            'host' => $host,
            'service' => self::$PORT_SERVICES[$port] ?? 'Port ' . $port,
            'response' => $response,
            'error' => null
        ];

        if (!empty($response)) {
            $json = json_decode($response['response'], true);
            if ($json === null) {
                $result['error'] = 'Invalid JSON response: ' . substr($response['response'], 0, 200);
            } elseif (!isset($json['ports'])) {
                $result['error'] = 'Missing ports array in response: ' . json_encode($json);
            } elseif (!isset($json['ports'][0])) {
                $result['error'] = 'Empty ports array in response: ' . json_encode($json);
            } elseif (!isset($json['ports'][0]['isOpen'])) {
                $result['error'] = 'Missing isOpen field in response: ' . json_encode($json['ports'][0]);
            } else {
                $result['isOpen'] = (bool)$json['ports'][0]['isOpen'];
                $result['response'] = $json;
                return $result;
            }
        } else {
            $result['error'] = 'No response from external service';
        }

        error_log("External port check failed for {$host}:{$port} - " . ($result['error'] ?? 'Unknown error'));
        return $result;
    }


    /**
     * Get a badge for external port status
     * 
     * @param array $portData Database row from external_ports table
     * @return string HTML badge
     */
    public static function externalPortBadge(array $portData): string
    {
        $isOpen = isset($portData['isOpen']) ? $portData['isOpen'] : $portData['is_open'];
        if ($isOpen) {
            return self::badge('OPEN', '28a745'); // Green
        } else {
            return self::badge('CLOSED', 'dc3545'); // Red
        }
    }

    /**
     * Add query parameter to URL
     * 
     * @param string $url Base URL
     * @param string $param Parameter name
     * @param string $value Parameter value
     * @return string URL with added parameter
     */
    private static function addQueryParam(string $url, string $param, string $value): string
    {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . urlencode($param) . '=' . urlencode($value);
    }


    /**
     * Execute cURL request with common settings
     */
    static function executeCurl(string $url, array $data, int $timeout = 10): array
    {
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
}
