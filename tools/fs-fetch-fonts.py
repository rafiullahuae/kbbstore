#!/usr/bin/env python3
"""
Fetch the font library ONCE from Google's own css2 API (Lane FS).

Writes resources/fonts/lib/<slug>/<file>.woff2 -- Google's files, unchanged --
and prints the face table App\\Support\\FontLibrary carries as JSON. Run it
again only to refresh the files; the shop never talks to Google.

    python3 tools/fs-fetch-fonts.py > storage/fs-logs/faces.json

Only the `latin` subset is kept, plus `arabic` for an Arabic family: latin-ext,
cyrillic, greek and vietnamese are glyphs this shop does not print.
"""
import json, os, re, sys, urllib.request, urllib.error

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'resources', 'fonts', 'lib')

# family, slug, kind, static weights (None = variable, wght axis)
FAMILIES = [
    ('Inter', 'inter', 'sans', None), ('DM Sans', 'dm-sans', 'sans', None),
    ('Manrope', 'manrope', 'sans', None), ('Plus Jakarta Sans', 'plus-jakarta-sans', 'sans', None),
    ('Montserrat', 'montserrat', 'sans', None), ('Nunito Sans', 'nunito-sans', 'sans', None),
    ('Work Sans', 'work-sans', 'sans', None), ('Figtree', 'figtree', 'sans', None),
    ('Raleway', 'raleway', 'sans', None), ('Poppins', 'poppins', 'sans', [400, 600, 700]),
    ('Playfair Display', 'playfair-display', 'serif', None), ('Cormorant Garamond', 'cormorant-garamond', 'serif', None),
    ('Lora', 'lora', 'serif', None), ('Fraunces', 'fraunces', 'serif', None),
    ('DM Serif Display', 'dm-serif-display', 'serif', [400]), ('Libre Baskerville', 'libre-baskerville', 'serif', None),
    ('Marcellus', 'marcellus', 'serif', [400]), ('Bodoni Moda', 'bodoni-moda', 'serif', None),
    ('EB Garamond', 'eb-garamond', 'serif', None),
    ('Great Vibes', 'great-vibes', 'script', [400]), ('Dancing Script', 'dancing-script', 'script', None),
    ('Allura', 'allura', 'script', [400]),
    ('Tajawal', 'tajawal', 'arabic', [400, 700]), ('Almarai', 'almarai', 'arabic', [400, 700]),
    ('IBM Plex Sans Arabic', 'ibm-plex-sans-arabic', 'arabic', [400, 700]),
    ('Noto Kufi Arabic', 'noto-kufi-arabic', 'arabic', None), ('Readex Pro', 'readex-pro', 'arabic', None),
]
RANGES = ['100..900', '200..900', '300..900', '100..800', '200..800', '300..800', '400..900',
          '100..700', '200..700', '300..700', '400..800', '400..700', '160..700', '500..900', '400..600']


def get(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read()


def css(family, spec):
    q = family.replace(' ', '+')
    try:
        return get(f'https://fonts.googleapis.com/css2?family={q}:wght@{spec}&display=swap').decode()
    except urllib.error.HTTPError:
        return None


out = []
for family, slug, kind, static in FAMILIES:
    body = None
    if static is None:
        for r in RANGES:
            body = css(family, r)
            if body:
                break
    if body is None:
        static = static or [400, 700]
        body = css(family, ';'.join(map(str, static)))
    if body is None:
        sys.exit(f'no css2 answer for {family}')
    keep = ('latin', 'arabic') if kind == 'arabic' else ('latin',)
    faces = []
    for m in re.finditer(r'/\* ([a-z-]+) \*/\s*@font-face \{(.*?)\}', body, re.S):
        subset, block = m.group(1), m.group(2)
        if subset not in keep:
            continue
        weight = re.search(r'font-weight: ([0-9 ]+);', block).group(1).strip()
        url = re.search(r'src: url\((.*?)\)', block).group(1)
        rng = re.search(r'unicode-range: (.*?);', block).group(1)
        fname = f'{slug}-{subset}' + ('' if ' ' in weight else f'-{weight}') + '.woff2'
        os.makedirs(os.path.join(ROOT, slug), exist_ok=True)
        path = os.path.join(ROOT, slug, fname)
        data = get(url)
        open(path, 'wb').write(data)
        faces.append({'subset': subset, 'weight': weight, 'file': fname, 'range': rng, 'bytes': len(data)})
    out.append({'family': family, 'slug': slug, 'kind': kind, 'faces': faces})
    print(family, [(f['subset'], f['weight'], f['bytes']) for f in faces], file=sys.stderr)

print(json.dumps(out, indent=1))
