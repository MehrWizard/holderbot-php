<?php
declare(strict_types=1);
require_once __DIR__ . '/queue.php';

/** Periodic jobs corresponding to app/settings/tasks/items. */
class BackgroundTasks {
    /** Process one expiry-report page and return checkpoint state. */
    public static function expiryPage(array $server, array $state, ?int $now = null): array {
        $now = (int)($state['now'] ?? $now ?? time());
        $page = max(1, (int)($state['page'] ?? 1));
        $total = (int)($state['total'] ?? 0);
        $names = [];
        $matched = (int)($state['matched'] ?? count($names));
        $pageSize = (int)($state['page_size'] ?? PanelManager::pageSize($server));
        $users = PanelManager::getUsers($server, $page, $pageSize, null, null, null, true);
        if ($page === 1 && $users && count($users) < $pageSize) $pageSize = count($users);
        $total += count($users);
        foreach ($users as $user) {
            if ($server['type'] === 'marzneshin' && ($user['raw']['expire_strategy'] ?? '') !== 'fixed_date') continue;
            $hours = (int)((($user['expire_timestamp'] ?? 0) - $now) / 3600);
            if ($hours > 0 && $hours < 24) {
                $matched++;
                $names[] = (string)$user['username'];
            }
        }
        return ['page' => $page + 1, 'page_size' => $pageSize, 'total' => $total, 'names' => $names, 'matched' => $matched, 'done' => !$users, 'now' => $now];
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
