#!/usr/bin/env python3
# Lane ASEO: the search/social tags of each page's <head>, as a crawler reads them.
#   python3 tools/aseo-head.py <base> <path>...
import json, re, sys, urllib.request
base = sys.argv[1]
TAG = re.compile(r'<(title)>(.*?)</title>|<(html)\b[^>]*>|<meta (?:name|property)="(description|keywords|og:[a-z_:]+|twitter:[a-z]+)" content="([^"]*)"|<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', re.S)
for path in sys.argv[2:]:
    html = urllib.request.urlopen(base + path).read().decode('utf-8')
    head = html.split('</head>', 1)[0]
    print('== ' + path)
    for m in TAG.finditer(head):
        if m.group(1): print('  <title> ' + m.group(2))
        elif m.group(3): print('  ' + m.group(0))
        elif m.group(4):
            if m.group(4).startswith('og:image') or m.group(4) == 'twitter:image': continue
            print('  %s = %s' % (m.group(4), m.group(5)))
        else: print('  hreflang %s %s' % (m.group(6), m.group(7)))
    for blob in re.findall(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S):
        try: data = json.loads(blob)
        except Exception: continue
        nodes = data if isinstance(data, list) else data.get('@graph', [data])
        for n in nodes if isinstance(nodes, list) else [nodes]:
            if not isinstance(n, dict): continue
            keep = {k: n[k] for k in ('@type', 'name', 'alternateName', 'description', 'keywords', 'inLanguage') if k in n}
            print('  ld ' + json.dumps(keep, ensure_ascii=False))
