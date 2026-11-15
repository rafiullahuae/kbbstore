import json, sys, collections
# Summarise ln15-gaps output: gap values per width, bodies with uneven heading gaps.
rows = json.load(open(sys.argv[1]))
dist = collections.Counter()
uneven = []
for r in rows:
    g = [x['gap'] for x in (r.get('gaps') or [])]
    for x in g:
        dist[(r['w'], round(x))] += 1
    if g and max(g) - min(g) > 1:
        uneven.append((r['slug'], r['w'], [(x['heading'][:22], x['gap']) for x in r['gaps']]))
print('pages', len(rows), 'with headings', sum(1 for r in rows if r.get('gaps')))
for w in sorted({k[0] for k in dist}):
    print(w, sorted(((k[1], v) for k, v in dist.items() if k[0] == w)))
print('uneven bodies:', len(uneven), 'products:', len({u[0] for u in uneven}))
for u in uneven:
    if u[1] == 1280 or len(sys.argv) > 2:
        print(' ', u[0], u[1], u[2])
