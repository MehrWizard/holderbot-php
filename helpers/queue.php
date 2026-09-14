<?php
declare(strict_types=1);

require_once __DIR__ . '/request_budget.php';

/** Persistent batch queue backed by MySQL. Credentials are loaded at run time. */
final class BatchQueue
{
    public const PAGE_SIZE = 50;
    public const MAX_TARGETS = 10000;

    private static function db(): PDO { return Storage::db(); }

    private static function lockName(string $id): string { return 'holderbot-job-' . $id; }

    private static function acquireJobLock(string $id): bool
    {
        $stmt = self::db()->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([self::lockName($id)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private static function releaseJobLock(string $id): void
    {
        $stmt = self::db()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([self::lockName($id)]);
    }

    private static function fingerprint(array $server): string
    {
        return hash('sha256', json_encode([
            $server['type'] ?? '', rtrim((string)($server['base_url'] ?? ''), '/'), $server['username'] ?? ''
        ], JSON_THROW_ON_ERROR));
    }

    private static function decode(array $row): array
    {
        $row['params'] = json_decode((string)($row['payload'] ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            'cursor_pos' => 'cursor', 'total' => 'total', 'success_count' => 'success',
            'unconfirmed_count' => 'unconfirmed', 'skipped_count' => 'skipped',
            'active_phase' => 'active', 'notification_status' => 'notification', 'error_text' => 'error'
        ] as $from => $to) $row[$to] = $row[$from] ?? null;
        return $row;
    }

    private static function save(array $job): void
    {
        self::db()->prepare(
            'UPDATE bot_queue SET kind=?, payload=?, status=?, cursor_pos=?, total=?, success_count=?, unconfirmed_count=?, skipped_count=?, error_text=?, next_run=?, active_phase=?, notification_status=?, lease_until=? WHERE id=?'
        )->execute([
            $job['kind'], json_encode($job['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $job['status'],
            (int)$job['cursor'], (int)$job['total'], (int)$job['success'], (int)$job['unconfirmed'],
            (int)$job['skipped'], $job['error'] ?: null, (int)$job['next_run'], $job['active'] ?: null,
            $job['notification'] ?: 'pending', (int)($job['lease_until'] ?? 0), $job['id']
        ]);
    }

    public static function get(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $stmt = self::db()->prepare('SELECT * FROM bot_queue WHERE id=?'); $stmt->execute([$id]);
        $row = $stmt->fetch(); return $row ? self::decode($row) : null;
    }

    public static function recent(int|string $chatId, int $userId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM bot_queue WHERE chat_id=? AND user_id=? ORDER BY created_at DESC LIMIT 10');
        $stmt->execute([$chatId, $userId]); return array_map([self::class, 'decode'], $stmt->fetchAll() ?: []);
    }

    public static function cancel(string $id): void
    {
        self::db()->prepare("UPDATE bot_queue SET status='cancelled' WHERE id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$id]);
    }

    public static function enqueue(string $kind, array $server, array $params, int|string $chatId, int $userId, string $submission): array
    {
        $allowed = ['delete','transfer','config','create','admin_status','stats','expiry','access','monitor','outbox','import','recharge','qr','revoke_qr'];
        if (!in_array($kind, $allowed, true)) throw new InvalidArgumentException('Unsupported queue operation');
        $targetCount = max((int)($params['count'] ?? 1), count($params['uploaded_json'] ?? []));
        if ($kind === 'create' && $targetCount > self::MAX_TARGETS) throw new LengthException('Too many targets');
        $serverId = (int)($server['id'] ?? 0);
        $id = substr(hash('sha256', json_encode([$chatId,$userId,$submission,$kind,$serverId,$params], JSON_THROW_ON_ERROR)), 0, 32);
        $submissionKey = hash('sha256', json_encode([$chatId,$userId,$submission,$kind,$serverId], JSON_THROW_ON_ERROR));
        $db = self::db(); $lock = $db->prepare("SELECT GET_LOCK('holderbot-queue-submit', 10)"); $lock->execute();
        try {
            $existing = $db->prepare('SELECT * FROM bot_queue WHERE id=? OR submission_key=?'); $existing->execute([$id,$submissionKey]);
            if ($row = $existing->fetch()) return self::decode($row);
            $status = in_array($kind, ['delete','transfer','config'], true) ? 'discovering' : 'running';
        $total = in_array($kind, ['admin_status','outbox','recharge'], true) ? 1 : (in_array($kind, ['create','import'], true) ? max(1,$targetCount) : 0);
            $insert = $db->prepare('INSERT INTO bot_queue (id,kind,server_id,server_fingerprint,chat_id,user_id,submission_key,status,payload,total,notification_status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$id,$kind,$serverId,self::fingerprint($server),$chatId,$userId,$submissionKey,$status,json_encode($params, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$total,$userId ? 'pending' : 'suppressed']);
            return self::get($id) ?? throw new RuntimeException('Queue insert failed');
        } finally { $db->query("SELECT RELEASE_LOCK('holderbot-queue-submit')"); }
    }

    /** Persist a short operation before attempting it inline. */
    public static function enqueueInline(
        string $kind,
        array $server,
        array $params,
        int|string $chatId,
        int $userId,
        string $submission
    ): array {
        global $config;
        $allowed = ['create', 'recharge', 'qr', 'revoke_qr'];
        if (!in_array($kind, $allowed, true)) throw new InvalidArgumentException('Unsupported inline operation');
        $serverId = (int)($server['id'] ?? 0);
        $id = substr(hash('sha256', json_encode([$chatId, $userId, $submission, $kind, $serverId, $params], JSON_THROW_ON_ERROR)), 0, 32);
        $submissionKey = hash('sha256', json_encode([$chatId, $userId, $submission, $kind, $serverId], JSON_THROW_ON_ERROR));
        $lease = time() + max(15, (int)($config['inline_lease_seconds'] ?? 45));
        $db = self::db();
        $lock = $db->prepare("SELECT GET_LOCK('holderbot-queue-submit', 10)"); $lock->execute();
        try {
            $existing = $db->prepare('SELECT * FROM bot_queue WHERE id=? OR submission_key=?');
            $existing->execute([$id, $submissionKey]);
            if ($row = $existing->fetch()) return self::decode($row);
            $insert = $db->prepare('INSERT INTO bot_queue (id,kind,server_id,server_fingerprint,chat_id,user_id,submission_key,status,payload,total,notification_status,lease_until) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([
                $id, $kind, $serverId, self::fingerprint($server), $chatId, $userId, $submissionKey,
                'inline', json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 1,
                $userId ? 'pending' : 'suppressed', $lease,
            ]);
            return self::get($id) ?? throw new RuntimeException('Inline job insert failed');
        } finally { $db->query("SELECT RELEASE_LOCK('holderbot-queue-submit')"); }
    }

    /**
     * Run a persisted short operation while holding its MySQL advisory lock.
     * If the operation throws, leave it runnable for the normal queue worker.
     */
    public static function executeInline(array $job, callable $operation): array
    {
        global $config;
        $id = (string)$job['id'];
        if (!self::acquireJobLock($id)) return ['state' => 'locked', 'job' => $job, 'result' => null];
        try {
            $current = self::get($id);
            if (!$current) return ['state' => 'missing', 'job' => $job, 'result' => null];
            if (!in_array($current['status'], ['inline', 'inline_running'], true)) {
                return ['state' => $current['status'], 'job' => $current, 'result' => null];
            }
            $current['status'] = 'inline_running';
            $current['lease_until'] = time() + max(15, (int)($config['inline_lease_seconds'] ?? 45));
            $current['active'] = 'inline';
            self::save($current);
            try {
                $result = $operation();
                $current['status'] = 'completed';
                $current['cursor'] = 1;
                $current['total'] = 1;
                $current['success'] = 1;
                $current['active'] = null;
                $current['lease_until'] = 0;
                self::save($current);
                return ['state' => 'completed', 'job' => $current, 'result' => $result];
            } catch (InvalidArgumentException $e) {
                $current['status'] = 'failed';
                $current['lease_until'] = 0;
                $current['active'] = null;
                $current['error'] = $e->getMessage();
                self::save($current);
                return ['state' => 'failed', 'job' => $current, 'result' => null, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $current['status'] = 'running';
                $current['next_run'] = time();
                $current['lease_until'] = 0;
                $current['active'] = 'fallback';
                $current['error'] = 'Inline attempt failed: ' . $e->getMessage();
                self::save($current);
                return ['state' => 'queued', 'job' => $current, 'result' => null, 'error' => $e->getMessage()];
            }
        } finally {
            self::releaseJobLock($id);
        }
    }

    public static function fallbackMessage(array $job): string
    {
        return 'The request took too long. Continuing in the background.' .
            "\nQueue reference: <code>#" . htmlspecialchars(substr((string)$job['id'], 0, 10), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    }

    private static function notifyFallback(array $job): void
    {
        $messageId = (int)($job['params']['message_id'] ?? 0);
        if ($messageId <= 0 || (string)$job['chat_id'] === '0') return;
        try { tg_edit_message($job['chat_id'], $messageId, self::fallbackMessage($job), self::keyboard($job)); }
        catch (Throwable $e) { error_log('Unable to update inline fallback message: ' . $e->getMessage()); }
    }

    private static function finalizeMessage(array $job): void
    {
        if (!in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) return;
        $messageId = (int)($job['params']['message_id'] ?? 0);
        if ($messageId <= 0 || (string)$job['chat_id'] === '0') return;
        if ($job['status'] === 'completed' && in_array($job['kind'], ['stats', 'qr', 'revoke_qr'], true)) return;
        if ($job['kind'] === 'create' && !empty($job['params']['send_qr']) && $job['status'] === 'completed') return;
        try { tg_replace_message($job['chat_id'], $messageId, self::describe($job), self::keyboard($job)); }
        catch (Throwable $e) { error_log('Unable to deliver final job result: ' . $e->getMessage()); }
    }

    public static function message(int|string $chatId, int $userId, string $text, string $key, ?array $keyboard=null): array
    { return self::enqueue('outbox',['id'=>0,'type'=>'internal','base_url'=>'','username'=>''],['text'=>$text,'keyboard'=>$keyboard],$chatId,$userId,$key); }

    public static function photo(int|string $chatId, int $userId, string $url, string $caption, string $key): array
    { return self::enqueue('outbox',['id'=>0,'type'=>'internal','base_url'=>'','username'=>''],['photo'=>$url,'text'=>$caption],$chatId,$userId,$key); }

    public static function keyboard(array $job): array
    {
        $rows = [[['text'=>'Refresh status','callback_data'=>'job:'.$job['id']]]];
        if (!in_array($job['status'],['completed','failed','cancelled'],true)) $rows[]=[['text'=>'Cancel remaining work','callback_data'=>'job_cancel:'.$job['id']]];
        if ((int)$job['server_id']>0) $rows[]=[['text'=>'Back','callback_data'=>'srv:'.$job['server_id']]];
        return ['inline_keyboard'=>$rows];
    }

    public static function label(string $kind): string
    {
        return [
            'create' => 'user creation',
            'import' => 'user import',
            'delete' => 'user deletion',
            'transfer' => 'ownership transfer',
            'config' => 'configuration update',
            'admin_status' => 'admin user status update',
            'stats' => 'server statistics',
            'qr' => 'QR delivery',
            'revoke_qr' => 'subscription revoke',
            'access' => 'panel access refresh',
            'monitor' => 'node monitoring',
            'expiry' => 'expiry report',
            'outbox' => 'message delivery',
            'recharge' => 'user recharge',
        ][$kind] ?? 'background operation';
    }

    public static function describe(array $job): string
    {
        $label = self::label((string)$job['kind']);
        $status = (string)($job['status'] ?? 'running');
        if ($status === 'completed') {
            if ($job['kind'] === 'stats') return '📊 Server statistics updated.';
            return '✅ ' . ucfirst($label) . ' completed.\nProcessed: ' . (int)$job['cursor'] . ' item(s).';
        }
        if ($status === 'failed') {
            $text = '❌ ' . ucfirst($label) . ' failed.';
            return $text . (!empty($job['error']) ? "\n" . htmlspecialchars((string)$job['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '');
        }
        if ($status === 'cancelled') return '⛔ ' . ucfirst($label) . ' cancelled.';

        $text = '⏳ ' . ucfirst($label) . ' is running in the background.';
        $total = (int)($job['total'] ?? 0);
        if ($total > 0) $text .= "\nProcessed: " . (int)$job['cursor'] . "/{$total}.";
        return $text . "\nUse Refresh status to check progress.";
    }

    private static function step(array &$job): void
    {
        $params = $job['params']; $server = $job['server_id']>0 ? Storage::getServer((int)$job['server_id']) : null;
        if ($job['kind'] !== 'outbox' && (!$server || self::fingerprint($server) !== $job['server_fingerprint'])) { $job['status']='failed'; $job['error']='Server removed or identity changed'; return; }
        if ($job['kind'] === 'outbox') {
            $job['active']='outbox'; self::save($job);
            $result = isset($params['photo']) ? QrGenerator::sendQrPhoto($job['chat_id'],$params['photo'],$params['text']) : tg_send_message($job['chat_id'],$params['text'],$params['keyboard']??null);
            $job['active']=null; $job['cursor']=1; $job['total']=1; if (!empty($result['ok'])) $job['success']=1; else { $job['unconfirmed']=1; $job['error']='Message delivery not confirmed'; } $job['status']='completed'; return;
        }
        if ($job['kind'] === 'import') {
            $raw = tg_download_file((string)($params['import_file_id'] ?? ''), 8 * 1024 * 1024);
            if ($raw === null || strlen($raw) > 8 * 1024 * 1024) throw new RuntimeException('Unable to download import');
            $items = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items) || array_is_list($items) === false || count($items) > self::MAX_TARGETS) throw new InvalidArgumentException('Invalid import payload');
            foreach ($items as $item) {
                if (!is_array($item) || empty($item['username']) || (float)($item['datalimit'] ?? 0) < 0 || (int)($item['datelimit'] ?? 0) < 0) throw new InvalidArgumentException('Invalid import entry');
            }
            $params['uploaded_json'] = array_values($items);
            unset($params['import_file_id']);
            $job['params'] = $params; $job['kind'] = 'create'; $job['status'] = 'running'; $job['total'] = count($items); return;
        }
        if ($job['kind'] === 'access') {
            $token = $server['type']==='marzneshin' ? MarzneshinClient::getToken($server,true) : MarzbanClient::getToken($server,true);
            if (!$token) throw new RuntimeException('Access refresh failed'); Storage::cacheSet('online_'.$server['id'],time(),86400); $job['success']=1; $job['total']=1; $job['cursor']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'qr') {
            $messageId = (int)($params['message_id'] ?? 0);
            $username = (string)($params['username'] ?? '');
            if ($messageId <= 0 || $username === '') throw new InvalidArgumentException('QR request is incomplete');
            $user = PanelManager::getUser($server, $username);
            if (!$user || empty($user['subscription_url'])) throw new InvalidArgumentException('No subscription link available for QR');
            QrGenerator::sendQrPhoto($job['chat_id'], $user['subscription_url'], Formatter::userInfo($server, $user));
            tg_replace_message($job['chat_id'], $messageId, '✅ QR code sent.', Keyboards::cancel('usr:' . $server['id'] . ':' . $username));
            $job['success']=1; $job['total']=1; $job['cursor']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'revoke_qr') {
            $messageId = (int)($params['message_id'] ?? 0);
            $username = (string)($params['username'] ?? '');
            if ($messageId <= 0 || $username === '') throw new InvalidArgumentException('Revoke request is incomplete');
            $updated = PanelManager::revokeSub($server, $username);
            if (!$updated) throw new RuntimeException('Subscription revoke failed');
            if (!empty($updated['subscription_url'])) {
                QrGenerator::sendQrPhoto($job['chat_id'], $updated['subscription_url'], Formatter::userInfo($server, $updated));
            }
            tg_replace_message($job['chat_id'], $messageId, '✅ Success.', Keyboards::cancel('usr:' . $server['id'] . ':' . $username));
            $job['success']=1; $job['total']=1; $job['cursor']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'stats') {
            if (empty($params['message_id'])) {
                $job['status'] = 'cancelled';
                $job['error'] = 'Stats request has no originating message';
                return;
            }
            $stats = Storage::cacheGet('stats_' . $server['id']);
            if (!is_array($stats)) {
                $stats = PanelManager::getServerStats($server);
                Storage::cacheSet('stats_' . $server['id'], $stats, 30);
            }
            $text = Formatter::statsCard($server, $stats);
            tg_replace_message($job['chat_id'], (int)$params['message_id'], $text, Keyboards::serverMenu((int)$server['id']));
            $job['success'] = 1; $job['total'] = 1; $job['cursor'] = 1; $job['status'] = 'completed'; return;
        }
        if ($job['kind'] === 'monitor') {
            BackgroundTasks::monitorNodes([$server], $params['recipients'] ?? []);
            $job['success'] = 1; $job['total'] = 1; $job['cursor'] = 1; $job['status'] = 'completed'; return;
        }
        if ($job['kind'] === 'expiry') {
            BackgroundTasks::expiredReport([$server], $params['recipients'] ?? [], time());
            $job['success'] = 1; $job['total'] = 1; $job['cursor'] = 1; $job['status'] = 'completed'; return;
        }
        if ($job['status'] === 'discovering') {
            $users = PanelManager::getUsers($server,(int)($params['page']??1),self::PAGE_SIZE,null,$params['status']??null,$params['admin']??null,true);
            $params['targets']=array_merge($params['targets']??[],array_column($users,'username')); $params['page']=(int)($params['page']??1)+1; $job['params']=$params; $job['total']=count($params['targets']); if (count($users)<self::PAGE_SIZE) $job['status']='running'; return;
        }
        if ($job['cursor'] >= $job['total']) { $job['status']='completed'; return; }
        $index=(int)$job['cursor']; $username=$params['targets'][$index]??($params['username']??''); $ok=true;
        switch ($job['kind']) {
            case 'delete': $ok=PanelManager::deleteUser($server,$username); break;
            case 'transfer': $ok=PanelManager::setOwner($server,$username,(string)$params['to_admin']); break;
            case 'admin_status': $ok=!empty($params['active']) ? PanelManager::activateAdminUsers($server,(string)$params['admin']) : PanelManager::disableAdminUsers($server,(string)$params['admin']); break;
            case 'recharge': $ok=(bool)PanelManager::chargeUser($server,(string)$params['username'],$params['data_limit'],(int)$params['date_limit'],!empty($params['reset']),!empty($params['additive']),(string)($params['date_type']??'fixed')); break;
            case 'config':
                $user=PanelManager::getUser($server,$username); if(!$user) throw new RuntimeException('Cannot read user config'); $serviceId=(string)$params['service_id']; $ids=$user['service_ids']??[]; $newIds=!empty($params['add'])?array_merge($ids,[$serviceId]):array_values(array_filter($ids,fn($id)=>(string)$id!==$serviceId)); $ok=PanelManager::updateUserConfigs($server,$username,$newIds); break;
            case 'create':
                $item=$params['uploaded_json'][$index]??null; $name=$item['username']??(($params['username']??'user').($job['total']>1?(string)((int)($params['usersuffix']??1)+$index):''));
                $created = PanelManager::getUser($server, $name) ?: PanelManager::createUser($server, $name, (float)($item['datalimit']??$params['data_limit']??0), (int)($item['datelimit']??$params['date_limit']??0), null, $params['selected_configs']??[], (string)($params['date_type']??'fixed'), $params['admin']??null);
                $ok = $created !== null;
                if ($ok && !empty($params['send_qr'])) {
                    if (!empty($created['subscription_url'])) {
                        QrGenerator::sendQrPhoto($job['chat_id'], $created['subscription_url'], Formatter::userInfo($server, $created));
                    }
                    $messageId = (int)($params['message_id'] ?? 0);
                    if ($messageId > 0) {
                        tg_replace_message($job['chat_id'], $messageId, '✅ User created.', Keyboards::cancel('srv:' . $server['id']));
                        tg_send_message($job['chat_id'], "Let's back...", Keyboards::cancel('srv:' . $server['id']));
                    }
                }
                break;
            default: $job['status']='completed'; return;
        }
        if ($job['kind'] === 'recharge') {
            $messageId = (int)($params['message_id'] ?? 0);
            if ($messageId > 0) tg_replace_message($job['chat_id'], $messageId, $ok ? '✅ Success.' : '❌ Failed', Keyboards::cancel('usr:' . $server['id'] . ':' . (string)$params['username']));
        }
        if($ok) $job['success']++; else $job['unconfirmed']++; $job['cursor']++;
    }

    public static function run(?float $seconds=null, ?int $steps=null): int
    {
        global $config;
        $steps=max(1,min(100,$steps??(int)($config['queue_steps']??10))); $seconds=max(.1,min(45.0,$seconds??(float)($config['queue_budget_seconds']??15.0))); $db=self::db(); $lock=$db->query("SELECT GET_LOCK('holderbot-queue-worker',1)"); if((int)$lock->fetchColumn()!==1)return 0;
        $old=RequestBudget::$deadline; RequestBudget::$deadline=microtime(true)+$seconds; $done=0;
        try { while($done<$steps&&microtime(true)<RequestBudget::$deadline-.1) {
            $row=$db->query("SELECT * FROM bot_queue WHERE next_run<=UNIX_TIMESTAMP() AND status NOT IN ('completed','failed','cancelled') AND (status NOT IN ('inline','inline_running') OR lease_until<=UNIX_TIMESTAMP()) ORDER BY created_at LIMIT 1")->fetch();
            if(!$row)break;
            $job=self::decode($row);
            if (!self::acquireJobLock((string)$job['id'])) { $done++; continue; }
            try {
                $staleInline = in_array($job['status'], ['inline', 'inline_running'], true);
                if ($staleInline) {
                    $job['status'] = 'running';
                    $job['lease_until'] = 0;
                    $job['active'] = 'fallback';
                    self::save($job);
                    self::notifyFallback($job);
                }
                try{self::step($job);unset($job['params']['read_failures']);}
                catch(Throwable $e){$job['error']=$e->getMessage();if($e instanceof InvalidArgumentException){$job['status']='failed';}else{$job['next_run']=time()+30;$job['params']['read_failures']=(int)($job['params']['read_failures']??0)+1;if($job['params']['read_failures']>=3)$job['status']='failed';}}
                self::finalizeMessage($job);
                self::save($job);
            } finally { self::releaseJobLock((string)$job['id']); }
            $done++;
        } }
        finally { $db->query("SELECT RELEASE_LOCK('holderbot-queue-worker')"); RequestBudget::$deadline=$old; }
        return $done;
    }

    public static function health(): array
    { $row=self::db()->query("SELECT COUNT(*) AS pending FROM bot_queue WHERE status NOT IN ('completed','failed','cancelled')")->fetch(); return ['last_run'=>time(),'pending'=>(int)($row['pending']??0),'stale'=>false]; }

    /** Compact old terminal payloads while retaining IDs for deduplication. */
    public static function maintain(?int $retentionDays = null): int
    {
        global $config;
        $days = max(1, $retentionDays ?? (int)($config['queue_retention_days'] ?? 7));
        $stmt = self::db()->prepare("UPDATE bot_queue SET payload='{}' WHERE status IN ('completed','failed','cancelled') AND updated_at < FROM_UNIXTIME(?) AND payload <> '{}' LIMIT 100");
        $stmt->execute([time() - $days * 86400]);
        return $stmt->rowCount();
    }
}
