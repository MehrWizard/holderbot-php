<?php
/** Queue behavior tests use the same isolated storage and panel fixture as workflows. */
require __DIR__ . '/run.php';
$before = $checks;
function queueJob(string $kind, array $params, string $key): array {
    global $server;
    return BatchQueue::enqueue($kind, $server, $params, 42, 42, $key);
}
function finishJob(string $id): array {
    for ($i = 0; $i < 100; $i++) {
        BatchQueue::run(2, 20);
        $j = BatchQueue::get($id);
        if (in_array($j['status'], ['completed','failed','cancelled'], true) && $j['notification'] !== 'pending') return $j;
    }
    throw new RuntimeException('Job did not finish');
}
$db = [];
for ($i=0; $i<121; $i++) $db['q'.$i] = array_merge($raw, ['username'=>'q'.$i, 'status'=>'expired']);
$requests = [];
$j = queueJob('delete', ['admin'=>'sudo','status'=>'expired'], 'delete-121');
check(!$requests && count($db)===121, 'Enqueue must not contact panel');
check(queueJob('delete', ['admin'=>'sudo','status'=>'expired'], 'delete-121')['id']===$j['id'], 'Duplicate submission creates a job');
BatchQueue::run(2, 1);
check(BatchQueue::get($j['id'])['total']===50 && count($db)===121, 'Discovery must be paginated without mutations');
BatchQueue::run(2, 2);
check(BatchQueue::get($j['id'])['total']===121 && count($db)===121, 'Snapshot must finish before deletion');
BatchQueue::run(2, 1);
check(count($db)===120 && BatchQueue::get($j['id'])['cursor']===1, 'Worker step limit ignored');
$j = finishJob($j['id']);
check($j['success']===121 && !$db, 'Resumed delete skipped users');
$mutationCount = count(array_filter($requests, fn($r)=>$r['method']==='DELETE'));
finishJob(queueJob('delete', ['admin'=>'sudo','status'=>'expired'], 'delete-121')['id']);
check(count(array_filter($requests, fn($r)=>$r['method']==='DELETE'))===$mutationCount, 'Completed submission replayed mutations');
// Read errors must not appear to be empty successful discovery.
$j = queueJob('delete', ['admin'=>'sudo'], 'read-failure');
$fail = true; BatchQueue::run(2, 1); $fail = false;
$state = BatchQueue::get($j['id']);
check($state['status']==='discovering' && $state['read_failures']===1 && $state['total']===0, 'Failed page treated as empty success');
BatchQueue::cancel($j['id']);
// Clear only test backoff so the cancellation can be processed now.
$state['next_run']=0; file_put_contents($config['queue_path'].'/'.$j['id'].'/job.json', json_encode($state));
check(finishJob($j['id'])['status']==='cancelled', 'Cancel did not stop remaining work');
// Interrupted mutation: apply at panel, lose result, then recover without replay.
$db=['crash'=>array_merge($raw,['username'=>'crash'])];
$j=queueJob('delete', [], 'crash'); BatchQueue::run(2,1);
$state=BatchQueue::get($j['id']); $state['active']='mutate'; $state['current_user']='crash';
file_put_contents($config['queue_path'].'/'.$j['id'].'/job.json', json_encode($state));
unset($db['crash']); $requests=[];
$j=finishJob($j['id']);
check($j['unconfirmed']===1 && !array_filter($requests,fn($r)=>$r['method']==='DELETE'), 'Interrupted mutation was replayed');
// Failed mutations are also not automatically replayed.
$db=['reject'=>array_merge($raw,['username'=>'reject'])];
$j=queueJob('delete', [], 'reject'); BatchQueue::run(2,1);
$fail=true; BatchQueue::run(2,1); $fail=false;
$j=finishJob($j['id']); check($j['unconfirmed']===1 && isset($db['reject']), 'Unconfirmed mutation reported success or retried');
// Creation, owner assignment and QR delivery are separate persisted steps.
$j=queueJob('create', ['username'=>'queued','count'=>2,'selected_configs'=>['a'],'admin'=>'sudo','data_limit'=>2,'date_limit'=>2,'date_type'=>'fixed'], 'create');
$photos=count(QrGenerator::$photos); BatchQueue::run(2,1);
$state=BatchQueue::get($j['id']);
check(isset($db['queued1']) && $state['pending']['phase']==='owner' && count(QrGenerator::$photos)===$photos, 'Creation and delivery are not separated');
BatchQueue::run(2,1); check(BatchQueue::get($j['id'])['pending']['phase']==='photo', 'Owner assignment did not checkpoint');
// Simulate a failed photo send; the worker must not recreate the account.
$state=BatchQueue::get($j['id']); $state['active']='photo';
file_put_contents($config['queue_path'].'/'.$j['id'].'/job.json', json_encode($state));
$j=finishJob($j['id']); check($j['success']===2 && $j['delivery_failed']===1, 'Delivery recovery altered mutation results');
// Revoked admins and changed server identities cannot execute pending work.
$j=queueJob('delete', [], 'revoked'); $config['admin_ids']=[];
BatchQueue::run(2,1); check(BatchQueue::get($j['id'])['status']==='cancelled', 'Revoked administrator executed job'); $config['admin_ids']=[42];
$j=queueJob('delete', [], 'server-change'); $changed=$server; $changed['base_url']='https://different.invalid'; Storage::saveServer($changed);
check(finishJob($j['id'])['status']==='failed', 'Changed server identity executed job'); Storage::saveServer($server);
// Transfer snapshots remain complete even when owner filters shrink.
$db=[]; for($i=0;$i<61;$i++) $db['t'.$i]=array_merge($raw,['username'=>'t'.$i]);
$j=finishJob(queueJob('transfer', ['admin'=>'sudo','to_admin'=>'alice'], 'transfer')['id']);
check($j['success']===61 && count(array_filter($db,fn($u)=>$u['admin']['username']==='alice'))===61, 'Queued transfer skipped users');
// Queue callbacks must enforce chat and actor ownership.
Storage::setState(42,'search_user',['server_id'=>1]);
$foreign=BatchQueue::enqueue('delete',$server,[],99,42,'foreign');
click('job_cancel:'.$foreign['id']); check(!is_file($config['queue_path'].'/'.$foreign['id'].'/cancel'), 'Foreign-chat callback cancelled job');
BatchQueue::cancel($foreign['id']); finishJob($foreign['id']);
// Native deadline calculations bound requests and fail before an expired call.
RequestBudget::$deadline=microtime(true)+.2;
check(RequestBudget::milliseconds(20)<=200, 'HTTP timeout exceeds worker budget');
RequestBudget::$deadline=microtime(true)-1; $threw=false;
try { RequestBudget::milliseconds(5); } catch (RuntimeException $e) { $threw=true; }
RequestBudget::$deadline=null;
check($threw, 'Expired worker budget allowed a request');
// Config updates use current per-user configs and preserve unrelated selections.
$ns=Storage::saveServer(array_merge($server,['id'=>0,'remark'=>'neshin_queue','type'=>'marzneshin']));
$ns=Storage::getServer($ns);
$db=['cfg1'=>array_merge($raw,['username'=>'cfg1','service_ids'=>[1],'activated'=>true]), 'cfg2'=>array_merge($raw,['username'=>'cfg2','service_ids'=>[1,2],'activated'=>true])];
$j=BatchQueue::enqueue('config',$ns,['admin'=>'ALL','service_id'=>'2','add'=>true],42,42,'config-add');
$j=finishJob($j['id']);
check($j['success']===1 && $j['skipped']===1 && $db['cfg1']['service_ids']===[1,2], 'Config add overwrote or double-counted configs');
$j=BatchQueue::enqueue('config',$ns,['admin'=>'ALL','service_id'=>'2','add'=>false],42,42,'config-remove');
$j=finishJob($j['id']);
check($j['success']===2 && $db['cfg1']['service_ids']===[1], 'Config removal failed');
// Entry points enqueue without running a destructive call in the webhook.
$db=['entry'=>array_merge($raw,['username'=>'entry'])]; $requests=[];
click('exec_act:del_all:1:sudo');
check(isset($db['entry']) && !$requests && str_contains(lastText(), 'Status: discovering'), 'Batch callback did not defer work');
$recent=BatchQueue::recent(42,42); $entry=$recent[0]; finishJob($entry['id']);
Storage::setState(42,'xfer_confirm',['server_id'=>1,'from_admin'=>'sudo','to_admin'=>'alice']);
$requests=[]; click('xfer_ok:1');
check(!$requests && Storage::getState(42)===null && str_contains(lastText(),'Operation: transfer'), 'Transfer callback did not enqueue');
$recent=BatchQueue::recent(42,42); finishJob($recent[0]['id']);
// Reject oversized imports before downloading or filling wizard storage.
Storage::setState(42,'create_user_json',['server_id'=>1]);
StateHandlers::handle(['chat'=>['id'=>42],'from'=>['id'=>42],'document'=>['file_id'=>'fixture','file_name'=>'large.json','file_size'=>8388609]],Storage::getState(42));
check(str_contains(lastText(),'exceeds 8 MiB'), 'Oversized import accepted');
// Restore default test transport after native timeout checks above.
// Same-server jobs must not mutate while an older snapshot is still being built.
$db=[]; for($i=0;$i<51;$i++) $db['ordered'.$i]=array_merge($raw,['username'=>'ordered'.$i]);
$first=queueJob('transfer',['admin'=>'sudo','to_admin'=>'alice'],'ordered-first');
$second=queueJob('delete',['admin'=>'alice'],'ordered-second');
BatchQueue::run(2,1);
check(BatchQueue::get($second['id'])['page']===1 && BatchQueue::get($first['id'])['page']===2, 'Later same-server job ran during discovery');
$first=finishJob($first['id']); $second=finishJob($second['id']);
check($first['success']===51 && $second['success']===51 && !$db, 'Same-server jobs did not preserve submission order');
// Non-finite import values are rejected before creation.
$invalid=false;
try { QueueImport::validate(['username'=>'invalid','datalimit'=>INF,'datelimit'=>1,'datetypes'=>'now']); }
catch (LengthException $e) { $invalid=true; }
check($invalid,'Non-finite import accepted');
echo 'PASS: '.($checks-$before)." queue assertions\n";
