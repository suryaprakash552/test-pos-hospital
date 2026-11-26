#!/usr/bin/env python3
"""Compare the Postman collection against Laravel's own validation rules.

The collection was originally generated from `php artisan route:list`, which gets
every path and method right and every request body empty. That made it useless as
documentation: each POST/PUT/PATCH would come back 422. This tool closes that gap
and keeps it closed.

Laravel is the source of truth. A field appears in the collection only because it
appears in a FormRequest `rules()` array or an inline `->validate([...])` call.

    python3 postman/tools/contract_audit.py            # audit, exit 1 on drift
    python3 postman/tools/contract_audit.py --fix      # rewrite bodies from rules
    python3 postman/tools/contract_audit.py --matrix   # print the field matrix

Run it after changing any FormRequest, and in CI to stop the collection drifting.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
COLLECTION = os.path.join(ROOT, 'postman', 'POS-API.postman_collection.json')

# Routes carrying the `idempotent` middleware in routes/api.php. Sending a repeat
# with the same key replays the first response; see EnsureIdempotentRequest.
IDEMPOTENT = {
    ('POST', '/api/v1/purchase'), ('PUT', '/api/v1/purchase/{X}'),
    ('PATCH', '/api/v1/purchase/{X}'), ('DELETE', '/api/v1/purchase/{X}'),
    ('POST', '/api/v1/sales'), ('PUT', '/api/v1/sales/{X}'),
    ('PATCH', '/api/v1/sales/{X}'), ('DELETE', '/api/v1/sales/{X}'),
    ('POST', '/api/v1/sales-return'), ('POST', '/api/v1/purchases-return'),
}

# Ids come from earlier requests rather than being invented.
VAR_FOR = {
    'stock_id': '{{stock_id}}', 'product_id': '{{product_id}}',
    'party_id': '{{party_id}}', 'sale_id': '{{sale_id}}',
    'purchase_id': '{{purchase_id}}', 'customer_id': '{{customer_id}}',
    'payment_type_id': '{{payment_type_id}}',
    'business_category_id': '{{business_category_id}}',
    'expense_category_id': '{{expense_category_id}}',
    'income_category_id': '{{income_category_id}}',
    'loyalty_package_id': '{{loyalty_package_id}}',
    'sale_detail_id': '{{sale_detail_id}}',
    'purchase_detail_id': '{{purchase_detail_id}}',
    'branch_id': '{{branch_id}}', 'warehouse_id': '{{warehouse_id}}',
    'category_id': '{{category_id}}', 'brand_id': '{{brand_id}}',
    'unit_id': '{{unit_id}}', 'vat_id': '{{vat_id}}',
}


# ── reading Laravel ─────────────────────────────────────────────────────────

def bracket_block(src: str, start: int) -> str:
    """The balanced [...] beginning at or after `start`."""
    j = src.find('[', start)
    if j == -1:
        return ''
    depth, k = 0, j
    while k < len(src):
        if src[k] == '[':
            depth += 1
        elif src[k] == ']':
            depth -= 1
            if depth == 0:
                break
        k += 1
    return src[j:k + 1]


def parse_rules(text: str) -> dict[str, str]:
    out: dict[str, str] = {}
    pattern = r"""['"]([A-Za-z0-9_.*]+)['"]\s*=>\s*(\[[^\]]*\]|'[^']*'|"[^"]*")"""
    for m in re.finditer(pattern, text):
        out[m.group(1)] = ' '.join(m.group(2).split())
    return out


def normalise(uri: str) -> str:
    return re.sub(r'\{[^}]+\??\}', '{X}', '/' + uri.strip('/'))


def load_routes() -> list[dict]:
    out = subprocess.run(
        ['php', 'artisan', 'route:list', '--json'],
        cwd=ROOT, capture_output=True, text=True,
    )
    if out.returncode != 0 or not out.stdout.strip():
        sys.exit('could not run `php artisan route:list --json`')
    return json.loads(out.stdout)


def collect_rules(routes: list[dict]) -> dict[str, dict[str, str]]:
    """endpoint ("POST /api/v1/x") -> {field: rule string}."""
    targets: dict[tuple[str, str], tuple[str, str]] = {}
    for r in routes:
        action = r.get('action', '')
        if '@' not in action:
            continue
        cls, meth = action.split('@')
        path = os.path.join(ROOT, 'app', cls.replace('App\\', '').replace('\\', '/') + '.php')
        for m in r['method'].split('|'):
            if m in ('GET', 'HEAD', 'DELETE'):
                continue
            targets[(m, normalise(r['uri']))] = (path, meth)

    rules: dict[str, dict[str, str]] = {}
    for (method, path), (cfile, meth) in targets.items():
        if not os.path.exists(cfile):
            continue
        src = open(cfile).read()
        text = ''

        # A typed FormRequest parameter means the rules live in that class.
        sig = re.search(rf'function\s+{re.escape(meth)}\s*\(\s*([A-Za-z_]\w*)\s+\$request', src)
        if sig and sig.group(1).endswith('Request') and sig.group(1) != 'Request':
            found = subprocess.run(
                ['bash', '-c', f"grep -rl 'class {sig.group(1)} ' app/Http/Requests 2>/dev/null | head -1"],
                cwd=ROOT, capture_output=True, text=True,
            ).stdout.strip()
            if found:
                fsrc = open(os.path.join(ROOT, found)).read()
                i = fsrc.find('function rules')
                text = bracket_block(fsrc, i) if i != -1 else ''

        # Otherwise an inline ->validate([...]) inside the method.
        if not text:
            mi = src.find(f'function {meth}(')
            if mi != -1:
                seg = src[mi:mi + 12000]
                vi = seg.find('->validate(')
                if vi != -1:
                    text = bracket_block(seg, vi)

        if text:
            rules[f'{method} {path}'] = parse_rules(text)
    return rules


# ── rule interpretation ─────────────────────────────────────────────────────

def is_required(rule: str) -> bool:
    """Bare `required` only. required_if/required_with are conditional."""
    return re.search(r"(^|[|'\", ])required($|[|'\", ])", rule) is not None


def is_conditional(rule: str) -> bool:
    return 'required_' in rule


def is_file(rule: str) -> bool:
    return bool(re.search(r'\b(image|file|mimes)\b', rule))


def sample(field: str, rule: str):
    leaf = field.split('.')[-1]
    opts = re.search(r'in:([A-Za-z0-9_,\- ]+)', rule)
    if opts:
        return opts.group(1).split(',')[0].strip()
    if 'boolean' in rule:
        return True
    if leaf in VAR_FOR:
        return VAR_FOR[leaf]
    if 'email' in rule:
        return '{{email}}'
    if re.search(r'\bdate\b|date_format', rule):
        return '{{today}}'
    if 'integer' in rule:
        return 1
    if 'numeric' in rule:
        return 100
    if 'array' in rule:
        return []
    if 'password' in leaf:
        return '{{password}}'
    if leaf.endswith('_id') or leaf == 'id':
        return 1
    return f'Sample {leaf.replace("_", " ")}'


def build_body(rules: dict[str, str]):
    """-> (body, optional, conditional, required_files)"""
    body: dict = {}
    optional: list = []
    conditional: list = []
    files: list = []
    nested: dict[str, dict] = {}

    for field, rule in rules.items():
        if is_file(rule):
            (files if is_required(rule) else optional).append((field, rule))
            continue
        if is_conditional(rule):
            conditional.append((field, rule))
            continue
        if '.' in field:
            if '*' in field and is_required(rule):
                root, _, leaf = field.partition('.')
                nested.setdefault(root, {})[leaf.replace('*.', '')] = sample(field, rule)
            continue
        if is_required(rule):
            body[field] = sample(field, rule)
        else:
            optional.append((field, rule))

    for root, obj in nested.items():
        body[root] = [obj]
    return body, optional, conditional, files


# ── the collection ──────────────────────────────────────────────────────────

def walk(items, folder=''):
    for it in items:
        if 'item' in it:
            yield from walk(it['item'], it.get('name', folder))
        else:
            yield folder, it


def request_path(req) -> str:
    url = req['url']
    p = '/' + '/'.join(str(s) for s in url['path'])
    p = re.sub(r'\{\{[^}]+\}\}', '{X}', p)
    p = re.sub(r':[A-Za-z_]\w*', '{X}', p)
    return '/api/v1' + p


def describe(endpoint, rules, optional, conditional, files, idem) -> str:
    out = [f'`{endpoint}`', '']
    required = sorted((f, r) for f, r in rules.items() if is_required(r) and not is_conditional(r))
    if required:
        out += ['**Required**', ''] + [f'- `{f}` — {r}' for f, r in required] + ['']
    if conditional:
        out += ['**Conditional**', ''] + [f'- `{f}` — {r}' for f, r in sorted(conditional)] + ['']
    if optional:
        out += ['**Optional**', ''] + [f'- `{f}` — {r}' for f, r in sorted(optional)] + ['']
    if files:
        out += ['**File upload (multipart/form-data)**', ''] + [f'- `{f}` — {r}' for f, r in sorted(files)] + ['']
    if idem:
        out += [
            '**Idempotency**', '',
            'Send `Idempotency-Key`. A repeat with the same key and the same body',
            'replays the stored response. Same key with a different body returns',
            '**422** `idempotency_key_reused`; same key while the first is still in',
            'flight returns **409** `idempotency_in_progress`.', '',
        ]
    out.append('Fields are taken from this route\'s Laravel validation rules.')
    return '\n'.join(out)


def run(fix: bool, matrix: bool) -> int:
    rules_by_endpoint = collect_rules(load_routes())
    coll = json.load(open(COLLECTION))

    drift, rows, filled = [], [], 0

    for folder, item in walk(coll['item']):
        req = item['request']
        method, path = req['method'], request_path(req)
        endpoint = f'{method} {path}'
        rules = rules_by_endpoint.get(endpoint)
        idem = (method, path) in IDEMPOTENT

        if idem and fix:
            headers = req.setdefault('header', [])
            if not any(h.get('key', '').lower() == 'idempotency-key' for h in headers):
                headers.append({
                    'key': 'Idempotency-Key', 'value': '{{$guid}}', 'type': 'text',
                    'description': 'Retry-safe key; same key + same body replays the first response.',
                })

        if not rules:
            continue

        body, optional, conditional, files = build_body(rules)

        if fix and method in ('POST', 'PUT', 'PATCH'):
            if files:
                req['body'] = {'mode': 'formdata', 'formdata':
                    [{'key': f, 'type': 'file', 'src': [], 'description': r} for f, r in files]
                    + [{'key': k, 'value': str(v), 'type': 'text'} for k, v in body.items()]}
            else:
                req['body'] = {'mode': 'raw', 'raw': json.dumps(body, indent=2),
                               'options': {'raw': {'language': 'json'}}}
            item['description'] = describe(endpoint, rules, optional, conditional, files, idem)
            filled += 1

        # Audit what is actually in the file.
        if method in ('POST', 'PUT', 'PATCH'):
            b = req.get('body', {})
            if b.get('mode') == 'formdata':
                present = {x['key'] for x in b.get('formdata', [])}
            else:
                try:
                    present = set(json.loads(b.get('raw', '{}')))
                except Exception:
                    present = set()
            missing = sorted({
                f for f, r in rules.items()
                if is_required(r) and not is_conditional(r) and f.split('.')[0] not in present
            })
            if missing:
                drift.append((folder, item['name'], endpoint, missing))
            rows.append((method, path, sorted(body), [f for f, _ in optional],
                         [f for f, _ in conditional], sorted(present), missing))

    if fix:
        json.dump(coll, open(COLLECTION, 'w'), indent=1)
        print(f'rewrote {filled} request bodies from Laravel validation rules')

    if matrix:
        print('| Method | Endpoint | Required | Optional | Conditional | In Postman | Missing |')
        print('| --- | --- | --- | --- | --- | --- | --- |')
        for m, p, req_f, opt, cond, present, missing in sorted(rows):
            print(f'| {m} | `{p}` | {", ".join(req_f) or "—"} | {len(opt)} | '
                  f'{", ".join(cond) or "—"} | {len(present)} | '
                  f'{", ".join(missing) if missing else "none"} |')

    print(f'\nwrite requests audited: {len(rows)}')
    print(f'requests missing required fields: {len(drift)}')
    for folder, name, endpoint, missing in drift:
        print(f'  [{folder}] {name} ({endpoint}) missing: {missing}')

    return 1 if drift else 0


if __name__ == '__main__':
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--fix', action='store_true', help='rewrite bodies from the rules')
    ap.add_argument('--matrix', action='store_true', help='print the field matrix')
    args = ap.parse_args()
    sys.exit(run(args.fix, args.matrix))
