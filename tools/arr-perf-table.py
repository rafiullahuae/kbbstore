"""Lane AR round 2 -- the BEFORE / AFTER table from tools/arr-speed.php and tools/arr-cls.cjs.

    python3 tools/arr-perf-table.py css <tag> <public-build-dir>   adds render-blocking CSS bytes to speed-<tag>.json
    python3 tools/arr-perf-table.py table                            prints the table, writes docs/lane-ar-shots/round2/perf.json

Server ms is the lowest median over the interleaved runs (speed-before1..4 /
speed-after1..4, base commit and this tree alternating on one copy of the same
database, storage/ar-logs/speed-ab.sh), because other lanes share the machine.
Queries, settings-map reads and bytes come from speed-before.json (the base
commit on its own seeded copy) and speed-after.json.
"""
import sys, json, gzip, os, glob

L = 'storage/ar-logs'
if sys.argv[1] == 'css':
    tag, build = sys.argv[2], sys.argv[3]
    d = json.load(open(f'{L}/speed-{tag}.json'))
    for lang in d:
        for page, r in d[lang].items():
            raw = gz = 0
            for href in r['css']:
                b = open(os.path.join(build, href.split('/build/', 1)[1]), 'rb').read()
                raw += len(b)
                gz += len(gzip.compress(b, 6))
            r['css_raw'], r['css_gzip'] = raw, gz
    json.dump(d, open(f'{L}/speed-{tag}.json', 'w'), indent=1)
    print('ok', tag)
    sys.exit()

b, a = json.load(open(f'{L}/speed-before.json')), json.load(open(f'{L}/speed-after.json'))
bi = [json.load(open(f)) for f in sorted(glob.glob(f'{L}/speed-before[0-9].json'))]
ai = [json.load(open(f)) for f in sorted(glob.glob(f'{L}/speed-after[0-9].json'))]
cb, ca = json.load(open(f'{L}/cls-before.json')), json.load(open(f'{L}/cls-after.json'))
rows = []
print(f"{'page':14} {'ms b→a':>13} {'queries':>8} {'set.reads':>10} {'HTML gz b→a':>14} {'CSS raw b→a':>16} {'CSS gz b→a':>13}  CLS 390/1280 b→a")
for lang in ('en', 'ar'):
    for page in ('home', 'category', 'product', 'brand', 'cart', 'checkout'):
        x, y = b[lang][page], a[lang][page]
        mb = min(r[lang][page]['median_ms'] for r in bi) if bi else x['median_ms']
        ma = min(r[lang][page]['median_ms'] for r in ai) if ai else y['median_ms']
        cl = lambda c, w: c['cls'].get(f'{lang} {page} {w}', '-')
        row = {'page': f'{lang} {page}', 'ms': [mb, ma], 'queries': [x['queries'], y['queries']],
               'settings_reads': [x['settings_reads'], y['settings_reads']], 'html_gzip': [x['html_gzip'], y['html_gzip']],
               'css_raw': [x.get('css_raw'), y.get('css_raw')], 'css_gzip': [x.get('css_gzip'), y.get('css_gzip')],
               'scripts': [x['scripts'], y['scripts']], 'cls': [[cl(cb, 390), cl(cb, 1280)], [cl(ca, 390), cl(ca, 1280)]]}
        rows.append(row)
        print(f"{row['page']:14} {mb:>6}→{ma:<6} {x['queries']:>3}→{y['queries']:<4} {x['settings_reads']:>4}→{y['settings_reads']:<5} {x['html_gzip']:>6}→{y['html_gzip']:<7} {x.get('css_raw')}→{y.get('css_raw')} {x.get('css_gzip')}→{y.get('css_gzip')}  {row['cls'][0]}→{row['cls'][1]}")
out = {'rows': rows, 'prefetch_before': cb['prefetch'], 'prefetch_after': ca['prefetch']}
json.dump(out, open('docs/lane-ar-shots/round2/perf.json', 'w'), indent=1)
for k, v in ca['prefetch'].items():
    pb = cb['prefetch'].get(k)
    print('prefetch', k, 'used before', pb.get('used') if isinstance(pb, dict) else pb, 'after', v.get('used') if isinstance(v, dict) else v)
