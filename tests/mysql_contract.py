#!/usr/bin/env python3
"""Optional isolated MariaDB upgrade test; requires server tools and pdo_mysql."""
from pathlib import Path
import socket
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='holderbot-mysql-') as tmp:
    data = tmp + '/db'
    subprocess.run(['mariadb-install-db', '--no-defaults', '--datadir='+data], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    server = subprocess.Popen(['mariadbd', '--no-defaults', '--datadir='+data, '--socket='+tmp+'/db.sock', '--pid-file='+tmp+'/db.pid', '--bind-address=127.0.0.1', '--port='+str(port), '--skip-grant-tables'], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.2): break
            except OSError: time.sleep(.1)
        else: raise RuntimeError('Temporary database did not start')
        php = r'''
$config=['storage_type'=>'mysql','storage_path'=>$argv[2].'/fallback.json','mysql'=>['host'=>'127.0.0.1','port'=>(int)$argv[3],'database'=>$argv[4],'username'=>'root','password'=>'']];
require $argv[1].'/storage.php';
Storage::init();
Storage::setChatContext(99);
Storage::setState(42, 'new_state', ['server_id'=>1]);
if ((Storage::getState(42)['step'] ?? '') !== 'new_state') throw new RuntimeException('State write failed');
Storage::setChatContext(42);
if ($argv[4]!=='fresh' && (Storage::getState(42)['step'] ?? '') !== 'legacy') throw new RuntimeException('Legacy state lost');
if (file_exists($argv[2].'/fallback.json')) throw new RuntimeException('Unexpected JSON fallback');
'''
        sql = 'CREATE DATABASE fresh; CREATE DATABASE legacy; CREATE DATABASE partial;'
        for name in ['legacy', 'partial']:
            sql += f"CREATE TABLE {name}.bot_states (user_id BIGINT PRIMARY KEY, step VARCHAR(64) NOT NULL, data JSON NULL, updated_at INT NOT NULL); INSERT INTO {name}.bot_states VALUES (42,'legacy','{{}}',0);"
        sql += 'ALTER TABLE partial.bot_states ADD COLUMN chat_id BIGINT NOT NULL DEFAULT 0;'
        subprocess.run(['mariadb','--no-defaults','--socket='+tmp+'/db.sock','-u','root','-e',sql],check=True)
        for name in ['fresh','legacy','partial']:
            workers = [subprocess.Popen(['php','-d','extension=pdo_mysql','-r',php,str(root),tmp,str(port),name], stdout=subprocess.PIPE, stderr=subprocess.PIPE) for _ in range(4)]
            for worker in workers:
                out, err = worker.communicate(timeout=45)
                assert worker.returncode == 0, err.decode()
        print('PASS: fresh, legacy, interrupted MySQL upgrades with 4 concurrent workers each')
    finally:
        server.terminate()
        server.wait(timeout=15)
