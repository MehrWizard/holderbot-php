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
    if (empty($server['is_active']) || empty($server['node_monitoring'])) {
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
        $isOk = in_array($status, ['connected', 'healthy', 'active', 'ok']);

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
$lastExpiredCheck = Storage::cacheGet('last_expired_check_time');
$now = time();

// Run once every 24 hours (or if forced via ?task=expired or --expired)
$forceExpired = (isset($argv[1]) && $argv[1] === '--expired') || (isset($_GET['task']) && $_GET['task'] === 'expired');

if ($forceExpired || !$lastExpiredCheck || ($now - (int)$lastExpiredCheck) >= 86400) {
    echo "Running daily expiring users report...\n";
    Storage::cacheSet('last_expired_check_time', $now, 86400 * 2);

    $botInfo = tgbot('getMe');
    $botUsername = $botInfo['result']['username'] ?? '';

    foreach ($servers as $server) {
        if (empty($server['is_active']) || empty($server['expired_stats'])) {
            continue;
        }

        $users = PanelManager::getUsers($server, 1, 100, null, 'expired');
        if (empty($users)) {
            continue;
        }

        $todayExpired = [];
        foreach ($users as $u) {
            $exp = $u['expire_timestamp'] ?? 0;
            // Expired in last 24h or expiring today
            if ($exp > 0 && abs($now - $exp) <= 86400) {
                $link = !empty($botUsername)
                    ? "<a href='https://t.me/{$botUsername}?start=user_{$server['id']}_{$u['username']}'><code>{$u['username']}</code></a>"
                    : "<code>{$u['username']}</code>";
                $todayExpired[] = $link;
            }
        }

        if (!empty($todayExpired)) {
            $text = "📊 <b>Users Expired / Expiring Today:</b> <code>" . htmlspecialchars($server['remark']) . "</code>\n\n";
            $text .= "Accounts (" . count($todayExpired) . "):\n" . implode(', ', $todayExpired);

            foreach ($admins as $adminId) {
                tg_send_message($adminId, $text);
            }
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Health checks complete.\n";
