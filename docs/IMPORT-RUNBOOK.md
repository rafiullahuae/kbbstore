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
php artisan kbb:import --dir=storage/app/woo --dry-run --rejects=storage/app/rejects.csv

# 2. Read storage/app/rejects.csv. Fix the export. Repeat step 1.

# 3. Do it for real.
php artisan kbb:import --dir=storage/app/woo

# 4. If it was killed, run the exact same command again. It continues.
php artisan kbb:import --dir=storage/app/woo

# 5. Prove it worked: run it once more. Everything should say "unchanged".
php artisan kbb:import --dir=storage/app/woo
```

Exit status is 0 only when nothing was refused, so it can be used in a script.

---

## 2. What it imports

Six entities, in this order, because it is a dependency graph and not a
preference — products reference categories and brands, orders reference
customers, order lines reference both orders and products.

| Entity | File | Matched on | Notes |
|---|---|---|---|
| `categories` | `categories.csv` | `source_term_id` | Also computes the cached `depth` and `path`. Production nests four deep and the URL path is cached, not derived. |
| `brands` | `brands.csv` | `source_term_id` | Production keeps brands as the `pa_brands` attribute; export those terms. |
| `products` | `products.csv` | `wc_id` | Writes `products.category_id` (primary) and the `category_product` pivot. |
| `customers` | `customers.csv` | `wp_user_id` | Plus their billing/shipping addresses, from columns on the same row. |
| `orders` | `orders.csv` | `wc_order_id` | Plus the order's own address snapshot and its `addresses` rows. |
| `order-items` | `order_items.csv` | `wc_item_id` | Matched on the WooCommerce `order_item_id`. |

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

**Not imported by this lane:** product variants, attributes, tags, reviews,
coupons, refunds, order notes, media, posts, pages, menus, redirects. The
external-id columns exist for all of them (see IMPORT-READINESS §4) and the
`EntityImporter` base class is what a new one plugs into.

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

---

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
