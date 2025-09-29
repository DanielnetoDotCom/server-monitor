<?php

/**
 * Dashboard - Server monitoring overview
 * 
 * Displays aggregated health data from all monitored servers
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Health.php';

// Get current group
$currentGroup = get_group_name();
$availableGroups = get_available_groups();

// Determine if we should show all groups or just the current one
$showAllGroups = (!isset($_GET['group']) && $currentGroup === 'default');

if (isset($_GET['group']) && $_GET['group'] === 'index') {
    $showAllGroups = true;
    $_GET['group'] = $currentGroup = 'default';
}

// Get latest report for each server (filtered by group or all groups)
try {
    if ($showAllGroups) {
        // Show all groups when on main dashboard
        $stmt = $pdo->query('
            SELECT r.* 
            FROM reports r
            INNER JOIN (
                SELECT server_id, MAX(ts) as max_ts
                FROM reports
                GROUP BY server_id
            ) latest ON r.server_id = latest.server_id AND r.ts = latest.max_ts
            ORDER BY r.group_name ASC, r.hostname ASC
        ');
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Show only the current group
        $stmt = $pdo->prepare('
            SELECT r.* 
            FROM reports r
            INNER JOIN (
                SELECT server_id, MAX(ts) as max_ts
                FROM reports
                WHERE group_name = ?
                GROUP BY server_id
            ) latest ON r.server_id = latest.server_id AND r.ts = latest.max_ts
            WHERE r.group_name = ?
            ORDER BY r.group_name ASC, r.hostname ASC
        ');
        $stmt->execute([$currentGroup, $currentGroup]);
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get external port data for each server
    $externalPorts = [];
    if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK) {
        foreach ($servers as $server) {
            $stmt = $pdo->prepare('
                SELECT port, is_open, service_name, last_checked
                FROM external_ports 
                WHERE server_id = ? AND group_name = ?
                ORDER BY port ASC
            ');
            $stmt->execute([$server['server_id'], $server['group_name']]);
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .group-badge-default {
            background-color: #f3e5f5 !important;
            color: #7b1fa2 !important;
            border-color: #ce93d8 !important;
        }

        .group-badge-customer1 {
            background-color: #e8f5e8 !important;
            color: #2e7d32 !important;
            border-color: #a5d6a7 !important;
        }

        .group-badge-customer2 {
            background-color: #fff3e0 !important;
            color: #ef6c00 !important;
            border-color: #ffcc02 !important;
        }
    </style>
    <script>
        // Auto-refresh every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</head>

<body class="bg-light">
    <div class="container-xl py-4">
        <h1 class="h2 text-dark mb-2"><?php echo h(SITE_TITLE); ?></h1>

        <!-- Group Selector -->
        <?php if (count($availableGroups) > 1): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <label for="group-select" class="form-label fw-semibold text-secondary mb-0">Customer Group:</label>
                        <select id="group-select" class="form-select w-auto" onchange="window.location.href = this.value">
                            <?php foreach ($availableGroups as $group): ?>
                                <?php $isSelected = ($group === $currentGroup); ?>
                                <?php $url = SERVER_URL . (($group === 'default') ? '' : urlencode($group) . '/'); ?>
                                <option value="<?php echo h($url); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                    <?php echo h(ucfirst($group)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted small">Currently viewing: <strong class="text-primary"><?php echo h(ucfirst($currentGroup)); ?></strong></span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-muted small mb-3">Group: <strong class="text-primary"><?php echo h(ucfirst($currentGroup)); ?></strong></div>
        <?php endif; ?>

        <div class="text-muted small mb-3">Updated at: <?php echo format_time($currentTime); ?></div>

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

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-2">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="h4 text-success mb-1"><?php echo $normalCount; ?></div>
                        <div class="text-muted small">Normal</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="h4 text-warning mb-1"><?php echo $warningCount; ?></div>
                        <div class="text-muted small">Attention</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="h4 text-danger mb-1"><?php echo $criticalCount; ?></div>
                        <div class="text-muted small">Critical</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="h4 text-danger mb-1"><?php echo $problemCount; ?></div>
                        <div class="text-muted small">Problem</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="h4 text-secondary mb-1"><?php echo $staleCount; ?></div>
                        <div class="text-muted small">No Signal</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <?php if (empty($servers)): ?>
                <div class="card-body text-center py-5">
                    <div class="text-muted">
                        No servers are currently being monitored.<br>
                        Deploy the monitoring agent to your servers to see data here.
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-uppercase small fw-semibold">Group</th>
                                <th class="text-uppercase small fw-semibold">Hostname</th>
                                <th class="text-uppercase small fw-semibold">Disk Usage</th>
                                <th class="text-uppercase small fw-semibold">Apache</th>
                                <th class="text-uppercase small fw-semibold">MySQL</th>
                                <?php if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK): ?>
                                    <th class="text-uppercase small fw-semibold">External Ports</th>
                                <?php endif; ?>
                                <th class="text-uppercase small fw-semibold">Last Report</th>
                                <th class="text-uppercase small fw-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servers as $server): ?>
                                <?php $status = Health::statusLabelForRow($server); ?>
                                <tr>
                                    <td><span class="badge rounded-pill border group-badge-<?php echo h($server['group_name']); ?>"><?php echo h(ucfirst($server['group_name'])); ?></span></td>
                                    <td><strong><?php echo h($server['hostname']); ?></strong></td>
                                    <td><?php echo Health::diskBadge($server['disk_pct']); ?></td>
                                    <td><?php echo Health::serviceBadge((bool)$server['apache_ok']); ?></td>
                                    <td><?php echo Health::serviceBadge((bool)$server['mysql_ok']); ?></td>
                                    <?php if (defined('ENABLE_EXTERNAL_PORT_CHECK') && ENABLE_EXTERNAL_PORT_CHECK): ?>
                                        <td class="text-nowrap">
                                            <?php
                                            $serverPorts = $externalPorts[$server['server_id']] ?? [];
                                            if (empty($serverPorts)): ?>
                                                <span class="text-muted small">Not checked</span>
                                            <?php else: ?>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php foreach ($serverPorts as $portData): ?>
                                                        <span class="d-inline-flex align-items-center gap-1" title="<?php echo h($portData['service_name']); ?> (<?php echo h($portData['port']); ?>)">
                                                            <?php echo Health::externalPortBadge($portData); ?>
                                                            <small class="fw-bold"><?php echo h($portData['port']); ?></small>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="font-monospace small text-muted">
                                        <?php if (is_stale($server['ts'])): ?>
                                            <span class="text-danger"><?php echo format_time($server['ts']); ?></span>
                                        <?php else: ?>
                                            <?php echo format_time($server['ts']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold">
                                        <?php echo Health::badge($status, Health::getStatusColor($status)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-info mt-4">
            <strong>Note:</strong> This page automatically refreshes every 60 seconds.
            Servers are considered "stale" if they haven't reported in the last <?php echo STALE_MINUTES; ?> minutes.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>