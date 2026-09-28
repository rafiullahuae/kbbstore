# Lane SP2 — the evidence

Chromium 1194, `deviceScaleFactor: 2`, full-page and panel-cropped, at **390**
and **1280**. Reproduce with:

```sh
sh tools/sp2-preview.sh 8700        # migrate + seed a throwaway sqlite preview
SP2_BASE=http://127.0.0.1:8700 node tools/sp2-shots.cjs > docs/lane-sp2-shots/measurements.json
```

`measurements.json` is what the run printed: the viewport, the
`document.documentElement.scrollWidth`, every money tile as text, and the five
rows of the fixed-mode working-out. **No shot overflows** — `scrollWidth` equals
the viewport at 390 on all fourteen.

Where it sits in the admin: **Catalog → Product editor**, panels
**What is in the box** and **Search appearance → Share image**.

## The fixture, in integer fils

The seed (`tools/sp2-seed.php`) prints these on the way past:

```
fixed set #29 basis 20000 parts 18500 adjustment 1500 price 14500 compare 16500 fils
percent set #30 price 16650 fils
amount  set #31 price 16000 fils
```

| | fils | shown |
|---|---|---|
| Toner, when the set was priced | 12000 | AED 120.00 |
| Serum | 8000 | AED 80.00 |
| **Parts total at the anchor** (`set_price_basis`) | **20000** | AED 200.00 |
| Typed price (`products.price`) | 18000 | AED 180.00 |
| Typed sale price (`products.sale_price`) | 16000 | AED 160.00 |
| Toner **after** the markdown | 10500 | AED 105.00 |
| Parts total now | 18500 | AED 185.00 |
| **Adjustment** = max(0, 20000 − 18500) | **1500** | AED 15.00 |
| Price now = 18000 − 1500 | 16500 | AED 165.00 |
| **Sale price now** = 16000 − 1500 — what a shopper pays | **14500** | AED 145.00 |

Nobody saved the set. The toner was repriced and the set followed it, off the
regular price and off the sale price by the same 1500 fils.

## The shots

| File | What it is |
|---|---|
| `pricing-fixed-after-member-repriced-*` | the whole editor, hand-typed mode, after the member came down |
| `panel-pricing-fixed-after-member-repriced-*` | the same panel on its own — the five-row working-out, the "prices only ever come down" sentence, and **Start again from today's total** |
| `pricing-discount-percent-*`, `panel-…` | 10% off the box: AED 185.00 → **AED 166.50** (`intdiv(18500 × 9000 + 5000, 10000)`), unchanged by this lane |
| `pricing-discount-amount-*`, `panel-…` | AED 25 off the box: AED 185.00 → **AED 160.00**, unchanged by this lane |
| `pricing-fixed-retyped-will-reanchor-*`, `panel-…` | AED 175 typed into the price box. The reduction disappears from the panel and the sentence says saving will take today's AED 185.00 as the new starting point — because a changed price re-anchors on the server, and a panel claiming a reduction it is about to zero would be lying. |
| `seo-image-auto-from-main-*`, `panel-…` | the serum had no share image. A main image chosen **through the Media Library** filled it: `…/serum-front-2026.png`, and the state line reads *"Automatic — taken from the main image."* |
| `seo-image-hand-picked-before-*`, `panel-…` | the moisturiser's share image is `/uploads/products/ceramide-share-card.png`, a different picture from its packshot: *"Chosen by hand. Changing the main image will **not** replace it."* with **Use the main image** beside it |
| `seo-image-hand-picked-kept-*`, `panel-…` | the same panel after its main image was replaced with `…/ceramide-front-2026.png` through the picker. The share image is **byte-identical** — `shareImageUnchanged: true` in the run log. |

Panel widths measured: **362 px at 390** (a 16 px gutter each side, nothing
clipped) and **660 px at 1280** (the editor's own two-column grid). The
working-out rows are `11px` labels and `13.5px` figures, and collapse to one
column under 460 px — `calc()` and `grid-template-columns`, no JavaScript
measures anything.
