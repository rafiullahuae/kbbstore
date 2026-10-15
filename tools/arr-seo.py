"""Lane AR round 2 -- SEO parity, /ar against its English twin, from the SERVER
HTML tools/arr-shots.cjs saved (storage/ar-logs/html/<lang>-<key>.html).

    python3 tools/arr-seo.py  ->  docs/lane-ar-shots/round2/seo-parity.json (+ a table on stdout)

Checks per page: <html lang/dir>, Arabic <title> and description, canonical
= own /ar URL, hreflang en+ar+x-default both ways, og:locale ar_AE with the
en alternate, og/twitter title in Arabic, robots identical to English, JSON-LD
(Product name Arabic, offers unchanged, BreadcrumbList Arabic names on /ar
URLs, inLanguage, no Latin-only names), one <h1> like English, image alt text
not Latin-only, every internal link on the page staying under /ar.
"""
import json, re, html, os, glob

DIR = 'storage/ar-logs/html'
LATIN = re.compile(r'[A-Za-z]{3,}')
ARABIC = re.compile(r'[؀-ۿ]')


def head_meta(s):
    m = {}
    m['lang'] = (re.search(r'<html[^>]*\blang="([^"]*)"', s) or [None, None])[1]
    m['dir'] = (re.search(r'<html[^>]*\bdir="([^"]*)"', s) or [None, None])[1]
    t = re.search(r'<title>([^<]*)</title>', s)
    m['title'] = html.unescape(t.group(1)) if t else ''
    def meta(attr, name):
        x = re.search(r'<meta\s+%s="%s"\s+content="([^"]*)"' % (attr, re.escape(name)), s)
        return html.unescape(x.group(1)) if x else None
    m['desc'] = meta('name', 'description')
    m['robots'] = meta('name', 'robots')
    m['og_locale'] = meta('property', 'og:locale')
    m['og_locale_alt'] = re.findall(r'<meta\s+property="og:locale:alternate"\s+content="([^"]*)"', s)
    m['og_title'] = meta('property', 'og:title')
    m['og_url'] = meta('property', 'og:url')
    m['tw_title'] = meta('name', 'twitter:title')
    c = re.search(r'<link rel="canonical" href="([^"]*)"', s)
    m['canonical'] = c.group(1) if c else None
    m['hreflang'] = dict(re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', s))
    m['ld'] = []
    for blk in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        try:
            m['ld'].append(json.loads(blk))
        except Exception:
            m['ld'].append({'_parse_error': True})
    body = s.split('<body', 1)[-1]
    body_nos = re.sub(r'(?is)<(script|style|template)\b.*?</\1>', '', body)
    m['h1'] = len(re.findall(r'<h1[\s>]', body_nos))
    alts = [html.unescape(a) for a in re.findall(r'<img[^>]*\salt="([^"]*)"', body_nos)]
    m['alts_latin_only'] = [a for a in alts if a and LATIN.search(a) and not ARABIC.search(a)][:5]
    hrefs = re.findall(r'<a\s[^>]*href="(/[^"#]*)"', body_nos)
    m['links_total'] = len(hrefs)
    m['links_not_ar'] = sorted(set(h for h in hrefs if not h.startswith('/ar/') and h != '/ar' and not re.match(r'^/(api|uploads|img-cache|build|wp-content)/', h)))[:8]
    return m


def walk_ld(o, out):
    if isinstance(o, dict):
        t = o.get('@type')
        if t:
            out.append(o)
        for v in o.values():
            walk_ld(v, out)
    elif isinstance(o, list):
        for v in o:
            walk_ld(v, out)


rows = {}
for f in sorted(glob.glob(os.path.join(DIR, 'ar-*.html'))):
    key = os.path.basename(f)[3:-5]
    en_f = os.path.join(DIR, 'en-' + key + '.html')
    if not os.path.exists(en_f):
        continue
    a = head_meta(open(f, encoding='utf8').read())
    e = head_meta(open(en_f, encoding='utf8').read())
    r = {}
    r['lang_dir'] = 'OK' if (a['lang'] == 'ar' and a['dir'] == 'rtl') else f"gap ({a['lang']}/{a['dir']})"
    r['title_ar'] = 'OK' if ARABIC.search(a['title']) and not re.search(r'K-Beauty Bliss', a['title']) else f"gap: {a['title'][:60]}"
    r['desc_ar'] = 'n/a' if not e['desc'] else ('OK' if a['desc'] and ARABIC.search(a['desc']) and not LATIN.search(re.sub(r'«[^»]*»', '', a['desc'])) else f"gap: {(a['desc'] or '')[:60]}")
    r['canonical'] = 'n/a' if not e['canonical'] else ('OK' if a['canonical'] and '/ar/' in a['canonical'] or (a['canonical'] or '').endswith('/ar') else f"gap: {a['canonical']}")
    hl = a['hreflang']
    if not e['hreflang']:
        r['hreflang'] = 'n/a (noindex page, none in English either)' if not hl else 'gap: Arabic has, English has not'
    else:
        ok = set(hl) >= {'en', 'ar', 'x-default'} and hl == e['hreflang']
        r['hreflang'] = 'OK' if ok else f"gap: ar={hl} en={e['hreflang']}"
    r['og_locale'] = 'OK' if a['og_locale'] in ('ar_AE', 'ar') and (not e['og_locale'] or e['og_locale_alt'] or a['og_locale_alt']) else ('n/a' if not e['og_locale'] else f"gap: {a['og_locale']} alt={a['og_locale_alt']}")
    r['og_twitter_ar'] = 'n/a' if not e['og_title'] else ('OK' if ARABIC.search(a['og_title'] or '') and ARABIC.search(a['tw_title'] or a['og_title']) else f"gap: {a['og_title']}")
    r['robots'] = 'OK' if a['robots'] == e['robots'] else f"gap: ar={a['robots']} en={e['robots']}"
    lds_a, lds_e = [], []
    for o in a['ld']: walk_ld(o, lds_a)
    for o in e['ld']: walk_ld(o, lds_e)
    notes = []
    for n in lds_a:
        t = n.get('@type')
        t = t if isinstance(t, str) else ','.join(t)
        if t in ('Product',):
            if not ARABIC.search(str(n.get('name', ''))): notes.append('Product.name Latin')
            # the English twin of THIS product (listing pages carry many): same URL without /ar
            u = str(n.get('url', '') or n.get('@id', '')).replace('/ar/', '/')
            en_p = next((x for x in lds_e if x.get('@type') == 'Product' and str(x.get('url', '') or x.get('@id', '')) == u), None)
            if en_p is None and not u:
                en_p = next((x for x in lds_e if x.get('@type') == 'Product'), None)
            if (n.get('description') or (en_p or {}).get('description')) and not ARABIC.search(str(n.get('description', ''))):
                notes.append('Product.description Latin')
            if en_p and json.dumps(en_p.get('offers'), sort_keys=True).replace('/ar/', '/') != json.dumps(n.get('offers'), sort_keys=True).replace('/ar/', '/'):
                notes.append('offers differ')
        if t == 'BreadcrumbList':
            for it in n.get('itemListElement', []):
                nm = str(it.get('name', '') or (it.get('item') or {}).get('name', '') if isinstance(it.get('item'), dict) else it.get('name', ''))
                url = it.get('item') if isinstance(it.get('item'), str) else (it.get('item') or {}).get('@id', '')
                if nm and LATIN.search(nm) and not ARABIC.search(nm): notes.append('crumb "%s" Latin' % nm[:20])
                if url and '/ar' not in url: notes.append('crumb url not /ar')
        if t in ('WebSite', 'WebPage', 'CollectionPage', 'Article', 'BlogPosting', 'ItemPage'):
            il = n.get('inLanguage')
            if il and il != 'ar' and il != ['en', 'ar'] and 'ar' not in (il if isinstance(il, list) else [il]): notes.append(f'{t}.inLanguage={il}')
    r['jsonld'] = 'n/a' if not lds_e else ('OK' if not notes else 'gap: ' + '; '.join(sorted(set(notes))[:4]))
    r['h1'] = 'OK' if a['h1'] == e['h1'] else f"gap: ar={a['h1']} en={e['h1']}"
    r['alt_ar'] = 'OK' if not a['alts_latin_only'] else 'gap: ' + ' | '.join(x[:25] for x in a['alts_latin_only'][:3])
    r['links_ar'] = 'OK' if not a['links_not_ar'] else 'gap: ' + ' '.join(a['links_not_ar'][:5])
    rows[key] = r

os.makedirs('docs/lane-ar-shots/round2', exist_ok=True)
json.dump(rows, open('docs/lane-ar-shots/round2/seo-parity.json', 'w'), ensure_ascii=False, indent=1)
cols = ['lang_dir', 'title_ar', 'desc_ar', 'canonical', 'hreflang', 'og_locale', 'og_twitter_ar', 'robots', 'jsonld', 'h1', 'alt_ar', 'links_ar']
for k, r in rows.items():
    bad = {c: r[c] for c in cols if not r[c].startswith(('OK', 'n/a'))}
    print(f"{k:15s} {'ALL OK' if not bad else ''}")
    for c, v in bad.items():
        print('     ', c, v[:150])
