# cut3.py src out.png [key=value ...]
# tol=6     how far from pure white a background pixel may be (255-min channel)
# close=9   px (at source size) closing radius that repairs bites in the outline
# holes=1   fill enclosed background holes (0 keeps them, e.g. gaps between pads)
# grab=0    refine with OpenCV GrabCut seeded by the flood mask (white products)
# pull=1.0  px the edge is pulled in before feathering
# band=2    px rim whose colour is re-filled from inside (kills the white halo)
# minfrac=0.01 keep pieces at least this fraction of the largest
import sys
import numpy as np
import cv2

src, out = sys.argv[1], sys.argv[2]
o = dict(open=0, smooth=2.5, tol=6, close=9, holes=1, grab=0, pull=1.0, band=2, minfrac=0.01, edge=0)
for kv in sys.argv[3:]:
    k, v = kv.split('=')
    o[k] = float(v)

bgr = cv2.imread(src, cv2.IMREAD_COLOR)
h, w = bgr.shape[:2]
mn = bgr.min(axis=2).astype(np.int32)
cand = (255 - mn) <= o['tol']
if o['edge']:
    # an outline barrier: strong local gradient never counts as background,
    # so a white body with a faint grey outline cannot be flooded through
    g = cv2.cvtColor(bgr, cv2.COLOR_BGR2GRAY).astype(np.float32)
    gx = cv2.Sobel(g, cv2.CV_32F, 1, 0, ksize=3)
    gy = cv2.Sobel(g, cv2.CV_32F, 0, 1, ksize=3)
    mag = cv2.magnitude(gx, gy)
    cand &= mag < o['edge']
n, lab = cv2.connectedComponents(cand.astype(np.uint8), connectivity=4)
border = set(np.unique(np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]]))) - {0}
bg = np.isin(lab, list(border)) & cand
fg = (~bg).astype(np.uint8)

if o['close'] > 0:
    r = int(o['close'])
    k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1))
    fg = cv2.morphologyEx(fg, cv2.MORPH_CLOSE, k)
    k2 = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
    fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, k2)

if o['holes']:
    inv = (1 - fg).astype(np.uint8)
    n2, lab2 = cv2.connectedComponents(inv, connectivity=4)
    b2 = set(np.unique(np.concatenate([lab2[0], lab2[-1], lab2[:, 0], lab2[:, -1]]))) - {0}
    fg = np.where((inv == 1) & ~np.isin(lab2, list(b2)), 1, fg).astype(np.uint8)

if o['grab']:
    gm = np.full((h, w), cv2.GC_PR_BGD, np.uint8)
    gm[bg] = cv2.GC_BGD
    er = cv2.erode(fg, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (15, 15)))
    gm[fg == 1] = cv2.GC_PR_FGD
    gm[er == 1] = cv2.GC_FGD
    bm, fm = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    cv2.grabCut(bgr, gm, None, bm, fm, 4, cv2.GC_INIT_WITH_MASK)
    fg = np.isin(gm, (cv2.GC_FGD, cv2.GC_PR_FGD)).astype(np.uint8)

# keep the product, drop specks
n3, lab3, st, _ = cv2.connectedComponentsWithStats(fg, connectivity=8)
if n3 > 1:
    areas = st[1:, cv2.CC_STAT_AREA]
    keep = np.zeros(n3, bool)
    keep[1:] = areas >= areas.max() * o['minfrac']
    fg = keep[lab3].astype(np.uint8)

if o['open'] > 0:
    r = int(o['open'])
    fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1)))
if o['smooth'] > 0:
    fg = (cv2.GaussianBlur(fg.astype(np.float32), (0, 0), o['smooth']) > 0.5).astype(np.uint8)

# soft edge: signed distance to the outline, pulled in, ~1.4 px ramp
din = cv2.distanceTransform(fg, cv2.DIST_L2, 5)
dout = cv2.distanceTransform(1 - fg, cv2.DIST_L2, 5)
sd = np.where(fg == 1, din, -dout) - o['pull']
alpha = np.clip(0.5 + sd / 1.4, 0, 1)

# re-fill the rim's colour from inside so no white is mixed into it
bw = int(o['band'])
if bw > 0:
    band = ((din <= bw + o['pull'] + 1) & (fg == 1)) | ((alpha > 0) & (fg == 0))
    bgr = cv2.inpaint(bgr, band.astype(np.uint8) * 255, 3, cv2.INPAINT_TELEA)

rgba = np.dstack([bgr, (alpha * 255).round().astype(np.uint8)])
ys, xs = np.nonzero(alpha > 0.1)
rgba = rgba[ys.min():ys.max() + 1, xs.min():xs.max() + 1]
cv2.imwrite(out, rgba)
print(out, rgba.shape[1], 'x', rgba.shape[0], o)
