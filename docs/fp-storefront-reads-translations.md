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
