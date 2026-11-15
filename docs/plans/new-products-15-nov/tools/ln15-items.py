import json, os, sys
# ITEMS list for tools/ln15-gaps.cjs: every fetched page of the given locale (optionally only the given slugs)
d, loc, out = sys.argv[1], sys.argv[2], sys.argv[3]
only = set(sys.argv[4].split(',')) if len(sys.argv) > 4 and sys.argv[4] else None
bodydir = sys.argv[5] if len(sys.argv) > 5 else None
tabs = json.load(open(d + '/tabs.json'))
items = []
for slug in tabs:
    if only and slug not in only:
        continue
    it = {'slug': slug, 'loc': loc, 'page': f'{d}/pages/{loc}-{slug}.html'}
    if bodydir:
        f = f'{bodydir}/{loc}-{slug}.html'
        if os.path.exists(f):
            it['body'] = f
    items.append(it)
json.dump(items, open(out, 'w'))
print(len(items))
