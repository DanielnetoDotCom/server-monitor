<?php
/**
 * Dashboard - Server monitoring overview
 * 
 * Displays aggregated health data from all monitored servers
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Health.php';

// Get latest report for each server
try {
    $stmt = $pdo->query('
        SELECT r.* 
        FROM reports r
        INNER JOIN (
            SELECT server_id, MAX(ts) as max_ts
            FROM reports
            GROUP BY server_id
        ) latest ON r.server_id = latest.server_id AND r.ts = latest.max_ts
        ORDER BY r.hostname ASC
    ');
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get external port data for each server
    $externalPorts = [];
    if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK) {
        foreach ($servers as $server) {
            $stmt = $pdo->prepare('
                SELECT port, is_open, service_name, last_checked
                FROM external_ports 
                WHERE server_id = ?
                ORDER BY port ASC
            ');
            $stmt->execute([$server['server_id']]);
            $externalPorts[$server['server_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log('Dashboard query error: ' . $e->getMessage());
    $servers = [];
    $externalPorts = [];
}

$currentTime = now();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(SITE_TITLE); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .updated-time {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        th {
            background-color: #343a40;
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 50px;
        }
        
        .status-cell {
            font-weight: 600;
        }
        
        .time-cell {
            font-family: monospace;
            font-size: 13px;
            color: #6c757d;
        }
        
        .no-servers {
            padding: 40px;
            text-align: center;
            color: #6c757d;
            font-size: 16px;
        }
        
        .summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-width: 150px;
            text-align: center;
        }
        
        .summary-number {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #6c757d;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 0 10px;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px 6px;
            }
            
            .summary {
                justify-content: center;
            }
            
            .summary-card {
                flex: 1;
                min-width: 120px;
            }
        }
        
        .refresh-note {
            margin-top: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            border-radius: 4px;
            font-size: 14px;
            color: #1565c0;
        }
        
        .external-ports-cell {
            white-space: nowrap;
        }
        
        .port-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        
        .port-badge {
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }
        
        .port-badge small {
            font-size: 10px;
            font-weight: bold;
        }
    </style>
    <script>
        // Auto-refresh every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</head>
<body>
    <div class="container">
        <h1><?php echo h(SITE_TITLE); ?></h1>
        <div class="updated-time">Updated at: <?php echo format_time($currentTime); ?></div>
        
        <?php
        // Calculate summary statistics
        $totalServers = count($servers);
        $normalCount = 0;
        $warningCount = 0;
        $criticalCount = 0;
        $problemCount = 0;
        $staleCount = 0;
        
        foreach ($servers as $server) {
            $status = Health::statusLabelForRow($server);
            switch ($status) {
                case 'Normal':
                    $normalCount++;
                    break;
                case 'Attention':
                    $warningCount++;
                    break;
                case 'Critical':
                    $criticalCount++;
                    break;
                case 'Problem':
                    $problemCount++;
                    break;
                case 'No signal':
                    $staleCount++;
                    break;
            }
        }
        ?>
        
        <div class="summary">
            <div class="summary-card">
                <div class="summary-number" style="color: #28a745;"><?php echo $normalCount; ?></div>
                <div class="summary-label">Normal</div>
            </div>
            <div class="summary-card">
                <div class="summary-number" style="color: #ffc107;"><?php echo $warningCount; ?></div>
                <div class="summary-label">Attention</div>
            </div>
            <div class="summary-card">
                <div class="summary-number" style="color: #dc3545;"><?php echo $criticalCount; ?></div>
                <div class="summary-label">Critical</div>
            </div>
            <div class="summary-card">
                <div class="summary-number" style="color: #dc3545;"><?php echo $problemCount; ?></div>
                <div class="summary-label">Problem</div>
            </div>
            <div class="summary-card">
                <div class="summary-number" style="color: #6c757d;"><?php echo $staleCount; ?></div>
                <div class="summary-label">No Signal</div>
            </div>
        </div>
        
        <div class="table-container">
            <?php if (empty($servers)): ?>
                <div class="no-servers">
                    No servers are currently being monitored.<br>
                    Deploy the monitoring agent to your servers to see data here.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>Disk Usage</th>
                            <th>Apache</th>
                            <th>MySQL</th>
                            <?php if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK): ?>
                            <th>External Ports</th>
                            <?php endif; ?>
                            <th>Last Report</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servers as $server): ?>
                            <?php $status = Health::statusLabelForRow($server); ?>
                            <tr>
                                <td><strong><?php echo h($server['hostname']); ?></strong></td>
                                <td><?php echo Health::diskBadge($server['disk_pct']); ?></td>
                                <td><?php echo Health::serviceBadge((bool)$server['apache_ok']); ?></td>
                                <td><?php echo Health::serviceBadge((bool)$server['mysql_ok']); ?></td>
                                <?php if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK): ?>
                                <td class="external-ports-cell">
                                    <?php 
                                    $serverPorts = $externalPorts[$server['server_id']] ?? [];
                                    if (empty($serverPorts)): ?>
                                        <span style="color: #6c757d; font-size: 12px;">Not checked</span>
                                    <?php else: ?>
                                        <div class="port-badges">
                                        <?php foreach ($serverPorts as $portData): ?>
                                            <span class="port-badge" title="<?php echo h($portData['service_name']); ?> (<?php echo h($portData['port']); ?>)">
                                                <?php echo Health::externalPortBadge($portData); ?>
                                                <small><?php echo h($portData['port']); ?></small>
                                            </span>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td class="time-cell">
                                    <?php if (is_stale($server['ts'])): ?>
                                        <span style="color: #dc3545;"><?php echo format_time($server['ts']); ?></span>
                                    <?php else: ?>
                                        <?php echo format_time($server['ts']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="status-cell">
                                    <?php echo Health::badge($status, Health::getStatusColor($status)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div class="refresh-note">
            <strong>Note:</strong> This page automatically refreshes every 60 seconds. 
            Servers are considered "stale" if they haven't reported in the last <?php echo STALE_MINUTES; ?> minutes.
        </div>
    </div>
</body>
</html>