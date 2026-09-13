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
        if (!empty($user['lifetime_used_traffic_bytes'])) {
            $card .= "<b>📈 Lifetime Used Traffic:</b> <code>" . self::bytes($user['lifetime_used_traffic_bytes']) . "</code>\n";
        }
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
    public static function serverCard(array $server): string {
        $remark = htmlspecialchars($server['remark']);
        $type = strtoupper($server['type']);
        $baseUrl = htmlspecialchars($server['base_url']);
        $activeEmoji = !empty($server['is_active']) ? '✅ Active' : '❌ Inactive';
        $onlineEmoji = PanelManager::isOnline($server) ? '✅ Yes' : '❌ No';
        $monitorEmoji = !empty($server['node_monitoring']) ? '✅ Enabled' : '❌ Disabled';
        $restartEmoji = !empty($server['node_restart']) ? '✅ Enabled' : '❌ Disabled';
        $expiredStatsEmoji = !empty($server['expired_stats']) ? '✅ Enabled' : '❌ Disabled';

        $text = "<b>🖥 Server:</b> <code>{$remark}</code> (ID: <code>{$server['id']}</code>)\n";
        $text .= "<b>⚙️ Type:</b> <code>{$type}</code>\n";
        $text .= "<b>🌐 URL:</b> <code>{$baseUrl}</code>\n";
        $text .= "<b>🚦 State:</b> {$activeEmoji}\n";
        $text .= "<b>🛰 Online:</b> {$onlineEmoji}\n";
        $text .= "<b>📡 Node Monitoring:</b> {$monitorEmoji}\n";
        $text .= "<b>🔄 Auto Restart:</b> {$restartEmoji}\n";
        $text .= "<b>⚰️ Expired Stats:</b> {$expiredStatsEmoji}\n";

        return $text;
    }

    /**
     * Format server statistics dashboard card.
     */
    public static function statsCard(array $server, array $stats): string {
        $remark = htmlspecialchars($server['remark']);

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

        $expiredList = !empty($stats['today_expired']) ? implode(', ', $stats['today_expired']) : '<code>None</code>';
        $text .= "⚰️ <b>List of users:</b> {$expiredList}\n";

        return $text;
    }

    /**
     * Format template card.
     */
    public static function templateCard(array $template): string {
        $remark = htmlspecialchars($template['remark']);
        $isActive = !isset($template['is_active']) || !empty($template['is_active']);
        $dateType = $template['date_type'] ?? 'fixed';
        $dateTypeLabel = match ($dateType) {
            'unlimited' => 'Unlimited',
            'onhold' => 'After First Use',
            default => 'Fixed Date',
        };
        return "📋 <b>Template:</b> <code>{$remark}</code> (ID: <code>{$template['id']}</code>)\n\n" .
               "• <b>Active:</b> <code>" . ($isActive ? 'Yes' : 'No') . "</code>\n" .
               "• <b>Data Limit:</b> <code>{$template['data_limit']} GB</code>\n" .
               "• <b>Date Limit:</b> <code>{$template['date_limit']} Days</code>\n" .
               "• <b>Date Type:</b> <code>{$dateTypeLabel}</code>\n";
    }
}
