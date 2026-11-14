# KBB Ad Studio -- builds ONE self-contained offline HTML file:
#   python3 build.py  ->  ad-studio.html (not committed) + dist/ad-studio.html (committed: the owner's file)
#
# Inlined, so the file makes zero network requests: the fonts (fonts.py, cached in fontcache/), the three
# vendored libraries (html-to-image 1.11.11, JSZip 3.10.1, mp4-muxer 5.2.1 for the Video mode's MP4 export), the 12 product cut-outs (assets/products,
# copied from ../black-friday-ads/assets), the tabby / tamara badges (resources/payment-logos), the
# templates (src/templates.js) and the editor (src/studio.js).
import base64, json, os, shutil, sys
from PIL import Image
D = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, D)
import fonts as _fonts

NAMES = {'guasha': 'medicube Gua Sha cream', 'anua': 'Anua mist mini', 'marymay': 'Mary&May PDRN serum', 'deodorant': 'medicube deodorant',
         'eyemask': 'medicube eye patches', 'balmred': 'medicube EGF balm (red)', 'balmpink': 'medicube PDRN balm (pink)',
         'celimax': 'celimax Retinal Shot', 'dalba': "d'Alba First Spray Serum", 'glowvanilla': 'medicube Glow Vanilla mist',
         'anuacream': 'Anua PDRN cream', 'hairoil': 'medicube Rosemary hair oil'}


def b64(path):
    return base64.b64encode(open(path, 'rb').read()).decode()


prods = {}
for p, n in NAMES.items():
    f = os.path.join(D, 'assets', 'products', p + '.webp')
    w, h = Image.open(f).size
    prods[p] = {'n': n, 'a': round(w / h, 4), 'src': 'data:image/webp;base64,' + b64(f)}
pay = {k: 'data:image/svg+xml;base64,' + b64(os.path.join(D, 'assets', 'pay', k + '-badge.svg')) for k in ('tabby', 'tamara')}
DATA = json.dumps({'prods': prods, 'pay': pay}, ensure_ascii=False).replace('</', '<\\/')


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
    '/*TEMPLATES*/': safe(open(os.path.join(D, 'src', 'templates.js')).read(), 'templates'),
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
