# Running the WooCommerce import

`docs/IMPORT-READINESS.md` is the schema audit — what the database will accept
and what it will refuse. This is the operating manual for the importer that was
built against it: what to export, what to run, what it will refuse and why, and
what is still the owner's decision.

The importer is `kbb:import`. There is no admin screen for it, deliberately: the
import runs against a restored copy of the live MySQL, takes as long as it takes,
and is re-run until a mapping is right. A later lane can put a screen on top of
it.

---

## 1. The short version

```bash
# 1. Look before you write. This does the whole import and rolls it back.
#    --changes= is the discard list Phase 13 says you have to approve.
php artisan kbb:import --dir=storage/app/woo --dry-run \
    --rejects=storage/app/rejects.csv --changes=storage/app/changes.csv

# 2. Read BOTH files. rejects.csv is what will not be in the database; fix the
#    export and repeat step 1. changes.csv is what goes in altered and what is
#    dropped on the way — nothing there stops the import, and all of it is
#    yours to approve before it happens.

# 3. Do it for real.
php artisan kbb:import --dir=storage/app/woo

# 4. If it was killed, run the exact same command again. It continues.
php artisan kbb:import --dir=storage/app/woo

# 5. Prove it worked: run it once more. Everything should say "unchanged".
php artisan kbb:import --dir=storage/app/woo

# 6. The old addresses Google still holds. Look first; --write when it reads right.
php artisan kbb:import-redirects --csv=storage/app/url-map.csv
php artisan kbb:import-redirects --write

# 7. The pictures. Read-only, and there is no --write to forget.
php artisan kbb:import-media --csv=storage/app/images.csv
```

Exit status is 0 only when nothing was refused, so it can be used in a script.

**Steps 6 and 7 are not optional and they are not part of step 3.** The row
import can finish perfectly with every category unreachable at the address
Google has and every product photo still being served by the old shop. Neither
shows up in a row count. See §10.

---

## 2. What it imports

Eleven entities, in this order, because it is a dependency graph and not a
preference — products reference categories and brands, coupons name the
products they are restricted to, orders reference customers and name a coupon
code, order lines reference both orders and products, a refund and an order
note each attach to an order, and a review names both its product and its
reviewer.

| Entity | File | Matched on | Notes |
|---|---|---|---|
| `categories` | `categories.csv` | `source_term_id` | Also computes the cached `depth` and `path`. Production nests four deep and the URL path is cached, not derived. |
| `brands` | `brands.csv` | `source_term_id` | Production keeps brands as the `pa_brands` attribute; export those terms. |
| `products` | `products.csv` | `wc_id` | Writes `products.category_id` (primary) and the `category_product` pivot. |
| `coupons` | `coupons.csv` | `wc_id` | After products, because a restriction list names products and categories by WooCommerce id and has to be translated through them. Before orders, because an order names its code. |
| `customers` | `customers.csv` | `wp_user_id` | Plus their billing/shipping addresses, from columns on the same row. |
| `orders` | `orders.csv` | `wc_order_id` | Plus the order's own address snapshot and its `addresses` rows. |
| `order-items` | `order_items.csv` | `wc_item_id` | Matched on the WooCommerce `order_item_id`. |
| `refunds` | `refunds.csv` | `wc_refund_id` | Money WooCommerce gave back. After orders; `refunds.order_id` is NOT NULL. Imported rows carry `provider = woocommerce` and no provider reference or idempotency key — they are a record of money that has already moved, not a call this shop can make. They land `succeeded`, so they count in `PaymentRefunder::COUNTED` and net out of every revenue figure and out of the Refund button's ceiling. **Until these are imported, a partially refunded order reads as full revenue and offers its whole total as refundable.** See `docs/GI-REFUNDS-AND-NOTES.md`. |
| `order-notes` | `order_notes.csv` | `source_comment_id` | The history of what was said and done on an order, shown beside the notes this shop writes itself. `is_customer_note` defaults to internal, which is the safe direction on a column whose other value is "show this to the buyer". |
| `reviews` | `reviews.csv` | `source` + `source_id` | WordPress comments with `comment_type = 'review'`. After products (each names its product by post id) and after customers (a review carries the reviewer's WP user id). Recomputes `products.rating` and `products.review_count` at the end of the entity. |
| `posts` | `posts.csv` | `source_post_id` | The Journal. **Last, and that is not a dependency** — an article references nothing else this import writes. Only `type = post` is imported: the export carries every WordPress post type in this one file, and a page or anything else is refused by name and listed in the discard list. **An article whose slug is a first path segment the storefront already owns — `about`, `wishlist`, `feed` — is refused**, because `/{slug}/` is the shop's page and never the article; the row would be unreachable. A slug of the wrong shape (capitals, underscores, a percent-encoded non-Latin title) is normalised instead, and reported as an adjustment. Lane GJ. |

A file that is not there is skipped without complaint — a delta pass that only
carries new orders is a normal thing to run.

### `--only` does not reorder, and running orders first has a cost

`--only` filters that list; it never reorders it, so a single invocation always
runs customers before orders. **Running `--only=orders` on its own, before the
customers have been imported, is the one sequence that leaves a mess**, and it
is worth knowing why rather than discovering it:

an order naming `customer_user 412` cannot find that customer, falls back to
linking by billing email, and — under the default `--guests=synthesise` —
creates a customer row for that address with `wp_user_id` NULL. When
`customers.csv` is imported afterwards, the genuine user 412 arrives carrying
the same email, finds it already held by a row with no WordPress id, and is
**refused as an email collision**. Nothing is corrupted and the report says
exactly what happened, but the fix is to re-run with `--restart` after deleting
those synthetic rows.

Run everything in one invocation, or run `--only=customers` before
`--only=orders`.

**Not imported by `kbb:import`:** product variants, attributes, tags, refunds,
order notes, posts, pages, menus. The external-id columns exist for all
of them (see IMPORT-READINESS §4) and the `EntityImporter` base class is what a
new one plugs into.

**Covered, but not by this command — do not read the list above as "missing":**

- **Redirects** — `kbb:import-redirects`, §10. It derives the map from the rows
  this command imported, so it has to run after, not as a seventh entity.
- **Media** — `kbb:import-media`, §10. Image *paths* are imported here, on the
  product, brand and category rows. Whether the file those paths name exists is
  a separate, read-only question.
- **Reviews, the other door.** `kbb:import` now carries `reviews.csv` as an
  entity of its own (above), and Store → Reviews → Import still exists, backed
  by `App\Services\Reviews\ReviewCsvImport`. **They are two doors into one
  table and they agree**, which is deliberate rather than lucky: both key on
  `source` + `source_id`, both fold `comment_approved` through
  `App\Support\ReviewStatus`, and both put the aggregates back with the same
  `App\Support\ProductRating::refresh()` the moderation screen calls. So a
  file imported through one and then the other updates its rows instead of
  doubling them.

  Which to use: the admin screen for a reviews-only file the owner has in his
  hand, `kbb:import` for the migration, where the reviews arrive in the same
  export as everything else and need the products to be there first.

### Column names

There is no single "WooCommerce CSV" — Woo's own exporter writes `Regular
price`, WebToffee writes `order_number`, a hand-rolled `SELECT` writes
`post_status`. Headers are folded to lowercase with runs of punctuation and
spaces collapsed to `_`, and every field accepts several spellings, so all of
those arrive at the same place. The canonical names are the first alias listed
in each importer under `app/Services/Import/Entities/`.

The only columns with no alternative are the external ids — `term_id`, `id`,
`user_id`, `order_id`, `item_id` — because a row without one cannot be matched
on a re-run, and the next pass would insert a second copy of it.

---

## 3. What it refuses, and why each refusal is better than a guess

Every refusal is printed with its line number, its id, and a sentence saying
what to do; `--rejects=<file>` writes all of them to a CSV. Nothing is ever
skipped silently — a silent skip is the failure mode that makes an import
untrustworthy, because the totals come out plausible and nobody finds the
missing orders until a customer asks where theirs went.

| Refusal | Why not just accept it |
|---|---|
| `12.345` in a money column | A third decimal is finer than fils. Rounding it silently is an adjustment applied to every row like it, and the owner could never reconcile the result against WooCommerce. |
| `99,50` or `1,2345.6` | A lone comma is ambiguous: a European export means a decimal comma, an English one means thousands. The two readings differ by a factor of a thousand. Re-export with a decimal point. |
| A value past AED 21,474,836.47 | Every money column is a signed 32-bit `integer`. MySQL in strict mode errors; SQLite stores it and the parity breaks in production. |
| `not-a-date`, `2019-03-04 garbage`, `04/03/2019` | `Carbon::parse()` would accept most of these and invent a value. `04/03/2019` is a real date under two different readings. |
| An order with no `date_created` | It is the column Store → Customers reads as "last order". Importing it without one would make that customer look like they bought something today. |
| A product with `status: trash` | It is in the WordPress trash. Importing it makes it a live row. |
| A product status this schema has no concept of | Guessing wrong in the unsafe direction publishes something the owner had unpublished. |
| `sale_price` with no `regular_price` | The storefront prices from the regular price; there is no way to express a sale from nothing. |
| A three-letter country code | `addresses.country` is `varchar(2)` on a Phase 0 server, so `ARE` is silently truncated to `AR` — Argentina — and the shipping zones, which match on alpha-2, stop matching. Only the address is refused; the customer or order that carried it is still imported. |
| Two WordPress users sharing an email | `customers.email` is `UNIQUE NOT NULL` and `wp_user_id` is unique too, so they cannot both exist and cannot be merged without discarding a WordPress identity. **Decision D2 — see §6.** |
| A duplicate `order_number` | The column is unique. `--order-number=id` resolves it. |
| A row with no external id | It could not be matched on a re-run, so the next pass would insert a second copy of it. |
| A misaligned CSV row | Usually an unescaped quote earlier in the file that swallowed a line break. Padding it quietly is how a phone number ends up in the country column. |

### What it does NOT refuse

These change what the data means and are reported as notes with a count, not
refused:

- **An order status this application has never defined.** `orders.status` is
  free-form on purpose — production carries `shipped` and `tamara-p-failed` —
  so it is kept verbatim. The note says it is not in `Order::REAL_STATUSES` and
  so will not be counted as revenue.
- **An order with no email address.** It gets
  `wc-order-<wc_order_id>@import.invalid`. RFC 2606 reserves `.invalid`, so no
  receipt re-send, newsletter or abandoned-cart sequence can ever reach a real
  person. It is deterministic, so a re-run finds the same row.
- **A line item whose product is gone.** `order_items.product_id` is a nullable
  FK with `nullOnDelete` precisely because a five-year-old order references
  products that no longer exist. The line is still real money.
- **An order naming a WordPress user who is not in the export.** Linked by
  email instead, or left unlinked. Refusing it would remove real money from the
  store's revenue.
- **A category whose parent is not in the export.** Imported at the root.

### What it changes on the way in, and what it drops

Refused rows are not in the database and are listed in full. These are the other
two cases: a row that IS imported and is not what the export said, and something
in the export that is not imported at all. Both are in the `ADJUSTED` and
`DISCARDED` columns of the report, grouped by kind with a full count and a few
worked examples each, and `--changes=<file>` writes them to a CSV.

Phase 13's own line is *"three-bucket classification: migrate / discard / ask —
Rafi approves any discard list"*. This is that list. Every one of these was
found by running the importer at the shop's real volume and reading what came
back; before, each one reported as `created` and was indistinguishable from a
row that arrived intact.

| What | Bucket | Why it is not simply refused |
|---|---|---|
| Two products sharing one SKU | adjusted | `products.sku` has no unique index, so both import — and only one is findable by the handle the warehouse and the Meta feed use. Reported once per SKU. |
| A product with no SKU | adjusted | It is in the shop with no warehouse handle at all. |
| A price or an order amount carrying fils | adjusted | The shop prints whole dirhams (`Money::displayDecimals()` is 0), so AED 99.50 is charged exactly and **printed as AED 100**. Rounding it on the way in would be a silent edit to the owner's prices. |
| An order in a currency that is not the store's | adjusted | Every revenue figure is a `SUM` with no currency in the `GROUP BY`, so USD 100 is added to AED revenue at face value. The importer has no rate for the day and will not invent one. |
| A slug invented from the product name | adjusted | Only happens when the export carries **no slug column**. `Str::slug()` transliterates: an Arabic name becomes a romanised address and the CJK is dropped. The old `/product/<slug>/` 404s unless a redirect is written. Each one is named with its before and after so you can write it. |
| A refund line's negative quantity | adjusted | `order_items.quantity` is unsigned, so `-1` is clamped to `0` while the money stays at `-50`: the two no longer agree. Every refunded order has one. |
| A unit price that does not divide evenly | adjusted | Three for AED 100 imports as 33.33 each, which multiplies back to 99.99. `subtotal` and `total` stay exact; the order page prints `unit_price`. |
| HTML the allowlist removed | **discarded** | The `<script>` has to go. What the owner needs to know is that the description is no longer byte-for-byte what WooCommerce held, and by how much. |
| Columns nothing reads | **discarded** | One entry per entity, naming every column and the first real value found in it. This is where `meta:_delivery_instructions = Ring the bell twice` and `order_notes` and `refund_amount` show up — real content nobody had a way to notice losing. |
| Files nothing opens | **discarded** | One entry per file in the export folder that no entity opens — today `posts.csv` and whatever else the next export carries — with its row count. Suppressed under `--only`, where ignoring a file is the point. `coupons.csv` and `reviews.csv` were once the two worst entries on this list; `variations.csv`, `attributes.csv` and `tags.csv` came off it with Lane GH and `refunds.csv` and `order_notes.csv` with Lane GI, all in one round. The channel is not shrinking towards empty — it stays for whatever the next export carries, which is the whole reason it was never about two filenames. |
| A coupon amount carrying fils | adjusted | The owner prices in whole dirhams (`App\Support\WholeDirhams`), and **nothing in WooCommerce ever enforced that**. Imported exactly — a 40,000-row migration must not die on a rounding policy — and named so `kbb:whole-dirhams` can settle it. Worse than the price case above: `CouponService::discountFor()` rounds a discount **up**, so a `fixed_cart` coupon imported at AED 99.50 hands back AED 100.00 on every order while Store → Coupons shows 99.50. |
| A coupon expiry with no time on it | adjusted | WooCommerce reads `date_expires` as end-of-day-inclusive; this schema checks `now() > expires_at`. Imported bare, the code would die a day early and silently, so a date with no time is moved to 23:59:59. |
| A coupon's `usage_limit_per_user` | **discarded** | Enforced here by counting `coupon_redemptions` rows, and an imported coupon has none. There is no column that could hold "who has already used this", so a shopper who spent a one-per-customer code in WooCommerce can spend it again here. Named per coupon with the limit's real value, because only the owner can decide whether to shorten the code's life instead. |
| A coupon's `used_by` | **discarded** | Woo's list of who redeemed the code. It carries no order, so every row synthesised from it would be a redemption with `order_id` NULL that Store → Coupons would then report as usage history. |
| A review whose product is gone | rejected | Published with no product it becomes a review *of the shop*, sitting in the moderation queue with nothing to attach it to. |
| A review with no author | adjusted | Imported as **Anonymous**. The review is the rating and the words; a blank name is a visibly broken card, and dropping the row would move the product's star average. |
| A `comment_approved` with no equivalent | rejected | `1`, `0`, `spam` and `trash` all map (trash folds onto `spam`, and is reported). Anything else is refused rather than guessed onto `pending`, because guessing publishes — or hides — a real customer's words. |

Two of those remain gaps rather than behaviour, and they are the owner's to
decide:

- **The per-customer coupon limit does not survive the migration**, and cannot:
  it is a fact about the two data models, not a mapping that was skipped. Every
  imported code starts every shopper again from zero uses.
- **The demo catalogue survives the import.** `seed_demo_catalogue` leaves 24
  products, 8 brands and 6 categories with a NULL external id, and the import
  does not touch them. Measured on a full-volume run: 703 products in the
  database for 679 imported. `--adopt-by-slug` claims the ones whose slugs
  collide; the rest have to be deleted deliberately.

---

## 4. Resuming

The host is shared hosting with no shell access, so the import runs from a
process nobody can watch and nobody can signal. It will be killed. The question
is not whether but what is true afterwards.

Progress lives in `import_checkpoints`, and **each batch's rows and the advance
of its checkpoint commit in the same transaction**, so the recorded offset can
never describe work that was rolled back. Re-running the same command continues
from the last committed batch.

```bash
php artisan kbb:import --dir=storage/app/woo --batch=200   # smaller batches, more checkpoints
php artisan kbb:import --dir=storage/app/woo --limit=5000  # do 5,000 rows per entity and stop
```

### ▲ `--limit` on a whole export imports orders before their customers

**Do not use `--limit` across more than one entity on a live run.** It is a
budget PER ENTITY, so one invocation does 5,000 customers and then 5,000 orders
— and every order in that slice whose customer is further down `customers.csv`
does exactly what §2 warns about under "running orders first": it cannot find
its customer, links by billing email, synthesises a guest row with a NULL
`wp_user_id`, and the genuine WordPress user is then **refused as an email
collision** when they arrive.

Measured, not feared: a 140-order export run in slices of 23 refused **14 of its
80 customers** this way. Nothing is corrupted and every refusal is named, but
fourteen people are missing from the shop and the reason printed beside them
points at decision D2, which is a different problem.

The command warns when you do it. Use one of these instead:

- **Store → Import**, which steps ONE entity per request in the runner's own
  order and never starts the next until the current one is finished. It cannot
  produce this.
- `--only=customers` until it finishes, then `--only=orders`, and so on down
  `ImportRunner::entityNames()`.
- No `--limit` at all, against a restored copy where there is no timeout.

There is also no termination signal for a loop of limited runs. A finished
entity restarts from row one (rule 2 below) and, if `--limit` cuts that
re-presentation short, is left unfinished again — so "every checkpoint is
finished" is a state such a loop never reliably reaches. Nothing is lost by the
extra passes; there is simply nothing a script can wait for. The screen keeps
its own `done_entities` list and does reach `complete`.

Both of these are measured in `docs/FV-IMPORT-AT-VOLUME.md` §6.

`--limit` is a resume point, not a truncation: the entity is left unfinished, so
the next run carries on from where it stopped. That is how a very large import
gets done inside a shared host's `max_execution_time` — run it repeatedly until
it reports nothing left.

Three rules govern what happens next time:

1. **An unfinished entity resumes**, from the row after the last committed one.
2. **A finished entity starts again from row one.** It has no partial progress
   to resume, and the next run is almost always a delta from a NEW export whose
   rows all need re-presenting. Re-running costs one `updateOrCreate` per row and
   reports them as unchanged.
3. **An unfinished entity whose source file has CHANGED is refused.** Rows are
   resumed by position, so an offset only means anything against the exact file
   it was recorded for; a re-export with six new orders at the top would make
   the importer skip 18,000 rows that are no longer the ones it did. Pass
   `--restart` to import that entity from the beginning.

`--run=<key>` keeps separate checkpoints, so a trial run does not resume into
the real one.

---

## 4a. Count-based verification, after every bucket

Every other number in the report is the importer describing its own work:
`created` is incremented by the code that created the row, `rejected` by the code
that refused it. A row read from the file and then neither written nor refused
increments nothing — and is invisible in a report whose every column is
self-reported, while the totals still look plausible.

So two checks run after each bucket, and neither asks the importer how it thinks
it did:

```
Count-based verification — rows in against rows out, per bucket:
+-------------+-----------+-----------+---------+-------------+----------+
| bucket      | rows read | accounted | refused | in database | verdict  |
+-------------+-----------+-----------+---------+-------------+----------+
| products    | 671       | 669       | 2       | 669         | VERIFIED |
| customers   | 3,713     | 3,712     | 1       | 3,712       | VERIFIED |
| orders      | 4,159     | 4,159     | 0       | 4,159       | VERIFIED |
| order-items | 10,571    | 10,571    | 0       | 10,571      | VERIFIED |
| seo         | 671       | 669       | 2       | —           | no table |
+-------------+-----------+-----------+---------+-------------+----------+
```

- **rows read against rows accounted for.** Each row is watched for whether it
  moved its own entity's tally. One that moves nothing and throws nothing is
  printed with its line number and its id. That is the named discrepancy — a
  row, not a total that is one short — and it **fails the command** even when
  nothing was refused.
- **rows in the database.** One COUNT per bucket, restricted to rows carrying an
  external id, so the demo catalogue's 24 id-less products do not read as a
  surplus. Fewer rows than the file supplied is a DISCREPANCY. More is a note:
  a delta import runs against a table that already holds last week's rows.

Four things it deliberately will not claim:

- `seo` writes onto rows `products` owns, so it has no table of its own and says
  so rather than leaving the line out — a verification that is silently absent
  reads exactly like one that passed.
- A bucket that is part-way through is marked as a slice, which is every step of
  the admin screen.
- A **resumed** run reports the count without a verdict: it cannot know how many
  of the rows it did not re-read were refusals, so `read − refused` is not the
  number the table should hold.
- The customers tally includes guests synthesised by the ORDERS bucket — 4,411
  created for a 3,713-row file on the full-volume run — so it can never answer
  "customers in = customers out". The COUNT can, because it asks about
  `wp_user_id`.

The same line goes into the entity's notes, so it appears on Store → Import as
well as in the console.

## 5. Dry run

```bash
php artisan kbb:import --dir=storage/app/woo --dry-run
```

It performs the entire import inside one transaction and **always rolls it
back**. That is how it can tell the truth: an order needs its customer's primary
key to exist and a line item needs its order's, so a preview that wrote nothing
could not resolve any of them and would report cascading failures that are
artefacts of the preview rather than of the data. The answer includes every
foreign key resolved and every constraint the database itself would refuse.

Nothing survives it — checkpoints included, so a dry run never advances one.

The consequence worth knowing: for the duration of the run the rows exist inside
an uncommitted transaction. On a very large import that is a long-held
transaction. Use `--limit` to preview a slice if that matters.

---

## 6. Decisions that are still the owner's

IMPORT-READINESS lists six. Here is where each one stands.

| # | Decision | What the importer does | Flag |
|---|---|---|---|
| D1 | Order with no email | Synthesises `wc-order-<id>@import.invalid`, counts it in the report, and leaves `customer_id` NULL. | none — it is the only option that satisfies a NOT NULL column without inventing a person |
| D2 | Two users sharing an email | **Refuses the second one, by name, with both ids.** Merging is lossy; an importer that merges two people's accounts on its own initiative is not a thing to build. | **still needs the owner** |
| D3 | Guest orders | Synthesises a customer row per distinct guest email and links the order — what this application's own checkout already does. | `--guests=unlinked` for the older shape |
| D4 | WordPress admin users | **Not imported.** `users` has no `legacy_password` column, so every imported admin would need a reset anyway. | **still needs the owner** — say whether staff accounts come across at all |
| D5 | `orders.order_number` | The Woo order number where there is one, falling back to the post id. | `--order-number=id` to use the post id always |
| D6 | Historical invoice numbers | Imported when the export carries `invoice_number`, and a duplicate is refused. | **still needs the owner** — importing some but not all leaves a gap the WebToffee sequence may collide with |

And one the audit did not anticipate:

| | Decision | What the importer does | Flag |
|---|---|---|---|
| D7 | **The demo catalogue holds real slugs.** `2026_08_27_100000_seed_demo_catalogue` runs on every install, production included, and seeds brands slugged `cosrx` and `beauty-of-joseon` and categories slugged `cleansers`, `toners`, `serums` — all real things this store sells. `brands.slug` is unique, so the genuine terms collide with the placeholders. | **Refuses**, naming the flag. | `--adopt-by-slug` claims the placeholder row by writing the WooCommerce id onto it, and reports every adoption. Only ever for a holder whose external id is NULL; a slug held by a different Woo term is refused regardless. |

---

## 7. Timezone

```bash
php artisan kbb:import --dir=... --timezone=Asia/Dubai   # the default
```

WooCommerce writes `post_date` in the **site's** timezone and `post_date_gmt` in
UTC, and the common CSV exporters emit the local one. This schema stores UTC.
Reading a local timestamp as UTC shifts every order in the store by the offset —
four hours for Asia/Dubai — which moves orders placed after 20:00 onto the next
day and makes every daily revenue figure disagree with WooCommerce's own reports
by a sliver nobody can account for.

A timestamp that carries an explicit offset or a `Z` is honoured as written and
not re-interpreted, so an export of `post_date_gmt` is safe whatever
`--timezone` says.

### The importer checks you, where it can

`--timezone` was the one assumption in this importer that nothing verified: get
it wrong and every order shifts by four hours, the rows stay perfectly
well-formed, and the only symptom is that daily revenue disagrees with
WooCommerce by a sliver nobody can account for. This shop has already paid for
that bug once from the other direction — see *The shop's own clock* in the
master plan.

WooCommerce writes **both** columns, and its exporters emit both as
`date_created` and `date_created_gmt`. Where a row carries both, the difference
between them *is* the site's offset at that instant, measured rather than
declared. If reading the local column under `--timezone` does not land on the
GMT column, the report says so with a count:

```
orders — would be imported CHANGED:
  4,159x  the export's own GMT column disagrees with --timezone=UTC — every date in
          this file is being read in the wrong zone, which shifts the whole store's
          order history and every daily revenue figure derived from it.
            date_created: 2023-05-05 21:40:00Z (reading it as UTC)
                       -> 2023-05-05 17:40:00Z (what the export's GMT column says)
```

It is reported rather than fatal because the import is still recoverable if you
know: re-run with the right zone and every row reports as `updated`. What is not
recoverable is not being told.

**Where the export carries only the local column, this check cannot be made and
is silent.** That is a run whose timezone is unverified, not a run whose
timezone is confirmed. Re-export with the GMT column if you want the check.

---

## 7a. It has been run at the real volume

671 products, 4,159 orders, 3,712 customers, on MySQL, twice over and killed in
the middle. 49 seconds, 34 MB peak, 107,258 queries; the second pass reported
`created 0, updated 0` and a byte-identical database; the killed-and-resumed
database matched the uninterrupted one row for row. From Store → Import it is
**62 browser steps, the slowest 2.2 seconds** — or 229 steps at 0.67 seconds
each with a smaller slice.

The numbers, the method, the discard list at that volume, and what a real export
still carries that nothing here reads are in `docs/FV-IMPORT-AT-VOLUME.md`. The
export that produced them is `tools/woo-volume-fixture/generate.php`, which is
deterministic and takes the three numbers as flags.

## 8. Run it against MySQL, not SQLite

IMPORT-READINESS §5 rule 8, and it is not a formality. Three of the failures the
importer guards against cannot occur on SQLite and cannot be avoided on MySQL:

- **The case-insensitive email collision.** MySQL's default
  `utf8mb4_..._ci` collation makes `A@x.com` and `a@x.com` collide on
  `customers.email`; SQLite's BINARY collation does not. The importer lowercases
  on the way in and does the collision check in its own bookkeeping, so it
  behaves identically on both — but only the importer does. Anything else
  touching that column does not.
- **The missing unique indexes.** The repair migrations may have left
  `orders.wc_order_id` and `customers.wp_user_id` on the live server as ordinary
  nullable columns. `2026_09_22_000000_add_import_external_ids` adds them; run it
  and read its output before importing. If it says `SKIPPED ... already holds
  duplicate`, de-duplicate and re-run it, because until then the constraint the
  whole import rests on is not there.
- **The `varchar(2)` country truncation.**

The suite runs on both:

```bash
vendor/bin/pest --compact
vendor/bin/pest -c phpunit-mysql.xml --compact
```

---

## 9. Adding a second source

`app/Services/Import/Sources/RowSource.php` is an interface with one
implementation, and the second one is the point. WooCommerce can be read as a
CSV export — which the owner can produce from wp-admin with no credentials — or
through the REST API, which needs a consumer key and a site that stays up for
the length of the run.

All the mapping, validation and refusal logic lives in the entity importers,
which see only `array<string, string>` rows and do not know where those came
from. A REST source is therefore a new class implementing `RowSource` that
flattens the API's nested JSON into the same flat keys, plus a `fingerprint()`
that is stable for the same data, and **no change at all** to the mapping.

The one thing a new source must get right is `fingerprint()`. It is what makes
resume safe: a checkpoint saying "18,000 rows done" is a lie the moment the
source behind it changes.

---

## 9b. Producing the export (WordPress side)

Upload `wordpress-plugin/kbb-exporter` as a zip through Plugins -> Add New on
kbeautybliss.com, then Tools -> KBB Export. It says which order storage the shop
uses before it starts, writes one batch per request so a shared host cannot time
it out, and resumes from the last completed batch if a request dies.

It writes into `wp-content/uploads/kbb-export/<export id>/`. Download the folder
over FTP and **delete it from the server**: `customers.csv` holds every
shopper's address and password hash, and `reviews.csv` holds reviewers' email
addresses and the IPs they posted from.

The screen offers the export in eight ticked groups rather than all at once, so
a second pass can carry only this month's orders. The order the new shop needs
them in is the order they are listed on the screen, and it matters: importing
orders before the customers they belong to costs customer rows. The screen will
not let you start a selection that does that until you have either added the
missing group or confirmed it is already imported here, and either way it writes
what you chose into `manifest.json`. `docs/GK-EXPORT-GROUPS.md` has the reasoning
and what each choice costs.

`manifest.json` is written LAST, so a folder without one is an export that did
not finish. Read `source.timezone` out of it and pass it as `--timezone`;
`App\Services\Import\DateParser` refuses to default it and reading Dubai
timestamps as UTC shifts the whole order history by four hours.

The full column derivation, the round-trip evidence and what still needs one
real run are in `docs/GE-WP-EXPORTER.md`.

## 10. After the rows: URLs and pictures

Two things the row import cannot tell you about, because both are invisible to
a count of rows written.

### `kbb:import-redirects` — the addresses Google already has

WooCommerce commonly publishes a category at its leaf slug,
`/product-category/serums/`. This shop's URL contract U-03 is the full nested
path, `/product-category/skincare/treatments/serums/`. Every indexed flat URL
therefore 404s after the import. This is not hypothetical: fourteen menu links
carried exactly that shape and 404'd until two packages ago, and those were
only the ones somebody was looking at.

```bash
php artisan kbb:import-redirects                              # look
php artisan kbb:import-redirects --csv=storage/app/url-map.csv # look, in a spreadsheet
php artisan kbb:import-redirects --write                       # apply
php artisan kbb:import-redirects --rollback                    # undo the above
```

It writes nothing without `--write`. Every row lands in one of Phase 13's three
buckets:

| Bucket | Means |
|---|---|
| `migrate` | a redirect that will be written |
| `discard` | nothing to do — the address did not move |
| `ask` | the owner has to decide; never written |

**If the export can give you real permalinks, use them.** A CSV of
`type,wc_id,permalink` passed as `--permalinks=` replaces guesswork with what
the old site really served, and wins over the derived rule wherever the two
disagree.

**What it will not do:** guess a brand-archive base (U-05 says this shop has no
brand archive at all, and what the old one used depends on which plugin it
ran), or redirect `/?p=123` — `CheckRedirects::findMatch()` matches on
`getPathInfo()`, which excludes the query string, so such a row would be stored
and never match.

**It is reversible**, which the row import is not. `--rollback` removes only
rows whose source is in the current map, whose target is still the one this
command writes, and which are flagged `auto_created`. An admin's own row, or
one an admin has since re-pointed, is kept and named.

### `kbb:import-media` — do the pictures exist?

The importer copies image paths as strings. It does not fetch anything and does
not check that what was named is there — which is the right call for the import
(a 404ing photo is no reason to refuse an order, and the uploads folder is
usually copied across separately and afterwards), but it means a clean import
report is not evidence that a single picture will load.

```bash
php artisan kbb:import-media
php artisan kbb:import-media --csv=storage/app/images.csv
php artisan kbb:import-media --verdict=remote
```

Read-only, always. Three verdicts:

| Verdict | Means |
|---|---|
| `present` | the file is where the row says it is |
| `missing` | a local path naming nothing — expected before the uploads folder is copied, alarming after |
| `remote` | **still served by the old shop** |

**`remote` is the number to watch, not `missing`.** A missing image is visibly
broken and somebody reports it. A remote one renders perfectly for as long as
WooCommerce is still up, and every one of them breaks on the day the old site
is switched off — which is usually the day after the migration is declared
finished.

Exit status is non-zero while anything is `missing` or `remote`, so it can be
used in a script.
