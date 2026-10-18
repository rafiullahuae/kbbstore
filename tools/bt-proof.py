"""Lane BT: the normal visitor's pixel proof.

    python3 -I tools/bt-proof.py storage/bt-logs/shots/normal  [out.json]

Expects four tools/bt-shots.cjs MODE=normal runs under that folder: `before`
and `control` (the tree without the guard, twice) and `after` and `after2`
(with it, twice). A page is proved unchanged when SOME before run and SOME
after run are pixel-identical: the guard cannot change a pixel in one pairing
and not in another. The two noise columns are what a page does by itself
between two identical loads (the WhatsApp button's rotating avatar ring,
sub-pixel anti-aliasing on rounded pills).
"""
import json
import os
import sys

import numpy as np
from PIL import Image

root = sys.argv[1]
B, A = [x for x in ['before', 'control', 'before3'] if os.path.isdir(os.path.join(sys.argv[1], x))], ['after', 'after2']


def diff(x, y):
    return None if x.shape != y.shape else int(np.any(x != y, axis=2).sum())


out = {}
for n in sorted(os.listdir(os.path.join(root, 'before'))):
    if not n.endswith('.png'):
        continue
    im = {k: np.asarray(Image.open(os.path.join(root, k, n)).convert('RGB')) for k in B + A}
    pairs = {b + '/' + a: diff(im[b], im[a]) for b in B for a in A}
    vals = [v for v in pairs.values() if v is not None]
    out[n] = {
        'size': list(im['before'].shape[:2]),
        'min_before_vs_after': min(vals) if vals else None,
        'pairs': pairs,
        'noise_before_vs_before': diff(im['before'], im['control']),
        'noise_after_vs_after': diff(im['after'], im['after2']),
    }
    o = out[n]
    print(f"{n:24} min={o['min_before_vs_after']}  pairs={list(pairs.values())}  noise b/b={o['noise_before_vs_before']} a/a={o['noise_after_vs_after']}")

if len(sys.argv) > 2:
    with open(sys.argv[2], 'w') as f:
        json.dump(out, f, indent=1)
