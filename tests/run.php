<?php
/** Offline workflow/API regression suite. No production config or network is used. */
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$path = sys_get_temp_dir() . '/holderbot-test-' . bin2hex(random_bytes(8)) . '.json';
$config = ['storage_path' => $path, 'storage_type' => 'json'];
require __DIR__ . '/../storage.php';
require __DIR__ . '/../panels/panel_manager.php';
require __DIR__ . '/../helpers/format.php';
require __DIR__ . '/../helpers/keyboards.php';
require __DIR__ . '/../handlers/commands.php';
require __DIR__ . '/../handlers/states.php';
require __DIR__ . '/../handlers/callbacks.php';
require __DIR__ . '/../handlers/inline.php';
Storage::init();
register_shutdown_function(function() use ($path) { foreach ([$path, $path . '.lock'] as $file) if (is_file($file)) unlink($file); });
$messages = []; $requests = []; $checks = 0;
function check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function tg_send_message($chat, $text, $kb = null): array { global $messages; $messages[] = ['text' => $text, 'kb' => $kb]; return ['ok' => true, 'result' => ['message_id' => count($messages)]]; }
function tg_edit_message($chat, $id, $text, $kb = null): array { return tg_send_message($chat, $text, $kb); }
function tg_answer_callback($id, $text = null, $alert = false): array { return ['ok' => true]; }
function tg_delete_message($chat, $id): array { return ['ok' => true]; }
function tgbot($method, $params = []): array { return ['ok' => true, 'result' => ['username' => 'test_bot']]; }
function tg_answer_inline_query($id, $results, $cache = 10, $switch = null, $parameter = null): void { global $inline; $inline = compact('results','cache','switch','parameter'); }
function tg_download_file($id): string { global $document; return $document; }
class QrGenerator { public static array $photos = []; public static function sendQrPhoto($chat, $url, $caption): void { self::$photos[] = compact('url','caption'); } }
function click(string $data): void { CallbackHandlers::handle(['id' => 'test', 'data' => $data, 'from' => ['id' => 42], 'message' => ['chat' => ['id' => 42], 'message_id' => 1]]); }
function say(string $text): void { StateHandlers::handle(['chat'=>['id'=>42],'from'=>['id'=>42],'text'=>$text], Storage::getState(42)); }
function lastText(): string { global $messages; return end($messages)['text']; }
function labels(array $kb): array { return array_map(fn($row) => array_column($row, 'text'), $kb['inline_keyboard']); }
$server = ['remark'=>'panel','type'=>'marzban','base_url'=>'https://panel.invalid','username'=>'sudo','password'=>'test'];
$sid = Storage::saveServer($server); $server = Storage::getServer($sid);
$neshin = array_merge($server, ['type'=>'marzneshin']);
$raw = ['username'=>'client_long_username_12345678901234567890','status'=>'active','data_limit'=>10*1024**3,'used_traffic'=>1024**3,'subscription_url'=>'/sub/test','inbounds'=>['vless'=>['a']],'admin'=>['username'=>'sudo']];
$db = [$raw['username'] => $raw];
$fail = false;
$transport = function($server, $method, $endpoint, $payload) use (&$db, &$requests, &$fail) {
    $requests[] = compact('method','endpoint','payload');
    if ($fail) return null;
    $url = parse_url($endpoint); $path = $url['path']; parse_str($url['query'] ?? '', $q);
    if (str_ends_with($path, '/token')) return ['access_token'=>'test-token','is_sudo'=>true];
    if ($path === '/api/admin') return ['username'=>'sudo','is_sudo'=>true];
    if ($path === '/api/admins') return $server['type']==='marzneshin' ? ['items'=>[['username'=>'sudo']]] : [['username'=>'sudo']];
    if ($path === '/api/inbounds') return ['vless'=>[['tag'=>'a','protocol'=>'vless'],['tag'=>'b','protocol'=>'vless']]];
    if ($path === '/api/services') return ['items'=>[['id'=>1,'name'=>'one','remark'=>'one'],['id'=>2,'name'=>'two','remark'=>'two']]];
    if ($path === '/api/users' && $method === 'GET') {
        $users = array_values($db);
        if (!empty($q['status'])) $users = array_values(array_filter($users, fn($u)=>$u['status']===$q['status']));
        if (!empty($q['admin'])) $users = array_values(array_filter($users, fn($u)=>($u['admin']['username'] ?? '')===$q['admin']));
        if (!empty($q['search'])) $users = array_values(array_filter($users, fn($u)=>str_contains($u['username'], $q['search'])));
        $size = (int)($q['limit'] ?? $q['size'] ?? 20);
        $offset = (int)($q['offset'] ?? (((int)($q['page'] ?? 1)-1)*$size));
        return [$server['type']==='marzneshin' ? 'items':'users' => array_slice($users,$offset,$size)];
    }
    if (($path === '/api/user' || $path === '/api/users') && $method === 'POST') {
        $u = array_merge(['status'=>'active','is_active'=>true,'activated'=>true,'used_traffic'=>0,'subscription_url'=>'/sub/new'], $payload);
        $db[$u['username']]=$u; return $u;
    }
    if (preg_match('~^/api/users?/([^/]+)(?:/(.+))?$~', $path, $m)) {
        $name=rawurldecode($m[1]); $action=$m[2] ?? '';
        if (!isset($db[$name])) return null;
        if ($action==='set-owner') { $db[$name]['admin']=['username'=>$q['admin_username']]; return []; }
        if ($action==='reset') { $db[$name]['used_traffic']=0; return []; }
        if ($method==='DELETE') { unset($db[$name]); return []; }
        if ($method==='PUT') $db[$name]=array_merge($db[$name], $payload);
        return $db[$name];
    }
    return [];
};
MarzbanClient::$transport=$transport; MarzneshinClient::$transport=$transport;
check(Storage::getTemplates()===[], 'New installs must not invent templates');
check(labels(Keyboards::serverMenu(1)) === [['👤 Users','🗄 Actions'],['📊 Stats','➕ Create User'],['🔍 Search User'],['☁️ Server','🏛️ Home']], 'Server menu differs from Python');
check(labels(Keyboards::home([$server])) === [['✅ panel'],['🗃 Templates','👀 Check Update'],['➕ Add Server']], 'Home layout differs');
check(Formatter::bytes(1024**3)==='1.00 GB', 'Byte formatting');
check(Formatter::timeDiff(100,100)==='now', 'Date now');
check(Formatter::timeDiff(100,86501)==='2 day ago', 'Python negative timedelta day rounding');
$user=PanelManager::normalizeUser($server,$raw);
check($user['owner_username']==='sudo','Owner normalization');
check(str_contains(Formatter::userCard($server,$user), '<b>• Data Reset Strategy:</b>'), 'Missing user fields');
check(str_contains(Formatter::userInfo($server,$user), '• <b>Date Limit:</b> <code>active</code>'), 'Creation caption');
$kb=Keyboards::userActions(1,$raw['username'],true,'active');
check(str_contains($kb['inline_keyboard'][0][0]['callback_data'],$raw['username']), 'Username truncated');
$nr=PanelManager::normalizeUser($neshin,['username'=>'neshin','is_active'=>false,'activated'=>true,'expired'=>true,'data_limit_reached'=>true,'owner_username'=>'alice']);
check(!$nr['is_active'] && $nr['is_enabled'] && $nr['owner_username']==='alice','Neshin flags conflated');
click('new_usr:1'); check(lastText()==='Select admin:', 'Single-admin create must still select admin');
click('new_usr:1'); click('new_usr_adm:1:sudo'); say('abc'); check(lastText()==='❌ Invalid pattern.','Short username validation');
say('abcd'); check(Storage::getState(42)['step']==='create_user_count','Username -> count');
say('1'); check(Storage::getState(42)['step']==='create_user_data','No template -> custom data');
say('2.5'); check(lastText()==='❌ Invalid, Just use [0-9]','Reject fractional input');
say('10'); click('crt_dt_type:1:abcd:unlimited');
check(Storage::getState(42)['step']==='create_user_configs','Unlimited -> config selection');
click('usr_cfg:none:1'); click('usr_cfg_done:1'); check(!isset($db['abcd']), 'Empty selection must not create');
click('usr_cfg:all:1'); click('usr_cfg_done:1'); check(isset($db['abcd']), 'Create user workflow');
check(count(QrGenerator::$photos)===1 && str_starts_with(QrGenerator::$photos[0]['caption'],'• <b>Username:'), 'Created photo caption');
click('act:1:abcd:cfg'); click('cfg_pick:none:1'); click('cfg_save:1:abcd'); check(Storage::getState(42)!==null,'Empty save must keep selection state');
click('cfg_pick:tgl:1:b'); click('cfg_save:1:abcd'); check($db['abcd']['inbounds']===['vless'=>['b']], 'Config selection roundtrip');
$tid=Storage::saveTemplate(['remark'=>'test','data_limit'=>12,'date_limit'=>3,'date_type'=>'onhold']);
click("tmpl_edit_data:{$tid}"); check(Storage::getState(42)['data']['tmpl_id']===$tid,'Template id parser');
say('14'); check(Storage::getTemplate($tid)['data_limit']===14,'Template edit');
click('tgl_srv_mon_ask:1'); click('tgl_srv_mon:1'); check((bool)Storage::getServer(1)['node_monitoring'],'Server toggle id parser');
$fail=true; Storage::setState(42,'user_mod_datalimit',['server_id'=>1,'username'=>'abcd']); say('99'); check(lastText()==='❌ Failed','Failed edit falsely reports success');
$fail=false;
$created=PanelManager::createUser($neshin,'neshin_create',2,3,null,[1],'onhold'); check($created!==null,'Marzneshin creation fatal');
check($created['raw']['usage_duration']===3*86400 && $created['raw']['expire_strategy']==='start_on_first_use','Marzneshin creation payload');
PanelManager::updateDateLimit($server,'abcd',2,'fixed'); check($db['abcd']['status']==='active','Date edit must activate Marzban');
$db['abcd']['on_hold_expire_duration']=86400;
PanelManager::chargeUser($server,'abcd',3,2,false,true,'onhold'); check($db['abcd']['on_hold_expire_duration']===3*86400,'Additive onhold duration');
$fail=true; check(PanelManager::chargeUser($server,'abcd',1,1)===null,'Failed recharge result'); $fail=false;
$db=[];
for($i=0;$i<61;$i++) $db['u'.$i]=array_merge($raw,['username'=>'u'.$i,'status'=>'expired']);
$deleted=PanelManager::deleteExpiredUsers($server); check($deleted===['success'=>61,'total'=>61] && !$db,'Multi-page delete skipped users');
for($i=0;$i<61;$i++) $db['u'.$i]=array_merge($raw,['username'=>'u'.$i]);
$moved=PanelManager::transferUsers($server,'sudo','alice'); check($moved===['success'=>61,'total'=>61], 'Multi-page transfer skipped users');
click('users:1:2:all'); parse_str(parse_url(end($requests)['endpoint'],PHP_URL_QUERY),$q); check((int)$q['offset']===10 && (int)$q['limit']===10,'Pagination gap');
InlineHandlers::handle(['id'=>'inline','query'=>'1.5']); check($inline['parameter']==='invalid_server_id','Inline server id integer validation');
InlineHandlers::handle(['id'=>'inline','query'=>'1 u1']); check($inline['results'][0]['id']==='u1','Inline result id');
check(str_starts_with($inline['results'][0]['input_message_content']['message_text'],'• <b>Username:'),'Inline caption');
click('add_srv'); say('newpanel'); check(lastText()==='Select a type:','Server type prompt');
click('srv_type:marzneshin'); check(Storage::getState(42)['step']==='add_server_credentials','Server type button');
say('bad'); check(lastText()==='❌ Invalid pattern.','Credential pattern');
$fail=true; say("sudo\nwrong\nhttps://new.invalid"); check(Storage::getState(42)['step']==='add_server_credentials','Bad credentials should be retryable');
$fail=false; say("sudo\ncorrect\nhttps://new.invalid"); check(count(Storage::getServers())===2,'Combined server credential workflow');
// State belongs to a (chat, user) pair, as in aiogram's default strategy.
Storage::setChatContext(-1001); Storage::setState(42, 'group_one', ['a'=>1]);
Storage::setChatContext(-1002); check(Storage::getState(42)===null, 'Wizard leaks across chats');
Storage::setState(42, 'group_two'); Storage::setChatContext(-1001);
check(Storage::getState(42)['step']==='group_one', 'Group wizard overwritten');
Storage::setChatContext(null);
// JSON creation rejects empty selections, supports per-user strategies and owner assignment.
click('new_usr:1'); click('new_usr_adm:1:sudo'); click('new_usr_json:1');
$document=json_encode([['username'=>'json_fixed','datalimit'=>5,'datelimit'=>2,'datetypes'=>'now'],['username'=>'json_hold','datalimit'=>0,'datelimit'=>3,'datetypes'=>'after first use']]);
StateHandlers::handle(['chat'=>['id'=>42],'from'=>['id'=>42],'document'=>['file_id'=>'fixture','file_name'=>'users.json']], Storage::getState(42));
click('usr_cfg:none:1'); click('usr_cfg_done:1'); check(!isset($db['json_fixed']), 'JSON creation bypasses required configs');
click('usr_cfg:all:1'); click('usr_cfg_done:1');
check($db['json_fixed']['status']==='active' && $db['json_hold']['status']==='on_hold', 'JSON strategies');
check($db['json_hold']['admin']['username']==='sudo', 'JSON owner');
// Confirmations must not execute their mutations when No is selected.
click('decline:srv:1'); check(lastText()==='❌ Failed', 'Negative confirmation screen');
require __DIR__ . '/../helpers/tasks.php';
$nodeServer = array_merge($server, ['node_monitoring'=>true,'node_restart'=>true]);
$requests=[];
MarzbanClient::$transport=function($s,$m,$e,$p) use ($transport) {
    if ($e==='/api/nodes') return [['id'=>1,'name'=>'bad','address'=>'host','status'=>'error','message'=>'failed'],['id'=>2,'name'=>'off','address'=>'host','status'=>'disabled']];
    return $transport($s,$m,$e,$p);
};
BackgroundTasks::monitorNodes([$nodeServer],[42]);
check(str_starts_with(lastText(),'<b>❌ This Nodes is have a error!</b>'), 'Node alert interface');
check(count(array_filter($requests, fn($r)=>$r['endpoint']==='/api/node/1/reconnect'))===1, 'Failed node not restarted');
check(!array_filter($requests, fn($r)=>$r['endpoint']==='/api/node/2/reconnect'), 'Disabled node restarted');
MarzbanClient::$transport=$transport;
$nodeServer['expired_stats']=true; Storage::cacheSet('online_1',time(),86400);
$db=['soon'=>array_merge($raw,['username'=>'soon','expire'=>time()+2*3600]),'immediate'=>array_merge($raw,['username'=>'immediate','expire'=>time()+100]),'past'=>array_merge($raw,['username'=>'past','expire'=>time()-100])];
BackgroundTasks::expiredReport([$nodeServer],[42]);
check(str_contains(lastText(),'[<code>1</code>/<code>3</code>]') && str_contains(lastText(),'user_1_soon'), 'Expired report hour buckets and links');
$stats=PanelManager::getServerStats($server);
check($stats['total_users']===3 && count($stats['today_expired'])===1, 'Stats expiry buckets');
// All template date modes remain editable, including a zero-day template.
click("tmpl_edit_dt:{$tid}:fixed"); say('0'); check(Storage::getTemplate($tid)['date_limit']===0,'Zero-day template edit');
click("tmpl_edit_dt:{$tid}:unlimited"); check(Storage::getTemplate($tid)['date_type']==='unlimited','Unlimited template edit');
check(Input::decimalDigits('۱۲۳۴۵۶۷۸۹۰')==='1234567890', 'Persian integer input');
check(Input::decimalDigits('١٢٣٤٥٦٧٨٩٠')==='1234567890', 'Arabic integer input');


// Old creation keyboards must never consume another wizard's state.
foreach (['create_user_name', 'create_user_configs', 'search_user'] as $step) {
    Storage::setState(42, $step, ['server_id'=>2, 'username'=>'stale', 'selected_configs'=>['test']]);
    $beforeState = Storage::getState(42);
    $beforeRequests = count($requests);
    foreach (['new_usr_adm:1:sudo','new_usr_json:1','rnd_usr:1','use_tmpl:1:1','use_tmpl_custom:1','usr_cfg:tgl:1:test','usr_cfg:all:1','usr_cfg:none:1','usr_cfg_done:1','crt_dt_type:1:fixed'] as $button) click($button);
    check(Storage::getState(42) === $beforeState && count($requests) === $beforeRequests, 'Stale creation callbacks changed another wizard');
}
Storage::setState(42, 'search_user', ['server_id'=>1, 'username'=>'stale', 'selected_configs'=>['test']]);
$beforeState = Storage::getState(42);
click('usr_cfg_done:1');
check(Storage::getState(42) === $beforeState, 'Wrong-step callback consumed state');
Storage::clearState(42);
$beforeRequests = count($requests);
click('usr_cfg_done:1');
check(count($requests) === $beforeRequests, 'Cancelled creation callback reached panel');

echo "PASS: {$checks} assertions\n";
