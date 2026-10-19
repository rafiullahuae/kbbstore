"""Lane VPA: product pictures for the preview catalogue, one per shade.

Simple drawings (a BB cream tube, a cushion compact, a toner bottle) filled
with each option's own shade, so tapping a swatch visibly moves the picture.
python3 -I tools/vpa-art.py <out-dir>
"""
import sys
import os
from PIL import Image, ImageDraw

OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)

MISSHA = [('13', '#F1D2B6'), ('21', '#E8C3A0'), ('27', '#D3A47A')]
TIRTIR = [('17c', '#F3D9C7'), ('21n', '#EED0B3'), ('21w', '#EDCBA6'), ('23n', '#E2BC98'), ('24n', '#D9B08A'),
          ('24w', '#DDB385'), ('25n', '#CFA27A'), ('27n', '#C2925F'), ('29n', '#B4835A')]


def canvas():
    img = Image.new('RGB', (800, 800), '#FFFFFF')
    d = ImageDraw.Draw(img)
    d.ellipse((90, 90, 710, 710), fill='#FBF3F0')
    return img, d


def tube(name, shade):
    img, d = canvas()
    d.rounded_rectangle((300, 120, 500, 560), 40, fill='#B8243A')
    d.rounded_rectangle((330, 170, 470, 300), 12, fill='#E7C46A')
    d.text((360, 215), 'M', fill='#7A1020')
    d.rounded_rectangle((330, 560, 470, 690), 18, fill='#D9B76A')
    d.ellipse((520, 470, 700, 650), fill=shade, outline='#00000022')
    img.save(os.path.join(OUT, name), 'PNG')


def compact(name, shade):
    img, d = canvas()
    d.ellipse((150, 420, 650, 600), fill='#8E1020')
    d.rectangle((150, 360, 650, 510), fill='#8E1020')
    d.ellipse((150, 280, 650, 440), fill='#C21E2E')
    d.ellipse((230, 310, 570, 410), fill=shade)
    d.ellipse((520, 520, 720, 680), fill='#C21E2E')
    img.save(os.path.join(OUT, name), 'PNG')


def bottle(name, liquid):
    img, d = canvas()
    d.rounded_rectangle((345, 110, 455, 200), 16, fill='#2A2228')
    d.rounded_rectangle((270, 190, 530, 700), 46, fill=liquid)
    d.rounded_rectangle((310, 380, 490, 520), 10, fill='#FFFFFF')
    img.save(os.path.join(OUT, name), 'PNG')


for key, hex_ in MISSHA:
    tube('missha-%s.png' % key, hex_)
for key, hex_ in TIRTIR:
    compact('tirtir-%s.png' % key, hex_)
bottle('anua-250.png', '#CFE6D2')
bottle('anua-500.png', '#B9DBBE')
print('ok', len(os.listdir(OUT)))
