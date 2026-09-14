#!/usr/bin/env python3
"""Execute original Python presentation code against PHP, without app dependencies.
Usage: python3 tests/compare_python.py [path-to-original-holderbot]
Only framework plumbing is stubbed; expected values come from original methods.
"""
import ast
import datetime
import enum
import json
from pathlib import Path
import subprocess
import sys
import types

PHP = Path(__file__).resolve().parents[1]
SOURCE = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else PHP.parent

def execute(path, namespace):
    tree = ast.parse((SOURCE / path).read_text())
    tree.body = [node for node in tree.body if not isinstance(node, (ast.Import, ast.ImportFrom))]
    exec(compile(tree, str(SOURCE / path), 'exec'), namespace)
    return namespace

class Model:
    def __init__(self, **values):
        for cls in reversed(type(self).__mro__):
            for name in getattr(cls, '__annotations__', {}):
                setattr(self, name, getattr(cls, name, None))
        self.__dict__.update(values)
    def dict(self):
        return self.__dict__

ns = dict(datetime=datetime.datetime, timezone=datetime.timezone, Enum=enum.Enum,
          BaseModel=Model, validator=lambda *a, **kw: lambda f: f,
          Optional=__import__('typing').Optional, Dict=dict, List=list, MarzbanAdmin=Model)
execute('app/api/helpers.py', ns)
fixtures = []
for kind in ['marzban', 'marzneshin']:
    local = ns.copy()
    execute(f'app/api/types/{kind}/user.py', local)
    raw = dict(username='client_123', data_limit=1024**3, used_traffic=1024,
               lifetime_used_traffic=2*1024**3, note='test', subscription_url='https://panel.invalid/sub/a',
               sub_updated_at=None, online_at=None, created_at=None, sub_last_user_agent=None,
               data_limit_reset_strategy=local['MarzbanUserDataUsageResetStrategy' if kind=='marzban' else 'UserDataUsageResetStrategy'].no_reset)
    if kind == 'marzban':
        raw.update(status=local['MarzbanUserStatus'].ONHOLD, expire=None, admin=Model(username='alice'), on_hold_expire_duration=86400)
        model = local['MarzbanUserResponse'](**raw)
    else:
        raw.update(expire_strategy=local['UserExpireStrategy'].START_ON_FIRST_USE, usage_duration=86400,
                   expire_date=None, activation_deadline=None, activated=True, enabled=True, is_active=False,
                   expired=True, data_limit_reached=True, service_ids=[1,2], owner_username='alice',
                   sub_revoked_at=None, traffic_reset_at=None)
        model = local['MarzneshinUserResponse'](**raw)
    expected = model.format_data_str()
    if kind == 'marzban': raw['admin'] = {'username':'alice'}
    fixtures.append(dict(kind='card', server=dict(type=kind, base_url='https://panel.invalid',remark='panel'),raw=raw, expected=expected))
    for limit in [0, 1024**3]:
        raw['data_limit'] = limit
        model.data_limit = limit
        caption = ('• <b>Username:</b> <code>{username}</code>\n• <b>Data Limit:</b> <code>{data_limit}</code>\n'
                   '• <b>Date Limit:</b> <code>{expire_strategy}</code>\n• <b>Sub Url:</b> <code>{subscription_url}</code>\n').format(**model.format_data)
        fixtures.append(dict(kind='caption',server=dict(type=kind,base_url='https://panel.invalid'),raw=raw.copy(),expected=caption))
for value in [0, 1, 1023, 1024, 1024**2, 1024**3, 1024**4, 1024**5]:
    fixtures.append(dict(kind='bytes',value=value,expected=ns['format_bytes'](value)))
now = datetime.datetime.fromtimestamp(2000000000, datetime.timezone.utc)
for diff in [None,0,1,-1,59,-60,3600,-3600,86400,-86401,172800]:
    date = None if diff is None else now+datetime.timedelta(seconds=diff)
    fixtures.append(dict(kind='date',value=None if date is None else int(date.timestamp()),now=int(now.timestamp()),expected=ns['format_date_diff'](now,date)))

class Button:
    def __init__(self, **kw): self.__dict__.update(kw)
class Builder:
    def __init__(self): self.buttons=[]; self.rows=[]
    def button(self, **kw): self.buttons.append(Button(**kw))
    def adjust(self, width):
        self.rows += [self.buttons[i:i+width] for i in range(0,len(self.buttons),width)]
        self.buttons=[]
    def row(self,*buttons,width=8):
        if self.buttons: self.adjust(1)
        self.rows += [list(buttons[i:i+width]) for i in range(0,len(buttons),width)]
    def as_markup(self):
        if self.buttons: self.adjust(1)
        return [[b.text for b in row] for row in self.rows]
class Callback:
    def __init__(self, **kw): pass
    def pack(self): return 'callback'
kns=dict(BaseSettings=object,SettingsConfigDict=lambda **kw: {},Enum=enum.Enum)
execute('app/settings/language/_keyboard.py',kns)
execute('app/keys/_enums.py',kns)
kns.update(InlineKeyboardBuilder=Builder,InlineKeyboardButton=Button,InlineKeyboardMarkup=list,
           KeyboardTexts=kns['_KeyboardSettings'](),Server=Model,PageCB=Callback,SelectCB=Callback)
execute('app/keys/manager.py',kns)
keys=kns['_KeyboardsManager']()
fixtures += [
    dict(kind='keyboard',method='home',args=[[dict(id=1,remark='panel',is_active=True)]],expected=keys.home([Model(id=1,remark='panel',emoji='✅ ')])),
    dict(kind='keyboard',method='serverMenu',args=[1],expected=keys.menu(1)),
    dict(kind='keyboard',method='cancel',args=[],expected=keys.cancel()),
    dict(kind='keyboard',method='cancel',args=['srv:1'],expected=keys.cancel(server_back=1)),
    dict(kind='keyboard',method='dateTypeSelector',args=[1,'client'],expected=keys.selector(['unlimited','now','after first use'],'users',width=1,server_back=1,user_back='client')),
]
for path, name, method, args, fields in [
    ('app/models/user.py','UserModify','userActions',[1,'client',True,'active'],['DATA_LIMIT','DATE_LIMIT','DISABLED','RESET_USAGE','REVOKE','QRCODE','NOTE','OWNER','CONFIGS','CHARGE','REMOVE']),
    ('app/models/template.py','TemplateModify','templateActions',[1,True],['DATA_LIMIT','DATE_LIMIT','DISABLED','REMARK','REMOVE']),
    ('app/models/server.py','ServerModify','serverSettings',[dict(id=1)],['REMARK','DATA','NODE_MONITORING','NODE_AUTORESTART','EXPIRED_STATS','REMOVE'])]:
    mod=ns.copy();execute(path,mod)
    data=[getattr(mod[name],f) for f in fields]
    expected=keys.selector(data,'users',server_back=1 if name != 'TemplateModify' else None)
    fixtures.append(dict(kind='keyboard',method=method,args=args,expected=expected))
action_ns=ns.copy();execute('app/models/action.py',action_ns)
Action=action_ns['ActionTypes']
for kind in ['marzban','marzneshin']:
    actions=[Action.ACTIVATED_USERS,Action.DISABLED_USERS,Action.DELETE_EXPIRED_USERS,Action.DELETE_LIMITED_USERS,Action.DELETE_USERS,Action.TRANSFER_USERS]
    if kind=='marzneshin': actions += [Action.ADD_CONFIG,Action.DELETE_CONFIG]
    fixtures.append(dict(kind='keyboard',method='actionsMenu',args=[1,kind],expected=keys.selector(actions,'actions',server_back=1)))
fixtures.append(dict(kind='keyboard',method='configSelector',args=[1,[dict(id='a',name='a'),dict(id='b',name='b')],['a'],'pick','done','srv:1'],expected=keys.selector(['a','b'],'users',selects=['a'],all_selects=True,server_back=1)))
php = r'''
require $argv[1].'/helpers/format.php'; require $argv[1].'/helpers/keyboards.php'; require $argv[1].'/panels/panel_manager.php';
$fixtures=json_decode(stream_get_contents(STDIN),true); $out=[];
foreach($fixtures as $f) {
 switch($f['kind']) {
 case 'card': $out[]=Formatter::userCard($f['server'],PanelManager::normalizeUser($f['server'],$f['raw'])); break;
 case 'caption': $out[]=Formatter::userInfo($f['server'],PanelManager::normalizeUser($f['server'],$f['raw'])); break;
 case 'bytes': $out[]=Formatter::bytes($f['value']); break;
 case 'date': $out[]=Formatter::timeDiff($f['value'],$f['now']); break;
 case 'keyboard': $k=Keyboards::{$f['method']}(...$f['args']); $out[]=array_map(fn($row)=>array_column($row,'text'),$k['inline_keyboard']); break;
 }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
result = subprocess.run(['php','-r',php,str(PHP)],input=json.dumps(fixtures),text=True,capture_output=True,check=True)
actual = json.loads(result.stdout)
def navigation_last(rows):
    content=[]; back=None; home=None
    for row in rows:
        remaining=[]
        for label in row:
            if label == '🏛️ Home': home=label
            elif label == '◀️ Back': back=label
            else: remaining.append(label)
        if remaining: content.append(remaining)
    navigation=[label for label in (back,home) if label]
    if navigation: content.append(navigation)
    return content
for index,(fixture,value) in enumerate(zip(fixtures,actual)):
    expected=navigation_last(fixture['expected']) if fixture['kind']=='keyboard' else fixture['expected']
    assert value == expected, f"Fixture {index} {fixture['kind']}:\nExpected: {expected!r}\nActual: {value!r}"
print(f'PASS: {len(fixtures)} differential fixtures evaluated against original Python code')
