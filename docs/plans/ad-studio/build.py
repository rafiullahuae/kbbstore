# KBB Ad Studio -- builds ONE self-contained offline HTML file:
#   python3 build.py  ->  ad-studio.html (not committed) + dist/ad-studio.html (committed: the owner's file)
#                     + studio/reel-studio/app/adstudio/ (the same files, inside KBB Studio)
#
# Inlined, so the file makes zero network requests: the fonts (fonts.py, cached in fontcache/), the three
# vendored libraries (html-to-image 1.11.11, JSZip 3.10.1, mp4-muxer 5.2.1 for the Video mode's MP4 export), the 20 product cut-outs (assets/products: 12
# copied from ../black-friday-ads/assets, 8 of the shop's newest cut by cutout.py), the tabby / tamara badges (resources/payment-logos), the
# templates (src/templates.js) and the editor (src/studio.js).
import base64, json, os, shutil, sys
from PIL import Image
D = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, D)
import fonts as _fonts

NAMES = {'guasha': 'medicube Gua Sha cream', 'anua': 'Anua mist mini', 'marymay': 'Mary&May PDRN serum', 'deodorant': 'medicube deodorant',
         'eyemask': 'medicube eye patches', 'balmred': 'medicube EGF balm (red)', 'balmpink': 'medicube PDRN balm (pink)',
         'celimax': 'celimax Retinal Shot', 'dalba': "d'Alba First Spray Serum", 'glowvanilla': 'medicube Glow Vanilla mist',
         'anuacream': 'Anua PDRN cream', 'hairoil': 'medicube Rosemary hair oil',
         # the shop's newest (15 Nov 2026, for the National Day ads) -- cut by cutout.py from assets/src/<slug>.webp
         'anuaeye': 'Anua PDRN Caffeine eye cream', 'eospeach': 'eos 24H Moisture lotion (jasmine peach)',
         'eosbutter': 'eos Cashmere whipped oil butter', 'bdeye': 'Biodance Collagen Peptide eye cream',
         'bdserum': 'Biodance Caviar PDRN serum', 'azelaic': 'medicube Azelaic Acid foam cleanser',
         'nbz9': 'numbuzin No.9 NAD+ sun essence', 'nbz5': 'numbuzin No.5 Glutathione mask set'}


def b64(path):
    return base64.b64encode(open(path, 'rb').read()).decode()


prods = {}
for p, n in NAMES.items():
    f = os.path.join(D, 'assets', 'products', p + '.webp')
    w, h = Image.open(f).size
    prods[p] = {'n': n, 'a': round(w / h, 4), 'src': 'data:image/webp;base64,' + b64(f)}
pay = {k: 'data:image/svg+xml;base64,' + b64(os.path.join(D, 'assets', 'pay', k + '-badge.svg')) for k in ('tabby', 'tamara')}
# Companies (16 Nov 2026): the two seed brand kits (copied from studio/reel-studio/brands/<slug>/kit.json) and the
# built-in art -- 33 flags (flag-icons, MIT), country outlines and landmark silhouettes (lane R3), the Planet Careers
# mark -- as one gzip + base64 pack the editor unpacks once at start (370 KB of SVG -> ~140 KB in the file).
import gzip, re
CO = os.path.join(D, 'assets', 'companies')
kits = {}
for slug in ('kbb', 'planet-careers'):
    k = json.load(open(os.path.join(CO, slug + '.kit.json')))
    for f in k.get('fonts', {}).values():
        f.pop('files', None)
    k.get('logos', {}).pop('markPaths', None)      # the video renderer's copy of the mark; this studio draws art:mark:*
    kits[slug] = k
def mini(svg):
    return re.sub(r'>\s+<', '><', svg.strip())
art = {}
for kind, sub in (('flag', 'flags'), ('map', 'maps'), ('lm', 'landmarks')):
    for f in sorted(os.listdir(os.path.join(CO, 'countries', sub))):
        if f.endswith('.svg'):
            art[kind + ':' + f[:-4]] = mini(open(os.path.join(CO, 'countries', sub, f)).read())
# Planet Careers: the company's own logo from theplanetcareers.com (studio/reel-studio/brands/planet-careers, made by its
# source/make-logos.py -- the site's logo unmixed from its white ground and traced, never redrawn). The mark is the "A"
# with the bull's head; -light = navy turned white for dark grounds (the red kept); -full = the whole logo, which the
# logo element draws instead of mark + typed name.
for name, f in (('pc', 'logo-mark'), ('pc-light', 'logo-mark-white'), ('pc-full', 'logo'), ('pc-full-light', 'logo-white')):
    art['mark:' + name] = mini(open(os.path.join(CO, f + '.svg')).read())
# Work Abroad (16 Nov 2026): the occupation library is studio/reel-studio/library/occupations (one source for both studios).
# Each occupation gets its placeholder worker figure in its own uniform colours (fig:<id>, composed by that folder's
# figures.py) and every tool icon (oi:<tool>, one colour, recoloured like the outlines); the templates get a compact list.
OCD = os.path.normpath(os.path.join(D, '..', '..', '..', 'studio', 'reel-studio', 'library', 'occupations'))
sys.path.insert(0, OCD)
import figures as _figs
OCJ = json.load(open(os.path.join(OCD, 'occupations.json')))
OCCS = []
for o in OCJ['occupations']:
    c = o['colors']
    art['fig:' + o['id']] = mini(_figs.figure(o['figure']['parts'], c['uniform'], c['trim'], c['ppe']))
    OCCS.append({'id': o['id'], 's': o['sector'], 't': [o['title']['en'], o['title']['ar']], 'l': o['inSentence'], 'pl': [o['plural']['en'], o['plural']['ar']],
                 'tools': [[t['id'], t['en'], t['ar']] for t in o['tools']], 'c': [c['uniform'], c['trim'], c['ppe'], c['accent']]})
for t in OCJ['tools']:
    art['oi:' + t['id']] = mini(open(os.path.join(OCD, t['icon'])).read())
OSECT = [[x['id'], x['en']] for x in OCJ['sectors']]
# National Day · Glass & Dew and Skyline Dusk (19 Nov 2026): the art the owner approved in the preview
# (shots/nd3/preview.html), drawn by the same seeded generators (bgDew seed 11, bgSky seed 5) so every drop, tower and
# spark sits where it did there. Each piece is its own layer in the templates: the dew drops, the skyline, and each of
# the six firework bursts (nd:fw-1..6, in the order the preview draws them). No real building; constant markup only.
def _rng(s):
    st = [s]
    def r():
        st[0] = (st[0] * 16807) % 2147483647
        return st[0] / 2147483647
    return r
def _n(x):
    return ('%.2f' % x).rstrip('0').rstrip('.')
def _dew(n, h):
    r, out = _rng(11), []
    for _ in range(n):
        x, y, rad = 9 + r() * 82, 32 + r() * h, .25 + r() ** 3 * 1.6
        out.append('<g transform="translate(%s %s)"><circle r="%s" fill="url(#dg)"/><circle cx="%s" cy="%s" r="%s" fill="#fff" opacity=".9"/></g>'
                   % (_n(x), _n(y), _n(rad), _n(-rad * .35), _n(-rad * .4), _n(rad * .28)))
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="8 %s 84 %s"><defs><radialGradient id="dg" cx=".6" cy=".65" r=".7"><stop offset="0" stop-color="#fff" stop-opacity=".9"/>'
            '<stop offset=".6" stop-color="#fff" stop-opacity=".25"/><stop offset="1" stop-color="#1a3a2a" stop-opacity=".25"/></radialGradient></defs>%s</svg>') % (_n(31), _n(h + 2), ''.join(out))
art['nd:dew'] = _dew(70, 56)            # the preview's own 70 drops (post); the story's taller pane takes nd:dew-tall
art['nd:dew-tall'] = _dew(100, 80)
_r, _city, _x = _rng(5), '', 0
while _x < 100:
    w, h = 2.5 + _r() * 5, 6 + _r() ** 2 * 22
    _city += '<rect x="%s" y="%s" width="%s" height="%s" fill="#8a78b4" opacity="%s"/>' % (_n(_x), _n(78 - h), _n(w), _n(h + 30), _n(.28 + _r() * .14))
    if _r() > .78:
        _city += '<path d="M%s %s L%s %s L%s %sZ" fill="#7d6aa6" opacity=".55"/>' % (_n(_x + w / 2 - .4), _n(78 - h), _n(_x + w / 2), _n(78 - h - 6 - _r() * 8), _n(_x + w / 2 + .4), _n(78 - h))
    k = 0
    while k < h / 3:
        if _r() > .55:
            _city += '<rect x="%s" y="%s" width=".5" height=".8" fill="#ffe9c7" opacity=".7"/>' % (_n(_x + .6 + _r() * (w - 1.6)), _n(78 - h + 1.5 + k * 3))
        k += 1
    _x += w + .4 + _r() * 1.6
art['nd:skyline'] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 34 100 74" preserveAspectRatio="none">' + _city + '</svg>'
import math
ND_FW = [[10, 12, 9], [90, 10, 10], [6, 46, 6], [94, 44, 7], [80, 60, 5], [18, 62, 5]]   # [cx, cy, R] in the preview's 100 x 125 frame
for _bi, (cx, cy, R) in enumerate(ND_FW):
    c, g = ['#00a34f', '#e2304d', '#ffffff', '#f3d38a'][_bi % 4], ''
    for i in range(26):
        a, r1, r2 = (i / 26) * math.pi * 2 + _r() * .1, R * (.35 + _r() * .15), R * (.85 + _r() * .2)
        g += '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width=".35" stroke-linecap="round" opacity=".85"/>' % (
            _n(cx + math.cos(a) * r1), _n(cy + math.sin(a) * r1), _n(cx + math.cos(a) * r2), _n(cy + math.sin(a) * r2), c)
        g += '<circle cx="%s" cy="%s" r=".45" fill="%s"/>' % (_n(cx + math.cos(a) * (r2 + 1)), _n(cy + math.sin(a) * (r2 + 1)), c)
    S = R * 1.05 + 3.3
    art['nd:fw-%d' % (_bi + 1)] = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s"><defs><filter id="glow" x="-20%%" y="-20%%" width="140%%" height="140%%"><feGaussianBlur stdDeviation=".6"/></filter></defs>'
                                   '<g filter="url(#glow)" opacity=".7">%s</g><g opacity=".9">%s</g></svg>') % (_n(cx - S), _n(cy - S), _n(2 * S), _n(2 * S), g, g)
for k, v in art.items():
    assert '<script' not in v.lower() and 'href="http' not in v and 'on' + 'load' not in v.lower(), k
ARTPACK = base64.b64encode(gzip.compress(json.dumps(art, separators=(',', ':'), ensure_ascii=False).encode(), 9, mtime=0)).decode()
DATA = json.dumps({'prods': prods, 'pay': pay, 'kits': kits, 'art': ARTPACK}, ensure_ascii=False).replace('</', '<\\/')


def lib(n):
    return open(os.path.join(D, 'vendor', n)).read()


def safe(js, what):
    assert '</script' not in js.lower(), what + ' would end its <script> early'
    return js


# ✂ Cut-out / background removal (18 Nov 2026): the video editor's own code, read from studio/reel-studio/app/look at build
# time and inlined (it is never copied by hand, so the two cannot drift): gl.js whole (the WebGL2 engine and its 'look'
# shader: matte refinement, chroma key, spill, light wrap), looks.js's neutral BASE, vision.js's v2 mask store and temporal
# filter (MASK_FPS, nearest, temporal, maskAt) and bg.js's BG_DEFAULTS / BG_KEYS / autoKeyColor. Each piece is cut out by
# its own markers; a marker that moves stops the build instead of shipping half a function.
LOOKD = os.path.normpath(os.path.join(D, '..', '..', '..', 'studio', 'reel-studio', 'app', 'look'))
def lsrc(n):
    return open(os.path.join(LOOKD, n)).read()
def cut(src, start, end, what):
    i = src.index(start); j = src.index(end, i)
    return src[i:j]
def unexport(js):
    assert not re.search(r'^\s*import\b', js, re.M), 'look code with an import cannot be inlined'
    return re.sub(r'^export (const|function|class|let) ', r'\1 ', js, flags=re.M)
_gl, _vi, _bg, _lk = lsrc('gl.js'), lsrc('vision.js'), lsrc('bg.js'), lsrc('looks.js')
LOOK = '\n'.join([
    '(function () {', "'use strict';", '// ── studio/reel-studio/app/look/gl.js ──', unexport(_gl),
    '// ── looks.js: BASE ──', unexport(cut(_lk, 'export const BASE = {', '\n};', 'BASE') + '\n};'),
    '// ── vision.js: v2 masks ──', unexport(cut(_vi, 'export const MASK_FPS', '\n', 'MASK_FPS')), unexport(cut(_vi, 'const MASKS = new Map();', '\n', 'MASKS')),
    unexport(cut(_vi, 'const nearest = ', '\n', 'nearest')), unexport(cut(_vi, '// ── v2 masks: temporal filter ──', 'export const hasMask', 'temporal')),
    '// ── bg.js ──', unexport(cut(_bg, 'export const BG_DEFAULTS', '\n', 'BG_DEFAULTS')), unexport(cut(_bg, 'export const BG_KEYS', '\n', 'BG_KEYS')),
    unexport(cut(_bg, 'export function autoKeyColor', '\n}\n', 'autoKeyColor') + '\n}'),
    # forget one mask track (a clip analysed again): its frames and every cached filtered frame of it
    'function drop(key) { MASKS.delete(key); for (const k of [...tCache.keys()]) if (k.startsWith(key + \':\')) tCache.delete(k); blend2.delete(key); }',
    'window.KBB_LOOK = { gl, blurs, BASE, MASKS, MASK_FPS, maskAt, drop, BG_DEFAULTS, BG_KEYS, autoKeyColor };',
    '})();'])
WA = open(os.path.join(D, 'src', 'templates-wa.js')).read()
assert WA.count('/*OCC*/[]') == 1 and WA.count('/*OSECT*/[]') == 1
WA = WA.replace('/*OCC*/[]', json.dumps(OCCS, ensure_ascii=False, separators=(',', ':'))).replace('/*OSECT*/[]', json.dumps(OSECT, separators=(',', ':')))
page = open(os.path.join(D, 'src', 'studio.html')).read()
app = open(os.path.join(D, 'src', 'studio.js')).read().replace('/*DATA*/', DATA)
parts = {
    '/*FONTS*/': _fonts.css(),
    '/*PRODUCT_CSS*/': '',
    '/*LIB_H2I*/': safe(lib('html-to-image-1.11.11.min.js'), 'html-to-image'),
    '/*LIB_ZIP*/': safe(lib('jszip-3.10.1.min.js'), 'JSZip'),
    '/*LIB_MP4*/': safe(lib('mp4-muxer-5.2.1.min.js'), 'mp4-muxer'),
    '/*VIDEO_CSS*/': open(os.path.join(D, 'src', 'video.css')).read(),
    '/*VIDEO_HTML*/': open(os.path.join(D, 'src', 'video.html')).read(),
    '/*VIDEO*/': safe(open(os.path.join(D, 'src', 'video.js')).read(), 'the video editor'),
    '/*LOOK*/': safe(LOOK, 'the look engine'),
    '/*CUTLOOK*/': safe(open(os.path.join(D, 'src', 'cutlook.js')).read(), 'the cut-out engine'),
    '/*KBBDISK*/': safe(open(os.path.join(D, 'src', 'kbbdisk.js')).read(), 'the KBB Studio storage'),
    '/*TEMPLATES*/': safe(open(os.path.join(D, 'src', 'templates.js')).read().replace('/*PC*/', open(os.path.join(D, 'src', 'templates-pc.js')).read() + '\n' + WA, 1), 'templates'),
    '/*APP*/': safe(app, 'the editor'),
}
for k, v in parts.items():
    assert page.count(k) == 1, k
    i = page.index(k)
    page = page[:i] + v + page[i + len(k):]      # plain splice: nothing in v is treated as a pattern
out = os.path.join(D, 'ad-studio.html')
open(out, 'w').write(page)
os.makedirs(os.path.join(D, 'dist'), exist_ok=True)
shutil.copyfile(out, os.path.join(D, 'dist', 'ad-studio.html'))
print('ad-studio.html', os.path.getsize(out) // 1024, 'KB')

# ✂ Cut out the person (Work Abroad photo slots): MediaPipe's selfie segmenter, vendored by KBB Studio
# (studio/reel-studio/vendor/mediapipe, Apache-2.0) -- packed into ad-studio-cutout.js, which sits NEXT TO the studio
# file and loads only on the first cut-out (≈4 MB that the studio itself never carries).
MP = os.path.normpath(os.path.join(D, '..', '..', '..', 'studio', 'reel-studio', 'vendor', 'mediapipe'))
def gz64(p):
    return base64.b64encode(gzip.compress(open(p, 'rb').read(), 9, mtime=0)).decode()
pack = {'bundle': gz64(os.path.join(MP, 'tasks-vision-0.10.21', 'vision_bundle.mjs')),
        'loader': gz64(os.path.join(MP, 'tasks-vision-0.10.21', 'wasm', 'vision_wasm_internal.js')),
        'wasm': gz64(os.path.join(MP, 'tasks-vision-0.10.21', 'wasm', 'vision_wasm_internal.wasm')),
        'model': gz64(os.path.join(MP, 'models', 'selfie_segmenter.tflite'))}
cut = open(os.path.join(D, 'src', 'cutout.js')).read()
assert cut.count('/*PACK*/{}') == 1
cut = cut.replace('/*PACK*/{}', json.dumps(pack, separators=(',', ':')))
cout = os.path.join(D, 'ad-studio-cutout.js')
open(cout, 'w').write(cut)
shutil.copyfile(cout, os.path.join(D, 'dist', 'ad-studio-cutout.js'))
shutil.copyfile(os.path.join(MP, 'LICENSE.txt'), os.path.join(D, 'dist', 'ad-studio-cutout.LICENSE.txt'))
print('ad-studio-cutout.js', os.path.getsize(cout) // 1024, 'KB')

# KBB Studio ships the same two files (18 Nov 2026: "i need also the Ads studio too"): studio/reel-studio/app/adstudio/,
# served by its engine at /app/adstudio/ad-studio.html, where src/kbbdisk.js saves to ~/KBB Studio Data/adstudio.
# Byte-identical copies of dist/ (git stores each once); tools/package-mac.sh takes them with app/.
KS = os.path.normpath(os.path.join(D, '..', '..', '..', 'studio', 'reel-studio', 'app', 'adstudio'))
os.makedirs(KS, exist_ok=True)
for f in ('ad-studio.html', 'ad-studio-cutout.js', 'ad-studio-cutout.LICENSE.txt'):
    shutil.copyfile(os.path.join(D, 'dist', f), os.path.join(KS, f))
print('copied into', os.path.relpath(KS, os.path.join(D, '..', '..', '..')))
