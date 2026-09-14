<?php
declare(strict_types=1);
require __DIR__.'/../panels/panel_manager.php';
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
echo "PASS: partial creation/ownership failures across both adapters\n";
