<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
Storage::init();
$sends=[];
function tg_send_message($chat, $text, ...$args): array {
    global $sends;
    $sends[] = [$chat,$text];
    throw new RuntimeException('Simulated delivery failure');
}
function tg_replace_message(...$args): array { return ['ok'=>false]; }
class PanelManager {
    public static int $revokes=0;
    public static function getUser(...$args): array { return ['subscription_url'=>'old secret subscription']; }
    public static function revokeSub(...$args): array { self::$revokes++; return ['subscription_url'=>'test']; }
    public static function chargeUser($server,$username,$data,$days,$reset,$additive,$type,$beforeWrite): ?array {
        global $rechargeId;
        foreach (['reset_usage','apply_recharge'] as $phase) {
            $beforeWrite($phase,['data_limit'=>12345,'expire'=>45678]);
            $saved=BatchQueue::get($rechargeId);
            if (($saved['params']['mutation_intent']['phase'] ?? '')!==$phase || $saved['active']!=='mutation') throw new LogicException('Remote write preceded intent checkpoint');
        }
        throw new RuntimeException('Simulated lost recharge response');
    }
}
class QrGenerator {
    public static function sendQrPhoto(...$args): array { throw new RuntimeException('Simulated QR failure'); }
}
class Formatter { public static function userInfo(...$args): string { return 'user'; } }
class Keyboards { public static function cancel(...$args): array { return []; } }
$serverId=Storage::saveServer(['remark'=>'delivery','type'=>'marzban','base_url'=>'http://example.invalid','username'=>'test','password'=>'test']);
$server=Storage::getServer($serverId);
$step=new ReflectionMethod(BatchQueue::class,'step');
$store=new ReflectionMethod(BatchQueue::class,'storeReportPage');
$save=new ReflectionMethod(BatchQueue::class,'save');
$report=BatchQueue::enqueue('expiry',$server,['report_ready'=>true,'recipients'=>[11,22,33]],0,0,'delivery_report');
$store->invoke(null,$report['id'],1,['report one']);
for ($i=1;$i<=3;$i++) {
    $step->invokeArgs(null,[&$report]);
    if (count($sends)!==$i) throw new RuntimeException('Report did not bound delivery to one recipient');
    $report=BatchQueue::get($report['id']);
}
$step->invokeArgs(null,[&$report]);
if ($report['status']!=='completed' || array_column($sends,0)!==[11,22,33]) throw new RuntimeException('Report failed to advance after failed sends');
$save->invoke(null,$report);
$report['status']='failed';
if (str_contains(BatchQueue::describe($report),'Delivering')) throw new RuntimeException('Terminal report has nonterminal description');
$report['error']=str_repeat('خطا & <bad> ',100);
$alert=BatchQueue::alertText($report);
if (!preg_match('//u',$alert) || preg_match_all('/./us',$alert)>190 || str_contains($alert,'&lt;')) throw new RuntimeException('Invalid Unicode status alert');
$uncertain=BatchQueue::enqueueInline('recharge',$server,['username'=>'needs_review'],0,0,'retain_evidence');
BatchQueue::executeInline($uncertain,fn()=>null);
Storage::db()->prepare('UPDATE bot_queue SET updated_at=FROM_UNIXTIME(?) WHERE id=?')->execute([time()-864000,$uncertain['id']]);
BatchQueue::maintain(1);
if (empty(BatchQueue::get($uncertain['id'])['params']['username'])) throw new RuntimeException('Cleanup erased uncertain mutation evidence');
$outbox=BatchQueue::message(44,0,'one attempt','delivery_outbox');
BatchQueue::run(2,10);
BatchQueue::run(2,10);
if (BatchQueue::get($outbox['id'])['status']!=='completed' || count($sends)!==4) throw new RuntimeException('Failed outbox delivery replayed');
$revoke=BatchQueue::enqueue('revoke_qr',$server,['username'=>'test','message_id'=>123],44,0,'delivery_revoke');
BatchQueue::run(2,10);
BatchQueue::run(2,10);
$revoke=BatchQueue::get($revoke['id']);
if ($revoke['status']!=='completed' || (int)$revoke['success']!==1 || PanelManager::$revokes!==1) throw new RuntimeException('QR failure changed or replayed confirmed revoke');
$evidence=BatchQueue::issues($revoke['id']);
if (($evidence['intent']['payload']['previous_subscription_sha256'] ?? '')!==hash('sha256','old secret subscription')) throw new RuntimeException('Missing revoke baseline');
$recharge=BatchQueue::enqueueInline('recharge',$server,['username'=>'review_me','data_limit'=>1,'date_limit'=>2,'reset'=>true],0,0,'intent_recharge');
$rechargeId=$recharge['id'];
$attempt=BatchQueue::executeInline($recharge,function(array &$running)use($server){ return BatchQueue::recharge($running,$server); });
$evidence=BatchQueue::issues($rechargeId);
if ($attempt['state']!=='failed' || $evidence['intent']['payload']['data_limit']!==12345 || $evidence['intent']['phase']!=='apply_recharge') throw new RuntimeException('Lost recharge intent after failure');
$insert=Storage::db()->prepare("INSERT INTO bot_queue_items(job_id,position,username,status) VALUES(?,?,?,'uncertain')");
for($i=0;$i<51;$i++) $insert->execute([$rechargeId,$i,'review_'.$i]);
if (count(BatchQueue::issues($rechargeId)['items'])!==50 || count(BatchQueue::issues($rechargeId,49)['items'])!==1) throw new RuntimeException('Issue inspection is not paginated');
echo "PASS: bounded report recipients, terminal delivery failures, confirmed revoke survives QR failure; Telegram stubbed\n";
