<?php
declare(strict_types=1);

require_once __DIR__ . '/request_budget.php';
require_once __DIR__ . '/import_reader.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/reconciliation.php';

/** Persistent batch queue backed by MySQL. Credentials are loaded at run time. */
final class BatchQueue
{
    public const PAGE_SIZE = 50;
    public const MAX_TARGETS = 100000;
    private const TELEGRAM_TEXT_LIMIT = 4096;

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
        $ownsTransaction = !self::db()->inTransaction();
        if ($ownsTransaction) self::db()->beginTransaction();
        try {
        self::db()->prepare(
            'UPDATE bot_queue SET kind=?, payload=?, status=?, cursor_pos=?, total=?, success_count=?, unconfirmed_count=?, skipped_count=?, error_text=?, next_run=?, active_phase=?, notification_status=?, lease_until=? WHERE id=?'
        )->execute([
            $job['kind'], json_encode($job['params'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $job['status'],
            (int)$job['cursor'], (int)$job['total'], (int)$job['success'], (int)$job['unconfirmed'],
            (int)$job['skipped'], $job['error'] ?: null, (int)$job['next_run'], $job['active'] ?: null,
            $job['notification'] ?: 'pending', (int)($job['lease_until'] ?? 0), $job['id']
        ]);
        foreach ($job['params']['notifications'] ?? [] as $notice) {
            NotificationOutbox::stage($job['id'], $notice['key'], $notice['chat_id'] ?? $job['chat_id'], $notice['payload']);
        }
        if (in_array($job['status'], ['completed','failed','cancelled'], true) && !empty($job['params']['message_id']) && empty($job['params']['custom_result'])) {
            NotificationOutbox::stage($job['id'],'final',$job['chat_id'],[
                'message_id'=>(int)$job['params']['message_id'], 'text'=>self::describe($job), 'keyboard'=>self::keyboard($job),
            ]);
        }
        if ($ownsTransaction) self::db()->commit();
        } catch (Throwable $e) { if ($ownsTransaction && self::db()->inTransaction()) self::db()->rollBack(); throw $e; }
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
        self::db()->prepare("UPDATE bot_queue SET cancel_requested=1 WHERE id=? AND status NOT IN ('completed','failed','cancelled')")->execute([$id]);
        if (!self::acquireJobLock($id)) return;
        try {
            $job = self::get($id);
            if (!$job || in_array($job['status'], ['completed','failed','cancelled'], true)) return;
            // A released lock can also mean the previous PHP process died.
            // Preserve uncertain mutation evidence even when cancellation follows.
            if (($job['active'] ?? '') === 'mutation' || ($job['status'] === 'inline_running' && in_array($job['kind'], ['create','recharge','reset','user_mutation','revoke_qr'], true))) {
                $job['status'] = 'failed';
                $job['unconfirmed']++;
                $job['error'] = 'Interrupted mutation: verify the panel before retrying.';
            } else {
                $job['status'] = 'cancelled';
            }
            self::save($job);
            self::finalizeMessage($job);
        } finally { self::releaseJobLock($id); }
    }

    public static function enqueue(string $kind, array $server, array $params, int|string $chatId, int $userId, string $submission): array
    {
        $allowed = ['delete','transfer','config','create','admin_status','stats','expiry','access','monitor','outbox','import','recharge','reset','qr','revoke_qr'];
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
            $status = in_array($kind, ['delete','transfer','config','admin_status'], true) ? 'discovering' : 'running';
            $total = in_array($kind, ['outbox','recharge'], true) ? 1 : (in_array($kind, ['create','import'], true) ? max(1,$targetCount) : 0);
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
        $allowed = ['create', 'recharge', 'reset', 'user_mutation', 'qr', 'revoke_qr', 'stats'];
        if (!in_array($kind, $allowed, true)) throw new InvalidArgumentException('Unsupported inline operation');
        $serverId = (int)($server['id'] ?? 0);
        $id = substr(hash('sha256', json_encode([$chatId, $userId, $submission, $kind, $serverId, $params], JSON_THROW_ON_ERROR)), 0, 32);
        $submissionKey = hash('sha256', json_encode([$chatId, $userId, $submission, $kind, $serverId], JSON_THROW_ON_ERROR));
        $lease = time() + max(15, (int)($config['inline_lease_seconds'] ?? 120));
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
     * Unconfirmed mutations are held for review; read-only failures can fall back.
     */
    public static function executeInline(array $job, callable $operation): array
    {
        global $config;
        $id = (string)$job['id'];
        if (!self::acquireJobLock($id)) return ['state' => 'locked', 'job' => $job, 'result' => null];
        try {
            $current = self::get($id);
            if (!$current) return ['state' => 'missing', 'job' => $job, 'result' => null];
            if ($current['status'] !== 'inline') {
                return ['state' => $current['status'], 'job' => $current, 'result' => null];
            }
            if (!empty($current['cancel_requested'])) {
                $current['status'] = 'cancelled';
                self::save($current);
                self::finalizeMessage($current);
                return ['state' => 'cancelled', 'job' => $current, 'result' => null];
            }
            $current['status'] = 'inline_running';
            $current['lease_until'] = time() + max(15, (int)($config['inline_lease_seconds'] ?? 120));
            $current['active'] = 'inline';
            self::save($current);
            try {
                $result = $operation($current);
                if ($result === null || $result === false) throw new RuntimeException('Operation outcome was not confirmed');
                $current['status'] = 'completed';
                $current['cursor'] = 1;
                $current['total'] = 1;
                $current['success'] = 1;
                $current['active'] = null;
                $current['lease_until'] = 0;
                if(in_array($current['kind'],['create','recharge','reset','user_mutation','revoke_qr'],true)) self::invalidateStats((int)$current['server_id']);
                self::resultNotices($current, is_array($result) ? $result : []);
                self::save($current);
                self::finalizeMessage($current);
                return ['state' => 'completed', 'job' => $current, 'result' => $result];
            } catch (InvalidArgumentException $e) {
                $current['status'] = 'failed';
                $current['lease_until'] = 0;
                $current['active'] = null;
                $current['error'] = $e->getMessage();
                self::save($current);
                return ['state' => 'failed', 'job' => $current, 'result' => null, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $current['status'] = in_array($current['kind'], ['create', 'recharge', 'reset', 'user_mutation', 'revoke_qr'], true) ? 'failed' : 'running';
                if ($current['status'] === 'failed') $current['unconfirmed'] = 1;
                $current['next_run'] = time();
                $current['lease_until'] = 0;
                $current['active'] = 'fallback';
                $current['error'] = 'Inline attempt failed: ' . $e->getMessage();
                self::save($current);
                return ['state' => $current['status'] === 'failed' ? 'failed' : 'queued', 'job' => $current, 'result' => null, 'error' => $e->getMessage()];
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

    private static function resultNotices(array &$job, array $result): void
    {
        if (empty($job['params']['message_id'])) return;
        $notices=[];
        if (in_array($job['kind'],['create','revoke_qr','qr'],true) && !empty($result['subscription_url'])) {
            $server=Storage::getServer((int)$job['server_id']);
            $notices[]=['key'=>'photo','payload'=>['photo'=>$result['subscription_url'],'text'=>Formatter::userInfo($server,$result)]];
        }
        $text=match($job['kind']) { 'create'=>'✅ User created.', 'qr'=>'QR request processed.', default=>'✅ Success.' };
        $back=isset($job['params']['username']) && $job['kind']!=='create' && ($job['params']['operation']??'')!=='delete' ? 'usr:'.$job['server_id'].':'.$job['params']['username'] : 'srv:'.$job['server_id'];
        $notices[]=['key'=>'final','payload'=>['message_id'=>(int)$job['params']['message_id'],'text'=>$text,'keyboard'=>Keyboards::cancel($back)]];
        $job['params']['notifications']=$notices;
        $job['params']['custom_result']=true;
    }

    /** Called while the job lock is held; persist absolute intent before each write. */
    public static function recharge(array &$job, array $server): ?array
    {
        $params = $job['params'];
        return PanelManager::chargeUser($server, (string)$params['username'], $params['data_limit'], (int)$params['date_limit'],
            !empty($params['reset']), !empty($params['additive']), (string)($params['date_type'] ?? 'fixed'),
            function (string $phase, array $payload, array $before = []) use (&$job): void {
                $job['params']['mutation_intent'] = ['username'=>$job['params']['username'], 'phase'=>$phase, 'payload'=>$payload, 'before'=>$before];
                $job['active'] = 'mutation';
                self::save($job);
            });
    }

    public static function creationCheckpoint(array &$job): Closure
    {
        return function(string $phase,array $before,array $payload)use(&$job):void {
            $job['params']['mutation_intent']=['username'=>$before['username'],'phase'=>$phase,'before'=>$before,'payload'=>$payload];
            $job['active']='mutation'; self::save($job);
            if($phase==='assign_created_owner') self::invalidateStats((int)$job['server_id']);
        };
    }

    /** Bounded, credential-free evidence for operator review. */
    public static function issues(string $id, int $after = -1): array
    {
        $job = self::get($id);
        if (!$job) throw new InvalidArgumentException('Job not found');
        $select = self::db()->prepare("SELECT position,username,status FROM bot_queue_items WHERE job_id=? AND status='uncertain' AND position>? ORDER BY position LIMIT 50");
        $select->execute([$id,$after]);
        return ['intent'=>$job['params']['mutation_intent'] ?? null, 'items'=>$select->fetchAll()];
    }

    public static function reconcile(string $id): array
    {
        if (!self::acquireJobLock($id)) return ['state'=>'busy'];
        try {
            $job=self::get($id);
            if (!$job || $job['status']!=='failed' || (int)$job['unconfirmed']<1) return ['state'=>'not_uncertain'];
            $server=Storage::getServer((int)$job['server_id']);
            if (!$server || self::fingerprint($server)!==$job['server_fingerprint']) return ['state'=>'server_changed'];
            $intent=$job['params']['mutation_intent'] ?? [];
            $username=$intent['username'] ?? $job['params']['username'] ?? '';
            if ($username==='') return ['state'=>'insufficient_evidence'];
            try { $probe=PanelManager::probeUser($server,$username); }
            catch(Throwable) { $probe=['confirmed'=>false,'user'=>null]; }
            $user=$probe['user'] ?? null;
            $comparison=!empty($probe['confirmed']) ? MutationReconciliation::compare($server['type'],$intent,$user['raw'] ?? null) : ['state'=>'read_unconfirmed'];
            $job['params']['reconciliation']=['checked_at'=>time(),'attempts'=>(int)($job['params']['reconciliation']['attempts'] ?? 0)+1,'comparison'=>$comparison];
            $resolved=in_array($comparison['state'] ?? '',['desired_state_observed','subscription_changed','reset_marker_changed'],true);
            $position=$intent['position'] ?? null;
            if($resolved && $position!==null && in_array($job['kind'],['delete','transfer','config','admin_status'],true)) {
                self::db()->prepare("UPDATE bot_queue_items SET status='succeeded' WHERE job_id=? AND position=? AND status='uncertain'")->execute([$job['id'],(int)$position]);
                $job['status']='running'; $job['cursor']=max((int)$job['cursor'],(int)$position+1); $job['success']++;
                $job['unconfirmed']=max(0,(int)$job['unconfirmed']-1); $job['active']=null; $job['error']=null;
                self::invalidateStats((int)$job['server_id']);
            }
            if ($resolved && in_array($job['kind'],['recharge','reset','user_mutation','revoke_qr','create'],true)) {
                $job['status']='completed'; $job['success']=1; $job['cursor']=1; $job['total']=1; $job['unconfirmed']=0;
                $job['active']=null; $job['error']=null;
                self::invalidateStats((int)$job['server_id']);
                $label=match($job['kind']) {'create'=>'User creation','reset'=>'Usage reset','user_mutation'=>'User update','revoke_qr'=>'Subscription revocation',default=>'Recharge'};
                $job['params']['notifications']=[['key'=>'reconciled','payload'=>[
                    'message_id'=>(int)($job['params']['message_id'] ?? 0),
                    'text'=>$label.' reconciled: the panel currently matches the saved target values. No mutation was replayed.',
                    'keyboard'=>self::keyboard($job),
                ]]];
                $job['params']['custom_result']=true;
            }
            self::save($job);
            return $comparison;
        } finally { self::releaseJobLock($id); }
    }

    public static function revoke(array &$job, array $server): ?array
    {
        $username = (string)$job['params']['username'];
        $before = PanelManager::getUser($server, $username);
        if (!$before || empty($before['subscription_url'])) throw new RuntimeException('Cannot read subscription before revoke');
        $job['params']['mutation_intent'] = ['username'=>$username, 'phase'=>'revoke_subscription',
            'before'=>$before['raw'] ?? [], 'payload'=>['previous_subscription_sha256'=>hash('sha256', $before['subscription_url'])]];
        $job['active'] = 'mutation';
        self::save($job);
        return PanelManager::revokeSub($server, $username);
    }

    public static function reset(array &$job, array $server): bool
    {
        $username=(string)$job['params']['username'];
        $before=PanelManager::getUser($server,$username);
        if (!$before) throw new RuntimeException('Cannot read user before usage reset');
        $job['params']['mutation_intent']=['username'=>$username,'phase'=>'reset_usage','before'=>$before['raw'],'payload'=>[]];
        $job['active']='mutation'; self::save($job);
        return PanelManager::resetUsage($server,$username);
    }

    public static function mutateUser(array &$job,array $server): bool
    {
        $p=$job['params']; $username=(string)($p['username'] ?? ''); $operation=(string)($p['operation'] ?? '');
        $before=PanelManager::getUser($server,$username);
        if(!$before) throw new RuntimeException('Cannot read user before mutation');
        $raw=$before['raw']; $expected=[];
        $call=match($operation) {
            'status'=>fn()=>PanelManager::setStatus($server,$username,(bool)$p['active']),
            'data'=>fn()=>PanelManager::modifyUserDataLimit($server,$username,(float)$p['value']),
            'date'=>fn()=>PanelManager::updateDateLimit($server,$username,(int)$p['days'],(string)$p['date_type']),
            'note'=>fn()=>PanelManager::modifyUserNote($server,$username,(string)$p['note']),
            'owner'=>fn()=>PanelManager::setOwner($server,$username,(string)$p['owner']),
            'config'=>fn()=>PanelManager::updateUserConfigs($server,$username,$p['ids']),
            'delete'=>function()use($server,$username){return PanelManager::deleteUser($server,$username);},
            default=>throw new InvalidArgumentException('Unsupported user mutation'),
        };
        $phase=$operation==='delete'?'delete_user':'modify_user';
        if($operation!=='delete') {
            match($operation) {
                'status'=>$expected=['enabled'=>(bool)$p['active']],
                'data'=>$expected=['data_limit'=>(int)round((float)$p['value']*1024**3)],
                'date'=>$expected=PanelManager::datePayload($server,$username,(int)$p['days'],(string)$p['date_type']),
                'note'=>$expected=['note'=>(string)$p['note']],
                'owner'=>$expected=['owner_username'=>(string)$p['owner']],
                'config'=>$expected=[$server['type']==='marzneshin'?'service_ids':'selected_configs'=>array_values($p['ids'])],
            };
        }
        $job['params']['mutation_intent']=['username'=>$username,'phase'=>$phase,'before'=>$raw,'payload'=>$expected];
        $job['active']='mutation'; self::save($job);
        $ok=$call();
        return $ok;
    }

    public static function invalidateStats(int $serverId): void { Storage::cacheDelete('stats_result_'.$serverId); }

    public static function submitUserMutation(array $server,array $params,int|string $chatId,int $userId,string $submission): array
    {
        $job=self::enqueueInline('user_mutation',$server,$params,$chatId,$userId,$submission);
        return self::executeInline($job,fn(array &$running)=>self::mutateUser($running,$server));
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
        $old=RequestBudget::$deadline;
        if ($old===null) RequestBudget::$deadline=microtime(true)+5;
        try { NotificationOutbox::drain(3, $job['id']); }
        catch (Throwable $e) { error_log('Notification remains queued: '.$e->getMessage()); }
        finally { RequestBudget::$deadline=$old; }
    }

    public static function message(int|string $chatId, int $userId, string $text, string $key, ?array $keyboard=null): array
    { return self::enqueue('outbox',['id'=>0,'type'=>'internal','base_url'=>'','username'=>''],['text'=>$text,'keyboard'=>$keyboard],$chatId,$userId,$key); }

    public static function photo(int|string $chatId, int $userId, string $url, string $caption, string $key): array
    { return self::enqueue('outbox',['id'=>0,'type'=>'internal','base_url'=>'','username'=>''],['photo'=>$url,'text'=>$caption],$chatId,$userId,$key); }

    public static function keyboard(array $job): array
    {
        $rows = [[['text'=>'Refresh status','callback_data'=>'job:'.$job['id']]]];
        if (!in_array($job['status'],['completed','failed','cancelled'],true)) $rows[]=[['text'=>'Cancel remaining work','callback_data'=>'job_cancel:'.$job['id']]];
        if ((int)$job['server_id']>0) $rows[]=[['text'=>'Back','callback_data'=>'queue_back:'.$job['server_id']]];
        $rows[]=[['text'=>'Home','callback_data'=>'queue_home']];
        return ['inline_keyboard'=>$rows];
    }

    public static function alertText(array $job): string
    {
        $text = html_entity_decode(strip_tags(self::describe($job)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/\A.{0,187}/us', $text, $match);
        $prefix = $match[0] ?? '';
        return $prefix === $text ? $text : $prefix . '...';
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

    public static function loadingMessage(string $kind): string
    {
        return [
            'create' => 'Creating users...',
            'import' => 'Importing users...',
            'delete' => 'Deleting users...',
            'transfer' => 'Transferring user ownership...',
            'config' => 'Updating user configurations...',
            'admin_status' => 'Updating user statuses...',
            'stats' => 'Loading server statistics...',
            'access' => 'Refreshing panel access...',
            'monitor' => 'Checking nodes...',
            'expiry' => 'Preparing expiry report...',
            'outbox' => 'Delivering message...',
            'recharge' => 'Updating user...',
            'qr' => 'Generating QR code...',
            'revoke_qr' => 'Updating subscription...',
        ][$kind] ?? 'Loading...';
    }

    public static function describe(array $job): string
    {
        $label = self::label((string)$job['kind']);
        if (!empty($job['cancel_requested']) && !in_array($job['status'], ['completed','failed','cancelled'], true)) return 'Cancellation requested. The current step may finish; remaining work will stop.';
        $status = (string)($job['status'] ?? 'running');
        if (!empty($job['params']['report_ready']) && !in_array($status, ['completed','failed','cancelled'], true)) return 'Delivering report messages.';
        if ($status === 'completed') {
            if ($job['kind'] === 'stats') return '📊 Server statistics updated.';
            $text = '✅ ' . ucfirst($label) . " completed.\nProcessed: " . (int)$job['cursor'] . ' item(s).';
            if ((int)($job['unconfirmed'] ?? 0) > 0) $text .= "\nUnconfirmed: " . (int)$job['unconfirmed'] . '. Check the panel before retrying.';
            return $text;
        }
        if ($status === 'failed') {
            $text = '❌ ' . ucfirst($label) . ' failed.';
            return $text . (!empty($job['error']) ? "\n" . htmlspecialchars((string)$job['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '');
        }
        if ($status === 'cancelled') return '⛔ ' . ucfirst($label) . ' cancelled.';

        if ($status === 'discovering') {
            return '⏳ Finding users for ' . $label . '.' .
                ((int)($job['total'] ?? 0) > 0 ? "\nDiscovered: " . (int)$job['total'] . '.' : '');
        }
        if ($job['kind'] === 'stats') {
            $page = max(0, (int)($job['params']['page'] ?? 1) - 1);
            if (($job['active'] ?? '') === 'scan') return 'Fetching statistics page ' . ($page + 1) . '. Pages saved: ' . $page . '.';
            return $page > 0
                ? "⏳ Statistics scan in progress.\nPages scanned: {$page}."
                : '⏳ Statistics scan queued; first page is pending.';
        }
        if ($job['kind'] === 'expiry') {
            $page = max(0, (int)($job['params']['scan']['page'] ?? 1) - 1);
            return $page > 0
                ? "⏳ Expiry scan in progress.\nPages scanned: {$page}."
                : '⏳ Expiry scan queued; first page is pending.';
        }
        if (($job['active'] ?? '') === 'inline') return '⏳ Executing the request now.';
        if (($job['active'] ?? '') === 'fallback') {
            return '⏳ The inline request exceeded its time limit. Queue processing is continuing.';
        }
        $text = '⏳ ' . (($job['active'] ?? '') === 'mutation' ? 'Applying ' . $label . '.' : self::loadingMessage((string)$job['kind']));
        $total = (int)($job['total'] ?? 0);
        if ($total > 0) $text .= "\nProcessed: " . (int)$job['cursor'] . "/{$total}.";
        if ($total === 0) $text .= "\nWaiting for the worker to start.";
        return $text;
    }

    private static function storeReportPage(string $jobId, int $page, array $entries): void
    {
        $insert = self::db()->prepare('INSERT IGNORE INTO bot_queue_items (job_id,position,username,payload) VALUES (?,?,?,?)');
        $chunks = []; $chunk = '';
        foreach ($entries as $entry) {
            $candidate=$chunk . ($chunk === '' ? '' : ',') . $entry;
            if ($chunk !== '' && self::telegramTextLength($candidate) > self::TELEGRAM_TEXT_LIMIT) { $chunks[] = $chunk; $chunk = ''; }
            $chunk .= ($chunk === '' ? '' : ',') . $entry;
        }
        if ($chunk !== '') $chunks[] = $chunk;
        foreach ($chunks as $index => $entry) {
            $position = ($page - 1) * 1000 + $index;
            $insert->execute([$jobId, $position, 'report_' . $position, json_encode(['text'=>$entry], JSON_THROW_ON_ERROR)]);
        }
    }

    /** Telegram applies its text limit after parsing HTML entities and tags. */
    private static function telegramTextLength(string $html): int
    {
        $plain=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (function_exists('mb_convert_encoding')) return intdiv(strlen(mb_convert_encoding($plain,'UTF-16LE','UTF-8')),2);
        if (function_exists('iconv')) {
            $utf16=iconv('UTF-8','UTF-16LE//IGNORE',$plain);
            if ($utf16!==false) return intdiv(strlen($utf16),2);
        }
        return strlen($plain);
    }

    private static function prepareStatsDelivery(array &$job, array $server, array $stats): string
    {
        $select=self::db()->prepare("SELECT payload FROM bot_queue_items WHERE job_id=? AND status='pending' ORDER BY position");
        $select->execute([$job['id']]); $entries=[];
        foreach($select->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $text=(string)(json_decode((string)$payload,true,512,JSON_THROW_ON_ERROR)['text'] ?? '');
            foreach(explode(',',$text) as $entry) if($entry!=='') $entries[]=$entry;
        }
        $stats['today_expired']=$entries;
        $full=Formatter::statsCard($server,$stats);
        if(self::telegramTextLength($full)<=self::TELEGRAM_TEXT_LIMIT) {
            self::db()->prepare('DELETE FROM bot_queue_items WHERE job_id=?')->execute([$job['id']]);
            return $full;
        }
        $footer="\nSee the following report messages for the complete list.";
        $stats['today_expired']=[$footer];
        $summary=Formatter::statsCard($server,$stats);
        if(self::telegramTextLength($summary)>self::TELEGRAM_TEXT_LIMIT) throw new LengthException('Statistics summary exceeds Telegram text limit');
        self::db()->prepare('DELETE FROM bot_queue_items WHERE job_id=?')->execute([$job['id']]);
        self::storeReportPage($job['id'],1,$entries);
        return $summary;
    }

    private static function cacheStatsResult(array $job, string $text): void
    {
        $select=self::db()->prepare("SELECT payload FROM bot_queue_items WHERE job_id=? AND status='pending' ORDER BY position");
        $select->execute([$job['id']]); $chunks=[];
        foreach($select->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $chunks[]=(string)(json_decode((string)$payload,true,512,JSON_THROW_ON_ERROR)['text'] ?? '');
        }
        Storage::cacheSet('stats_result_'.(int)$job['server_id'],[
            'server_id'=>(int)$job['server_id'],'text'=>$text,'chunks'=>$chunks,'generated_at'=>time(),
        ],30*86400);
    }

    public static function cachedStats(int $serverId): ?array
    {
        $cached=Storage::cacheGet('stats_result_'.$serverId);
        return is_array($cached) && (int)($cached['server_id'] ?? 0)===$serverId && is_string($cached['text'] ?? null) && is_array($cached['chunks'] ?? null) ? $cached : null;
    }

    private static function monitorStep(array &$job, array $server): void
    {
        if (empty($server['node_monitoring'])) { $job['status']='cancelled'; return; }
        if (empty($job['params']['nodes_staged'])) {
            $nodes = PanelManager::getNodes($server, true);
            $insert = self::db()->prepare("INSERT IGNORE INTO bot_queue_items(job_id,position,username,payload,status) VALUES(?,?,?,?,'node_pending')");
            self::db()->beginTransaction();
            try {
                foreach ($nodes as $i=>$node) {
                    if (!isset($node['id'])) throw new InvalidArgumentException('Panel returned a node without an ID');
                    $insert->execute([$job['id'],$i,'node_'.$node['id'],json_encode($node,JSON_THROW_ON_ERROR)]);
                }
                $job['total']=count($nodes); $job['params']['nodes_staged']=true;
                self::save($job); self::db()->commit();
            } catch (Throwable $e) { self::db()->rollBack(); throw $e; }
            return;
        }
        $select=self::db()->prepare("SELECT position,payload FROM bot_queue_items WHERE job_id=? AND status='node_pending' ORDER BY position LIMIT 1");
        $select->execute([$job['id']]); $item=$select->fetch();
        if (!$item) { $job['params']['report_ready']=true; return; }
        $node=json_decode($item['payload'],true,512,JSON_THROW_ON_ERROR);
        $text=null;
        if (!PanelManager::isNodeOk($node,$server['type'])) {
            // Bound upstream diagnostic text before HTML escaping.
            $field=static function($value): string {
                preg_match('/\A.{0,250}/us',(string)$value,$match);
                return Formatter::escape($match[0] ?? '');
            };
            $text='<b>Node error</b> — '.$field($server['remark'])."\n".
                '<b>Node Remark:</b> <code>'.$field($node['name'] ?? $node['remark'] ?? '')."</code>\n".
                '<b>Node Address:</b> <code>'.$field($node['address'] ?? '')."</code>\n".
                '<b>Node Message:</b> <code>'.$field($node['message'] ?? 'None').'</code>';
            if (!empty($server['node_restart'])) {
                $job['params']['mutation_intent']=['phase'=>'node_restart','node_id'=>(int)$node['id']];
                $job['active']='mutation'; self::save($job);
                $ok=PanelManager::restartNode($server,(int)$node['id']);
                $text.="\n<b>Restart:</b> ".($ok ? 'Confirmed' : 'Not confirmed; review the panel.');
                if (!$ok) $job['unconfirmed']++;
            }
        }
        self::db()->beginTransaction();
        try {
            self::db()->prepare('UPDATE bot_queue_items SET payload=?,status=? WHERE job_id=? AND position=?')->execute([
                json_encode(['text'=>$text],JSON_THROW_ON_ERROR),$text===null ? 'node_done' : 'pending',$job['id'],$item['position'],
            ]);
            $job['active']=null; $job['cursor']++; $job['success']++;
            self::save($job); self::db()->commit();
        } catch (Throwable $e) { self::db()->rollBack(); throw $e; }
    }

    private static function step(array &$job): void
    {
        $params = $job['params']; $server = $job['server_id']>0 ? Storage::getServer((int)$job['server_id']) : null;
        if ($job['kind'] !== 'outbox' && (!$server || self::fingerprint($server) !== $job['server_fingerprint'])) { $job['status']='failed'; $job['error']='Server removed or identity changed'; return; }
        if (!empty($params['report_ready'])) {
            $select = self::db()->prepare("SELECT position,payload FROM bot_queue_items WHERE job_id=? AND status='pending' ORDER BY position LIMIT 1");
            $select->execute([$job['id']]);
            $part = $select->fetch();
            if (!$part) { $job['status']='completed'; $job['success']=1; return; }
            $payload = json_decode($part['payload'], true, 512, JSON_THROW_ON_ERROR);
            $recipients = array_values($params['recipients'] ?? [$job['chat_id']]);
            $index = (int)($payload['recipient_cursor'] ?? 0);
            $payload['recipient_cursor'] = $index + 1;
            self::db()->beginTransaction();
            try {
                if (isset($recipients[$index])) NotificationOutbox::stage($job['id'], 'report_'.$part['position'].'_'.$index, $recipients[$index], ['text'=>$payload['text']]);
                self::db()->prepare('UPDATE bot_queue_items SET payload=?,status=? WHERE job_id=? AND position=?')->execute([
                    json_encode($payload, JSON_THROW_ON_ERROR), $index + 1 >= count($recipients) ? 'delivered' : 'pending', $job['id'], $part['position'],
                ]);
                self::db()->commit();
            } catch (Throwable $e) { self::db()->rollBack(); throw $e; }
            NotificationOutbox::drain(1,$job['id']);
            return;
        }
        if ($job['kind'] === 'outbox') {
            $job['params']['notifications']=[['key'=>'outbox','payload'=>['text'=>$params['text'],'keyboard'=>$params['keyboard']??null]+(isset($params['photo'])?['photo'=>$params['photo']]:[])]];
            $job['active']=null; $job['cursor']=1; $job['total']=1; $job['success']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'import') {
            if (empty($params['import_staged'])) {
                $raw = tg_download_file((string)($params['import_file_id'] ?? ''), 8 * 1024 * 1024);
                if ($raw === null) throw new RuntimeException('Unable to download import');
                self::db()->prepare('INSERT INTO bot_queue_imports (job_id,content) VALUES (?,?) ON DUPLICATE KEY UPDATE content=VALUES(content)')->execute([$job['id'], $raw]);
                $job['params']['import_staged'] = true;
                return;
            }
            $read = function(int $offset, int $length) use ($job): string {
                $stmt = self::db()->prepare('SELECT SUBSTRING(content,?,?) FROM bot_queue_imports WHERE job_id=?');
                $stmt->execute([$offset + 1, $length, $job['id']]);
                $value = $stmt->fetchColumn();
                if ($value === false) throw new RuntimeException('Missing import source');
                return (string)$value;
            };
            $page = ImportReader::page($read, (int)($params['import_offset'] ?? 0), !empty($params['import_offset']));
            $count = (int)($params['import_count'] ?? 0);
            if ($count + count($page['items']) > self::MAX_TARGETS) throw new InvalidArgumentException('Too many import entries');
            self::db()->beginTransaction();
            try {
                $insert = self::db()->prepare('INSERT INTO bot_queue_items (job_id,position,username,payload) VALUES (?,?,?,?)');
                foreach ($page['items'] as $item) {
                    if (!is_string($item['username'] ?? null) || $item['username'] === '' || (float)($item['datalimit'] ?? 0) < 0 || (int)($item['datelimit'] ?? 0) < 0) throw new InvalidArgumentException('Invalid import entry');
                    try { $insert->execute([$job['id'], $count++, $item['username'], json_encode($item, JSON_THROW_ON_ERROR)]); }
                    catch (PDOException $e) { if ($e->getCode() === '23000') throw new InvalidArgumentException('Duplicate import username', 0, $e); throw $e; }
                }
                $job['params']['import_offset'] = $page['offset'];
                $job['params']['import_count'] = $count;
                if ($page['done']) { $job['params']['import_rows']=true; $job['kind']='create'; $job['total']=$count; }
                self::save($job);
                self::db()->commit();
            } catch (Throwable $e) { self::db()->rollBack(); throw $e; }
            return;
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
            self::resultNotices($job,$user);
            $job['success']=1; $job['total']=1; $job['cursor']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'revoke_qr') {
            $messageId = (int)($params['message_id'] ?? 0);
            $username = (string)($params['username'] ?? '');
            if ($messageId <= 0 || $username === '') throw new InvalidArgumentException('Revoke request is incomplete');
            $updated = self::revoke($job, $server);
            if (!$updated) throw new RuntimeException('Subscription revoke failed');
            self::resultNotices($job,$updated);
            $job['active']=null; $job['success']=1; $job['total']=1; $job['cursor']=1; $job['status']='completed'; return;
        }
        if ($job['kind'] === 'stats') {
            if (empty($params['message_id'])) {
                $job['status'] = 'cancelled';
                $job['error'] = 'Stats request has no originating message';
                return;
            }
            $job['active'] = 'scan';
            self::save($job);
            $stats = null;
            if (!is_array($stats)) {
                $stats = $params['stats'] ?? PanelManager::statsForUsers($server, [], time());
                $page = max(1, (int)($params['page'] ?? 1));
                $users = PanelManager::getUsers($server, $page, PanelManager::pageSize($server), null, null, null, true);
                $part = PanelManager::statsForUsers($server, $users, (int)($params['now'] ?? time()), PanelManager::getBotUsername());
                self::storeReportPage($job['id'], $page, $part['today_expired']);
                $part['today_expired'] = [];
                foreach ($part as $key => $value) $stats[$key] = is_array($value) ? array_merge($stats[$key] ?? [], $value) : (int)($stats[$key] ?? 0) + (int)$value;
                if (count($users) >= PanelManager::pageSize($server)) {
                    $params['stats'] = $stats;
                    $params['page'] = $page + 1;
                    $params['now'] = (int)($params['now'] ?? time());
                    $job['params'] = $params;
                    return;
                }
            }
            $text=self::prepareStatsDelivery($job,$server,$stats);
            self::cacheStatsResult($job,$text);
            $job['params']['notifications']=[['key'=>'final','payload'=>['message_id'=>(int)$params['message_id'],'text'=>$text,'keyboard'=>Keyboards::stats((int)$server['id'])]]];
            $job['params']['custom_result']=true;
            $job['params']['report_ready'] = true;
            $job['success'] = 1; $job['total'] = 1; $job['cursor'] = 1; return;
        }
        if ($job['kind'] === 'monitor') {
            self::monitorStep($job, $server);
            return;
        }
        if ($job['kind'] === 'expiry') {
            $scan = BackgroundTasks::expiryPage($server, $params['scan'] ?? [], (int)($params['now'] ?? time()));
            $botUsername = PanelManager::getBotUsername();
            self::storeReportPage($job['id'], $scan['page'] - 1, array_map(function($name) use ($botUsername, $server) {
                $label = '<code>' . Formatter::escape($name) . '</code>';
                return $botUsername === '' ? $label : '<a href="https://t.me/' . Formatter::escape($botUsername) . '?start=user_' . (int)$server['id'] . '_' . rawurlencode($name) . '">' . $label . '</a>';
            }, $scan['names']));
            $scan['names'] = [];
            if (!$scan['done']) {
                $params['scan'] = $scan;
                $job['params'] = $params;
                return;
            }
            self::storeReportPage($job['id'], 0, ['Expiry report for ' . Formatter::escape($server['remark']) . ': ' . $scan['matched'] . ' matching users out of ' . $scan['total'] . '.']);
            $job['params']['report_ready'] = true;
            $job['success'] = 1; $job['total'] = 1; $job['cursor'] = 1; return;
        }
        if ($job['status'] === 'discovering') {
            $job['active'] = 'discovering';
            $users = PanelManager::getUsers($server,(int)($params['page']??1),self::PAGE_SIZE,null,$params['status']??null,$params['admin']??null,true);
            $insert = self::db()->prepare('INSERT IGNORE INTO bot_queue_items (job_id,position,username) VALUES (?,?,?)');
            $offset = ((int)($params['page'] ?? 1) - 1) * self::PAGE_SIZE;
            foreach ($users as $i => $user) $insert->execute([$job['id'], $offset + $i, $user['username']]);
            $params['page'] = (int)($params['page'] ?? 1) + 1;
            $job['params'] = $params;
            $count = self::db()->prepare('SELECT COUNT(*) FROM bot_queue_items WHERE job_id=?');
            $count->execute([$job['id']]);
            $job['total'] = (int)$count->fetchColumn();
            if (count($users) < self::PAGE_SIZE) $job['status'] = 'running';
            return;
        }
        if (isset($params['delivery'])) {
            // This phase cannot repeat the panel mutation. Telegram failure is terminal.
            $delivery = $params['delivery'];
            unset($job['params']['delivery']);
            $job['active'] = null;
            // Legacy checkpoint: enqueue delivery without repeating the mutation.
            $job['params']['notifications']=[['key'=>'qr_'.(int)$job['cursor'],'payload'=>['photo'=>$delivery['url'],'text'=>$delivery['caption']]]];
            self::save($job);
            return;
        }
        if ($job['cursor'] >= $job['total']) { $job['status']='completed'; return; }
        $index=(int)$job['cursor']; $username=$params['targets'][$index]??($params['username']??''); $ok=true;
        $itemPosition = null;
        if (in_array($job['kind'], ['delete','transfer','config','admin_status'], true) && !isset($params['targets'])) {
            $select = self::db()->prepare("SELECT position,username FROM bot_queue_items WHERE job_id=? AND status='pending' ORDER BY position LIMIT 1");
            $select->execute([$job['id']]);
            $target = $select->fetch();
            if (!$target) { $job['status']='completed'; return; }
            $username = $target['username']; $itemPosition = (int)$target['position'];
        }

        $before=null; $expected=[];
        if(in_array($job['kind'],['delete','transfer','config','admin_status'],true)) {
            $before=PanelManager::getUser($server,$username);
            if(!$before) throw new RuntimeException('Cannot read user before bulk mutation');
            $expected=match($job['kind']) {
                'transfer'=>['owner_username'=>(string)$params['to_admin']],
                'admin_status'=>['enabled'=>(bool)$params['active']],
                default=>[],
            };
        }
        $job['params']['mutation_intent'] = ['username'=>$username,'phase'=>$job['kind']==='delete'?'delete_user':'modify_user','position'=>$itemPosition ?? $index,'before'=>$before['raw'] ?? [],'payload'=>$expected];
        $job['active'] = 'mutation';
        switch ($job['kind']) {
            case 'delete': self::save($job); $ok=PanelManager::deleteUser($server,$username); break;
            case 'transfer': self::save($job); $ok=PanelManager::setOwner($server,$username,(string)$params['to_admin']); break;
            case 'admin_status': self::save($job); $ok=PanelManager::setStatus($server,$username,(bool)$params['active']); break;
            case 'recharge': $ok=(bool)self::recharge($job,$server); break;
            case 'config':
                $serviceId=(string)$params['service_id']; $ids=$before['service_ids']??[]; $newIds=!empty($params['add'])?array_values(array_unique(array_merge($ids,[$serviceId]))):array_values(array_filter($ids,fn($id)=>(string)$id!==$serviceId)); $job['params']['mutation_intent']['payload']=[$server['type']==='marzneshin'?'service_ids':'selected_configs'=>$newIds]; self::save($job); $ok=PanelManager::updateUserConfigs($server,$username,$newIds); break;
            case 'create':
                $item=$params['uploaded_json'][$index]??null; $name=$item['username']??(($params['username']??'user').($job['total']>1?(string)((int)($params['usersuffix']??1)+$index):''));
                if (!empty($params['import_rows'])) {
                    $select = self::db()->prepare('SELECT payload FROM bot_queue_items WHERE job_id=? AND position=?');
                    $select->execute([$job['id'], $index]);
                    $payload = $select->fetchColumn();
                    if ($payload === false) throw new InvalidArgumentException('Missing import item');
                    $item = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                    $name = $item['username'];
                    $itemPosition = $index;
                }
                $job['params']['mutation_intent']['username'] = $name;
                self::save($job);
                $created = PanelManager::createUser($server, $name, (float)($item['datalimit']??$params['data_limit']??0), (int)($item['datelimit']??$params['date_limit']??0), null, $params['selected_configs']??[], (string)($params['date_type']??'fixed'), $params['admin']??null, self::creationCheckpoint($job));
                $ok = $created !== null;
                if ($ok && !empty($created['subscription_url'])) {
                    $job['params']['notifications'] = [['key'=>'qr_'.$index,'payload'=>['photo'=>$created['subscription_url'],'text'=>Formatter::userInfo($server,$created)]]];
                }
                break;
            default: $job['status']='completed'; return;
        }
        if ($itemPosition !== null) {
            self::db()->prepare('UPDATE bot_queue_items SET status=? WHERE job_id=? AND position=?')->execute([$ok ? 'succeeded' : 'uncertain', $job['id'], $itemPosition]);
        }
        if($ok) { $job['success']++; if(in_array($job['kind'],['delete','transfer','config','admin_status','create','recharge'],true) && empty($job['params']['stats_invalidated'])) { self::invalidateStats((int)$server['id']); $job['params']['stats_invalidated']=true; } }
        else { $job['unconfirmed']++; $job['status']='failed'; $job['error']='Panel did not confirm the mutation; reconciliation is required.'; if($itemPosition!==null)self::db()->prepare("UPDATE bot_queue_items SET status='uncertain' WHERE job_id=? AND position=?")->execute([$job['id'],$itemPosition]); }
        $job['cursor']++;
    }

    public static function run(?float $seconds=null, ?int $steps=null, ?string $onlyJob=null): int
    {
        global $config;
        $steps=max(1,min(100,$steps??(int)($config['queue_steps']??10))); $seconds=max(.1,min(45.0,$seconds??(float)($config['queue_budget_seconds']??15.0))); $db=self::db();
        if ($onlyJob !== null && (!preg_match('/^[a-f0-9]{32}$/D', $onlyJob) || (self::get($onlyJob)['kind'] ?? '') !== 'stats')) throw new InvalidArgumentException('Immediate scan requires a statistics job');
        if ($onlyJob === null) { $lock=$db->query("SELECT GET_LOCK('holderbot-queue-worker',1)"); if((int)$lock->fetchColumn()!==1)return 0; }
        if ($onlyJob === null) Storage::cacheSet('queue_heartbeat', time(), 604800);
        $old=RequestBudget::$deadline; RequestBudget::$deadline=microtime(true)+$seconds; $done=0;
        try {
            if ($onlyJob===null) NotificationOutbox::drain(1);
            if ($onlyJob===null) {
                $candidate=self::db()->query("SELECT id FROM bot_queue WHERE status='failed' AND unconfirmed_count>0 AND kind IN ('recharge','reset','user_mutation','revoke_qr','create','delete','transfer','config','admin_status') AND JSON_EXTRACT(payload,'$.mutation_intent.before') IS NOT NULL AND COALESCE(JSON_EXTRACT(payload,'$.reconciliation.attempts'),0)<3 AND COALESCE(JSON_EXTRACT(payload,'$.reconciliation.checked_at'),0)<UNIX_TIMESTAMP()-60 ORDER BY updated_at LIMIT 1")->fetchColumn();
                if ($candidate) {
                    try { self::reconcile($candidate); }
                    catch(Throwable $e) { error_log('Read-only reconciliation unavailable: '.$e->getMessage()); }
                }
            }
            while($done<$steps&&microtime(true)<RequestBudget::$deadline-.1) {
            if ($onlyJob === null) Storage::cacheSet('queue_heartbeat', time(), 604800);
            if ($onlyJob !== null) {
                $select=$db->prepare("SELECT * FROM bot_queue WHERE id=? AND next_run<=UNIX_TIMESTAMP() AND status NOT IN ('completed','failed','cancelled')");
                $select->execute([$onlyJob]); $row=$select->fetch();
            } else $row=$db->query("SELECT * FROM bot_queue WHERE next_run<=UNIX_TIMESTAMP() AND status NOT IN ('completed','failed','cancelled') AND (status NOT IN ('inline','inline_running') OR lease_until<=UNIX_TIMESTAMP()) ORDER BY last_served, created_at, id LIMIT 1")->fetch();
            if(!$row) {
                if ($onlyJob===null) $done+=NotificationOutbox::drain(max(0,$steps-$done));
                break;
            }
            $job=self::decode($row);
            $db->prepare('UPDATE bot_queue SET last_served=? WHERE id=?')->execute([microtime(true), $job['id']]);
            $done++;
            if (!self::acquireJobLock((string)$job['id'])) { if ($onlyJob !== null) break; continue; }
            $lockedId = (string)$job['id'];
            try {
                $job = self::get($lockedId);
                if (!$job || in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) continue;
                if ($onlyJob === null && in_array($job['status'], ['inline', 'inline_running'], true) && (int)$job['lease_until'] > time()) continue;
                if (($job['active'] ?? '') === 'mutation' || ($job['status'] === 'inline_running' && in_array($job['kind'], ['create', 'recharge', 'reset', 'user_mutation', 'revoke_qr'], true))) {
                    $job['status'] = 'failed';
                    $job['unconfirmed']++;
                    $job['error'] = 'Interrupted mutation: verify the panel before retrying.';
                    $position=$job['params']['mutation_intent']['position'] ?? null;
                    if($position!==null) $db->prepare("UPDATE bot_queue_items SET status='uncertain' WHERE job_id=? AND position=?")->execute([$job['id'],(int)$position]);
                    self::save($job);
                    self::finalizeMessage($job);
                    continue;
                }
                if (!empty($job['cancel_requested'])) {
                    $job['status']='cancelled'; self::save($job); self::finalizeMessage($job); continue;
                }
                $staleInline = in_array($job['status'], ['inline', 'inline_running'], true);
                if ($staleInline) {
                    $job['status'] = 'running';
                    $job['lease_until'] = 0;
                    $job['active'] = null;
                    self::save($job);
                    if ($onlyJob === null) self::notifyFallback($job);
                }
                if ($onlyJob === null && !empty($job['params']['fallback_pending'])) {
                    unset($job['params']['fallback_pending']);
                    self::save($job);
                    self::notifyFallback($job);
                }
                try{self::step($job);unset($job['params']['read_failures']);}
                catch(Throwable $e){
                    $job['error']=$e->getMessage();
                    if($e instanceof InvalidArgumentException){$job['status']='failed';}
                    elseif (($job['active'] ?? '') === 'mutation' || ($job['active'] ?? '') === 'fallback') {
                        $job['unconfirmed']++;
                        $job['status']='failed';
                        $job['error']='Outcome uncertain; verify the panel. ' . $e->getMessage();
                        $position=$job['params']['mutation_intent']['position'] ?? null;
                        if($position!==null) self::db()->prepare("UPDATE bot_queue_items SET status='uncertain' WHERE job_id=? AND position=?")->execute([$job['id'],(int)$position]);
                    } else {
                        $job['next_run']=time()+30;
                        $job['params']['read_failures']=(int)($job['params']['read_failures']??0)+1;
                        if($job['params']['read_failures']>=3)$job['status']='failed';
                    }
                }
                $job['active'] = null;
                if ($onlyJob !== null && !in_array($job['status'], ['completed','failed','cancelled'], true) && empty($job['params']['report_ready'])) $job['params']['fallback_pending'] = true;
                else unset($job['params']['fallback_pending']);
                self::save($job);
                self::finalizeMessage($job);
            } finally { self::releaseJobLock($lockedId); }
        } }
        finally { if ($onlyJob === null) $db->query("SELECT RELEASE_LOCK('holderbot-queue-worker')"); RequestBudget::$deadline=$old; }
        return $done;
    }

    public static function health(): array
    {
        $row=self::db()->query("SELECT COUNT(*) AS pending FROM bot_queue WHERE status NOT IN ('completed','failed','cancelled')")->fetch();
        $heartbeat = Storage::cacheGet('queue_heartbeat');
        $notifications=(int)self::db()->query("SELECT COUNT(*) FROM bot_notifications WHERE status='pending'")->fetchColumn();
        return ['last_run'=>$heartbeat, 'pending'=>(int)($row['pending']??0), 'pending_notifications'=>$notifications, 'stale'=>!is_numeric($heartbeat) || time()-(int)$heartbeat > 180];
    }

    /** Compact old terminal payloads while retaining IDs for deduplication. */
    public static function maintain(?int $retentionDays = null): int
    {
        global $config;
        $days = max(1, $retentionDays ?? (int)($config['queue_retention_days'] ?? 7));
        $cutoff = time() - $days * 86400;
        self::db()->prepare("UPDATE bot_notifications SET payload='{}' WHERE status<>'pending' AND payload<>'{}' AND job_id IN (SELECT id FROM bot_queue WHERE status IN ('completed','failed','cancelled') AND unconfirmed_count=0 AND updated_at<FROM_UNIXTIME(?)) LIMIT 1000")->execute([$cutoff]);
        foreach (['bot_queue_items', 'bot_queue_imports'] as $table) {
            $cleanup = self::db()->prepare("DELETE FROM {$table} WHERE job_id IN (SELECT id FROM bot_queue WHERE status IN ('completed','failed','cancelled') AND unconfirmed_count=0 AND NOT EXISTS (SELECT 1 FROM bot_notifications n WHERE n.job_id=bot_queue.id AND n.status='pending') AND updated_at < FROM_UNIXTIME(?)) LIMIT 1000");
            $cleanup->execute([$cutoff]);
        }
        $stmt = self::db()->prepare("UPDATE bot_queue SET payload='{}' WHERE status IN ('completed','failed','cancelled') AND unconfirmed_count=0 AND NOT EXISTS (SELECT 1 FROM bot_notifications n WHERE n.job_id=bot_queue.id AND n.status='pending') AND updated_at < FROM_UNIXTIME(?) AND payload <> '{}' AND NOT EXISTS (SELECT 1 FROM bot_queue_items WHERE job_id=bot_queue.id) AND NOT EXISTS (SELECT 1 FROM bot_queue_imports WHERE job_id=bot_queue.id) LIMIT 100");
        $stmt->execute([time() - $days * 86400]);
        return $stmt->rowCount();
    }
}
