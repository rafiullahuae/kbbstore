# New products 15 Nov 2026 — Arabic, and the space under English headings (Lane LN15)

The owner, 15 Nov 2026: *"we have added some recently few products, please do translation and give me
patch. also in long desecription in english. there'es some spacing issues under headings. plz note, must
be equal spacing under each heading. in arabic it's fine. plz fix."*

## How it was measured

- **Every product in the sitemap (875)**, English and Arabic page each, fetched from kbeautybliss.com one
  at a time (~1 s apart, a bot-check page retried after a pause): 1,750 pages, 0 errors
  (`tools/ln15-fetch.sh`, `tools/ln15-tabs.py`).
- **Arabic still missing:** `tools/ln15-english2.py` — name / short description / description with no
  Arabic letter on /ar, and every other tab whose /ar body is English (the INCI tabs, "Ingredients" /
  "Major Ingredients", are English on every product by design and are not counted).
- **Gaps under headings:** `tools/ln15-gaps.cjs` serves each saved live page back to Chromium (no script,
  no picture; the build's own stylesheets and fonts, which are the live shop's hashes) and measures, for
  every heading line (all words bold or in h2–h6, ≤ 80 characters; a bullet's bold label and the old
  shop's designed blocks excluded), the distance from the bottom of its text box to the top of the next
  line's, at 390 and 1280. Inside one paragraph that distance is 0.5px; anything above it is space added
  under the heading. The "after" is the same page with the Description body as this branch draws it
  (`tools/ln15-after.php`: `RichText::markHeadings()`) and this branch's `kbb-product.css`.

## Task 1 — Arabic

14 products read English on /ar in name, short description and description; one product's own tab was
English beside an Arabic description; one brand name was English. All written in
`database/migrations/2028_02_15_100000_arabic_new_products_15_november.php`:

| Product | Fields |
|---|---|
| vt-pdrn-reedle-shot-brush-hair-serum | name, short, description |
| anua-pdrn-caffeine-rejuvenating-eye-cream | name, short, description |
| eos-shea-better-cashmere-post-shave-smooth-body-cream-vanilla-cashmere | name, short, description |
| eos-shea-better-24h-moisture-body-lotion-jasmine-peach | name, short, description |
| eos-shea-better-cashmere-whipped-oil-body-butter-vanilla | name, short, description |
| eos-shea-better-24h-moisture-body-lotion-fresh-cozy | name, short, description |
| eos-shea-better-24h-moisture-body-lotion-vanilla-cashmere | name, description (no short in English) |
| biodance-collagen-peptide-eye-cream | name, short, description |
| biodance-rejuvenating-caviar-pdrn-serum | name, short, description |
| biodance-caviar-pdrn-eye-patches | name, description (no short in English) |
| biodance-collagen-peptide-eye-patches | name, short, description |
| numbuzin-no-5-glutathione-vitamin-concentrated-mask-set-5-sheets | name, short, description |
| numbuzin-no-5-glutathione-vitamin-concentrated-mask-1-sheet | name, short, description |
| aromatica-rosemary-scalp-scaling-shampoo-400ml | name, short, description |
| medicube-age-r-booster-pro | its own "Warranty" tab: title + body |
| brand **eos** | Arabic name إيوس (its /ar brand page read "eos") |

Every body is tag for tag and number for number with its live English (`HtmlStructure::problem()` null)
and has the English's blank lines and lists in the same places (`BlockStructure::shape()` equal;
`ArabicStructureRepair::scan()` finds nothing to fix). Not translated: `medicube-pdrn-pink-vita-coating-mask-new`
has no description in English either (the page prints the shop's "No description available." fallback).

Pictures (local preview with the live English and this migration applied, `tools/ln15-preview.sh`):
`shots/after-ar-*.png`.

## Task 2 — the space under English headings

### What the live English does today

Every heading on all 875 English pages (861 have headings; 2,615 headings at 1280):

| Gap under the heading | Headings | What typed it |
|---|---:|---|
| 0–1px | 656 | the owner's own copy: `<div><b>Key Benefits:</b></div><div><ul>` in a description written with empty lines |
| ~6px | 146 | `<div class="productDescription"><b>Benefits:</b></div><div><ul>`, bare `<strong>Benefits:</strong><ul>` — the list's own top margin |
| ~11px | 1,805 | `<p><b>How to use:</b></p>` in imported copy — a paragraph's bottom margin |
| 18px+ | 8 | an empty line typed straight under the heading (VT PDRN Reedle Shot hair serum, the newest product: under "Recommended for:" only) |

**223 products had two different gaps on one page** (e.g. COSRX Low pH cleanser 6 / 11px, Tocobo Bio Watery
6 / 11 / 0px, Some By Mi retinol eye cream 6 / 11 / 11px, VT hair serum 0 / 18 / 0px). /ar measured on the
first 210: the same shapes, the same gaps — the Arabic copies the English's tags.

### The rule

**A heading sits directly on the text under it — no gap; the space between sections stays above the
heading.** It is what the owner's own descriptions already do (each Enter a new line with no space,
2.60.504) and what 656 headings already looked like. `RichText::markHeadings()` marks each heading at
render time, English product pages only (`Store\ProductController::tabs()`), with `class="kbb-hd"`; a heading
is a short (≤ 80 characters) p / div / section / h2–h6 whose words are all bold or all in the h2–h6, or bare
bold words alone on their line just before a block. `kbb-product.css` then draws: no margin under it, none
on top of what follows it (four deep), none after a wrapper it ends, and an empty line typed straight under
it (`kbb-bl`, or `<b><br></b>` as `kbb-hd-br`) not at all. Stored copy is untouched; Arabic pages get no
class and draw exactly as before; a body with no heading is byte for byte.

### After (same 875 pages, this branch's render and stylesheet)

| | 390 | 1280 |
|---|---:|---:|
| headings at 0–1px | 2,593 of 2,599 | 2,609 of 2,615 |
| ~6px / ~11px / 18px+ | 1 / 0 / 1 | 1 / 0 / 1 |
| products with two different gaps | 2 | 2 |
| pages wider than the window | 0 | 0 |

Descriptions only get shorter (0 to −105px; 332 of 1,750 page-widths unchanged). The two left: Purito Green
Level Buffet serum, whose copy glues the heading to the line before it (`Made in Korea<b>How to use:</b>`),
and Anua PDRN Hyaluron Glass Skin set ("What's Included ?" inside a `<span>`, then a bold empty line;
its h2 headings sit 1.7px — the h2's own line height). Per-page numbers: `gaps-all-before.json`,
`gaps-all-after.json`; the six pictured products: `gaps-shots-*.json`.

Pictures, EN, 390 and 1280, live page before / this branch after (the live page served back with the
branch's body and stylesheet): `shots/before-en-*.png`, `shots/after-en-*.png` — VT PDRN Reedle Shot hair
serum and Anua PDRN eye cream (new), COSRX Low pH cleanser, Some By Mi retinol eye cream, Tocobo Bio Watery
sun cream, Laneige lip sleeping mask grapefruit.

### Speed (tools/ln15-speed.sh on the tools/ln15-preview.sh shop, median of 15, three runs each)

| | before ms | after ms | queries | settings reads |
|---|---:|---:|---|---|
| product (VT, 3 headings) | 25.8–27.2 | 28.4–28.9 | 11 → 11 | 6 → 6 |
| category | 23.8–24.8 | 26.2–26.7 | 5 → 5 | 5 → 5 |
| brand | 18.3–19.5 | 18.6–27.2 | 5 → 5 | 6 → 6 |

The category and brand pages do not run the new code, so their spread is this machine's noise; the function
itself costs 22 µs median (71 µs p95) per tab body over all 1,671 live English tab bodies. No query, no
script, no request; product HTML +15 bytes per heading; kbb-product.css 64,044 → 65,542 bytes (13,127 →
13,239 gzipped).
