<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
require __DIR__.'/../helpers/tracker.php';
Storage::init();
$sent=[]; $deleted=[]; $mode='ok'; $nextMessageId=null;
function tg_send_message($chat,$text,...$args): ?array {
    global $sent,$mode,$nextMessageId; $sent[]=[$chat,$text];
    $messageId=$nextMessageId===null ? 123 : $nextMessageId++;
    return match($mode) { 'blocked'=>['ok'=>false,'error_code'=>403], 'rate'=>['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>120]], 'timeout'=>null, default=>['ok'=>true,'result'=>['message_id'=>$messageId]] };
}
function tg_replace_message($chat,$id,$text,...$args): ?array { return tg_send_message($chat,$text); }
function tg_delete_message($chat,$id): array { global $deleted; $deleted[]=(int)$id; return ['ok'=>true]; }
function check($value,$message): void { if(!$value) throw new RuntimeException($message); }
function notice($id): array {
    $s=Storage::db()->prepare('SELECT * FROM bot_notifications WHERE job_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$id]); return $s->fetch() ?: [];
}
$step=new ReflectionMethod(BatchQueue::class,'step'); $save=new ReflectionMethod(BatchQueue::class,'save');
$job=BatchQueue::message(99,42,'durable','notification_crash');
$step->invokeArgs(null,[&$job]);
Storage::db()->beginTransaction(); $save->invoke(null,$job); Storage::db()->rollBack();
check(notice($job['id'])===[] && BatchQueue::get($job['id'])['status']==='running','Notification and completion not rolled back together');
$save->invoke(null,$job);
check(notice($job['id'])['status']==='pending' && BatchQueue::get($job['id'])['status']==='completed','Completion has no durable pending notification');
// A separate PHP process exits at send entry, without returning a response.
$code='$config='.var_export($config,true).'; require '.var_export(__DIR__.'/../storage.php',true).'; require '.var_export(__DIR__.'/../helpers/queue.php',true).'; Storage::init(); function tg_send_message(...$args){exit(17);} NotificationOutbox::drain(1,'.var_export($job['id'],true).');';
$process=proc_open([PHP_BINARY,'-d','extension=pdo_mysql','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
foreach($pipes as $pipe) fclose($pipe);
check(proc_close($process)===17,'Crash fixture failed');
check(notice($job['id'])['status']==='pending','Process exit lost pending send');
NotificationOutbox::drain(1,$job['id']);
check(notice($job['id'])['status']==='sent' && count($sent)===1,'Pending send did not recover');
$save->invoke(null,$job); NotificationOutbox::drain(1,$job['id']);
check(count($sent)===1,'Repeated checkpoint duplicated acknowledged delivery');
$mutation=BatchQueue::enqueueInline('recharge',['id'=>0,'type'=>'internal','base_url'=>'','username'=>''],['message_id'=>777],99,42,'mutation_delivery_crash');
$code='$config='.var_export($config,true).'; require '.var_export(__DIR__.'/../storage.php',true).'; require '.var_export(__DIR__.'/../helpers/queue.php',true).'; Storage::init(); class Keyboards { public static function cancel(...$args){return [];} } function tg_replace_message(...$args){exit(18);} BatchQueue::executeInline(BatchQueue::get('.var_export($mutation['id'],true).'),function(){Storage::cacheSet("confirmed_mutations",1,3600);return true;});';
$process=proc_open([PHP_BINARY,'-d','extension=pdo_mysql','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
foreach($pipes as $pipe) fclose($pipe);
check(proc_close($process)===18,'Mutation delivery crash fixture failed');
check(BatchQueue::get($mutation['id'])['status']==='completed' && notice($mutation['id'])['status']==='pending','Crash lost confirmed mutation or result');
NotificationOutbox::drain(1,$mutation['id']);
BatchQueue::executeInline($mutation,function(){throw new LogicException('Confirmed mutation replayed for notification');});
check(notice($mutation['id'])['status']==='sent' && Storage::cacheGet('confirmed_mutations')===1,'Mutation result delivery did not recover independently');
NotificationOutbox::stage($job['id'],'slow_photo',99,['text'=>'pending photo']);
$mode='timeout'; NotificationOutbox::drain(1,$job['id']);
NotificationOutbox::stage($job['id'],'final',99,['message_id'=>123,'text'=>'operation complete']);
$mode='ok'; NotificationOutbox::drain(1,$job['id']);
check(notice($job['id'])['status']==='sent','Pending photo blocked final result');
Storage::db()->prepare("UPDATE bot_notifications SET next_run=0 WHERE job_id=? AND status='pending'")->execute([$job['id']]);
NotificationOutbox::drain(1,$job['id']);
foreach(['blocked','timeout','rate'] as $case) {
    $mode=$case; $j=BatchQueue::message(99,42,$case,'notice_'.$case);
    $step->invokeArgs(null,[&$j]); $save->invoke(null,$j); NotificationOutbox::drain(1,$j['id']);
    $n=notice($j['id']);
    check($n['status']===($case==='blocked'?'suppressed':'pending'),'Incorrect delivery retry classification');
    check(BatchQueue::get($j['id'])['status']==='completed','Delivery changed completed operation');
    if($case==='rate') check((int)$n['next_run']>=time()+119,'Telegram retry_after ignored');
    if($case!=='blocked') {
        Storage::db()->prepare('UPDATE bot_notifications SET next_run=0 WHERE job_id=?')->execute([$j['id']]); $mode='ok';
        NotificationOutbox::drain(1,$j['id']); check(notice($j['id'])['status']==='sent','Transient failure did not recover');
    }
}
$mode='ok'; NotificationOutbox::stage($job['id'],'replace',99,['message_id'=>999,'text'=>'fresh result']);
NotificationOutbox::drain(1,$job['id']); check(notice($job['id'])['telegram_message_id']==123,'Replacement result not recorded');
NotificationOutbox::stage($job['id'],'late_result',99,['text'=>'late result']);
Storage::db()->prepare('UPDATE bot_queue SET updated_at=FROM_UNIXTIME(?) WHERE id=?')->execute([time()-864000,$job['id']]);
BatchQueue::maintain(1);
check(notice($job['id'])['payload']!=='{}' && BatchQueue::get($job['id'])['payload']!=='{}','Cleanup erased pending delivery');
check(BatchQueue::health()['pending_notifications']>0,'Health omitted delivery backlog');
BatchQueue::run(2,10);
check(notice($job['id'])['status']==='sent','Cron did not deliver notification for completed job');
check(in_array(123,Storage::cacheGet('tracked_messages_99')??[],true),'Delayed final message was not retained for navigation cleanup');
Storage::cacheDelete('tracked_messages_99');
$nextMessageId=201;
NotificationOutbox::stage($job['id'],'report_1_0',99,['text'=>'first,second']);
NotificationOutbox::stage($job['id'],'report_2_0',99,['text'=>'third,fourth']);
NotificationOutbox::drain(2,$job['id']);
check(Storage::cacheGet('tracked_messages_99')===[201,202],'Delivered report message IDs were not retained together');
MessageTracker::begin(99); MessageTracker::sent(99,'new menu',[],['ok'=>true,'result'=>['message_id'=>300]]);
check($deleted===[201,202],'Menu cleanup did not delete every old report chunk');
echo "PASS: atomic notification staging, process-exit recovery, deduplication, permanent rejection and transient retry; Telegram stubbed\n";
