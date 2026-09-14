<?php
declare(strict_types=1);
// Exercise real handlers and keyboard builders; only external boundaries are stubbed.
class Storage {
    public static array $state=[];
    public static function getServer(int $id): ?array { return $id===1 ? ['id'=>1,'remark'=>'test','type'=>'marzban'] : null; }
    public static function getServers(): array { return [self::getServer(1)]; }
    public static function getState($id): ?array { return self::$state[$id] ?? null; }
    public static function setState($id,$step,$data): void { self::$state[$id]=compact('step','data'); }
    public static function clearState($id): void { unset(self::$state[$id]); }
    public static function cacheGet(...$args): mixed { return null; }
    public static function cacheSet(...$args): void {}
}
class PanelManager {
    public static array $calls=[];
    public static array $users=[];
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
echo "PASS: command, deep-link, Home, search, pagination and stale wizard workflows; external boundaries stubbed\n";
