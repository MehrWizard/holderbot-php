<?php
declare(strict_types=1);

require_once __DIR__ . '/request_budget.php';

/** Persistent batch queue backed by MySQL. Credentials are loaded at run time. */
final class BatchQueue
{
    public const PAGE_SIZE = 50;
    public const MAX_TARGETS = 10000;

    private static function db(): PDO { return Storage::db(); }

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
            'UPDATE bot_queue SET kind=?, payload=?, status=?, cursor_pos=?, total=?, success_count=?, unconfirmed_count=?, skipped_count=?, error_text=?, next_run=?, active_phase=?, notification_status=? WHERE id=?'
        )->execute([
            $job['kind'], json_encode($job['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $job['status'],
            (int)$job['cursor'], (int)$job['total'], (int)$job['success'], (int)$job['unconfirmed'],
            (int)$job['skipped'], $job['error'] ?: null, (int)$job['next_run'], $job['active'] ?: null,
            $job['notification'] ?: 'pending', $job['id']
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
        $allowed = ['delete','transfer','config','create','admin_status','stats','expiry','access','monitor','outbox','import','recharge'];
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

    public static function describe(array $job): string
    {
        $text = "Batch {$job['id']}\nOperation: {$job['kind']}\nStatus: {$job['status']}\nProcessed: {$job['cursor']}/{$job['total']}\nConfirmed: {$job['success']}\nNot confirmed: {$job['unconfirmed']}\nSkipped: {$job['skipped']}";
        return $text . (!empty($job['error']) ? "\n".htmlspecialchars((string)$job['error'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') : '');
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
            $response = tg_edit_message($job['chat_id'], (int)$params['message_id'], $text, Keyboards::serverMenu((int)$server['id']));
            if (empty($response['ok'])) throw new RuntimeException('Unable to deliver statistics');
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
                $item=$params['uploaded_json'][$index]??null; $name=$item['username']??(($params['username']??'user').($job['total']>1?(string)((int)($params['usersuffix']??1)+$index):'')); $ok=(bool)PanelManager::createUser($server,$name,(float)($item['datalimit']??$params['data_limit']??0),(int)($item['datelimit']??$params['date_limit']??0),null,$params['selected_configs']??[],(string)($params['date_type']??'fixed'),$params['admin']??null); break;
            default: $job['status']='completed'; return;
        }
        if($ok) $job['success']++; else $job['unconfirmed']++; $job['cursor']++;
    }

    public static function run(?float $seconds=null, ?int $steps=null): int
    {
        global $config;
        $steps=max(1,min(100,$steps??(int)($config['queue_steps']??10))); $seconds=max(.1,min(45.0,$seconds??(float)($config['queue_budget_seconds']??15.0))); $db=self::db(); $lock=$db->query("SELECT GET_LOCK('holderbot-queue-worker',1)"); if((int)$lock->fetchColumn()!==1)return 0;
        $old=RequestBudget::$deadline; RequestBudget::$deadline=microtime(true)+$seconds; $done=0;
        try { while($done<$steps&&microtime(true)<RequestBudget::$deadline-.1) { $row=$db->query("SELECT * FROM bot_queue WHERE next_run<=UNIX_TIMESTAMP() AND status NOT IN ('completed','failed','cancelled') ORDER BY created_at LIMIT 1")->fetch(); if(!$row)break; $job=self::decode($row); try{self::step($job);unset($job['params']['read_failures']);}catch(Throwable $e){$job['error']=$e->getMessage();if($e instanceof InvalidArgumentException){$job['status']='failed';}else{$job['next_run']=time()+30;$job['params']['read_failures']=(int)($job['params']['read_failures']??0)+1;if($job['params']['read_failures']>=3)$job['status']='failed';}} self::save($job);$done++;} }
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
