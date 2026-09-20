<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Product;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\YoastSeo;
use App\Support\YoastTiers;

/**
 * Yoast's per-product SEO, out of the WordPress export and into `products.seo`.
 *
 * WHY THIS IS AN ENTITY ON THE EXISTING RUNNER rather than an importer of its
 * own. Everything this needs — batching, one transaction per batch, a
 * checkpoint that survives a request dying on a 110-second host, a dry run, a
 * rejects CSV, and "created / updated / unchanged" tallies that are the only
 * honest evidence a second pass changed nothing — is already built and already
 * tested in App\Services\Import. A second importer would be a second place for
 * all of it to be subtly wrong, and this repository has already paid for two
 * product editors and two title strings.
 *
 * IT RUNS AFTER PRODUCTS, AND THAT IS NOT A PREFERENCE. Every row here is
 * matched on `wc_id`, which the product import is what writes. Run first, this
 * would reject the entire file.
 *
 * ── THE FILE IT READS ───────────────────────────────────────────────────────
 *
 * `seo.csv`: one row per product, a column identifying the WooCommerce post and
 * whatever `_yoast_wpseo_*` columns the export carried. The realistic shapes
 * are a `wp db export` of `postmeta` pivoted to one row per post, and the CSV
 * that WP All Export or the Yoast "export settings" flow produces; both are
 * accepted, along with the bare `metadesc` spelling, because
 * App\Support\YoastSeo::fragment() matches a column with or without the
 * `_yoast_wpseo_` prefix. The full field-by-field table — what is mapped, what
 * is deliberately not, and why — lives in that class and is not repeated here.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────────
 *
 * IT WILL NOT CREATE A PRODUCT. A Yoast row naming a post this catalogue does
 * not have is a rejection with the id in it, not a new row. The `seo` column is
 * an attribute of a product; a product conjured out of its own meta data would
 * have no name, no price and no images, and would be visible to exactly the
 * queries that do not filter on those.
 *
 * IT WILL NOT INVENT A VALUE THE SOURCE LACKS. An absent column, a blank
 * column, and Yoast's `meta-robots-noindex: '2'` all write nothing at all —
 * which is different from writing an empty string, and on this storefront
 * visibly so: `Seo` emits NO description tag for an empty description rather
 * than falling back to the sitewide default, so a blank Yoast field imported as
 * a value would strip the search snippet off every product it touched.
 *
 * IT WILL NOT OVERWRITE WHAT THE OWNER HAS TYPED HERE. A key already present in
 * `products.seo` is left exactly as it is and the Yoast value for it is
 * dropped; only keys the column does not yet have are filled. The export is the
 * older document by definition — it was taken before this admin existed — and
 * an importer that can quietly undo an afternoon's work is one nobody runs
 * twice.
 *
 * THE REVERSE IS BUILT AND NOT WIRED, ON PURPOSE. YoastSeo::merge() takes an
 * $overwrite flag for the case where the export really is the newer document: a
 * store still being edited in WordPress while this port is finished. Turning it
 * on needs one more field on App\Services\Import\ImportOptions, which belongs
 * to the import lane rather than this one, so it is described for the
 * integrator instead of being reached for across a lane boundary. Until the
 * owner asks for it, the safe direction is the only direction.
 *
 * ── IDEMPOTENCE ─────────────────────────────────────────────────────────────
 *
 * Every write goes through ImportContext::apply(), so a second pass over an
 * unchanged export reports `unchanged` for every row — and reports it from
 * Eloquent's own dirty check against the database, not from this class
 * deciding it did nothing. That matters more here than for most entities:
 * `seo` is a json column, and MySQL reorders object keys on the way in, so a
 * naive comparison is dirty on every pass forever. apply() already handles
 * that (withoutEquivalentJson), which is a second reason not to have written a
 * separate importer.
 */
final class SeoImporter extends EntityImporter
{
    public function name(): string
    {
        return 'seo';
    }

    public function conventionalFile(): string
    {
        return 'seo.csv';
    }

    public function import(Row $row, ImportContext $context): void
    {
        $wcId = $row->requireId('id', 'id', 'wc_id', 'product_id', 'post_id');

        $cells = $row->all();

        /*
         * ── SAY WHICH COLUMNS THIS ENTITY READ ──────────────────────────────
         *
         * all() hands back every cell without asking Row for any of them by
         * name, so without this line the runner sees an entity that read
         * nothing but `id` and names every Yoast column of the file in its
         * consolidated "columns nothing reads" discard -- including
         * `_yoast_wpseo_metadesc`, `_yoast_wpseo_title` and
         * `wpseo_global_identifier_values`, all three of which this method
         * writes to `products`. Measured on Lane GE's export: seven columns
         * named as lost, four of them imported.
         *
         * The three vocabularies are the three things this method does with a
         * column: MAPPED is imported, UNMAPPED is discarded BY NAME with its
         * value a few lines below, and YoastTiers::KEYS is noted with its tier.
         * A wpseo column in none of them is genuinely unread and stays in the
         * consolidated line, which is where it belongs.
         */
        foreach (YoastSeo::recognisedColumns($cells, [
            ...array_keys(YoastSeo::MAPPED),
            ...YoastSeo::UNMAPPED,
            ...array_keys(YoastTiers::KEYS),
        ]) as $column) {
            $row->has($column);
        }

        /*
         * A row with no Yoast column at all is a file problem, not a row
         * problem — most likely the products export handed to --files=seo by
         * mistake. Rejecting it row by row turns one wrong path into 671
         * identical rejection lines, but that is still better than a run that
         * reports 671 rows imported and wrote nothing, which is what a silent
         * skip would produce.
         */
        if (! YoastSeo::looksLikeYoast($cells)) {
            throw RowRejected::because(
                'no _yoast_wpseo_* column in this row — is this file the Yoast export?'
            );
        }

        $product = Product::query()->withTrashed()->where('wc_id', $wcId)->first();

        if ($product === null) {
            throw RowRejected::because(
                'no product with wc_id '.$wcId.' — import products before seo, '
                .'and check this row is not a page or a post rather than a product'
            );
        }

        $fragment = YoastSeo::fragment($cells);

        $report = $context->report->for($this->name());

        /*
         * ── WHAT THIS EXPORT CARRIES THAT THIS SHOP HAS NO HOME FOR ─────────
         *
         * discarded(), NOT note(), AND THAT IS THE POINT OF THIS BLOCK.
         *
         * This used to be a note() reading "<field> is in this export and has
         * no home in this application — not imported". EntityReport counts
         * notes by their text, so the run produced one line per field with the
         * number of products that had one — which sounds complete and is not,
         * because it never said WHAT WAS IN THE FIELD. "671 products had a
         * focus keyphrase and it was dropped" is a fact the owner can do
         * nothing at all with.
         *
         * discarded() is the channel Phase 13 specified for exactly this — "the
         * three-bucket classification: migrate / discard / ask — Rafi approves
         * any discard list" — and it carries the before and the after. So the
         * owner now reads the field, the product it was on, and the actual
         * string being dropped, five examples per field with a full count
         * beside it. That is a discard list somebody can approve. A count of
         * anonymous drops is not.
         */
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

        /*
         * ── AND WHAT IS IMPORTED BUT WILL NOT COME OUT THE WAY IT WENT IN ───
         *
         * A Yoast title or description is a TEMPLATE. Four of Yoast's tokens
         * resolve on this storefront — %%title%%, %%sep%%, %%sitename%%,
         * %%page%% — and every other one is DELETED by TitleTemplate::render()
         * on its way to the page. So `Buy %%title%% for %%currentyear%%` is
         * imported intact, is perfectly valid in the column, and publishes as
         * "Buy Dokdo Toner for".
         *
         * That is neither a rejection (the row imports fine) nor a discard (the
         * value is in the database) — it is the definition of an ADJUSTMENT:
         * imported, and not what the export said. It is reported per FIELD and
         * per TOKEN rather than per row, so an export whose whole catalogue
         * shares one template produces one line with a count of 671 and five
         * examples, which is what the owner needs to decide whether to fix the
         * template before importing or the four odd products afterwards.
         *
         * The value is still stored verbatim. Expanding tokens at import time
         * would freeze this store's name into every row — see rule 3 on
         * App\Support\YoastSeo — so the answer is to SAY SO, not to rewrite
         * the owner's templates on the way past.
         */
        foreach (['title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc'] as $key => $meta) {
            if (! isset($fragment[$key]) || ! is_string($fragment[$key])) {
                continue;
            }

            $template = $fragment[$key];
            $unresolvable = YoastSeo::unresolvableTokens($template);

            if ($unresolvable === []) {
                continue;
            }

            $report->adjusted(
                '%%'.implode('%%, %%', $unresolvable).'%% is a Yoast placeholder this storefront does not '
                .'resolve — it is imported as written and DELETED from the page when the tag is rendered, '
                .'which shortens the '.($key === 'title' ? 'title' : 'description').' rather than showing it',
                $row->line,
                (string) $wcId,
                $meta,
                $template,
                YoastSeo::afterStripping($template),
            );
        }

        /*
         * ── THE BARCODES, WHICH HAD EVERY PIECE BUT THE WIRE ────────────────
         *
         * `wpseo_global_identifier_values` is the Yoast WooCommerce SEO add-on's
         * identifier map. Until now this shop had all four parts of importing it
         * and none of the joins: the export carries the column (Lane GE made the
         * SELECT `LIKE 'wpseo_%'` as well as `LIKE '_yoast_wpseo_%'` precisely
         * so it would be there), `YoastTiers::gtinFrom()` unpicks both the JSON
         * and the PHP-serialised shape and validates the check digit,
         * `products.gtin` exists, and `App\Support\Seo` publishes it into the
         * Product node the moment it is not null. YoastTiers said so in its own
         * report line -- "NOT imported, AND THERE IS SOMEWHERE FOR IT TO GO".
         *
         * WHY IT IS WORTH THE THREE LINES. Google matches a product listing on
         * its GTIN. Without one, 671 products publish as anonymous offers; with
         * one they are the same product Google already knows, which is the
         * difference between appearing in a shopping result and not.
         *
         * NEVER OVERWRITES. Same rule as the `seo` merge below and for the same
         * reason: the product editor writes this column too, and a barcode the
         * owner typed in this shop beats one carried over from a five-year-old
         * Yoast field. A row whose map holds only an MPN, or a number that fails
         * its own check digit, comes back null from gtinFrom() and leaves the
         * column alone -- "somebody else's barcode" is the failure being avoided,
         * not "no barcode".
         */
        $gtin = YoastTiers::gtinFrom($cells);
        $gtinOutcome = 'unchanged';

        if ($gtin !== null && ($product->gtin === null || $product->gtin === '')) {
            $gtinOutcome = $context->apply($product, ['gtin' => $gtin]);
        }

        if ($fragment === []) {
            /*
             * Every Yoast column on this row was blank — which is the COMMON
             * case in a real export, not an error: most products in a Woo store
             * have never had their SEO tab opened. Recorded as unchanged so the
             * tallies are honest about how much of the file carried anything.
             */
            /*
             * 'unchanged' UNLESS THE BARCODE WAS WRITTEN. A product whose Yoast
             * tab was never opened but which carries an identifier map is a
             * real and common row -- the add-on writes that field from the
             * product's own Inventory tab -- and recording it as unchanged
             * after writing to it would make the entity's tallies disagree with
             * what is in the table, which is the one thing the count check
             * after each bucket exists to catch.
             */
            $context->record($this->name(), $gtinOutcome);

            return;
        }

        // false: the owner's typing wins. See the header — reversing it is a
        // one-field change on ImportOptions, which this lane does not own.
        $merged = YoastSeo::merge($product->seo, $fragment, overwrite: false);

        $seoOutcome = $context->apply($product, ['seo' => $merged]);

        $context->record($this->name(), $seoOutcome === 'unchanged' ? $gtinOutcome : $seoOutcome);
    }
}
