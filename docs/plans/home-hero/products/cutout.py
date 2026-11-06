import sys
from PIL import Image, ImageFilter
import numpy as np
from collections import deque
src, out = sys.argv[1], sys.argv[2]
thr = int(sys.argv[3]) if len(sys.argv) > 3 else 246
im = Image.open(src).convert('RGB'); a = np.asarray(im).astype(int)
h, w, _ = a.shape
white = (a.min(axis=2) >= thr)
bg = np.zeros((h, w), bool)
q = deque()
for x in range(w):
    for y in (0, h - 1):
        if white[y, x] and not bg[y, x]: bg[y, x] = True; q.append((y, x))
for y in range(h):
    for x in (0, w - 1):
        if white[y, x] and not bg[y, x]: bg[y, x] = True; q.append((y, x))
while q:
    y, x = q.popleft()
    for dy, dx in ((1,0),(-1,0),(0,1),(0,-1)):
        ny, nx = y+dy, x+dx
        if 0 <= ny < h and 0 <= nx < w and not bg[ny, nx] and white[ny, nx]:
            bg[ny, nx] = True; q.append((ny, nx))
alpha = Image.fromarray(np.where(bg, 0, 255).astype('uint8'))
alpha = alpha.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(1.1))
rgba = im.convert('RGBA'); rgba.putalpha(alpha)
bbox = alpha.point(lambda v: 255 if v > 20 else 0).getbbox()
rgba = rgba.crop(bbox)
rgba.save(out)
print(out, rgba.size)
