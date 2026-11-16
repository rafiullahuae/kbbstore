# KBB Ad Studio -- builds ONE self-contained offline HTML file:
#   python3 build.py  ->  ad-studio.html (not committed) + dist/ad-studio.html (committed: the owner's file)
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
for k, v in art.items():
    assert '<script' not in v.lower() and 'href="http' not in v and 'on' + 'load' not in v.lower(), k
ARTPACK = base64.b64encode(gzip.compress(json.dumps(art, separators=(',', ':'), ensure_ascii=False).encode(), 9, mtime=0)).decode()
DATA = json.dumps({'prods': prods, 'pay': pay, 'kits': kits, 'art': ARTPACK}, ensure_ascii=False).replace('</', '<\\/')


def lib(n):
    return open(os.path.join(D, 'vendor', n)).read()


def safe(js, what):
    assert '</script' not in js.lower(), what + ' would end its <script> early'
    return js


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
    '/*TEMPLATES*/': safe(open(os.path.join(D, 'src', 'templates.js')).read().replace('/*PC*/', open(os.path.join(D, 'src', 'templates-pc.js')).read(), 1), 'templates'),
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
