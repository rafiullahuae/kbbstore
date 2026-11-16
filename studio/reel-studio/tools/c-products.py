#!/usr/bin/env python3
"""Writes brands/kbb/products/index.json: the KBB product cut-outs (copied from docs/plans/ad-studio/assets/products,
cut by that folder's cutout.py) with the words a transcript uses for them, so app/content can match spoken words
to product shots offline.  usage: python3 -I tools/c-products.py   (from studio/reel-studio)"""
import json, os
from PIL import Image

D = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'brands', 'kbb', 'products')
P = {
    'guasha': ('medicube', 'Gua Sha cream', 'cream gua sha massage face lifting contour'),
    'anua': ('Anua', 'Heartleaf mist mini', 'mist toner spray hydrating heartleaf'),
    'marymay': ('Mary&May', 'PDRN serum', 'serum pdrn ampoule glow'),
    'deodorant': ('medicube', 'Deodorant', 'deodorant body fresh'),
    'eyemask': ('medicube', 'Eye patches', 'eye patches patch mask puffiness'),
    'balmred': ('medicube', 'EGF balm (red)', 'balm egf stick moisturiser'),
    'balmpink': ('medicube', 'PDRN balm (pink)', 'balm pdrn stick glow pink'),
    'celimax': ('celimax', 'Retinal Shot', 'retinal retinol serum wrinkles'),
    'dalba': ("d'Alba", 'First Spray Serum', 'spray serum mist truffle glow'),
    'glowvanilla': ('medicube', 'Glow Vanilla mist', 'mist body vanilla fragrance glow'),
    'anuacream': ('Anua', 'PDRN cream', 'cream moisturiser pdrn hydrating'),
    'hairoil': ('medicube', 'Rosemary hair oil', 'hair oil rosemary scalp'),
    'anuaeye': ('Anua', 'PDRN Caffeine eye cream', 'eye cream caffeine pdrn puffiness'),
    'eospeach': ('eos', '24H Moisture lotion (jasmine peach)', 'lotion body moisturiser jasmine peach'),
    'eosbutter': ('eos', 'Cashmere whipped body butter', 'body butter whipped vanilla moisturiser'),
    'bdeye': ('Biodance', 'Collagen Peptide eye cream', 'eye cream collagen peptide'),
    'bdserum': ('Biodance', 'Caviar PDRN serum', 'serum caviar pdrn ampoule'),
    'azelaic': ('medicube', 'Azelaic Acid foam cleanser', 'cleanser foam wash azelaic niacinamide acne pores'),
    'nbz9': ('numbuzin', 'No.9 NAD+ sun essence', 'sunscreen spf sun essence'),
    'nbz5': ('numbuzin', 'No.5 Glutathione mask set', 'sheet mask glutathione brightening vitamin'),
}
items = []
for k, (b, n, kw) in P.items():
    w, h = Image.open(os.path.join(D, k + '.webp')).size
    words = set(kw.split()) | {x.lower().strip('.()+') for x in (b + ' ' + n).replace('&', ' ').split() if len(x.strip('.()+')) > 2}
    items.append({'id': k, 'brand': b, 'name': n, 'file': k + '.webp', 'w': w, 'h': h, 'keywords': sorted(words)})
json.dump({'v': 1, 'note': 'KBB product cut-outs (transparent WebP) from the shop catalogue, copied from docs/plans/ad-studio/assets/products. Read offline by app/content (b-roll, covers, formats).', 'products': items},
          open(os.path.join(D, 'index.json'), 'w'), indent=1, ensure_ascii=False)
print(len(items), 'products')
