<?php
declare(strict_types=1);
$config=['storage_type'=>'mysql','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[1],'database'=>'fresh','username'=>'root','password'=>'']];
require __DIR__.'/../storage.php';
require __DIR__.'/../helpers/queue.php';
require __DIR__.'/../panels/panel_manager.php';
Storage::init();
$attempted=[];
MarzbanClient::$transport=function($server,$method,$endpoint)use(&$attempted) {
    parse_str((string)parse_url($endpoint,PHP_URL_QUERY),$query);
    $limit=(int)($query['limit']??0); $attempted[]=$limit;
    if($limit>100)return null;
    return ['users'=>[['username'=>'negotiated','status'=>'active','data_limit'=>0,'used_traffic'=>0]],'total'=>1];
};
$negotiatedServer=Storage::getServer(Storage::saveServer(['remark'=>'negotiation','type'=>'marzban','base_url'=>'http://example.invalid','username'=>'test','password'=>'test']));
$negotiated=PanelManager::scanUsers($negotiatedServer,1,1000);
if($attempted!==[1000,500,250,100] || $negotiated['page_size']!==100 || $negotiated['users'][0]['username']!=='negotiated') throw new RuntimeException('Panel page-size negotiation failed');
$cache=Storage::db()->prepare('SELECT expires_at FROM bot_cache WHERE cache_key=?');
$cache->execute(['panel_page_size_'.$negotiatedServer['id']]);
if((int)$cache->fetchColumn()>time()+1) throw new RuntimeException('Negotiated page size did not use the measured request window');
sleep(2);
if(PanelManager::pageSize($negotiatedServer)!==1000) throw new RuntimeException('Expired page-size cache was reused');
$cache->execute(['panel_page_size_'.$negotiatedServer['id']]);
if($cache->fetchColumn()!==false) throw new RuntimeException('Expired page-size cache was not deleted');
$memory=memory_get_usage(true);
foreach(['marzban','marzneshin'] as $type) {
    $next=0;
    $transport=function($server,$method,$endpoint,$payload)use(&$next,$type) {
        $expected=($type==='marzban'?'/api/user/':'/api/users/').'large_'.$next;
        if($method==='GET' && $endpoint===$expected) return $type==='marzban'
            ? ['username'=>'large_'.$next,'status'=>'active','data_limit'=>0,'used_traffic'=>0]
            : ['username'=>'large_'.$next,'enabled'=>true,'is_active'=>true,'expire_strategy'=>'never','data_limit'=>0,'used_traffic'=>0,'service_ids'=>[]];
        if($method!=='DELETE' || $endpoint!==$expected) throw new LogicException('Skipped or repeated bulk target: '.$endpoint.' expected '.$expected);
        $next++; return ['success'=>true];
    };
    MarzbanClient::$transport=$transport; MarzneshinClient::$transport=$transport;
    $id=Storage::saveServer(['remark'=>'bulk_'.$type,'type'=>$type,'base_url'=>'http://example.invalid','username'=>'test','password'=>'test']);
    $job=BatchQueue::enqueue('delete',Storage::getServer($id),[],0,0,'bulk_test');
    Storage::db()->beginTransaction();
    $insert=Storage::db()->prepare('INSERT INTO bot_queue_items(job_id,position,username) VALUES(?,?,?)');
    for($i=0;$i<16000;$i++) $insert->execute([$job['id'],$i,'large_'.$i]);
    Storage::db()->prepare("UPDATE bot_queue SET status='running',total=16000 WHERE id=?")->execute([$job['id']]);
    Storage::db()->commit();
    for($round=0;$round<400;$round++) {
        BatchQueue::run(2,100);
        $job=BatchQueue::get($job['id']);
        if($job['status']==='completed') break;
        if($job['status']==='failed') throw new RuntimeException($job['error']);
    }
    if($job['status']!=='completed' || $next!==16000 || (int)$job['success']!==16000 || (int)$job['unconfirmed']!==0) throw new RuntimeException('Large mutation workload incomplete');
    $count=Storage::db()->prepare("SELECT COUNT(*) FROM bot_queue_items WHERE job_id=? AND status='succeeded'"); $count->execute([$job['id']]);
    if((int)$count->fetchColumn()!==16000 || strlen($job['payload'])>2048) throw new RuntimeException('Lost item results or unbounded parent payload');
}
if(memory_get_peak_usage(true)-$memory>16*1024*1024) throw new RuntimeException('Bulk execution retained target arrays');
echo "PASS: 16,000 deletions per adapter, exact target ordering, saved item outcomes and bounded PHP memory; remote transport stubbed\n";
