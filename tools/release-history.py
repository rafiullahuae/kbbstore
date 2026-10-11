#!/usr/bin/env python3
"""Print docs/RELEASE-HISTORY.md from docs/CHANGELOG.md: one row per release,
newest first. Run from the repo root after every release:

    python3 tools/release-history.py > docs/RELEASE-HISTORY.md
"""
import re

c = open('docs/CHANGELOG.md', encoding='utf-8').read()
parts = re.split(r'^## (2\.\d+\.[\d–.\-]+)\s*$', c, flags=re.M)
rows = []
for i in range(1, len(parts), 2):
    v, body = parts[i], parts[i + 1].strip()
    m = re.match(r'\*\*(.+?)\*\*', body, flags=re.S)
    if m:
        t = m.group(1)
    else:
        t = body.split('\n\n', 1)[0]  # the first paragraph
    t = re.sub(r'\s+', ' ', t).strip().replace('|', '\\|')
    if len(t) > 400:
        t = t[:400].rsplit(' ', 1)[0] + ' …'
    rows.append((v, t))

print('# Release history — every package, newest first\n')
print('Generated from `docs/CHANGELOG.md` by `tools/release-history.py` (the full')
print("entry for each version, with the owner's words, admin paths and files, is")
print('there). Versions before 2.60.72 are summarised in `KBB-Master-Plan.md` ("What')
print('shipped" tables and the phases) and in the timeline in `docs/HANDOVER.md` §13.\n')
print(f'**{len(rows)} entries, {rows[-1][0]} → {rows[0][0]}.**\n')
print('| Version | What it did |')
print('|---|---|')
for v, t in rows:
    print(f'| {v} | {t} |')
print('\nRegenerate after every release: `python3 tools/release-history.py > docs/RELEASE-HISTORY.md`')
