# Lane Q8 — the cart's eager-load list, measured rather than asserted

No screenshot in this round, and that is the honest answer rather than a gap.
Nothing a shopper sees moved: the /cart page rendered from a four-line basket
(one of them a two-axis variation) is **byte-identical before and after this
change apart from the CSRF token**, which is per-request. The option chips read
`00ml`, `10ml / Rose`, `20ml`, `30ml` in both captures — including the ` / `
order, which is the one thing an eager load *can* change and which
`CartLineEagerLoadTest` now pins. What moved is the number of statements the
page runs, so the evidence here is counts.

## How every number below was measured

One HTTP request per row, through Laravel's test client with the cart cookie
unencrypted, on file-backed SQLite.

- **One warm-up request, discarded.** `Setting::map()` memoises in a
  process-level static as well as the cache, so the first request of a process
  is dearer than every later one and a cold-against-warm comparison measures the
  warm-up.
- **`SettingsService::forgetMemo()` and `app()->forgetScopedInstances()` before
  every measured request.** A test process keeps one container across many
  requests, so a `scoped()` binding — `CartService` and `SettingsService` among
  them — answers the second request out of the first one's memo.
- **A different product per basket line**, so lines cannot be batched by
  accident.

### ▲ A third trap, not previously written down

`Illuminate\Routing\Route::getController()` **caches the controller instance on
the Route object**, and the `Router` lives for the whole test process. So from
the second request to a route onward, the controller is the one built for the
*first* request and it still holds the `CartService` that `forgetScopedInstances()`
has since evicted — while the view composers resolve a fresh one. The two halves
of the request then disagree about whether the basket has been loaded, and
`CartDrawerComposer` re-runs a display load that `CartService::$displayLoaded`
exists to prevent.

Cost: roughly four extra statements per measured request (`cart_items` ×2 more,
`product_variants` ×1 more, `products` ×1 more) that **no real visitor pays** —
under PHP-FPM the container, the router and the controller are all thrown away
between requests. The first request of a process shows the production shape and
has none of them.

It does not affect anything below, because every comparison here is between two
requests measured the same way, and the defect was in the *slope*. But an
absolute total taken this way is about four too high.

## Task 1 — the N+1 Lane Q7 reported. It is real.

`store/cart-inner.blade.php:170` calls `$item->variant?->label()`;
`ProductVariant::label()` reads `attributeValues`;
`Store\CartController::loadCart()` did not eager-load it. One
`product_variant_attribute_value` join per variant line, singular `= ?` rather
than `in (...)`.

| /cart, variant lines | 1 | 2 | 3 | 5 | 8 |
|---|---|---|---|---|---|
| total queries, **before** | 10 | 11 | 12 | 14 | **17** |
| `attribute_values` reads, before | 1 | 2 | 3 | 5 | **8** |
| total queries, **after** | 10 | 10 | 10 | 10 | **10** |
| `attribute_values` reads, after | 1 | 1 | 1 | 1 | **1** |

Slope exactly 1.0 before, 0.0 after. The fix is one entry in the eager-load
list, spelled exactly as `Store\CheckoutController::loadCart()` spells it:

```php
'items.variant.attributeValues:id,attribute_id,name',
```

The shopper-facing path that re-renders this partial pays it too —
`POST /api/cart/update?with_page=1`, which is what the `+` and `−` buttons call.
Measured **13 queries flat** from one line to eight after the change.

### Why the previous round could not reproduce it

Lane Q8's earlier attempt saw **one rendered line whatever it put in the
basket**, so its query count was flat for the wrong reason. That was the fixture,
not the page:

- `/cart` renders exactly one `.ci` block per `cart_items` row. Measured
  directly: 5 rows → 5 lines, for five variations of one parent **and** for five
  distinct simple products. There is no dedup anywhere between
  `Cart::items()` and the `@foreach` in `cart-inner.blade.php`.
- The one thing in this codebase that collapses a basket to a single line is
  **`CartService::add()`**, which merges on `product_id` + `product_variant_id`
  and increments `quantity` instead of inserting a second row. So a fixture that
  builds its basket by calling that method — or by `POST /api/cart/add`, which
  is how `docs/q7-cart-was-shots/` built its screenshots — gets **one line of
  quantity N** for N adds of the same product. That is correct behaviour and it
  is the only shape that produces the symptom described.

No shopper is losing items.

## Task 2 — the rest of the eager-load lists

`loadCart()`'s old docblock claimed "one query for the cart, one for its lines
with products and brands", and every relation it named *was* loaded. It was short
by one, and the missing one was the only relation read inside the line loop.
Everything else checks out:

| page | relation touched | loaded? | grows with lines? |
|---|---|---|---|
| /cart line | `item->product` | eager | no |
| /cart line | `product->brand` | eager | no |
| /cart line | `item->variant` | eager | no |
| /cart line | `variant->attributeValues` | **was lazy — fixed** | **was +1/line** |
| /cart line | `variant->isOnSale()` → parent window | handed over with `setRelation()` | no (Q7) |
| /cart totals | `cart->coupon` | eager | no |
| drawer line | product, brand, variant | eager | no |
| drawer | — | — | **flat at 12 from 2 lines to 8** |
| /checkout line | product, brand, variant, attributeValues | eager | **flat at 12 from 1 line to 8** |

The checkout is the reference and it is genuinely flat. It is now pinned by a
case in `CartLineEagerLoadTest`, because removing its `attributeValues` entry
produces **no visible defect** — `CartService::lineLabel()` checks
`relationLoaded()` and silently omits the size from the order snapshot — so
nothing would have caught it.

### One N+1 found and NOT fixed: the recommended rail

`App\Services\CartPage::recommended()` runs
`Product::query()->whereIn('id', $ids)->…->get()` with **no `with('brand')` and
no column list**, and `cart-inner.blade.php` reads `$recProduct->brand?->name`
for every card.

| /cart, 1 basket line | rail off | rail with 6 products |
|---|---|---|
| total queries | 12 | **19** |
| `select * from brands where id = ?` | 0 | **6** |

One `brands` read per card, and `MAX_REC` is 24, so up to 24. The rows are also
fetched with `select *`, which pulls `description` (longText) plus the `seo`,
`meta_feed` and `custom_tabs` json columns for every card on a cart page.

**Latent today**, which is why it is reported rather than rushed: the rail only
renders on the `squeeze` layout and `layout` ships as `classic`, and `rec_ids`
ships empty, so `recommended()` returns without a query on the live shop. It
fires the moment the owner switches the layout and picks products — i.e. exactly
when somebody is looking.

Not fixed here because `app/Services/CartPage.php` is not this lane's file. The
fix is one line: `->with('brand:id,name,slug')`, plus a column list.

## What was deliberately left alone

`ProductVariant::isOnSale()` refuses to lazily fetch an unloaded parent, and the
obvious symmetry would be for `label()` to refuse an unloaded `attributeValues`
the same way. It does not, and should not. `isOnSale()` failing closed answers
"no sale I can vouch for", which is a safe sentence; `label()` failing closed
**drops the size off a basket line**, leaving a shopper with two lines of the
same cushion and nothing to tell them apart. The cost is pinned where it can be
measured instead of being bought with a silent content regression.
