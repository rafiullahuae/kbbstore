# The storefront reads a content translation, and the map stops carrying prose

Lane FP. Everything measured below was produced by `php artisan kbb:page-cost`
(`app/Console/Commands/PageCost.php`) on MySQL 8.0.46 at 696 products with the
whole catalogue translated into Arabic, by the method `docs/page-cost.md` sets
out: one operating-system process per measurement, the live host's
`SESSION_DRIVER=database` and `CACHE_STORE=file`. No number here was estimated.

This is the sequel to `docs/fn-translation-at-scale.md` and it acts on that
lane's two findings.

## 1. The finding

Lane FN proved, with a sentinel rather than a grep, that **nothing on the
storefront read a content translation**. With the whole catalogue translated and
Arabic switched on, `/ar/shop` rendered the English product name and **zero**
occurrences of that product's Arabic one.

It is now the other way round, asserted the same way — through a real HTTP
request, on the rendered bytes, in both directions.

```
/ar/shop            ZZSENTINELNAME × 1,  "Hydrating Serum No. 360" × 0
/shop               "Hydrating Serum No. 360" × 1,  ZZSENTINEL* × 0
```

### Where the read now happens

FN named five call sites. They were not the whole list.

| Surface | File |
| --- | --- |
| product card (the shop grid) | `resources/views/components/product-card.blade.php` |
| skinned grid, home rails | `components/product-grid.blade.php`, `partials/home/grid.blade.php` |
| product page: `<title>`, `<h1>`, breadcrumb, blurb, sticky bar | `store/product.blade.php` |
| product gallery caption and alt text | `partials/product-gallery.blade.php`, `Product::altFor()` |
| the three detail tabs, bodies **and** headings | `Store\ProductController::tabs()` |
| quick view, and the modal's heading | `partials/quick-view.blade.php`, `Store\QuickViewController` |
| basket drawer, cart page, checkout summary, thumbs, browsed strip | five partials |
| frequently-bought-together | `partials/fbt.blade.php` |
| shop facets (category and brand names) | `store/shop.blade.php` |
| category archive `<h1>` and standfirst | `Store\ShopController::heading()` |
| brand page: title, hero, standfirst, directory tiles, banner fallback | `store/brands.blade.php`, `Store\BrandController` |
| content pages, journal index, article | `store/page.blade.php`, `blog.blade.php`, `post.blade.php` |
| routine swaps, review wall, home review cards | three views |
| search suggest and search results JSON | `Store\SearchController` |
| **SEO**: `<title>`, `<meta description>`, JSON-LD Product, BreadcrumbList, Article | `App\Support\ProductSeo`, `ProductController`, `BrandController`, `PageController` |
| **the header menu** | `App\Services\NavigationService::filterVisible()` |

Three rules held throughout:

- **Blank means untranslated and untranslated falls back to English**, per
  field. `t()` already does this; every call site was written so that nothing
  can render an empty string where a name goes.
- **A `@if` that asks whether a field exists keeps asking the English column.**
  Whether a product has a blurb is a fact about the product, not about the
  language it is read in.
- **`Gradient::for()` seeds stay English.** The tile colour is a hash of the
  string it is given, so translating the seed would repaint the whole shop
  between languages for a value nobody reads. The initials and words printed
  *inside* the tile are translated.

### The menu label, and the trap it sits in

`NavigationService::menu()` caches one tree for five minutes and shares it with
every visitor. Translating inside that closure would bake whichever visitor's
**language** triggered the cache miss into everybody else's header — the same
trap `tree()`'s own comment records about `visibility`. The cached tree stays
language-neutral and the swap happens per request, in `filterVisible()`, in the
pass that is already walking every item. Pinned in both directions.

### The demo fixtures

`App\Services\DemoContent::fill()` concatenates **anonymous fixture objects**
onto a collection of real models, and the home rails render both from one
expression. The moment those templates read catalogue text through `t()`, a
stand-in that answers only `->name` became a fatal *Call to undefined method* on
the home page with demo content on — the exact failure the fixture's own
`cover`/`body` comment already records, one method along. All four stand-ins and
the demo product's brand now answer `t()` with their own English.

## 2. The map split

`TranslationStore::LONG_FIELDS` — `description`, `ingredients`, `how_to_use`,
`content`, `body` — are filtered out **in SQL**, so the bytes never cross the
wire, are never hydrated and are never serialised into the cache entry. They are
read instead by `TranslationStore::longFor()`, one query for a whole page,
memoised per row, and `primeTranslations()` is now the real batch call.

Not `short_description` and not `excerpt`, though both are prose: both are
printed on **grids**, so taking them out of the map would trade 0.02 MB for a
query per card.

### Measured directly, one process, warm file cache

|  | entries | load | resident | strings | serialized |
| --- | ---: | ---: | ---: | ---: | ---: |
| `map('ar')` **before** | 4,512 | 7.1–7.8 ms | **+4.72 MB** | 3.20 MB | 3.27 MB |
| `map('ar')` **after** | 2,491 | 0.9–1.0 ms | **+0.48 MB** | 0.18 MB | 0.22 MB |
| `longFor()`, 25 products | 73 rows | 3.0–3.4 ms | +0.29 MB | 0.11 MB | — |

Ten times less resident memory, eighteen times fewer bytes of string and seven
times less time, on **every** Arabic request —
including `/ar/cart`, which prints no prose at all and was paying the whole
4.72 MB because `__()` loads the map to answer for an interface string.

`uiMap()` is also memoised now. It walks the map to answer and the loader asks
it once per translation group, five or so times a request; that was five walks
of 4,512 entries and is now one walk of 2,491.

`HasTranslations` asks `TranslationStore::get()` rather than `forGroup()`.
`forGroup()` is the batch form and scans the whole map, so called per field per
row it was performing 48 full scans of a 2,491-entry array on a 24-card grid to
do 48 lookups it already had the exact key for.

### At the page

Three interleaved passes, before/after/before/after/before/after, with the split
line removed and restored between each; each pass is the median of five
requests, one process per request.

| Page | Queries | Peak memory before → after |
| --- | ---: | --- |
| `/ar/` home | 4 → 4 | 42.5 → **34.0 MB** |
| `/ar/shop` page 1 | 7 → 7 | 40.5 → **34.0 MB** |
| `/ar/product` | 14 → 14 | 42.5 → **38.5 MB** |
| `/ar/cart` | 8 → 8 | 42.5 → **34.0 MB** |
| `/shop` (control) | 7 → 7 | 34.0 → 34.0 MB |
| `/product` (control) | 13 → 13 | 38.5 → 38.5 MB |

Identical in all three passes. SQL time moved by less than the box's noise floor
on every row.

**The Arabic surcharge is gone.** `/ar/` and `/ar/shop` and `/ar/cart` now cost
the same 34.0 MB as their English counterparts, and `/ar/product` the same
38.5 MB. FN's `+6 MB on every Arabic page` is 0.

**No query was added to any English page**, and none of the budgets in
`StorefrontQueryBudgetTest` or `PageCostBudgetTest` was raised. `t()` returns
before it touches anything for the default locale, and that is asserted directly
rather than inferred from nothing breaking.

**The Arabic product page costs one query more than the English one** (14
against 13), and that query is the page's own long prose. It is the trade the
19× is made of: one statement, 0.9–3 ms, on the one page that prints a
description, against 4.7 MB on every page that does not.

## 3. The comment that was wrong

`HasTranslations`' header named "a third and fourth language" as the trigger for
splitting the map. That is false and it was hiding the real problem: each locale
has its own cache entry and a request loads only the locale it is served in, so
a fourth language costs a fourth cache key and **nothing per request**. The
trigger was always field **length**, and it had been crossed long before anyone
looked. Corrected in place, with the measurement.

## 3b. Re-verified this round, on a rebuilt fixture (Lane F)

425 products, 2,053 published `ar` translations, Arabic and RTL on, served by a
real HTTP server and read by headless Chromium.

**The sentinel, both ways, on the rendered bytes:**

| page | `ZZSENTINELNAME` | `Hydrating Serum No. 360` |
| --- | ---: | ---: |
| `/shop?s=Hydrating` | 0 | 3 |
| `/ar/shop?s=Hydrating` | **3** | **0** |
| `/product/fp-evidence-hero/` | 0 | 7 |
| `/ar/product/fp-evidence-hero/` | **7** | **0** |

**The map split, measured directly, three interleaved passes, warm file cache,
one process per measurement** — identical on all three:

| | entries | strings | serialized | resident | load |
| --- | ---: | ---: | ---: | ---: | ---: |
| map `ar` **with** long prose | 2,053 | 3.07 MB | 3.13 MB | +2.00 MB | 52–62 ms |
| map `ar` **as it ships** | 850 | **0.03 MB** | **0.06 MB** | **+0.00 MB** | **0.4–0.7 ms** |

`+0.00 MB` is below `memory_get_usage(true)`'s 2 MB allocator step, not zero.
The page-level peak in this rig is dominated by the console bootstrap and did
not resolve a 3 MB difference either way, so section 2's MySQL page-cost table
stands as the page-level record and nothing here amends it.

**Queries, English against Arabic, at 425 products:**

| page | English | Arabic |
| --- | ---: | ---: |
| `/shop/` | 4 | **4** |
| `/cart` | 2 | **2** |
| `/` | 1 | **1** |
| `/product/…` | 8 | **9** |

Flat everywhere but the product page, whose one extra statement is that page's
own long prose — the trade the split is made of. (The absolute numbers are lower
than section 2's because this rig is SQLite with array session rather than the
live host's `SESSION_DRIVER=database`; the **equality** is the claim.)

**Pictures** — `docs/fp-shots/`, Chromium, full page:

| file | width | `scrollWidth` / `clientWidth` | `<html>` | `<h1>` | h1 size |
| --- | ---: | --- | --- | --- | ---: |
| `ar-shop-390.png` | 390 | 390 / 390 | `lang="ar" dir="rtl"` | `Search: Hydrating` | 32px |
| `ar-shop-1280.png` | 1280 | 1280 / 1280 | `lang="ar" dir="rtl"` | `Search: Hydrating` | 32px |
| `ar-product-390.png` | 390 | 390 / 390 | `lang="ar" dir="rtl"` | **`ZZSENTINELNAME`** | 25px |
| `ar-product-1280.png` | 1280 | 1280 / 1280 | `lang="ar" dir="rtl"` | **`ZZSENTINELNAME`** | 25px |

No horizontal page scroll at either width. The product page's `<h1>`, its
breadcrumb, its Description tab body and its related-product cards are all the
Arabic row; the English name appears zero times.

## 4. The hole the render inherited, closed in the same cycle (Lane F)

`App\Support\RichText`'s own header states the rule:
`partials/product-tabs.blade.php` prints a product description with `{!! !!}` —
twice, desktop panel and mobile accordion — so *"the allowlist runs on the way
IN to the database, on the server, every time. Nothing is trusted for having
come from the editor's own toolbar."*

Three writers reach `translations.value` for those fields. Two obeyed it:

| writer | sanitised? |
| --- | --- |
| `ProductEditorApiController` (the Arabic box beside the English one) | yes — `TranslationInput::clean(RICH_FIELDS)`, the T4b fix |
| `MachineTranslationRunner` (the accelerator) | n/a — it never sends markup out |
| **`TranslationsApiController::store()`** — Content → Translations, the standalone screen, **published immediately with no draft step** | **no** |

The third passed the owner's typing to `TranslationStore::put()` verbatim.

**That bypass was inert until this cycle, and that is the whole point.** With
nothing on the storefront reading a content translation it was data in a table
no page printed. Section 1 is what connected the two ends. So the sanitiser had
to arrive in the same change as the render, or the render would have opened the
hole it inherited.

### Driven, not argued

Through the real endpoint for the storage half, and in a real browser for the
render half — `<p>ZZSENTINELDESC</p><img src=x onerror=alert(1)><script>alert(2)</script>`
as the Arabic `description` of one product, `/ar/product/…` loaded headless:

```
written straight into the row (what store() did)
    stored:   <p>ZZSENTINELDESC</p><img src=x onerror=alert(1)><script>alert(2)</script>
    on load:  4 dialogs fired — "1", "2", "2", "1"
              (each payload twice: the description is printed twice)
    DOM:      onerror present, <script>alert(2) present

through TranslationStore::put() (what it does now)
    stored:   <p>ZZSENTINELDESC</p><img src="x">
    on load:  0 dialogs
    DOM:      no onerror, no script tag
    and       ZZSENTINELDESC still visible — the operator's words survive
```

### Where it is enforced, and why there

In `TranslationStore::put()`, which is the one function all three writers call,
rather than in the one caller that was missing it. A sanitiser each writer has
to remember is a sanitiser one writer will not have; a fourth writer added later
now inherits the rule instead of re-opening the hole.

Scoped to `TranslationStore::RICH_FIELDS` — `short_description`, `description`,
`ingredients`, `how_to_use` — on group `products`, which is exactly the set
whose **English** the product editor cleans. `clean()` parses its input as HTML,
so run over a name it would re-encode a product genuinely called `Serum <3` and
change the shop under the owner for a value he typed correctly. Group and field
are normalised before the check, so `Products`/`Description` cannot walk past a
rule `products`/`description` is subject to.

The list is held identical to `ProductEditorApiController::RICH_FIELDS` by an
assertion rather than an import, because that file belongs to another lane — add
a fifth rich column there and the test goes red until the store is told.

## 5. THE HARNESS DEFECT THIS LANE FELL INTO, WHICH IS EVERY LANE'S

**`vendor/bin/pest` inside a worktree whose `vendor/` is a symlink runs that
lane's TEST FILES against the MAIN CHECKOUT's APPLICATION CODE.** Five of the
seven worktrees on this box are in that shape (`lane-c`, `lane-d`, `lane-e`,
`lane-f`, `lane-g`); `lane-a` and `lane-cart-desktop` have a real `vendor/` and
are unaffected.

Two independent causes, both from the symlink:

1. **Pest's root.** `vendor/pestphp/pest/bin/pest` computes
   `$rootPath = dirname($autoloadPath, 2)` from the autoloader it included, and
   PHP resolves the symlink, so the root is `/home/user/kbbstore`. `tests/Pest.php`
   ends `->in('Feature')`, which then names the MAIN checkout's `tests/Feature`.
   The worktree's own test files therefore get **no** `TestCase`, no
   `RefreshDatabase` and no Laravel application at all. Observed: the whole
   suite "fails" in 0.15 s with `Call to a member function connection() on null`
   and `Call to undefined method Illuminate\Container\Container::path()` —
   130 reds that say nothing about the code.
2. **Composer's maps, and Laravel's base path.** `vendor/composer/autoload_static.php`
   resolves `App\` through `__DIR__ . '/../..'`, which is the real directory, so
   `App\Foo` loads from `/home/user/kbbstore/app/`. `Application::inferBasePath()`
   then derives its base path from that same loader, so `config/`, `routes/` and
   `resources/` come from the main checkout too.

Cause 1 is loud. **Cause 2 is silent and is the dangerous one**: work around the
first with `--test-directory` and the suite goes green — against somebody else's
application code. This lane hit exactly that: three new tests passed, and the
sanitiser they were written for was not in the file being loaded.

Two lines fix it for one process, and they are what every measurement in this
document was taken with:

```php
$_ENV['APP_BASE_PATH'] = $root;                 // inferBasePath() consults this first
spl_autoload_register($psr4ForTheWorktree, true, /* prepend */ true);
```

```bash
vendor/bin/pest --test-directory=.claude/worktrees/<lane>/tests \
                --bootstrap=<wrapper that sets those two and then requires tests/bootstrap.php>
```

Under that harness the full suite is **5,125 passed, 41 skipped, 0 failed** on
`lane/round-f`. This wants a permanent answer in `tests/bootstrap.php` or in the
worktree setup — it is not Lane F's file, and it is worth more than any one
lane's round.

## Found and deliberately not fixed

**The Journal is not a bilingual document.** `store/post.blade.php` and
`store/blog.blade.php` open with `@verbatim<!DOCTYPE html><html lang="en">` and
carry their own hard-coded navigation. They do not extend `layouts.store`, so an
Arabic article's **words** are Arabic (asserted) while its document declares
`lang="en"`, has no `dir="rtl"`, no hreflang and an English header. Rebuilding
two standalone documents onto the shared layout is the RTL and SEO lanes'
surface.

**Order line names are still snapshots of the English.**
`Store\CheckoutController` writes `'name' => $p?->name` onto each `order_items`
row, under a comment saying that is deliberate — so the order still reads
correctly if the product is renamed. Translating it would freeze one language
into a historical record that the owner reads in the other one, on the admin
order screen and on the printed invoice. The column can hold one language and
the order is read by two people who do not share one. That is a decision, not a
line in a diff.

**`ShopController::seoDescription()` is an English sentence template.**
`"Shop {$category->name} at K-Beauty Bliss — {$count}."` is built from literals
in the controller, so an Arabic category page publishes an English meta
description. Translating only the name inside it would produce a mixed sentence,
which is worse. The three sentences want keys, which is the interface lane's
work; the same is true of the group labels in `SearchController`
(`'Products'`, `'Categories'`, `'Brands'`, `' products'`).

**`pages.content` and `posts.body` are stored as trusted operator HTML, in both
languages.** `store/page.blade.php` and `store/post.blade.php` print them with
`{!! !!}`, and unlike the product columns their **English** goes to the database
with no `RichText::clean()` either — `PagesApiController` and
`PostsApiController` sanitise nothing. So the Arabic half is no worse than the
English half, which is why the sanitiser in section 4 deliberately stops short
of them: cleaning only one language would render one document differently in its
two. Making page and post bodies allowlisted on both sides is a real decision
about what an admin may author, and it is the owner's, not this lane's.

**`seo.title` / `seo.desc` and `banner.heading`** remain as Lane FN left them —
argued, not done, because they are JSON sub-keys rather than columns and
`TranslationEstimate::fieldsWithText()` would count them silently as zero work.
The banner's *fallback* heading, which is the brand's own name, is translated.

## For the integrator: the `KBB-Master-Plan.md` edit this lane may not make

**Anchor** — Lane FN's replacement text for T4 contains this paragraph:

```
      **The reason it is not urgent, and the reason T4 is not close to done:
      nothing on the storefront reads a content translation.** `t()` is called
      by no Blade file, no view composer and no storefront controller —
      measured with a sentinel, not grepped: with the shop fully translated and
      Arabic on, /ar/shop renders "Hydrating Serum No. 360" and zero
      occurrences of that product's Arabic name. Every Arabic word on an Arabic
      page today is an interface string. So the owner's ~55 hours of catalogue
      typing would currently land in a table no page reads, and the map split
      should ship in the same cycle as the render rather than before it.
      product-card.blade.php, store/product.blade.php, quick-view,
      product-tabs and Store\ProductController::tabs() are the call sites, and
      they belong to the storefront and RTL lanes.
```

**Replace with:**

```
      **The storefront reads it now, and the map no longer carries the owner's
      prose to do it.** Lane FN's sentinel is asserted the other way round
      through a real request: /ar/shop renders the Arabic name and zero
      occurrences of the English one, /shop the reverse, and the English output
      of every storefront page is byte-identical
      (StorefrontEnglishUnchangedTest). The read reaches further than the five
      call sites FN named — the cards and grids, the product page and its three
      tabs, the gallery alt text, quick view, the basket and checkout, the shop
      facets, the category and brand headings, content pages, the journal, the
      routine swaps, search suggest, the header MENU, and the SEO surface:
      <title>, <meta description>, JSON-LD Product, BreadcrumbList and Article.
      Blank still means untranslated and still falls back to English, per field.

      **And the map is a tenth of the size.** description, ingredients,
      how_to_use, pages.content and posts.body are filtered out in SQL and read
      by the page that prints them. Measured, warm cache, one process:
      map('ar') 4,512 entries / +4.72 MB / 7.1-7.8 ms becomes 2,491 / +0.48 MB
      / 0.9-1.0 ms — ten times the resident memory gone. At the page, three
      interleaved passes: /ar/ 42.5 -> 34.0 MB,
      /ar/shop 40.5 -> 34.0, /ar/cart 42.5 -> 34.0, /ar/product 42.5 -> 38.5 —
      an Arabic page now costs what the English one costs. No query was added to
      any English page and no budget was raised; the Arabic product page costs
      one query more than the English one, which is its own long prose. See
      docs/fp-storefront-reads-translations.md.

      **Still open:** the Journal is not a bilingual document — post.blade.php
      and blog.blade.php are standalone `<html lang="en">` files with their own
      nav, so an article's words reach Arabic and its document does not. Order
      line names stay English snapshots, deliberately. And
      ShopController::seoDescription() and SearchController's group labels are
      English sentence templates built in PHP, which the interface lane missed
      because they are not in a Blade.
```

**Anchor** — and in the same block:

```
      of a thing that was already 19× bigger than it needs to be. They do not
      change the query counts.
```

leave unchanged; it is still true.
