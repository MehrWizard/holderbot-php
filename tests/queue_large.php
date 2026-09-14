<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
Storage::init();
foreach (['[{"username":"a"},]', '[{}]garbage', '[{"username":"a"}', '[null]'] as $invalid) {
    try { ImportReader::page(fn($offset,$length)=>substr($invalid,$offset,$length),0,false); throw new LogicException('Malformed import accepted'); }
    catch (InvalidArgumentException|JsonException $expected) {}
}
$escaped=json_encode([['username'=>'a','note'=>'escaped " brace }'],['username'=>'b']]);
$page=ImportReader::page(fn($offset,$length)=>substr($escaped,$offset,min($length,7)),0,false,1);
$next=ImportReader::page(fn($offset,$length)=>substr($escaped,$offset,min($length,7)),$page['offset'],true,1);
if (!$next['done'] || $next['items'][0]['username']!=='b') throw new RuntimeException('Chunk boundary parsing failed');
function tg_download_file(string $id, int $max): string { return '[' . implode(',', array_map(fn($i)=>json_encode(['username'=>'large_'.$i,'datalimit'=>2,'datelimit'=>30]),range(1,16000))) . ']'; }
function tg_replace_message(...$args): array { return ['ok'=>false,'error_code'=>403]; }
function tg_edit_message(...$args): array { return ['ok'=>false,'error_code'=>403]; }
function tg_send_message(...$args): array { return ['ok'=>false,'error_code'=>403]; }
$serverId=Storage::saveServer(['remark'=>'isolated','type'=>'marzban','base_url'=>'http://example.invalid','username'=>'test','password'=>'test']);
$server=Storage::getServer($serverId);
$locked=BatchQueue::enqueueInline('qr',$server,[],0,0,'locked');
$other=new PDO('mysql:host=127.0.0.1;port='.(int)$argv[1].';dbname=fresh','root','');
$lock=$other->prepare('SELECT GET_LOCK(?,0)'); $lock->execute(['holderbot-job-'.$locked['id']]);
BatchQueue::cancel($locked['id']);
if (!BatchQueue::get($locked['id'])['cancel_requested']) throw new RuntimeException('Cancellation request lost');
if (BatchQueue::get($locked['id'])['status']!=='inline') throw new RuntimeException('Locked job state changed');
$other->query('SELECT RELEASE_ALL_LOCKS()');
BatchQueue::cancel($locked['id']);
$pending=BatchQueue::message(99,42,'must not run','cancel_pending');
$lock->execute(['holderbot-job-'.$pending['id']]);
BatchQueue::cancel($pending['id']);
$other->query('SELECT RELEASE_ALL_LOCKS()');
BatchQueue::run(2,2);
if (BatchQueue::get($pending['id'])['status']!=='cancelled') throw new RuntimeException('Worker lost pending cancellation');
$uncertain=BatchQueue::enqueueInline('recharge',$server,[],0,0,'cancel_uncertain');
Storage::db()->prepare("UPDATE bot_queue SET status='inline_running',active_phase='mutation' WHERE id=?")->execute([$uncertain['id']]);
BatchQueue::cancel($uncertain['id']);
$uncertain=BatchQueue::get($uncertain['id']);
if ($uncertain['status']!=='failed' || (int)$uncertain['unconfirmed']!==1) throw new RuntimeException('Cancellation erased uncertain mutation');
$job=BatchQueue::enqueue('import',$server,['import_file_id'=>'test'],99,42,'large');
$step=new ReflectionMethod(BatchQueue::class,'step');
$save=new ReflectionMethod(BatchQueue::class,'save');
for ($i=0; $i<400; $i++) {
    $step->invokeArgs(null,[&$job]); $save->invoke(null,$job);
    $job=BatchQueue::get($job['id']);
    if ($job['kind']==='create') break;
}
if ($job['kind']!=='create' || (int)$job['total']!==16000) throw new RuntimeException('Large import incomplete');
$stmt=Storage::db()->prepare('SELECT COUNT(*) FROM bot_queue_items WHERE job_id=?'); $stmt->execute([$job['id']]);
if ((int)$stmt->fetchColumn()!==16000) throw new RuntimeException('Import rows lost');
if (strlen(json_encode($job['params']))>2048) throw new RuntimeException('Parent payload grew with import');
BatchQueue::cancel($job['id']);

// A hard exit after persisting the mutation phase must not replay it.
$crash=BatchQueue::enqueueInline('recharge',$server,[],0,0,'crash');
$pid=pcntl_fork();
if ($pid===0) {
    // Forked PDO connections cannot be shared; use a fresh process instead.
    pcntl_exec(PHP_BINARY,['-d','extension=pdo_mysql','-r',
        '$config='.var_export($config,true).'; require '.var_export(__DIR__.'/../storage.php',true).'; require '.var_export(__DIR__.'/../helpers/queue.php',true).'; Storage::init(); BatchQueue::executeInline(BatchQueue::get("'.$crash['id'].'"), function(){exit(17);});']);
    exit(99);
}
pcntl_waitpid($pid,$status);
if (pcntl_wexitstatus($status)!==17) throw new RuntimeException('Crash fixture did not execute');
Storage::db()->prepare('UPDATE bot_queue SET lease_until=0 WHERE id=?')->execute([$crash['id']]);
BatchQueue::run(2,10);
$result=BatchQueue::get($crash['id']);
if ($result['status']!=='failed' || (int)$result['unconfirmed']!==1) throw new RuntimeException('Interrupted mutation was replayed');
if (BatchQueue::health()['stale']) throw new RuntimeException('Heartbeat was not recorded');
Storage::cacheSet('queue_heartbeat',time()-600,3600);
if (!BatchQueue::health()['stale']) throw new RuntimeException('Stopped worker not detected');
$first=BatchQueue::message(99,42,'first','fair1');
$second=BatchQueue::message(99,42,'second','fair2');
$lock->execute(['holderbot-job-'.$first['id']]);
BatchQueue::run(2,2);
if (BatchQueue::get($second['id'])['status']!=='completed') throw new RuntimeException('Locked oldest job starved newer work');
$other->query('SELECT RELEASE_ALL_LOCKS()');
BatchQueue::cancel($first['id']);
Storage::db()->prepare('UPDATE bot_queue SET updated_at=FROM_UNIXTIME(?) WHERE id=?')->execute([time()-864000,$job['id']]);
BatchQueue::maintain(1);
$stmt->execute([$job['id']]);
if ((int)$stmt->fetchColumn()!==15000) throw new RuntimeException('Cleanup did not respect 1000-row budget');
BatchQueue::maintain(1);
$stmt->execute([$job['id']]);
if ((int)$stmt->fetchColumn()!==14000) throw new RuntimeException('Cleanup stopped after parent compaction');
echo "PASS: 16,000 import rows, bounded parent payload, process-exit mutation recovery; Telegram stubbed\n";
