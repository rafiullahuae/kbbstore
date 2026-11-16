"""Fonts for Ad Studio, embedded so the file works offline and downloads draw in the right font.
(Copied from ../black-friday-ads/fonts.py; adds Dancing Script for the softer occasions.)

A downloaded picture is drawn by the browser from the page's own markup; a web font it cannot
reach is a different font in the file. So the editor carries its fonts inline (latin, plus arabic
for Cairo), and the same CSS is handed to the picture renderer. Fetched once into fontcache/.
"""
import os, re, base64, urllib.request, hashlib
D = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(D, 'fontcache')
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
URL = ('https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900'
       '&family=Poppins:ital,wght@0,400;0,600;0,700;0,800;0,900;1,700'
       '&family=Montserrat:ital,wght@0,400;0,600;0,800;0,900;1,800'
       '&family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,700'
       '&family=Bebas+Neue&family=DM+Serif+Display&family=Cairo:wght@400;700;900'
       '&family=Dancing+Script:wght@700&display=swap')
KEEP = {'latin', 'arabic'}

def _get(url):
    os.makedirs(CACHE, exist_ok=True)
    f = os.path.join(CACHE, hashlib.sha1(url.encode()).hexdigest()[:16])
    if not os.path.exists(f):
        req = urllib.request.Request(url, headers={'User-Agent': UA})
        with urllib.request.urlopen(req, timeout=30) as r:
            open(f, 'wb').write(r.read())
    return open(f, 'rb').read()

def css():
    src = _get(URL).decode()
    groups = {}
    for sub, body in re.findall(r'/\* ([a-z-]+) \*/\s*@font-face \{(.*?)\}', src, re.S):
        if sub not in KEEP:
            continue
        fam = re.search(r"font-family: '([^']+)'", body).group(1)
        sty = re.search(r'font-style: (\w+)', body).group(1)
        wt = int(re.search(r'font-weight: (\d+)', body).group(1))
        url = re.search(r'url\((https://[^)]+\.woff2)\)', body).group(1)
        rng = re.search(r'unicode-range: ([^;]+);', body).group(1)
        g = groups.setdefault((fam, sty, url), {'w': [], 'rng': rng})
        g['w'].append(wt)
    out = []
    for (fam, sty, url), g in groups.items():
        b64 = base64.b64encode(_get(url)).decode()
        w = f"{min(g['w'])} {max(g['w'])}" if len(set(g['w'])) > 1 else str(g['w'][0])
        out.append(f"@font-face{{font-family:'{fam}';font-style:{sty};font-weight:{w};font-display:block;"
                   f"src:url(data:font/woff2;base64,{b64}) format('woff2');unicode-range:{g['rng']}}}")
    return '\n'.join(out + _vendored())


# Afacad (Planet Careers' brand font, as on theplanetcareers.com), vendored offline from npm
# @fontsource-variable/afacad 5.3.0 (SIL OFL 1.1, vendor/fonts/OFL-afacad.txt): one variable file per subset, 400-700.
VENDORED = [('Afacad', '400 700', 'afacad-latin.woff2',
             'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'),
            ('Afacad', '400 700', 'afacad-latin-ext.woff2',
             'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF')]


def _vendored():
    out = []
    for fam, w, f, rng in VENDORED:
        b64 = base64.b64encode(open(os.path.join(D, 'vendor', 'fonts', f), 'rb').read()).decode()
        out.append(f"@font-face{{font-family:'{fam}';font-style:normal;font-weight:{w};font-display:block;"
                   f"src:url(data:font/woff2;base64,{b64}) format('woff2');unicode-range:{rng}}}")
    return out

if __name__ == '__main__':
    c = css(); print(len(c) // 1024, 'KB', c.count('@font-face'), 'faces')
