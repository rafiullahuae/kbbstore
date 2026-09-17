# Lane FX — the Yoast tier question, answered by the export

Phase 12 leaves the Yoast importer at `[~]` with one thing outstanding, and
calls it **"an owner question, not a developer one"**:

> which Yoast tier was actually in use (free / Premium / +WooCommerce SEO
> add-on). That decides whether the export carries product-schema data at all;
> the map handles what the free tier writes

It is not an owner question. Each tier writes post meta the others do not, and
the export is a list of exactly those keys. The answer has been in the owner's
hands the whole time; nothing was reading it out.

`app/Support/YoastTiers.php` is that reader. It is a **new file in this lane's
own namespace** and it does **not** touch `app/Services/Import/**`, which Lane
FV owns this round. The anchors below are what turns it from a class into a
report the owner sees; they are for the integrator to apply.

---

## 1. The table, and where it came from

Read out of sources rather than from blog posts:

| tier | where the key list came from |
|---|---|
| Yoast SEO (free) | `Yoast/wordpress-seo` → `inc/class-wpseo-meta.php`: `$meta_prefix = '_yoast_wpseo_'`, the `general` / `advanced` / `schema` groups, and the `$social_networks` × `$social_fields` cross-product that builds the `opengraph-*` and `twitter-*` four at `init()` |
| Yoast SEO Premium | `_yoast_wpseo_focuskeywords` (related keyphrases, JSON) and `_yoast_wpseo_keywordsynonyms` — neither has a free-tier writer |
| WooCommerce SEO add-on | `woocommerce/google-listings-and-ads` → `src/Integration/YoastWooCommerceSeo.php`, which reads the add-on's identifiers to build a Merchant Center feed and is the closest thing to a specification outside the paid plugin |

### The tier tells

* **Premium, proved:** `_yoast_wpseo_focuskeywords`, `_yoast_wpseo_keywordsynonyms`.
* **Premium, hinted only:** `_yoast_wpseo_redirect`. It is **registered by the
  free plugin** (the `advanced` group of `class-wpseo-meta.php`) and merely
  *written* by Premium's redirect manager, so it raises a hint and never the
  verdict. `YoastTiers::PREMIUM_PROOF` is where that distinction lives, and
  `YoastTierCensusTest` mutates it both ways.
* **WooCommerce SEO add-on:** `wpseo_global_identifier_values` on the product,
  `wpseo_variation_global_identifiers_values` on a variation.

### Absence is not evidence

A Premium shop whose operator never opened the extra boxes writes no Premium
key. `verdict()` therefore reports *"only fields the FREE tier writes are
present"* rather than *"the free tier"*, and reports an empty export as **"no
Yoast key of any tier carried a value — this export has no SEO data in it,
which is an answer and not a failure."** That last sentence is the direct answer
to what Phase 12 actually asked: *does the export carry product-schema data at
all.*

---

## 2. The finding: the GTIN blocker is an import gap, not a data gap

Phase 12's structured-data item ends:

> **Still open: GTIN and variant-level offers** — genuinely blocked, no
> GTIN/barcode column exists anywhere in the schema and there's no existing
> source of truth for it

**Both halves have stopped being true**, and this document is about the second.

The WooCommerce SEO add-on stores a product's GTIN-8, GTIN-12/UPC,
GTIN-13/EAN, GTIN-14/ITF-14, ISBN and MPN in **one** post meta key,
`wpseo_global_identifier_values`, holding a map. If the shop ran that add-on and
filled those boxes in, **the export is the source of truth the plan says does
not exist**, and `products.gtin` — added since, by
`2026_10_05_000000_add_product_editor_columns` — is the column it goes in.

### Two traps, which is why it has been invisible

1. **The key does not start with `_yoast_`.** It is
   `wpseo_global_identifier_values`: no leading underscore, no `yoast`.
   `YoastSeo::looksLikeYoast()` tests for the substring `yoast_wpseo`, so the
   column does not look like Yoast to the importer at all, and
   `YoastSeo::UNMAPPED` does not list it, so `skipped()` cannot report it
   either. A file of nothing but an id and the identifiers is **rejected row by
   row** as "is this file the Yoast export?" — asserted in
   `YoastTierCensusTest`.

2. **The value is a map, not a string.** WordPress stores it serialized; a CSV
   exporter emits either the PHP serialization or JSON. Even a reader that found
   the column would get `a:1:{s:6:"gtin13";s:13:"…";}`.
   `YoastTiers::gtinFrom()` unpicks both, with `unserialize(…, ['allowed_classes'
   => false])` so a crafted export cannot instantiate anything.

Nothing in this lane **writes** a GTIN. A barcode is the field Google matches
products on: a wrong one attaches this shop's price and stock to somebody
else's product, which is worse than having none. `gtinFrom()` therefore returns
`null` for a bad check digit, for an MPN, for a value that is not a map, and for
an absent column — four ways of having no answer, all producing the same
nothing.

---

## 3. ANCHOR A — make the run print the census

**File:** `app/Services/Import/Entities/SeoImporter.php`

**Anchor** (exact, lines 147–156 at `c6c74be`):

```php
        foreach (YoastSeo::skippedWithValues($cells) as $meta => $value) {
            $report->discarded(
                $meta.' has no equivalent in this application — the export carries it and nothing here '
                .'reads it, so it is not imported',
                $row->line,
                (string) $wcId,
                $meta,
                $value,
            );
        }
```

**Replacement** (append after the existing block; the block above is unchanged):

```php
        foreach (YoastSeo::skippedWithValues($cells) as $meta => $value) {
            $report->discarded(
                $meta.' has no equivalent in this application — the export carries it and nothing here '
                .'reads it, so it is not imported',
                $row->line,
                (string) $wcId,
                $meta,
                $value,
            );
        }

        /*
         * ── WHICH YOAST WAS IN USE, COUNTED RATHER THAN ASKED ──────────────
         *
         * One note per key this row carries. EntityReport counts notes BY THEIR
         * TEXT, so a run comes out as a table — the key, the tier that writes
         * it, what this application does with it, and the number of rows
         * carrying it — with no new report channel and no run-level state:
         *
         *   671  _yoast_wpseo_metadesc  — Yoast SEO (free) — … — imported
         *   183  _yoast_wpseo_focuskeywords — Yoast SEO PREMIUM — … — NOT imported
         *   214  wpseo_global_identifier_values — Yoast WooCommerce SEO ADD-ON
         *        — … — NOT imported, AND THERE IS SOMEWHERE FOR IT TO GO
         *
         * Line two settles the tier question Phase 12 could not answer. Line
         * three is the barcodes.
         *
         * App\Support\YoastTiers::KEYS is deliberately WIDER than
         * YoastSeo::MAPPED + ::UNMAPPED: it names the Premium and add-on keys
         * that table has never had a line for, plus three free-tier scores
         * (inclusive_language_score, seo_title_score, meta_description_score)
         * that are today neither imported nor reported. YoastTierCensusTest
         * fails if a key the importer acts on has no line here.
         */
        foreach (YoastTiers::present($cells) as $meta) {
            $report->note(YoastTiers::line($meta));
        }

        /*
         * And the honesty half: a wpseo-looking column nobody has documented.
         * "We did not import it" and "we never heard of it" are the same bytes
         * on disk afterwards, and only one of them is a decision.
         */
        foreach (YoastTiers::unrecognised($cells) as $column) {
            $report->note(YoastTiers::line($column));
        }
```

**Also add** to that file's `use` block:

```php
use App\Support\YoastTiers;
```

---

## 4. ANCHOR B — import the barcode, once the owner has approved the census

**Only after** the report above has shown the owner how many rows carry
`wpseo_global_identifier_values`. This is the step that writes.

**File:** `app/Services/Import/Entities/SeoImporter.php`

**Anchor** (exact):

```php
        // false: the owner's typing wins. See the header — reversing it is a
        // one-field change on ImportOptions, which this lane does not own.
        $merged = YoastSeo::merge($product->seo, $fragment, overwrite: false);

        $context->record($this->name(), $context->apply($product, ['seo' => $merged]));
```

**Replacement:**

```php
        // false: the owner's typing wins. See the header — reversing it is a
        // one-field change on ImportOptions, which this lane does not own.
        $merged = YoastSeo::merge($product->seo, $fragment, overwrite: false);

        $changes = ['seo' => $merged];

        /*
         * THE BARCODE, out of the WooCommerce SEO add-on's identifier map.
         *
         * gtinFrom() returns null unless the value is a readable map holding a
         * GTIN whose own mod-10 check digit agrees with it — so a typo, an MPN,
         * an ISBN-10 and an unreadable cell all import nothing rather than
         * importing something wrong. Google MATCHES PRODUCTS ON this field.
         *
         * `?? $product->gtin` and not an overwrite, for exactly the reason the
         * `seo` merge above defaults the way it does: a value already on the
         * row was typed into this admin AFTER the export was taken, and an
         * importer that can quietly undo an afternoon's work is one nobody runs
         * twice.
         */
        $gtin = YoastTiers::gtinFrom($cells);

        if ($gtin !== null && ($product->gtin === null || trim((string) $product->gtin) === '')) {
            $changes['gtin'] = $gtin;
        }

        $context->record($this->name(), $context->apply($product, $changes));
```

---

## 5. What is still genuinely blocked, stated precisely

**Variant-level GTINs.** `product_variants` has **no `gtin` column**, and the
add-on's `wpseo_variation_global_identifiers_values` is keyed by WooCommerce
variation id — which this schema does carry, as `product_variants.wc_id`. So
this is a migration and a reader, not a data problem, and the owner has to
supply nothing:

```php
// database/migrations/<date>_add_gtin_to_product_variants.php
Schema::table('product_variants', function (Blueprint $table) {
    if (! Schema::hasColumn('product_variants', 'gtin')) {
        $table->string('gtin', 14)->nullable()->index();
    }
});
```

It is **not** applied in this lane, for one reason: nothing would read it yet.
`App\Support\Seo` publishes one `gtin` on the Product node, and the per-variant
`Offer`s inside the `AggregateOffer` carry `sku` only. Adding the column now
would be a fifth "built, never wired up" find waiting to be made. The order that
works is: run the census → learn whether the add-on was even in use → if it was,
ship the column, the reader and the emitter together.

**What the owner would have to supply if the add-on was NOT in use.** A
barcode per product, and there is no substitute for it: `sku` is free text this
shop invents, so two retailers selling the same toner have two different SKUs.
The realistic sources are the supplier's price list, the carton, or a GS1
lookup. `App\Support\Gtin` already refuses anything whose check digit disagrees,
and the product editor already collects and validates it — so the receiving end
is finished and waiting.

---

## 6. Running it

There is no shell on the host, so this cannot be an Artisan command anybody can
use. The census rides the existing import run and appears in the report the
Import screen already draws — which is also why it is built out of `note()`
rather than a new channel.
