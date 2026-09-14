<?php
declare(strict_types=1);
require __DIR__.'/../helpers/reconciliation.php';
function check($actual,$expected): void { if($actual['state']!==$expected) throw new RuntimeException('Expected '.$expected.', got '.$actual['state']); }
foreach(['marzban','marzneshin'] as $type) {
    $before=['username'=>'test','data_limit'=>100,'subscription_url'=>'old'];
    $intent=['username'=>'test','phase'=>'apply_recharge','before'=>$before,'payload'=>['data_limit'=>200]];
    check(MutationReconciliation::compare($type,$intent,['username'=>'test','data_limit'=>200]),'desired_state_observed');
    check(MutationReconciliation::compare($type,$intent,['username'=>'test','data_limit'=>300]),'conflict');
    check(MutationReconciliation::compare($type,$intent,['username'=>'test']),'insufficient_evidence');
    check(MutationReconciliation::compare($type,$intent,['username'=>'other','data_limit'=>200]),'insufficient_evidence');
    $intent['phase']='revoke_subscription';
    check(MutationReconciliation::compare($type,$intent,$before),'unchanged');
    check(MutationReconciliation::compare($type,$intent,array_replace($before,['subscription_url'=>'new'])),'subscription_changed');
    $intent['phase']='reset_usage';
    check(MutationReconciliation::compare($type,$intent,array_replace($before,['used_traffic'=>0])),'insufficient_evidence');
    $field=$type==='marzneshin'?'traffic_reset_at':'last_traffic_reset_time';
    $intent['before'][$field]='2026-01-01T00:00:00Z';
    check(MutationReconciliation::compare($type,$intent,array_replace($before,[$field=>'2026-01-02T00:00:00Z'])),'reset_marker_changed');
    check(MutationReconciliation::compare($type,$intent,array_replace($before,[$field=>'2025-01-02T00:00:00Z'])),'unchanged');
    $intent['phase']='assign_created_owner'; $intent['payload']=['owner_username'=>'alice'];
    $owned=$before+($type==='marzban'?['admin'=>['username'=>'alice']]:['owner_username'=>'alice']);
    check(MutationReconciliation::compare($type,$intent,$owned),'desired_state_observed');
    $owned['subscription_url']='different-user-identity';
    check(MutationReconciliation::compare($type,$intent,$owned),'identity_conflict');
    $created=['username'=>'test','_exists'=>false];
    $payload=$type==='marzban'?['data_limit'=>200,'status'=>'active']:['data_limit'=>200,'expire_strategy'=>'never'];
    check(MutationReconciliation::compare($type,['username'=>'test','phase'=>'create_user','before'=>$created,'payload'=>$payload],['username'=>'test']+$payload),'desired_state_observed');
    check(MutationReconciliation::compare($type,['username'=>'test','phase'=>'create_user','before'=>$created,'payload'=>$payload],['username'=>'test','data_limit'=>300]+$payload),'creation_conflict');
    $payload['owner_username']='alice';
    $withoutOwner=$payload; unset($withoutOwner['owner_username']);
    check(MutationReconciliation::compare($type,['username'=>'test','phase'=>'create_user','before'=>$created,'payload'=>$payload],['username'=>'test']+$withoutOwner),'creation_conflict');
    $raw=$type==='marzban'?['username'=>'test','status'=>'disabled','admin'=>['username'=>'old'],'inbounds'=>['vless'=>['old']]]:['username'=>'test','enabled'=>false,'owner_username'=>'old','service_ids'=>[1]];
    foreach([
        ['enabled'=>true],['owner_username'=>'alice'],[$type==='marzban'?'selected_configs':'service_ids'=>$type==='marzban'?['new']:[2]],['data_limit'=>500],['note'=>'updated']
    ] as $wanted) {
        $current=$raw;
        foreach($wanted as $key=>$value) {
            if($key==='enabled') $type==='marzban'?$current['status']='active':$current['enabled']=$value;
            elseif($key==='owner_username') $type==='marzban'?$current['admin']=['username'=>$value]:$current[$key]=$value;
            elseif($key==='selected_configs') $current['inbounds']=['vless'=>$value]; else $current[$key]=$value;
        }
        check(MutationReconciliation::compare($type,['username'=>'test','phase'=>'modify_user','before'=>$raw,'payload'=>$wanted],$current),'desired_state_observed');
    }
    check(MutationReconciliation::compare($type,['username'=>'test','phase'=>'delete_user','before'=>$raw,'payload'=>[]],null),'desired_state_observed');
}
echo "PASS: adapter-specific read-only reconciliation, concurrent conflicts and missing evidence\n";
