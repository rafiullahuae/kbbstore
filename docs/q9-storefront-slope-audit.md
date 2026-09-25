# Lane Q9 — the rest of the storefront, measured by slope

Lane Q8's one-line conclusion was that **a query budget caps a total without
saying how the total grows**, and that the cure is to measure the same page at
several sizes of whatever repeats on it. This round applies that to the rest of
the shop: eleven page-and-variable pairs, four sizes each, plus the one N+1 Q8
found and could not fix because the file was not its to edit.

**No screenshot in this round, and that is the honest answer rather than a gap.**
Nothing a shopper sees moved. The proof is stronger than a picture: /cart
rendered with a four-card rail — one plain product, one on a live markdown, one
whose markdown expired last week, and one with an image — is **byte-identical
before and after this change apart from the CSRF token**, which is per-request.
Both captures are 162,299 bytes and the masked files share an md5. What moved is
the number of statements the page runs and the number of columns it drags back,
so the evidence here is counts.

## How every number below was measured

One HTTP request per row, through Laravel's test client with the cart cookie
unencrypted, on file-backed SQLite. The three traps Q8 wrote down, all handled:

1. **One warm-up request, discarded.** `Setting::map()` memoises in a
   process-level static, so the first request of a process is dearer.
2. **`SettingsService::forgetMemo()` and `app()->forgetScopedInstances()` before
   every measured request**, or the second request answers out of the first
   one's scoped memos.
3. **No absolute total is quoted as production shape.** `Route::getController()`
   caches the controller on the Route object and the Router outlives the
   container, so from the second request onward the controller holds services
   the reset has evicted — about four statements no real visitor pays. It is
   identical at both sizes, so it cancels out of a comparison and would poison a
   ceiling. Everything below is a comparison.

And a fourth, **which this round paid for and Q8 did not have to**:

> ### ▲ A fresh fixture per size can measure nothing at all
>
> The rail's first measurement rebuilt the cart for each size. From the second
> size onward the Route-cached controller answered out of a `CartService` bound
> to the cart that had just been deleted, so the page rendered an **empty
> basket**: 7 statements, no rail, and a perfectly flat line across 2, 5 and 10
> cards. It looks exactly like a page that is already fine. The fix is to build
> the cart **once** and vary only the thing under measurement.

And the rule that makes any of it mean anything:

> **Every row below states what the page actually RENDERED at each size.** A
> flat count over a fixture that renders the same thing twice is precisely how
> both of the last two defects survived their own budget. Three of this round's
> ten fixtures rendered **zero** of the thing they were varying on the first
> attempt, and would have been reported as "flat" by anyone not counting.

## Task 2 — the slope table

`n` is the number of the repeated thing; the cell is `statements / rendered`.

| page | varying | 1 | 2 | 5 | 10 | slope |
|---|---|---|---|---|---|---|
| `/shop` | tiles | 5 / 1 | 5 / 2 | 5 / 5 | 5 / 10 | **0** |
| `/product-category/{c}` | tiles | 6 / 1 | 6 / 2 | 6 / 5 | 6 / 10 | **0** |
| product page | variations | 11 / 1 | 11 / 2 | 11 / 5 | 11 / 10 | **0** |
| product page | gallery images | 9 / 5 | 9 / 10 | 9 / 19 | 9 / 34 | **0** |
| product page | reviews | 9 / 2 | 9 / 4 | 9 / 10 | 9 / 20 | **0** |
| brand page | tiles | 6 / 1 | 6 / 2 | 6 / 5 | 6 / 10 | **0** |
| concern page¹ | tiles | 6 / 3 | 6 / 4 | 6 / 6 | 6 / 10 | **0** |
| Journal index | articles | 2 / 1 | 2 / 2 | 2 / 5 | 2 / 10 | **0** |
| article | `<img>` in body | 3 / 1 | 3 / 2 | 3 / 5 | 3 / 10 | **0** |
| drawer on `/shop` | basket lines | 9 / 1 | 9 / 2 | 9 / 5 | 9 / 10 | **0** |
| `/ar/shop`² | tiles | 7 / 1 | 7 / 2 | 7 / 5 | 7 / 10 | **0** |
| **`/cart` rail, before** | **rec cards** | **11 / 1** | **12 / 2** | **15 / 5** | **20 / 10** | **1.0** |
| `/cart` rail, after | rec cards | 11 / 1 | 11 / 2 | 11 / 5 | 11 / 10 | **0** |

¹ `ConcernCollections::MIN_PRODUCTS` is 3, so that row is measured at 3, 4, 6, 10.

² Added after the first draft of this document claimed the Arabic storefront was
unmeasured and probably dearer. **It is not, and the claim was withdrawn because
it was measured instead of argued.** With a published Arabic `name` (a short
field) and `description` (a LONG_FIELD) on every product, `/ar/shop` runs **7
statements and exactly 2 reads of `translations` whatever the tile count**, and
the Arabic names really render — 3, 6, 15 and 30 occurrences against 1, 2, 5 and
10 tiles. `HasTranslations::translationFor()` answers a short field out of the
cached map with no query at all, and batches the long ones through
`TranslationStore::longFor()`, one query per page.

**Every page but the rail is genuinely flat, and that is a real result rather
than a shrug.** Each was re-measured after its fixture was made to vary provably:

- **`/shop` and the category archive** already carry `CARD_COLUMNS` and
  `with('brand:id,name,slug')`, and `<x-product-card>` — unlike
  `<x-product-grid>` — never reads `categories`, so the pivot costs nothing here.
- **The product page** eager-loads `variants.attributeValues:id,attribute_id,name,slug`.
  ▲ The first fixture built bare variations with no attribute values, so
  `$v->label()` never touched the relation and the page measured flat **for the
  wrong reason** — the identical mistake that hid Q8's cart defect. With real
  option labels attached it is still flat, and now provably so.
- **Gallery images and reviews** are columns and one hasMany; neither is per-item.
- **The brand page** was fixed in an earlier round (`with(['brand:id,name,slug',
  'categories:id,name'])`) and stays flat — worth restating because
  `store/brands.blade.php` is the one view rendering `<x-product-grid>`, which
  *does* read `$p->categories->first()`.
- **The concern page** goes through `ConcernCollections::query()` with the same
  card column list and brand eager load.
- **The Journal** costs 2 statements for the index and 3 for an article,
  whatever they carry. Article bodies are stored HTML; images in them are not rows.
- **The drawer on a non-cart page is confirmed flat at 9**, measured rather than
  inherited from Q8's note.

## Task 1 — the recommended rail. Fixed.

`App\Services\CartPage::recommended()` ran
`Product::query()->whereIn('id', $ids)->…->get()` with no `with('brand')` and no
column list, while `store/cart-inner.blade.php` reads `$recProduct->brand?->name`
twice per card. One `select * from "brands" where "id" = ? limit 1` **per card**,
and `CartPage::MAX_REC` is 24.

| /cart, basket held at one line | 1 card | 2 | 5 | 10 |
|---|---|---|---|---|
| total statements, **before** | 11 | 12 | 15 | **20** |
| reads of `brands`, before | 3 | 4 | 7 | **12** |
| total statements, **after** | 11 | 11 | 11 | **11** |
| reads of `brands`, after | 3 | 3 | 3 | **3** |

The three remaining `brands` reads are the basket line's own chain, and they do
not move with the rail.

**`select *` was the other half, and a statement count cannot see it.**
`products.description` is a longText and `seo`, `meta_feed` and `custom_tabs` are
json blobs the rail never opens; all four came back for every card. The fix adds
`Store\CollectionController::CARD_COLUMNS` character for character, for the
reason Q8 gave for spelling the cart's eager load exactly as the checkout's.
(`Store\ShopController`'s list is the same plus `created_at`, which dates a
product for the New badge — a badge this hand-written rail card does not draw,
so the column would be fetched for every card and read by nothing.)

### Four columns on that list look optional and are not

A narrowed select is not free: **an accessor reading a column that was never
SELECTed gets null and cannot tell that from a NULL column.**

| column | what drops it silently breaks |
|---|---|
| `type`, `price` | `VariantPricing::entry()` reads both off the raw attributes. Without them a variable parent gets no range, `effectivePrice()` falls to 0, and the rail prints **AED 0** — the defect that class exists to remove, re-entering through the fix. |
| `sale_starts_at`, `sale_ends_at` | `Product::advertisedSalePrice()` warns about this in its own docblock: two absent dates read as "no start bound, no end bound", i.e. a sale that is always on. It **fails open** — an expired markdown is quoted forever. |
| `brand_id` | the `brand` eager load is a belongsTo and has nothing to match on. |

Each is pinned by a case in `tests/Feature/CartRecommendedRailSlopeTest.php`.

## Mutation notes — all run, including the ones that did not go red

Against `tests/Feature/CartRecommendedRailSlopeTest.php` (6 cases).

1. **Delete `->with('brand:id,name,slug')`** — the fix itself. RUN: **2 failed**,
   both cost cases.
2. **Delete `->select(self::CARD_COLUMNS)`.** RUN: **1 failed**, 'does not fetch
   the description or the json blobs'.
   ▲ **This came back GREEN the first time, and it should not have.** The case
   found the rail's statement by matching `from "products"` plus `"id" in (` and
   keeping the last hit — and /cart issues **three** `products` statements, two
   of them the basket line's and the drawer's, both already column-listed. So
   the assertion was being made against somebody else's query and would have
   passed with the rail on `select *` forever. The rail's statement is the only
   one of the three filtering on `status` and `is_visible`; that is what
   identifies it now. **The mutation is what found this, not review.**
3. **Drop `type` from `CARD_COLUMNS`.** RUN: **1 failed**, the variable-product
   price case (the card prints AED 0.00).
4. **Drop `price`.** RUN: **2 failed** — the variable-product case and the
   expired-sale case, since `ownPrice()` loses the column it compares against.
5. **Drop `sale_starts_at` and `sale_ends_at`.** RUN: **1 failed**, the
   expired-sale case — the card quotes AED 40.00 for a markdown that ended a
   week ago.
6. **Drop `brand_id`.** RUN: **1 failed**, the content case.
   ▲ **Also green at first, for a different and more embarrassing reason.** The
   content case asserted the page contained `Gradient::initials('BrandOf3x0')` —
   and `initials()` takes the first letter of each word, capped at two, so for a
   one-word brand that is the string **`"B"`**. Asserting that an HTML page
   contains the letter B is an assertion that cannot fail. The fixture's brands
   are two words now, and the case reads the initials out of the card's own
   placeholder span rather than looking for them anywhere on the page.
7. **Swap the eager load onto the wrong relation** (`->with('categories:id,name')`
   — Product has one, so this is a plausible typo). RUN: **2 failed**, the same
   two as mutation 1.

Against `tests/Feature/StorefrontSlopeGuardTest.php` (3 cases):

8. **Revert the rail eager load.** RUN: **1 failed**, the /cart case.
9. **Drop `variants.attributeValues` from `Store\ProductController`.** RUN:
   **1 failed**, the product-page case.
10. **Drop `with('brand:id,name,slug')` from `Store\ShopController`.** RUN:
    **1 failed**, the /shop case.

## Task 3 — the standing check. Built, because it is cheap.

`tests/Feature/StorefrontSlopeGuardTest.php`. Three cases, each rendering one
page at two sizes and asserting the statement count does not move.

**Measured cost: 2.39s for the file, of which 1.90s is the first case's
application boot — the fixed price every Pest file pays.** The marginal cost of
the guard itself is about **0.5 s**, against a suite that runs in minutes. It
earns that by failing on all three of mutations 8–10 above.

**Three pages, not all of them, and the reason is the table.** Every page
measured but the rail is already flat, and structurally so — they share two
card column lists and two eager loads between them. Pinning them all would roughly triple this
file's cost to re-prove things that are flat for the same reason twice over. The
three chosen are where an N+1 costs a *shopper* rather than a crawler and where
the per-item work is richest: `/shop` (the grid, once 390 queries for four
products), the product page (variations, reading an option label off a
many-to-many — the exact relation Q8's defect was in), and `/cart` (basket lines
**and** the rail, on the screen where somebody decides to pay).

**No absolute number appears in that file.** Ceilings live in
`StorefrontQueryBudgetTest`, where they are measured as ceilings; this file only
ever compares one size against another, because trap 3 above inflates any total
taken in a test process by about four.

## Where this sits in the admin

Nothing new to find. The rail is **Appearance → Cart page → Recommended rail**
(`rec_on`, `rec_heading`, `rec_ids`), and the layout switch that decides whether
it renders at all is **Appearance → Cart page → Cart page layout**, still
shipping as `classic`. **No setting changed value and none was added.**

## Found and not fixed

`<x-product-grid>` reads `$p->categories->first()` per tile, and nothing pins
that. It is flat everywhere today only because its single caller —
`store/brands.blade.php` — happens to eager-load `categories:id,name`; the other
grid component, `<x-product-card>`, never touches the pivot, so the requirement
is invisible from the component's own side. The next view that renders
`<x-product-grid>` from a query without that eager load gets one
`product_category` read per tile and no test will notice. Left alone because
`resources/views/components/product-grid.blade.php` needs either a documented
contract or a `loadMissing`, and that is a change to a shared component rather
than a measurement — but it is the likeliest place the next one of these
appears.
