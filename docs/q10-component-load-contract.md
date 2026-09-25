# Lane Q10 — what a component needs loaded, said out loud

Lane Q9 measured eleven storefront page-and-variable pairs and found ten flat.
Its closing paragraph named the thing that makes that result fragile:

> `<x-product-grid>` reads `$p->categories->first()` per tile and nothing pins
> it — flat today **only because** its single caller `store/brands.blade.php`
> happens to eager-load `categories:id,name`.

This round took that as a claim to check rather than a fact to act on. Two parts
of it were wrong, one in each direction, and the correction is the reason the
guard this round ships looks the way it does.

**No screenshot, and that is the honest answer rather than a gap.** Nothing a
shopper sees moved. The proof is stronger than a picture: all **eight** pages
that render a contracted template — /shop, the brand landing page, a product
page, /routines/acne, /concern/acne/, a CMS page carrying `[kbb_products]`,
/my-wishlist and the homepage — are **byte-identical before and after**, 500,464
bytes of HTML in total, combined md5 `f18eae073e3a25ae52354d860807fdd9` on both
sides. Two things are masked and both are per-request rather than per-change:
the 40-character CSRF token, and the product page's dispatch countdown, which
ticked from `9h 37m` to `9h 38m` between the two captures. Everything this lane
added to a template is inside a `{{-- --}}` block that was already there.

## Task 1 — the real shape, which is not quite the shape reported

### Every component, and whether it is handed a model

There are five files under `resources/views/components/**`. Three of them cannot
have this defect at all:

| component | handed | reads a relation? |
|---|---|---|
| `field.blade.php` | strings | no |
| `checkout/field.blade.php` | strings | no |
| `kbb-banner.blade.php` | the resolved array `PageBanner::forModel()` returns | no |
| **`product-card.blade.php`** | **one `Product`** | **`brand`** |
| **`product-grid.blade.php`** | **a collection of `Product`** | **`brand`, `categories`** |

And one file that is not under `components/` and behaves exactly like one:
**`resources/views/partials/home/grid.blade.php`**, handed a collection and
reading `$p->brand`, used by three unrelated pages. Leaving it out would have
made this guard's boundary a directory name rather than the defect, so it is
contracted here. It is **not edited** by this lane — `partials/home/` is another
lane's ground — so its contract is declared in the guard only.

Everything else a tile calls turns out to be columns or a batched read, and that
was checked rather than assumed:

- `Product::requiresVariant()` falls back to `variants()->exists()` — **one query
  per tile** — but only when `type` was not SELECTed. Every `CARD_COLUMNS` list
  in the application carries `type`, which is why no tile pays it.
- `VariantPricing::range()` answers from a per-request memo filled by one grouped
  query, and only for a variable parent with a NULL `price`.
- `ProductLabels::for()` reads `stock_status`, `created_at` and `featured`, all
  columns.
- `Product::url()`, `altFor()`, `compareAtPrice()`, `isOnSale()` — columns and
  the memo above.

### ▲ `<x-product-grid>` does not have one caller. It has two.

Q9's grep was for the Blade tag. `App\Support\Shortcodes::products()` renders the
same file as a **view**:

```php
return view('components.product-grid', [...])->render();      // Shortcodes.php:288
```

That is `[kbb_products]`, which any CMS page or journal article can carry. It
eager-loads `brand:id,name,slug` **and** `categories:id,name,slug`, so it is flat
— but "one caller" was the premise of the "it is flat by coincidence" argument
and the coincidence was twice as wide as reported. A guard built on a grep for
`<x-product-grid` would have covered half of it.

### Every caller, and what it provides

| template | caller | query | provides |
|---|---|---|---|
| `product-grid` | `store/brands.blade.php` | `Store\BrandController::show()` | `brand`, `categories` ✔ |
| `product-grid` | `Support\Shortcodes::products()` | in a `Cache::remember()` closure | `brand`, `categories` ✔ |
| `product-card` | `store/shop.blade.php` | `Store\ShopController::index()` | `brand` ✔ |
| `product-card` | `store/product.blade.php` | `Store\ProductController::related()` | `brand` ✔ |
| `product-card` | `store/routines.blade.php` | `Services\BuildMyRoutine::candidatesByRole()` | `brand` ✔ |
| `partials/home/grid` | `store/collection.blade.php` | `Store\CollectionController::show()` / `concern()` | `brand` ✔ |
| `partials/home/grid` | `store/wishlist.blade.php` | `Store\WishlistController::index()` | `brand` ✔ |
| `partials/home/grid` | `store/home.blade.php` | `Store\HomeController` | `brand` ✔ |

**Eight call sites, eight correct queries, and not one of them is required to be
correct by anything.** That is the finding, and it is exactly as reported — the
shop is right by coincidence.

### ▲ The latent case, proved rather than argued

The brand landing page, measured at 1, 2, 5 and 10 tiles, each tile carrying its
own brand and its own category so a lazy read cannot batch by luck. Warm-up
request discarded, `SettingsService::forgetMemo()` and
`app()->forgetScopedInstances()` before each measured request, and **every row
states what the page actually rendered**:

| `/korean-skincare-brands/{slug}/` | 1 | 2 | 5 | 10 | slope |
|---|---|---|---|---|---|
| tiles rendered | 1 | 2 | 5 | 10 | — |
| statements, **as shipped** | 5 | 5 | 5 | 5 | **0** |
| `category_product` reads, as shipped | 1 | 1 | 1 | 1 | 0 |
| statements, **without `categories:id,name`** | 5 | 6 | 9 | 14 | **1.0** |
| `category_product` reads, without it | 1 | 2 | 5 | 10 | 1.0 |
| statements, **without either eager load** | 5 | 7 | 13 | 23 | **2.0** |
| `brands` reads, without either | 2 | 3 | 6 | 11 | 1.0 |

So the reported defect is real, it is worth exactly one statement per tile per
missing relation, and it is invisible until somebody writes the caller that
forgets.

## Task 2 — the guard, and why this shape

`tests/Feature/ComponentLoadContractTest.php`. Seven cases.

The requirement is now **declared at the top of each component**, inside the
`{{-- --}}` block that was already there, naming the relations, the cost of
omitting one, the exact `->with(...)` a caller writes, and every caller today.
The guard holds the same contract in a machine-readable form and checks four
things:

1. the relation names it looks for are **derived from `app/Models/*.php`**, not
   listed — the same argument `VariantPriceMemoRawWriteGuardTest` makes for
   checking `MEMO_TABLES` against the real statement;
2. each contracted template **declares exactly the relations it reads**, re-derived
   from the template's own source;
3. **every component under `components/` that reads a relation is contracted** —
   this is what catches the third component, written six months from now;
4. **every call site is named**, in both directions: a new one reddens the file
   with its path and line, and an entry naming a file that no longer calls
   anything reddens it too.

And then the part that makes it a measurement rather than a list: each named page
is **rendered with `Model::preventLazyLoading()` on and a recording callback
installed**, and any relation read off an un-eager-loaded row is reported with
the model, the relation and the count.

### Why not one of the other three shapes

**A component that loads what it needs (`loadMissing`)** fixes the grid and makes
the card worse. `<x-product-card>` is handed ONE model, so a `loadMissing` inside
it is one query per card — the N+1 it was meant to remove, now written into the
component where no caller can eager-load it away.

**A scanner that walks every caller and checks its eager load** cannot be written
honestly here. The query behind `<x-product-card :product="$step['chosen']" />`
is in `App\Services\BuildMyRoutine`; the query behind the grid on a CMS page is
inside a `Cache::remember()` closure in `App\Support\Shortcodes`. Statically
pairing a Blade variable with the query that filled it is guesswork, and a guard
that guesses gets silenced.

**`Model::preventLazyLoading()` globally** is the thorough answer, and it was
**priced rather than argued about**. The whole suite was run once with it enabled
and a recording callback in `tests/Pest.php` (the exception replaced by a logger,
so nothing failed and everything was counted):

| what it catches, whole suite | count | where |
|---|---|---|
| `Category::$parent` | **1,272** | `app/Models/Category.php:44`, inside `buildPath()` |
| `Product::$brand` | 27 | `app/Http/Controllers/Admin/CartPageApiController.php:181` |
| `Order::$items` | 14 | `app/Services/Mail/OrderEmailPresenter.php:460` |
| `OrderItem::$product` | 6 | a compiled Blade view |
| `ProductVariant::$product` | 4 | `app/Models/ProductVariant.php:131`, `saleWindowOpen()` |
| `ProductVariant::$attributeValues` | 2 | `app/Models/ProductVariant.php:258`, `label()` |
| **total** | **1,325** | **6 sites** |

That run was **345.00s against the baseline's 349.22s**, so the listener itself
is free. The cost is not the listener, it is the six sites:

- `Category::buildPath()` walks its ancestors one row at a time and **cannot be
  eager-loaded** — the depth is not known in advance. 96% of every violation in
  the suite is this one method. It would need a permanent exemption inside the
  model, which is a global switch with a hole in it.
- The two `ProductVariant` sites are **deliberate, and two earlier rounds decided
  them on purpose**. `saleWindowOpen()` reads the parent to avoid repricing a
  live line; `label()` is documented in `docs/q8-cart-eager-loads.md` under "what
  was deliberately left alone" — failing closed there **drops the size off a
  basket line**. Turning the global switch on means reopening both decisions.
- `Admin\CartPageApiController` is a **real N+1** (below), and the email
  presenter probably is too. Neither is this lane's file.

So a global switch costs three exemptions that argue against decisions already
made, to catch two defects in code this lane does not own. **Scoped to the eight
renders this file makes, the same mechanism reports ZERO noise** — measured, not
assumed. That is the whole case for the narrow version.

### ▲ The one thing this mechanism cannot see, stated up front

`Illuminate\Database\Eloquent\Builder::hydrate()` copies
`Model::preventsLazyLoading()` onto each row it builds, and **only when the result
has more than one row**:

```php
if (count($items) > 1) { $model->preventsLazyLoading = Model::preventsLazyLoading(); }
```

A page that renders ONE tile therefore reports nothing, however badly its query is
written. This is mutation 16 below, and it came back **green**. Every fixture in
the guard renders at least three of the thing it is about, and every case asserts
that count before it asserts anything else.

### What it costs, measured

| | duration | result |
|---|---|---|
| full suite, before | **349.22s** | 5907 passed, 26 skipped, 0 failed |
| full suite, with the guard | **353.73s** | 5914 passed, 26 skipped, 0 failed |

Both runs on the same machine with no other lane's suite running beside them —
which had to be waited for, because a concurrent run moves this number by more
than the thing being measured.

**+4.51s for seven cases, and the file's own reported duration is 2.50s of which
1.80s is the first case's application boot — the fixed price every Pest file
pays.** The marginal cost of the seven cases is about **0.7s** of that: eight
page renders, two component renders and a scan of 1,100 source files. The
difference between +4.51s and 2.50s is the boot, counted once in the file's own
timing and paid for real in the suite. Set against
`ColumnWidthGuardTest`'s 338.80s-against-342.64s precedent, this is a real but
small number rather than one inside the noise, and it buys a defect class that no
budget and no slope test can see.

## Where this sits in the admin

**Nothing new to find, and no setting changed value or was added.** The pages the
guard renders are the shop's own: Appearance → Cart page is untouched, and the
only admin screen named anywhere in this round is **Appearance → Cart page →
Recommended rail**, whose product picker is the un-fixed N+1 below.

## Mutations — all seventeen run, including the one that came back green

Against `tests/Feature/ComponentLoadContractTest.php`.

**The eight eager loads, one per call site.** Each was deleted, the file run, and
the eager load put back. Every one goes red on the page-render case, naming the
page:

| # | mutation | RUN |
|---|---|---|
| 1 | `Store\BrandController::show()` — drop `categories:id,name` | **1 failed**, names `/korean-skincare-brands/clc-house/` |
| 2 | `Store\ShopController::index()` — drop `brand:id,name,slug` | **1 failed**, names `/shop` |
| 3 | `Store\ProductController::related()` — drop `brand:id,name,slug` | **1 failed**, names `/product/clc-a` |
| 4 | `Services\BuildMyRoutine::candidatesByRole()` — drop `brand` | **1 failed**, names `/routines/acne` |
| 5 | `Support\Shortcodes::products()` — drop `categories:id,name,slug` | **1 failed**, names `/about` |
| 6 | `Store\WishlistController::index()` — drop `brand:id,name,slug` | **1 failed**, names `/my-wishlist` |
| 7 | `Store\HomeController` — drop `brand:id,name,slug` | **1 failed**, names `/` |
| 8 | `Store\CollectionController::concern()` — drop `brand:id,name,slug` | **1 failed**, names `/concern/acne/` |

**The scanner halves.**

9. **Add `<x-product-card :product="$p" />` to `store/checkout-success.blade.php`**,
   a view nothing covers. RUN: **1 failed** —
   `resources/views/store/checkout-success.blade.php:303  renders resources/views/components/product-card.blade.php`.
10. **Make `product-card` read `$product->tags`** without declaring it. RUN:
    **2 failed** — the contract case ("declares [brand] and reads [brand, tags]")
    and the page-render case, which sees the lazy read on five pages at once.
11. **Add a new component** `components/zz-mutation-tile.blade.php` reading
    `$item->brand`. RUN: **1 failed** — "A component reads a relation off a model
    it was handed and does not say so."
12. **Break the relation-name derivation** (drop `belongsTo` from the pattern).
    RUN: **2 failed** — the derivation's own case, and then all three templates
    reporting "declares [brand, categories] and reads []". Without case 1 this
    mutation would have made the whole file pass while asserting nothing.
13. **Add a stale `CLC_COVERED` entry** naming `store/blog.blade.php`. RUN:
    **1 failed** — "CLC_COVERED names a file that no longer renders a contracted
    template".
14. **Fetch the proof case's rows OUTSIDE the guard window.** RUN: **1 failed**.
    ▲ **This is the shape that came back green while the file was being written.**
    The first version of that case fetched its collection before `clcWatch()` was
    entered and reported no violations at all — against a query with no eager load
    that provably costs one statement per tile. `hydrate()` sets the flag as it
    builds the rows, so a collection built before the guard was on carries
    `preventsLazyLoading = false` and is invisible. The case now fetches inside,
    and this mutation is what proves it.
15. **Empty the catalogue before every page render.** RUN: **1 failed** — the
    fixture floor fires first.

**The blind spot, as an assertion.**

16. **The same bare query with ONE row instead of six**, expectation changed to
    match. RUN: **7 passed — GREEN, and it should be.** The defect is present and
    identical; `hydrate()` simply does not flag a single-row result, so the guard
    sees nothing. This is the mechanism's permanent limit and is why every fixture
    in the file renders at least three tiles and asserts it.
17. **Remove the tile floor AND empty the catalogue AND break
    `BrandController`'s eager load** — the attempt to slip a real defect past the
    guard on an empty fixture. RUN: **1 failed**, but on `assertOk()` rather than
    on the floor: with no products, `/routines/acne` and `/concern/acne/` **404**
    instead of rendering empty. A second, independent reason the fixture cannot
    silently vanish, found by trying.

## Task 3 — nothing to fix, which is the honest answer

Every one of the eight call sites already eager-loads what its template reads.
There is no latent N+1 left in this lane's ground to close, and the deliverable
of the round is that the eight cannot quietly become seven.

## Found and NOT fixed

**`Admin\CartPageApiController::search()` is a live N+1.** `Product::query()
->where(...)->get()` with no `with('brand')`, and `card()` reads
`$product->brand?->name` for every row — one `select * from brands where id = ?`
per product, `PER_PAGE` of them, on the picker behind **Appearance → Cart page →
Recommended rail**. Measured at 27 lazy reads across the suite. The fix is one
line, `->with('brand:id,name,slug')`, in both branches of that method.
`app/Http/Controllers/Admin/**` is not this lane's ground, so it is reported
rather than edited — the same call `docs/q8-cart-eager-loads.md` made about the
recommended rail, which the next round then fixed.

**`OrderEmailPresenter::…:460` reads `$order->items` lazily** (14 in the suite),
and a compiled order view reads `$item->product` (6). Both are almost certainly
the same one-line shape. Not measured on a page, not this lane's files.

**`Category::buildPath()` walks its ancestors one query at a time.** Production
nests four deep, so a category whose path is not cached costs up to four
single-row reads. It is by far the largest source of lazy loading in this
codebase (1,272 of 1,325) and it is the reason a global `preventLazyLoading()` is
not a one-line change. Fixing it means a recursive CTE or a materialised path
column, which is a migration and somebody's decision, not a measurement.
