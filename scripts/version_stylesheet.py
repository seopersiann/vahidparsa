#!/usr/bin/env python3
"""Refresh local stylesheet URLs when their content changes, preserving caching."""
from pathlib import Path
import hashlib
import re

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED = {'deploy', 'scripts', 'tests', 'node_modules', 'assets', 'dist', 'build', '__pycache__'}

def update(root=ROOT):
    digest = hashlib.sha256((root / 'assets/css/style.css').read_bytes()).hexdigest()[:12]
    changed = 0
    pattern = re.compile(r'(\bhref=["\'])([^"\']*assets/css/style\.css)(?:\?[^"\']*)?(["\'])')
    for page in sorted(root.rglob('*.html')):
        if any(part.startswith('.') or part in EXCLUDED for part in page.relative_to(root).parts):
            continue
        before = page.read_text(encoding='utf-8')
        after = pattern.sub(lambda m: m[1] + m[2] + '?v=' + digest + m[3], before)
        if before != after:
            page.write_text(after, encoding='utf-8')
            changed += 1
    print(f'Stylesheet {digest}; updated {changed} HTML pages')

if __name__ == '__main__':
    update()
