<?php
declare(strict_types=1);

/** Periodic jobs corresponding to app/settings/tasks/items. */
class BackgroundTasks {
    public static function refreshAccess(array $servers): void {
        foreach ($servers as $server) {
            $token = $server['type'] === 'marzneshin'
                ? MarzneshinClient::getToken($server, true)
                : MarzbanClient::getToken($server, true);
            if (!$token) {
                error_log('Failed to generate access: ' . $server['remark']);
                continue;
            }
            Storage::cacheSet('online_' . $server['id'], time(), 86400);
        }
    }
    public static function monitorNodes(array $servers, array $admins): void {
        $text = "<b>❌ This Nodes is have a error!</b>\n";
        $hasError = false;
        foreach ($servers as $server) {
            if (empty($server['node_monitoring'])) continue;
            foreach (PanelManager::getNodes($server) as $node) {
                if (PanelManager::isNodeOk($node, $server['type'])) continue;
                $hasError = true;
                $text .= "➖➖➖➖➖\n<b>Node Remark:</b> <code>" . Formatter::escape($node['name'] ?? $node['remark'] ?? '') . "</code>\n";
                $text .= '<b>Node Address:</b> <code>' . Formatter::escape($node['address'] ?? '') . "</code>\n";
                $text .= '<b>Node Message:</b> <code>' . Formatter::escape($node['message'] ?? 'None') . "</code>\n";
                if (!empty($server['node_restart'])) {
                    PanelManager::restartNode($server, (int)$node['id']);
                    $text .= "<b>Node Is Restart:</b> <code>✔️</code>\n";
                }
            }
        }
        if ($hasError) foreach ($admins as $admin) tg_send_message($admin, $text);
    }
    public static function expiredReport(array $servers, array $admins, ?int $now = null): void {
        $now ??= time();
        $bot = tgbot('getMe');
        $botUsername = $bot['result']['username'] ?? '';
        foreach ($servers as $server) {
            if (empty($server['expired_stats']) || !PanelManager::isOnline($server)) continue;
            $total = 0; $names = [];
            $size = PanelManager::pageSize($server);
            for ($page = 1; ; $page++) {
                $users = PanelManager::getUsers($server, $page, $size);
                if (!$users) break;
                foreach ($users as $user) {
                    $total++;
                    if ($server['type'] === 'marzneshin' && ($user['raw']['expire_strategy'] ?? '') !== 'fixed_date') continue;
                    $hours = (int)((($user['expire_timestamp'] ?? 0) - $now) / 3600);
                    if ($hours > 0 && $hours < 24) $names[] = $user['username'];
                }
                if (count($users) < $size) break;
            }
            $links = [];
            foreach ($names as $name) $links[] = "<a href='https://t.me/{$botUsername}?start=user_{$server['id']}_" . rawurlencode($name) . "'> <code>" . Formatter::escape($name) . '</code> </a>';
            $list = $links ? implode(',', $links) : '<code>None</code>';
            $text = '📊 <b>Users scheduled to expire today in ' . Formatter::escape(ucwords($server['remark'])) . " server:</b>\n";
            $text .= '⚰️ <b>List of users[<code>' . count($names) . '</code>/<code>' . $total . '</code>]:</b> ' . $list;
            foreach ($admins as $admin) tg_send_message($admin, $text);
        }
    }
    public static function tick(bool $forceExpired = false): void {
        global $config;
        $servers = Storage::getServers();
        $admins = $config['admin_ids'] ?? [];
        if (Storage::cacheGet('access_refresh_due') === null) {
            self::refreshAccess($servers);
            Storage::cacheSet('access_refresh_due', true, 8 * 3600);
        }
        self::monitorNodes($servers, $admins);
        $today = date('Y-m-d');
        if ($forceExpired || ((int)date('G') >= 6 && Storage::cacheGet('last_expired_check_date') !== $today)) {
            self::expiredReport($servers, $admins);
            Storage::cacheSet('last_expired_check_date', $today, 2 * 86400);
        }
    }
}
