# Cut-out v2 (the owner, 6 Nov: "the trimming is not correctly, still white
# edges are there. plz revise on all products"). From the shop's FULL-SIZE
# photo, not a thumbnail:
#   1. flood the white background in from the edges (min channel >= thr);
#   2. in a thin band along that edge, turn white into transparency in
#      proportion to how white the pixel is (colour-to-alpha), so the
#      anti-aliased rim keeps its shape but loses its white;
#   3. take the white back out of the rim's colour (un-premultiply), so no
#      pale halo is left on a dark or coloured background;
#   4. pull the edge in by 1 px and feather it by half a pixel; crop.
import sys
import numpy as np
from collections import deque
from PIL import Image, ImageFilter

src, out = sys.argv[1], sys.argv[2]
thr = int(sys.argv[3]) if len(sys.argv) > 3 else 246
# 'white' for a white product: its own rim is near-white, so step 2 would eat
# into it; rely on the flood, the 1 px pull-in and the feather instead.
white_item = len(sys.argv) > 4 and sys.argv[4] == 'white'
im = Image.open(src).convert('RGB')
a = np.asarray(im).astype(np.float32)
h, w, _ = a.shape
mn = a.min(axis=2)
white = mn >= thr
bg = np.zeros((h, w), bool)
q = deque()
for x in range(w):
    for y in (0, h - 1):
        if white[y, x] and not bg[y, x]:
            bg[y, x] = True; q.append((y, x))
for y in range(h):
    for x in (0, w - 1):
        if white[y, x] and not bg[y, x]:
            bg[y, x] = True; q.append((y, x))
while q:
    y, x = q.popleft()
    for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
        ny, nx = y + dy, x + dx
        if 0 <= ny < h and 0 <= nx < w and not bg[ny, nx] and white[ny, nx]:
            bg[ny, nx] = True; q.append((ny, nx))

fg = Image.fromarray(np.where(bg, 0, 255).astype('uint8'))
band = np.asarray(fg.filter(ImageFilter.MinFilter(7))) < 128  # within ~3 px of the background
alpha = np.where(bg, 0.0, 1.0)
# colour-to-alpha in the band: pure white -> 0, 40 below white or darker -> 1
ca = np.clip((255.0 - mn) / 40.0, 0.0, 1.0)
if not white_item:
    alpha = np.where(band & ~bg, np.minimum(alpha, ca), alpha)
# un-premultiply the white out of the rim
A = alpha[..., None]
rgb = np.where(A > 0.02, (a - (1.0 - A) * 255.0) / np.maximum(A, 0.02), a)
rgb = np.clip(rgb, 0, 255)
al = Image.fromarray((alpha * 255).astype('uint8')).filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(0.6))
out_im = Image.fromarray(rgb.astype('uint8')).convert('RGBA')
out_im.putalpha(al)
# drop stray specks: keep only pieces of at least 0.5% of the largest piece
m = np.asarray(al) > 160
lab = np.zeros(m.shape, np.int32); sizes = [0]; n = 0
for y0, x0 in zip(*np.nonzero(m)):
    if lab[y0, x0]:
        continue
    n += 1; lab[y0, x0] = n; q2 = deque([(y0, x0)]); c = 0
    while q2:
        y, x = q2.popleft(); c += 1
        for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            ny, nx = y + dy, x + dx
            if 0 <= ny < h and 0 <= nx < w and m[ny, nx] and not lab[ny, nx]:
                lab[ny, nx] = n; q2.append((ny, nx))
    sizes.append(c)
keep = np.array(sizes) >= max(sizes) * 0.005
keep[0] = False
near = np.asarray(Image.fromarray((keep[lab] * 255).astype("uint8")).filter(ImageFilter.MaxFilter(5))) > 0
al = Image.fromarray(np.where(near, np.asarray(al), 0).astype("uint8"))
out_im.putalpha(al)
bbox = al.point(lambda v: 255 if v > 24 else 0).getbbox()
out_im = out_im.crop(bbox)
out_im.save(out)
print(out, out_im.size)
