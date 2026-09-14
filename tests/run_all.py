#!/usr/bin/env python3
"""Run syntax and reference checks without creating local application state."""
from pathlib import Path
import subprocess
import sys
root=Path(__file__).resolve().parents[1]
for path in sorted(root.rglob('*.php')):
    subprocess.run(['php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
print('PASS: PHP syntax',flush=True)
args=[sys.executable,str(root/'tests/compare_python.py')]
if len(sys.argv)>1: args.append(sys.argv[1])
subprocess.run(args,check=True)
for test in ['qr_contract.py', 'http_contract.py']:
    subprocess.run([sys.executable,str(root/'tests'/test)],check=True,timeout=120)
