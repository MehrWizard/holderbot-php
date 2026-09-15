<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
Storage::init();
class PanelManager {
    public static array $pages=[];
    public static int $size=1;
    public static ?int $cap=null;
    public static bool $fail=false;
    public static function pageSize(...$args): int { return 2; }
    public static function getBotUsername(): string { return 'test'; }
    public static function getUsers($server,$page,...$args): array {
        self::$pages[]=$page;
        if (self::$fail) { self::$fail=false; throw new RuntimeException('Simulated read timeout'); }
        $pageSize=min((int)($args[0] ?? 2),self::$cap ?? 2);
        return array_fill(0,max(0,min($pageSize,self::$size-($page-1)*$pageSize)),['username'=>'test']);
    }
    public static function scanUsers($server,$page,$size,...$args): array { return ['users'=>self::getUsers($server,$page,$size,...$args),'page_size'=>min($size,self::$cap ?? $size),'elapsed'=>0.01]; }
    public static function rememberPageSize(...$args): void {}
    public static function statsForUsers($server,$users,...$args): array { return ['total'=>count($users),'today_expired'=>[]]; }
}
class Formatter {
    public static function escape($text): string { return htmlspecialchars($text,ENT_QUOTES,'UTF-8'); }
    public static array $cards=[];
    public static function statsCard($server,$stats): string { self::$cards[]=$stats; return '<b>Stats</b> '.implode(',', $stats['today_expired'] ?? []); }
}
class Keyboards { public static function serverMenu(...$args): array { return []; } public static function stats(...$args): array { return []; } }
$replacements=0;$newMessages=0;
function tg_replace_message(...$args): array { global $replacements;$replacements++;return ['ok'=>true]; }
function tg_send_message(...$args): array { global $newMessages;$newMessages++;return ['ok'=>true,'result'=>['message_id'=>1000+$newMessages]]; }
function tg_edit_message(...$args): array { return ['ok'=>true]; }
class BackgroundTasks {
    public static function expiryPage(...$args): array { return ['page'=>2,'names'=>['name_with_underscores'],'done'=>true,'matched'=>1,'total'=>1]; }
}
$serverId=Storage::saveServer(['remark'=>'statistics','type'=>'marzban','base_url'=>'http://example.invalid','username'=>'test','password'=>'test']);
$server=Storage::getServer($serverId);
$navigation=BatchQueue::enqueueInline('stats',$server,['message_id'=>901],99,42,'navigation_result');
NotificationOutbox::stage($navigation['id'],'final',99,['message_id'=>901,'text'=>'Stats finished','keyboard'=>[]]);
if(!NotificationOutbox::detachLoadingMessage(99,901)) throw new RuntimeException('Could not detach loading menu');
NotificationOutbox::drain(1,$navigation['id']);
if($replacements!==0 || $newMessages!==1) throw new RuntimeException('Detached result deleted the navigated menu');
$other=new PDO('mysql:host=127.0.0.1;port='.(int)$argv[1].';dbname=fresh','root','');
$other->query("SELECT GET_LOCK('holderbot-queue-worker',0)");
Storage::cacheSet('queue_heartbeat',time()-600,3600);
$small=BatchQueue::enqueueInline('stats',$server,['message_id'=>123],99,42,'small_stats');
BatchQueue::run(2,10,$small['id']);
if (BatchQueue::get($small['id'])['status']!=='completed' || PanelManager::$pages!==[1,2]) throw new RuntimeException('Small stats did not finish immediately beside cron lock');
$freshCache=BatchQueue::cachedStats($serverId,true);
if(($freshCache['text'] ?? '')==='' || (float)($freshCache['generation_seconds']??0)<=0 || (int)($freshCache['fresh_until']??0)<time()) throw new RuntimeException('Completed statistics freshness was not cached');
$staleCache=$freshCache; $staleCache['fresh_until']=time()-1; Storage::cacheSet('stats_result_'.$serverId,$staleCache,3600);
if(BatchQueue::cachedStats($serverId,true)!==null || BatchQueue::cachedStats($serverId)===null) throw new RuntimeException('Stale statistics were not separated from navigation cache');
if (!BatchQueue::health()['stale']) throw new RuntimeException('Inline scan concealed stopped cron');
if (Formatter::$cards[0]['today_expired']!==[]) throw new RuntimeException('Empty report promised follow-up messages');
$other->query('SELECT RELEASE_ALL_LOCKS()');
PanelManager::$pages=[]; PanelManager::$size=5;
$large=BatchQueue::enqueueInline('stats',$server,['message_id'=>124],99,42,'large_stats');
$lock=$other->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['holderbot-job-'.$large['id']]);
BatchQueue::run(2,10,$large['id']);
if (PanelManager::$pages!==[]) throw new RuntimeException('Immediate scan ignored competing job lock');
$other->query('SELECT RELEASE_ALL_LOCKS()');
BatchQueue::run(2,1,$large['id']);
if ((BatchQueue::get($large['id'])['params']['page'] ?? 0)!==2) throw new RuntimeException('Missing inline page checkpoint');
BatchQueue::run(2,10);
if (BatchQueue::get($large['id'])['status']!=='completed' || PanelManager::$pages!==[1,2,3] || end(Formatter::$cards)['total']!==5) throw new RuntimeException('Cron repeated or lost inline scan pages');
PanelManager::$fail=true; PanelManager::$size=1;
$retry=BatchQueue::enqueueInline('stats',$server,['message_id'=>125],99,42,'retry_stats');
BatchQueue::run(2,1,$retry['id']);
if (BatchQueue::get($retry['id'])['status']==='failed' || BatchQueue::get($retry['id'])['unconfirmed']!=0) throw new RuntimeException('Read timeout classified as uncertain mutation');
Storage::db()->prepare('UPDATE bot_queue SET next_run=0 WHERE id=?')->execute([$retry['id']]);
BatchQueue::run(2,10);
if (BatchQueue::get($retry['id'])['status']!=='completed') throw new RuntimeException('Timed-out scan did not recover');
$expiry=BatchQueue::enqueue('expiry',$server,['recipients'=>[]],0,0,'expiry_links');
BatchQueue::run(2,1);
$select=Storage::db()->prepare('SELECT payload FROM bot_queue_items WHERE job_id=? AND position=0');
$select->execute([$expiry['id']]);
$text=json_decode($select->fetchColumn(),true)['text'];
if (!str_contains($text,'https://t.me/test?start=user_'.$serverId.'_name_with_underscores')) throw new RuntimeException('Expiry report omitted user deep link');
BatchQueue::cancel($expiry['id']);
$store=new ReflectionMethod(BatchQueue::class,'storeReportPage');
$prepare=new ReflectionMethod(BatchQueue::class,'prepareStatsDelivery');
$short=BatchQueue::enqueueInline('stats',$server,['message_id'=>126],99,42,'short_report');
$store->invoke(null,$short['id'],1,['<code>one</code>','<code>two</code>']);
$shortText=$prepare->invokeArgs(null,[&$short,$server,['today_expired'=>[]]]);
if(!str_contains($shortText,'one') || str_contains($shortText,'following report')) throw new RuntimeException('Short expiry list was split unnecessarily');
$count=Storage::db()->prepare('SELECT COUNT(*) FROM bot_queue_items WHERE job_id=?'); $count->execute([$short['id']]);
if((int)$count->fetchColumn()!==0) throw new RuntimeException('Short inline report left follow-up chunks');
$long=BatchQueue::enqueueInline('stats',$server,['message_id'=>127],99,42,'long_report');
$entries=[]; for($i=0;$i<300;$i++) $entries[]='<code>'.str_repeat('user_'.$i,4).'</code>';
$store->invoke(null,$long['id'],1,$entries);
$longText=$prepare->invokeArgs(null,[&$long,$server,['today_expired'=>[]]]);
if(!str_contains($longText,"\nSee the following report messages for the complete list.")) throw new RuntimeException('Long report omitted its calculated footer');
$chunks=Storage::db()->prepare('SELECT payload FROM bot_queue_items WHERE job_id=? ORDER BY position'); $chunks->execute([$long['id']]);
$parts=$chunks->fetchAll(PDO::FETCH_COLUMN);
if(count($parts)<2) throw new RuntimeException('Long report was not safely chunked');
$length=new ReflectionMethod(BatchQueue::class,'telegramTextLength');
foreach($parts as $part) if($length->invoke(null,json_decode($part,true)['text'])>4096) throw new RuntimeException('A follow-up report exceeds Telegram limits');
foreach($parts as $part) if(str_contains(json_decode($part,true)['text'],"\n")) throw new RuntimeException('Chunked users are not comma-separated');
$entities=new ReflectionMethod(BatchQueue::class,'telegramEntityCount');
foreach($parts as $part) if($entities->invoke(null,json_decode($part,true)['text'])>100) throw new RuntimeException('A follow-up report exceeds Telegram entity limits');
$linked=BatchQueue::enqueueInline('stats',$server,['message_id'=>129],99,42,'entity_report');
$linkedEntries=[]; for($i=0;$i<120;$i++) $linkedEntries[]='<a href="https://t.me/test?start=user_1_'.$i.'">u'.$i.'</a>';
$store->invoke(null,$linked['id'],1,$linkedEntries);
$linkedText=$prepare->invokeArgs(null,[&$linked,$server,['today_expired'=>[]]]);
if(!str_contains($linkedText,'following report')) throw new RuntimeException('Entity-heavy report was not split');
$chunks->execute([$linked['id']]);
foreach($chunks->fetchAll(PDO::FETCH_COLUMN) as $part) if($entities->invoke(null,json_decode($part,true)['text'])>100) throw new RuntimeException('Entity-heavy chunk exceeds Telegram limits');
$cache=new ReflectionMethod(BatchQueue::class,'cacheStatsResult'); $cache->invoke(null,$long,$longText);
$cached=BatchQueue::cachedStats($serverId);
if($cached['text']!==$longText || count($cached['chunks'])!==count($parts)) throw new RuntimeException('Latest complete statistics cache lost its chunks');
PanelManager::$pages=[]; PanelManager::$size=5; PanelManager::$cap=1;
$capped=BatchQueue::enqueueInline('stats',$server,['message_id'=>128],99,42,'capped_stats');
BatchQueue::run(2,10,$capped['id']);
BatchQueue::run(2,10,$capped['id']);
if (BatchQueue::get($capped['id'])['status']!=='completed' || end(Formatter::$cards)['total']!==5) throw new RuntimeException('Panel page cap silently truncated statistics');
echo "PASS: immediate statistics, cron continuation, read timeout recovery, heartbeat isolation; Telegram stubbed\n";
