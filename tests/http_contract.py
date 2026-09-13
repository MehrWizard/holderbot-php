#!/usr/bin/env python3
"""Exercise native cURL clients against a local fake panel; no external traffic."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import subprocess
import tempfile
import threading
import time
from urllib.parse import urlparse, parse_qs

root = Path(__file__).resolve().parents[1]
calls = []
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def handle_request(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        payload = json.loads(body) if body and 'json' in self.headers.get('Content-Type','') else parse_qs(body.decode())
        path = urlparse(self.path).path
        calls.append(dict(method=self.command,path=path,query=parse_qs(urlparse(self.path).query),payload=payload,auth=self.headers.get('Authorization')))
        result = {}
        status = 200
        if path.endswith('/token'):
            if payload.get('password') == ['bad']: status=401; result={'detail':'invalid credentials'}
            else: result={'access_token':'token-'+payload['password'][0],'is_sudo':True}
        elif path=='/api/admin': result={'is_sudo':True,'username':'sudo'}
        elif path=='/api/inbounds': result={'vless':[{'tag':'test','protocol':'vless'}]}
        elif path in ['/api/users','/api/user'] and self.command=='POST':
            result=dict(payload,subscription_url='/sub/test',is_active=True,activated=True,status=payload.get('status','active'))
        elif path.startswith('/api/user') and self.command=='PUT':
            result=dict(payload,username=payload.get('username','client'),subscription_url='/sub/test',status=payload.get('status','active'))
        elif path=='/api/users': result={'users':[],'items':[]}
        if path in ['/api/nodes', '/api/admins', '/empty-array']: result=[]
        if path=='/slow': time.sleep(1)
        if path=='/empty-body': status=204
        encoded = b'' if status==204 else (b'not json' if path=='/invalid-json' else json.dumps(result).encode())
        self.send_response(status); self.send_header('Content-Type','application/json'); self.end_headers()
        try: self.wfile.write(encoded)
        except BrokenPipeError: pass
    do_GET = do_POST = do_PUT = do_DELETE = handle_request
server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
php=r'''
$config=['storage_path'=>$argv[2]];
require $argv[1].'/storage.php'; require $argv[1].'/panels/panel_manager.php'; Storage::init();
$s=['id'=>1,'type'=>'marzban','remark'=>'test','base_url'=>$argv[3],'username'=>'sudo','password'=>'good'];
foreach(['marzban','marzneshin'] as $kind) {
 $s['type']=$kind;
 foreach(['fixed','onhold','unlimited'] as $date) {
  $u=PanelManager::createUser($s,'client',3,2,null,$kind==='marzban'?['test']:[1],$date);
  if (!$u) throw new RuntimeException('Create failed');
  if (!PanelManager::updateDateLimit($s,'client',2,$date)) throw new RuntimeException('Date edit failed');
 }
 PanelManager::getUsers($s,2,10,'client','active','alice');
 PanelManager::setOwner($s,'client','alice');
 $s['password']='bad';
 $token=$kind==='marzban'?MarzbanClient::getToken($s):MarzneshinClient::getToken($s);
 if ($token!==null) throw new RuntimeException('Changed credentials reused old token');
 $s['password']='good';
 $client=$kind==='marzban'?MarzbanClient::class:MarzneshinClient::class;
 if ($client::request($s,'GET','/empty-array') !== []) throw new RuntimeException('Empty list corrupted');
 foreach (['/empty-object','/empty-body'] as $endpoint) {
  if ($client::request($s,'POST',$endpoint) !== ['success'=>true]) throw new RuntimeException('Empty success response rejected');
 }
 if ($client::request($s,'GET','/invalid-json') !== null) throw new RuntimeException('Malformed JSON accepted');
 $start=microtime(true); RequestBudget::$deadline=$start+0.2;
 $slow=$client::request($s,'GET','/slow',null,false);
 RequestBudget::$deadline=null;
 if ($slow!==null || microtime(true)-$start>0.8) throw new RuntimeException('Native request exceeded worker budget');
}
$s['type']='marzban';
if (PanelManager::getNodes($s)!==[] || PanelManager::getAdmins($s)!==[]) throw new RuntimeException('Empty panel lists corrupted');
'''
try:
    with tempfile.TemporaryDirectory() as tmp:
        proc=subprocess.run(['php','-d','error_reporting=24575','-r',php,str(root),tmp+'/storage.json',f'http://127.0.0.1:{server.server_port}'],capture_output=True,text=True)
        assert proc.returncode==0,proc.stderr
    creates=[c for c in calls if c['method']=='POST' and c['path'] in ['/api/user','/api/users']]
    assert len(creates)==6
    for call in creates:
        p=call['payload']
        assert p['data_limit']==3*1024**3
        assert call['auth']=='Bearer token-good'
        assert all(v is not None for v in p.values()), 'Nulls should be omitted like upstream _clean_payload'
        if call['path']=='/api/users':
            assert p['service_ids']==[1]
            if p['expire_strategy']=='start_on_first_use': assert p['usage_duration']==2*86400
        else:
            assert p['proxies']=={'vless':{}}
            assert p['inbounds']=={'vless':['test']}
    edits=[c for c in calls if c['method']=='PUT' and not c['path'].endswith('set-owner')]
    assert len(edits)==6
    for c in edits:
        if c['path'].startswith('/api/users/'):
            assert c['payload']['username']=='client'
            assert 'is_active' not in c['payload']
        else: assert c['payload']['status'] in ['active','on_hold']
    queries=[c for c in calls if c['path']=='/api/users' and c['method']=='GET']
    assert queries[0]['query']=={'offset':['10'],'limit':['10'],'sort':['-created_at'],'search':['client'],'status':['active'],'admin':['alice']}
    assert queries[1]['query']=={'page':['2'],'size':['10'],'order_by':['created_at'],'descending':['true'],'username':['client'],'owner_username':['alice'],'is_active':['true']}
    assert len([c for c in calls if c['path'].endswith('/token')])==4, 'Token cache must isolate changed credentials'
    print(f'PASS: {len(calls)} local HTTP requests, both panel clients')
finally:
    server.shutdown(); server.server_close()
