"""Lane BT: pixel compare two tools/bt-shots.cjs runs.

    python3 -I tools/bt-diff.py A_DIR B_DIR [--noise CONTROL_DIR] [--out out.json]

Per screenshot: the number of differing pixels (any channel). With --noise,
CONTROL_DIR is a second run of A (same tree, same fixture): the pixels that
differ between A and CONTROL are what the page does by itself between two
identical loads (the WhatsApp button's rotating avatar ring, a sub-pixel of
anti-aliasing), and `outside_noise` counts B's differences that are NOT among
them. Exit 0 always; read the table.
"""
import json
import os
import sys

import numpy as np
from PIL import Image

args = sys.argv[1:]
noise_dir = out = None
if '--noise' in args:
    i = args.index('--noise'); noise_dir = args[i + 1]; del args[i:i + 2]
if '--out' in args:
    i = args.index('--out'); out = args[i + 1]; del args[i:i + 2]
a_dir, b_dir = args[0], args[1]


def load(p):
    return np.asarray(Image.open(p).convert('RGB')) if os.path.exists(p) else None


rows = {}
for name in sorted(os.listdir(a_dir)):
    if not name.endswith('.png'):
        continue
    ia, ib = load(os.path.join(a_dir, name)), load(os.path.join(b_dir, name))
    if ib is None:
        rows[name] = {'missing': True}
        continue
    if ia.shape != ib.shape:
        rows[name] = {'size_a': list(ia.shape[:2]), 'size_b': list(ib.shape[:2]), 'differing': None}
        continue
    d = np.any(ia != ib, axis=2)
    row = {'size': list(ia.shape[:2]), 'differing': int(d.sum())}
    if noise_dir:
        ic = load(os.path.join(noise_dir, name))
        if ic is not None and ic.shape == ia.shape:
            n = np.any(ia != ic, axis=2)
            # A pixel that moves by itself moves its neighbours' anti-aliasing too.
            pad = np.zeros_like(n)
            for dy in (-2, -1, 0, 1, 2):
                for dx in (-2, -1, 0, 1, 2):
                    pad |= np.roll(np.roll(n, dy, 0), dx, 1)
            row['noise'] = int(n.sum())
            row['outside_noise'] = int((d & ~pad).sum())
    if d.any():
        ys, xs = np.nonzero(d)
        row['bbox'] = [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())]
    rows[name] = row

for k, v in rows.items():
    print(f"{k:32} {v}")
if out:
    with open(out, 'w') as f:
        json.dump(rows, f, indent=1)
