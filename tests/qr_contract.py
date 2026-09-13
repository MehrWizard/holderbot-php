#!/usr/bin/env python3
"""Validate every supported QR version against python-qrcode's byte-mode matrix."""
from pathlib import Path
import json
import subprocess
import qrcode
import qrcode.base
from qrcode.util import QRData, MODE_8BIT_BYTE
root=Path(__file__).resolve().parents[1]
lengths=[]
for version in range(1,41):
    blocks=qrcode.base.rs_blocks(version,qrcode.constants.ERROR_CORRECT_M)
    capacity=sum(b.data_count for b in blocks)
    lengths.append(capacity-(2 if version<10 else 3))
values=['x'*n for n in lengths]
php=r'''require $argv[1].'/helpers/qrcode.php'; $out=[];foreach(json_decode(stream_get_contents(STDIN),true) as $s) $out[]=QrEncoder::encode($s,'M'); echo json_encode($out);'''
proc=subprocess.run(['php','-r',php,str(root)],input=json.dumps(values),text=True,capture_output=True,check=True)
for version,(value,matrix) in enumerate(zip(values,json.loads(proc.stdout)),1):
    assert matrix is not None and len(matrix)==17+4*version, f'Capacity/version {version}'
    matches=[]
    for mask in range(8):
        qr=qrcode.QRCode(version=version,error_correction=qrcode.constants.ERROR_CORRECT_M,border=0,mask_pattern=mask)
        qr.add_data(QRData(value,mode=MODE_8BIT_BYTE));qr.make(fit=False)
        if matrix==qr.modules: matches.append(mask)
    assert matches, f'Invalid QR matrix at version {version}'
print('PASS: all 40 QR versions match a reference byte-mode matrix')
