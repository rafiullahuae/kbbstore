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
    save('balmred', cut(duo[:, : w // 2 + 20]))
    save('balmpink', cut(duo[:, w // 2 - 20:]))
    save('celimax', cut(load('celimax-retinal-shot.webp')))
    da = load('dalba-first-spray-serum.webp'); h, w = da.shape[:2]
    save('dalba', cut(da[int(h * .02):int(h * .995), int(w * .075):int(w * .925)]))   # inside its grey photo panel
    save('glowvanilla', cut(load('medicube-glow-vanilla.webp'), coloured=True))
    save('anuacream', cut(load('anua-pdrn-cream.png')))
    save('hairoil', cut(load('u1.png')))
    save('marymay', cut(load('u2.png')))
