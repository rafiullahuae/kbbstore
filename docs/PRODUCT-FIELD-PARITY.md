# The WooCommerce product round-trip, field by field

Lane PX. Written against the owner's own product edit page — *Medicube – Kojic
Acid Glow Full Routine Set* — and his instruction:

> "check everything on edit page and match with ours. because we will export the
> products from wordpress site and import into ours. also check and update our
> import/export module if needed. don't assume anything. everything must be
> compatible without anything skipping or losing."

**Where it sits in the admin.** `Store → Store Import / Export`, and the live
page at `Store → Store Import / Export → Import progress`. The product screens
themselves are behind `Catalog → Product editor`.

---

## The one-paragraph answer

`products.csv` now carries **46 columns**. Twenty-four of them are written onto
`products`, one (`tag_term_ids`) is redundant because the same pivot arrives from
`tags.csv`, and **twenty-one reach no column at all**.

Eighteen of those twenty-one were already not crossing before this lane and were
already in the export; the other **three were on his edit page and in no file at
all**, so nothing could have reported them. What changed is that all twenty-one
are now **named at run time, one by one, with the value each one held and a count
of the products it happened on**, and that the reconciliation at the end of an
import counts fields as well as rows.

The third of those three — `default_attributes`, "Default Form Values" on the
Variations tab — hid behind something worse than an absence. `_default_attributes`
was **already in the exporter's `META_KEYS`**: queried on every batch and emitted
by no column. So the one list this project offers as its account of what it reads
out of WooCommerce named a field nothing carried, and a reader checking coverage
against that list would have concluded it crossed.

**This lane carried nothing new and added no column.** Which of the twenty-one
earn a column is the owner's decision, and it is put to him at the bottom of this
document. Reporting them costs nothing and is worth having whichever way he
decides.

---

## The table

`->` lands in the database. **NAMED** reaches no column and is reported by name,
with its value, in the import's discard channel. **CARRIED** is read, and the
fact it holds arrives under a sibling file. The same classifications are
machine-checked in `tests/Feature/GqMigrationCensusTest.php`, which fails if the
code and this table drift apart.

### Carried — 24 columns

| # | Exporter column | Lands in | Notes |
|---|---|---|---|
| 1 | `id` | `products.wc_id` | The identity. Also a URL contract: `?add-to-cart={id}` links are live in the wild |
| 2 | `name` | `products.name` | |
| 3 | `slug` | `products.slug` | `post_name` verbatim, never regenerated, so `/product/{slug}/` does not move |
| 4 | `sku` | `products.sku` | Not unique; a duplicate or missing SKU is reported, not refused |
| 5 | `status` | `products.status` | Mapped explicitly; `trash` is refused rather than published |
| 6 | `type` | `products.type` | `simple`/`variable` known; anything else stored verbatim |
| 7 | `regular_price` | `products.price` | Integer fils, parsed digit by digit — never a float |
| 8 | `sale_price` | `products.sale_price` | A sale price with no regular price is refused |
| 9 | `sale_starts_at` | `products.sale_starts_at` | |
| 10 | `sale_ends_at` | `products.sale_ends_at` | |
| 11 | `stock_status` | `products.stock_status` | |
| 12 | `stock` | `products.stock` | Present-and-empty is NULL, not 0 |
| 13 | `manage_stock` | `products.manage_stock` | |
| 14 | `featured` | `products.featured` | From the `product_visibility` taxonomy |
| 15 | `is_visible` | `products.is_visible` | The yes/no half of Woo's four states — see `product_visibility` below |
| 16 | `brand_term_id` | `products.brand_id` | |
| 17 | `category_term_ids` | `products.category_id` **+** `category_product` | Full set to the pivot; the **first** becomes the primary, and the exporter sorts the Yoast primary first |
| 18 | `position` | `products.position` | |
| 19 | `date_created` | `products.created_at` | Preserved, so the "newest" sort survives cutover |
| 20 | `image` | `products.image` | |
| 21 | `images` | `products.images` | Separator chosen by looking for `\|`, else comma — Woo's own exporter writes commas |
| 22 | `short_description` | `products.short_description` | Through the `RichText` allowlist; what it removed is reported |
| 23 | `description` | `products.description` | Same |
| 24 | `total_sales` | `products.total_sales` | |

### Carried from elsewhere — 1 column

| # | Exporter column | Verdict | Why it is not a loss |
|---|---|---|---|
| 25 | `tag_term_ids` | **CARRIED** | `TagImporter` writes `product_tag` from the **tag** side out of `tags.csv` `product_ids`, so the membership arrives in full. Read by `ProductImporter` only so that the discard list stops naming it beside fields that really do go nowhere. **The premise is checked:** an export with no `tags.csv` in it has nothing to carry the membership, and there this column is reported as a genuine loss rather than reassured about |

### Reaching no column — 21 columns, each NAMED at run time

| # | Exporter column | On his edit page | Why it does not cross |
|---|---|---|---|
| 26 | `weight` | Shipping | No weight column. **Nothing can price a parcel by weight** |
| 27 | `length` | Shipping → Dimensions | No parcel dimensions |
| 28 | `width` | Shipping → Dimensions | No parcel dimensions |
| 29 | `height` | Shipping → Dimensions | No parcel dimensions |
| 30 | `shipping_class` | Shipping | No per-product shipping class; shipping is decided per zone |
| 31 | `tax_status` | General | Tax is not decided per product here |
| 32 | `tax_class` | General | No per-product tax class to select into — `tax_rates` has no class column |
| 33 | `virtual` | Beside "Simple product" | Every product is treated as a physical good |
| 34 | `downloadable` | Beside "Simple product" | No downloadable products, no file-permissions table |
| 35 | `backorders` | Inventory | No backorder *policy* column. `stock_status` can say `onbackorder` but not whether to allow one |
| 36 | `low_stock_amount` | Inventory | The low-stock threshold is one shop-wide setting |
| 37 | `upsell_ids` | Linked Products | No upsell pivot; related products are computed from the category |
| 38 | `cross_sell_ids` | Linked Products | No cross-sell pivot |
| 39 | `grouped_ids` | Linked Products | Grouped products are not a type this shop sells |
| 40 | `purchase_note` | Advanced | No per-product note on the order-received page or the confirmation email |
| 41 | `product_visibility` | The Catalog visibility box | Woo's four states fold into `is_visible` + `featured`, so `search` and `hidden` both arrive as not-visible and the difference between them is gone |
| 42 | `attribute_summary` | Attributes (custom ones) | **The custom, non-taxonomy attributes.** `attributes.csv` carries the `pa_*` taxonomy ones and only those, so nothing else in the import covers these |
| 43 | `date_modified` | — | This shop stamps its own `updated_at` |
| 44 | `sold_individually` | Inventory → "Limit purchases to 1 item per order" | **New to the export in this lane.** No one-per-order cap exists on a product here |
| 45 | `reviews_enabled` | Advanced → "Enable reviews" | **New to the export in this lane.** Reviews are not switched per product here |
| 46 | `default_attributes` | Variations → "Default Form Values" | **New to the export in this lane, and it was already being fetched.** `product_variants` has no default flag and nothing preselects a variant, so a product that opened on 50ml opens on no size here |

---

## What this lane changed

### 1. Every dropped field is named, counted, and shown with its value

`ProductImporter::NOT_CARRIED` declares all twenty with a reason each, and each
gets its **own** discard kind — its own count and its own five examples — the way
`SeoImporter` already did it for the Yoast keys in `YoastSeo::UNMAPPED`.

Before, all of them were anonymous names inside the runner's single consolidated
"no field of this importer reads these" line. That line is a good backstop and
was a poor answer, for three reasons:

* **It could not say how many.** One discard, count 1 — so `weight` dropped on
  400 products and `weight` dropped on one read identically.
* **It could not say what was in them,** beyond the first value the file held for
  each. Five samples per kind is the report's own answer to "show me one", and a
  consolidated line got one sample for nineteen columns between them.
* **It could not tell a loss from a transfer.** `tag_term_ids` sat in it beside
  `weight`. The tags arrive; the weight arrives nowhere. The owner was being
  shown a false alarm, and a discard list with false alarms in it is one nobody
  finishes reading.

A field that is empty on every row of the export is **not** counted as a loss — it
cost nothing — but it is still read, so it does not reappear in the consolidated
line.

### 2. A real defect: the consolidated list truncated itself, and ate `weight`

`EntityReport::collect()` put every value through `excerpt()`, which cuts at 600
characters and appends `...`. The consolidated column list is the one sample in
the whole report that is a **list** rather than a value, and the constant's own
docblock claimed 600 was *"long enough to hold the whole ignored-column list for
a wide WooCommerce export … the names at the end are exactly the ones nothing
else in the report mentions."*

It was long enough for the test fixture, where eleven of nineteen columns are
empty and the line is **511** characters. On one realistic product row it came
out at **exactly 600, ending in `...`** — and what fell off the end, alphabetically,
was `virtual`, `weight` and `width`.

The single most load-bearing field in the whole drop list was being dropped from
the list whose only job is to name what the migration loses, with nothing to
indicate that the missing names were the expensive ones.

A length limit cannot be the mechanism that keeps a list whole, whatever it is
set to. `EntityReport::discardedList()` keeps it whole; the length is bounded by
the width of the export, and it is recorded once per entity rather than once per
row.

### 3. Three fields were on his edit page and in no file at all

`sold_individually`, `reviews_enabled` (WordPress's `posts.comment_status`) and
`default_attributes` were not exported. That makes them a **different kind of
gap** from the other eighteen, and the worse kind: the migration census only
classifies columns the export writes, and the importer's discard list can only
name columns that arrive. A field in neither channel is lost in silence, and no
report this migration has could have found it.

Consequences on the live shop, had they gone unnoticed: a product the owner had
capped at one per order can be added to a basket ten times; a product whose
reviews he had switched **off** arrives with them **on**; and a variable product
that opened on a chosen size opens on none, so every visitor has to pick before
the Add to basket button means anything.

**`default_attributes` was the worst of the three to find and the cheapest to
fix, because it was already being read.** `_default_attributes` sits in the
products stage's `META_KEYS` — fetched on every batch, for every product, and
emitted by no column. The query cost was already being paid. And `META_KEYS` is
the list this plugin offers as its own account of what it reads out of
WooCommerce, so a fetch with no column does not read as an absence, it reads as
**coverage**: anyone checking whether the field crossed would have found its name
in the right place and stopped looking.

It was invisible to every test for a second reason, which is now closed. The
meta-key half of `GqMigrationCensusTest` reads `SELECT DISTINCT meta_key` out of
the WordPress harness and insists every key is classified — so a field the
harness does not model is a field that half of the census cannot ask about. The
harness had variations and no default on any of them. It has one now.

All three are now exported and all three are reported as dropped. They are
**not** carried — that is on the list below.

### 4. The reconciliation counts fields as well as rows

Per entity, in the import report:

> `671 read, 671 accounted for, 0 refused, and 21 fields skipped (columns of the
> export this shop has nowhere to put, not rows — each one is named in the
> discard list with what it held), 671 in the database.`

The field clause is **absent when nothing was skipped**, so an entity that
carries every column it was given reads exactly as it read before, byte for byte.
It is also worded so it cannot be mistaken for part of the row arithmetic:
`accounted + refused = read` is the identity the rest of the sentence rests on,
and a field count does not belong to it.

### 5. The live progress page shows both

`Store → Store Import / Export → Import progress` gained:

* a **Fields dropped** column per file, whose tooltip is the field names in full;
* a closing card, **"Did anything get lost?"**, ending in one sentence:

> WooCommerce said 671 rows across 17 files, 671 arrived, 0 refused, and 21
> fields skipped (attribute_summary, backorders, …). Every row is accounted for.

It stays a neutral note while the run is unfinished and says so — a green
"everything arrived" printed over a half-done import is the most expensive
sentence this screen could produce. It turns red if any row was read and neither
imported nor refused.

The field names live in a new nullable `import_checkpoints.dropped_fields`
column, stored as names and **unioned** across slices rather than summed: a
background import is many requests over one file, each reporting only the fields
its own rows carried, so adding the counts would report `weight` once per batch
and unioning is what keeps a field a later slice did not happen to see.

### 6. Re-running an import is still a no-op, and now it is pinned

`wc_id` is the identity, every write is an upsert on it, and every pivot is
diffed. A test imports the same export **twice** and asserts identical row and
pivot counts, zero created, zero refused, and both rows reported *unchanged*.

The refusal assertion matters more than the count one, and this lane only learned
that by mutating the code the test was supposedly about: replace the pivot diff
with a plain insert and the **counts still match**, because `category_product` is
a composite primary key and re-inserting a pair raises rather than duplicating.
The row is then refused, and a snapshot of counts is perfectly happy. A second
pass that silently refuses all 671 rows looks like a clean no-op from every
number on the page — worse than a duplicate, which is at least visible.

---

## Six things that look like gaps and are not

Checked, first-hand, and deliberately left alone:

| Concern | How it is already handled |
|---|---|
| Multiple categories | `category_term_ids` is a comma list; every one resolves to `category_product`, the first to `products.category_id` |
| The Yoast primary category | The exporter sorts on `_yoast_wpseo_primary_product_cat` so the primary lands first, which is what `category_id` takes. The Yoast key is in `YoastSeo::UNMAPPED` because it is spent on the **product** row, not the SEO row |
| Tags | `TagImporter` writes `product_tag` from the tag side |
| Taxonomy attributes (`pa_*`) | `AttributeImporter` writes `product_attribute_value` from the attribute side. **Custom attributes are a different set — see `attribute_summary`, #42** |
| Variations | `VariationImporter`, its own entity and file |
| Yoast SEO | The SEO stage exports every `_yoast_wpseo_*` key by `SELECT DISTINCT meta_key`. Five are imported and nineteen are named as skipped |

---

## Three decisions only the owner can make

Each is a real thing his old shop holds. None of them is carried today, all of
them are now *reported*, and the cost below is the honest cost of carrying them.

### 1. Shipping — `weight`, `length`, `width`, `height`, `shipping_class`

**Recommend carrying `weight` at least.** He ships real parcels across the UAE,
and losing weight is losing the input to any weight-based rate. There is no way
to recover it later except by re-exporting from a WooCommerce site that may be
gone.

*Cost:* one migration adding five columns; `ProductImporter` writes them; the
product editor gets a Shipping section if he wants to edit them; nothing on the
storefront changes. **Roughly one lane, and it is the one with a deadline** —
every other decision here can be made after cutover, and this one cannot be
made after the old site is switched off.

*Public API:* none of the five should go into `Product::toApi()`. `/api/*` is
unauthenticated, and parcel dimensions are supplier information, not shopper
information.

### 2. Tax — `tax_status`, `tax_class`

**Recommend NOT carrying, and the question first.** A `tax_rates` table exists in
WooCommerce's own shape — `country, state, rate, priority, is_inclusive,
applies_to_shipping` — and it **currently holds zero rows**. There is no
per-product tax class anywhere to select into.

So: **is UAE VAT a single flat 5% on everything you sell?** If it is, a
per-product tax class is data that will never be read, and two columns holding
`taxable` / `reduced-rate` on every row would be worse than the report line that
names them — they would look like a working feature.

*Cost if the answer is "one flat rate":* nothing. Populate `tax_rates` with one
row, which is a separate and much smaller job.
*Cost if it is not:* a tax-class table, a per-product reference, and something
that reads both at checkout — considerably more than the two columns, and none of
it useful until that last part exists.

### 3. Linked products — `upsell_ids`, `cross_sell_ids`, `grouped_ids`

**Ask what he wants them for.** Related products are currently computed from the
category, which is free and roughly as good for a catalogue this size. Hand-picked
upsells are better if he actually curates them; if the lists in WooCommerce were
set once in 2019 and never revisited, importing them is importing stale
merchandising.

*Cost:* a pivot table and a **second pass**, because these are
product-to-product references and a product can point at one imported after it —
`ImportContext::localId()` will legitimately answer null on the first pass. That
second pass is most of the work; the columns are the easy part.
`grouped_ids` additionally needs grouped products to be a type the shop
understands, which is a storefront change and not an import one.

*Public API:* an upsell list is shopper-facing and could reasonably go into
`toApi()` later; it should not go in as raw `wc_id` values.

### And two smaller ones, for completeness

* `sold_individually` — a genuine behaviour change if ignored (a capped product
  becomes uncapped). One boolean column plus one check in the cart. Cheap, and
  the cart check is Lane CP's file, so it needs coordinating.
* `reviews_enabled` — same shape: one boolean, honoured on the product page.
  Cheap. Worth knowing how many products he actually had reviews switched off on;
  the import report will now tell him.
* `default_attributes` — one column on `products` naming the default variant, or
  one nullable `is_default` on `product_variants`, plus the product page
  preselecting it. Cheap on the import side; the preselect is a storefront change.
  **The number to decide on is in the report**: if it names a handful of products
  this is not worth a column, and if it names every variable product he has, his
  shoppers have all just been given an extra click.

Everything else in the twenty-one — `product_visibility`'s four states,
`backorders`, `low_stock_amount`, `purchase_note`, `date_modified`,
`attribute_summary` — is reported and, on the evidence, safe to lose. If any of
them matters, the report will say how many products carried it and what was in
them, which is the point.

---

## Two deliverables, not one

The exporter change (`sold_individually`, `reviews_enabled`,
`default_attributes`) lives in `wordpress-plugin/kbb-exporter/`, which **never
ships in an update package** — `UpdateGuard` refuses it and
`BuildPackage::NEVER_SHIP` blocks it twice. It reaches the old site by installing
the plugin there **by hand**. The rest travels normally through
`Store → Core Updates`.

Exporting again with the old plugin still works; products.csv simply arrives
without those three columns, and the import reports eighteen skipped fields
instead of twenty-one rather than failing.

---

## Two defects this round fixed, beyond the field itself

### A process-level static that the suite could not see past

`Checkpoint::$hasColumn` memoises whether `import_checkpoints` has the
`dropped_fields` column, so `advance()` does not pay for a `Schema::hasColumn()`
introspection query on each of four hundred committed batches. It caches only the
**yes**, deliberately: these update packages are applied by hand while the shop
runs, and a worker that cached a **no** would keep answering "no column" for as
long as it lived.

That memo went in without being registered in `Tests\Support\StaticMemos`, and
`StaticMemoIsolationTest` is red on it by name — which is what that test is for.
It is not a formality. The suite runs every test in one process against a schema
it migrates, so the first test to commit an import batch fixes the answer for
every test after it, including one that deliberately builds a checkpoints table
**without** the column to prove an import still works on a shop whose package has
not landed. That test would then write a column that is not there and fail
pointing at the import rather than at the memo — the ordering bug `CLAUDE.md`
records for `Setting::map()`, with a migration as the change.

Registered with a reset rather than an exemption, because an exemption is a claim
that the value cannot cross a test boundary and this one plainly can.

### An idempotence test where two of its four pivots were empty

The test named *"imports the same export twice and leaves every row and every
pivot count identical"* compared five numbers across two passes. Two of them —
`product_attribute_value` and `product_variants` — were **0 before and 0 after**,
because its fixture replaced `products.csv` with products `4021`/`4022` while
`attributes.csv` names `4023` and `variations.csv` has `4023` as every variant's
`parent_id`. `0 === 0` is identical and asserts nothing: an export whose
attributes and variations never arrived satisfied every assertion in the test.

The fixture now carries `4023`, and the test asserts each pivot is **non-empty**
before comparing it. That assertion is what found this — the test was green and
had been green. A vacuous assertion is the worse half of a green suite, because
from the outside it is indistinguishable from a real one; it is the same shape as
`Api\ProductController`'s dead `status` filter and `Row::list()`'s unreachable
comma fallback, both of which this repository has already paid for.

---

## Where the evidence is

* `tests/Feature/ImportProductParityTest.php` — every claim above, with the
  mutation that turns each test red written into its comment.
* `tests/Feature/GqMigrationCensusTest.php` — the census. Its fourth test moves a
  column's name from one side of a comparison to the other the moment a field
  stops crossing, and fails with the name in the message.
* `app/Services/Import/Entities/ProductImporter.php` — `NOT_CARRIED`.
* `app/Services/Import/EntityReport.php` — `discardedList()`, `droppedField()`,
  and the corrected `EXCERPT_LENGTH` docblock.
* `tests/Feature/ImportProductParityTest.php` → *"emits a column for every
  product meta key the exporter goes and fetches"* — the guard against the class
  of gap `default_attributes` was. It reads the stage's `META_KEYS` against the
  row the stage actually emits, so a key that is fetched and dropped is red by
  name. It needs no MySQL and runs in CI, unlike the meta-key half of the census.
* `docs/px-progress-shots/` — the progress page at 390 and 1280, mid-run and
  finished, regenerated by `tools/px-progress-preview.sh` and
  `tools/px-progress-shots.mjs`. The seed now **restarts** the run and refuses to
  shoot a mid-run picture whose reconciliation already reads as concluded: it
  produced four identical *finished* pictures once, two of them captioned
  "mid-run", which is evidence for the opposite of what the card does.
