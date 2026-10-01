# Killing the import with `kill -9`, and resuming it — Lane KR

The owner is about to import his WooCommerce shop into the live Laravel shop
over SSH with `php artisan kbb:import`. Shared hosts kill long PHP processes.
Until this round **no import had been `kill -9`'d mid-run against the current
importer** (docs/IE-IMPORT-END-TO-END.md §7.7; Lane FV killed an older,
nine-entity version once, mid-orders, in docs/FV-IMPORT-AT-VOLUME.md §5).

## 1. The verdict

**Orders, line items, customers, products, refunds and money resume exactly**,
at every kill point tried, including inside an open batch and inside the very
first batch of an entity.

**Two things did not, and both are fixed here.** A kill in the middle of
`categories.csv` or `reviews.csv` left a shop whose row counts were all right,
whose report said "verified", and which was wrong:

| killed after | what was wrong after the resume | measured |
|---|---|---|
| 10 of 59 categories | 9 categories at the **top level**, with the wrong `depth` and `path` (so the wrong URL), and the 9 imported menu items built from those paths pointing at the wrong addresses | `categories.with_parent` **47** vs **56** clean |
| 1,000 of 2,514 reviews | **32 products** still showing 0.0 stars / 0 reviews on every card and every `?sort=rating`, while their pages listed the reviews | `sum(products.review_count)` **1,403** vs **1,449** clean |

**Root cause.** Both entities postpone work to `finalise()` — parent links,
the rating recompute — and collect what `finalise()` needs in **process
memory** during `import()` (`CategoryImporter::$pendingParents`,
`ReviewImporter::$touched`). The runner resumes by position and skips the rows
an earlier process committed, which is right for the rows, but a killed
process never reaches `finalise()`, and the resumed one never learned about
those rows. Both class comments claimed "an interrupted run that resumes and
finishes still gets it". The call to `finalise()` did happen. The data it
needed did not.

**Why the existing resume test did not catch it.** `ImportAtVolumeTest > it
resumes onto exactly the rows it had not done` stops each run with `--limit`.
A `--limit` slice finishes normally and runs `finalise()` over its own rows.
With a kill, `finalise()` never runs, and nothing in the suite simulated that.
That snapshot also never looked at `products.rating` or `categories.parent_id`.

## 2. The fix

`EntityImporter::alreadyCommitted(Row, ImportContext)` is a new hook, empty by
default. `ImportRunner::runEntity()` calls it for every row it skips on resume.
Its job is to write nothing and remember whatever `finalise()` needs.

- `CategoryImporter::alreadyCommitted()` puts the row's parent link back on
  `$pendingParents`. It runs through the same `identity()` checks `import()`
  makes first, so a row the earlier pass refused is refused again and not
  linked.
- `ReviewImporter::alreadyCommitted()` records the comment id. `finalise()`
  reads the products back from the review rows that were actually committed,
  one query per 500 ids, and adds them to the set it recomputes.

The fix also covers a process killed **after its last batch committed but
before `finish()`**: on resume every row is "already committed", every row is
replayed into the hook, and `finalise()` gets the whole file.

**Cost, measured.** On the admin screen's 400-row step over `reviews.csv`, each
slice now also recomputes the products of the earlier slices. The 7 slices took
0.68 / 0.51 / 0.51 / 0.61 / 0.66 / 0.63 / 0.40 s, recomputing 305 → 653
products. The slowest single step recorded in FV-IMPORT-AT-VOLUME §3 is 2.24 s.
There is no new query per row: the review lookup is one query per 500 ids, and
the category replay runs no query at all.

**Test.** `tests/Feature/ImportKilledMidEntityTest.php` stands in for the kill
with an exception thrown from inside a row's write. To the database that is the
same thing as SIGKILL: the open batch rolls back, the committed batches stay,
and `finalise()` never runs. The test then resumes the import and checks:

- every category's parent against `categories.csv`, and every cached `depth`;
- every product's stored rating against `ProductRating::refresh()` over the
  same table.

Mutation runs, each one made and reverted on this branch:

| mutation | result |
|---|---|
| remove the runner's `alreadyCommitted()` call | both tests red |
| empty `CategoryImporter::alreadyCommitted()` | categories test red, reviews test green |
| empty `ReviewImporter::alreadyCommitted()` | reviews test red (`product 41: 0 reviews stored, 1 approved`, …), categories test green |

## 3. How it was measured

`tools/kr-kill-rehearsal.sh` runs the whole thing and can be re-run. It writes
everything under `storage/kr-logs/`, which is gitignored.

1. **Export.** `tools/woo-volume-fixture/generate.php` at its defaults, which
   are the real shop's sizes: 671 products, 671 variations, 3,713 customers,
   4,159 orders, 10,582 order lines, 207 refunds, 1,386 order notes, 2,514
   reviews, 59 categories, 93 brands, plus menus and posts.
2. **One migrated file-SQLite template**, which every run copies. All runs
   start from the same demo-catalogue prices.
3. **Two clean imports** (`clean-a`, `clean-b`), each about 33 s. Diffing
   them gives the **noise floor**: the columns that differ between two runs of
   the same command on the same files. They are only `created_at`/`updated_at`
   stamped with `now()`, and the four checkpoint timestamps. Row counts and all
   other values agree.
4. **Each kill.** The script starts `php artisan kbb:import --dir=… --adopt-by-slug`
   in the background, waits for the named progress line, then `kill -9`s **the
   PID it started**. It records what the kill left before anything else opens
   the database file, because the first open rolls the journal back:
   - `hot_journal=yes` means a write transaction was open when the process
     died, so the kill landed **inside a batch**;
   - every checkpoint.

   A kill counts only if the entity's checkpoint is OPEN and inside the
   intended range. Otherwise the script retries with the next delay. One retry
   was needed: `k-first-batch` landed at 500 on the first try. The kill points
   in the table are measured, not intended.
5. **Resume** with exactly the same command, to the end.
6. **Compare.** The script dumps every table of both databases (all columns,
   ordered by primary key) and diffs them. A resumed database counts as
   identical when:
   - every table's row count equals `clean-a`;
   - every differing column is in the noise floor;
   - `tools/kr-db-compare.php checks` agrees line for line. That covers counts
     for 23 tables, duplicates on 15 external ids, money sums,
     `review_count`/rating sums, a per-order digest of line count and line
     total keyed on the WooCommerce order id, 10 orphan checks, and each
     checkpoint's processed / landed / rejected / finished.

   One column is compared differently. A resumed run re-reads every *finished*
   entity from row one by design (`Checkpoint::open`, which a delta import
   relies on), so on that pass its rows count as `unchanged` where the clean
   run had `created`. The checks compare the **sum** created+updated+unchanged
   per entity exactly, instead of the split.

Because the comparison includes `id` and every foreign key, a database counted
as identical also has the **same primary keys** as the clean one. The batch that
was rolled back left no gap in the ids. That is measured here on SQLite. On
MySQL, InnoDB does not give back auto-increment values on rollback, so the
production ids may have gaps after a kill. The rows, links and money are
unaffected, because every comparison that matters is keyed on WooCommerce ids.

## 4. Results (final tree)

Kill state is as recorded by `kr-db-compare.php state` immediately after the
`kill -9`, before any resume.

| scenario | kill point (measured) | batch open? | resumed by | identical to clean? |
|---|---|---|---|---|
| `k-products` (`--batch=100`) | products 300/671 committed (323 rows incl. 24 demo) | yes, hot journal | "resumed: 300 rows…" | **yes** |
| `k-orders` | orders 2,000/4,159 committed, 0 lines | yes, hot journal | "resumed: 2000 rows…" | **yes** |
| `k-first-batch` | order-items **0**/10,582, inside its first batch | yes, hot journal | order-items from row 1 | **yes** |
| `k-order-items` | order-items 5,000/10,582 | yes, hot journal | "resumed: 5000 rows…" | **yes** |
| `k-reviews` | reviews 1,000/2,514 (995 rows: 5 refused) | yes, hot journal | "resumed: 1000 rows…" | **yes** (before the fix: **no**, 32 products) |
| `k-categories` (`--batch=10`) | categories 10/59 | yes, hot journal | "resumed: 10 rows…" | **yes** (before the fix: **no**, 9 categories + 9 menu items) |
| `k-thrice` | killed 3×: customers 1,500 → orders 3,000 → order-items 8,000, one database, resumed after each | yes ×3 | "resumed: 8000 rows…" | **yes** |
| `f-changed` | orders 1,000/4,159; then `orders.csv` re-exported (order 10002 `wc-completed` → `wc-cancelled`, `date_modified` moved) | yes, hot journal | **refused**, exit 1, names `--restart`; `--restart` then run | **yes**, against a clean import of the *changed* export (which itself differs from `clean-a` in exactly `orders.status` ×1) |

Every row of the table, resumed vs clean:

| db | products | variants | customers | orders | order_items | refunds | reviews | Σ orders.total (fils) | Σ items.total | Σ review_count | cats with parent | lines/order digest | dup ext ids | orphans |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| clean-a | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-products | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-orders | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-first-batch | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-reviews | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-categories | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-order-items | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| k-thrice | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| clean-v2 | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |
| f-changed | 693 | 670 | 4409 | 4159 | 10582 | 207 | 2499 | 337327920 | 337280600 | 1449 | 56 | 463f316d | 0 | 0 |

(products 693 = 669 imported + 24 demo catalogue; customers 4,409 = 3,712
from the file + synthesised guests. "dup ext ids" sums the duplicates over
`products.wc_id`, `product_variants.wc_id`, `orders.wc_order_id`,
`order_items.wc_item_id`, `customers.wp_user_id`, `reviews.source_id`,
`categories.source_term_id`, `refunds.wc_refund_id` and
`order_notes.source_comment_id`. "orphans" sums 10 checks: lines without an
order or product, variants without a product, refunds and notes without an
order, reviews without a product, orders without a customer, addresses without
a customer, category pivots without a product, categories with a missing
parent.)

The import exits 1 on every clean run and every resume, for the same reason:
the export carries deliberate defects that are refused, such as 2 trashed
products and 6 orphan reviews. The rejected counts in the checkpoints match
clean-a exactly.

## 5. Noticed, not changed

**A re-imported row whose `updated_at` value has not changed gets `now()`.**
`OrderImporter` sets `updated_at` from the export's `date_modified` on purpose,
so that "recently modified" means something. But `ImportContext::apply()` uses
`forceFill()` then `save()`. On an UPDATE where the value is identical to the
stored one, `updated_at` is not dirty, and Eloquent's `updateTimestamps()`
stamps `now()` over it.

This happens only when another column changed and `date_modified` did not.
WooCommerce moves `date_modified` on every status change, so a real re-export
should not trigger it. The first draft of `f-changed` did trigger it, because
it changed a status without moving the date. The difference was exactly 1 row
of `orders.updated_at`.

It is not a kill or resume issue: any delta import has the same behaviour. It
was left alone under rule 1. The fix would be one line in `apply()`
(`$model->timestamps = false` when the attributes carry `updated_at`). It is
recorded here for whoever owns the importer next.

**Report-only memory.** Two pieces of per-process state also cover only the
rows *this* process read: `ImportRunner::$columnsSeen`, which feeds the
"columns no field reads" list, and `ProductImporter::$skusReported`. A resumed
run's discard list therefore samples values from the resumed rows only. The
column *names* are the header's and do not change. No data depends on either.

## 6. Reproduce

```bash
tools/kr-kill-rehearsal.sh                    # everything, ~10 minutes
tools/kr-kill-rehearsal.sh prepare clean k-reviews   # one scenario
cat storage/kr-logs/results.txt
```

There is no admin control. This is the command-line import path, and the fix
changes no setting and no screen.
