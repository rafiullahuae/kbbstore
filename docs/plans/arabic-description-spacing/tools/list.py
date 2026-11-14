import re, sys
d = sys.argv[1]
s = open(d + '/sitemap.xml', encoding='utf8').read()
locs = re.findall(r'<loc>https://kbeautybliss.com(/product/[^<]*)</loc>', s)
r = [l.strip() for l in open(d + '/recent50.txt') if l.strip()]
seen = set(r)
allp = r + [l for l in locs if l not in seen]
open(d + '/all-products.txt', 'w').write('\n'.join(allp) + '\n')
print(len(allp))
