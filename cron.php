<?php
/**
 * HolderBot PHP - Background Task Runner for cPanel Cron Jobs
 *
 * Runs periodic maintenance:
 *   1. Monitors node health and auto-restarts failed nodes
 *   2. Alerts admins on node disconnects
 *   3. Daily expired accounts summary to Telegram admins
 *
 * Setup in cPanel -> Cron Jobs:
 *   * * * * * php /home/youruser/public_html/holderbot-php/cron.php > /dev/null 2>&1
 */

declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
    die("config.php not found.\n");
}

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

require_once __DIR__ . '/tgbot.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/panels/panel_manager.php';
require_once __DIR__ . '/helpers/format.php';

Storage::init();

$servers = Storage::getServers();
$admins = $config['admin_ids'] ?? [];

echo "[" . date('Y-m-d H:i:s') . "] Starting HolderBot background health checks...\n";

// =============================================================================
// Task 1: Node Monitoring & Auto-Restart
// =============================================================================
foreach ($servers as $server) {
    // Gated on node_monitoring only - the original bot's monitoring job has no
    // is_active check at all.
    if (empty($server['node_monitoring'])) {
        continue;
    }

    echo "Checking nodes for server: {$server['remark']} ({$server['type']})...\n";

    $nodes = PanelManager::getNodes($server);
    if (empty($nodes)) {
        continue;
    }

    $failedNodes = [];
    foreach ($nodes as $node) {
        $status = strtolower($node['status'] ?? 'unknown');
        $isOk = PanelManager::isNodeOk($node);

        if (!$isOk) {
            $nodeRemark = $node['remark'] ?? ($node['name'] ?? 'Node #' . ($node['id'] ?? ''));
            $nodeAddress = $node['address'] ?? ($node['ip'] ?? '');
            $failedNodes[] = [
                'id' => $node['id'] ?? 0,
                'remark' => $nodeRemark,
                'address' => $nodeAddress,
                'status' => $status,
                'message' => $node['message'] ?? 'Node disconnected',
            ];

            if (!empty($server['node_restart']) && !empty($node['id'])) {
                PanelManager::restartNode($server, (int)$node['id']);
                echo "  -> Restarted node {$node['id']}\n";
            }
        }
    }

    if (!empty($failedNodes)) {
        $alertText = "🚨 <b>Node Failure Alert!</b>\n";
        $alertText .= "<b>Server:</b> <code>" . htmlspecialchars($server['remark']) . "</code>\n\n";

        foreach ($failedNodes as $fn) {
            $alertText .= "➖➖➖➖➖\n";
            $alertText .= "• <b>Node:</b> <code>" . htmlspecialchars($fn['remark']) . "</code>\n";
            if (!empty($fn['address'])) {
                $alertText .= "• <b>Address:</b> <code>" . htmlspecialchars($fn['address']) . "</code>\n";
            }
            $alertText .= "• <b>Status:</b> <code>" . htmlspecialchars($fn['status']) . "</code>\n";
            $alertText .= "• <b>Details:</b> <code>" . htmlspecialchars($fn['message']) . "</code>\n";
            if (!empty($server['node_restart'])) {
                $alertText .= "• <b>Auto-Restart:</b> <code>Dispatched ✔️</code>\n";
            }
        }

        foreach ($admins as $adminId) {
            tg_send_message($adminId, $alertText);
        }
    }
}

// =============================================================================
// Task 2: Daily Expired Users Summary
// =============================================================================
// Fires once per calendar day, at or after 06:00 local time (matching the
// original bot's fixed daily 06:00 trigger as closely as a cron-driven,
// no-persistent-process script can) - or immediately if forced.
$lastExpiredRunDate = Storage::cacheGet('last_expired_check_date');
$now = time();
$today = date('Y-m-d', $now);
$hour = (int)date('G', $now);

$forceExpired = (isset($argv[1]) && $argv[1] === '--expired') || (isset($_GET['task']) && $_GET['task'] === 'expired');

if ($forceExpired || ($hour >= 6 && $lastExpiredRunDate !== $today)) {
    echo "Running daily expiring users report...\n";
    Storage::cacheSet('last_expired_check_date', $today, 86400 * 2);

    $botInfo = tgbot('getMe');
    $botUsername = $botInfo['result']['username'] ?? '';

    foreach ($servers as $server) {
        // Gated on reachability (isOnline), not the admin-set is_active flag -
        // matches the original bot's server.is_online gate for this task.
        if (!PanelManager::isOnline($server) || empty($server['expired_stats'])) {
            continue;
        }

        $size = PanelManager::pageSize($server);
        $page = 1;
        $total = 0;
        $todayExpired = [];
        while (true) {
            $users = PanelManager::getUsers($server, $page, $size);
            if (empty($users)) break;
            foreach ($users as $u) {
                $total++;
                $exp = $u['expire_timestamp'] ?? 0;
                // Scheduled to expire today: NOT YET expired, due within 24h -
                // matches Python's last_expired_hour (hours remaining, None if
                // already past due).
                $hoursUntilExpiry = $exp > 0 ? ($exp - $now) / 3600.0 : -1;
                if ($hoursUntilExpiry > 0 && $hoursUntilExpiry < 24) {
                    $todayExpired[] = !empty($botUsername)
                        ? "<a href='https://t.me/{$botUsername}?start=user_{$server['id']}_{$u['username']}'><code>{$u['username']}</code></a>"
                        : "<code>{$u['username']}</code>";
                }
            }
            if (count($users) < $size) break;
            $page++;
        }

        // Sent unconditionally (even with zero matches), matching the original
        // bot's daily heartbeat behavior.
        $count = count($todayExpired);
        $expiredList = $count > 0 ? implode(', ', $todayExpired) : '<code>None</code>';
        $text = "📊 <b>Users scheduled to expire today in " . htmlspecialchars(ucwords($server['remark'])) . " server:</b>\n";
        $text .= "⚰️ <b>List of users[{$count}/{$total}]:</b> {$expiredList}";

        foreach ($admins as $adminId) {
            tg_send_message($adminId, $text);
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Health checks complete.\n";
