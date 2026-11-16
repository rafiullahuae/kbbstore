# Planet Careers -- every logo file in this folder, made from the company's own logo (16 Nov 2026).
#   python3 -I source/make-logos.py        (run from brands/planet-careers; needs numpy, opencv, Pillow, potrace)
#
# source/site-logo.webp is the logo exactly as theplanetcareers.com serves it (1600x666 on white; the
# owner: "for the branding colors of careers company, u can take from here https://theplanetcareers.com/").
# Nothing is redrawn: every pixel is unmixed into the logo's two inks over the white paper (least squares),
# so the anti-aliased edges become alpha and no white halo is left (the white gap between the bull and the
# "A" becomes transparent, as it is in the artwork). The SVGs are the same inks traced with potrace.
#
#   logo.png / logo.svg               the full logo, transparent (navy + red)            -- light grounds
#   logo-white.png / logo-white.svg   navy -> white, red kept                            -- dark grounds
#   logo-mark.png / logo-mark.svg     the mark: the "A" with the bull's head (navy + red)
#   logo-mark-white.png / .svg        the mark, white "A" + red bull
#   logo-app-icon.png / .svg          the mark on a navy square (white "A", red bull)
#   logo-app-icon-light.png / .svg    the mark on a white square
#   favicon.png                       the site's own favicon, as served (32x32)
import json, os, re, subprocess, sys, tempfile
import numpy as np, cv2
from PIL import Image

D = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TMP = tempfile.mkdtemp(prefix='pc-logo-')
a = np.array(Image.open(os.path.join(D, 'source', 'site-logo.webp')).convert('RGB')).astype(np.float64)
PAPER = np.array([254., 254., 254.])
NAVY, RED, WHITE = np.array([2., 52., 105.]), np.array([199., 11., 35.]), np.array([255., 255., 255.])   # the logo's own inks (medians)
NH, RH = '#023469', '#C70B23'
BG_NAVY = '#002768'                     # the site's primary (Elementor global), for the app icon square

x, *_ = np.linalg.lstsq(np.stack([NAVY - PAPER, RED - PAPER], 1), (a.reshape(-1, 3) - PAPER).T, rcond=None)
an, ar = np.clip(x[0], 0, 1), np.clip(x[1], 0, 1)
s = an + ar; k = np.where(s > 1, 1 / np.maximum(s, 1e-9), 1); an, ar = an * k, ar * k
H0, W0 = a.shape[:2]
an, ar = an.reshape(H0, W0), ar.reshape(H0, W0)
snap = lambda v: np.clip((v - .06) / .88, 0, 1)      # compression noise: near-0 -> 0, near-1 -> 1
an, ar = snap(an), snap(ar)


def bbox(m, pad):
    ys, xs = np.where(m > .02)
    return max(ys.min() - pad, 0), ys.max() + pad + 1, max(xs.min() - pad, 0), xs.max() + pad + 1


def rgba(an, ar, c1, c2):
    al = np.clip(an + ar, 0, 1); d = np.maximum(an + ar, 1e-9)
    rgb = (an[..., None] * c1 + ar[..., None] * c2) / d[..., None]
    return Image.fromarray(np.dstack([np.clip(rgb, 0, 255), al * 255]).round().astype(np.uint8), 'RGBA')


def trace(alpha, sc=4):
    """potrace one ink's coverage (cubic-upsampled sc times, cut at 50 %) -> (path data, ty, sx, sy, sc)"""
    big = cv2.resize(alpha.astype(np.float32), None, fx=sc, fy=sc, interpolation=cv2.INTER_CUBIC)
    pbm = os.path.join(TMP, 'm.pbm'); Image.fromarray(np.where(big >= .5, 0, 255).astype(np.uint8)).convert('1').save(pbm)
    out = os.path.join(TMP, 'm.svg')
    subprocess.run(['potrace', pbm, '-s', '-o', out, '-t', '6', '-a', '1.0', '-O', '0.4', '-u', '1', '--flat'], check=True)
    t = open(out).read()
    tf = re.search(r'<g transform="translate\(([\d.]+),([\d.]+)\) scale\(([\d.-]+),([\d.-]+)\)"', t).groups()
    return ' '.join(re.findall(r' d="([^"]+)"', t)), float(tf[1]), float(tf[2]), float(tf[3]), sc


def num(v):
    s = '%.2f' % v
    s = s.rstrip('0').rstrip('.') if '.' in s else s
    return '0' if s in ('-0', '') else s


def bake(tr, ox=0., oy=0.):
    """the traced path with potrace's transform (and an offset of our own) baked into the numbers, so it draws as
    is -- in an <svg>, or in Path2D from kit.json markPaths"""
    d, ty, sx, sy, sc = tr
    sx, sy, ty = sx / sc, sy / sc, ty / sc + oy
    out = []
    for cmd, args in re.findall(r'([MmLlCcZz])([^MmLlCcZz]*)', d):
        if cmd in 'Zz': out.append('z'); continue
        v = [float(n) for n in re.findall(r'-?\d+(?:\.\d+)?', args)]
        pts = []
        for i in range(0, len(v), 2):
            pts += [num(ox + sx * v[i]), num(ty + sy * v[i + 1])] if cmd.isupper() else [num(sx * v[i]), num(sy * v[i + 1])]
        out.append(cmd + ' '.join(pts))
    return ''.join(out).replace(' -', '-')


def svg(w, h, layers, bg='', vb=None):
    vb = vb or (0, 0, w, h)
    body = ''.join('<path fill="%s" d="%s"/>' % (fl, d) for d, fl in layers)
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%d" height="%d">%s%s</svg>\n' % (tuple(num(x) for x in vb) + (round(w), round(h), bg, body))


def write(name, txt): open(os.path.join(D, name), 'w').write(txt)


# ── the full logo
y0, y1, x0, x1 = bbox(an + ar, 10)
fn, fr = an[y0:y1, x0:x1], ar[y0:y1, x0:x1]; H, W = fn.shape
dn, dr = bake(trace(fn)), bake(trace(fr))
write('logo.svg', svg(W, H, [(dn, NH), (dr, RH)]))
write('logo-white.svg', svg(W, H, [(dn, '#FFFFFF'), (dr, RH)]))
rgba(fn, fr, NAVY, RED).save(os.path.join(D, 'logo.png'), optimize=True)
rgba(fn, fr, WHITE, RED).save(os.path.join(D, 'logo-white.png'), optimize=True)
print('logo', W, 'x', H)

# ── the mark: the "A" of PLANET with the bull's head in it. The A's pieces are the navy shapes (solid ink) that
#    share columns with the bull; every other pixel goes to whichever letter's solid ink is nearer (the A's right
#    foot touches the N), and nothing of CAREERS (below the PLANET row) is kept.
cut = int(H * .62)
solid = cv2.connectedComponentsWithStats((fn[:cut] > .95).astype(np.uint8), 4)
bx = np.where((fr[:cut] > .5).any(0))[0]; b0, b1 = bx.min(), bx.max()
mine = np.zeros((cut, W), np.uint8); other = np.zeros((cut, W), np.uint8)
for i in range(1, solid[0]):
    x_, y_, w_, h_, n_ = solid[2][i]
    if n_ < 200: continue
    (mine if x_ <= b1 and x_ + w_ >= b0 else other)[solid[1] == i] = 1
dm = cv2.distanceTransform(1 - mine, cv2.DIST_L2, 5); do = cv2.distanceTransform(1 - other, cv2.DIST_L2, 5)
keep = np.zeros((H, W), bool); keep[:cut] = (dm <= 6) & (dm < do)
mn = np.where(keep, fn, 0)
mr = np.zeros_like(fr); mr[:cut, b0 - 6:b1 + 7] = fr[:cut, b0 - 6:b1 + 7]     # the bull alone (no stray tint off the navy edges)
y0, y1, x0, x1 = bbox(mn + mr, 2)
mn, mr = mn[y0:y1, x0:x1], mr[y0:y1, x0:x1]; h, w = mn.shape
qn, qr = trace(mn), trace(mr)
en, er = bake(qn), bake(qr)
write('logo-mark.svg', svg(w, h, [(en, NH), (er, RH)]))
write('logo-mark-white.svg', svg(w, h, [(en, '#FFFFFF'), (er, RH)]))
rgba(mn, mr, NAVY, RED).save(os.path.join(D, 'logo-mark.png'), optimize=True)
rgba(mn, mr, WHITE, RED).save(os.path.join(D, 'logo-mark-white.png'), optimize=True)
# kit.json logos.markPaths: the mark as plain paths for the video renderer (app/auto/layers.js drawMark). The A is
# filled with the kit's primary, so the renderer turns it white on a brand-coloured card; the bull keeps its red.
kp = os.path.join(D, 'kit.json'); kit = json.load(open(kp))
kit['logos']['markPaths'] = {'viewBox': [w, h], 'paths': [{'d': en, 'fill': kit['colors']['primary']}, {'d': er, 'fill': RH}]}
open(kp, 'w').write(json.dumps(kit, indent=1, ensure_ascii=False) + '\n')
print('mark', w, 'x', h)

# ── app icons: the mark centred on a square, 72% of the side (1024 px PNG + SVG)
S = max(w, h) / .72; ox, oy = (S - w) / 2, (S - h) / 2 + h * .01
for name, bgc, c1, ink1 in (('logo-app-icon', BG_NAVY, '#FFFFFF', WHITE), ('logo-app-icon-light', '#FFFFFF', NH, NAVY)):
    write(name + '.svg', svg(1024, 1024, [(bake(qn, ox, oy), c1), (bake(qr, ox, oy), RH)], '<rect width="%s" height="%s" fill="%s"/>' % (num(S), num(S), bgc), (0, 0, S, S)))
    f = 1024 / S; sz = (round(w * f), round(h * f))
    ink = rgba(cv2.resize(mn.astype(np.float32), sz, interpolation=cv2.INTER_CUBIC).clip(0, 1), cv2.resize(mr.astype(np.float32), sz, interpolation=cv2.INTER_CUBIC).clip(0, 1), ink1, RED)
    im = Image.new('RGBA', (1024, 1024), bgc); im.alpha_composite(ink, (round(ox * f), round(oy * f))); im.convert('RGB').save(os.path.join(D, name + '.png'), optimize=True)
print('app icons 1024')
