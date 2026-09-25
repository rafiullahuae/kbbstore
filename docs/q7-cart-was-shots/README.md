# Lane Q7 — the cart's struck "was" on a dearer option, and the feed's compare-at

Four shots, one page × two widths × before and after. Chromium 1194
(`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`) driven by Playwright,
`browser.newContext({viewport})` rather than `page.setViewportSize` (the README
in `docs/rtl-shots/` records wrong-sized shots from the latter on this box),
`deviceScaleFactor: 1`, `reducedMotion: 'reduce'`, animations and transitions
frozen by an injected stylesheet, `fullPage: true`.

Two `php -S` instances against **one** seeded SQLite database, each with its own
web root:

| | port | tree |
|---|---|---|
| before | 8642 | a detached worktree at `dfe28fe`, this branch's base |
| after | 8641 | this branch |

The basket is built the way a shopper builds one — `POST /api/cart/add` from the
page, with the CSRF token off `window.KBB.csrf` — rather than written into the
database, so the shots are of a cart the shop itself created.

## The subject

Two products in the seeded catalogue, and one basket holding one of each:

- `Water Glow Cushion`, slug `q7-cushion`, `type = variable`, `price = NULL`,
  two variations — AED 120 → AED 90 and **AED 190 → AED 140** — with the sale
  window on the PARENT row, open now. The basket holds the **dearer** option.
- `Heartleaf Calming Toner`, a simple product, AED 200 → AED 50.

Subtotal AED 190. This is the exact shape a WooCommerce export produces: the
money on the variations, the schedule on the parent, nothing in
`products.price` or `products.sale_price`.

## What moved

| | before | after |
|---|---|---|
| cushion line (the dearer option) | `AED 140` | `AED 140` ~~`AED 190`~~ |
| toner line (simple, on sale) | `AED 50` ~~`AED 200`~~ | unchanged |
| Order value row | ~~`AED 340`~~ `AED 190` | ~~`AED 390`~~ `AED 190` |
| Order total | `AED 190` | `AED 190` |

The price a shopper pays did not move. What was added is the statement that the
option in the basket was marked down from AED 190 — a saving of AED 50 the cart
was silent about, because the only compare-at a variable line had was the
PARENT's from-price (AED 120), which sits **below** the AED 140 being charged.
`cart-inner.blade.php` clamped that with `max()` rather than print it, which was
right: `AED 120` struck beside `AED 140` is a worse screen than no strike at
all. `ProductVariant::compareAtPrice()` is the figure that clamp was waiting
for.

The order-value row follows the lines because both now read one closure,
`$kbbLineWas()`: AED 190 + AED 200 = AED 390, which is the sum of exactly the
two figures struck above it.

## `document.documentElement.scrollWidth`

No horizontal overflow at either width, before or after — `scrollWidth` equals
`clientWidth` in all four captures.

| shot | clientWidth | scrollWidth | full-page height |
|---|---|---|---|
| `cart-390-before` | 390 | 390 | 900 |
| `cart-390-after` | 390 | 390 | 900 |
| `cart-1280-before` | 1280 | 1280 | 2028 |
| `cart-1280-after` | 1280 | 1280 | 2028 |

Neither width changes height. The struck figure goes into the `.cwas` span
already in the price cell — the same span the toner line beside it has been
using all along — so nothing reflows around it.

## Query counts, before → after

Measured with a warm-up render first (`Setting::map()` memoises in a
process-level static, so the first render of a process is dearer than every
later one) and `forgetScopedInstances()` before the measured one (a test does
not reboot the container between renders, so without it the count measures the
memo rather than the page). Stable across repeated runs.

| page | before | after |
|---|---|---|
| `/cart`, this basket | 10 | **9** |

**The page lost a statement rather than gaining one, and the reason is worth
writing down.** Before, the struck figure on a variable line came from
`Product::compareAtPrice()`, which resolves `App\Services\VariantPricing` and
runs its grouped read over `product_variants`. A line that holds a variation
does not need it: the variation carries its own regular price, and
`ProductVariant::isOnSale()` reads the parent's window off the row the cart has
already eager-loaded. So the cart no longer runs the grouped read at all unless
a line holds a variable product with no variation chosen.

`ProductVariant::isOnSale()` REFUSES to load a parent it was not given rather
than lazily fetching one — `Store\CartController::loadCart()` eager-loads
`items.variant` and not `variant.product`, so reading it would have been one
statement per basket line. `CartVariantWasPriceTest` pins that at the model
(zero queries for an unloaded parent) and at the page (reads of `products` are
the same for one variable line as for five).

▲ **One N+1 on this page that this lane did NOT fix and does not own.**
`$item->variant?->label()` reads the `attributeValues` relation, which
`loadCart()` does not eager-load, so a basket of five variant lines runs five
`attribute_values` joins. It predates this change and the fix is one line in
`Store\CartController::loadCart()`'s eager-load list —
`'items.variant.attributeValues'` — in a file this lane does not own.

## The feed, which has no picture worth taking

`/api/products/{slug}` for the same cushion, captured off the two servers above:

```
BEFORE                                AFTER
{                                     {
  "slug": "q7-cushion",                 "slug": "q7-cushion",
  "price": 9000,                        "price": 9000,
  "sale_price": null,                   "sale_price": null,
                                        "compare_at_price": 12000,
  ...                                   ...
}                                     }
```

and for the simple toner beside it, `price: 20000`, `sale_price: 5000`,
`compare_at_price: 20000`.

`price` and `sale_price` are byte-identical before and after. The new key is the
figure to strike through, or `null` when there is nothing to strike, and it
means the same thing for both kinds of product — which is what lets a consumer
draw the badge with one rule:

```
charged = sale_price ?? price
was     = compare_at_price          # null → no strike
percent = 1 - charged / compare_at_price
```

The alternative — redefining `price` as the compare-at for variable rows and
publishing the charged figure as `sale_price`, so the two existing keys mean one
thing for every product — is a **contract change on an unauthenticated feed**
and is the owner's call, not a lane's. It is written out in full at the top of
`tests/Feature/ApiCompareAtPriceTest.php` and in `Product::toApi()`. Nothing in
this change forecloses it: under that contract this key would simply equal
`price` on every row.
