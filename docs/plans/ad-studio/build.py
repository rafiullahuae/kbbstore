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
for k, v in art.items():
    assert '<script' not in v.lower() and 'href="http' not in v and 'on' + 'load' not in v.lower(), k
ARTPACK = base64.b64encode(gzip.compress(json.dumps(art, separators=(',', ':'), ensure_ascii=False).encode(), 9, mtime=0)).decode()
DATA = json.dumps({'prods': prods, 'pay': pay, 'kits': kits, 'art': ARTPACK}, ensure_ascii=False).replace('</', '<\\/')


def lib(n):
    return open(os.path.join(D, 'vendor', n)).read()


def safe(js, what):
    assert '</script' not in js.lower(), what + ' would end its <script> early'
    return js


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
