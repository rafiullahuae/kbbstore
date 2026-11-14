"""Lane GS2: fit.json (tools/gs2-shots.cjs) as a table, and the failures.

  python3 tools/gs2-fit-table.py <out dir>   -> prints; writes <out dir>/fit.md
"""
import json, os, sys

out = sys.argv[1]
fit = json.load(open(os.path.join(out, 'fit.json')))
widths = ['320', '360', '375', '390', '414', '430', '768', '1280']
rows = ['| style | lang | ' + ' | '.join(widths) + ' |', '|---|---|' + '---|' * len(widths)]
bad = []
for style, langs in fit.items():
    for lang, d in langs.items():
        cells = []
        for w in widths:
            m = d[w]
            if not m.get('stripW'):
                cells.append('—'); bad.append(f'{style} {lang} {w}: no strip'); continue
            ok = m['lines'] == 1 and (style == 'd' or not m['overflow']) and m['scrollWidth'] <= int(w) and m['font'] >= 10
            cells.append(f"{m['font']:.1f}px" + ('' if ok else ' ✗'))
            if not ok:
                bad.append(f"{style} {lang} {w}: {m}")
        if d.get('errors'):
            bad.append(f"{style} {lang} console: {d['errors']}")
        rows.append(f'| {style} | {lang} | ' + ' | '.join(cells) + ' |')
txt = '\n'.join(rows)
open(os.path.join(out, 'fit.md'), 'w').write(
    'Font size of the words at each viewport width (CSS px). Every cell: one line box, '
    'nothing overflowing (the ticker D scrolls by design), page scrollWidth = width, font >= 10px. ✗ marks a miss.\n\n' + txt + '\n')
print(txt)
print('\nMISSES:', len(bad))
for b in bad:
    print(' ', b)
