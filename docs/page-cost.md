# What every page costs, measured

Phase 12's performance half says "performance, measured not assumed". This is
the measurement, the fixes the numbers justified, and — at least as important —
the things that looked slow, were measured, and were left alone.

Every figure here was produced by `php artisan kbb:page-cost`
(`app/Console/Commands/PageCost.php`), which is checked in and re-runnable. No
number in this document was estimated.

## How to reproduce it

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS kbb_perf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# A .env pointing at that database. SESSION_DRIVER and CACHE_STORE are the
# LIVE HOST's, off env.staging.txt — not the suite's array drivers, which would
# delete the session's SELECT and UPDATE from every authenticated page's total.
cat > .env <<'EOF'
APP_ENV=production
APP_KEY=base64:...
DB_CONNECTION=mysql
DB_DATABASE=kbb_perf
DB_USERNAME=kbb
DB_PASSWORD=kbb
SESSION_DRIVER=database
CACHE_STORE=file
QUEUE_CONNECTION=sync
EOF

KBB_PUBLIC_PATH=$PWD/public php artisan kbb:page-cost --fresh --seed --runs=7 \
    --json=storage/app/page-cost.json --dump=storage/app/page-cost-bodies
```

Useful flags: `--only=admin,search` to narrow, `--queries` to print every
statement a page ran slowest-first, `--explain` for the slowest one's plan,
`--dump=DIR` to write each response body out for a before/after diff.

### Why every measurement is its own operating-system process

This application memoises in process-level statics in at least seven places —
`Setting::map()`, `SettingsService`, `Facets`, `Money`, `Url`,
`AdminPathService` — and CLAUDE.md names the first of them as a trap. Under
PHP-FPM a request **is** a process, so every one of those memos starts cold on
every page view a shopper performs. Measuring two pages in one PHP process
hands the second one the first one's warm memos and reports a number nobody
will ever experience.

So the command spawns itself once per repetition, and each child boots one
fresh application, handles one request through the real HTTP kernel — real
middleware, real session, real Blade — and prints what it cost. Nothing is
shared between two measurements except the database and the file cache, which
is exactly what is shared between two requests on the live host.

Cookies for the basket, the account and the admin come from a separate
preparation pass that performs real logins through the real middleware, so what
is measured is a signed-in page and not a redirect to a login form.

## The data volume

`--seed` builds this, deterministically (`mt_srand` is pinned, so two seeds
produce identical tables and a before/after comparison compares the same shop):

| | rows |
| --- | ---: |
| products | 3,025 |
| categories | 20 |
| brands | 93 |
| customers | 3,000 |
| orders | 6,000 |
| order lines | 27,000 |
| reviews | 6,458 (400 of them on one product) |

The live WooCommerce store carries 671 products across 93 brands and 3,712
customers. The figures above are comfortably past that, so an index that is
merely break-even today still shows its shape, and small enough to rebuild in
under a minute.

The product measured is a deliberate worst case: twelve gallery images and 400
approved reviews. Measuring the median product would report a page that costs
nothing and say nothing about the one that does.

## Where it ran, and what that does to the numbers

MySQL 8.0.46, PHP 8.4, InnoDB buffer pool warm. **Production is MySQL, which is
why this does not run on the suite's SQLite.** Where SQLite disagrees is noted
at the bottom.

The box this ran on is shared with four other lanes' test suites and a headless
Chromium; load average during the measurement was between 4 and 7 on 4 cores.
That matters enough to change the method: the before and after columns below
are **not** two runs an hour apart. They are three interleaved passes —
before, after, before, after, before, after — with the controllers swapped and
the indexes dropped and recreated between each, so a load spike lands on both
arms. Each pass is itself the median of five requests.

The noise floor that leaves is about ±1.5 ms. Any row below that moves by less
than that has not moved.

> One measurement in this lane was thrown away for exactly this reason. A
> covering index on `order_items` appeared to take the admin order list's
> derived table from 20.9 ms to 6.8 ms. Re-measured with the index dropped and
> recreated twice against an already-warm pool: 7.3 ms against 7.7 ms —
> nothing. The first "win" was the buffer pool warming up. The index is not in
> this branch. See *Measured and not taken* below.

## The table

SQL time is the sum of every statement the request issued, as Laravel's own
`DB::listen` timed it. Median of three interleaved passes, with the lowest pass
in brackets — the lowest is the least contaminated by another lane's suite.

| Page | Queries | SQL ms before → after | Slowest query ms | Peak memory | Verdict |
| --- | ---: | ---: | ---: | ---: | --- |
| `/` home | 4 | 2.1 → 2.3 [2.1 → 2.2] | 1.0 → 1.3 | 32 MB | Four statements. Nothing to fix. |
| `/shop` page 1 | 7 | 16.7 → **9.1** [16.2 → 7.5] | 8.4 → 4.6 | 32 MB | Default sort was a filesort over the whole catalogue. Indexed. |
| `/shop?paged=100` | 7 | 19.1 → 21.8 [17.3 → 18.5] | 11.8 → 13.0 | 32 MB | Unchanged. A deep OFFSET cannot be indexed away — see below. |
| category + filters | 9 | 4.6 → 5.5 [4.2 → 5.2] | 1.1 → 1.5 | 32 MB | Already cheap: the category narrows to ~200 rows before the sort. |
| brand page | 8 | 4.0 → 5.4 [3.9 → 3.8] | 1.1 → 2.6 | 32 MB | Noise. Nothing to fix. |
| `/shop?filter_brands=…` | 9 | 4.6 → 5.4 [3.7 → 4.0] | 1.3 → 2.4 | 32 MB | Noise. Nothing to fix. |
| product (12 images, 400 reviews) | 13 | 16.4 → 13.2 [9.6 → 10.2] | 4.4 → 2.2 | 36 MB | 13 statements against 400 reviews. The star histogram is one grouped query, not 400 rows counted in PHP. |
| search | 7 | 47.8 → **22.1** [40.8 → 21.0] | 21.9 → 18.3 | 32 MB | Halved by the sort index. What remains is the result COUNT — see below. |
| `/cart` with 6 lines | 8 | 4.7 → 6.9 [4.0 → 3.2] | 1.4 → 4.4 | 32 MB | Noise. Nothing to fix. |
| `/checkout` with 6 lines | 10 | 7.3 → 4.7 [3.8 → 4.3] | 2.9 → 1.3 | 32 MB | Noise. Nothing to fix. |
| account order list | 7 | 7.6 → 4.2 [5.3 → 4.2] | 4.5 → 1.6 | 32 MB | Noise. The `customer_id` FK index already serves the list and its COUNT. |
| account order detail | 8 | 6.8 → 4.8 [3.8 → 3.7] | 2.3 → 1.6 | 32 MB | Noise. Nothing to fix. |
| admin dashboard | 20 | 28.8 → 27.7 [26.0 → 27.2] | 4.1 → 4.6 | 32 MB | Unchanged, deliberately — see below. |
| admin orders list | 10 | 131.1 → **76.1** [127.3 → 73.0] | 41.9 → 38.1 | 32 MB | Three statements were aggregating 27,000 order lines to answer questions that never read the result. |
| admin products list | 11 | 209.0 → **59.9** [163.4 → 56.4] | 50.0 → 22.2 | 32 MB | Same defect, twice over. |

Query counts did not move on any page. That was already true before this lane
started — `StorefrontQueryBudgetTest` and `AdminListQueryBudgetTest` have been
pinning them for several lanes, and there was no N+1 left to find. **What
nobody had measured was time**, and a page can run seven statements and spend
forty milliseconds in them.

Peak memory is flat at 32 MB across every page (36 MB on the 400-review product
page), against PHP's default 128 MB limit. Memory is not a problem on this
application and no change here was made for it.

Two wrinkles in the query counts. Laravel's session garbage collector fires on
a 2% lottery, so an occasional page shows one extra `delete from sessions`; it
is not the page. And the table was measured at `9f0bed7`, immediately before
the bilingual foundation landed: `SetLocaleFromPath` now reads `Setting::map()`
on every request, which adds one `select key, value from settings` to the two
admin-api endpoints (the storefront pages already read it). A fresh run
therefore shows 11 and 12 statements there rather than 10 and 11. The SQL time
is unchanged — that statement is 0.2 ms.

## The fixes

### 1. The shop's default sort had no index

`Store\ShopController::applyDefaultSort()` is the sort a shopper gets when they
have chosen nothing — `/shop` and every category archive:

```sql
... WHERE status='publish' AND is_visible=1
      AND (published_at IS NULL OR published_at <= ?) AND deleted_at IS NULL
    ORDER BY featured DESC, position ASC, name ASC, id ASC
    LIMIT 24
```

Nothing indexed that ordering. `products_status_is_visible_index` does not
help: nearly every row in a shop's catalogue is published and visible, so the
filter excludes almost nothing.

```
EXPLAIN  type=ref  key=products_status_is_visible_index  rows=3025
         Extra: Using where; Using filesort
```

Two indexes, added by
`database/migrations/2026_11_11_000000_index_featured_sort_and_clear_caches.php`,
because `applyDefaultSort()` consults `products.position` only when the
`product_sorting` module is on and `position` sits in the MIDDLE of the ORDER
BY, so neither shape is a prefix of the other:

| ORDER BY | before | after |
| --- | ---: | ---: |
| `featured DESC, position ASC, name ASC, id ASC` (module on) | 7.4 – 8.0 ms | 0.24 – 0.29 ms |
| `featured DESC, name ASC, id ASC` (module off) | 5.8 – 6.8 ms | 0.25 – 0.28 ms |

Both measured three times with the index dropped and recreated in between.
The live shop runs the first shape (2026\_09\_17\_000000 turns `product_sorting`
on for existing installs) and Store → Modules can turn it off this afternoon,
at which point the page is 6 ms again with only one index present. A page that
is fast until somebody flips a documented switch is not fixed.

Cost, measured rather than feared: 0.156 MB and 0.141 MB at 3,025 rows
(`mysql.innodb_index_stats`). `name` is a `varchar(255)` and InnoDB stores the
bytes a row has rather than the maximum, so these are the same order of size as
the existing single-column indexes on this table. `products` is written by
imports and the product editor, not by shoppers.

The same index is what halved `search`: `/shop?s=serum` runs the same ORDER BY
over the same table, and its row query fell from about 20 ms to 0.5 ms because
MySQL now walks the index and stops at 25 matches instead of sorting 3,025 rows.

It does **not** help a deep page, and the migration says so: `/shop?paged=100`
is 9.5 ms before and 9.4 ms after. An ordered index walk still has to pass
every entry it skips, so MySQL goes back to the filesort and is right to.

The migration is reversible — `down()` drops both indexes — which the three
index migrations before it are not. Proven by running `migrate:rollback` and
`migrate` against MySQL and reading the index list back both times.

### 2. The admin order list aggregated 27,000 order lines four times per request

`Admin\OrdersApiController::rowQuery()` joined two grouped derived tables onto
the query every statement on the screen is built from:

```sql
LEFT JOIN (SELECT order_id, SUM(quantity) AS units, COUNT(*) AS lines_count
           FROM order_items GROUP BY order_id) ia ON ia.order_id = orders.id
LEFT JOIN (SELECT order_id, SUM(amount) AS refunded_fils
           FROM refunds WHERE status IN (…) GROUP BY order_id) ra ON …
```

A derived table is materialised in full before it can be joined, so every
statement aggregated the whole of `order_items` to answer a question about at
most fifty orders:

```
the page of rows              52.6 ms      needs ia and ra
the summary strip             46.2 ms      names ra; never names ia
the per-status chip counts    42.5 ms      names neither
the pagination COUNT          41.4 ms      names neither
```

`AggregatesQueries::aggregateQuery()` discards the select list so that an
aggregate is legal — and the select list was the only thing referencing those
joins.

The joins now go on in `withLineAndRefundTotals()` and `withRefundTotals()`,
which are called by the statements that read them: the page of rows and the CSV
export take both, the summary strip takes refunds only, and the two counting
statements take neither.

**Why that cannot change a figure**: both subqueries `GROUP BY order_id`, so
each yields at most one row per order, and a LEFT JOIN onto at most one row can
neither multiply rows nor drop them. A COUNT over `orders` is therefore the same
COUNT with the joins and without. That is an argument, not evidence, so
`tests/Feature/PageCostBudgetTest.php` asserts the endpoint's per-row units,
per-row lines, per-row refunds, every chip count and every summary field against
the same numbers worked out from the rows by hand.

**131.1 ms → 76.1 ms.** The response body is byte-for-byte identical.

### 3. The admin product list did the same thing, twice over

`Admin\CatalogProductsApiController::rowQuery()` carried two grouped derived
tables: `oa` (order\_items joined to orders, grouped by product) and `ca`
(the category pivot, grouped by product). Six statements, and:

```
the derived chip counts     40.4 ms     needs ca; never names oa
the stock_status chips      40.2 ms     names neither
the pagination COUNT        39.9 ms     names neither
the status chip counts      39.6 ms     names neither
the summary strip           38.5 ms     names neither
the page of rows            34.7 ms     needs both
```

`oa` moved to `withSalesTotals()` and `ca` to `withCategoryCounts()`, each
called only where a column of it is read.

`ca` needed one further change to get out of the base query, because the
"No category" chip **filtered** on it: `COALESCE(ca.category_count, 0) = 0` is
now a `whereNotExists` against `category_product`. Identical set of rows — "no
pivot row" and "a count of zero over the pivot" are the same statement about the
same table — and it is the shape `baseQuery()` already uses for `category_id`,
with the same reasoning recorded there. Measured, same statement with the `ca`
join and without, twice in each direction:

| statement | with `ca` | without |
| --- | ---: | ---: |
| pagination COUNT | 6.7 – 7.2 ms | 3.3 – 3.7 ms |
| status chips | 8.0 – 8.7 ms | 4.9 – 5.3 ms |
| summary strip | 7.7 – 8.0 ms | 3.6 – 4.3 ms |

`b` (brands) stays in the base query on purpose: the search box matches
`b.name`, so a statement that counts the filtered set has to carry it or it
would count the wrong rows.

**209.0 ms → 59.9 ms.** The response body is byte-for-byte identical.

## Proof that nothing changed

`--dump=DIR` writes every page's response body. Before and after, with CSRF
tokens, `"N minutes ago"` and the dispatch countdown normalised (all three
change between any two requests), **all fifteen pages are byte-identical**. The
three admin endpoints are byte-identical without any normalisation at all.

## Measured and not taken

Each of these was measured, and each is left alone for a reason.

**An index on `order_items`.** The admin lists' remaining cost is the derived
tables on the row page, which still aggregate the whole of `order_items`. A
covering `(order_id, product_id, quantity, total)` looked like it halved that:
20.9 ms → 6.8 ms on the group-by, 26.3 ms → 11.1 ms on the row page. Re-measured
properly — index dropped and recreated twice, warm pool both times — it was 7.3
ms against 7.7 ms and 15.8 ms against 16.4 ms. The first figures were the buffer
pool warming, not the index. **A redundant index costs every write and buys
nothing**, so it is not here.

**Correlated subqueries in place of the row page's derived tables.** This is a
real win at the default page size and a real loss at the maximum one:

| | derived table | correlated subqueries |
| --- | ---: | ---: |
| orders, 25 rows | 13.4 ms | **5.0 ms** |
| orders, 500 rows | 18.9 ms | **10.4 ms** |
| products, 25 rows | 19.5 ms | **2.5 ms** |
| products, 500 rows | **21.8 ms** | 27.1 ms |

The operator chooses the page size (`per_page`, clamped to 500). Trading the
maximum page size for the default one is a change of shape, not an improvement,
and which way it falls depends on a control the code does not get to set. Left
as it is.

**The search result COUNT: 15 – 21 ms, and it stays.** `/shop?s=…` expands the
term through `SearchTerms` and matches with `LIKE '%term%'` on three columns. A
leading wildcard cannot use an index, and the COUNT has to examine every product
to report an honest total. A `FULLTEXT` index would be fast and would **change
which products match** — stemming, a minimum word length, a different notion of
a word — on a shop where a shopper typing "sun cream" has to reach "sunscreen".
That is a behaviour change bought with speed, which this lane does not do.

Measured and also declined: rewriting the search's
`orWhereHas('brand', …)` as a LEFT JOIN. The `EXISTS` is per-row on 3,025 rows
and the join is not; the COUNT goes 15.0 ms → 9.2 ms. It is 5 ms on one page,
inside `ShopController::applyFacets()`, which belongs to the storefront lane.
Named here rather than taken.

**The admin dashboard: 20 statements, 27.7 ms, untouched.** Nine of them are
`COUNT(*)` and `SUM()` over the whole of `orders`, `products`, `reviews` and
`customers`, each wrapped in `DemoSeed::exclude()`'s `NOT EXISTS`. They are 1.5
– 3.8 ms each and they genuinely have to read every row: there is no way to
count 6,000 orders without touching 6,000 orders. `orders.status` is already
indexed and MySQL declines it because the four revenue statuses match most of
the table, which is the correct decision. The only thing that would make this
screen faster is a cached or denormalised counter, which is the one fix this
lane is forbidden — a cache here has to be invalidated by hand on a host with no
queue worker, and `Setting::map()` has already taught this project what a stale
memo costs.

**`/shop?paged=100`: 21.8 ms, untouched.** `LIMIT 25 OFFSET 2475` has to pass
2,475 rows however it is sorted. Keyset pagination would fix it and would change
the URL contract — `?paged=N` is a live, crawled URL shape — so it is not a
speed fix, it is a different feature.

**The product page's 400-review queries.** 13 statements and 13.2 ms for a
product with twelve images and 400 approved reviews, of which 3.3 ms is the star
histogram and 1.9 ms the review list. Both are single grouped statements over
`reviews (product_id, status)`, which 2026\_10\_12\_000000 already indexed. There
is nothing here to remove.

## The guard

`tests/Feature/PageCostBudgetTest.php`, and it runs on both engines.

A hard query ceiling on `/shop`, a category archive and the product page, set at
the measured count plus three, with the measured count written beside it so that
whoever trips it can see what moved. Plus a growth check: the same page measured
at 4 products and again at 40.

**The ceiling only works because the fixture clears the demo catalogue first,**
and that is not tidiness. The migration set seeds 24 products and
`products_per_page` is 24, so a fixture that ADDS to them fills the first page
whatever it does. Measured with a real N+1 deliberately in place, the growth
check **passed** — both pages drew the same 24 cards and ran the same 24 extra
statements. That is precisely the shape of guard this repo already has four of.

With the fixture fixed, and the N+1 put back by deleting
`->with('brand:id,name,slug')` from `ShopController::index()`:

```
/shop ran 39 queries, ceiling 19 (it was 16 when this ceiling was set)
/product-category/cost-category ran 39 queries, ceiling 19 (it was 16 …)
/shop cost 19 queries for 4 products and 38 for 40. That is a query per card.
```

Both assertions go red. Reverted, both go green.

The admin assertions were checked the same way: dropping
`withLineAndRefundTotals()` and `withSalesTotals()` from the row queries — the
plausible mistake this change could make — fails three tests with "units on
order 1: Failed asserting that 0 is identical to 6".

## Where SQLite disagrees

The suite runs on SQLite and the numbers above are MySQL's. Two differences
matter to anyone reading a query count:

- SQLite answers `Schema::hasColumn()` from a pragma that `DB::listen` can see,
  while MySQL asks `information_schema`. `ProductVisibility::raw()` reads its
  column list once, so SQLite measures one or two statements MORE than MySQL on
  the listing pages. A ceiling that holds on SQLite holds on MySQL.
- SQLite has no query planner worth measuring at this size and no buffer pool,
  so timings from it say nothing about the live host. That is why this harness
  refuses to be a test: it warns and keeps going if it finds itself on SQLite,
  and the numbers in this document were all taken on MySQL 8.0.

The two indexes are created with raw DDL because `Blueprint::index()` cannot
express a per-column direction and `featured DESC` is the whole point. MySQL 8.0
and SQLite 3.8.3+ accept the same statement, backticks included.
