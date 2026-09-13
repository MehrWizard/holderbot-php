#!/usr/bin/env python3
"""Concurrent JSON writes must not lose server, template, state, or cache records."""
from pathlib import Path
import json
import subprocess
import tempfile
root=Path(__file__).resolve().parents[1]
php=r'''
$config=['storage_path'=>$argv[2]]; require $argv[1].'/storage.php'; Storage::init();
for($i=0;$i<20;$i++) {
 $name=$argv[3].'_'.$i;
 Storage::saveTemplate(['remark'=>$name,'data_limit'=>1,'date_limit'=>1]);
 Storage::cacheSet($name,$i);
 Storage::setState((int)$argv[3]*100+$i,'test',['name'=>$name]);
}
'''
with tempfile.TemporaryDirectory() as tmp:
    path=tmp+'/storage.json'
    Path(path).write_text(json.dumps(dict(servers=[],templates=[],states={},cache={})))
    processes=[subprocess.Popen(['php','-r',php,str(root),path,str(i)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for i in range(6)]
    for p in processes:
        out,err=p.communicate(timeout=30)
        assert p.returncode==0,err
    data=json.loads(Path(path).read_text())
    assert len(data['templates'])==120 and len({t['id'] for t in data['templates']})==120
    assert len(data['states'])==120 and len(data['cache'])==120
print('PASS: 6 processes, 360 concurrent mutations, no lost records')
