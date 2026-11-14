import json, sys
# usage: report.py audit.json -> markdown tables on stdout
a = json.load(open(sys.argv[1]))
print(f"Products compared (EN + AR page both fetched): **{a['products']}**; tab bodies + short descriptions compared: **{a['tabs_checked']}**, of which actually translated (Arabic letters, not the English fallback): **{a['tabs_translated']}**.\n")
print('| Class | Bodies | Products |\n|---|---:|---:|')
for k, v in sorted(a['classes'].items(), key=lambda kv: -kv[1]['tabs']):
    print(f"| {k} | {v['tabs']} | {v['products']} |")
print()
print('### Worst 20\n')
print('| # | Product | Score | Body | English lines | Arabic lines | Command |\n|---|---|---:|---|---|---|---|')
n = 0
for p in a['worst']:
    if p['score'] == 0:
        continue
    for t in p['tabs']:
        if t['en'] == t['ar']:
            continue
        n += 1
        verdict = 'repairs it: ' + '; '.join(t.get('changes') or []) if t.get('changes') else ('leaves it: ' + (t.get('problem') or 'list sizes only'))
        print(f"| {n} | [{p['slug']}](https://kbeautybliss.com/ar/product/{p['slug']}/) | {p['score']} | {t['title']} | `{t['en']}` | `{t['ar']}` | {verdict} |")
    if n >= 20:
        break
print()
rec = a['recent']
bad = [p for p in rec if any(t['en'] != t['ar'] for t in p['tabs'])]
print(f"### The 50 newest products\n\n{len(rec)} of the 50 compared; {len(bad)} with a body whose lines differ from the English:\n")
for p in bad:
    for t in p['tabs']:
        if t['en'] != t['ar']:
            print(f"- {p['slug']} ({t['title']}): EN `{t['en']}` / AR `{t['ar']}` -> {('repaired: ' + '; '.join(t['changes'])) if t.get('changes') else ('left: ' + (t.get('problem') or 'list sizes only'))}")
