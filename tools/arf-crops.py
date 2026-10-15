"""Lane ARF -- the report's pictures, cut from the crawl's full-page shots.

    python3 -I tools/arf-crops.py <shotsDir> <outDir> <before|after>

header (top of the homepage), home-top (hero, trust strip, bundles heading),
footer (bottom of the homepage), cart panel, cart page, checkout -- at 390 and
1280, as JPEG.
"""
import os
import sys

from PIL import Image

src, out, tag = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(out, exist_ok=True)
CUTS = {
    390: {'header': ('home', 0, 130), 'home-top': ('home', 0, 1500), 'footer': ('home', -1100, None)},
    1280: {'header': ('home', 0, 100), 'home-top': ('home', 0, 1250), 'footer': ('home', -700, None)},
}
for w, cuts in CUTS.items():
    for name, (page, y0, y1) in cuts.items():
        im = Image.open(os.path.join(src, f'{page}-{w}.png')).convert('RGB')
        h = im.height
        top = y0 if y0 >= 0 else h + y0
        im.crop((0, top, im.width, h if y1 is None else y1)).save(os.path.join(out, f'{tag}-{name}-{w}.jpg'), quality=82)
    for page in ('cart-panel', 'cart', 'checkout'):
        im = Image.open(os.path.join(src, f'{page}-{w}.png')).convert('RGB')
        im.save(os.path.join(out, f'{tag}-{page}-{w}.jpg'), quality=82)
print('ok')
