# The WooCommerce migration, rehearsed at the shop's real volume

Lane FV. Phase 13. **671 products, 4,159 orders, 3,712 customers**, run end to
end on **MySQL**, twice over, and killed in the middle.

This is the record of what was measured, what it proved, what it found, and the
two things the owner has to decide. `docs/IMPORT-RUNBOOK.md` is still the
operating manual; this is the evidence behind the sentence in it that says the
import is safe to press.

---

## 1. The short answer

**Yes, the owner can press it — from Store → Import, not from a shell.**

The whole migration completes in **62 browser steps**, the slowest of which took
**2.2 seconds**. At a step size of 100 rows it is 229 steps and the slowest is
**0.67 seconds**. Nothing in it needs a request to survive longer than a couple
of seconds, which is the only property that matters on a host whose real
`max_execution_time` cannot be discovered from inside PHP.

**The command-line path has a hazard the screen does not.** `kbb:import --limit`
is a budget **per entity**, so one invocation imports some orders before
`customers.csv` has been read to the end — and every order whose customer is
further down that file synthesises a guest row that then refuses the genuine
customer. Measured: **14 of 80 customers lost** on a 140-order export run in
slices of 23. §6 has the detail. The screen cannot do this, because it runs one
entity per request and never starts the next until the previous one is finished.

---

## 2. What was run, and on what

| | |
|---|---|
| Fixture | `tools/woo-volume-fixture/generate.php`, checked in. Deterministic; the same seed writes byte-identical files. |
| Volume | 671 products · 4,159 orders · 10,571 line items · 3,712 customers · 2,514 reviews · 93 brands · 59 categories · 40 coupons · 671 Yoast rows |
| Also in the folder | `refunds.csv`, `order_notes.csv`, `variations.csv`, `tags.csv` — four files no entity opens, so the unread-file channel has something to find |
| Engine | MySQL 8.0, `strict` mode, `utf8mb4_unicode_ci` — **not** SQLite. A migration of this size is exactly where the two dialects diverge. |
| Code | `lane/woo-data-migration` |

The fixture is not clean data. It carries, at the density a real export does:
two products in the WordPress trash, two sharing a SKU, two with no SKU at all,
128 prices carrying fils, seven descriptions with a `<script>` in them, 700
guest orders, 11 orders with no email, nine naming a WordPress user who is not
in the export, 14 in USD, 494 line items whose unit price does not divide
evenly, 152 refund lines with a negative quantity, two customers whose address
carries a three-letter country code, one pair of WordPress users sharing an
email, 20 coupons with a bare expiry date, seven fixed-amount coupons carrying
fils, six reviews of a product that is not in the export, and 382 comments in
the WordPress trash.

---

## 3. The numbers

### Run 1 — a fresh database

| | |
|---|---|
| **Wall clock** | **49.2 s** |
| **Peak memory** | **34 MB** (`memory_get_peak_usage(true)`) |
| **Queries** | **107,258** |

| bucket | rows | seconds | queries | queries/row |
|---|---:|---:|---:|---:|
| categories | 59 | 0.0 | 579 | 9.8 |
| brands | 93 | 0.0 | 4,486 | 48.2 |
| products | 671 | 0.7 | 1,666 | 2.5 |
| coupons | 40 | 0.0 | 4,000 | 100.0 |
| customers | 3,713 | 10.6 | 30,796 | 8.3 |
| orders | 4,159 | 17.4 | 38,213 | 9.2 |
| order-items | 10,571 | 9.0 | 21,199 | 2.0 |
| reviews | 2,514 | 2.3 | 5,680 | 2.3 |
| seo | 671 | 0.3 | 344 | 0.5 |

Memory is flat in the size of the export: the source is a generator, batches are
500 rows, and the only thing held for the whole run is the id maps (int ⇒ int).
34 MB is comfortably inside a shared host's usual 128 MB.

The two outliers are worth naming because they are the two buckets that would
hurt at ten times the volume: **brands at 48 queries a row** and **coupons at
100 queries a row** — both are `SlugGuard` and the restriction-list translation
doing per-row lookups. At 93 and 40 rows they cost four seconds between them and
nothing needs doing. On a catalogue with 5,000 coupons they would be the first
thing to fix.

### Run 2 — the same command again, on the database run 1 produced

| | |
|---|---|
| **Wall clock** | **22.1 s** |
| **Peak memory** | **34 MB** |
| **Queries** | **44,613** |
| **created** | **0** |
| **updated** | **0** |
| **unchanged** | **21,999** — every row |

### The admin screen

| step size | steps | wall clock | slowest single step | peak memory |
|---|---:|---:|---:|---:|
| 400 rows | **62** | 63.4 s | **2.24 s** | 32 MB |
| 100 rows | **229** | 72.2 s | **0.67 s** | 32 MB |

The screen's result is **identical to the command's**, row for row and value for
value, once both are given `--adopt-by-slug` (see §7).

---

## 4. Idempotent — proved by row counts, not by the absence of errors

Run 2 above reported `created 0, updated 0` for every one of the nine buckets.
Separately, a digest of every imported row's identity and money columns —
products, orders, order items, customers, coupons — was taken after run 1 and
again after run 2:

```
diff snapshot-after-run-1.json snapshot-after-run-2.json
→ identical
```

Row counts, sums and per-table MD5s, all unchanged. `tests/Feature/ImportAtVolumeTest.php`
asserts the same thing at a size the suite can afford, and asserts the *shape* of
the second report as well: a `created` on the second pass means a duplicate went
in, an `updated` means something is rewritten every run and the idempotency claim
is a guess.

---

## 5. Resumable — proved by killing it

The import was started against an empty database and the process killed with
`SIGKILL` 26 seconds in, matching `/proc/<pid>/cwd` to this worktree first.

```
checkpoints at the moment of death
  categories=59* brands=93* products=671* coupons=40* customers=3713* orders=1500-
tables
  orders=1500  order_items=0  customers=3973
```

The kill landed on a batch boundary because it cannot land anywhere else: each
batch's rows and the advance of its checkpoint commit in one transaction, so a
killed process rolls back the batch it was inside and the recorded offset is
exactly the work that survived.

The same command was then run again. It reported `resumed: 1,500 rows were
already committed by an earlier run and were not re-read`, created the remaining
2,659 orders, and finished. Comparing the killed-and-resumed database against
the uninterrupted one:

* every row count identical
* every money sum identical — `orders.total` and `order_items.total` to the fil
* the 669 imported product rows **byte-for-byte identical**
* zero duplicate external ids: 4,159 distinct `wc_order_id`, 10,571 distinct `wc_item_id`
* zero orphaned line items

The only difference between the two databases was the 24 demo-catalogue
products, whose prices `DemoCatalogueSeeder` randomises on every `migrate:fresh`
and which the import does not touch.

A second, harsher run killed the process **eight times in a row at five-second
intervals**. Same result: no duplicates, no half-orders, and every restart
resumed from the last committed batch.

---

## 6. ▲ `--limit` across a whole export imports orders before their customers

**This is the one finding that costs data, and it is in the documented
shared-hosting workflow.**

`docs/IMPORT-RUNBOOK.md` §2 warns that running `--only=orders` before the
customers are in leaves a mess: the order cannot find its customer, links by
billing email, synthesises a guest row with a NULL `wp_user_id`, and the genuine
WordPress user is then **refused as an email collision** when they arrive.

`--limit` produces the same sequence by a different route, and nothing said so.
It is a budget **per entity**, so a single invocation does N customers, then N
orders, then N line items — and every order in that slice whose customer is
further down `customers.csv` does exactly the thing the runbook warns about.

Measured on a 45-product / 140-order / 80-customer export in slices of 23:

```
customers imported   66 of 80
refused              14, every one of them:
  "email 'shopper427@example.test' already belongs to a customer with no
   WordPress id (id 24)"
```

Nothing is corrupted and nothing is silent — each refusal is named, and the
count verification added by this lane shows the shortfall. But fourteen people
are missing from the shop, and the remedy printed beside them points at decision
D2, which is a different problem entirely.

**What was done about it.** `kbb:import` now prints a warning naming the hazard
and the two safe alternatives whenever `--limit` is used on a live run covering
more than one entity. It is a warning and not a refusal because `--limit` across
every entity is the shape the runbook documents and two other suites already
exercise; turning it into an error from this lane would break work that is not
wrong, only unsafe in one combination.

**What the owner should do instead**, in order of preference:

1. **Store → Import.** It steps one entity per request in the runner's own
   dependency order and never starts the next until the current one is finished.
   `tests/Feature/ImportAtVolumeTest.php` asserts that no order is ever read
   before `customers.csv` is complete, whatever the step size.
2. `kbb:import --only=customers` repeatedly until it finishes, **then**
   `--only=orders`, and so on down `ImportRunner::entityNames()`.
3. `kbb:import` with no `--limit` at all, against a restored copy where there is
   no timeout — which is what the command was designed for.

### And a smaller one in the same place: the loop has no termination signal

A finished entity restarts from row one on the next run (runbook §4 rule 2), and
if `--limit` then cuts that re-presentation short the entity is left *unfinished
again*. Six consecutive limited runs over the same unchanged export:

```
run 1: categories=13* brands=12* products=25- ... orders=25-
run 2: categories=13* brands=12* products=40* ... orders=50-
run 3: categories=13* brands=12* products=25- ... orders=75-
run 4: categories=13* brands=12* products=40* ... orders=100-
run 5: categories=13* brands=12* products=25- ... orders=120*
run 6: categories=13* brands=12* products=40* ... orders=25-
```

`orders` reaches 120 rows and complete, and is then back at 25 and incomplete.
Nothing is lost — every extra pass reports unchanged — but "every checkpoint is
finished" is a state a loop of limited runs never reliably reaches, so a script
cannot use it to know when to stop. The screen has no such problem: it keeps its
own `done_entities` list in `import_runs` and reaches `complete`.

---

## 7. Count-based verification after each bucket — built, because it did not exist

Phase 13's second line is *"count-based verification after each bucket"*, and
the report could not do it. Every figure it printed was the importer describing
its own work: `created` is incremented by the code that created the row,
`rejected` by the code that refused it. **A row read from the file and then
neither written nor refused increments nothing**, and is invisible in a report
whose every column is self-reported. The totals still look plausible.

Two checks now run after every bucket, neither of which asks the importer how it
thinks it did:

* **rows read vs rows accounted for.** The runner counts rows off the source and
  separately watches each one move its entity's own tally. A row that moves
  nothing and throws nothing is recorded with its line and its id — the *named
  discrepancy* the plan asks for, which is a row and not a total that is one
  short.
* **rows in the database.** One `COUNT` per bucket against the table the entity
  writes to, restricted to rows carrying an external id. It is the only number
  in the whole report that comes from the database.

```
Count-based verification — rows in against rows out, per bucket:
+-------------+-----------+-----------+---------+-------------+----------+
| bucket      | rows read | accounted | refused | in database | verdict  |
+-------------+-----------+-----------+---------+-------------+----------+
| categories  | 59        | 59        | 0       | 59          | VERIFIED |
| brands      | 93        | 93        | 0       | 93          | VERIFIED |
| products    | 671       | 669       | 2       | 669         | VERIFIED |
| coupons     | 40        | 40        | 0       | 40          | VERIFIED |
| customers   | 3,713     | 3,712     | 1       | 3,712       | VERIFIED |
| orders      | 4,159     | 4,159     | 0       | 4,159       | VERIFIED |
| order-items | 10,571    | 10,571    | 0       | 10,571      | VERIFIED |
| reviews     | 2,514     | 2,496     | 18      | 2,496       | VERIFIED |
| seo         | 671       | 669       | 2       | —           | no table |
+-------------+-----------+-----------+---------+-------------+----------+
```

Five things it is careful about, each of which would otherwise make it lie:

* **The count is on the external id, not on the table.** `seed_demo_catalogue`
  puts 24 products, 8 brands and 6 categories on every install with no external
  id at all, so `COUNT(*) FROM products` is 693 for 669 imported and the
  difference reads like an import bug.
* **Guests are not counted as customers of the file.** The customers bucket's
  `created` legitimately includes the 700 guest rows the *orders* bucket
  synthesised — 4,411 created for a 3,713-row file — so that tally can never
  answer "customers in = customers out". The count can, because it asks about
  `wp_user_id`.
* **More rows than the file supplied is a note, not an alarm.** A delta import of
  six new orders runs against a table holding four thousand from last week.
* **A part-way bucket says so.** The screen imports in slices; a verification
  that reported a shortfall on every slice would be noise.
* **A resumed run reports the count without a verdict.** It cannot know how many
  of the rows it did not re-read were refusals, so `read − refused` is not the
  number the table should hold. Claiming a shortfall on that arithmetic would
  raise an alarm on every resumed import that refused anything, which on shared
  hosting is most of them.

The line also goes into the entity's **notes**, which is the one channel both
front ends already print — so it appears on Store → Import with no change to a
view file. It replaces itself there rather than stacking one stale sentence per
slice.

An unaccounted row now **fails the command** even when nothing was refused: a
refused row is one the importer knows it does not have, and an unaccounted row is
one it does not know it does not have.

One thing the table makes visible that nothing did before: **refusing a product
refuses things downstream of it.** The two products in the WordPress trash cost
two `seo` rows and twelve of the eighteen refused reviews, because a review of a
product that is not here cannot be attached to anything. Each of those is named
individually in the rejection list, and the three buckets now line up in one
place so the chain can be read rather than reconstructed.

---

## 8. Money — 39,156 values checked against the export, nothing wrong

Every money value in the database was compared against the decimal string in the
CSV that produced it, by integer arithmetic with no float anywhere:

| what | values | mismatches |
|---|---:|---:|
| product price and sale price | 1,338 | 0 |
| order total, subtotal, discount, shipping | 16,636 | 0 |
| line item subtotal and total | 21,142 | 0 |
| coupon amount | 40 | 0 |

`coupons.amount` is the column whose **unit depends on another column** —
hundredths of a per cent for `percent`, fils for everything else — and
WooCommerce writes one decimal string for both. Confirmed against real values:
`20` → `2000`, `12.5` → `1250`, `99.50` → `9950`. A percentage coupon's stored
amount never exceeds 10000.

`sum(orders.total)` = 336,987,370 fils and `sum(order_items.total)` =
336,891,100 fils; both survived the kill-and-resume unchanged.

---

## 9. ▲ The other doors into a table whose rule lives in a controller

Phase 13 already records that `CouponImporter` bypasses the admin controller's
whole-dirham refusal. It is not the only one. The rule lives in eight
controllers — `CouponAdminApiController`, `CatalogProductsApiController`,
`ProductEditorApiController`, `EcommerceApiController`, `AdminOrderController`,
`ShippingApiController`, `ExtendedDeliveryApiController`, `AdminController` —
and a rule that lives in a controller is a rule that only applies to people
typing into a form.

| table and column | where the rule lives | who else writes it | verdict |
|---|---|---|---|
| `coupons.amount` | `CouponAdminApiController::save()` | **`CouponImporter`** | Known, recorded, and reported: `kbb:whole-dirhams` counts coupons separately. 7 of 40 imported coupons carried fils at volume. **The expensive kind** — `CouponService::discountFor()` rounds a discount **up**, so an imported AED 99.50 coupon hands back AED 100.00 on every order. |
| `products.price`, `products.sale_price` | `CatalogProductsApiController`, `ProductEditorApiController` | **`ProductImporter`** | By design and reported as an ADJUSTED line per product; 127 of 669 at volume. Stored exactly, printed rounded. `kbb:whole-dirhams --fix` can settle them. |
| `products.sale_price` | the same two controllers | **`database/seeders/DemoCatalogueSeeder.php:76`** | ▲ **Not previously named.** `'sale_price' => $onSale ? (int) round($price * 0.7) : null` — 70% of a whole dirham is not a whole dirham. Five of the 24 demo products ship with a sale price carrying fils **on every install, production included**, and nothing refuses it because the rule is in a controller no seeder goes through. Measured after a full import: 127 imported products carrying fils and **5 demo ones**. |
| `order_items.unit_price` | `AdminOrderController` (manual orders) | **`OrderItemImporter`**, and the checkout | Correct as it stands. An order is a record of what a customer was charged; `kbb:whole-dirhams` never touches one on any flag. 494 line items at volume carry a unit price that does not divide evenly, and each is reported. |
| `settings` money keys | `EcommerceApiController`, `ModuleSchema` | nothing else | Clean. |
| `shipping_methods` rates | `ShippingApiController`, `ExtendedDeliveryApiController` | nothing else | Clean. |

The seeder line is the one worth fixing and it is not this lane's file. Exact
change in §12.

---

## 10. The discard list — its shape, and that nothing is silent

`--changes=` writes it to a CSV; the screen shows it before a row moves. At full
volume it came to **2,297 adjusted values and 1,369 discarded things**, grouped
by kind, each kind carrying its full count and five worked examples with the
before and the after. Not one row per observation: 9 ignored columns over 4,159
orders would otherwise be 37,431 identical lines, and the report's sample cap
would then hide the tail — which is the part that matters, because every one of
those names is a different thing the owner is losing.

What the run actually produced:

**ADJUSTED — imported, and not what the export said**

| kind | count |
|---|---:|
| an order amount carrying fils on a shop that prints whole dirhams | 1,046 |
| a unit price truncated by integer division | 494 |
| `comment_approved 'trash'` imported as `spam` | 378 |
| a negative refund quantity clamped to zero while the money stayed | 152 |
| a price carrying fils | 127 |
| coupon code lower-cased | 40 |
| a bare coupon expiry moved to 23:59:59 | 20 |
| a review with no author imported as "Anonymous" | 15 |
| an order in a currency that is not the store's | 14 |
| a fixed coupon amount carrying fils | 7 |
| two products sharing one SKU | 2 |
| no SKU in the export | 2 |
| a coupon restriction naming products that are not in this shop | 1 |

**DISCARDED — in the export, and never in the database**

| kind | count |
|---|---:|
| `_yoast_wpseo_focuskw`, no equivalent here | 669 |
| `_yoast_wpseo_linkdex`, no equivalent here | 669 |
| a coupon's `usage_limit_per_user` (8) and `used_by` (8) | 16 |
| `<script>` removed from a product description | 7 |
| **columns in the export that nothing reads** — one entry per entity, naming every column with the first real value found in it | 5 |
| **files in the folder that no importer opens** — `refunds.csv` (207 rows), `order_notes.csv` (1,386), `variations.csv` (335), `tags.csv` (74), and `manifest.json` | 5 |

The two bold rows are the channel that found `coupons.csv` and `reviews.csv` in
the first place. They still work — and they are not selective about it: all four
planted files were named with their row counts, and so was the generator's own
`manifest.json`, which is exactly the behaviour wanted. A channel that only
names the files somebody thought of is not a channel.

On the column side, `meta:_delivery_instructions = Ring the bell twice`,
`refund_amount` and `order_notes` were all named as columns nothing reads — each
with a real value beside the name, so the owner is approving a fact rather than a
column heading.

---

## 11. ▲ What a real export still carries that nothing here reads

Assume there are more unread files, because there were. The sharp version of the
answer is not "this application has no concept of refunds" — **it has a
`refunds` table** — it is that the migration will leave it empty and the report
will say so in one line the owner may skim past.

After a complete, clean, full-volume import, these tables are still at zero:

| table | what a real WooCommerce export carries for it | why it matters |
|---|---|---|
| `refunds` | `refunds.csv`, or the refund rows inside the order export | **Money the shop gave back.** `orders.status = refunded` imports, and the amount refunded does not. A partial refund imports as an order at its full total. The `refund_amount` column is named in the discard list and nothing more. |
| `order_notes` | `order_notes.csv` | The history of what was said and done on an order. This application writes its own notes and would show these beside them. |
| `product_variants`, `product_variant_attribute_value` | `variations.csv` — every variable product's sizes, shades and per-variant prices and SKUs | A variable product imports as its parent only. The shop then sells "50ml or 100ml" as one price. This is the largest missing entity by revenue. |
| `attributes`, `attribute_values`, `product_attribute_value` | the `pa_*` terms other than `pa_brands` | Brands are imported *because* someone noticed `pa_brands` was an attribute. The other attributes are what the filters on a category page are built from. |
| `tags`, `product_tag` | `tags.csv` | Product tags, and the tag archives Google has indexed. |
| `posts` | the blog | 0 rows after the import. |
| `pages` | WordPress pages | 7 rows after the import — all of them this application's own, none from WordPress. |

Two more that are not files at all and are covered elsewhere, named so this list
is not read as complete: **media** (`kbb:import-media`, read-only, and `remote`
is the number to watch) and **URLs** (`kbb:import-redirects`). Neither runs as
part of `kbb:import` and neither shows up in a row count.

And one that cannot be imported at all: **a coupon's per-customer usage limit**.
There is no column that could hold "who has already used this", so every
imported code starts every shopper again from zero uses. It is a fact about the
two data models, not a mapping that was skipped.

---

## 12. Changes for files this lane may not edit

### `database/seeders/DemoCatalogueSeeder.php` — the demo sale prices carry fils

Anchor:

```php
                    'sale_price' => $onSale ? (int) round($price * 0.7) : null,
```

Replacement:

```php
                    /*
                     * WHOLE DIRHAMS, because the rule that says so lives in a
                     * controller and no seeder goes through one. 70% of a whole
                     * dirham is not a whole dirham: five of the 24 products this
                     * seeds ship with a sale price carrying fils, on every
                     * install including production, and the shop then prints a
                     * price it does not charge. WholeDirhams::toward() rounds
                     * down, which is the direction a discount off a shelf price
                     * should go — see the class header.
                     */
                    'sale_price' => $onSale ? WholeDirhams::toward((int) round($price * 0.7)) : null,
```

and, with the other imports at the top of that file:

```php
use App\Support\WholeDirhams;
```

This changes only new installs; existing shops keep what they have, and
`kbb:whole-dirhams --fix` already offers to settle those.

### `KBB-Master-Plan.md` — Phase 13

Anchor:

```markdown
- [ ] Count-based verification after each bucket
```

Replacement:

```markdown
- [x] **Count-based verification after each bucket** — every figure in the report
  was the importer describing its own work, so a row read and then neither
  written nor refused incremented nothing and was invisible. Two checks now run
  after every bucket that do not take the importer's word for it: rows read
  against rows accounted for, with any unaccounted row **named by line and id**,
  and one COUNT per bucket against the table it writes to. An unaccounted row
  fails the command even with nothing refused. Rehearsed at 671/4,159/3,712 on
  MySQL — see `docs/FV-IMPORT-AT-VOLUME.md`
```

Anchor:

```markdown
- [ ] Products **671** · Orders **4,159** · Customers **3,712**
```

Replacement:

```markdown
- [x] Products **671** · Orders **4,159** · Customers **3,712** — run end to end
  on MySQL: 49 s, 34 MB peak, 107,258 queries. Run twice, second pass `created 0,
  updated 0` and a byte-identical database. Killed with SIGKILL mid-orders and
  resumed: no duplicates, no orphaned line items, and the 669 imported products
  identical to the uninterrupted run. From Store → Import it is **62 browser
  steps, slowest 2.2 s** — or 229 steps, slowest 0.67 s, at a step size of 100.
  ▲ `kbb:import --limit` across a whole export imports orders before their
  customers and loses them to guest collisions; the screen cannot.
```

---

## 13. What the owner still has to decide

Unchanged from the runbook's §6 (D2, D4, D6 are still open), plus what this
rehearsal adds:

1. **The discard list in §10.** Phase 13 says he approves it. The two entries
   that are real content rather than plumbing are the coupon `usage_limit_per_user`
   and `used_by`, and the ignored-column list — which on this export named
   `meta:_delivery_instructions`, `refund_amount` and `order_notes`.
2. **The empty tables in §11.** `refunds` and `product_variants` are the two that
   cost money. Deciding to do without them is a decision; not noticing them is
   not.
3. **The seven fixed-amount coupons carrying fils.** They give away more than the
   screen shows, on every order they are used on, until `kbb:whole-dirhams --fix`
   settles them.

---

## 14. Reproducing this

```bash
# the export, at the real volume, deterministic
php tools/woo-volume-fixture/generate.php storage/app/woo-vol

# smaller, same shapes, same defects — what the suite runs
php tools/woo-volume-fixture/generate.php /tmp/woo-small \
    --products=40 --orders=120 --customers=90

# against MySQL, not SQLite
php artisan migrate:fresh --force
php artisan kbb:import --dir=storage/app/woo-vol --adopt-by-slug \
    --rejects=storage/app/rejects.csv --changes=storage/app/changes.csv

# and again: every bucket must report unchanged
php artisan kbb:import --dir=storage/app/woo-vol --adopt-by-slug
```

```bash
vendor/bin/pest tests/Feature/ImportAtVolumeTest.php
vendor/bin/pest tests/Feature/ImportCountVerificationTest.php
KBB_TEST_DB=kbb_fv vendor/bin/pest -c phpunit-mysql.xml
```
