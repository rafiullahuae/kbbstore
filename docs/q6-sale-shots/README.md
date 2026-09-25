# Lane Q6 — a markdown on a variable product, made visible

Twelve shots, three pages × two widths × before and after. Chromium 1194
(`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`) driven by Playwright,
`browser.newContext({viewport})` rather than `page.setViewportSize` (the README
in `docs/rtl-shots/` records wrong-sized shots from the latter on this box),
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, animations and transitions
frozen by an injected stylesheet, `fullPage: true`.

Two `php -S` instances against **one** seeded SQLite database, each with its own
web root and `KBB_PUBLIC_PATH` pointing at it:

| | port | tree |
|---|---|---|
| before | 8642 | a detached worktree at `db672d9`, this branch's base |
| after | 8641 | this branch |

## The subject

One product, created in the seeded catalogue:

- `Water Glow Cushion`, slug `q6-cushion`, `type = variable`, `price = NULL`
- two variations: AED 120 → AED 90, and AED 190 → AED 140
- the sale window on the PARENT row, open now (`sale_starts_at` yesterday,
  `sale_ends_at` next week)

which is the exact shape a WooCommerce export produces: the money on the
variations, the schedule on the parent, and nothing at all in `products.price`
or `products.sale_price`.

## What moved

| | before | after |
|---|---|---|
| tile price cell (`/shop/`) | `AED 90 – AED 140` | `AED 90 – AED 140` (unchanged) |
| tile badge | none | `-25% OFF` |
| `/shop/?sale=1` | 6 products, this one absent | 7 products, this one present |
| product page `.bb-price` | `AED 90 – AED 140` | `AED 90 – AED 140` `AED 120` `-25%` |
| quick-view modal `.qv-price` | `AED 90` | `AED 120` `AED 90` `-25%` |
| AggregateOffer `priceValidUntil` | absent | the date the markdown ends |

The price a shopper reads did not move anywhere. What was added is the statement
that it is a markdown, on the two surfaces that draw one and in the listing that
collects them.

The AggregateOffer's `lowPrice`/`highPrice` were already right in both — they
are built from `ProductVariant::effectivePrice()`, which honours the parent's
window. The one field the parent-row defect reached is `priceValidUntil`, which
`Store\ProductController` publishes only when `isOnSale()` — so a genuinely
marked-down variable product told Google nothing about when the price stops
applying.

Worth noting in the before shots: the product page's **per-option** chips
already read `Save 25%` and `Save 26%`. The shop knew perfectly well that these
options were marked down — it just could not say so about the product.

## `document.documentElement.scrollWidth`

No horizontal overflow at either width, before or after — `scrollWidth` equals
`clientWidth` in all twelve captures.

| shot | clientWidth | scrollWidth |
|---|---|---|
| `tile-390-{before,after}` | 390 | 390 |
| `tile-1280-{before,after}` | 1280 | 1280 |
| `onsale-390-{before,after}` | 390 | 390 |
| `onsale-1280-{before,after}` | 1280 | 1280 |
| `pdp-390-{before,after}` | 390 | 390 |
| `pdp-1280-{before,after}` | 1280 | 1280 |

## Query counts, before → after

Measured with a warm-up request first (`Setting::map()` memoises in a
process-level static, so the first request of a process is dearer than every
later one) and `forgetScopedInstances()` before each measured request (a test
does not reboot the container between them, so without it the count measures
the memo rather than the page).

| page | before | after |
|---|---|---|
| `/shop/` | 5 | 5 |
| `/shop/?sale=1` | 5 | 5 |
| `/product/<variable, marked down>/` | 12 | 12 |
| `/product/<simple, on sale>/` | 8 | 8 |

No page gained a statement. The compare-at is `MIN(v.price)` on the grouped row
`VariantPricing::load()` already fetched, and `EffectivePrice::regularSql()`
carries no bindings and lives inside the one WHERE that was already there.

## Full-page heights, before → after

| shot | before | after | |
|---|---|---|---|
| `tile-390` | 6636 | 6636 | unchanged |
| `tile-1280` | 3489 | 3489 | unchanged |
| `onsale-390` | 2584 | 2977 | one more tile in the list |
| `onsale-1280` | 1537 | 1537 | the extra tile fills an existing row |
| `pdp-390` | 3789 | 3819 | +30px, the line the `AED 120` and `-25%` sit on |
| `pdp-1280` | 2438 | 2438 | unchanged; the pair fits the existing line |

The tile pages do not change height at all, which is the thing to check: the
badge is absolutely positioned inside the photograph frame, and that frame is a
fixed CSS box, so adding one moves nothing around it.

## The cart, and why there is no shot of it

The second commit on this branch changes `store/cart-inner.blade.php`, and it is
a regression avoided rather than a change shipped: against the shop as it stands
today the struck order-value row is **identical for every basket that exists**,
because a simple product on sale has a compare-at above its line by definition
and one that is not on sale takes the line. What the commit prevents is the row
silently dropping a marked-down variable line to zero once `isOnSale()` started
answering true for one — measured at `AED 200` where the honest figure is
`AED 340`.

An arithmetic figure is pinned better by a number than by a picture, so the
proof is the test and its two mutations, both run:

| | order-value row |
|---|---|
| this branch | `AED 340` beside a subtotal of `AED 190` |
| without the `max()` | `AED 320` — the line undercounted by the parent's from-price |
| with the original `(int) $p->price` | `AED 200` — the variable line contributing nothing |

The one genuinely new pixel on that page is the recommendation tile's struck
price for a marked-down variable product, which is the same `AED 120` / `AED 90`
/ `-25%` pair the `/shop` shots above already show at both widths.
