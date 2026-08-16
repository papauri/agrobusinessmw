#!/usr/bin/env python3
"""Bilingual key-set parity across every translation table in the project.

    python3 tests/i18n_parity.py

Completion criterion 4 of .claude/BUILD_PLAN.md is "every user-facing string
exists in both English and Chichewa". A key present in one table and missing
from the other means a farmer reading in Chichewa hits an English string, or
worse a raw key. This check is the gate.

It parses the tables rather than executing them, so it needs no browser and no
PHP runtime.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def _balanced_block(text: str, start: int) -> str:
    """Return the contents of the { … } that begins at `start`."""
    depth, i = 1, start
    while depth and i < len(text):
        if text[i] == '{':
            depth += 1
        elif text[i] == '}':
            depth -= 1
        i += 1
    return text[start:i - 1]


def js_table(path: Path, marker: str) -> dict:
    """Keys of the en/ci objects inside a JS translation table."""
    src = path.read_text(encoding='utf-8')
    if marker not in src:
        raise SystemExit(f'{path}: marker {marker!r} not found — has the table moved?')
    block = src[src.index(marker):]

    out = {}
    for lang in ('en', 'ci'):
        match = re.search(r'\b' + lang + r'\s*:\s*\{', block)
        if not match:
            out[lang] = set()
            continue
        body = _balanced_block(block, match.end())
        # A key starts a line or follows a comma/brace. re.MULTILINE matters:
        # without it, every key that begins a line is silently skipped and the
        # check reports gaps that are not there.
        out[lang] = set(re.findall(r'(?:^|[,{])\s*([A-Za-z_][A-Za-z0-9_]*)\s*:', body, re.MULTILINE))
    return out


def php_table(path: Path) -> dict:
    """Keys of REGISTRATION_STRINGS that carry an 'en' / 'ci' entry."""
    src = path.read_text(encoding='utf-8')
    start = src.index('const REGISTRATION_STRINGS')
    block = src[start:src.index('\n];', start)]

    found = {'en': set(), 'ci': set()}
    for match in re.finditer(r"'([a-z_0-9]+)'\s*=>\s*\[(.*?)\],\n", block, re.S):
        key, body = match.group(1), match.group(2)
        for lang in ('en', 'ci'):
            if f"'{lang}'" in body:
                found[lang].add(key)
    return found


TABLES = [
    ('assets/js/register.js', lambda p: js_table(p, '  const copy = {')),
    ('assets/js/directory-navigation.js', lambda p: js_table(p, '    const copy = {')),
    ('assets/js/market-insights-page.js', lambda p: js_table(p, '    const copy = {')),
    ('assets/js/app.js', lambda p: js_table(p, '        this.texts = {')),
    ('register.php', php_table),
]


def main() -> int:
    failures = 0
    for name, loader in TABLES:
        path = ROOT / name
        if not path.exists():
            print(f'FAIL {name}: file not found')
            failures += 1
            continue

        keys = loader(path)
        en, ci = keys.get('en', set()), keys.get('ci', set())
        only_en, only_ci = en - ci, ci - en

        if not en:
            print(f'FAIL {name}: no English keys parsed — the table format changed')
            failures += 1
            continue

        ok = not (only_en or only_ci)
        if not ok:
            failures += 1
        print(f'{"ok  " if ok else "FAIL"} {name:38s} en={len(en):3d} ci={len(ci):3d}')
        if only_en:
            print(f'       no Chichewa for: {sorted(only_en)}')
        if only_ci:
            print(f'       no English for:  {sorted(only_ci)}')

    print()
    if failures:
        print(f'i18n_parity: {failures} table(s) with gaps')
        return 1
    print(f'i18n_parity: {len(TABLES)}/{len(TABLES)} tables have complete en/ci parity')
    return 0


if __name__ == '__main__':
    sys.exit(main())
