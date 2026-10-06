#!/usr/bin/env python3
"""Lane SP (speed): server-side before/after, median of N, warm and cold.

  python3 tools/spd-server.py LABEL[,LABEL...] [N] > table.json

Each LABEL is a tools/spd-preview.sh preview. Requests are INTERLEAVED across
the previews (one URL, one preview, next preview, ...) so machine drift on
this shared container lands on every column rather than on one.

  warm  the application cache as a running shop has it (one discarded hit)
  cold  storage/framework/cache/data emptied before EVERY hit (settings map,
        nav, shop caches all rebuilt) -- OPcache and compiled views stay warm,
        as PHP-FPM keeps them across a cache clear.
"""
import json, os, shutil, statistics, subprocess, sys, time

APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
labels = sys.argv[1].split(',')
N = int(sys.argv[2]) if len(sys.argv) > 2 else 5
PAGES = [
    ('home', '/'),
    ('product', '/product/spd-product-500/'),
    ('category big', '/collections/spd-dept-1/'),
    ('category small', '/collections/spd-dept-1/spd-shelf-1-2/'),
    ('brand', '/brands/anua/'),
    ('super-sale', '/super-sale/'),
    ('shop', '/shop/'),
    ('search', '/shop/?s=glow'),
    ('blog post', '/blog/spd-post-12/'),
]
if os.environ.get('SPD_PAGES'):
    keep = os.environ['SPD_PAGES'].split(',')
    PAGES = [p for p in PAGES if p[0] in keep]


def d(label):
    return os.path.join(APP, 'storage/framework/testing/lane-spd-' + label)


def port(label):
    return open(os.path.join(d(label), 'port')).read().strip()


def clear(label):
    data = os.path.join(d(label), 'app/storage/framework/cache/data')
    shutil.rmtree(data, ignore_errors=True)
    os.makedirs(data, exist_ok=True)


def hit(label, path):
    out = subprocess.run(['curl', '-s', '-o', '/dev/null', '-D', '-', '-H', 'Accept-Encoding: identity',
                          '-w', 'TTFB=%{time_starttransfer} TOTAL=%{time_total} SIZE=%{size_download} CODE=%{http_code}',
                          'http://127.0.0.1:%s%s' % (port(label), path)], capture_output=True, text=True).stdout
    h = {}
    for line in out.splitlines():
        if ':' in line and line.lower().startswith('x-spd'):
            k, v = line.split(':', 1)
            h[k.strip().lower()] = float(v.strip())
    tail = dict(x.split('=') for x in out.strip().splitlines()[-1].split() if '=' in x)
    return {'ms': h.get('x-spd-ms'), 'q': h.get('x-spd-q'), 'qms': h.get('x-spd-qms'), 'mem': h.get('x-spd-mem'),
            'ttfb': float(tail['TTFB']) * 1000, 'total': float(tail['TOTAL']) * 1000,
            'bytes': int(tail['SIZE']), 'code': tail['CODE']}


res = {}
for name, path in PAGES:
    for mode in ('warm', 'cold'):
        runs = {l: [] for l in labels}
        if mode == 'warm':
            for l in labels:
                hit(l, path)
        for i in range(N):
            for l in labels:
                if mode == 'cold':
                    clear(l)
                runs[l].append(hit(l, path))
        for l in labels:
            r = runs[l]
            med = lambda k: round(statistics.median([x[k] for x in r if x[k] is not None]), 1)
            res.setdefault(name, {}).setdefault(mode, {})[l] = {
                'ms': med('ms'), 'ttfb': med('ttfb'), 'total': med('total'), 'q': med('q'), 'qms': med('qms'),
                'mem_kb': med('mem'), 'bytes': r[-1]['bytes'], 'code': r[-1]['code']}
        print(name, mode, {l: (res[name][mode][l]['ms'], res[name][mode][l]['q'], res[name][mode][l]['total']) for l in labels}, file=sys.stderr)
print(json.dumps(res, indent=1))
