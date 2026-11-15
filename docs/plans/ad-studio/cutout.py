"""Product cut-outs for the Ad Studio's newest products (owner, 15 Nov 2026: "National Day ads ... include
minimum 4 or 5 latest products").

The cutting functions are copied verbatim from ../black-friday-ads/cutout.py (its 2nd pass: the flood
from the backdrop for products with a clear outline, GrabCut-whole-shape-then-peel for white tubes,
mirror for an upright symmetric tube, rim colour taken from inside the product). Read that file's notes.

  python3 cutout.py           -> assets/products/<key>.webp   (sources: assets/src/<shop slug>.webp)
  python3 cutout.py preview   -> also writes nd-check-<key>.png on dark + UAE red, full size, for inspection
"""
import cv2, numpy as np, os, sys
D = os.path.dirname(os.path.abspath(__file__))


def bg_colour(img):
    h, w = img.shape[:2]
    s = max(4, min(h, w) // 40)
    corners = np.concatenate([img[:s, :s].reshape(-1, 3), img[:s, -s:].reshape(-1, 3), img[-s:, :s].reshape(-1, 3), img[-s:, -s:].reshape(-1, 3)])
    return np.median(corners, axis=0)

def silhouette(img, tol=9):
    h, w = img.shape[:2]
    bg = bg_colour(img)
    diff = cv2.GaussianBlur(np.abs(img.astype(np.int16) - bg.astype(np.int16)).max(axis=2).astype(np.float32), (0, 0), .8)
    wall = (diff > tol).astype(np.uint8)
    # seal hair-line gaps in a pale outline so the flood cannot leak inside
    wall = cv2.morphologyEx(wall, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5)))
    pad = np.pad(1 - wall, 1, constant_values=1).astype(np.uint8)
    ff = np.zeros((h + 4, w + 4), np.uint8)
    cv2.floodFill(pad, ff, (0, 0), 2)
    back = (pad[1:-1, 1:-1] == 2)
    prod = (~back).astype(np.uint8) * 255
    # the pixels the closing added sat on the backdrop side: give the flood its true edge back
    prod = cv2.bitwise_and(prod, cv2.dilate((diff > tol * .6).astype(np.uint8) * 255, np.ones((3, 3), np.uint8)))
    n, lab, st, _ = cv2.connectedComponentsWithStats(prod, 8)
    big = 1 + np.argmax(st[1:, cv2.CC_STAT_AREA])
    keep = [i for i in range(1, n) if st[i, cv2.CC_STAT_AREA] >= st[big, cv2.CC_STAT_AREA] * .03]
    prod = np.isin(lab, keep).astype(np.uint8) * 255
    cnts, _ = cv2.findContours(prod, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    solid = np.zeros_like(prod)
    cv2.drawContours(solid, cnts, -1, 255, -1)          # holes filled: nothing inside is ever cut
    sm = max(1.2, min(h, w) / 700)
    return (cv2.GaussianBlur(solid.astype(np.float32), (0, 0), sm) > 127).astype(np.uint8) * 255

def finish(rgb, solid, inset=1.5, soft=.9):
    """Alpha from the silhouette pulled in by `inset` px; colour of the outer rim taken from inside."""
    rgb = rgb.astype(np.float32)
    dist = cv2.distanceTransform((solid > 0).astype(np.uint8), cv2.DIST_L2, 5)
    a = np.clip((dist - inset) / soft + .5, 0, 1)
    a = cv2.GaussianBlur(a, (0, 0), .5)
    inner = (dist > inset + 2.5).astype(np.float32)        # pixels that are certainly product
    col, m = rgb * inner[..., None], inner.copy()
    for r in (2, 4, 8):                                      # spread the inside colour outwards
        k = (0, 0)
        cb, mb = cv2.GaussianBlur(col, k, r), cv2.GaussianBlur(m, k, r)
        fill = cb / np.maximum(mb, 1e-4)[..., None]
        need = (m < .5)[..., None]
        col = np.where(need & (mb[..., None] > 1e-3), fill, col); m = np.maximum(m, (mb > 1e-3).astype(np.float32))
    rim = (dist <= inset + 2.5)[..., None]
    out = np.where(rim, col, rgb)
    rgba = np.dstack([np.clip(out, 0, 255), a * 255]).astype(np.uint8)
    ys, xs = np.where(a > .02)
    return rgba[ys.min():ys.max() + 1, xs.min():xs.max() + 1]

def grab(img):
    """GrabCut's product, holes filled -- its SHAPE is trusted whole (the old edge-band test that
    took white tube for backdrop is gone)."""
    h, w = img.shape[:2]
    bg = bg_colour(img)
    diff = np.abs(img.astype(np.int16) - bg.astype(np.int16)).max(axis=2)
    ys, xs = np.where(diff > 10)
    pad = int(min(h, w) * .02)
    x0, y0, x1, y1 = max(xs.min() - pad, 1), max(ys.min() - pad, 1), min(xs.max() + pad, w - 2), min(ys.max() + pad, h - 2)
    mask = np.zeros((h, w), np.uint8)
    bgd, fgd = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, (x0, y0, x1 - x0, y1 - y0), bgd, fgd, 8, cv2.GC_INIT_WITH_RECT)
    fg = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
    n, lab, st, _ = cv2.connectedComponentsWithStats(fg, 8)
    big = 1 + np.argmax(st[1:, cv2.CC_STAT_AREA])
    fg = np.isin(lab, [i for i in range(1, n) if st[i, cv2.CC_STAT_AREA] >= st[big, cv2.CC_STAT_AREA] * .03]).astype(np.uint8) * 255
    cnts, _ = cv2.findContours(fg, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    solid = np.zeros_like(fg)
    cv2.drawContours(solid, cnts, -1, 255, -1)
    return solid

def cut_outline(img, tol=20, op=0):
    """A product with a clear outline (coloured, glossy, dark): traced from the backdrop's side, so the
    faint off-white blotches some photos carry around the product are never kept."""
    shape = silhouette(img, tol=tol)
    if op:   # a thin floor reflection under the product (hair oil) goes; the product has nothing that thin
        shape = cv2.morphologyEx(shape, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (op, op)))
    return finish(img, shape)

def cut_white(img, peel=10, tol=10, op=13, mirror=False):
    """A white tube on a white backdrop: GrabCut's whole shape, holes filled; only its outer `peel` px
    may go, and only where those pixels ARE backdrop. Nothing deeper is ever removed."""
    g = grab(img)
    bg = bg_colour(img)
    diff = np.abs(img.astype(np.int16) - bg.astype(np.int16)).max(axis=2)
    d = cv2.distanceTransform((g > 0).astype(np.uint8), cv2.DIST_L2, 5)
    shape = np.where((d <= peel) & (diff <= tol), 0, g).astype(np.uint8)
    # A peel can only open NOTCHES no deeper than itself: close them again with a disc wider than the
    # peel, never past GrabCut's outline -- loose backdrop bits stay gone, the tube's own edge comes back.
    r = peel + 3
    shape = cv2.bitwise_and(cv2.morphologyEx(shape, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1))), g)
    # and thin strips of tinted JPEG noise standing OUT from the edge (narrower than ~13px) go
    shape = cv2.morphologyEx(shape, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (op, op)))
    if mirror:
        # an upright, symmetric tube: keep only what its two sides agree on -- a lobe of backdrop
        # noise on one side has no twin on the other (celimax: a tinted strip beside its label)
        ys, xs = np.where(shape > 0)
        low = ys > ys.max() - (ys.max() - ys.min()) * .08
        axis = (xs[low].min() + xs[low].max()) / 2
        M = np.float32([[-1, 0, 2 * axis], [0, 1, 0]])
        shape = cv2.bitwise_and(shape, cv2.warpAffine(shape, M, (shape.shape[1], shape.shape[0]), flags=cv2.INTER_NEAREST))
    sm = max(1.2, min(img.shape[:2]) / 700)
    shape = (cv2.GaussianBlur(shape.astype(np.float32), (0, 0), sm) > 127).astype(np.uint8) * 255
    return finish(img, shape)


def load(slug):
    return cv2.cvtColor(cv2.imread(os.path.join(D, 'assets', 'src', slug + '.webp'), cv2.IMREAD_COLOR), cv2.COLOR_BGR2RGB)


def save(key, rgba, maxside=900):
    h, w = rgba.shape[:2]
    s = min(1, maxside / max(h, w))
    if s < 1:
        rgba = cv2.resize(rgba, (int(w * s), int(h * s)), interpolation=cv2.INTER_AREA)
    cv2.imwrite(os.path.join(D, 'assets', 'products', key + '.webp'), cv2.cvtColor(rgba, cv2.COLOR_RGBA2BGRA), [cv2.IMWRITE_WEBP_QUALITY, 92])
    print(key, rgba.shape[1], 'x', rgba.shape[0])
    return rgba


def preview(key, rgba, out):
    """the cut-out on near-black and on UAE red, side by side, at full size"""
    h, w = rgba.shape[:2]
    a = rgba[..., 3:4].astype(np.float32) / 255
    tiles = []
    for c in ((21, 21, 21), (239, 51, 64)):
        bg = np.full((h, w, 3), c, np.float32)
        tiles.append((rgba[..., :3] * a + bg * (1 - a)).astype(np.uint8))
    cv2.imwrite(out, cv2.cvtColor(np.hstack(tiles), cv2.COLOR_RGB2BGR))


# Not used: vt-pdrn-reedle-shot-brush-hair-serum (the newest) -- its white bristles stand on a pale grey
# disc; outline mode leaves them ragged and white mode keeps wedges of the disc. Left out rather than
# shipped with a dirty edge.
# key -> (shop slug, how). Outline mode for products with a clear coloured edge; white mode for a
# white tube on a white backdrop (anua eye cream: upright and symmetric, so mirrored too).
JOBS = {
    'anuaeye': ('anua-pdrn-caffeine-rejuvenating-eye-cream', lambda im: cut_white(im, mirror=True)),
    # its white pump on a white backdrop: outline mode chopped the nozzle off, so white mode with a light
    # opening (op=5) -- the nozzle is thinner than the default 13 px
    'eospeach': ('eos-shea-better-24h-moisture-body-lotion-jasmine-peach', lambda im: cut_white(im, op=5)),
    'eosbutter': ('eos-shea-better-cashmere-whipped-oil-body-butter-vanilla', lambda im: cut_outline(im)),
    'bdeye': ('biodance-collagen-peptide-eye-cream', lambda im: cut_outline(im)),
    'bdserum': ('biodance-rejuvenating-caviar-pdrn-serum', lambda im: cut_outline(im)),
    'azelaic': ('medicube-azelaic-acid-niacinamide-deep-clean-foam-cleanser', lambda im: cut_outline(im)),
    'nbz9': ('numbuzin-no-9-nad-peptides-dewy-sun-essence', lambda im: cut_outline(im)),
    'nbz5': ('numbuzin-no-5-glutathione-vitamin-concentrated-mask-set-5-sheets', lambda im: cut_outline(im)),
}

if __name__ == '__main__':
    only = [a for a in sys.argv[1:] if a in JOBS]
    for key, (slug, how) in JOBS.items():
        if only and key not in only:
            continue
        r = save(key, how(load(slug)))
        if 'preview' in sys.argv:
            preview(key, r, os.path.join(sys.argv[sys.argv.index('preview') + 1] if len(sys.argv) > sys.argv.index('preview') + 1 and os.path.isdir(sys.argv[sys.argv.index('preview') + 1]) else D, 'nd-check-' + key + '.png'))
