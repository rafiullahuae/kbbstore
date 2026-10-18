"""Lane BT: the pictures in docs/lane-bt, from tools/bt-shots.cjs runs.

    python3 -I tools/bt-pictures.py

Each picture is three phone screens side by side, first screen (844px):
NORMAL PHONE | LARGE TEXT, BEFORE (the shop today) | LARGE TEXT, AFTER.
Before shots are viewport captures; after and normal are full-page captures
cropped to the same first screen.
"""
import os

from PIL import Image, ImageDraw

S = 'storage/bt-logs/shots'
OUT = 'docs/lane-bt'
os.makedirs(OUT, exist_ok=True)


def first(path, h=844):
    im = Image.open(path).convert('RGB')
    return im.crop((0, 0, im.width, min(h, im.height)))


def trio(normal, before, after, label, out):
    ims = [first(normal), first(before), first(after)]
    w = sum(i.width for i in ims) + 2 * 12
    h = max(i.height for i in ims) + 30
    c = Image.new('RGB', (w, h), (255, 255, 255))
    d = ImageDraw.Draw(c)
    x = 0
    for im, t in zip(ims, ['normal phone', 'large text ' + label + ' - before', 'large text ' + label + ' - after']):
        d.text((x + 6, 8), t, fill=(200, 0, 60))
        c.paste(im, (x, 30))
        x += im.width + 12
    c.save(os.path.join(OUT, out), optimize=True)


for z in ('1.3', '1.6', '2'):
    for page in ('home', 'product', 'category', 'checkout'):
        n = f'{S}/zoom/final/z1/{page}-en-390.png'
        b = f'{S}/zoom/before/z{z}/{page}-en-390.png'
        a = f'{S}/zoom/final/z{z}/{page}-en-390.png'
        if all(os.path.exists(p) for p in (n, b, a)):
            trio(n, b, a, z, f'large-text-{z}-{page}-390.png')

for w in ('360', '412'):
    n = f'{S}/zoom/after/z1/product-en-{w}.png'
    b = f'{S}/zoom/before/z1.6/product-en-{w}.png'
    a = f'{S}/zoom/after/z1.6/product-en-{w}.png'
    if all(os.path.exists(p) for p in (n, b, a)):
        trio(n, b, a, '1.6', f'large-text-1.6-product-{w}.png')

n = f'{S}/zoom/final/z1/product-ar-390.png'
b = f'{S}/zoom/before-ar/z1.6/product-ar-390.png'
a = f'{S}/zoom/final/z1.6/product-ar-390.png'
if all(os.path.exists(p) for p in (n, b, a)):
    trio(n, b, a, '1.6', 'large-text-1.6-product-ar-390.png')

for w in ('390', '1280'):
    for page in ('home', 'product'):
        p = f'{S}/normal/after/{page}-en-{w}.png'
        if os.path.exists(p):
            first(p, 844 if w == '390' else 900).save(os.path.join(OUT, f'normal-visitor-{page}-{w}.png'), optimize=True)
print(sorted(os.listdir(OUT)))
