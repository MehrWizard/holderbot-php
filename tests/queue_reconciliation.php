<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php'; require __DIR__.'/../helpers/queue.php';
Storage::init();
class PanelManager {
    public static array $raw=['username'=>'test','data_limit'=>200];
    public static function getUser(...$args): array { return ['raw'=>self::$raw,'service_ids'=>self::$raw['service_ids']??[]]; }
    public static function probeUser(...$args): array { return ['confirmed'=>true,'user'=>['raw'=>self::$raw]]; }
    public static function setStatus($s,$u,$v): bool { self::$raw[$s['type']==='marzban'?'status':'enabled']=$s['type']==='marzban'?($v?'active':'disabled'):$v; return true; }
    public static function modifyUserDataLimit($s,$u,$v): bool { self::$raw['data_limit']=(int)$v*1024**3; return true; }
    public static function modifyUserNote($s,$u,$v): bool { self::$raw['note']=$v; return true; }
    public static function setOwner($s,$u,$v): bool { if($s['type']==='marzban')self::$raw['admin']=['username'=>$v];else self::$raw['owner_username']=$v; return true; }
    public static function updateUserConfigs($s,$u,$v): bool { self::$raw['service_ids']=$v; if($s['type']==='marzban')self::$raw['inbounds']=['x'=>$v]; return true; }
    public static function deleteUser(...$args): bool { return true; }
    public static function datePayload($s,$u,$days,$type): array { return ['expire'=>$type==='unlimited'?0:$days]; }
    public static function updateDateLimit($s,$u,$days,$type): bool { self::$raw['expire']=$type==='unlimited'?0:$days; return true; }
}
foreach(['marzban','marzneshin'] as $type) {
    $id=Storage::saveServer(['type'=>$type,'remark'=>'reconcile_'.$type,'base_url'=>'https://example.invalid','username'=>'test','password'=>'test']);
    $server=Storage::getServer($id);
    $first=BatchQueue::enqueue('delete',$server,['message_id'=>7,'admin'=>'alice'],99,42,'callback:bulk_a_'.$type);
    $repeat=BatchQueue::enqueue('delete',$server,['message_id'=>7,'admin'=>'alice'],99,42,'callback:bulk_a_'.$type);
    $second=BatchQueue::enqueue('delete',$server,['message_id'=>7,'admin'=>'bob'],99,42,'callback:bulk_b_'.$type);
    if($first['id']!==$repeat['id'] || $first['id']===$second['id']) throw new RuntimeException('Bulk callback identity suppressed a new action or replayed an old one');
    foreach([200,300] as $value) {
        $job=BatchQueue::enqueueInline('recharge',$server,['username'=>'test'],0,0,'reconcile_'.$value);
        BatchQueue::executeInline($job,function(array &$running){
            $running['params']['mutation_intent']=['username'=>'test','phase'=>'apply_recharge','before'=>['username'=>'test','data_limit'=>100],'payload'=>['data_limit'=>200]];
            throw new RuntimeException('response lost');
        });
        PanelManager::$raw['data_limit']=$value;
        $result=BatchQueue::reconcile($job['id']);
        if($result['state']!==($value===200?'desired_state_observed':'conflict')) throw new RuntimeException('Wrong comparison');
        if(BatchQueue::get($job['id'])['status']!==($value===200?'completed':'failed')) throw new RuntimeException('Unsafe reconciliation transition');
    }
    $field=$type==='marzneshin'?'traffic_reset_at':'last_traffic_reset_time';
    $cases=[
        ['reset',['username'=>'test','phase'=>'reset_usage','before'=>['username'=>'test',$field=>'2026-01-01T00:00:00Z'],'payload'=>[]],['username'=>'test',$field=>'2026-01-02T00:00:00Z'],'reset_marker_changed'],
        ['revoke_qr',['username'=>'test','phase'=>'revoke_subscription','before'=>['username'=>'test','subscription_url'=>'old'],'payload'=>[]],['username'=>'test','subscription_url'=>'new'],'subscription_changed'],
        ['create',['username'=>'test','phase'=>'create_user','before'=>['username'=>'test','_exists'=>false],'payload'=>['data_limit'=>200]],['username'=>'test','data_limit'=>200],'desired_state_observed'],
    ];
    foreach($cases as [$kind,$intent,$current,$state]) {
        $job=BatchQueue::enqueueInline($kind,$server,['username'=>'test'],0,0,'reconcile_'.$type.'_'.$kind);
        BatchQueue::executeInline($job,function(array &$running)use($intent){$running['params']['mutation_intent']=$intent;throw new RuntimeException('response lost');});
        PanelManager::$raw=$current;
        if(BatchQueue::reconcile($job['id'])['state']!==$state || BatchQueue::get($job['id'])['status']!=='completed') throw new RuntimeException('Safe '.$kind.' reconciliation failed');
    }
    PanelManager::$raw=['username'=>'test','data_limit'=>1,'service_ids'=>[1],'status'=>'disabled','enabled'=>false];
    foreach([
        ['status','active',true],['data','value',2],['note','note','memo'],['owner','owner','alice'],['config','ids',[2]],['date','days',0]
    ] as $i=>[$operation,$key,$value]) {
        $params=['username'=>'test','operation'=>$operation,$key=>$value]; if($operation==='date')$params['date_type']='unlimited';
        $result=BatchQueue::submitUserMutation($server,$params,0,0,'mutation_'.$type.'_'.$i);
        if($result['state']!=='completed') throw new RuntimeException('Journaled '.$operation.' mutation failed');
    }
}
echo "PASS: read-only reconciliation for creation, recharge, reset, revoke and user mutations on both adapters; conflicts remain held\n";
