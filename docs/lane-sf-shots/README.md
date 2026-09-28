# Lane SF — the set's buy column

Chromium 1194 at 390 and 1280, `deviceScaleFactor: 2`, full page.
Produced by `tools/sf-preview.sh` + `tools/sf-shoot.sh`; every number below is
read off the page by `tools/sf-shots.cjs` and written to
`measurements-after.json` / `measurements-before.json` beside these files.

## The change, as a pair

`before-*` is the branch point reconstructed: the bundle strip put back and the
contents `@include` moved back to the section near the foot of the page.
`after-*` is what this lane ships.

| | before | after |
|---|---|---|
| bulk-quantity strip on a set | **shown** | **gone** |
| what is in the box | a grid of tiles, ~800px down the page | a **list**, in the buy column |
| inside `.buybox`? | no | **yes** |
| an ordinary product | strip shown | **strip shown, byte for byte** |

## Every shot

| shot | viewport | scrollWidth | overflows | .buybox | member rows | standing / folded | linked / unlinked | list height | Add to cart at y | strip |
|---|---|---|---|---|---|---|---|---|---|---|
| after-set | 390 | 390 | no | 346 | 3 | 3 / 0 | 2 / 1 | 320 | 1123 | no |
| after-set | 1280 | 1280 | no | 582 | 3 | 3 / 0 | 2 / 1 | 308 | 687 | no |
| after-set-12 | 390 | 390 | no | 346 | 12 | 5 / 7 | 11 / 1 | 501 | 1304 | no |
| after-set-12 | 1280 | 1280 | no | 582 | 12 | 5 / 7 | 11 / 1 | 497 | 876 | no |
| after-set-12-open | 390 | 390 | no | 346 | 12 | 5 / 7 | 11 / 1 | 1025 | 1828 | no |
| after-set-12-open | 1280 | 1280 | no | 582 | 12 | 5 / 7 | 11 / 1 | 1050 | 1429 | no |
| after-unpriced-set | 390 | 390 | no | 346 | 2 | 2 / 0 | 2 / 0 | 221 | 1024 | no |
| after-unpriced-set | 1280 | 1280 | no | 582 | 2 | 2 / 0 | 2 / 0 | 229 | 608 | no |
| after-set-rtl | 390 | 390 | no | 346 | 3 | 3 / 0 | 2 / 1 | 320 | 1123 | no |
| after-set-rtl | 1280 | 1280 | no | 582 | 3 | 3 / 0 | 2 / 1 | 308 | 687 | no |
| after-plain-product | 390 | 390 | no | 346 | — | — | — | — | 1068 | **yes** |
| after-plain-product | 1280 | 1280 | no | 582 | — | — | — | — | 573 | **yes** |

`before-*` carries the same twelve rows; the differences are the `strip` column
and `Add to cart at y` on a set (1041 / 578 before, because the strip is
shorter than the list).

**`scrollWidth === clientWidth` on all twenty-four**, which is
`document.documentElement.scrollWidth === document.documentElement.clientWidth`
— no horizontal scroll at either width, in English or in Arabic.

**The ordinary product does not move.** `after-plain-product` and
`before-plain-product` agree on every number including `Add to cart at y`
(1068 at 390, 573 at 1280).

## What the fold buys

At 390 with twelve members the list is **501px** closed and **1025px** open, so
the disclosure keeps **524px** out of the space between the price and the
button. Add to cart sits at y=1304 rather than y=1828.

## Queries

Measured in the suite, not in the browser (`SetBuyColumnTest > it is flat in the
number of members`):

| page | queries |
|---|---|
| set, 3 members | **10** |
| set, 12 members | **10** |
| ordinary product | 7 |

Flat in the number of members, which is `App\Support\SetEagerLoad`: three
batched queries for the whole box whatever is in it. The ordinary product pays
none of them.

## The fixtures

`tools/sf-seed.php`:

- **Glow Starter Set** — 3 members. One is a **draft**, so it is named and
  *not* linked (the `2 / 1` above). One has **no picture**, so the gradient
  fallback is in the shot.
- **Full Routine Set** — 12 members, one draft, one pictureless: the box that
  makes the fold do something.
- **Draft Ritual Box** — **unpriced**. Its footing reads
  `Bought separately AED 166 | Set price AED 0` and says nothing about saving.
- **Ceramide Daily Moisturiser** — an ordinary product, the control.

None of the sets has a brand, which is the case the owner actually has:
*"the Set product type will not have any brand."*
