"""Lane AR round 3 -- round 2 (before) against round 3 (after), interleaved runs.

    python3 tools/arr-perf-r3.py  ->  docs/lane-ar-shots/round3/perf-round3.json
"""
import json

L = 'storage/ar-logs/'
b = [json.load(open(L + f'speed-before{i}.json')) for i in (1, 2)]
a = [json.load(open(L + f'speed-after{i}.json')) for i in (1, 2)]
rows = []
for lang in ('en', 'ar'):
    for p in ('home', 'category', 'product', 'brand', 'search', 'search_ar', 'suggest', 'suggest_ar'):
        bm = min(x[lang][p]['median_ms'] for x in b)
        am = min(x[lang][p]['median_ms'] for x in a)
        rb, ra = b[0][lang][p], a[0][lang][p]
        rows.append({'page': f'{lang} {p}', 'ms': [bm, am], 'queries': [rb['queries'], ra['queries']],
                     'settings_reads': [rb['settings_reads'], ra['settings_reads']], 'html_bytes': [rb['html_bytes'], ra['html_bytes']]})
        print(f"{lang} {p:10} ms {bm:6}->{am:<6} q {rb['queries']}->{ra['queries']} reads {rb['settings_reads']}->{ra['settings_reads']} bytes {rb['html_bytes']}->{ra['html_bytes']} status {rb['status']}/{ra['status']}")
json.dump(rows, open('docs/lane-ar-shots/round3/perf-round3.json', 'w'), indent=1)
