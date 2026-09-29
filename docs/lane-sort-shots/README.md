# Lane SORT — the price sort, the price band and "On sale", before and after

The shop grid photographed in Chromium at **390** and **1280**, against two
previews of the *same* seeded shop that differ in exactly one expression:

    tools/sort-preview.sh 8993 after     # this branch
    tools/sort-preview.sh 8994 before    # App\Support\EffectivePrice reverted

    SORT_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
    SORT_BASE=http://127.0.0.1:8993 SORT_LABEL=after node tools/sort-shots.cjs 390

`tools/sort-seed.php` seeds twelve published products: seven plain ones and
**four sets, one per pricing mode plus one that has been marked down since it
was last saved**. All three of the first sets carry `products.price = 18000` —
the snapshot `Admin\ProductEditorApiController` writes — which is exactly the
figure the old sort key used.

`measured-<label>-<width>.json` carries every number below, per tile: the name,
the printed price, the struck compare-at, the badges, the tile box, the price
font size, and `document.documentElement.scrollWidth` against `clientWidth`.

---

## 1. "Price: low to high" — `plow-{before,after}-{390,1280}.png`

| | before | after |
|---|---|---|
| | Mugwort Calming Pad  AED 60 | Mugwort Calming Pad  AED 60 |
| | Rice Water Cleanser  AED 80 | Rice Water Cleanser  AED 80 |
| | Propolis Ampoule  AED 80 | Propolis Ampoule  AED 80 |
| | Snail Mucin Essence  AED 100 | Snail Mucin Essence  AED 100 |
| | Azelaic Acid Serum  AED 100 | Azelaic Acid Serum  AED 100 |
| | **Heartleaf Soothing Toner  AED 145** | **Barrier Rescue Box  AED 140** |
| | **Barrier Rescue Box  AED 140** | **Glow Starter Set  AED 144** |
| | **Glow Starter Set  AED 144** | **Heartleaf Soothing Toner  AED 145** |
| | **Night Repair Set  AED 162** | **Morning Glow Duo  AED 160** |
| | **Morning Glow Duo  AED 160** | **Night Repair Set  AED 162** |
| | Ceramide Night Cream  AED 200 | Ceramide Night Cream  AED 200 |
| | Retinal Renewal Serum  AED 260 | Retinal Renewal Serum  AED 260 |

Before: AED 145 sits above AED 140 and AED 144, and AED 162 above AED 160 —
three inversions a shopper can see without leaving the page. After: the numbers
read down the column.

## 2. "Price: high to low" — `phigh-{before,after}-{390,1280}.png`

The same three inversions, mirrored: before, AED 144 and AED 140 are printed
*above* AED 145. After, they are below it.

## 3. The price band — `band-54-150-*` and `band-150-300-*`

| band | before | after |
|---|---|---|
| AED 54 – 150 | 6 tiles, **no set** | 8 tiles, **Barrier Rescue Box (AED 140) and Glow Starter Set (AED 144)** |
| AED 150 – 300 | 6 tiles, **including AED 140 and AED 144** | 4 tiles, **both of them gone** |

A shopper filtering to AED 54 – 150 could not see two sets advertised at
AED 140 and AED 144, and found them under AED 150 – 300 instead.

## 4. "On sale" — `onsale-{before,after}-{390,1280}.png`

**Before: 0 tiles.** The same grid, at the same moment, draws a `-11%` badge and
a struck-through `AED 162` on Glow Starter Set, `-11%` on Morning Glow Duo and
`-10%` on Night Repair Set. The badge and the filter contradicted each other.

**After: 3 tiles**, and they are exactly the three badged ones:

    Glow Starter Set   AED 162 -> AED 144   [-11%]
    Morning Glow Duo   AED 180 -> AED 160   [-11%]
    Night Repair Set   AED 180 -> AED 162   [-10%]

The badges themselves are **byte-identical before and after** — this lane
changed the filter to agree with the tile, never the tile.

## 5. Layout, measured

| | 390 | 1280 |
|---|---|---|
| `clientWidth` | 390 | 1280 |
| `scrollWidth` | 390 | 1280 |
| horizontal scroll | none | none |
| tile box | 173.0 × 289.8 | 232.8 × 348.6 |
| price font | 13.5px | 13px |

Identical before and after on every page and both widths: nothing about this
lane is a layout change.
