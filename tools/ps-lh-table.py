#!/usr/bin/env python3
"""Lane PS: median table of storage/ps-logs/lh/runs.jsonl (ab-before-* vs ab-after-*)."""
import json, statistics, sys, collections
rows = [json.loads(l) for l in open(sys.argv[1])]
g = collections.defaultdict(list)
for r in rows:
    if not r['label'].startswith('ab-'):
        continue
    _, tree, *page, _n = r['label'].split('-')
    g[('-'.join(page), r['profile'], tree)].append(r)
keys = ['perf', 'a11y', 'bp', 'seo', 'fcp', 'lcp', 'si', 'tbt', 'cls', 'kib']
which = sys.argv[2] if len(sys.argv) > 2 else 'both'
print('| page | profile | tree | n | Perf | A11y | BP | SEO | FCP ms | LCP ms | SI ms | TBT ms | CLS | KiB |')
print('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|')
for page in ['home', 'category', 'product', 'super-sale', 'brand']:
    for prof in ['mobile', 'desktop']:
        for tree in (['before', 'after'] if which == 'both' else [which]):
            rs = g.get((page, prof, tree), [])
            if not rs:
                continue
            med = {k: statistics.median([r[k] for r in rs]) for k in keys}
            print(f"| {page} | {prof} | {tree} | {len(rs)} | " + ' | '.join(
                (f"{med[k]:.3f}" if k == 'cls' else f"{med[k]:.0f}") for k in keys) + ' |')
