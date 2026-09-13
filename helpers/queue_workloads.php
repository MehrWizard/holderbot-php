<?php
declare(strict_types=1);
require_once __DIR__ . '/queue_import.php';

/** Read-only scans, scheduled work and a durable outbound message queue. */
trait QueueWorkloads {
    private static function virtualServer(): array {
        return ['id'=>0,'type'=>'internal','base_url'=>'','username'=>''];
    }
    public static function message(int|string $chat, int $user, string $text, string $key, ?array $keyboard = null): array {
        if (strlen($text) > 3900) throw new LengthException('Outbox text exceeds bounded message size');
        return self::enqueue('outbox', self::virtualServer(), ['text'=>$text,'keyboard'=>$keyboard], $chat, $user, $key);
    }
    public static function photo(int|string $chat, int $user, string $url, string $caption, string $key): array {
        return self::enqueue('outbox',self::virtualServer(),['photo'=>$url,'text'=>$caption,'keyboard'=>null],$chat,$user,$key);
    }
    private static function retryDelivery(?array $response, array &$job): bool {
        if ((int)($response['error_code'] ?? 0) !== 429) return false;
        $job['delivery_attempts'] = ($job['delivery_attempts'] ?? 0) + 1;
        if ($job['delivery_attempts'] >= 5) return false;
        $delay = max(1, (int)($response['parameters']['retry_after'] ?? 30));
        $job['next_run'] = time() + $delay;
        // Telegram flood control applies across this bot's scheduled deliveries.
        self::save(self::directory() . '/telegram-cooldown.json', ['until'=>$job['next_run']]);
        return true;
    }
    private static function workload(string $dir, string $path, array &$job, array $server): void {
        switch ($job['kind']) {
            case 'outbox':
                $cooldown = self::directory() . '/telegram-cooldown.json';
                if (is_file($cooldown) && ($until = self::read($cooldown)['until']) > time()) { $job['next_run']=$until; return; }
                $job['active']='outbox'; self::save($path,$job);
                $response=isset($job['params']['photo']) ? QrGenerator::sendQrPhoto($job['chat_id'],$job['params']['photo'],$job['params']['text']) : tg_send_message($job['chat_id'],$job['params']['text'],$job['params']['keyboard']);
                $job['active']=null;
                if (self::retryDelivery($response,$job)) return;
                $job['status']='completed'; $job['cursor']=1; $job['total']=1;
                $job[!empty($response['ok']) ? 'success' : 'unconfirmed']++;
                $job['delivery_error_code']=$response['error_code'] ?? null;
                if (empty($response['ok'])) self::issue($dir,$job,'','Message delivery not confirmed; not automatically repeated');
                return;
            case 'access':
                $token=$server['type']==='marzneshin' ? MarzneshinClient::getToken($server,true) : MarzbanClient::getToken($server,true);
                if (!$token) throw new RuntimeException('Access refresh failed');
                Storage::cacheSet('online_'.$server['id'],time(),86400);
                $job['status']='completed'; $job['success']=1;
                return;
            case 'monitor': self::monitorStep($dir,$path,$job,$server); return;
            case 'stats': case 'expiry': self::reportStep($dir,$job,$server); return;
            case 'import': QueueImport::step($dir,$job); return;
            case 'recharge': self::rechargeStep($dir,$path,$job,$server); return;
        }
    }
    /** Safe display units ensure HTML tags/entities are never split across messages. */
    private static function shortText(string $value, int $characters = 160): string {
        preg_match('/^.{0,' . $characters . '}/us', $value, $matches);
        return Formatter::escape($matches[0] ?? '');
    }
    private static function chunks(array $units, string $header = ''): array {
        $chunks=[]; $text=$header;
        foreach ($units as $unit) {
            if (strlen($text)+strlen($unit)+1 > 3500 && $text !== '') { $chunks[]=$text; $text=$header; }
            $text.=($text === $header ? '' : ',') . $unit;
        }
        if ($text!=='') $chunks[]=$text;
        return $chunks;
    }
    private static function reportStep(string $dir, array &$job, array $server): void {
        $job['reference_time'] ??= time();
        if (!isset($job['bot_username'])) {
            $info=tgbot('getMe');
            if (empty($info['result']['username'])) throw new RuntimeException('Cannot read bot identity');
            $job['bot_username']=$info['result']['username'];
            return;
        }
        if (($job['report_phase'] ?? 'scan') === 'scan') {
            $users=PanelManager::getUsers($server,$job['page'],self::PAGE_SIZE,null,null,null,true);
            $part=PanelManager::statsForUsers($server,$users,$job['reference_time']);
            unset($part['today_expired']);
            foreach ($part as $key=>$value) $job['aggregate'][$key]=($job['aggregate'][$key] ?? 0)+$value;
            $names=[];
            foreach ($users as $user) {
                if ($server['type']==='marzneshin' && ($user['raw']['expire_strategy'] ?? '')!=='fixed_date') continue;
                $hours=(int)((($user['expire_timestamp'] ?? 0)-$job['reference_time'])/3600);
                if ($hours>0 && ($job['kind']==='stats' ? $hours<=24 : $hours<24)) {
                    $name=$user['username'];
                    // Extremely long usernames still appear as plain text; avoid oversized links.
                    $names[]=strlen($name)>200 ? '<code>'.self::shortText($name).'</code>' : "<a href='https://t.me/{$job['bot_username']}?start=user_{$server['id']}_" . rawurlencode($name) . "'><code>" . Formatter::escape($name) . '</code></a>';
                }
            }
            $job['qualifying']=($job['qualifying'] ?? 0)+count($names);
            self::save($dir.'/report-'.$job['page'].'.json',self::chunks($names));
            $job['pages']=$job['page']; $job['page']++; $job['total']+=count($users); $job['cursor']=$job['total'];
            $job['read_failures']=0;
            if (count($users)<self::PAGE_SIZE) { $job['report_phase']='publish'; $job['output_page']=0; $job['output_part']=0; }
            return;
        }
        $recipients=$job['kind']==='stats' ? [$job['chat_id']] : ($job['params']['recipients'] ?? []);
        if ($job['output_page']===0) {
            if ($job['kind']==='stats') {
                $stats=$job['aggregate']; $stats['today_expired']=[];
                $text=Formatter::statsCard($server,$stats);
                if ($job['qualifying']) $text=str_replace('<code>None</code>', 'See following messages.', $text);
            } else $text='<b>Users scheduled to expire today in '.self::shortText($server['remark'])."</b>\nQualifying: {$job['qualifying']}/{$job['total']}";
            $pieces=[$text];
        } else $pieces=self::read($dir.'/report-'.$job['output_page'].'.json');
        if (isset($pieces[$job['output_part']])) {
            foreach ($recipients as $recipient) self::message($recipient,$job['user_id'],$pieces[$job['output_part']],$job['id'].':report:'.$job['output_page'].':'.$job['output_part'].':'.$recipient);
            $job['output_part']++;
        } else { $job['output_page']++; $job['output_part']=0; }
        if ($job['output_page']>$job['pages']) { $job['status']='completed'; $job['success']=$job['total']; }
    }
    private static function monitorStep(string $dir, string $path, array &$job, array $server): void {
        if (empty($server['node_monitoring'])) { $job['status']='cancelled'; return; }
        if (!isset($job['node_cursor'])) {
            $nodes=$server['type']==='marzneshin' ? MarzneshinClient::getNodes($server) : MarzbanClient::getNodes($server);
            if (!is_array($nodes)) throw new RuntimeException('Cannot read nodes');
            $nodes=array_values(array_filter($nodes, fn($node)=>!PanelManager::isNodeOk($node,$server['type'])));
            self::save($dir.'/nodes.json',$nodes);
            $job['node_cursor']=0; $job['total']=count($nodes);
            return;
        }
        $nodes=self::read($dir.'/nodes.json');
        if (!isset($nodes[$job['node_cursor']])) { $job['status']='completed'; return; }
        $node=$nodes[$job['node_cursor']];
        if (!isset($job['node_result'])) {
            $job['node_result']='Not requested';
            if (!empty($server['node_restart'])) {
                $job['active']='restart'; self::save($path,$job);
                $ok=PanelManager::restartNode($server,(int)$node['id']);
                $job['node_result']=$ok ? 'Confirmed' : 'Not confirmed';
                if (!$ok) $job['unconfirmed']++;
                $job['active']=null;
            }
            return;
        }
        $text='<b>Node error: '.self::shortText($server['remark'])."</b>\nName: ".self::shortText($node['name'] ?? $node['remark'] ?? '')."\nAddress: ".self::shortText($node['address'] ?? '')."\nMessage: ".self::shortText($node['message'] ?? '',300)."\nRestart: ".$job['node_result'];
        foreach ($job['params']['recipients'] ?? [] as $recipient) self::message($recipient,0,$text,$job['id'].':node:'.$job['node_cursor'].':'.$recipient);
        $job['node_cursor']++; $job['cursor']=$job['node_cursor']; unset($job['node_result']);
    }
    /** Persist absolute quota/date values; reset, write and reconciliation are separate. */
    private static function rechargeStep(string $dir, string $path, array &$job, array $server): void {
        if (isset($job['recharge_done'])) { $job['status']='completed'; return; }
        $p=$job['params']; $name=$p['username'];
        $job['current_user']=$name; $job['total']=1;
        if (!isset($job['desired'])) {
            $user=PanelManager::getUser($server,$name);
            if (!$user) throw new RuntimeException('Cannot prepare recharge');
            $job['desired']=PanelManager::rechargePayload($server,$name,$user,$p['data_limit'],$p['date_limit'],$p['additive'],$p['date_type']);
            $job['before']=array_intersect_key($user['raw'],$job['desired']);
            $job['recharge_phase']=$p['reset'] ? 'reset' : 'write';
            return;
        }
        $phase=$job['recharge_phase'];
        if ($phase==='reconcile') {
            $user=PanelManager::getUser($server,$name);
            if (!$user) throw new RuntimeException('Cannot reconcile recharge');
            $matches=true;
            foreach ($job['desired'] as $key=>$value) if ($value!==null && ($user['raw'][$key] ?? null)!=$value) $matches=false;
            $job[$matches ? 'success':'unconfirmed']++;
            if (!$matches) self::issue($dir,$job,$name,'Recharge result does not match saved intent; not repeated');
            $job['recharge_done']=true; $job['cursor']=1;
            return;
        }
        $user=PanelManager::getUser($server,$name);
        if (!$user) throw new RuntimeException('Cannot verify recharge precondition');
        foreach ($job['before'] as $key=>$value) {
            if (($user['raw'][$key] ?? null)!=$value) {
                $job['status']='failed'; $job['error']='Quota or expiry changed after recharge preparation; operation stopped'; return;
            }
        }
        if ($phase==='reset') {
            $job['active']='recharge_reset'; self::save($path,$job);
            $ok=PanelManager::resetUsage($server,$name);
            $job['active']=null;
            if (!$ok) { $job['unconfirmed']++; $job['recharge_done']=true; $job['cursor']=1; self::issue($dir,$job,$name,'Usage reset not confirmed; quota update not attempted'); }
            else $job['recharge_phase']='write';
            return;
        }
        $job['active']='recharge_write'; self::save($path,$job);
        $response=$server['type']==='marzneshin' ? MarzneshinClient::modifyUser($server,$name,$job['desired']) : MarzbanClient::modifyUser($server,$name,$job['desired']);
        $job['active']=null;
        if ($response!==null && isset($response['username'])) { $job['success']++; $job['recharge_done']=true; $job['cursor']=1; }
        else $job['recharge_phase']='reconcile';
    }
}
