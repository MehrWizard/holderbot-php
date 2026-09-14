<?php
declare(strict_types=1);
require_once __DIR__ . '/queue.php';

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

    /** Process one expiry-report page and return checkpoint state. */
    public static function expiryPage(array $server, array $state, ?int $now = null): array {
        $now ??= time();
        $page = max(1, (int)($state['page'] ?? 1));
        $total = (int)($state['total'] ?? 0);
        $names = is_array($state['names'] ?? null) ? $state['names'] : [];
        $matched = (int)($state['matched'] ?? count($names));
        $users = PanelManager::getUsers($server, $page, PanelManager::pageSize($server), null, null, null, true);
        $total += count($users);
        foreach ($users as $user) {
            if ($server['type'] === 'marzneshin' && ($user['raw']['expire_strategy'] ?? '') !== 'fixed_date') continue;
            $hours = (int)((($user['expire_timestamp'] ?? 0) - $now) / 3600);
            if ($hours > 0 && $hours < 24) {
                $matched++;
                if (count($names) < 25) $names[] = (string)$user['username'];
            }
        }
        return ['page' => $page + 1, 'total' => $total, 'names' => $names, 'matched' => $matched, 'done' => count($users) < PanelManager::pageSize($server), 'now' => $now];
    }

    /** Deliver a completed expiry report from an incremental scan. */
    public static function sendExpiryReport(array $server, array $admins, array $names, int $total, ?int $matched = null): void {
        $bot = tgbot('getMe');
        $botUsername = $bot['result']['username'] ?? '';
        $links = [];
        foreach ($names as $name) $links[] = "<a href='https://t.me/{$botUsername}?start=user_{$server['id']}_" . rawurlencode($name) . "'> <code>" . Formatter::escape($name) . '</code> </a>';
        $list = $links ? implode(',', $links) : '<code>None</code>';
        $text = '📊 <b>Users scheduled to expire today in ' . Formatter::escape(ucwords($server['remark'])) . " server:</b>\n";
        $text .= '⚰️ <b>List of users[<code>' . ($matched ?? count($names)) . '</code>/<code>' . $total . '</code>]:</b> ' . $list;
        foreach ($admins as $admin) tg_send_message($admin, $text);
    }
    /** Schedule only; all panel and Telegram calls run in bounded queue steps. */
    public static function tick(bool $forceExpired = false): void {
        global $config;
        $admins = array_values(array_unique(array_map('intval', $config['admin_ids'] ?? [])));
        foreach (Storage::getServers() as $server) {
            self::schedule('access', $server, [], (string)intdiv(time(), 8 * 3600));
            if (!empty($server['node_monitoring'])) self::schedule('monitor', $server, ['recipients'=>$admins], (string)intdiv(time(), 30));
            if (!empty($server['expired_stats']) && ($forceExpired || (int)date('G') >= 6)) {
                self::schedule('expiry', $server, ['recipients'=>$admins], $forceExpired ? 'forced:' . time() : date('Y-m-d'));
            }
        }
    }
    private static function schedule(string $kind, array $server, array $params, string $period): void {
        $key='queue_schedule_'.$kind.'_'.$server['id'];
        $previous=Storage::cacheGet($key);
        if ($previous) {
            $job=BatchQueue::get($previous['id']);
            // Coalesce missed ticks instead of accumulating a monitoring backlog.
            if ($job && !in_array($job['status'], ['completed','failed','cancelled'], true)) return;
            if ($previous['period']===$period) return;
        }
        $job=BatchQueue::enqueue($kind,$server,$params,0,0,'schedule:'.$kind.':'.$period);
        Storage::cacheSet($key,['id'=>$job['id'],'period'=>$period],30*86400);
    }
}
