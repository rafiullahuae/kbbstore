"""Lane AR round 2 -- the screenshots, Arabic beside English, as JPEG (q70).

    python3 tools/arr-pairs.py [shots=storage/ar-logs/shots-final] [out=docs/lane-ar-shots/round2]

One file per page and width: <key>-<w>.jpg, Arabic on the LEFT, English on the
RIGHT, full page (1280 halved). Copies measurements.json beside them.
"""
import sys, os, glob, shutil
from PIL import Image

SRC = sys.argv[1] if len(sys.argv) > 1 else 'storage/ar-logs/shots-final'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'docs/lane-ar-shots/round2'
os.makedirs(OUT, exist_ok=True)
total = 0
for f in sorted(glob.glob(os.path.join(SRC, 'ar-*-*.png'))):
    base = os.path.basename(f)[3:-4]
    key, w = base.rsplit('-', 1)
    en = os.path.join(SRC, f'en-{key}-{w}.png')
    if not os.path.exists(en):
        continue
    a = Image.open(f).convert('RGB')
    e = Image.open(en).convert('RGB')
    s = 1.0 if int(w) < 900 else 0.5
    if s != 1.0:
        a = a.resize((int(a.width * s), int(a.height * s)))
        e = e.resize((int(e.width * s), int(e.height * s)))
    H = min(max(a.height, e.height), 9000)
    gap = 16
    im = Image.new('RGB', (a.width + e.width + gap, H), (230, 230, 230))
    im.paste(a.crop((0, 0, a.width, min(a.height, H))), (0, 0))
    im.paste(e.crop((0, 0, e.width, min(e.height, H))), (a.width + gap, 0))
    p = os.path.join(OUT, f'{key}-{w}.jpg')
    im.save(p, 'JPEG', quality=70, optimize=True)
    total += os.path.getsize(p)
m = os.path.join(SRC, 'measurements.json')
if os.path.exists(m):
    shutil.copy(m, os.path.join(OUT, 'measurements.json'))
print('wrote', len(glob.glob(os.path.join(OUT, '*.jpg'))), 'pairs,', total // 1024, 'KB')
