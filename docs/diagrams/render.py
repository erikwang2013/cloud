#!/usr/bin/env python3
"""Extract Mermaid blocks from markdown files and render them as SVGs via Kroki."""

import urllib.request, json, re, sys, time
from pathlib import Path

FILES = {
    '../../README.md': [
        ('system-architecture-zh', 0),
        ('security-middleware-zh', 1),
    ],
    '../../README_EN.md': [
        ('system-architecture-en', 0),
        ('security-middleware-en', 1),
    ],
    '../admin-design.md': [
        ('module-dependency', 0),
        ('order-payment-provisioning', 1),
        ('provisioning-detail', 2),
        ('notification-dispatch', 3),
        ('supplier-lifecycle', 4),
        ('ticket-lifecycle', 5),
        ('database-er', 6),
    ],
}

BASE = Path(__file__).parent

def extract_mermaid_blocks(filepath):
    with open(BASE / filepath) as f:
        content = f.read()
    blocks = re.findall(r'```mermaid\n(.*?)```', content, re.DOTALL)
    return blocks

def render_svg(diagram_text):
    payload = json.dumps({
        'diagram_source': diagram_text.strip(),
        'diagram_type': 'mermaid'
    }).encode()
    req = urllib.request.Request(
        'https://kroki.io/mermaid/svg',
        data=payload,
        headers={'Content-Type': 'application/json'}
    )
    resp = urllib.request.urlopen(req, timeout=30)
    return resp.read()

for filepath, diagrams in FILES.items():
    blocks = extract_mermaid_blocks(filepath)
    print(f'{filepath}: {len(blocks)} mermaid blocks')
    for name, idx in diagrams:
        if idx >= len(blocks):
            print(f'  SKIP {name} - index {idx} out of range')
            continue
        outpath = BASE / f'{name}.svg'
        if outpath.exists():
            print(f'  SKIP {name} - already exists')
            continue
        try:
            svg = render_svg(blocks[idx])
            outpath.write_bytes(svg)
            print(f'  OK {name} -> {len(svg)} bytes')
        except Exception as e:
            print(f'  FAIL {name}: {e}')
        time.sleep(0.5)  # Rate limit

print('Done')
