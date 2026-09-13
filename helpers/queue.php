<?php
declare(strict_types=1);
require_once __DIR__ . '/request_budget.php';

/** Local durable spool shared by JSON and MySQL installations on one host. */
class BatchQueue {
    public const PAGE_SIZE = 50;
    public const MAX_TARGETS = 10000;
    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    public static function directory(): string {
        global $config;
        $dir = $config['queue_path'] ?? dirname($config['storage_path'] ?? __DIR__ . '/../data/storage.json') . '/queue';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create queue directory');
        // Apache protection is supplemental; configure a path outside the web root.
        if (!file_exists($dir . '/.htaccess')) self::atomic($dir . '/.htaccess', "Require all denied\n");
        return $dir;
    }
    private static function atomic(string $path, string $value): void {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $fp = fopen($tmp, 'xb');
        if (!$fp) throw new RuntimeException('Cannot write queue');
        try {
            chmod($tmp, 0600);
            $offset = 0;
            while ($offset < strlen($value)) {
                $n = fwrite($fp, substr($value, $offset));
                if (!$n) throw new RuntimeException('Queue write failed');
                $offset += $n;
            }
            if (!fflush($fp) || (function_exists('fsync') && !fsync($fp))) throw new RuntimeException('Queue flush failed');
        } finally { fclose($fp); }
        if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Cannot publish queue file'); }
    }
    private static function save(string $path, array $value): void {
        self::atomic($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    private static function read(string $path): array {
        $value = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('Invalid queue file');
        return $value;
    }
    private static function fingerprint(array $server): string {
        return hash('sha256', json_encode([$server['type'], rtrim($server['base_url'], '/'), $server['username']]));
    }
    public static function get(string $id): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $file = self::directory() . '/' . $id . '/job.json';
        return is_file($file) ? self::read($file) : null;
    }
    public static function recent(int|string $chat, int $user): array {
        $jobs = [];
        foreach (glob(self::directory() . '/*/job.json') ?: [] as $file) {
            $job = self::read($file);
            if ((string)$job['chat_id'] === (string)$chat && (int)$job['user_id'] === $user) $jobs[] = $job;
        }
        usort($jobs, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        return array_slice($jobs, 0, 10);
    }
    public static function cancel(string $id): void {
        $job = self::get($id);
        if ($job && !in_array($job['status'], self::TERMINAL, true)) self::atomic(self::directory() . '/' . $id . '/cancel', '1');
    }
    public static function enqueue(string $kind, array $server, array $params, int|string $chat, int $user, string $submission): array {
        if (!in_array($kind, ['delete', 'transfer', 'config', 'create', 'admin_status'], true)) throw new InvalidArgumentException('Unsupported queue operation');
        if ($kind === 'create' && max((int)($params['count'] ?? 1), count($params['uploaded_json'] ?? [])) > self::MAX_TARGETS) throw new InvalidArgumentException('Too many targets');
        $dir = self::directory();
        // A repeated press on the same submitted message has the same identity.
        $id = substr(hash('sha256', json_encode([$chat, $user, $submission, $kind, $server['id'], $params], JSON_THROW_ON_ERROR)), 0, 32);
        $lock = fopen($dir . '/.enqueue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock queue submission');
        try {
            if ($existing = self::get($id)) return $existing;
            $jobDir = $dir . '/' . $id;
            if (!is_dir($jobDir) && !mkdir($jobDir, 0700)) throw new RuntimeException('Cannot create job');
            $count = 0;
            if ($kind === 'create') {
                if (isset($params['uploaded_json'])) {
                    $items = $params['uploaded_json'];
                    $count = count($items);
                    foreach (array_chunk($items, self::PAGE_SIZE) as $page => $chunk) self::save($jobDir . '/input-' . $page . '.json', $chunk);
                    unset($params['uploaded_json']);
                    $params['json'] = true;
                } else $count = max(1, (int)($params['count'] ?? 1));
            }
            $job = ['id'=>$id, 'kind'=>$kind, 'server_id'=>(int)$server['id'], 'server_fingerprint'=>self::fingerprint($server),
                'params'=>$params, 'chat_id'=>$chat, 'user_id'=>$user, 'status'=>in_array($kind, ['create','admin_status'], true) ? 'running' : 'discovering',
                'created_at'=>microtime(true), 'updated_at'=>microtime(true), 'page'=>1, 'pages'=>0, 'cursor'=>0, 'total'=>$kind === 'admin_status' ? 1 : $count,
                'success'=>0, 'unconfirmed'=>0, 'skipped'=>0, 'delivery_failed'=>0, 'owner_failed'=>0, 'read_failures'=>0, 'next_run'=>0,
                'active'=>null, 'pending'=>null, 'notification'=>'pending'];
            // Publish metadata only after all immutable import pages exist.
            self::save($jobDir . '/job.json', $job);
            return $job;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function keyboard(array $job): array {
        $rows = [[['text'=>'Refresh status', 'callback_data'=>'job:' . $job['id']]]];
        if (!in_array($job['status'], self::TERMINAL, true)) $rows[] = [['text'=>'Cancel remaining work', 'callback_data'=>'job_cancel:' . $job['id']]];
        $rows[] = [['text'=>'Back', 'callback_data'=>'srv:' . $job['server_id']]];
        return ['inline_keyboard'=>$rows];
    }
    public static function describe(array $job): string {
        $text = 'Batch ' . $job['id'] . "\nOperation: " . $job['kind'] . "\nStatus: " . $job['status'];
        $text .= "\nProcessed: {$job['cursor']}/{$job['total']}\nConfirmed: {$job['success']}\nNot confirmed: {$job['unconfirmed']}\nSkipped: {$job['skipped']}";
        if ($job['owner_failed']) $text .= "\nOwner assignments not confirmed: {$job['owner_failed']}";
        if ($job['delivery_failed']) $text .= "\nQR deliveries not confirmed: {$job['delivery_failed']}";
        if (isset($job['error'])) $text .= "\n" . htmlspecialchars(substr($job['error'], 0, 600), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($job['unconfirmed'] || $job['owner_failed']) $text .= "\nCheck the panel before repeating unconfirmed operations.";
        return $text;
    }
    private static function issue(string $dir, array &$job, string $username, string $reason): void {
        self::save($dir . '/issue-' . hash('sha256', $username . $reason) . '.json', ['username'=>$username, 'reason'=>$reason, 'time'=>time()]);
        $job['error'] = $reason . ($username !== '' ? ': ' . $username : '');
    }
    /** One bounded slice. The lock is released by the OS even after a killed worker. */
    public static function run(?float $seconds = null, ?int $steps = null): int {
        global $config;
        $seconds = max(0.1, min(45, $seconds ?? (float)($config['queue_budget_seconds'] ?? 15)));
        $steps = max(1, min(100, $steps ?? (int)($config['queue_steps'] ?? 10)));
        $dir = self::directory();
        $lock = fopen($dir . '/.worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return 0; }
        $oldDeadline = RequestBudget::$deadline;
        RequestBudget::$deadline = microtime(true) + $seconds;
        $processed = 0;
        try {
            do {
                $jobs = [];
                foreach (glob($dir . '/*/job.json') ?: [] as $file) {
                    try {
                        $job = self::read($file);
                        if ($job['next_run'] <= time() && (!in_array($job['status'], self::TERMINAL, true) || $job['notification'] === 'pending')) $jobs[] = $job;
                    } catch (Throwable $e) { error_log('Cannot read queue job ' . basename(dirname($file))); }
                }
                // Keep one active job per server so our own mutations cannot shift
                // another job's paginated discovery. Other servers remain fair.
                $first = [];
                foreach (glob($dir . '/*/job.json') ?: [] as $file) {
                    try {
                        $candidate = self::read($file);
                        if (in_array($candidate['status'], self::TERMINAL, true)) continue;
                        $key = $candidate['server_id'];
                        if (!isset($first[$key]) || [$candidate['created_at'], $candidate['id']] < [$first[$key]['created_at'], $first[$key]['id']]) $first[$key] = $candidate;
                    } catch (Throwable $e) { /* Corrupt jobs are logged above. */ }
                }
                $jobs = array_values(array_filter($jobs, fn($job) => in_array($job['status'], self::TERMINAL, true) || ($first[$job['server_id']]['id'] ?? '') === $job['id']));
                usort($jobs, fn($a, $b) => $a['updated_at'] <=> $b['updated_at']);
                foreach ($jobs as $job) {
                    if ($processed >= $steps || microtime(true) >= RequestBudget::$deadline - 0.1) break 2;
                    self::step($dir . '/' . $job['id'], $job);
                    $processed++;
                }
            } while ($jobs);
        } finally { RequestBudget::$deadline = $oldDeadline; flock($lock, LOCK_UN); fclose($lock); }
        return $processed;
    }
    private static function step(string $dir, array $job): void {
        global $config;
        $path = $dir . '/job.json';
        $job['updated_at'] = microtime(true);
        // Never replay a mutation or send whose result was not persisted.
        if ($job['active'] !== null) {
            $phase = $job['active'];
            if ($phase === 'mutate') { $job['unconfirmed']++; $job['cursor']++; }
            elseif ($phase === 'owner') { $job['owner_failed']++; $job['pending']['phase'] = 'photo'; }
            elseif ($phase === 'photo') { $job['delivery_failed']++; $job['pending'] = null; }
            elseif ($phase === 'notify') $job['notification'] = 'unconfirmed';
            self::issue($dir, $job, $job['current_user'] ?? '', 'Previous ' . $phase . ' result was not saved; not repeated');
            $job['active'] = null;
            self::save($path, $job);
        }
        if (!in_array((int)$job['user_id'], array_map('intval', $config['admin_ids'] ?? []), true)) {
            $job['status'] = 'cancelled'; $job['notification'] = 'suppressed';
            $job['error'] = 'Submitting administrator is no longer authorized';
            self::save($path, $job); return;
        }
        if (in_array($job['status'], self::TERMINAL, true)) {
            if ($job['notification'] !== 'pending') return;
            $job['active'] = 'notify'; self::save($path, $job);
            try {
                $response = tg_send_message($job['chat_id'], self::describe($job), self::keyboard($job));
                $job['notification'] = !empty($response['ok']) ? 'sent' : 'unconfirmed';
            } catch (Throwable $e) { $job['notification'] = 'unconfirmed'; }
            $job['active'] = null; self::save($path, $job); return;
        }
        if (is_file($dir . '/cancel')) {
            $job['status'] = 'cancelled'; $job['pending'] = null;
            self::save($path, $job); return;
        }
        $server = Storage::getServer($job['server_id']);
        if (!$server || self::fingerprint($server) !== $job['server_fingerprint']) {
            $job['status'] = 'failed'; $job['error'] = 'Server removed or identity changed'; self::save($path, $job); return;
        }
        try {
            if ($job['status'] === 'discovering') {
                $params = $job['params'];
                $users = PanelManager::getUsers($server, $job['page'], self::PAGE_SIZE, null, $params['status'] ?? null, $params['admin'] ?? null, true);
                if ($job['total'] + count($users) > self::MAX_TARGETS) throw new LengthException('Target limit exceeded; split the batch by administrator');
                self::save($dir . '/targets-' . $job['pages'] . '.json', array_column($users, 'username'));
                $job['pages']++; $job['page']++; $job['total'] += count($users); $job['read_failures'] = 0;
                if (count($users) < self::PAGE_SIZE) $job['status'] = 'running';
            } elseif ($job['pending'] !== null) {
                self::deliver($dir, $path, $job, $server);
            } elseif ($job['cursor'] >= $job['total']) {
                $job['status'] = 'completed';
            } else {
                self::mutate($dir, $path, $job, $server);
            }
        } catch (Throwable $e) {
            if ($job['active'] !== null) {
                // Leave the durable marker for the next slice to classify safely.
                return;
            }
            $job['read_failures']++;
            $job['error'] = $e instanceof LengthException ? $e->getMessage() : 'Unable to read batch data; retrying before further mutations';
            $job['next_run'] = time() + 30 * $job['read_failures'];
            if ($job['read_failures'] >= 3 || $e instanceof LengthException) $job['status'] = 'failed';
        }
        self::save($path, $job);
    }
    private static function mutate(string $dir, string $path, array &$job, array $server): void {
        $p = $job['params']; $index = $job['cursor']; $kind = $job['kind'];
        $item = null;
        if ($kind === 'create') {
            if (!empty($p['json'])) {
                $chunk = self::read($dir . '/input-' . intdiv($index, self::PAGE_SIZE) . '.json');
                $item = $chunk[$index % self::PAGE_SIZE];
                $username = $item['username'];
            } else $username = $p['username'] . ($job['total'] > 1 ? (string)((int)($p['usersuffix'] ?? 1) + $index) : '');
        } elseif ($kind === 'admin_status') $username = $p['admin'];
        else {
            $chunk = self::read($dir . '/targets-' . intdiv($index, self::PAGE_SIZE) . '.json');
            $username = $chunk[$index % self::PAGE_SIZE];
        }
        $job['current_user'] = $username;
        // Receipts deduplicate users repeated across changing discovery pages.
        $receipt = $dir . '/attempt-' . hash('sha256', $username) . '.json';
        if (is_file($receipt)) { $job['skipped']++; $job['cursor']++; return; }
        $ids = [];
        if ($kind === 'config') {
            $user = PanelManager::getUser($server, $username);
            if (!$user) throw new RuntimeException('Cannot read user before config change');
            $ids = $user['service_ids'];
            $exists = in_array((string)$p['service_id'], array_map('strval', $ids), true);
            if ($exists === (bool)$p['add']) { $job['skipped']++; $job['cursor']++; return; }
            $ids = $p['add'] ? [...$ids, $p['service_id']] : array_values(array_filter($ids, fn($id) => (string)$id !== (string)$p['service_id']));
        }
        $job['read_failures'] = 0;
        $job['active'] = 'mutate'; self::save($path, $job);
        self::save($receipt, ['username'=>$username, 'attempted_at'=>time()]);
        $created = null;
        $ok = match ($kind) {
            'delete' => PanelManager::deleteUser($server, $username),
            'transfer' => PanelManager::setOwner($server, $username, $p['to_admin']),
            'config' => PanelManager::updateUserConfigs($server, $username, $ids),
            'admin_status' => $p['active'] ? PanelManager::activateAdminUsers($server, $username) : PanelManager::disableAdminUsers($server, $username),
            'create' => (bool)($created = PanelManager::createUser($server, $username,
                (float)($item['datalimit'] ?? $p['data_limit'] ?? 0), (int)($item['datelimit'] ?? $p['date_limit'] ?? 0), null, $p['selected_configs'],
                $item !== null ? match ($item['datetypes']) { 'unlimited'=>'unlimited', 'after first use'=>'onhold', default=>'fixed' } : ($p['date_type'] ?? 'fixed'), null)),
        };
        $job[$ok ? 'success' : 'unconfirmed']++;
        if (!$ok) self::issue($dir, $job, $username, 'Panel mutation was not confirmed; inspect panel before retrying');
        if ($created) $job['pending'] = ['phase'=>!empty($p['admin']) ? 'owner' : 'photo', 'user'=>$created];
        $job['cursor']++; $job['active'] = null;
    }
    private static function deliver(string $dir, string $path, array &$job, array $server): void {
        $phase = $job['pending']['phase']; $user = $job['pending']['user'];
        $job['active'] = $phase; self::save($path, $job);
        if ($phase === 'owner') {
            if (!PanelManager::setOwner($server, $user['username'], $job['params']['admin'])) {
                $job['owner_failed']++;
                self::issue($dir, $job, $user['username'], 'Owner assignment was not confirmed');
            }
            $job['pending']['phase'] = 'photo';
        } else {
            if (!empty($user['subscription_url'])) {
                $response = QrGenerator::sendQrPhoto($job['chat_id'], $user['subscription_url'], Formatter::userInfo($server, $user));
                if (empty($response['ok'])) $job['delivery_failed']++;
            }
            $job['pending'] = null;
        }
        $job['active'] = null;
    }
}
