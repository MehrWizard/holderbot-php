<?php
declare(strict_types=1);
require_once __DIR__ . '/language.php';

class Formatter {
    public static function escape(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function start(): string {
        require_once __DIR__ . '/../version.php';
        return "Welcome to HolderBot 🤖 [<code>" . HOLDERBOT_VERSION . "</code> by @ErfJabs]\n" .
            "<b><a href='https://t.me/pingihostbot'>نصب پنل و انجام تانل به صورت کامل خودکار!</a></b>";
    }
    public static function credentialsPrompt(): string {
        return "<b>Enter Marz Server Credentials:\n</b>• <code>Username [sudo]</code>\n• <code>Password [sudo]</code>\n• <code>Host [https://sub.domain.com:port]</code>\n\n<b>Example:</b>\n<code>erfan\nerfan\nhttps://panel.domain.com:443</code>";
    }
    public static function bytes(int|float|null $bytes, int $precision = 2): string {
        $value = $bytes ?? 0;
        foreach (['bytes', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($value < 1024) return number_format($value, $precision, '.', '') . ' ' . $unit;
            $value /= 1024;
        }
        return number_format($value, $precision, '.', '') . ' TB';
    }
    public static function timestamp(mixed $value): ?int {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        try { return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->getTimestamp(); }
        catch (Exception) { return null; }
    }
    public static function timeDiff(?int $timestamp, ?int $now = null): string {
        if ($timestamp === null) return '➖';
        $diff = $timestamp - ($now ?? time());
        if ($diff === 0) return 'now';
        $seconds = abs($diff);
        if ($seconds < 60) $value = $seconds . ' sec';
        elseif ($seconds < 3600) $value = intdiv($seconds, 60) . ' min';
        elseif ($seconds < 86400) $value = intdiv($seconds, 3600) . ' hour';
        else $value = abs((int)floor($diff / 86400)) . ' day';
        return $diff > 0 ? 'in ' . $value : $value . ' ago';
    }
    public static function expireInfo(array $user): string {
        $raw = $user['raw'] ?? [];
        return match ($raw['expire_strategy'] ?? 'never') {
            'never' => 'Never expires',
            'fixed_date' => !empty($raw['expire_date']) ? self::timeDiff(self::timestamp($raw['expire_date'])) : 'Unknown',
            'start_on_first_use' => !empty($raw['usage_duration']) ? (int)($raw['usage_duration'] / 86400) . ' days after first use' : 'Unknown',
            default => 'Unknown',
        };
    }
    public static function briefData(array $server, array $user): array {
        $neshin = $server['type'] === 'marzneshin';
        return [
            'username' => $user['username'],
            'data_limit' => !empty($user['data_limit_bytes']) ? self::bytes($user['data_limit_bytes']) : ($neshin ? 'Unlimited' : 'None'),
            'expire_strategy' => $neshin ? ($user['raw']['expire_strategy'] ?? 'never') . ' (' . self::expireInfo($user) . ')' : $user['status'],
            'subscription_url' => $user['subscription_url'],
        ];
    }
    public static function userInfo(array $server, array $user): string {
        $d = array_map([self::class, 'escape'], self::briefData($server, $user));
        $template = Language::get('messages', 'USER_INFO');
        foreach ($d as $key => $value) $template = str_replace('{' . $key . '}', $value, $template);
        return $template;
    }
    public static function userCard(array $server, array $user): string {
        $r = $user['raw'] ?? [];
        $date = fn($key) => self::timeDiff(self::timestamp($r[$key] ?? null));
        $yes = fn($key) => !empty($r[$key]) ? 'Yes' : 'No';
        $fields = ['Username' => $user['username']];
        if ($server['type'] === 'marzneshin') {
            $fields += [
                'Expire Strategy' => ($r['expire_strategy'] ?? 'never') . ' (' . self::expireInfo($user) . ')',
                'Activation Deadline' => $date('activation_deadline'),
                'Data Limit' => !empty($r['data_limit']) ? self::bytes($r['data_limit']) : 'Unlimited',
                'Data Reset Strategy' => $r['data_limit_reset_strategy'] ?? 'no_reset',
                'Used Traffic' => self::bytes($r['used_traffic'] ?? 0),
                'Total Used Traffic' => self::bytes($r['lifetime_used_traffic'] ?? 0),
                'Last Update' => $date('sub_updated_at'), 'Last User Agent' => ($r['sub_last_user_agent'] ?? '') ?: '➖',
                'Last Online' => $date('online_at'), 'Activated' => $yes('activated'), 'Enabled' => $yes('enabled'),
                'Active' => $yes('is_active'), 'Expired' => $yes('expired'), 'Data Limit Reached' => $yes('data_limit_reached'),
                'Services' => implode(', ', $r['service_ids'] ?? []), 'Owner' => $r['owner_username'] ?? '➖',
                'Note' => ($r['note'] ?? '') ?: '➖', 'Revoked At' => $date('sub_revoked_at'),
                'Traffic Reset At' => $date('traffic_reset_at'), 'Created At' => $date('created_at'),
                'Subscription URL' => $user['subscription_url'],
            ];
        } else {
            $fields += [
                'Status' => $user['status'], 'Expire' => !empty($r['expire']) ? self::timeDiff((int)$r['expire']) : 'Never',
                'Expire Strategy: ' => $user['status'],
                'Data Limit' => !empty($r['data_limit']) ? self::bytes($r['data_limit']) : 'Unlimited',
                'Data Reset Strategy' => $r['data_limit_reset_strategy'] ?? 'no_reset',
                'Used Traffic' => !empty($r['used_traffic']) ? self::bytes($r['used_traffic']) : '0B',
                'Lifetime Used Traffic' => !empty($r['lifetime_used_traffic']) ? self::bytes($r['lifetime_used_traffic']) : '0B',
                'Last Update' => $date('sub_updated_at'), 'Last User Agent' => ($r['sub_last_user_agent'] ?? '') ?: '➖',
                'Last Online' => $date('online_at'), 'On Hold Expire Duration' => ($r['on_hold_expire_duration'] ?? 0) ?: '➖',
                'On Hold Timeout' => $date('on_hold_timeout'), 'Note' => ($r['note'] ?? '') ?: '➖',
                'Subscription URL' => $user['subscription_url'] ?: '➖', 'Created At' => $date('created_at'),
                'Admin' => $r['admin']['username'] ?? '➖',
            ];
        }
        $lines = [];
        foreach ($fields as $key => $value) {
            $label = $key === 'Expire Strategy: ' ? $key : $key . ':';
            $lines[] = '<b>• ' . $label . '</b> <code>' . self::escape($value) . '</code>';
        }
        return implode("\n", $lines);
    }
    private static function age(array $item): string {
        $created = self::timestamp($item['created_at'] ?? null);
        return $created !== null ? (int)floor((time() - $created) / 86400) . ' days ago' : '➖';
    }
    public static function serverCard(array $server): string {
        $fields = ['ID' => $server['id'], 'Remark' => $server['remark'], 'Active' => ($server['is_active'] ?? true) ? 'Yes' : 'No',
            'Online' => PanelManager::isOnline($server) ? 'Yes' : 'No',
            'Node Monitoring' => !empty($server['node_monitoring']) ? 'Yes' : 'No',
            'Node Auto Restart' => !empty($server['node_restart']) ? 'Yes' : 'No',
            'Expired Stats' => !empty($server['expired_stats']) ? 'Yes' : 'No', 'Types' => $server['type']];
        $text = '';
        foreach ($fields as $key => $value) $text .= '• <b>' . $key . ':</b> <code>' . self::escape($value) . "</code>\n";
        $text .= "• <b>Data</b>\n";
        foreach (['username' => 'username', 'password' => 'password', 'host' => 'base_url'] as $label => $key) $text .= '     • <b>' . $label . ':</b> <code>' . self::escape($server[$key] ?? '') . "</code>\n";
        return $text . '• <b>Updated At:</b> <code>' . self::escape($server['updated_at'] ?? '➖') . "</code>\n• <b>Created At:</b> <code>" . self::age($server) . "</code>\n";
    }
    public static function templateCard(array $template): string {
        $type = ['fixed' => 'now', 'onhold' => 'after first use', 'unlimited' => 'unlimited'][$template['date_type'] ?? 'fixed'];
        $fields = ['Remark' => $template['remark'], 'Active' => ($template['is_active'] ?? true) ? 'Yes' : 'No',
            'Data limit' => $template['data_limit'], 'Date limit' => $template['date_limit'], 'Date types' => $type,
            'Updated At' => $template['updated_at'] ?? '➖', 'Created At' => self::age($template)];
        $text = '';
        foreach ($fields as $key => $value) $text .= '• <b>' . $key . ':</b> <code>' . self::escape($value) . "</code>\n";
        return $text;
    }
    public static function statsCard(array $server, array $stats): string {
        $remark = htmlspecialchars($server['remark']);

        $text = "";
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

        $expired = is_array($stats['today_expired'] ?? null) ? $stats['today_expired'] : [];
        $visibleExpired = array_slice($expired, 0, 25);
        if (count($expired) > count($visibleExpired)) $visibleExpired[] = '<i>and ' . (count($expired) - count($visibleExpired)) . ' more</i>';
        $expiredList = $visibleExpired ? implode(',', $visibleExpired) : '<code>None</code>';
        $text .= "⚰️ <b>Expired in 24 Hours:</b> {$expiredList}";

        return $text;
    }

}
