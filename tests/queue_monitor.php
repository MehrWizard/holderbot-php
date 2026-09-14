<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
require __DIR__.'/../panels/panel_manager.php';
require __DIR__.'/../helpers/format.php';
Storage::init();
$sends=[];
function tg_send_message($recipient,$text,...$args): array { global $sends; $sends[]=[$recipient,$text]; return ['ok'=>false]; }
function tg_replace_message(...$args): array { return ['ok'=>false]; }
foreach (['marzban','marzneshin'] as $type) {
    $restarts=[];
    $transport=function($server,$method,$endpoint,$payload)use($type,&$restarts) {
        if ($method==='GET') {
            $nodes=[['id'=>1,'name'=>'healthy','status'=>'connected'],['id'=>2,'name'=>'bad <node>','status'=>$type==='marzban'?'error':'unhealthy'],['id'=>3,'name'=>'disabled','status'=>'disabled']];
            return $type==='marzban' ? $nodes : ['items'=>$nodes];
        }
        $restarts[]=$endpoint; return null;
    };
    MarzbanClient::$transport=$transport; MarzneshinClient::$transport=$transport;
    $id=Storage::saveServer(['remark'=>'monitor_'.$type,'type'=>$type,'base_url'=>'http://example.invalid','username'=>'test','password'=>'test','node_monitoring'=>1,'node_restart'=>1]);
    $job=BatchQueue::enqueue('monitor',Storage::getServer($id),['recipients'=>[10,20]],0,0,'monitor_test');
    $step=new ReflectionMethod(BatchQueue::class,'step'); $save=new ReflectionMethod(BatchQueue::class,'save');
    for($i=0;$i<9;$i++) {
        $before=count($restarts); $sent=count($sends);
        $step->invokeArgs(null,[&$job]); $save->invoke(null,$job); $job=BatchQueue::get($job['id']);
        if(count($restarts)-$before>1 || count($sends)-$sent>1) throw new RuntimeException('Monitoring exceeded per-step call limit');
        if($job['status']==='completed') break;
    }
    if($job['status']!=='completed' || count($restarts)!==1 || (int)$job['unconfirmed']!==1) throw new RuntimeException('Monitor lost restart failure or restarted healthy/disabled node');
    if(!str_contains(end($sends)[1],'Not confirmed') || !str_contains(end($sends)[1],'bad &lt;node&gt;')) throw new RuntimeException('Node report lost failure or escaping');
    MarzbanClient::$transport=fn()=>null; MarzneshinClient::$transport=fn()=>null;
    try { PanelManager::getNodes(Storage::getServer($id),true); throw new LogicException('Failed node lookup accepted'); }
    catch(RuntimeException $expected) {}
}
echo "PASS: both panel monitoring adapters, bounded restarts and recipients, restart failures and strict node reads; Telegram stubbed\n";
