<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
Storage::init();
class PanelManager {
    public static array $pages=[];
    public static int $size=1;
    public static bool $fail=false;
    public static function pageSize(...$args): int { return 2; }
    public static function getBotUsername(): string { return 'test'; }
    public static function getUsers($server,$page,...$args): array {
        self::$pages[]=$page;
        if (self::$fail) { self::$fail=false; throw new RuntimeException('Simulated read timeout'); }
        return array_fill(0,max(0,min(2,self::$size-($page-1)*2)),['username'=>'test']);
    }
    public static function statsForUsers($server,$users,...$args): array { return ['total'=>count($users),'today_expired'=>[]]; }
}
class Formatter {
    public static function escape($text): string { return htmlspecialchars($text,ENT_QUOTES,'UTF-8'); }
    public static array $cards=[];
    public static function statsCard($server,$stats): string { self::$cards[]=$stats; return 'stats'; }
}
class Keyboards { public static function serverMenu(...$args): array { return []; } }
function tg_replace_message(...$args): array { return ['ok'=>true]; }
function tg_edit_message(...$args): array { return ['ok'=>true]; }
class BackgroundTasks {
    public static function expiryPage(...$args): array { return ['page'=>2,'names'=>['name_with_underscores'],'done'=>true,'matched'=>1,'total'=>1]; }
}
$serverId=Storage::saveServer(['remark'=>'statistics','type'=>'marzban','base_url'=>'http://example.invalid','username'=>'test','password'=>'test']);
$server=Storage::getServer($serverId);
$other=new PDO('mysql:host=127.0.0.1;port='.(int)$argv[1].';dbname=fresh','root','');
$other->query("SELECT GET_LOCK('holderbot-queue-worker',0)");
Storage::cacheSet('queue_heartbeat',time()-600,3600);
$small=BatchQueue::enqueueInline('stats',$server,['message_id'=>123],99,42,'small_stats');
BatchQueue::run(2,10,$small['id']);
if (BatchQueue::get($small['id'])['status']!=='completed' || PanelManager::$pages!==[1]) throw new RuntimeException('Small stats did not finish immediately beside cron lock');
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
echo "PASS: immediate statistics, cron continuation, read timeout recovery, heartbeat isolation; Telegram stubbed\n";
