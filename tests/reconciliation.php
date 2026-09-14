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
}
echo "PASS: adapter-specific read-only reconciliation, concurrent conflicts and missing evidence\n";
