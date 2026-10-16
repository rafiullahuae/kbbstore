#!/usr/bin/env python3
"""Lane AS -- SEO audit of the Arabic (and English) storefront, page by page.

    python3 -I tools/as-seo-audit.py http://127.0.0.1:<port> [out.json]

Fetches each page type in both languages and prints one row per page:
lang/dir, title, description, canonical, hreflang set, og/twitter, JSON-LD
validity and the Arabic-ness of names inside it, h1 count, robots, and the
alt text of every <img>. Stdlib only.
"""
import json, re, sys, urllib.request, html

BASE = sys.argv[1].rstrip('/')
OUT = sys.argv[2] if len(sys.argv) > 2 else None
PAGES = [
    ('home', '/'), ('category', '/product-category/cleansers/'), ('brand', '/brands/anua/'),
    ('product', '/product/azelaic-10-hyaluron-redness-soothing-pad/'), ('blog index', '/blog/'),
    ('blog post', '/blog/how-to-layer-toner-pads/'), ('content page', '/about/'),
    ('search', '/shop/?s=toner'), ('shop', '/shop/'), ('cart', '/cart/'), ('checkout', '/checkout/'),
    ('account', '/my-account/'), ('404', '/no-such-page-as/'),
]
AR = re.compile(r'[؀-ۿ]')


def fetch(path):
    req = urllib.request.Request(BASE + path, headers={'User-Agent': 'Mozilla/5.0 lane-as-audit'})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)


def attr(tag, name):
    m = re.search(r'\b' + name + r'\s*=\s*"([^"]*)"', tag)
    return html.unescape(m.group(1)) if m else None


def meta(doc, key, val):
    for t in re.findall(r'<meta\b[^>]*>', doc):
        if attr(t, key) == val:
            return attr(t, 'content')
    return None


def arabic_share(s):
    if not s:
        return 0.0
    letters = [c for c in s if c.isalpha()]
    return round(sum(1 for c in letters if AR.match(c)) / max(1, len(letters)), 2)


def walk_names(node, out, path=''):
    if isinstance(node, dict):
        for k, v in node.items():
            if k in ('name', 'description', 'headline') and isinstance(v, str):
                out.append((path + '.' + k, v[:60], arabic_share(v)))
            elif k in ('item', 'url', '@id') and isinstance(v, str):
                out.append((path + '.' + k, v, None))
            elif k == 'inLanguage':
                out.append((path + '.inLanguage', v, None))
            else:
                walk_names(v, out, path + '.' + k)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk_names(v, out, path + '[%d]' % i)


def audit(path):
    code, doc, hdr = fetch(path)
    head = doc.split('</head>')[0]
    body = doc.split('<body', 1)[-1]
    h = re.search(r'<html\b[^>]*>', doc)
    title = re.search(r'<title>(.*?)</title>', head, re.S)
    lds = []
    for raw in re.findall(r'<script type="application/ld\+json">(.*?)</script>', doc, re.S):
        try:
            n = json.loads(raw)
            names = []
            walk_names(n, names)
            lds.append({'type': n.get('@type') if isinstance(n, dict) else 'list', 'names': names})
        except Exception as ex:  # invalid JSON is itself a finding
            lds.append({'invalid': str(ex)})
    alts = {}
    for t in re.findall(r'<img\b[^>]*>', body):
        a = attr(t, 'alt')
        alts.setdefault('missing' if a is None else ('empty' if a == '' else ('ar' if arabic_share(a) > 0.5 else 'latin')), []).append((a or '')[:50])
    return {
        'path': path, 'status': code,
        'html': h.group(0) if h else None,
        'title': html.unescape(title.group(1)) if title else None,
        'description': meta(head, 'name', 'description'),
        'robots': meta(head, 'name', 'robots'),
        'x_robots': hdr.get('X-Robots-Tag'),
        'canonical': [attr(t, 'href') for t in re.findall(r'<link\b[^>]*rel="canonical"[^>]*>', head)],
        'hreflang': {attr(t, 'hreflang'): attr(t, 'href') for t in re.findall(r'<link\b[^>]*hreflang=[^>]*>', head)},
        'og': {k: meta(head, 'property', k) for k in ('og:title', 'og:description', 'og:locale', 'og:locale:alternate', 'og:url', 'og:image:alt')},
        'tw': {k: meta(head, 'name', k) for k in ('twitter:title', 'twitter:description', 'twitter:image:alt')},
        'jsonld': lds,
        'h1': len(re.findall(r'<h1\b', body)),
        'alts': {k: (len(v), v[:4]) for k, v in alts.items()},
    }


rows = []
for kind, p in PAGES:
    for lang, prefix in (('en', ''), ('ar', '/ar')):
        r = audit(prefix + p)
        r['kind'], r['lang'] = kind, lang
        rows.append(r)
        print(json.dumps(r, ensure_ascii=False))

for extra in ('/sitemap.xml', '/robots.txt'):
    code, doc, _ = fetch(extra)
    print(extra, code, len(doc))
    print(doc[:1500])

if OUT:
    json.dump(rows, open(OUT, 'w'), ensure_ascii=False, indent=1)
