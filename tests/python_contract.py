#!/usr/bin/env python3
"""Verify the pinned upstream handler contract directly from the Python source."""
import ast
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 and sys.argv[1] != '--update' else ROOT.parent
UPDATE = '--update' in sys.argv
OUT = ROOT / 'tests' / 'upstream_behavior.json'

def dotted(node):
    try:
        return ast.unparse(node)
    except Exception:
        return ''

contract = {}
for path in sorted((SOURCE / 'app' / 'routers').rglob('*.py')):
    tree = ast.parse(path.read_text())
    for fn in tree.body:
        if not isinstance(fn, (ast.FunctionDef, ast.AsyncFunctionDef)) or not any('router.' in dotted(d) for d in fn.decorator_list):
            continue
        calls, constants = set(), set()
        for node in ast.walk(fn):
            if isinstance(node, ast.Call):
                name = dotted(node.func)
                if any(mark in name for mark in ('ClinetManager.', 'crud.', 'BotKeys.', '.set_state', '.update_data', '.edit_text', '.answer_photo', '.answer')):
                    calls.add(name)
            if isinstance(node, ast.Attribute) and dotted(node).startswith(('MessageTexts.', 'UserModifyForm.', 'UserCreateForm.', 'Template', 'Server')):
                constants.add(dotted(node))
        key = f'{path.relative_to(SOURCE)}:{fn.name}'
        contract[key] = {'decorators': sorted(dotted(d) for d in fn.decorator_list), 'calls': sorted(calls), 'constants': sorted(constants)}

if UPDATE:
    OUT.write_text(json.dumps(contract, indent=2, sort_keys=True) + '\n')
else:
    expected = json.loads(OUT.read_text())
    assert contract == expected, 'Original Python handler behavior changed; regenerate and review upstream_behavior.json'
    assert len(contract) == 75, f'Expected 75 upstream handlers, found {len(contract)}'
    php = '\n'.join(p.read_text() for p in (ROOT / 'handlers').glob('*.php')) + (ROOT / 'index.php').read_text()
    required = {
        'commands': ['case \'/start\'', 'case \'/user\''],
        'navigation': ["$data === 'home'", "str_starts_with($data, 'srv:')"],
        'users': ['create_user_name', 'user_mod_datalimit', 'user_mod_datelimit', 'user_mod_note', "case 'rst_ask'", "case 'rvk_ask'", "case 'qr'"],
        'templates': ['tmpl_add_remark', 'tmpl_edit_remark', 'tmpl_edit_data', 'tmpl_edit_date'],
        'servers': ['add_server_remark', 'srv_edit_remark', 'srv_edit_creds'],
        'bulk': ["queueBatch('delete'", "queueBatch('transfer'", "queueBatch('config'", "queueBatch('admin_status'"],
        'stats': ["queueBatch('stats'"],
        'inline': ['InlineHandlers::handle'],
    }
    missing = [f'{area}: {token}' for area, tokens in required.items() for token in tokens if token not in php]
    assert not missing, 'Missing PHP parity surfaces:\n' + '\n'.join(missing)
    print(f'PASS: {len(contract)} upstream Python handlers pinned; all PHP workflow surfaces present')
