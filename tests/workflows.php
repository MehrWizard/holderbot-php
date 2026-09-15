<?php
declare(strict_types=1);
// Exercise real handlers and keyboard builders; only external boundaries are stubbed.
class Storage {
    public static array $state=[];
    public static array $cache=[];
    public static array $templates=[1=>['id'=>1,'remark'=>'basic','date_type'=>'fixed','date_limit'=>30,'data_limit'=>10]];
    public static bool $failSave=false;
    public static function getTemplates(): array { return array_values(self::$templates); }
    public static function getActiveTemplates(): array { return array_values(array_filter(self::$templates,fn($t)=>!isset($t['is_active']) || !empty($t['is_active']))); }
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
    public static function cacheGet($key): mixed { return self::$cache[$key] ?? null; }
    public static function cacheSet($key,$value,...$args): void { self::$cache[$key]=$value; }
    public static function rememberUserBack($chat,$server,$username,$callback): void { self::$cache['back|'.$chat.'|'.$server.'|'.$username]=$callback; }
    public static function userBack($chat,$server,$username): ?string { return self::$cache['back|'.$chat.'|'.$server.'|'.$username]??null; }
}
class PanelManager {
    public static array $calls=[];
    public static array $users=[];
    public static ?int $total=null;
    public static array $admins=['new_admin','admin1','admin2'];
    public static function modifyUserDataLimit($server,$username,$value): bool { self::$calls[]=['data',$username,$value]; return false; }
    public static function modifyUserNote($server,$username,$value): bool { self::$calls[]=['note',$username,$value]; return true; }
    public static function getServices($server): array { return [['id'=>'one:tcp','name'=>'One'],['id'=>'two','name'=>'Two']]; }
    public static function getAdmins($server): array { return self::$admins; }
    public static function getAdmin($server,$username): ?array { return in_array($username,self::$admins,true)?['username'=>$username,'is_sudo'=>false]:null; }
    public static function getBotUsername(): string { return 'test_bot'; }
    public static function updateUserConfigs($server,$username,$ids): bool { self::$calls[]=['configs',$username,$ids]; return false; }
    public static function setOwner($server,$username,$admin): bool { self::$calls[]=['owner',$username,$admin]; return false; }
    public static function getUsers($server,$page,$size,$search=null,$status=null): array { self::$calls[]=['list',$page,$size,$search,$status]; return self::$users; }
    public static function getLastUsersTotal($server): ?int { return self::$total; }
    public static function getAdminUserCounts($server,$admin): array { return ['total'=>self::$total,'active'=>1,'disabled'=>2,'on_hold'=>3,'limited'=>4,'expired'=>5]; }
    public static function getUser($server,$username): array { self::$calls[]=['exact',$username]; return ['username'=>$username,'is_active'=>true,'status'=>'active','service_ids'=>['one:tcp','two']]; }
}
class Formatter {
    public static function start(): string { return 'Shared welcome'; }
    public static function userCard($server,$user): string { return 'Card '.$user['username']; }
    public static function adminCard($server,$admin,$total=null): string { return 'Admin '.$admin['username']; }
    public static function templateCard($template): string { return 'Template '.$template['id']; }
    public static function escape($text): string { return htmlspecialchars($text); }
}
class BatchQueue {
    public static array $queued=[];
    public static function cachedStats($id,$freshOnly=false): ?array { $v=Storage::cacheGet('stats_result_'.$id); return is_array($v) && (!$freshOnly || (int)($v['fresh_until']??0)>=time())?$v:null; }
    public static function enqueueInline($kind,...$args): array { self::$queued[]=$kind; return ['id'=>'test','kind'=>$kind,'status'=>'completed']; }
    public static function loadingMessage($kind): string { return 'Loading'; }
    public static function submitUserMutation($server,$p,$chatId=0,...$args): array {
        $ok=match($p['operation']) {
            'data'=>PanelManager::modifyUserDataLimit($server,$p['username'],$p['value']),
            'note'=>PanelManager::modifyUserNote($server,$p['username'],$p['note']),
            'owner'=>PanelManager::setOwner($server,$p['username'],$p['owner']),
            'config'=>PanelManager::updateUserConfigs($server,$p['username'],$p['ids']),
            default=>true,
        };
        tg_edit_message($chatId,(int)($p['message_id']??0),$ok?'✅ Success.':'❌ Failed');
        return ['state'=>$ok?'completed':'failed','job'=>['status'=>$ok?'completed':'failed']];
    }
    public static function describe($job): string { return '❌ Failed'; }
    public static function keyboard($job): array { return ['inline_keyboard'=>[]]; }
    public static function fallbackMessage($job): string { return 'Loading...'; }
}
class NotificationOutbox {
    public static array $detached=[];
    public static function detachLoadingMessage($chat,$message): bool { self::$detached[]=[(string)$chat,$message]; return true; }
}
$events=[];
function tg_send_message(...$args): array { global $events; $events[]=['send',$args]; return ['ok'=>true,'result'=>['message_id'=>100]]; }
function tg_edit_message(...$args): array { global $events; $events[]=['edit',$args]; return ['ok'=>true]; }
function tg_replace_message(...$args): array { global $events; $events[]=['replace',$args]; return ['ok'=>true]; }
function tg_delete_message(...$args): array { global $events; $events[]=['delete',$args]; return ['ok'=>true]; }
function tg_answer_callback(...$args): array { global $events; $events[]=['alert',$args]; return ['ok'=>true]; }
require __DIR__.'/../helpers/keyboards.php';
require __DIR__.'/../helpers/tracker.php';
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
check(Storage::getState(42)===null && end($events)[0]==='edit' && end($events)[1][2]==='Shared welcome','Home did not clear wizard and edit menu');
callback('queue_home'); check(end($events)[0]==='edit' && end(NotificationOutbox::$detached)===['99',5],'Loading Home did not detach and edit recent message');
callback('queue_back:1'); check(end($events)[0]==='edit' && count(NotificationOutbox::$detached)===2,'Loading Back did not detach and edit recent message');
check(in_array('srch_adm:1',buttons(Keyboards::serverMenu(1)),true),'Server menu omitted administrator search');
callback('srch_adm:1');check(Storage::getState(42)['step']==='search_admin','Administrator search did not start');
StateHandlers::handle(['text'=>'admin','chat'=>['id'=>99],'from'=>['id'=>42]],Storage::getState(42));$adminResultKeys=buttons(end($events)[1][3]);$adminView=array_values(array_filter($adminResultKeys,fn($key)=>str_contains($key,':admin1:')))[0];callback($adminView);$adminActionKeys=buttons(end($events)[1][3]);check(in_array('adm_act:act_adm:1:admin1:1',$adminActionKeys,true)&&in_array('adm_users:1:admin1:1:1',$adminActionKeys,true)&&in_array('adm_create:1:admin1:1',$adminActionKeys,true),'Administrator result did not open its complete action screen');
PanelManager::$users=[['username'=>'owned','is_active'=>true]];callback('adm_users:1:admin1:1:1');$ownedKeys=buttons(end($events)[1][3]);check(in_array('adm_users:1:admin1:1:1:active',$ownedKeys,true)&&in_array('adm_users:1:admin1:1:1:disabled',$ownedKeys,true)&&in_array('adm_users:1:admin1:1:1:on_hold',$ownedKeys,true)&&in_array('adm_users:1:admin1:1:1:limited',$ownedKeys,true)&&in_array('adm_users:1:admin1:1:1:expired',$ownedKeys,true)&&in_array('srch_adm_user:1:admin1:1',$ownedKeys,true),'Administrator users omitted search or status filters');
callback('srch_adm_user:1:admin1:1');check(Storage::getState(42)['step']==='search_admin_user','Administrator-owned user search did not start');StateHandlers::handle(['text'=>'owned','chat'=>['id'=>99],'from'=>['id'=>42]],Storage::getState(42));check(in_array('srch_adm_user:1:admin1:1',buttons(end($events)[1][2]),true),'Administrator-owned user search did not render reusable results');
callback('adm_act:act_adm:1:admin1:1');check(Storage::getState(42)['step']==='bulk_confirm'&&in_array('exec_act:act_adm:1:admin1',buttons(end($events)[1][3]),true),'Administrator activation did not reuse the confirmed bulk action');
check(in_array('adm_act:add_cfg:1:admin1:1',buttons(Keyboards::adminActions(1,'admin1',1,'marzneshin')),true),'Marzneshin administrator omitted config actions');
command('/start admin_1_YWRtaW4x');check(str_starts_with(end($events)[1][1],'Admin admin1')&&in_array('srv:1',buttons(end($events)[1][2]),true),'Administrator deep link did not render a usable card');
callback('srch_adm:1');
PanelManager::$users=array_fill(0,10,['username'=>'test','is_active'=>true]);
$before=count(PanelManager::$calls);
callback('users:1:2:expired');
check(count(PanelManager::$calls)===$before+1 && end(PanelManager::$calls)===['list',2,10,null,'expired'],'User pagination made more than one panel request');
$keys=buttons(end($events)[1][3]);
check(in_array('users:1:1:expired',$keys,true) && in_array('users:1:3:expired',$keys,true),'Pagination lost filter');
$userKeys=array_values(array_filter($keys,fn($key)=>str_starts_with($key,'usr:')));callback($userKeys[0]);check(in_array('users:1:2:expired',buttons(end($events)[1][3]),true),'User card lost its originating list page and filter');
PanelManager::$users=[['username'=>'searched','is_active'=>true]];command('/user 1 searched');$searchKeys=buttons(end($events)[1][2]);$searchUser=array_values(array_filter($searchKeys,fn($key)=>str_starts_with($key,'usr:')))[0];callback($searchUser);check(in_array('srch_usr:1',buttons(end($events)[1][3]),true),'Search result user did not return to the search flow');
PanelManager::$users=[]; callback('users:1:3:expired');
check(end($events)[0]==='edit' && end($events)[1][2]==='No users found.','Empty page did not render a navigable empty state');
Storage::$cache=[];callback('ref:missing');check(end($events)[0]==='alert' && str_contains(end($events)[1][1],'expired'),'Expired callback reference was not explained');
$many=array_map(fn($i)=>['id'=>$i,'remark'=>'S'.$i,'is_active'=>1],range(1,41));$home=Keyboards::home($many,99);check(in_array('srv:41',buttons($home),true),'Server selector did not clamp an obsolete page');
$templates=array_map(fn($i)=>['id'=>$i,'remark'=>'T'.$i,'is_active'=>1,'data_limit'=>1,'date_limit'=>1],range(1,41));check(in_array('tmpl_view:41:3',buttons(Keyboards::templatesMenu($templates,99)),true),'Template selector did not clamp an obsolete page');
$admins=array_map(fn($i)=>'admin'.$i,range(1,41));check(in_array('pick:1:admin41',buttons(Keyboards::adminsSelector(1,$admins,'pick',false,null,99)),true),'Admin selector did not clamp an obsolete page');
check(in_array('adm_view:1:admin41:3',buttons(Keyboards::adminSearchResults(1,$admins,99)),true),'Administrator search did not clamp an obsolete page');
$services=array_map(fn($i)=>['id'=>$i,'name'=>'C'.$i],range(1,41));check(in_array('pick:tgl:1:41',buttons(Keyboards::configSelector(1,$services,[],'pick','done','back',99)),true),'Config selector did not clamp an obsolete page');
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
$templateActions=buttons(Keyboards::templateActions(1,true,3));check(in_array('tmpl_edit_remark:1:3',$templateActions,true)&&in_array('tmpl_edit_data:1:3',$templateActions,true)&&in_array('tmpl_edit_date:1:3',$templateActions,true),'Template edit buttons lost the originating page');
callback('tmpl_edit_date:1:3');callback('tmpl_edit_dt:1:unlimited');check(in_array('tmpl_view:1:3',buttons(end($events)[1][3]),true),'Template date edit did not return to its original page context');
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
callback('act:1:first_user:cfg');
$oldKeys=buttons(end($events)[1][3]);
$oldToggle=array_values(array_filter($oldKeys,fn($key)=>str_starts_with($key,'cfg_pick:')))[0];
callback('act:1:second_user:cfg');
$before=Storage::getState(42);
callback($oldToggle);
check(Storage::getState(42)===$before && end($events)[0]==='alert','Old config button changed another user selection');
callback('cfg_pick:second_user:tgl:1:one%3Atcp');
check(Storage::getState(42)['data']['current_services']===['two'],'Encoded config ID did not toggle correctly');
callback('cfg_pick:second_user:none:1');
$count=count(PanelManager::$calls); callback('cfg_save:1:second_user');
check(count(PanelManager::$calls)===$count && end($events)[0]==='alert','Empty configs reached panel');
callback('cfg_pick:second_user:all:1'); callback('cfg_save:1:second_user');
check(end(PanelManager::$calls)===['configs','second_user',['one:tcp','two']] && end($events)[1][2]==='❌ Failed','Config failure reported success or wrong target');
Storage::setState(42,'create_user_configs',['server_id'=>1,'selected_configs'=>[],'selector_page'=>1]);
callback('usr_cfg:all:1');
check(Storage::getState(42)['data']['selected_configs']===['one:tcp','two'],'Create-user Select All lost wizard state');
callback('usr_cfg:none:1');
check(Storage::getState(42)['data']['selected_configs']===[],'Create-user DeSelect All lost wizard state');
Storage::setState(42,'user_mod_owner',['server_id'=>1,'username'=>'second_user']);
$count=count(PanelManager::$calls); callback('set_own:first_user:1:new_admin');
check(count(PanelManager::$calls)===$count && end($events)[0]==='alert','Old owner button mutated another user');
callback('set_own:second_user:1:new_admin');
check(end(PanelManager::$calls)===['owner','second_user','new_admin'] && end($events)[1][2]==='❌ Failed','Ownership failure reported success');
Storage::cacheSet('stats_result_1',['server_id'=>1,'text'=>'cached stats','chunks'=>['one,two'],'generated_at'=>time(),'fresh_until'=>time()+60],3600);
callback('stats:1');
check($events[count($events)-2][0]==='edit' && $events[count($events)-2][1][2]==='cached stats' && end($events)[0]==='send' && end($events)[1][1]==='one,two','Stats button did not render the latest cache immediately');
Storage::$cache['stats_result_1']['fresh_until']=time()-1;
callback('stats:1');
check(end(BatchQueue::$queued)==='stats','Stats button displayed an expired cache instead of starting a scan');
$queued=count(BatchQueue::$queued); callback('stats_cached:1');
check(count(BatchQueue::$queued)===$queued && $events[count($events)-2][1][2]==='cached stats','Back navigation did not restore the latest completed statistics');
$many=array_map(fn($i)=>['id'=>$i,'remark'=>'item'.$i,'is_active'=>true],range(1,45));
check(in_array('home_page:2',buttons(Keyboards::home($many)),true),'Server selector has no next page');
$homeRows=Keyboards::home($many)['inline_keyboard'];
check(array_map(fn($button)=>$button['callback_data'],$homeRows[10])===['tmpls','add_srv'],'Home actions must share one row without Check Update');
check(in_array('home_page:1',buttons(Keyboards::home($many,2)),true),'Server selector has no previous page');
$templates=array_map(fn($i)=>['id'=>$i,'remark'=>'template'.$i],range(1,45));
check(in_array('tmpls:2',buttons(Keyboards::templatesMenu($templates)),true),'Template selector has no next page');
$templateCard=Keyboards::templateActions(1);
check(in_array('tmpls:1',buttons($templateCard),true),'Template card has no Back to template list');
$pageTwoCard=Keyboards::templatesMenu($templates,2);
check(in_array('tmpl_view:21:2',buttons($pageTwoCard),true),'Template list did not retain its page in card callback');
Storage::$templates=array_column($templates,null,'id');
callback('tmpl_view:21:2');
check(in_array('tmpls:2',buttons(end($events)[1][3]),true),'Template card Back lost the source page');
callback('tmpl_tgl_ask:21:2');
check(in_array('tmpl_view:21:2',buttons(end($events)[1][3]),true),'Template confirmation No lost the source page');
callback('tmpl_view:21:2');
check(Storage::getState(42)===null,'Returning from template confirmation left a stale pending action');
callback('tmpl_tgl_ask:21:2');callback('tmpl_tgl_act:21');
check(in_array('tmpls:2',buttons(end($events)[1][3]),true) && Storage::$templates[21]['is_active']===0,'Template toggle did not return to source page');
callback('tmpl_tgl_act:21');
check(Storage::$templates[21]['is_active']===0 && end($events)[0]==='alert','Repeated template confirmation toggled twice');
$confirmation=Keyboards::confirm('tmpl_del:1','tmpl_view:1');
check(count(array_filter(buttons($confirmation),fn($data)=>$data==='tmpl_view:1'))===1 && !in_array('tmpls',buttons($confirmation),true),'Confirmation shows duplicate No and Back navigation');
$selectorTemplates=array_map(fn($i)=>['id'=>$i,'remark'=>'template'.$i,'data_limit'=>1,'date_limit'=>30],range(1,45));
check(count(array_filter(buttons(Keyboards::templateSelector(1,$selectorTemplates)),fn($v)=>str_starts_with($v,'use_tmpl:')))===20,'Create-user template selector is not bounded');
check(in_array('template_page:use_tmpl:1:2',buttons(Keyboards::templateSelector(1,$selectorTemplates)),true),'Create-user template selector has no next page');
Storage::$templates=array_column($selectorTemplates,null,'id');
Storage::setState(42,'create_user_template',['server_id'=>1,'username'=>'new_user']);callback('template_page:use_tmpl:1:2');
check(in_array('template_page:use_tmpl:1:1',buttons(end($events)[1][3]),true),'Create-user template selector did not navigate back');
Storage::setState(42,'user_mod_charge',['server_id'=>1,'username'=>'test_user']);callback('template_page:chg_tmpl:1:2');
check(in_array('usr:1:test_user',buttons(end($events)[1][3]),true),'Recharge template selector lost user Back');
$admins=array_map(fn($i)=>'admin'.$i,range(1,45));
check(count(array_filter(buttons(Keyboards::adminsSelector(1,$admins,'xfer_from')),fn($v)=>str_starts_with($v,'xfer_from:')))===20,'Admin selector is not bounded');
$configs=array_map(fn($i)=>['id'=>$i,'name'=>'config'.$i],range(1,45));
check(in_array('configs_page:1:2:usr_cfg:usr_cfg_done%3A1:srv%3A1',buttons(Keyboards::configSelector(1,$configs,[],'usr_cfg','usr_cfg_done:1','srv:1')),true),'Config selector has no next page');
foreach ([Keyboards::usersList(1,[],1),Keyboards::configSelector(1,$configs,[],'usr_cfg','usr_cfg_done:1','srv:1'),Keyboards::cancel('srv:1'),Keyboards::navigationLast(['inline_keyboard'=>[[['text'=>'Home','callback_data'=>'queue_home']],[['text'=>'Back','callback_data'=>'queue_back:1']]]])] as $keyboard) {
    $last=end($keyboard['inline_keyboard']);
    check(count($last)===2 && str_contains($last[0]['text'],'Back') && str_contains($last[1]['text'],'Home'),'Back and Home must share the last row');
}
$homeOnly=Keyboards::cancel();$backOnly=Keyboards::stats(1);
check(count(end($homeOnly['inline_keyboard']))===1,'Home-only keyboard needs one final navigation button');
check(count(end($backOnly['inline_keyboard']))===1,'Back-only keyboard needs one final navigation button');
callback('new_usr:1');callback('new_usr_adm:1:new_admin');$createNav=end($events)[1][3]['inline_keyboard'];$createLast=end($createNav);check(count($createLast)===2&&$createLast[0]['callback_data']==='srv:1'&&$createLast[1]['callback_data']==='home','Create-user prompt did not place Back and Home together in the final row');
echo "PASS: command, deep-link, Home, search, pagination and stale wizard workflows; external boundaries stubbed\n";
