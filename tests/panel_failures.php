<?php
declare(strict_types=1);
$config=['scan_request_timeout_seconds'=>25];
require __DIR__.'/../panels/panel_manager.php';
class Storage { public static function cacheSet(...$args): void {} }
foreach(['marzban','marzneshin'] as $type) {
    $creates=0; $owners=0;
    $transport=function($server,$method,$endpoint,$payload)use(&$creates,&$owners) {
        if($method==='GET') return ['vless'=>[['tag'=>'test']]];
        if($endpoint==='/api/user' || $endpoint==='/api/users') { $creates++; return ['username'=>'client']; }
        $owners++; return null;
    };
    MarzbanClient::$transport=$transport; MarzneshinClient::$transport=$transport;
    $server=['type'=>$type,'base_url'=>'https://example.invalid'];
    try {
        PanelManager::createUser($server,'client',1,30,null,$type==='marzban'?['test']:[1],'fixed','owner');
        throw new LogicException('Failed owner assignment reported successful creation');
    } catch(RuntimeException $e) {
        if(!str_contains($e->getMessage(),'User created')) throw $e;
    }
    if($creates!==1 || $owners!==1) throw new RuntimeException('Unexpected create/owner mutation count');
}
MarzbanClient::$transport=fn()=>null;
$failedServer=['id'=>91,'remark'=>'diagnostic-panel','type'=>'marzban','base_url'=>'https://example.invalid'];
try {
    PanelManager::scanUsers($failedServer,1,1000);
    throw new LogicException('Failed scan returned successfully');
} catch(RuntimeException $e) {
    foreach(['diagnostic-panel','adapter: marzban','page: 1','page size: 25','page sizes 1000, 500, 250, 100, 25'] as $detail) {
        if(!str_contains($e->getMessage(),$detail)) throw new RuntimeException('Panel failure omitted diagnostic detail: '.$detail);
    }
}
$timeouts=[];
MarzbanClient::$transport=function($server,$method,$endpoint,$payload,$timeout)use(&$timeouts) {
    $timeouts[]=$timeout;
    if($timeout<=5) { MarzbanClient::$lastError='Connection error: Operation timed out'; return null; }
    return ['users'=>[['username'=>'slow','status'=>'active','data_limit'=>0,'used_traffic'=>0]],'total'=>1];
};
$slow=PanelManager::scanUsers($failedServer,1,1000);
if($slow['page_size']!==25 || $slow['users'][0]['username']!=='slow' || end($timeouts)!==25) throw new RuntimeException('Slow panel did not receive the extended scan timeout');
echo "PASS: partial creation/ownership failures across both adapters\n";
