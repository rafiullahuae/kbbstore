# Lane SET — the pictures and the numbers behind them

Chromium at 390px and 1280px, `deviceScaleFactor: 2`, taken by
`tools/set-shots.cjs` and `tools/set-admin-shots.cjs` against the preview
`tools/set-preview.sh` boots. Both scripts derive the application path from
their own location, so they still run after this lane's worktree is removed.

The fixture is one set — **Glow Starter Set, AED 199** — holding 2 × Heartleaf
77% Soothing Toner (AED 90), 1 × Azelaic Acid 10 Serum (AED 75.50) and 1 × Rice
70 Glow Milky Toner (AED 69). Parts 324.50, set 199.00, **saving AED 125.50**,
printed as `You save AED 126` in the store's whole dirhams. Every figure is
integer fils in the database: 19900, 9000, 7550, 6900, saving 12550.

## The storefront, with the popup OPEN

| shot | viewport | `document.documentElement.scrollWidth` | popup width | popup right edge | circle ⌀ | fan width | opener font |
|---|---|---|---|---|---|---|---|
| `cart-panel-popup-open-390` | 390 | **390** | 230 | 377 | 26 | 58 | 11px |
| `cart-panel-popup-open-1280` | 1280 | **1280** | 230 | 1198 | 26 | 58 | 11px |
| `cart-page-popup-open-390` | 390 | **390** | 230 | 320 | 26 | 58 | 11px |
| `cart-page-popup-open-1280` | 1280 | **1280** | 230 | 455 | 26 | 58 | 10px |
| `checkout-summary-popup-open-390` | 390 | **390** | 362 | 376 | 26 | 58 | 11px |
| `checkout-summary-popup-open-1280` | 1280 | **1280** | 230 | 1073 | 26 | 58 | 10px |
| `storefront-set-page-390` | 390 | **390** | — | — | 26 | 58 | 11px |
| `storefront-set-page-1280` | 1280 | **1280** | — | — | 26 | 58 | 11px |

`scrollWidth` equals the viewport on every row, with the popup open: the box
never widens the page. Its right edge is inside the viewport at 390px on all
three surfaces.

The checkout row at 390 is the one wide box, and deliberately. Below 760px the
checkout collapses its summary — `.kbb-checkout .panels { max-height:148px;
overflow:hidden }` in `resources/css/kbb/kbb-checkout.css` — which SLICED the
popup to one visible line in the first shot of that screen. `position:fixed` is
the only thing that escapes an overflow:hidden ancestor, and it needs no
measurement: the box is pinned to the viewport's own edges with logical insets,
14px each side, so 390 − 28 = 362. `scrollWidth` is still 390.

The cart panel and the cart page do not clip, and the desktop summary is not
collapsed, so none of those three takes that branch — they keep the ordinary
230px box anchored to the row.

`labelsOnCircles` is `[]` on every shot — no initials, no quantity badge, no
text of any kind on a member thumbnail, which is what the owner asked for.

The popup lists, on all three surfaces:
`2× Heartleaf 77% Soothing Toner 250ml`, `1× Azelaic Acid 10 Serum 30ml`,
`1× Rice 70 Glow Milky Toner 250ml` — names and quantities, nothing else.

`aria-expanded` reads `false` closed and `true` open; `aria-controls` names the
popup's id (`kset-d3` in the panel, `kset-c3` on the cart page, `kset-o3` on the
checkout), so the button and the box are really associated.

## Catalog → Sets

| shot | viewport | `scrollWidth` | `#content` scrollWidth | breadcrumb | title | sidebar rows |
|---|---|---|---|---|---|---|
| `admin-sets-list-390` | 390 | **390** | 390 | Catalog | Sets | 1 |
| `admin-sets-list-1280` | 1280 | **1280** | 1032 | Catalog | Sets | 1 |
| `admin-sets-create-390` | 390 | **390** | 390 | Catalog | Sets | 1 |
| `admin-sets-create-1280` | 1280 | **1280** | 1032 | Catalog | Sets | 1 |

The create shot has three members chosen and reads
`Items: 3 · Bought separately: AED 464.00 · Set price: AED 179.00 · Saving: AED 285.00`.

Exactly one sidebar row is registered, at both widths, which is the count
`kbbAddNavEntry` guarantees and `SetRoutesWiredTest` pins for the include.
