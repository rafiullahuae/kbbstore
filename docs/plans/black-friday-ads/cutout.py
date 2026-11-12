"""Clean product cut-outs for the Black Friday ads (owner, 12 Nov 2026: "make it
super clean trip, no edges or low quality").

GrabCut separates the product from its plain studio background (works for white
products on white too, where a colour threshold leaks); the outline is then
pulled in ~1px and softened, and the background's colour is taken back out of
the edge pixels, so no light fringe is left on a dark or coloured ad.

  python3 cutout.py   -> assets/<key>.webp
"""
import cv2, numpy as np, os
D = os.path.dirname(os.path.abspath(__file__))

def bg_colour(img):
    h, w = img.shape[:2]
    s = max(4, min(h, w) // 40)
    corners = np.concatenate([img[:s, :s].reshape(-1, 3), img[:s, -s:].reshape(-1, 3), img[-s:, :s].reshape(-1, 3), img[-s:, -s:].reshape(-1, 3)])
    return np.median(corners, axis=0)

def cut(img, coloured=False):
    h, w = img.shape[:2]
    bg = bg_colour(img)
    diff = np.abs(img.astype(np.int16) - bg.astype(np.int16)).max(axis=2)
    ys, xs = np.where(diff > 10)
    pad = int(min(h, w) * .02)
    x0, y0, x1, y1 = max(xs.min() - pad, 1), max(ys.min() - pad, 1), min(xs.max() + pad, w - 2), min(ys.max() + pad, h - 2)
    mask = np.zeros((h, w), np.uint8)
    bgd, fgd = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    cv2.grabCut(img, mask, (x0, y0, x1 - x0, y1 - y0), bgd, fgd, 6, cv2.GC_INIT_WITH_RECT)
    fg = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
    # one solid product: the largest pieces, holes filled
    n, lab, stats, _ = cv2.connectedComponentsWithStats(fg, 8)
    if n > 1:
        big = 1 + np.argmax(stats[1:, cv2.CC_STAT_AREA])
        keep = [i for i in range(1, n) if stats[i, cv2.CC_STAT_AREA] >= stats[big, cv2.CC_STAT_AREA] * .03]
        fg = np.isin(lab, keep).astype(np.uint8) * 255
    cnts, _ = cv2.findContours(fg, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    solid = np.zeros_like(fg)
    cv2.drawContours(solid, cnts, -1, 255, -1)
    # THE EDGE BAND: inside a deep core GrabCut is trusted (a white tube's body stays);
    # in the outer band only pixels that really differ from the background stay, so no
    # white background clings to the outline.
    r = max(6, min(h, w) // 90)
    core = cv2.erode(solid, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1)))
    real = (cv2.GaussianBlur(diff.astype(np.float32), (0, 0), 1.2) > 7).astype(np.uint8) * 255
    # a product that is not white itself: the whole silhouette must differ from the background
    solid = cv2.bitwise_and(solid, real) if coloured else cv2.bitwise_or(core, cv2.bitwise_and(solid, real))
    cnts, _ = cv2.findContours(solid, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    big = max(cnts, key=cv2.contourArea)
    keep = [c for c in cnts if cv2.contourArea(c) >= cv2.contourArea(big) * .03]
    solid = np.zeros_like(fg)
    cv2.drawContours(solid, keep, -1, 255, -1)
    # A SMOOTH OUTLINE: blur the silhouette and cut it at the middle -- jagged steps go,
    # the product's own shape stays.
    sm = max(1.5, min(h, w) / 500)
    solid = (cv2.GaussianBlur(solid.astype(np.float32), (0, 0), sm) > 127).astype(np.uint8) * 255
    # pull the outline in ~1-2px, then a soft edge
    k = max(3, int(round(min(h, w) / 500)) * 2 + 1)
    solid = cv2.erode(solid, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (k, k)))
    a = cv2.GaussianBlur(solid.astype(np.float32) / 255, (0, 0), max(.8, min(h, w) / 1300))
    a = np.clip((a - .1) / .9, 0, 1)
    # take the background's colour back out of the edge pixels
    rgb = img.astype(np.float32)
    aa = np.maximum(a, 1e-3)[..., None]
    clean = np.clip((rgb - (1 - aa) * bg) / aa, 0, 255)
    clean = np.where(a[..., None] > .97, rgb, clean)
    out = np.dstack([clean, a * 255]).astype(np.uint8)
    ys, xs = np.where(a > .02)
    return out[ys.min():ys.max() + 1, xs.min():xs.max() + 1]

# ── 2nd pass (owner, 12 Nov: "hair oil has white edges ... anua cream has cut inner edge ... revise
# all the products deeply ... don't make any crop inside the product") ──────────────────────────────
# The silhouette is now what the BACKGROUND is not: a flood from the photo's border through pixels
# that match the backdrop, stopped by any real edge. Everything the flood cannot reach is product,
# holes filled, so a white tube on a white backdrop keeps every pixel of its own body. Then the
# edge pixels take their colour from INSIDE the product (not from an unmixing guess), so no trace of
# the white backdrop is left on the outline whatever the ad's colour.

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

def refine(rgba):
    """An existing transparent cut-out: same rim treatment, from its own alpha (no source photo kept)."""
    a = rgba[..., 3]
    solid = (a > 140).astype(np.uint8) * 255
    cnts, _ = cv2.findContours(solid, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    big = max(cv2.contourArea(c) for c in cnts)
    full = np.zeros_like(solid)
    cv2.drawContours(full, [c for c in cnts if cv2.contourArea(c) >= big * .03], -1, 255, -1)
    return finish(rgba[..., :3], full, inset=1.0)

def load_rgba(f):
    return cv2.cvtColor(cv2.imread(f, cv2.IMREAD_UNCHANGED), cv2.COLOR_BGRA2RGBA)

def save(key, rgba, maxside=900):
    h, w = rgba.shape[:2]
    s = min(1, maxside / max(h, w))
    if s < 1:
        rgba = cv2.resize(rgba, (int(w * s), int(h * s)), interpolation=cv2.INTER_AREA)
    cv2.imwrite(os.path.join(D, 'assets', key + '.webp'), cv2.cvtColor(rgba, cv2.COLOR_RGBA2BGRA), [cv2.IMWRITE_WEBP_QUALITY, 92])
    print(key, rgba.shape[1], 'x', rgba.shape[0])

def load(f):
    return cv2.cvtColor(cv2.imread(os.path.join(D, 'src', f), cv2.IMREAD_COLOR), cv2.COLOR_BGR2RGB)

if __name__ == '__main__':
    duo = load('medicube-balm-duo.webp'); h, w = duo.shape[:2]
    save('balmred', cut_outline(duo[:, : w // 2 + 20]))
    save('balmpink', cut_outline(duo[:, w // 2 - 20:]))
    save('celimax', cut_white(load('celimax-retinal-shot.webp'), mirror=True))
    da = load('dalba-first-spray-serum.webp'); h, w = da.shape[:2]
    save('dalba', cut_outline(da[int(h * .02):int(h * .995), int(w * .075):int(w * .925)]))   # inside its grey photo panel
    save('glowvanilla', cut_outline(load('medicube-glow-vanilla.webp')))
    save('anuacream', cut_white(load('anua-pdrn-cream.png')))
    save('hairoil', cut_outline(load('u1.png'), op=25))
    save('marymay', cut_white(load('u2.png')))
    # no source photo kept for these: the original transparent files, rim re-coloured from inside
    FG = os.path.join(D, '..', 'free-gifts-ad', 'assets')
    for k in ('guasha', 'eyemask'):
        save(k, refine(load_rgba(os.path.join(FG, k + '.webp'))))
    for k in ('anua', 'deodorant'):
        save(k, refine(load_rgba(os.path.join(os.environ.get('BF_PREV', os.path.join(D, 'assets')), k + '.webp'))))
