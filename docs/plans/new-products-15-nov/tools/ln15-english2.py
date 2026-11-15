import json, re, sys, collections
# Which products still read English on /ar: name / short / description, and other tabs that are not an INCI list.
d = json.load(open(sys.argv[1] + '/tabs.json'))
AR = re.compile(r'[؀-ۿ]')
def ar(s):
    return bool(AR.search(re.sub(r'<[^>]+>', '', s or '')))
main, tabs = [], collections.Counter()
tabrows = []
for slug, r in d.items():
    bad = []
    if not ar(r['ar_name']): bad.append('name')
    if r['ar_short'] and not ar(r['ar_short']): bad.append('short')
    if r['ar'] and not ar(r['ar'][0]['body']): bad.append('desc')
    if bad:
        main.append((slug, bad))
    for i, t in enumerate(r['ar'][1:], 1):
        en_title = r['en'][i]['title'] if i < len(r['en']) else '?'
        if not ar(t['body']):
            tabs[en_title] += 1
            if 'ngredient' not in en_title and 'INCI' not in en_title.upper():
                tabrows.append((slug, en_title, t['title'], re.sub(r'<[^>]+>', ' ', t['body'])[:90]))
print('name/short/description English:', len(main))
for m in main: print(' ', m)
print('English tab titles (untranslated bodies):', tabs.most_common(20))
print('non-ingredient English tabs:', len(tabrows))
for t in tabrows: print(' ', t)
