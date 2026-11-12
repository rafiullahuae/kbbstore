"""Fonts for the ad editor, embedded (owner, 12 Nov: "download ... in full HD quality").

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
       '&family=Bebas+Neue&family=DM+Serif+Display&family=Cairo:wght@400;700;900&display=swap')
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
    return '\n'.join(out)

if __name__ == '__main__':
    c = css(); print(len(c) // 1024, 'KB', c.count('@font-face'), 'faces')
