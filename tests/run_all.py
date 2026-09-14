#!/usr/bin/env python3
"""Run syntax and reference checks without creating local application state."""
from pathlib import Path
import subprocess
import sys
root=Path(__file__).resolve().parents[1]
for path in sorted(root.rglob('*.php')):
    subprocess.run(['php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
print('PASS: PHP syntax',flush=True)
subprocess.run(['php',str(root/'tests/workflows.php')],check=True)
subprocess.run(['php',str(root/'tests/reconciliation.php')],check=True)
subprocess.run(['php',str(root/'tests/panel_failures.php')],check=True)
args=[sys.executable,str(root/'tests/compare_python.py')]
if len(sys.argv)>1: args.append(sys.argv[1])
subprocess.run(args,check=True)
contract=[sys.executable,str(root/'tests/python_contract.py')]
if len(sys.argv)>1: contract.append(sys.argv[1])
subprocess.run(contract,check=True)
for test in ['qr_contract.py', 'http_contract.py']:
    subprocess.run([sys.executable,str(root/'tests'/test)],check=True,timeout=120)
