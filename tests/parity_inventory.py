#!/usr/bin/env python3
"""Inventory every upstream router handler; distinguish inventory from behavioral proof."""
import ast
from pathlib import Path
import sys
source=Path(sys.argv[1]) if len(sys.argv)>1 else Path(__file__).resolve().parents[2]
rows=[]
for file in sorted((source/'app/routers').rglob('*.py')):
    tree=ast.parse(file.read_text())
    for node in tree.body:
        if isinstance(node,(ast.FunctionDef,ast.AsyncFunctionDef)) and any('router.' in ast.unparse(d) for d in node.decorator_list):
            rows.append((str(file.relative_to(source)),node.name,[ast.unparse(d) for d in node.decorator_list]))
print(f'Upstream router handlers inventoried: {len(rows)}')
for path,name,decorators in rows:
    print(f'{path}:{name}\t'+ ' | '.join(decorators))
