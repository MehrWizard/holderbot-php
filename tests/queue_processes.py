#!/usr/bin/env python3
"""Actual process death, resume, and concurrent submission/worker exclusion."""
from pathlib import Path
import json
import subprocess
import tempfile
import time

root=Path(__file__).resolve().parents[1]
php=r'''
$config=['storage_path'=>$argv[2].'/storage.json','queue_path'=>$argv[2].'/queue','admin_ids'=>[42]];
require $argv[1].'/storage.php'; require $argv[1].'/panels/panel_manager.php'; require $argv[1].'/helpers/queue.php';
Storage::init();
function tg_send_message($chat,$text,$kb=null) { return ['ok'=>true]; }
$server=['id'=>1,'type'=>'marzban','remark'=>'test','base_url'=>'http://fake.invalid','username'=>'sudo','password'=>'test'];
if ($argv[3]==='init') { Storage::saveServer($server); exit; }
if ($argv[3]==='enqueue') { echo BatchQueue::enqueue('admin_status',$server,['admin'=>'alice','active'=>true],42,42,'same-message')['id']; exit; }
MarzbanClient::$transport=function($s,$m,$e,$p) use ($argv) {
 file_put_contents($argv[2].'/calls', $e."\n", FILE_APPEND | LOCK_EX);
 if ($argv[3]==='slow') { file_put_contents($argv[2].'/entered','1'); sleep(30); }
 return ['success'=>true];
};
echo BatchQueue::run(2,1);
'''
def cmd(tmp,mode): return ['php','-r',php,str(root),tmp,mode]
with tempfile.TemporaryDirectory(prefix='holderbot-queue-process-') as tmp:
    subprocess.run(cmd(tmp,'init'),check=True)
    workers=[subprocess.Popen(cmd(tmp,'enqueue'),stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(6)]
    ids=[]
    for worker in workers:
        out,err=worker.communicate(timeout=10)
        assert worker.returncode==0,err
        ids.append(out)
    assert len(set(ids))==1,'Concurrent submissions created duplicate jobs'
    assert len(list((Path(tmp)/'queue').glob('*/job.json')))==1
    worker=subprocess.Popen(cmd(tmp,'slow'),stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        for _ in range(100):
            if (Path(tmp)/'entered').exists(): break
            if worker.poll() is not None: raise AssertionError(worker.communicate())
            time.sleep(.02)
        else: raise AssertionError('Mutation did not start')
        second=subprocess.run(cmd(tmp,'normal'),capture_output=True,text=True,timeout=5)
        assert second.returncode==0 and second.stdout=='0', second.stderr
    finally:
        worker.kill(); worker.communicate(timeout=5)
    for _ in range(3): subprocess.run(cmd(tmp,'normal'),check=True,stdout=subprocess.DEVNULL)
    state=json.loads((Path(tmp)/'queue'/ids[0]/'job.json').read_text())
    assert state['status']=='completed' and state['unconfirmed']==1,state
    assert len((Path(tmp)/'calls').read_text().splitlines())==1,'Killed mutation was repeated'
    print('PASS: six concurrent submissions, worker exclusion, SIGKILL recovery without mutation replay')
