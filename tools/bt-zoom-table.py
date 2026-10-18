"""Lane BT: the enlarged visitor, after the guard, against a normal phone.

    python3 -I tools/bt-zoom-table.py storage/bt-logs/shots/zoom/after [out.json]

Compares every full-page screenshot at text zoom 1.3 / 1.6 / 2 with the same
page at zoom 1 (all under the gdb text-zoom emulation, so the only variable is
the zoom), and prints differing pixels per page and width.
"""
import json
import os
import sys

import numpy as np
from PIL import Image

root = sys.argv[1]
base = os.path.join(root, 'z1')
out = {}
for z in ('z1.3', 'z1.6', 'z2'):
    d = os.path.join(root, z)
    if not os.path.isdir(d):
        continue
    for n in sorted(os.listdir(base)):
        if not n.endswith('.png') or not os.path.exists(os.path.join(d, n)):
            continue
        a = np.asarray(Image.open(os.path.join(base, n)).convert('RGB'))
        b = np.asarray(Image.open(os.path.join(d, n)).convert('RGB'))
        if a.shape != b.shape:
            out[z + '/' + n] = {'height_normal': a.shape[0], 'height_zoomed': b.shape[0]}
        else:
            m = np.any(a != b, axis=2)
            row = {'differing': int(m.sum()), 'of': int(m.size)}
            if m.any():
                ys, xs = np.nonzero(m)
                row['bbox'] = [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())]
            out[z + '/' + n] = row
for k, v in out.items():
    print(k, v)
if len(sys.argv) > 2:
    with open(sys.argv[2], 'w') as f:
        json.dump(out, f, indent=1)
