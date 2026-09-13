<?php
require __DIR__.'/queue.php';
$productionChecks=$checks;
function persistJob(array $job): void {
    global $config;
    (new ReflectionMethod(BatchQueue::class,'save'))->invoke(null,$config['queue_path'].'/'.$job['id'].'/job.json',$job);
}
function drainQueue(): void { for($i=0;$i<20;$i++) if (!BatchQueue::run(2,100)) break; }
drainQueue();
// Scheduler must do no panel or Telegram work and must coalesce pending periods.
$scheduledServer=$server; $scheduledServer['node_monitoring']=true; $scheduledServer['expired_stats']=true; Storage::saveServer($scheduledServer);
$requests=[]; $messages=[];
BackgroundTasks::tick(true); $ready=count(glob($config['queue_path'].'/ready/*'));
BackgroundTasks::tick(true);
check(!$requests && !$messages && count(glob($config['queue_path'].'/ready/*'))===$ready,'Scheduler performed I/O or duplicated pending ticks');
// Remove scheduler fixtures before isolated report tests.
foreach(glob($config['queue_path'].'/ready/*') as $file) BatchQueue::cancel(basename($file));
drainQueue(); Storage::saveServer($server);
// Reports checkpoint pages, then publish bounded messages.
$db=[]; for($i=0;$i<121;$i++) $db['report'.$i]=array_merge($raw,['username'=>'report'.$i,'expire'=>time()+7200]);
$j=queueJob('stats',[],'production-stats'); $requests=[];
BatchQueue::run(2,1); check(!$requests,'Stats initialization unexpectedly scanned users');
BatchQueue::run(2,1); $state=BatchQueue::get($j['id']);
check($state['aggregate']['total_users']===50 && $state['page']===2,'Stats page not checkpointed');
$messages=[]; $j=finishJob($j['id']); drainQueue();
check($j['total']===121 && $j['qualifying']===121,'Paginated stats lost users');
check(count(array_filter($messages,fn($m)=>str_contains($m['text'],'report120')))===1,'Stats duplicated or omitted final user');
check(!array_filter($messages,fn($m)=>strlen($m['text'])>3900),'Report output exceeded Telegram bound');
check(!array_filter($messages,fn($m)=>substr_count($m['text'],'<a ')!==substr_count($m['text'],'</a>')),'Report split HTML tags');
// Failed report read must preserve counters and remain retryable, never publish partial data.
$j=queueJob('stats',[],'failed-stats'); BatchQueue::run(2,1);
$fail=true; BatchQueue::run(2,1); $fail=false; $state=BatchQueue::get($j['id']);
check($state['page']===1 && $state['read_failures']===1 && !isset($state['aggregate']),'Read failure advanced report progress');
$state['status']='failed'; $state['notification']='suppressed'; persistJob($state);
BatchQueue::retryRead($state['id']);
check(finishJob($state['id'])['total']===121,'Read retry lost scan progress'); drainQueue();
// Outbox honors retry_after and recipient order; ambiguous sends are not replayed.
$messages=[]; $sendResults=[['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>60]]];
$first=BatchQueue::message(42,42,'first','rate-first');
$second=BatchQueue::message(42,42,'second','rate-second');
BatchQueue::run(2,10); $state=BatchQueue::get($first['id']);
check(count($messages)===1 && $state['next_run']>=time()+58 && $state['active']===null,'429 was ignored or next message overtook it');
$state['next_run']=0; persistJob($state); unlink($config['queue_path'].'/telegram-cooldown.json');
drainQueue();
check(array_column($messages,'text')===['first','first','second'],'Outbox delivery order or explicit rejection retry failed');
$sendResults=[null]; $messages=[];
$j=BatchQueue::message(42,42,'unknown','ambiguous-message'); drainQueue();
check(BatchQueue::get($j['id'])['unconfirmed']===1 && count($messages)===1,'Ambiguous send was repeated');
$new=BatchQueue::resend(BatchQueue::get($j['id'])); drainQueue();
check($new['id']!==$j['id'] && BatchQueue::get($new['id'])['success']===1,'Explicit resend did not create a separate delivery');
// Import scanning is incremental and no users are created until the full file validates.
$items=[]; for($i=0;$i<51;$i++) $items[]=['username'=>'import'.$i,'datalimit'=>2,'datelimit'=>1,'datetypes'=>'now'];
$document=json_encode($items); $j=queueJob('import',['import_file_id'=>'fixture','selected_configs'=>['a']],'large-import');
BatchQueue::run(2,1); BatchQueue::run(2,1); $state=BatchQueue::get($j['id']);
check($state['kind']==='import' && $state['import_count']===50 && !isset($db['import0']),'Import created users before validation finished');
$j=finishJob($j['id']); drainQueue(); check($j['success']===51 && isset($db['import50']),'Validated import failed to resume');
$items[50]['datalimit']=-1; $document=json_encode($items);
$j=queueJob('import',['import_file_id'=>'bad','selected_configs'=>['a']],'invalid-late-import'); $requests=[];
$j=finishJob($j['id']); drainQueue();
check($j['status']==='failed' && !array_filter($requests,fn($r)=>$r['method']==='POST'),'Late invalid import partially mutated panel');
// Quota writes interrupted after the panel applied them reconcile without adding twice.
$db=['charge'=>array_merge($raw,['username'=>'charge','expire'=>time()+86400])];
$j=queueJob('recharge',['username'=>'charge','data_limit'=>2,'date_limit'=>1,'reset'=>false,'additive'=>true,'date_type'=>'fixed'],'prepared-recharge');
BatchQueue::run(2,1); $state=BatchQueue::get($j['id']);
check($state['desired']['data_limit']===12*1024**3,'Recharge did not save absolute target');
$db['charge']=array_merge($db['charge'],$state['desired']); $state['active']='recharge_write'; persistJob($state);
$requests=[]; $j=finishJob($j['id']); drainQueue();
check($j['success']===1 && !array_filter($requests,fn($r)=>$r['method']==='PUT'),'Recharge recovery repeated increment');
$j=queueJob('recharge',['username'=>'charge','data_limit'=>2,'date_limit'=>1,'reset'=>true,'additive'=>false,'date_type'=>'fixed'],'uncertain-reset');
BatchQueue::run(2,1); $state=BatchQueue::get($j['id']); $state['active']='recharge_reset'; persistJob($state);
$requests=[]; $j=finishJob($j['id']); drainQueue();
check($j['unconfirmed']===1 && !array_filter($requests,fn($r)=>$r['method']!=='GET'),'Uncertain usage reset was retried or followed by a quota write');
// Retention deletes bounded payloads but keeps the deduplication identity.
$old=queueJob('delete',[],'retention'); $old=finishJob($old['id']); drainQueue();
$old=BatchQueue::get($old['id']); $old['finished_at']=time()-10*86400; persistJob($old);
for($i=0;$i<5;$i++) file_put_contents($config['queue_path'].'/'.$old['id'].'/extra-'.$i.'.json','{}');
check(BatchQueue::maintain(2)<=2,'Retention exceeded deletion budget');
for($i=0;$i<20;$i++) BatchQueue::maintain(10);
check(!is_dir($config['queue_path'].'/'.$old['id']) && !empty(BatchQueue::get($old['id'])['archived']),'Completed payloads were not compacted');
check(queueJob('delete',[],'retention')['id']===$old['id'] && !is_dir($config['queue_path'].'/'.$old['id']),'Archived submission replayed');
check(!BatchQueue::health()['stale'],'Worker heartbeat not recorded');
// Node checks retry failed reads and never replay an interrupted restart.
$monitorServer=$server; $monitorServer['node_monitoring']=true; $monitorServer['node_restart']=true; Storage::saveServer($monitorServer);
$requests=[];
MarzbanClient::$transport=function($s,$m,$e,$p) use ($transport) {
    if ($e==='/api/nodes') return [['id'=>7,'name'=>'broken','address'=>'host','status'=>'error','message'=>'connection failed']];
    return $transport($s,$m,$e,$p);
};
$j=BatchQueue::enqueue('monitor',$monitorServer,['recipients'=>[42]],0,0,'monitor-recovery');
BatchQueue::run(2,1); $state=BatchQueue::get($j['id']); $state['active']='restart'; persistJob($state);
$messages=[]; $j=finishJob($j['id']); drainQueue();
check($j['unconfirmed']===1 && !array_filter($requests,fn($r)=>str_contains($r['endpoint'],'reconnect')),'Interrupted node restart repeated');
check(count(array_filter($messages,fn($m)=>str_contains($m['text'],'Not confirmed after interruption')))===1,'Uncertain restart alert lost');
MarzbanClient::$transport=$transport; Storage::saveServer($server);
// Expiry jobs use their reference time and produce a durable outbox.
$db=['soon'=>array_merge($raw,['username'=>'soon','expire'=>time()+7200]),'past'=>array_merge($raw,['username'=>'past','expire'=>time()-60])];
$j=BatchQueue::enqueue('expiry',$server,['recipients'=>[42]],0,0,'expiry-test');
$messages=[]; $j=finishJob($j['id']); drainQueue();
check($j['qualifying']===1 && $j['total']===2 && count(array_filter($messages,fn($m)=>str_contains($m['text'],'<code>soon</code>')))===1,'Expiry job output incorrect');
// A corrupt runnable record fails closed and health exposes the problem.
$j=queueJob('stats',[],'corrupt-health');
$file=$config['queue_path'].'/'.$j['id'].'/job.json'; $saved=file_get_contents($file); file_put_contents($file,'broken');
$threw=false; try { BatchQueue::run(2,1); } catch (RuntimeException $e) { $threw=true; }
check($threw && BatchQueue::health()['stale'] && isset(BatchQueue::health()['error']),'Corrupt job silently skipped');
file_put_contents($file,$saved); BatchQueue::cancel($j['id']); drainQueue();
// A revoked recipient cannot receive an already queued system notification.
$other=BatchQueue::message(99,0,'private','revoked-recipient'); $messages=[]; drainQueue();
check(BatchQueue::get($other['id'])['status']==='cancelled' && !$messages,'Outbox disclosed message to removed administrator');
// Upgrade indexing pauses work and tolerates submissions between migration slices.
drainQueue();
for($i=0;$i<105;$i++) BatchQueue::message(42,42,'migration-'.$i,'migration-'.$i);
foreach (['.indexed','.migration-cursor.json','.migration-jobs.json'] as $name) {
    $file=$config['queue_path'].'/'.$name; if (is_file($file)) unlink($file);
}
foreach (glob($config['queue_path'].'/ready/*') as $file) unlink($file);
$messages=[];
check(BatchQueue::run(2,1)===0 && !$messages,'Legacy migration executed jobs before indexing completed');
$late=BatchQueue::message(42,42,'migration-late','migration-late');
for($i=0;$i<30;$i++) BatchQueue::run(2,100);
check(BatchQueue::get($late['id'])['success']===1 && count(array_filter($messages,fn($m)=>str_starts_with($m['text'],'migration-')))===106,'Migration lost or duplicated newly submitted jobs');
echo 'PASS: '.($checks-$productionChecks)." production queue assertions\n";
