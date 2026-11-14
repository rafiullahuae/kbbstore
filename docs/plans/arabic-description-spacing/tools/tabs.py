import re, sys, json, os
# Extract tab titles + bodies from cached EN/AR product pages -> JSON per product.
d = sys.argv[1]
out = {}
for line in open(d + '/all-products.txt'):
    p = line.strip()
    if not p:
        continue
    slug = os.path.basename(p.rstrip('/'))
    rec = {}
    for loc in ('en', 'ar'):
        f = f'{d}/pages/{loc}-{slug}.html'
        if not os.path.exists(f):
            break
        s = open(f, encoding='utf8').read()
        titles = re.findall(r'<button class="dtab[^"]*" type="button" data-i="\d+">(.*?)</button>', s)
        bodies = re.findall(r'<div class="dtabpanel[^"]*" data-panel="\d+">\s*<div class="dcontent clamp">(.*?)</div>\s*<button class="readmore"', s, re.S)
        rec[loc] = [{'title': t, 'body': b} for t, b in zip(titles, bodies)]
        sm = re.search(r'<div class="pm-sec pm-short[^"]*"><input[^>]*>(?:<div|<p) class="[^"]*bb-desc">(.*?)</(?:div|p)><label class="bb-more"', s, re.S)
        rec[loc + '_short'] = sm.group(1) if sm else None
    if 'ar' in rec and 'en' in rec:
        out[slug] = rec
json.dump(out, open(d + '/tabs.json', 'w'), ensure_ascii=False)
print(len(out))
