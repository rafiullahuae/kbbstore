# T4 and T5 at the owner's real volume

Lane FN. Everything below was produced by `php artisan kbb:page-cost`
(`app/Console/Commands/PageCost.php`), on MySQL 8.0.46, at 696 products with
the whole catalogue translated into Arabic. No number here was estimated.

## How to reproduce it

The method is `docs/page-cost.md`'s, unchanged: one operating-system process per
measurement, the live host's `SESSION_DRIVER=database` and `CACHE_STORE=file`,
MySQL rather than the suite's SQLite. Two flags are new.

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS kbb_fn_perf \
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# .env.perf as docs/page-cost.md describes, pointed at kbb_fn_perf.
export APP_ENV=perf KBB_PUBLIC_PATH=$PWD/public

# 1. the English shop, which is the baseline.
php artisan kbb:page-cost --fresh --seed --products=671 --orders=2000 --reviews=1500 \
    --runs=5 --deep-page=24 --json=storage/app/fn-en-baseline.json

# 2. translate ALL of it, switch Arabic on, and measure both shops.
php artisan kbb:page-cost --translate=ar
php artisan kbb:page-cost --runs=5 --deep-page=24 --only=home,shop-p1,shop-deep,category-filtered,product,cart,ar- \
    --json=storage/app/fn-ar-full.json
```

`--translate=ar` writes a **published** translation for every translatable field
of every content row plus every interface string, and turns the two language
switches on. `--drafts=N` makes every Nth row a machine draft instead, which is
how the draft figures below were taken.

The Arabic is not a translation and does not pretend to be one — it is a memory
fixture. What has to be right is that it is the same **character** length as the
English and that every letter is outside ASCII, because Arabic costs two bytes a
character in utf8mb4 where English costs one and PHP measures a string in bytes.
Markup is left as markup for the same reason: a real Arabic description is still
HTML, and turning `<p>` into Arabic letters would over-state the bytes.

Two smaller changes to the harness, both needed before it could answer this:

- `--deep-page=N`. The deep-OFFSET scenarios asked for `?paged=100`, which is a
  real page in a 3,025-product seed and a **404** in a 671-product one. A 404
  measured as a page is a very fast page.
- Options that decide *which page* a scenario is now reach the child process.
  They did not, so the parent measured one URL and the child measured another —
  and the first table this lane produced was a column of 404s reported as
  30 MB pages.

## The volume

| | rows |
| --- | ---: |
| products | 696 (671 seeded + the 24 demo rows + the hero) |
| categories / brands | 20 / 93 |
| orders / order lines | 2,000 / 9,000 |
| **published `ar` translations** | **4,467** |
| value bytes in the table | 3.09 MB |

That is the shop **fully translated**: the state the owner is working towards
over his ~55 hours, and therefore the state the design has to be affordable in.
Measuring twenty-four translations measures nothing, because the open question
is the **size of the map** and twenty-four rows do not have a size.

## The table

Median of five, each request its own process. "BASE" is the English shop with no
`translations` table content at all; "AR" is the same page with the whole
catalogue translated and Arabic switched on.

Two full passes were taken, a rebase apart. Queries and peak memory are
identical between them; wall clock is not, for the reason
`docs/page-cost.md` gives — this box is shared with other lanes' suites, and its
noise floor on wall clock is several milliseconds. Both passes are shown where
they differ.

| Page | Queries BASE → AR | SQL ms | Peak memory BASE → AR |
| --- | ---: | ---: | ---: |
| `/` home | 3 → 4 | 1.6 → 1.6 | 34.0 → **42.5 MB** |
| `/shop` page 1 | 6 → 7 | 3.5 → 4.2 | 34.0 → **40.5 MB** |
| `/shop?paged=24` | 6 → 7 | 5.5 → 5.3 | 34.0 → **40.5 MB** |
| category + filters | 8 → 8 | 5.0 → 4.3 | 34.0 → **40.5 MB** |
| product page | 12 → 13 | 10.6 → 9.3 | 36.5 → **44.5 MB** |
| `/cart` | 7 → 8 | 3.5 → 3.2 | 32.0 → **42.5 MB** |

The `+1` query appears on the **English** pages too the moment Arabic is
switched on — it is `SetLocaleFromPath` reading `Setting::map()`, at 0.2 ms, and
`docs/page-cost.md` already predicted it. Arabic itself costs **no extra
queries**: `/ar/shop` runs the same seven statements as `/shop`, and `/ar/cart`
the same eight as `/cart`.

**So the N+1 the master plan asked about does not happen, at 696 products or at
24.** That claim is now pinned at 400 rows in
`tests/Feature/ContentTranslationAtScaleTest.php` as well as at 24 in
`BilingualFoundationTest`.

**And SQL time does not move either.** Whatever the second language costs, it is
not being spent in the database.

**What does move is memory, by +6.0 MB on every Arabic page**, to a tenth of a
megabyte, in both passes, on every page — including `/ar/cart`, which renders no
product prose at all. `/ar/home` and `/ar/cart` are 42.5 MB against an English
34.0 and 32.0; `/ar/product` is 44.5 against 36.5.

**Wall clock moves too, by something between 11 and 43 ms**, and that range is
the honest one rather than a figure: pass one gave +25, +21, +39 and +43 ms on
home, shop, deep shop and cart; pass two gave +11, +27, +25 and +24. One
English product page in pass two came back at 144 ms against an Arabic 120,
which is the shared box and not a discovery. What the page-level numbers cannot
be more precise than, the next section measures directly instead.

## Where the six megabytes are

Taken directly, in a process of its own, rather than inferred from a page:

```
map('ar'): 4,467 entries, 8.0 – 8.6 ms to load, +4.74 MB resident
raw string bytes: 3.20 MB
  products      3.10 MB     ← 97%
  ui            0.07 MB
  pages         0.02 MB
  brands, categories, menu_items   under 0.01 MB each
serialized cache payload: 3.27 MB, 1.9 ms to unserialize
```

That is the +6 MB the page table shows, and about ten of the milliseconds. The
rest of the wall-clock difference is `uiMap()`, which builds a fresh 895-key
array by walking all 4,467 entries — once per translation group, five or so
times a request.

`TranslationStore::map()` holds **one locale's whole published set**, and 97% of
it is `products` — almost all of that the three long prose columns,
`description`, `ingredients` and `how_to_use`. The same map, with those and the
other long-prose fields (`content`, `body`, `excerpt`) left out:

```
map minus long prose: 2,446 entries, 0.17 MB of strings, 0.21 MB serialized
```

**Nineteen times smaller.** And the thing that makes those 3.1 MB unnecessary is
not that they are large — it is *when they are read*. A long description is read
on **one product page at a time**. Read that way instead:

```
eager load, one page of 25 products: 53 rows, 0.9 – 1.1 ms, 0.01 MB
```

## The verdict: keep the map, take the long prose out of it

The question the foundation left open was "map, or switch
`scopeWithTranslations()` on". Measured, the answer is neither of those two.

**Switching the eager load on wholesale would not help.** The map is not loaded
because a product card asks for a name — it is loaded because `__()` asks for an
interface string, through `DatabaseTranslationLoader`, on every page including
ones with no products on them. An eager load added on top of that would be one
**extra** query per page and the same 4.74 MB.

**The map is the right design for what it was argued for.** Short, finite,
read-on-every-page text: the 895 interface strings, names, menu labels, brand
and category names. Those are 0.17 MB and 2,446 entries — configuration-sized,
exactly as `HasTranslations`' header claims, and an array lookup beats a query
every time.

**The map is the wrong container for long prose.** `description`, `ingredients`,
`how_to_use`, `pages.content`, `posts.body` are 95% of its bytes, are paid on
every Arabic request, and are read on at most one page at a time. The recommended
change, in the order it should be made:

1. **Split the cache entry.** `TranslationStore` keeps its per-locale map, but a
   named list of long-prose fields is stored under a second key and is not
   loaded by `map()`. `uiMap()` — which today walks all 4,467 entries once per
   translation group, five or so times a request — reads the small entry.
   Measured payoff: every Arabic page that renders no long prose drops from
   +4.74 MB to about +0.2 MB, and `flush()` already has exactly one place to clear.
2. **Read the long fields per page**, through `scopeWithTranslations()`, which
   is written, tested, and was put there for this. One extra query, 0.94 ms, on
   the pages that actually print a description.

That is a one-file change with a measured 19× and it is **not urgent**, for the
reason in the next section.

The header's stated trigger for switching — "a third and fourth language" — is
wrong, and worth correcting while somebody is in there. Each locale has its own
cache entry and its own map, and a request loads only the one it is being served
in, so a fourth language costs nothing per request. The trigger is **field
length**, and it was already crossed.

## The finding that outranks all of the above

**Nothing on the storefront reads a content translation.** `t()` is called by no
Blade file, no view composer and no storefront controller in this tree — only by
tests.

Measured rather than grepped. With the whole catalogue translated and Arabic on,
one product's Arabic name was replaced with the sentinel `ZZSENTINELNAME` and
`/ar/shop` re-rendered:

```
sentinel occurrences in /ar/shop: 0
"Hydrating Serum No. 360" occurrences: 1
```

The Arabic that *is* on the page — 1,717 Arabic codepoints, `<html lang="ar"
dir="rtl">` — is entirely interface strings coming through `__()`. Every product
name, short description and description on an Arabic page is the English one.

So the whole +6 MB and +20–43 ms measured above is being paid **for interface
strings alone**, and the owner's ~55 hours of catalogue typing would currently
land in a table that no page reads. `resources/views/components/product-card.blade.php`,
`store/product.blade.php`, `partials/quick-view.blade.php`,
`partials/product-tabs.blade.php` and `Store\ProductController::tabs()` all read
`$product->name` / `->description` directly.

Those files belong to the storefront and RTL lanes, so this lane has not touched
them. It is named here because it changes what "T4 is nearly done" means, and
because it also changes the urgency of the map split: **there is no page to slow
down yet, and the split should land in the same cycle as the render, not after
it.**

## T5 — the accelerator, at volume

Driven at 240 outstanding fields against a recording fake, and — for the
provider's own contract — against the real `GoogleProvider` with `Http::fake()`.
No test in this lane touches the network.

**Batching.** 120 products × 2 machine-safe fields = 240 fields go out as
`[100, 100, 40]`, Google's own per-request limit. Every row written is
`status=draft`, `source=machine`. Nothing is published by a run of any size.

**Drafts stay invisible at volume.** 240 machine drafts, and
`TranslationStore::map('ar')` is `[]`. Every product's `t('name')` is still its
English name and `hasTranslation('name')` is still false. The progress screen
counts them as `drafts: 240, translated: 0, percent: 0` — a run that has been
paid for but not read is not progress.

**Resume.** The provider fails on batch 2 of 3: 140 rows written, one error, the
other two batches unaffected. A second run finds exactly 100 outstanding, sends
exactly those 100, and **re-sends nothing already bought** — asserted field by
field, not by count.

**The short batch — fixed, not just measured.** `run()` paired answers to
requests by index and trusted the count. `GoogleProvider` upholds that contract
by answering an index it could not translate with `null` **in place**, and its
header says so in capitals; nothing on the runner's side enforced it. A provider
returning a **compacted** array — what a stray `array_filter`, or a second
implementation of `TranslationProvider`, produces — slid every answer one place
up the batch and wrote each product's Arabic copy onto the next product's row.
Ninety-nine drafts, no errors, the money spent, and ninety-nine products
carrying somebody else's name in Arabic, one Approve from being published on a
skincare catalogue.

The runner now drops a batch whose answer count does not match, whole, and
reports it. A broken contract means no row in that batch can be trusted, not
merely the missing one. Both halves are pinned: a compacted answer writes
nothing, and a `null`-in-place answer writes every other row against the right
product.

**The estimate against the spend — one real disagreement, fixed.** The quote and
the request agree exactly: the characters `TranslationEstimate`/`estimate`'s
`run.confirm_characters` counts over `pending()` are, to the character, the
characters the provider is handed. What did *not* agree was the **receipt**.
`characters` was accumulated inside the write loop, so a batch the provider
refused after reading the request, and every row answered with nothing, left its
characters out of the figure. Google bills for text it was **sent**. A run that
lost its second batch reported 4,000 characters used where the account had been
charged for 12,000, and the next estimate the owner read was wrong by the
difference. `run()` now also returns `characters_sent`, counted before the call
and whatever the call answers. `characters` still reports what was stored,
because that is what the progress screen grew by; the two differ by exactly what
was paid for and not kept.

### One thing found and deliberately NOT fixed here

**A machine draft can be longer than the editor will let the owner save.**
Reproduced:

```
english chars: 192    arabic chars: 264
stored draft length: 264          ← the runner writes it without complaint
editor box status: draft, length 264
save status: 422
  translations.ar.name: "The translations.ar.name field must not be greater than 200 characters."
```

`products.name` is `varchar(200)`, so `TranslationInput::rules()` mirrors
`max:200` onto the Arabic box — deliberately, and pinned by
`ArabicEditorBoxesTest`. But the Arabic does not live in that column; it lives in
`translations.value`, a `mediumtext`. Arabic is routinely longer than English for
the same meaning, so a machine draft of a long product name can land in a box
that the ordinary Save then refuses — on a field the owner never typed, with an
error naming an internal path he cannot map to a box, and with the product
unsavable until he shortens it.

`app/Support/TranslationInput.php` is Lane EX's, and the mirroring is a decision
that file argues for at length, so this lane has not changed it. The decision
that needs making is whether a `max` taken from an English **column** belongs on
a value stored in a different and much larger one.

## The two allowlist gaps

### `Product::ingredients` and `Product::how_to_use` — done

Both are prose, both are their own tab —
`Store\ProductController::tabs()` builds Description, Ingredients and How to use
from these three columns — and with them off `Product::$translatable` an Arabic
shopper read two of the three tabs in English **permanently, with nothing saying
so**: a field that is not translatable is not counted as outstanding, so the
progress bar would have read 100% with two tabs in English.

They are now on `Product::$translatable` and on `TranslationEstimate::CONTENT`,
which `BilingualFoundationTest` pins against each other. Both carry HTML, so
`isMachineSafe()` declines them and they are typed rather than machine-translated
— the honest state, not a gap: an INCI list through a translation engine is a
safety claim in a language nobody at the shop reads.

**It was not a two-line fix. It was a three-line fix, and the third line was a
stored-XSS hole.** `ProductEditorApiController` sanitises the English of all four
rich columns through `RichText::clean()`, and handed the Arabic side a literal
pair, `['short_description', 'description']`, under a comment reading "the four
rich fields are named". While `ingredients` and `how_to_use` were off the
allowlist that pair was complete and the comment was merely wrong. The moment
they went on, the Arabic halves of two `{!! !!}` tabs would have gone to the
database unsanitised — a hole opened not by writing any new code but by adding
two strings to a list in a different file. Both loops now read one
`RICH_FIELDS` constant, and the case is pinned.

The re-measurement with the larger surface is the table above: the two columns
add 1,342 rows and 0.42 MB to the map, which is 14% more of a thing that was
already 19× bigger than it needs to be. They do not change the query counts.

### `seo.title` / `seo.desc` and `banner.heading` / `subheading` / `image_alt` — argued, not done

**They belong translated.** They are customer-facing prose, they land in
`<title>` and `<meta name="description">`, and `hreflang` tells Google that the
Arabic URL is a page in its own right. An Arabic search result whose title and
description are in English is worse than no Arabic page: it is a page that
advertises itself as untranslated in exactly the place a shopper decides whether
to click. `banner.heading` is an `<h1>`.

**But they do not fit the allowlist mechanism as it stands, and must not be
forced into it.** `seo` and `banner` are `json` columns —
`2026_10_13_000000_add_banner_to_categories_and_brands` explains why `banner` is
its own column and not part of `seo`, and both are cast to arrays. Adding
`'seo'` to `$translatable` gives `t('seo')` an array to compare and return,
`saveTranslations()` refuses a non-string value outright, and the editor grows a
box containing raw JSON.

The shape that would work is a **dotted sub-key** — `'seo.title'`,
`'banner.heading'` — and the store already tolerates it: `field` is
`varchar(64)`, `normaliseKey()` lowercases a dotted string happily, interface
keys are already dotted, and `TranslationStore::forGroup()` splits with
`explode('.', $slot, 3)`, whose limit of 3 keeps `products.12.seo.title` intact.
`ProductEditorApiController` even validates `'seo.title' => [...]` under that
exact spelling already, so `TranslationInput::rules()` would derive the Arabic
bound with no change.

Four places would have to learn `data_get()` instead of `getAttribute()`:
`HasTranslations::t()`, `hasTranslation()`, `writeTranslation()`'s
`englishSource`, and `translationsForEditor()`.

**The part that is genuinely hard, and the reason this is a named step rather
than a line in this lane's diff, is the progress denominator.**
`TranslationEstimate::fieldsWithText()` counts with one SQL aggregate per table,
`SUM(CASE WHEN col IS NOT NULL AND col <> '' THEN 1 ELSE 0 END)`, deliberately
dialect-neutral because `SqlDialectGuardTest` exists and because the progress
screen is polled. It also drops any column name that is not a bare identifier,
so a dotted field would silently count as **zero work** — the exact failure mode
the `posts.body` comment in `CONTENT` was written about. Reaching inside a JSON
blob means `JSON_EXTRACT`, which is spelled differently enough between MySQL and
SQLite to be the kind of thing this repo has already shipped an outage over, or
a PHP-side cursor over the table, which that class went out of its way to avoid
on a shared host.

So the honest options are: teach the estimate a JSON path with a dialect test
behind it, or promote the six sub-fields to real columns. That is a decision, not
a two-line fix, and it should be made by whoever owns the SEO lane.

---

## For the integrator: the `KBB-Master-Plan.md` edit this lane may not make

This lane is forbidden from editing `KBB-Master-Plan.md`. Below is the anchor
and the exact replacement, to be applied verbatim.

**Anchor** — find these three lines in the Translation Module queue:

```
- [ ] **T4 · Content translations** — a polymorphic table over `name_ar` columns,
      so "what is still untranslated" is one query and a new field needs no
      schema change. Must not become an N+1 on a product grid; measure it
```

**Replace with:**

```
- [~] **T4 · Content translations — measured at volume this cycle; the render
      is what is left.** The table, the cached map, the editor boxes and the
      progress figures all work at 696 products with the whole catalogue
      translated. **The N+1 does not happen**: /ar/shop runs the same seven
      statements as /shop and /ar/cart the same eight as /cart, pinned at 400
      rows as well as at 24. What Arabic costs is **+6 MB of peak memory and
      +11–43 ms of wall clock on every Arabic page, none of it SQL** — 97% of
      the cached map is product long-prose that is read on one page at a time.
      Verdict, with the counterfactual measured: keep the map for short text
      (0.17 MB, 2,446 entries) and take `description`, `ingredients`,
      `how_to_use`, `pages.content` and `posts.body` out of it — a 19×, one
      file, and NOT urgent, for the reason below. See
      docs/fn-translation-at-scale.md.

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

      **Product::ingredients and Product::how_to_use are now on the allowlist**
      (the T4b gap). It was not a two-line fix: the third line was a stored-XSS
      hole. ProductEditorApiController sanitised the English of all four rich
      columns and handed the Arabic side a literal pair under a comment reading
      "the four rich fields are named" — so the moment those two joined the
      allowlist, the Arabic halves of two {!! !!} tabs went to the database
      unsanitised. Both loops now read one RICH_FIELDS constant, and the case
      is pinned.

      **seo.title / seo.desc and banner.heading / subheading / image_alt:
      argued, not done.** They belong translated — they are what an Arabic
      shopper reads in an Arabic search result — but they are JSON sub-keys,
      not columns, and `$translatable` names columns. The store already
      tolerates a dotted field; the part that does not is
      TranslationEstimate::fieldsWithText(), whose dialect-neutral SQL
      denominator drops any name that is not a bare identifier and would count
      them silently as zero work. A decision for the SEO lane, not a line in a
      diff.
```

**Anchor** — and these three lines:

```
- [ ] **T5 · The translate-from-Google accelerator** — pluggable provider, his
      own key, batched, cost shown before it runs, output as a draft. The manual
      path must keep working with no key at all
```

**Replace with:**

```
- [~] **T5 · The translate-from-Google accelerator — driven at volume this
      cycle; two defects found and fixed.** 240 outstanding fields go out
      as [100, 100, 40], every row a draft, `TranslationStore::map()` empty, the
      progress screen reading `drafts: 240, translated: 0, percent: 0`. A run
      that loses its second batch keeps the first, and the resume re-sends
      exactly the hundred that were lost and not one field it had already
      bought. No test touches the network: the provider is a recording fake, or
      the real GoogleProvider through Http::fake().

      **Fixed 1 — the shifted batch the foundation warned about was real.** The
      runner paired answers to requests by index and trusted the count.
      GoogleProvider pads a gap with null in place; nothing enforced that on the
      runner's side, so a provider returning a COMPACTED array wrote each
      product's Arabic copy onto the next product's row — 99 drafts, no errors,
      the money spent, one Approve from publishing somebody else's name on a
      skincare catalogue. A batch whose answer count does not match is now
      dropped whole and reported.

      **Fixed 2 — the receipt was not the bill.** `characters` was accumulated
      inside the write loop, so a batch the provider refused after reading the
      request left its characters out of the figure, and Google bills for text
      it was SENT. `run()` now returns `characters_sent` as well, counted before
      the call. The quote and the request already agreed to the character.

      **Found, not fixed, and it needs a decision:** a machine draft can be
      longer than the editor will let the owner SAVE. products.name is
      varchar(200), so TranslationInput mirrors max:200 onto the Arabic box,
      but the Arabic lives in translations.value, a mediumtext, and Arabic runs
      longer than English for the same meaning. Reproduced: a 264-character
      Arabic draft of a 192-character English name stores fine, appears in the
      box, and makes the ordinary Save 422 on a value the owner never typed.
      app/Support/TranslationInput.php is Lane EX's. See
      docs/fn-translation-at-scale.md.
```
