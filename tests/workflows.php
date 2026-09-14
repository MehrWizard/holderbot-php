<?php
declare(strict_types=1);
// Exercise real handlers and keyboard builders; only external boundaries are stubbed.
class Storage {
    public static array $state=[];
    public static array $templates=[1=>['id'=>1,'remark'=>'basic','date_type'=>'fixed','date_limit'=>30,'data_limit'=>10]];
    public static bool $failSave=false;
    public static function getTemplates(): array { return array_values(self::$templates); }
    public static function getTemplate($id): ?array { return self::$templates[$id] ?? null; }
    public static function saveTemplate($data): int {
        if (self::$failSave) throw new RuntimeException('Simulated database failure');
        $id=$data['id'] ?? count(self::$templates)+1;
        self::$templates[$id]=$data+['id'=>$id]; return $id;
    }
    public static function getServer(int $id): ?array { return $id===1 ? ['id'=>1,'remark'=>'test','type'=>'marzban'] : null; }
    public static function getServers(): array { return [self::getServer(1)]; }
    public static function getState($id): ?array { return self::$state[$id] ?? null; }
    public static function setState($id,$step,$data=[]): void { self::$state[$id]=compact('step','data'); }
    public static function clearState($id): void { unset(self::$state[$id]); }
    public static function cacheGet(...$args): mixed { return null; }
    public static function cacheSet(...$args): void {}
}
class PanelManager {
    public static array $calls=[];
    public static array $users=[];
    public static function modifyUserDataLimit($server,$username,$value): bool { self::$calls[]=['data',$username,$value]; return false; }
    public static function modifyUserNote($server,$username,$value): bool { self::$calls[]=['note',$username,$value]; return true; }
    public static function getUsers($server,$page,$size,$search=null,$status=null): array { self::$calls[]=['list',$page,$size,$search,$status]; return self::$users; }
    public static function getUser($server,$username): array { self::$calls[]=['exact',$username]; return ['username'=>$username,'is_active'=>true,'status'=>'active']; }
}
class Formatter {
    public static function start(): string { return 'Shared welcome'; }
    public static function userCard($server,$user): string { return 'Card '.$user['username']; }
    public static function escape($text): string { return htmlspecialchars($text); }
}
$events=[];
function tg_send_message(...$args): array { global $events; $events[]=['send',$args]; return ['ok'=>true,'result'=>['message_id'=>100]]; }
function tg_edit_message(...$args): array { global $events; $events[]=['edit',$args]; return ['ok'=>true]; }
function tg_replace_message(...$args): array { global $events; $events[]=['replace',$args]; return ['ok'=>true]; }
function tg_delete_message(...$args): array { global $events; $events[]=['delete',$args]; return ['ok'=>true]; }
function tg_answer_callback(...$args): array { global $events; $events[]=['alert',$args]; return ['ok'=>true]; }
require __DIR__.'/../helpers/keyboards.php';
require __DIR__.'/../handlers/commands.php';
require __DIR__.'/../handlers/callbacks.php';
require __DIR__.'/../handlers/states.php';
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function command(string $text): void { CommandHandlers::handle(['text'=>$text,'chat'=>['id'=>99],'from'=>['id'=>42],'message_id'=>5]); }
function callback(string $data): void { CallbackHandlers::handle(['id'=>'test','data'=>$data,'from'=>['id'=>42],'message'=>['chat'=>['id'=>99],'message_id'=>5]]); }
function buttons(array $keyboard): array { return array_column(array_merge(...$keyboard['inline_keyboard']),'callback_data'); }
Storage::setState(42,'create_user_name',[]);
command('/start@my_bot');
check(Storage::getState(42)===null && end($events)[1][1]==='Shared welcome','Start failed to clear wizard and render home');
command('/user 1 name_with_underscores');
check(end(PanelManager::$calls)===['list',1,10,'name_with_underscores',null],'User command bypassed search');
check(end($events)[1][1]==='Select items','Empty search did not render results');
command('/start user_1_name_with_underscores');
check(end(PanelManager::$calls)===['exact','name_with_underscores'],'Deep link damaged username or searched instead of exact lookup');
$count=count(PanelManager::$calls);
command('/user 1abc name'); command('/start user_1abc_name');
check(count(PanelManager::$calls)===$count,'Malformed server ID reached panel');
Storage::setState(42,'create_user_name',[]); callback('home');
check(Storage::getState(42)===null && end($events)[0]==='replace' && end($events)[1][2]==='Shared welcome','Home did not clear wizard and send replacement menu');
callback('queue_home'); check(end($events)[0]==='send','Loading Home reused loading message');
PanelManager::$users=array_fill(0,10,['username'=>'test','is_active'=>true]);
callback('users:1:2:expired');
check(end(PanelManager::$calls)===['list',2,10,null,'expired'],'List filter/page changed');
$keys=buttons(end($events)[1][3]);
check(in_array('users:1:1:expired',$keys,true) && in_array('users:1:3:expired',$keys,true),'Pagination lost filter');
PanelManager::$users=[]; callback('users:1:3:expired');
check(end($events)[0]==='alert' && end($events)[1][2]===true,'Empty page replaced menu instead of alert');
$state=['step'=>'search_user','data'=>['server_id'=>1]];
StateHandlers::handle(['text'=>'query','chat'=>['id'=>99],'from'=>['id'=>42]],$state);
check(end(PanelManager::$calls)===['list',1,10,'query',null],'Search wizard lookup differs from command');
$count=count(PanelManager::$calls); callback('set_own:1:other_admin');
check(end($events)[0]==='alert' && count(PanelManager::$calls)===$count,'Stale owner callback accepted');
$input=['chat'=>['id'=>99],'from'=>['id'=>42]];
Storage::setState(42,'create_user_name',['server_id'=>1]);
StateHandlers::handle($input+['text'=>'abc'],Storage::getState(42));
check(Storage::getState(42)['step']==='create_user_name','Short username advanced wizard');
StateHandlers::handle($input+['text'=>'test_user'],Storage::getState(42));
check(Storage::getState(42)['step']==='create_user_count','Valid username did not advance wizard');
StateHandlers::handle($input+['text'=>'0'],Storage::getState(42));
check(Storage::getState(42)['step']==='create_user_count','Zero count advanced wizard');
StateHandlers::handle($input+['text'=>'۰۲'],Storage::getState(42));
check(Storage::getState(42)['step']==='create_user_suffix' && Storage::getState(42)['data']['count']===2,'Unicode count did not preserve bulk wizard state');
callback('home'); check(Storage::getState(42)===null,'Home left bulk creation state active');
callback('tmpl_edit_dt:1:unlimited');
check(Storage::$templates[1]['date_type']==='fixed','Stale template edit changed data');
callback('tmpl_edit_date:1'); callback('tmpl_edit_dt:1:invalid');
check(Storage::getState(42)['step']==='tmpl_edit_datetype','Invalid date type advanced wizard');
callback('tmpl_edit_dt:1:onhold');
StateHandlers::handle($input+['text'=>'۷'],Storage::getState(42));
check(Storage::$templates[1]['date_type']==='onhold' && Storage::$templates[1]['date_limit']===7,'Template duration edit lost selected strategy');
callback('tmpl_edit_date:1'); callback('tmpl_edit_dt:1:unlimited');
check(Storage::$templates[1]['date_limit']===0 && Storage::getState(42)===null,'Unlimited edit left wizard active');
callback('new_tmpl');
StateHandlers::handle($input+['text'=>'BASIC'],Storage::getState(42));
check(Storage::getState(42)['step']==='tmpl_add_remark','Duplicate template name accepted');
StateHandlers::handle($input+['text'=>'new_plan'],Storage::getState(42));
callback('tmpl_add_dt:unlimited');
check(count(Storage::$templates)===1,'Date button bypassed data-limit entry');
StateHandlers::handle($input+['text'=>'۰'],Storage::getState(42));
Storage::$failSave=true;
try { callback('tmpl_add_dt:unlimited'); throw new LogicException('Save failure fixture not exercised'); }
catch (RuntimeException $expected) {}
check(Storage::getState(42)['step']==='tmpl_add_datetype','Failed save discarded creation state');
Storage::$failSave=false; callback('tmpl_add_dt:unlimited');
check(count(Storage::$templates)===2 && Storage::getState(42)===null,'Unlimited template creation failed');
callback('tmpl_add_dt:unlimited'); check(count(Storage::$templates)===2,'Repeated template callback duplicated creation');
Storage::setState(42,'user_mod_datalimit',['server_id'=>1,'username'=>'test_user']);
$count=count(PanelManager::$calls);
StateHandlers::handle($input+['text'=>'-1'],Storage::getState(42));
check(count(PanelManager::$calls)===$count,'Negative quota reached panel');
StateHandlers::handle($input+['text'=>'۰'],Storage::getState(42));
check(end(PanelManager::$calls)===['data','test_user',0] && end($events)[1][1]==='❌ Failed','Failed unlimited quota edit reported success');
Storage::setState(42,'user_mod_note',['server_id'=>1,'username'=>'test_user']);
$count=count(PanelManager::$calls);
StateHandlers::handle($input+['text'=>str_repeat('ی',501)],Storage::getState(42));
check(count(PanelManager::$calls)===$count,'Oversized Unicode note reached panel');
StateHandlers::handle($input+['text'=>str_repeat('ی',500)],Storage::getState(42));
check(end(PanelManager::$calls)===['note','test_user',str_repeat('ی',500)],'Valid Unicode note rejected');
echo "PASS: command, deep-link, Home, search, pagination and stale wizard workflows; external boundaries stubbed\n";
