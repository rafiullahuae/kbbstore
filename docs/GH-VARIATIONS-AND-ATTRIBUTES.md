# Variations, attributes and tags — the three files nothing opened · Lane GH

`docs/FV-IMPORT-AT-VOLUME.md` §11 is a list of what a **clean, complete,
full-volume import** still leaves at zero. Three lines of it are this lane:

| table | §11's words |
|---|---|
| `product_variants`, `product_variant_attribute_value` | "A variable product imports as its parent only. The shop then sells '50ml or 100ml' as one price. **This is the largest missing entity by revenue.**" |
| `attributes`, `attribute_values`, `product_attribute_value` | "Brands are imported *because* someone noticed `pa_brands` was an attribute. The other attributes are what the filters on a category page are built from." |
| `tags`, `product_tag` | "Product tags, and the tag archives Google has indexed." |

Lane GE then built the WordPress plugin that writes `variations.csv`,
`attributes.csv` and `tags.csv`. Nothing read them. This document is the other
end of that pipe.

The evidence is `tests/Feature/GhVariationsAndAttributesTest.php`: 26 tests, and
the round-trip ones feed **Lane GE's own fixture export** to
`App\Services\Import\ImportRunner` — the class `kbb:import` runs, with no test
double anywhere in the path — and then fetch the rendered product page and read
back what changed.

---

## 1. The headline

A variable product used to publish this to Google:

```json
{ "@type": "Offer", "price": "0.00", "availability": "https://schema.org/InStock" }
```

A shop telling a crawler its cleanser is free. Not because the SEO code was
wrong — `App\Support\Seo` has published an `AggregateOffer` over two or more
differently-priced variants since Lane FX — but because **it had never seen a
variant**, and WooCommerce keeps no price on a variable product's parent post,
so `products.price` imports as NULL and `Store\ProductController` hands Seo
`price_minor => 0`.

After this lane, the same page publishes:

```json
{
  "@type": "AggregateOffer",
  "lowPrice": "60.00", "highPrice": "100.00", "offerCount": 2,
  "offers": [
    { "@type": "Offer", "price": "60.00",  "sku": "KBB-4023-50ml",  "availability": "https://schema.org/InStock" },
    { "@type": "Offer", "price": "100.00", "sku": "KBB-4023-100ml", "availability": "https://schema.org/OutOfStock" }
  ]
}
```

and the page itself draws two option rows, each with its own price, with the
size the owner had disabled greyed out and tagged **Sold out** —
`docs/gh-shots/product-with-variants.png`, taken from a real server run
(`kbb:import` into a SQLite database, `php -S`, Chromium) rather than from the
test client.

---

## 2. What the schema already had — which is almost all of it

The brief said to check before building, because this project has been wrong
about "no column exists" five times this month. It was wrong again here, in the
same direction.

| table | verdict |
|---|---|
| `product_variants` | **complete.** `price`, `sale_price`, `sku`, `stock`, `stock_status`, `manage_stock`, `image`, `position` since the ORIGINAL schema migration; `tag` since 2026_08_28; `wc_id` made UNIQUE by 2026_09_22_000000_add_import_external_ids. Nothing added. |
| `product_variant_attribute_value` | **complete** — and already named explicitly by `ProductVariant::attributeValues()`, because Laravel's convention produces a different table name. |
| `attribute_values` | **complete.** `source_term_id` is there and 2026_09_22 made it UNIQUE by name: "WP term ids are globally unique … without it a second pass creates a duplicate '50ml' that variants then split across." |
| `product_attribute_value`, `tags`, `product_tag` | **complete.** `tags.source_term_id` unique in the Phase 0 schema. |
| `attributes` | **one column short.** See §3. |

So: three importers and one migration, and the migration adds a column §11 did
not ask about — the *data* columns those seven tables need were all there. The
thing that was missing was the ability to tell an imported attribute from one the
owner typed.

### What was waiting on the other side, also already built

- `App\Support\Seo::aggregateOffer()` — the range, the per-option availability,
  the integer-fils arithmetic. Written, tested against hand-made rows, never fed
  an imported one.
- `store/product.blade.php` — a price per `.variant` row, `$buyable` picking the
  first option actually on the shelf, the "Sold out" tag, the hidden
  `variation_id`. All of it ran for the first time on imported data here.
- `Admin\AttributesApiController` and Catalog → Attributes — full CRUD over
  `attributes`/`attribute_values` and both pivots, against tables that were
  empty on every install.

---

## 3. The one column added: `attributes.source_attribute_id`

`database/migrations/2026_11_24_000000_add_attribute_source_id.php`, nullable,
with a unique index, no `->after()`, no `down()`.

`attributes` is the **sixth** table with the problem
2026_09_22_000000_add_import_external_ids was written for — "a table whose rows
cannot be matched back to their WordPress originals has exactly one behaviour on
a re-run: it doubles". It is not in that migration's list only because nothing
imported attributes at the time, so the table had no re-run to survive.

Without it the only key is the slug, which `docs/IMPORT-READINESS.md` rule 1
forbids. The specific harm is not the usual one — a WooCommerce attribute's slug
*is* its taxonomy name and is rarely edited — it is that
**`Admin\AttributesApiController` lets the owner create an attribute by hand**.
"Size" typed into that screen and `pa_size` arriving from WooCommerce are two
different things wanting one unique slug, and with no external id on the row the
importer cannot tell them apart: it would silently adopt the hand-made row, or
refuse it with no way to say why. With the column, `SlugGuard` answers it exactly
as it already does for brands, categories and products.

**It is read immediately, which is the whole condition for adding it.**
`AttributeImporter` matches on it before it looks at a slug. A column nothing
reads is the "built, never wired up" find this project has made five times this
month.

### The per-variant `gtin` column — declined, and why the reason is now stronger

`docs/FX-YOAST-TIER-CENSUS.md` §5 wrote the migration and deliberately did not
apply it, because nothing would read it. That verdict holds, and this lane can
add two measurements to it rather than an opinion:

1. **The export does not carry it.** `variations.csv` has 22 columns and none of
   them is a GTIN — `docs/GE-WP-EXPORTER.md` §11 lists "variation-level GTINs"
   under *what was deliberately not exported*, for the same circular reason.
2. **The export for this shop does not even carry the source meta.**
   `seo.csv`'s columns are *discovered* by a `SELECT DISTINCT` over the Yoast
   keys, and the fixture's header carries `wpseo_global_identifier_values` and
   **no `wpseo_variation_global_identifiers_values`**. There is nothing to read.

So the column would be a third empty thing, not a first useful one. What would
have to move together, in one lane, for it to be worth applying:

- the migration (FX §5 has it verbatim);
- `KBB_Export_Stage_Variations::columns()` — a `gtin` column, or
  `SeoImporter` learning to unpick `wpseo_variation_global_identifiers_values`
  and key it by `product_variants.wc_id`;
- `Seo::aggregateOffer()`'s child offers, which carry `sku` only today.

Any one of those alone is a fifth "built, never wired up".

---

## 4. The four places the obvious column is the wrong one

`attributes.csv` is one row per **term**, with the attribute's definition
repeated on it. Four of its columns read as something they are not.

**1. The attribute's slug is `attribute_name`, not `taxonomy`.** The taxonomy is
`pa_size`; this schema's own column comment says `color, size, shades`, and
`Attribute::queryVar()` builds `filter_size` from it — which is the string
Catalog → Attributes prints beside every filterable attribute. Importing `pa_size`
puts `filter_pa_size` in front of the owner on the Attributes screen and in every
URL built from it. The exporter falls back to `substr($taxonomy, 3)` where the
definition row is missing; this importer does the same, so the two ends agree on
one value.

**2. The attribute's name is `attribute_label`, not `name`.** `name` on that row
is the TERM's name — `50ml`. Taking it names the attribute after whichever of its
terms was read last, so `Size` becomes `100ml`.

**3. `attribute_public` is NOT `is_filterable`, and this is the one that would
have been silent.** It is WooCommerce's *enable archives* flag — whether
`/pa_size/50ml/` was ever a page — which is what the permalinks stage reads it
for. `is_filterable` is this shop's *show it in the storefront filter panel*.
WooCommerce's layered-nav filter works perfectly well on an attribute with no
archive, and **`attribute_public = 0` is WooCommerce's default for a new
attribute** — so mapping one onto the other arrives at "nothing on this shop is
filterable" for a shop whose filters all worked. It is left unread, which puts it
in the runner's consolidated discard line with its value, where the owner can see
the flag and the decision rather than neither.

**4. `count` is not `position`.** It is WordPress's cached membership count.
Ordering sizes by how many products use each one puts 100ml above 50ml on a shop
that sells more of the large one. The export carries no term ordering at all —
`attribute_orderby` names the *rule* (`menu_order`, `name`, `id`), not the values
— so `position` stays at the schema default. Inventing an order from the file's
row order would also break under resume: a delta export carrying one term would
renumber it to 0.

And a fifth, of the same kind: **`query_var` is not invented.** The export has no
such column, and `Attribute::queryVar()` already computes `filter_size` from the
slug. Writing the same string into the column would claim the export supplied it.

### `is_variation_axis` is set by the variations file, not by the attributes file

Nothing in `attributes.csv` can say whether an attribute is used to build
variations: in WooCommerce that is a **per-product tick stored on the product**,
and the only durable evidence of it is a variation pinned to one of the
attribute's terms. `VariationImporter::markAxis()` sets it when it makes that
pin, and `AttributeImporter` deliberately leaves the flag — and `is_filterable`
— out of its write array entirely, so that an attribute the owner unticked on the
Attributes screen is not silently re-ticked by the next delta pass.

---

## 5. The size the owner had withdrawn

`variations.csv` carries the variation's own `post_status`, because **WooCommerce
disables a single size by setting it to `private`** — and `product_variants` has
no status column.

The honest reading of that is not "ignore it". It is "express it in the column
that exists". A non-publish variation is imported with
`stock_status = outofstock`, which is the one value **every door in this
application already refuses**:

- `Store\CartController::add()` and `Store\CheckoutController::browsedAdd()`
  both test `($variant?->stock_status ?? $product->stock_status) !== 'instock'`;
- `App\Services\StockClaim::claim()` tests it again **inside the placing
  transaction**, so a basket that predates the import cannot be paid for either.

The test drives the real endpoint: `POST /api/cart/add` with the withdrawn
variant answers **422**, and with the live one answers **200**.

`trash` is refused outright by name, exactly as `ProductImporter` refuses a
trashed product.

### What that costs, so the owner can overrule it

The option is still **drawn** on the product page, greyed and tagged "Sold out" —
which is not the same sentence as "withdrawn" — and its price is still inside the
`AggregateOffer`'s range, marked `OutOfStock`. Both of those are what the page
and the document say about *any* sold-out size, and this shop's own rule is that
the structured data states what the page states. Hiding it instead needs a
`status` column **and** a reader in three places (`ProductController`'s eager
load, the blade's `$variants`, and the `variants` array it hands `Seo`) — which
is a storefront change, not an import one, so it is in §11 for the owner rather
than done here.

---

## 6. What the product page and its structured data now say

Measured on `cleanser-4023`, the fixture's variable product, by rendering the
page rather than by reading the template.

| | before | after |
|---|---|---|
| option rows | none — `$isVar` false, no `.variants` block | two, `data-vid`, `data-price="AED 60"` / `"AED 100"` |
| option names | — | `50ml`, `100ml`, from the terms the variant is pinned to |
| the disabled size | — | `class="variant oos"`, `<span class="vtag sold">Sold out</span>` |
| the selected option | — | the **live** one (`variant on`), not option 0 |
| hidden `variation_id` | empty | the live variant's id |
| `optNote` | — | `2 options` |
| JSON-LD `offers` | `Offer`, `price: "0.00"` | `AggregateOffer`, `lowPrice 60.00`, `highPrice 100.00`, `offerCount 2` |
| per-option availability | — | `InStock` / `OutOfStock`, matching the page |
| the parent's `price` and `priceSpecification` | published `0.00` | **removed** — the range replaces them |

### The headline price is still AED 0, and that is not this import's to fix

The `.now` span, the shop tile, the price **sort** and the price **facet** all
read `products.price`, which is NULL for a variable product because WooCommerce
has no price on the parent to export. Measured, not inferred:

```
shop tile:   "Rice Cleanser · AED 0"
price sort:  ORDER BY price ASC puts cleanser-4023 FIRST, at null
```

`docs/gh-shots/shop-tile-variable-product.png` is `/shop/?orderby=plow` on the
same server: the cleanser is the **first tile**, at **AED 0**, ahead of a product
that really does cost AED 25. That shot also shows §7's other half — the filter
sidebar carries Category and Brand and no attribute group at all.

Backfilling it from the cheapest variant is one line in
`VariationImporter::finalise()` and it would **break the one property this whole
import is judged on**: `ProductImporter` writes `price => null` for that row out
of the CSV on every pass, so the product would be rewritten every run for ever
and the products bucket would never report `unchanged` again. The fix needs both
halves:

1. `ProductImporter` must not write a null price over an existing one **for a
   `variable` product** (and must say so in the report when it declines to);
2. `VariationImporter::finalise()` sets `products.price` to `min(price)` over the
   product's variants, in integer fils.

Both are in `app/Services/Import/Entities/`, so one lane can do it — but it
changes what a shopper is shown on every variable product's tile, and it is the
owner's decision whether the tile should read the cheapest option's price or
something else ("from AED 60"). Pinned as a failing-if-it-changes test:
`it leaves a variable product's HEADLINE price at zero`.

---

## 7. What importing attributes actually turns on — and what it does not

**It does turn on:** Catalog → Attributes, which has been a full CRUD screen over
four empty tables. `Admin\AttributesApiController::index()` now answers with real
attributes, real terms, and real per-term `products_count` and `variants_count`,
and its delete guard ("a variant that loses the value defining it stays on sale
with nothing left to say what it is") now has variants to guard.

**It does not turn on a storefront filter, and the admin screen says it does.**
The Attributes screen tells the owner that `is_filterable` "is what puts it in
the storefront filter panel", and `App\Support\Facets` — the class that reads the
query string and the sidebar in `store/shop.blade.php` — knows **`cat`, `brand`,
`price`, `sale`, `instock` and nothing else**. There is no `filter_size` branch
anywhere. `Attribute::queryVar()` exists, is correct, and is called by nothing on
the storefront.

So: this lane fills `attributes`, `attribute_values` and
`product_attribute_value` — which is the whole of the data a facet needs, and
`product_attribute_value` is exactly "which terms a product offers", the pivot a
filter matches on — and the filter itself is a separate, unbuilt thing. Stating
it plainly because the alternative is the owner importing 93 terms and waiting
for a filter panel that is not coming. **For the owner to settle: is the
attribute facet wanted on /shop/ and on the category pages?** If it is, it is a
`Facets` change plus a sidebar group, and the data is now there for it.

---

## 8. What it cannot keep, and says so

Every one of these is in the report the owner reads, per row, with the value
quoted — `docs/FV-IMPORT-AT-VOLUME.md` §10's channel.

| discarded | why, and what it costs |
|---|---|
| a variation's **own sale window** | `product_variants` has no date columns, and `ProductVariant::effectivePrice()` applies the **parent's** window to every variant under it. Where the parent has none, that variation's `sale_price` applies with no end date at all. Reported per row with both dates, not folded into the consolidated column line, because *which* variations carry one is the difference between "nothing to do" and "three sizes are about to sell at a 2022 discount for ever". |
| an axis set to **"any"** | WooCommerce lets one variation stand for every value of an attribute. A variant here is defined by the exact terms it is pinned to. The variant is imported without that axis rather than refused — refusing would lose a purchasable option over something the shop can still sell. |
| a **per-product ("custom") attribute** | `attributes.csv` is "every `pa_*` term that is not a brand", so a variation axis defined on the product itself has no term, no taxonomy and no file. Named, never invented: creating an attribute nothing described would put an axis on the Attributes screen that WooCommerce never had. **This is a gap in the contract, not in this importer — see §11.** |
| `tags.description`, `tags.parent`, `tags.count` | `tags` is four columns. Deliberately left unread so the runner's consolidated line names all three with a sample value. |
| a variation's `weight`, `length`, `width`, `height`, `tax_class`, `backorders`, `description`, `date_created` | no column. Named in the same consolidated line. |
| `attributes.attribute_type`, `attribute_orderby`, `attribute_public`, `count`, `description` | §4. |

Refusals, each with a sentence the owner can act on: a variation whose parent is
not in the database (with the reason it is usually missing — a trashed parent
takes its variations with it), an unknown `status`, `trash`, a sale price with no
regular price, a tag or attribute row that cannot be identified, and the slug
collisions in §9.

---

## 9. A defect this lane found in its own code, by writing the test for it

`BrandImporter` and `CategoryImporter` reach `SlugGuard` **only where the row is
new**, and the first draft of these importers copied that shape.

The case it misses is a row the import already owns whose slug the export has
since changed onto one something else holds. The guard never runs, the UPDATE
goes to the database, and `attributes.slug`, `tags.slug` and
`attribute_values (attribute_id, slug)` are all UNIQUE. What the owner gets is:

```
the database refused this row: SQLSTATE[23000]: Integrity constraint violation:
19 UNIQUE constraint failed: attribute_values.attribute_id, attribute_values.slug
(Connection: sqlite, SQL: update "attribute_values" set "slug" = 50ml, ...)
```

The runner does catch it — it is not silent — but the report carries a SQL
statement with its bound values and nothing saying which two rows are fighting or
what to do. All three now check before they write, and **adoption is deliberately
not on offer** in that branch: the row already exists, so claiming a second one
would merge two terms that variants are pinned to separately.

`attribute_values` could not use `SlugGuard` at all, and that is worth recording:
its unique key is the **pair** `(attribute_id, slug)` — "50ml" under Size and
"50ml" under Sample Size are two legitimate rows — while `SlugGuard` asks about a
bare `slug` column. Asking its question here would refuse a term over a collision
the database does not have.

**The same hole is still open in `BrandImporter` and `CategoryImporter`.** They
are not this lane's files and their own tests do not cover it; it is named here
rather than left to be found again.

---

## 10. Mutation testing

Every guard was broken, the suite run, and the guard restored.

**25 guards mutated; 2 survived first time, and both are red now.**

| # | mutation | verdict |
|---|---|---|
| 1 | a disabled (`private`) variation: stop forcing `outofstock` | red |
| 2 | the attribute's slug taken from `taxonomy` (`pa_size`) instead of `attribute_name` | red |
| 3 | the attribute's name taken from the row's `name` (`50ml`) instead of `attribute_label` | red |
| 4 | `is_filterable` written from `attribute_public` | red |
| 5 | `attribute_values.position` written from `count` | red |
| 6 | `is_variation_axis` claimed by the attributes file | red |
| 7 | the variations file stops marking the axis | red |
| 8 | the tag pivot appends instead of reconciling (never removes) | red |
| 9 | `product_attribute_value` appends instead of reconciling | red |
| 10 | the variation's axes split on a comma instead of a pipe | red |
| 11 | **a trashed variation is imported** | **GREEN — survived**, then red |
| 12 | an unknown `status` is guessed as sellable | red |
| 13 | a sale price with no regular price is accepted | red |
| 14 | a variation with no parent is imported anyway | red |
| 15 | the variation price is `(int)` cast instead of parsed to fils | red |
| 16 | the term slug guard runs only on a new row (the §9 hole, restored) | red |
| 17 | an attribute may move onto a slug something else holds | red |
| 18 | a tag may move onto a slug something else holds | red |
| 19 | an "any" axis is pinned to a term | red |
| 20 | a per-variation sale window is dropped in silence | red |
| 21 | the attributes bucket counts attributes rather than terms | red |
| 22 | **a variant whose defining terms moved reports `unchanged`** | **GREEN — survived**, then red |
| 23 | a tag whose membership moved reports `unchanged` | red |
| 24 | `variations` registered before `attributes` | red |
| 25 | `ProductVariant::attributeValues()` back to Laravel's conventional pivot name | red |

Each mutation was run against `GhVariationsAndAttributesTest` first and, where
that stayed green, against `ImportAtVolumeTest`, `AdminImportScreenTest`,
`GeWpExporterTest` and `GfImportRefinementTest` as well before being called a
survivor. Exactly one — **9**, the `product_attribute_value` reconciliation —
needed the wider set to die, which is worth knowing: that guard is load-bearing
for the **volume** suite's second-pass property rather than for the round trip.

### 11 — the assertion that could not fail

`it refuses a variation with no parent and one whose status it does not know…`
ended with

```php
expect(ghReasons($report, 'variations'))->toContain("status 'trash'");
```

and that needle is in **both** refusals. Delete the trash branch and `trash`
falls through to the unknown-status refusal, whose message is
`status 'trash' is not one this importer knows (publish, private, draft, pending,
future)` — which contains the needle, so the suite stayed green while a row
WordPress had deleted was one branch away from being imported as a live variant.

The two sentences are different statements and only one of them is right: "this
variation is in the WordPress trash — empty the trash or filter the export" tells
the owner what to do; "this importer does not know what that is" is a bug report
about an export that is fine. The assertion now reads the whole sentence. Re-run:
red.

This is the same class of defect as `->not->toContain($needle, $message)` and it
is worth naming separately, because that one is banned by convention here and
this one is not catchable by a rule: the needle was genuinely present, just not
because of the code under test.

### 22 — a guard with no test at all

`it files a product under its tags…` covers the tags pivot's
"pivot moved ⇒ report `updated`" correction. The identical correction on
`VariationImporter` had nothing pointing at it, so deleting it left the suite
green.

It is not cosmetic. A variant re-pinned from 50ml to 100ml between two exports is
a different purchasable option, and reporting it `unchanged` makes the
second-pass evidence a lie about the one table `docs/FV-IMPORT-AT-VOLUME.md` §11
calls the largest missing entity by revenue.
`it reports a variant whose defining terms moved as updated` re-pins one in the
export and asserts `updated: 1, unchanged: 1`. Re-run: red.

### 25 — a guard that existed with nothing behind it, and now has something

`ProductVariant::attributeValues()` names its pivot table explicitly, because
Laravel's convention produces `attribute_value_product_variant` and the schema
creates `product_variant_attribute_value`. Its own comment says what the wrong
name cost: "the moment the owner adds a size or a shade to a product in the
admin, that product's page stops loading."

Until this lane **nothing in the seeders or the suite ever created a
ProductVariant row**, so that fix had no test with data behind it. It does now —
mutating the name back fails **7 of these 26 tests**, including the rendered
product page and the JSON-LD. That is not a guard this lane wrote; it is one this
lane's data finally covers.


---

## 11. Changes for files this lane may not edit, and things only the owner can settle

### `app/Services/Import/ImportRunner.php` — the registration

`ImportRunner::entities()` is the file three lanes are in this round, so the
registration is handed over as anchors rather than assumed. It is applied on
this branch as well, so the integrator can take either the diff or the anchors —
whichever merges more cleanly alongside Lane GI's and Lane GJ's.

All three hunks are in `app/Services/Import/ImportRunner.php` and all three
anchors were verified at **exactly 1 occurrence** in the file as it stands at
`10ad3fe`.

**Hunk 1 — INSERTS one line before the anchor.** Anchor:

```php
use App\Services\Import\Entities\BrandImporter;
```

Replacement:

```php
use App\Services\Import\Entities\AttributeImporter;
use App\Services\Import\Entities\BrandImporter;
```

**Hunk 2 — INSERTS two lines after the anchor, anchor line unchanged.** Anchor:

```php
use App\Services\Import\Entities\ProductImporter;
```

Replacement:

```php
use App\Services\Import\Entities\ProductImporter;
use App\Services\Import\Entities\TagImporter;
use App\Services\Import\Entities\VariationImporter;
```

**Hunk 3 — INSERTS after the anchor, anchor line unchanged.** This is an
insertion in the MIDDLE of `entities()`, not an append: the three go after
`products` and before `coupons`, because the order of that array is the order the
runner walks it. Anchor (with its twelve leading spaces):

```php
            new ProductImporter,
```

Replacement:

```php
            new ProductImporter,
            /*
             * TAGS, ATTRIBUTES AND VARIATIONS -- AFTER PRODUCTS, AND
             * VARIATIONS AFTER ATTRIBUTES. Both halves are dependencies.
             *
             * After products, because all three carry WordPress ids that only
             * ProductImporter can translate: `tags.product_ids` and
             * `attributes.product_ids` fill pivots keyed on the LOCAL product
             * id, and `variations.parent_id` is the post id of the variable
             * product the variant hangs off -- product_variants.product_id is
             * NOT NULL, so a variation registered before products refuses every
             * row.
             *
             * Variations after attributes, because a variation names the terms
             * defining it by SLUG (`attribute_pa_size=50ml`) and those slugs
             * have to already be attribute_values rows to be pinned to. The
             * order of this array is the order the runner walks it.
             */
            new TagImporter,
            new AttributeImporter,
            new VariationImporter,
```

### `app/Services/ImportConsole/ImportWorkspace.php` — the screen's mirror

**This list must be in the same ORDER as the runner's**, not merely carry the
same keys: `CouponReviewImportTest` asserts
`ImportWorkspace::entities() === ImportRunner::entityNames()` with `toBe`. The
two drifted once before and every upload on Store → Import 500'd on an undefined
key.

Anchor — verified **1 occurrence**, and note it also corrects a help string that
this lane makes false:

```php
            'help' => 'Products → Export in WooCommerce. Variations, tags and images are not imported yet.',
        ],
```

Replacement: the products help line becomes
`'Products → Export in WooCommerce. Images are not imported here — kbb:import-media fetches those.'`,
followed by the three new entries (`tags`, `attributes`, `variations`, in that
order, each with `file`, `label`, `id`, `unique => false` and `help`). The exact
text is on this branch; it is a pure insertion after the `products` entry.

`ImportDriver` needs no change at all: it walks `ImportRunner::entityNames()` and
reads `ImportWorkspace::meta()`, so one entity per HTTP request, the checkpoint,
the progress denominator and the duplicate guard all cover the three new entities
the moment they are registered. Measured — `AdminImportScreenTest`'s sliced-run
and resume tests pass with them in.

### `app/Console/Commands/ImportWooCommerce.php` — one stale help string

`{--only=*}`'s help listed nine entity names and now lists twelve. The validation
already read `ImportRunner::entityNames()`, so only the help text was wrong.


### `docs/WP-EXPORT-CONTRACT.md` — one gap, stated rather than diverged from

The integrator owns that file and it is binding, so this is a request, not a
change. `attributes.csv` is defined as "every `pa_*` term that is not a brand".
That is right for global attributes and it leaves **per-product ("custom")
attributes** with no file at all — and WooCommerce lets a variation be built on
one. The plugin already emits them: `variations.attributes` carries
`attribute_scent=Unscented`, and `products.attribute_summary` carries
`Scent=Unscented`. Nothing can resolve either to a term, because no file
describes them.

Today this importer names each one in the discard channel and imports the variant
without that axis, so the option's label is built from the axes that did resolve
(and is empty where none did, which the page renders as "Option 1"). That is the
safe behaviour and it is a real loss on any shop that used custom attributes for
sizes. Closing it properly is one more file or one more column in
`attributes.csv`, and it is the contract's call, not this lane's.

### `app/Services/Import/Entities/OrderItemImporter.php` — Lane GI's file

`order_items` has **`product_variant_id`** (nullable FK, nullOnDelete) and
**`variant_attributes`** (json), both since the Phase 0 schema. `order_items.csv`
carries `variation_id` — Lane GE emits it precisely so the link is not lost. That
column is currently in the import's discard list, verbatim:

```
order_items.csv  [order-items]  1 column: variation_id = 4101  ->  (nothing)
```

Before this lane there was nothing to point it at. There is now. An order line
for a variable product can be filed against the exact size that was bought, and
`ManualOrderBuilder` and `CheckoutController` already print
`$item->variant?->attributeValues` as the line's option label. It is one lookup
through this run's id map (`variations`) plus a fallback to
`product_variants.wc_id`. **Lane GI owns that file** — handing it over rather
than doing it.

### For the owner

1. **The variable product's headline price** (§6) — AED 0 on the tile, first in
   the price sort. The fix needs a decision about what the tile should say.
2. **The attribute facet** (§7) — the data is now there; the filter is not built.
3. **Whether a withdrawn size should be hidden rather than greyed** (§5). Hiding
   it needs `product_variants.status`, and three readers.

---

## 12. Reproducing this

```bash
vendor/bin/pest tests/Feature/GhVariationsAndAttributesTest.php   # 26 passed
vendor/bin/pest                                  # 4,569 passed, 21 skipped, 0 failed
KBB_TEST_DB=kbb_gh vendor/bin/pest -c phpunit-mysql.xml
                                                 # 4,575 passed, 15 skipped, 0 failed
find app database routes tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

Both engines, because `attributes.source_attribute_id` is a new unique index and
`docs/MYSQL-PARITY.md` exists for exactly the class of thing SQLite accepts and
MySQL does not. `tools/` is in the lint because CI lints it and the volume
fixture generator changed.

The screenshot and the page readings come from a real server, not from the test
client:

```bash
php artisan --env=preview migrate --force
php artisan --env=preview kbb:import --dir=tests/Fixtures/kbb-export \
    --timezone=Asia/Dubai --adopt-by-slug
APP_ENV=preview SESSION_DRIVER=file php -S 127.0.0.1:8129 -t <docroot> <docroot>/index.php
```

`<docroot>` is any directory whose **parent** holds `bootstrap/` and `vendor/` —
`public-web-root/` is the one this repo ships, and its `index.php` resolves the
application with `__DIR__.'/..'`. Two things about a preview here, both found the
slow way:

- `bootstrap/app.php`'s `usePublicPath()` points at the live server's web root,
  so `public/build` has to be reachable **from the docroot** or the page renders
  with no stylesheet at all.
- Passing Laravel's `index.php` as the router to `php -S` makes it serve the CSS
  as well — **as `text/html`**, which the browser then ignores, so the page still
  renders unstyled and `curl` reports a perfectly healthy 200. A four-line router
  that `return false`s for an existing file fixes it:

  ```php
  <?php
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if ($path !== '/' && is_file(__DIR__.$path)) { return false; }
  require __DIR__.'/index.php';
  ```
