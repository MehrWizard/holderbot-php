<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php'; require __DIR__.'/../helpers/queue.php';
Storage::init();
class PanelManager {
    public static array $raw=['username'=>'test','data_limit'=>200];
    public static function getUser(...$args): array { return ['raw'=>self::$raw]; }
}
foreach(['marzban','marzneshin'] as $type) {
    $id=Storage::saveServer(['type'=>$type,'remark'=>'reconcile_'.$type,'base_url'=>'https://example.invalid','username'=>'test','password'=>'test']);
    $server=Storage::getServer($id);
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
}
echo "PASS: read-only job reconciliation completes observed recharge targets and retains conflicts for both adapters\n";
