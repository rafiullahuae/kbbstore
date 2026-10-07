#!/usr/bin/env python3
"""Lane PG2: does after-response picture work make the NEXT page wait?

  python3 tools/pg2g-pool.py LABEL

Run against a preview restarted by tools/pg2g-pool.sh (2 workers, cold
pictures). php -S has no fastcgi_finish_request, so a worker is busy until its
after-response work ends -- exactly as a PHP-FPM worker is, even though there
the shopper already has that page. Two shoppers' views of cold category pages
(and, separately, two of Chrome's hover PREFETCHES of them) land together,
then (PG2G_CLICK_AT, default 750 ms -- when both pages are sent and their
workers are making pictures) a shopper opens a product page. Reported: how long that
product page took to its first byte (what a shopper waits for under
PHP-FPM), and how long each worker spent after its response
(spd-after.log).
"""
import json, os, sys, threading, time, urllib.request

APP = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
label = sys.argv[1]
d = os.path.join(APP, 'storage/framework/testing/lane-spd-' + label)
base = 'http://127.0.0.1:' + open(os.path.join(d, 'port')).read().strip()
mode = sys.argv[2] if len(sys.argv) > 2 else 'views'
out = {}

def get(name, path, headers=None):
    t = time.time()
    req = urllib.request.Request(base + path, headers=headers or {})
    with urllib.request.urlopen(req, timeout=120) as r:
        first = time.time()   # status line and headers: the page is on its way
        r.read()
    out[name] = {'first byte': round((first - t) * 1000), 'connection closed': round((time.time() - t) * 1000)}

hdr = {'Sec-Purpose': 'prefetch'} if mode == 'prefetch' else {}
threads = [threading.Thread(target=get, args=('category A', '/collections/spd-dept-1/', hdr)),
           threading.Thread(target=get, args=('category B', '/collections/spd-dept-2/', hdr))]
for t in threads: t.start()
time.sleep(float(os.environ.get('PG2G_CLICK_AT', '0.75')))
get('product (the click)', '/product/spd-product-777/')
for t in threads: t.join()
after = [json.loads(l) for l in open(os.path.join(d, 'spd-after.log')) if l.strip()]
out['after-response ms per page'] = {a['uri']: a['after_ms'] for a in after}
print(json.dumps({'label': label, 'mode': mode, **out}))
