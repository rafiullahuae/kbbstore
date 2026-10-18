"""Lane SPD: what changes on screen, and when, in one Lighthouse run's filmed
(observed) frames.

    python3 -I tools/spd-film.py <label-profile.frames.json> <outdir>

Writes every distinct frame as t<ms>.jpg and prints, per change, the time,
the share of pixels that moved and the bounding box of the change -- so a
"late visual change" is named by where on the screen it happened, not guessed.
"""
import base64
import io
import json
import os
import sys

from PIL import Image, ImageChops

src, out = sys.argv[1], sys.argv[2]
os.makedirs(out, exist_ok=True)
ev = json.load(open(src))
starts = [e['ts'] for e in ev if e['name'] == 'navigationStart']
nav = min(starts) if starts else min(e['ts'] for e in ev)
shots = sorted((e for e in ev if e['name'] == 'Screenshot'), key=lambda e: e['ts'])
prev = None

for e in shots:
    im = Image.open(io.BytesIO(base64.b64decode(e['args']['snapshot']))).convert('RGB')
    t = int((e['ts'] - nav) / 1000)

    if prev is not None:
        d = ImageChops.difference(im, prev).convert('L').point(lambda v: 255 if v > 24 else 0)
        box = d.getbbox()

        if not box:
            continue

        share = d.histogram()[255] / (im.width * im.height)
        print(f'{t:6d} ms  {share * 100:5.1f}% changed  box={box}  size={im.size}')
    else:
        print(f'{t:6d} ms  first frame')

    im.save(os.path.join(out, f't{t:05d}.jpg'))
    prev = im
