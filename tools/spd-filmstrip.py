"""Lane SPD: the late-load picture.

    python3 -I tools/spd-filmstrip.py <framesDir> <out.png> <t1,t2,...> <caption> [<caption2> ...]

One row of filmed frames (spd-film.py's t<ms>.jpg; the nearest frame at or
before each time asked for), each labelled with its time, then the caption
lines underneath -- the requests the frames cannot show.
"""
import os
import sys

from PIL import Image, ImageDraw

frames_dir, out, times = sys.argv[1], sys.argv[2], [int(t) for t in sys.argv[3].split(',')]
captions = sys.argv[4:]
have = sorted(int(f[1:6]) for f in os.listdir(frames_dir) if f.startswith('t') and f.endswith('.jpg'))
picked = []
for t in times:
    best = max([h for h in have if h <= t] or [have[0]])
    picked.append((t, Image.open(os.path.join(frames_dir, f't{best:05d}.jpg')).convert('RGB')))

w, h = picked[0][1].size
gap, top, line = 10, 26, 22
W = len(picked) * (w + gap) + gap
H = top + h + gap + line * len(captions) + gap
img = Image.new('RGB', (W, H), 'white')
d = ImageDraw.Draw(img)
for i, (t, im) in enumerate(picked):
    x = gap + i * (w + gap)
    d.text((x, 6), f'{t} ms', fill='black')
    img.paste(im, (x, top))
for j, c in enumerate(captions):
    d.text((gap, top + h + gap + j * line), c, fill='black')
img = img.resize((W * 2, H * 2))
img.save(out)
