"""Lane GS2: the contact sheet of every style at 390, English and Arabic.

  python3 tools/gs2-sheet.py <out dir>   (reads <out dir>/sheet/<style>-<lang>.png)
  -> <out dir>/contact-sheet.png
"""
import os, sys
from PIL import Image, ImageDraw, ImageFont

out = sys.argv[1]
STYLES = [('underline', 'Flowing underline (default)'), ('sway', 'Pink hairlines + gift sway'), ('pill', 'Blush pill + slow glint'),
          ('rose', 'Rose shimmer words'), ('twinkles', 'Hairlines + twinkles'), ('band', 'Soft band + breathing gift'), ('plain', 'Plain line'),
          ('quiet', 'Quiet card'), ('blush', 'Blush tint'), ('blushglint', 'Blush + soft glint'), ('outline', 'Pink outline'),
          ('a', 'A Rose shimmer'), ('b', 'B Berry shine'), ('c', 'C Black & gold'), ('d', 'D Running ticker'), ('e', 'E Glowing border'),
          ('f', 'F Candy stripes'), ('g', 'G Aurora'), ('h', 'H Twinkle'), ('i', 'I Rainbow flow'), ('j', 'J Neon night'),
          ('k', 'K Confetti party'), ('ka', 'KA Confetti + Rose shimmer'), ('kb', 'KB Confetti + Berry'), ('kl', 'KL Confetti + Holographic'),
          ('l', 'L Holographic'), ('m', 'M Gift tag + arrows')]
try:
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 22)
except OSError:
    font = ImageFont.load_default()

tiles = []
for key, label in STYLES:
    pair = [Image.open(os.path.join(out, 'sheet', f'{key}-{lang}.png')).convert('RGB') for lang in ('en', 'ar')]
    h = max(p.height for p in pair)
    tile = Image.new('RGB', (pair[0].width * 2 + 24, h + 40), 'white')
    d = ImageDraw.Draw(tile)
    d.text((8, 6), label, fill=(40, 30, 40), font=font)
    tile.paste(pair[0], (0, 40))
    tile.paste(pair[1], (pair[0].width + 24, 40))
    tiles.append(tile)

cols = 3
w = max(t.width for t in tiles)
rows = [tiles[i:i + cols] for i in range(0, len(tiles), cols)]
H = sum(max(t.height for t in r) + 16 for r in rows)
sheet = Image.new('RGB', (cols * (w + 16), H), (236, 232, 235))
y = 0
for r in rows:
    for c, t in enumerate(r):
        sheet.paste(t, (c * (w + 16), y))
    y += max(t.height for t in r) + 16
sheet = sheet.resize((sheet.width // 2, sheet.height // 2), Image.LANCZOS)
sheet.save(os.path.join(out, 'contact-sheet.png'), optimize=True)
print(sheet.size)
