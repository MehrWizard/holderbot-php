<?php
/**
 * HolderBot PHP - String & Data Formatting Helpers
 */

declare(strict_types=1);

class Formatter {
    /**
     * Format bytes into a human-readable string (GB, MB, KB, B).
     */
    public static function bytes(int|float|null $bytes, int $precision = 2): string {
        if ($bytes === null || $bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / pow(1024, $power);
        return round($value, $precision) . ' ' . $units[$power];
    }

    /**
     * Format a timestamp into relative time difference.
     */
    public static function timeDiff(?int $timestamp): string {
        if (!$timestamp || $timestamp <= 0) {
            return 'Never';
        }

        $now = time();
        $diff = $timestamp - $now;

        $dateFormatted = date('Y-m-d H:i', $timestamp);

        if ($diff > 0) {
            $days = floor($diff / 86400);
            $hours = floor(($diff % 86400) / 3600);
            if ($days > 0) {
                return "{$days}d {$hours}h remaining ({$dateFormatted})";
            }
            return "{$hours}h remaining ({$dateFormatted})";
        }

        $absDiff = abs($diff);
        $days = floor($absDiff / 86400);
        return "Expired {$days}d ago ({$dateFormatted})";
    }

    /**
     * Format user details into a Telegram HTML message card.
     */
    public static function userCard(array $server, array $user): string {
        $username = htmlspecialchars($user['username']);
        $status = $user['status'];
        $isActive = $user['is_active'];

        $statusEmoji = match ($status) {
            'active' => '✅ Active',
            'disabled' => '❌ Disabled',
            'expired' => '⏱️ Expired',
            'limited' => '🚫 Limited',
            'on_hold' => '⏸️ On Hold',
            default => "ℹ️ " . ucfirst($status),
        };

        $usedTraffic = self::bytes($user['used_traffic_bytes']);
        $dataLimit = ($user['data_limit_bytes'] > 0) ? self::bytes($user['data_limit_bytes']) : 'Unlimited';

        $trafficPercent = '';
        if ($user['data_limit_bytes'] > 0) {
            $pct = round(($user['used_traffic_bytes'] / $user['data_limit_bytes']) * 100, 1);
            $trafficPercent = " ({$pct}%)";
        }

        $expire = self::timeDiff($user['expire_timestamp']);
        $serverName = htmlspecialchars($server['remark']);

        $card = "<b>👤 User:</b> <code>{$username}</code>\n";
        $card .= "<b>🖥 Server:</b> <code>{$serverName}</code>\n";
        $card .= "<b>🚦 Status:</b> <b>{$statusEmoji}</b>\n";
        $card .= "<b>📊 Traffic:</b> <code>{$usedTraffic} / {$dataLimit}{$trafficPercent}</code>\n";
        $card .= "<b>⏱ Expiration:</b> <code>{$expire}</code>\n";

        if (!empty($user['note'])) {
            $note = htmlspecialchars($user['note']);
            $card .= "<b>📝 Note:</b> <code>{$note}</code>\n";
        }

        if (!empty($user['subscription_url'])) {
            $subUrl = $user['subscription_url'];
            $card .= "\n<b>🔗 Subscription Link:</b>\n<code>{$subUrl}</code>\n";
        }

        return $card;
    }

    /**
     * Format server details card.
     */
    public static function serverCard(array $server, ?array $nodes = null): string {
        $remark = htmlspecialchars($server['remark']);
        $type = strtoupper($server['type']);
        $baseUrl = htmlspecialchars($server['base_url']);
        $activeEmoji = !empty($server['is_active']) ? '✅ Active' : '❌ Inactive';
        $monitorEmoji = !empty($server['node_monitoring']) ? '✅ Enabled' : '❌ Disabled';
        $restartEmoji = !empty($server['node_restart']) ? '✅ Enabled' : '❌ Disabled';

        $text = "<b>🖥 Server:</b> <code>{$remark}</code> (ID: <code>{$server['id']}</code>)\n";
        $text .= "<b>⚙️ Type:</b> <code>{$type}</code>\n";
        $text .= "<b>🌐 URL:</b> <code>{$baseUrl}</code>\n";
        $text .= "<b>🚦 State:</b> {$activeEmoji}\n";
        $text .= "<b>📡 Node Monitoring:</b> {$monitorEmoji}\n";
        $text .= "<b>🔄 Auto Restart:</b> {$restartEmoji}\n";

        if ($nodes !== null) {
            $totalNodes = count($nodes);
            $healthy = 0;
            $nodeDetails = "";

            foreach ($nodes as $node) {
                $nodeRemark = htmlspecialchars($node['remark'] ?? ($node['name'] ?? 'Node'));
                $status = $node['status'] ?? 'healthy';
                $isOk = in_array(strtolower($status), ['connected', 'healthy', 'active', 'ok']);
                if ($isOk) {
                    $healthy++;
                    $nodeDetails .= "  • ✅ <code>{$nodeRemark}</code>: {$status}\n";
                } else {
                    $nodeDetails .= "  • ❌ <code>{$nodeRemark}</code>: {$status}\n";
                }
            }

            $text .= "\n<b>📡 Nodes ({$healthy}/{$totalNodes} Online):</b>\n{$nodeDetails}";
        }

        return $text;
    }

    /**
     * Format server statistics dashboard card.
     */
    public static function statsCard(array $server, array $stats): string {
        $remark = htmlspecialchars($server['remark']);
        $totalTraffic = self::bytes($stats['total_traffic'] ?? 0);

        $text = "📊 <b>Statistics Dashboard - {$remark}</b>\n\n";
        $text .= "📊 <b>Total:</b> <code>{$stats['total_users']}</code>\n";
        $text .= "✅ <b>Enable:</b> <code>{$stats['active_users']}</code>\n";
        $text .= "🚫 <b>Disable:</b> <code>{$stats['disabled_users']}</code>\n";
        $text .= "⏳ <b>Expired:</b> <code>{$stats['expired_users']}</code>\n";
        $text .= "⚠️ <b>Limited:</b> <code>{$stats['limited_users']}</code>\n";
        $text .= "📉 <b>Remaining 1% Data Usage:</b> <code>" . ($stats['data_1'] ?? 0) . "</code>\n";
        $text .= "📊 <b>Remaining 10% Data Usage:</b> <code>" . ($stats['data_10'] ?? 0) . "</code>\n";
        $text .= "🕐 <b>Last Day Sub-Updated/Online:</b> <code>" . ($stats['update_day'] ?? 0) . "</code>/<code>" . ($stats['online_day'] ?? 0) . "</code>\n";
        $text .= "📆 <b>Last Week Sub-Updated/Online:</b> <code>" . ($stats['update_week'] ?? 0) . "</code>/<code>" . ($stats['online_week'] ?? 0) . "</code>\n";
        $text .= "📅 <b>Last Month Sub-Updated/Online:</b> <code>" . ($stats['update_month'] ?? 0) . "</code>/<code>" . ($stats['online_month'] ?? 0) . "</code>\n";
        $text .= "📈 <b>Total Traffic Used:</b> <code>{$totalTraffic}</code>\n";

        if (!empty($stats['today_expired'])) {
            $text .= "\n⚰️ <b>Expired in 24 Hours:</b> " . implode(', ', $stats['today_expired']) . "\n";
        }

        if (!empty($stats['system'])) {
            $sys = $stats['system'];
            $text .= "\n🖥️ <b>Host System:</b>\n";
            if (isset($sys['cpu_usage'])) {
                $text .= "• <b>CPU:</b> <code>{$sys['cpu_usage']}%</code>\n";
            }
            if (isset($sys['mem_used']) && isset($sys['mem_total'])) {
                $used = self::bytes($sys['mem_used']);
                $tot = self::bytes($sys['mem_total']);
                $text .= "• <b>RAM:</b> <code>{$used} / {$tot}</code>\n";
            }
        }

        return $text;
    }

    /**
     * Format bulk created users summary card.
     */
    public static function bulkCreatedCard(array $server, array $users): string {
        $count = count($users);
        $text = "🎉 <b>Batch Creation Finished!</b>\n";
        $text .= "Successfully generated <code>{$count}</code> accounts on <b>{$server['remark']}</b>:\n\n";

        foreach ($users as $u) {
            $uname = htmlspecialchars($u['username']);
            $sub = !empty($u['subscription_url']) ? "\n  <code>{$u['subscription_url']}</code>" : '';
            $text .= "• 👤 <code>{$uname}</code>{$sub}\n";
        }

        return $text;
    }

    /**
     * Format template card.
     */
    public static function templateCard(array $template): string {
        $remark = htmlspecialchars($template['remark']);
        return "📋 <b>Template:</b> <code>{$remark}</code> (ID: <code>{$template['id']}</code>)\n\n" .
               "• <b>Data Limit:</b> <code>{$template['data_limit']} GB</code>\n" .
               "• <b>Date Limit:</b> <code>{$template['date_limit']} Days</code>\n";
    }
}
