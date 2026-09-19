<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WooCommerce's global `pa_*` attributes, their terms, and which products offer
 * which term.
 *
 * `docs/FV-IMPORT-AT-VOLUME.md` §11: "Brands are imported *because* someone
 * noticed `pa_brands` was an attribute. The other attributes are what the
 * filters on a category page are built from." Three tables were empty after a
 * clean full-volume import -- `attributes`, `attribute_values` and
 * `product_attribute_value` -- and one file feeds all three.
 *
 * ── ONE ROW IS ONE TERM, WITH THE ATTRIBUTE REPEATED ON IT ──────────────────
 *
 * That is the export contract's shape and the reason it gives is the right one:
 * "Two files would need an order and a join; one file with the label repeated
 * is the same information and cannot be half-imported." So every row upserts
 * the attribute, then its value, then that value's product membership. The
 * attribute upsert is memoised per run, so a 93-term attribute costs one write,
 * not 93.
 *
 * ── THE FOUR PLACES THE OBVIOUS COLUMN IS THE WRONG ONE ─────────────────────
 *
 * 1. THE ATTRIBUTE'S SLUG IS `attribute_name`, NOT `taxonomy`. The taxonomy is
 *    `pa_size`; this schema's own column comment says the slug is `size`
 *    ("color, size, shades") and Admin\AttributesApiController derives
 *    `filter_size` from it. Importing `pa_size` would put `filter_pa_size` in
 *    front of the owner on the Attributes screen and in every URL built from
 *    it. `attribute_name` is WooCommerce's own bare name and the exporter falls
 *    back to `substr($taxonomy, 3)` where the definition row is missing; this
 *    does the same, so a `pa_*` taxonomy with no row in
 *    `wp_woocommerce_attribute_taxonomies` still lands correctly.
 *
 * 2. THE ATTRIBUTE'S NAME IS `attribute_label`, NOT `name`. `name` on this row
 *    is the TERM's name -- "50ml" -- because one row is one term. Taking it
 *    would name the attribute after whichever of its terms happened to be read
 *    last, so `Size` would be called `100ml`.
 *
 * 3. `attribute_public` IS NOT `is_filterable`, and this is the one that would
 *    have been silent. It is WooCommerce's "enable archives" flag -- whether
 *    `/pa_size/50ml/` was ever a page -- which is what the permalinks stage
 *    reads it for. `is_filterable` is this shop's "show it in the storefront
 *    filter panel". WooCommerce's own layered-nav filter works perfectly well
 *    on an attribute with no archive, and its default for a new attribute is
 *    `attribute_public = 0` -- so mapping one onto the other would arrive at
 *    "no attribute on this shop is filterable" for a shop whose filters all
 *    worked. It is left unread, which puts it in the runner's discard list with
 *    its value, where the owner can see the flag and the decision rather than
 *    neither.
 *
 * 4. `count` IS NOT `position`. It is WordPress's cached membership count, and
 *    ordering the size list by how many products use each size would put 100ml
 *    above 50ml on a shop that sells more of the large one. The export carries
 *    no ordering for terms at all -- `attribute_orderby` names the RULE
 *    (`menu_order`, `name`, `id`) and not the values -- so `position` is left
 *    at its schema default and Attribute::values() falls back to name order.
 *    Inventing an order from the file's row order would also break under
 *    resume, because a delta export carrying one term would renumber it to 0.
 *
 * ── `is_variation_axis` IS SET BY THE VARIATIONS FILE, NOT BY THIS ONE ──────
 *
 * Nothing in `attributes.csv` says whether an attribute is used to build
 * variations: in WooCommerce that is a per-product decision, stored on the
 * product, and the only durable evidence of it is that a variation is pinned to
 * one of the attribute's terms. VariationImporter sets the flag when it makes
 * that pin, which is the one place the fact is actually known. Registered in
 * that order for exactly this reason.
 *
 * ── MATCHING, AND THE COLUMN THIS LANE ADDED FOR IT ─────────────────────────
 *
 * Terms are matched on `source_term_id`, which 2026_09_22_000000_add_import_-
 * external_ids made unique for this table by name. Attributes had no external
 * id at all until 2026_11_24_000000_add_attribute_source_id, whose header says
 * why the slug alone was not safe here: Admin\AttributesApiController lets the
 * owner create an attribute by hand, so "Size" typed into that screen and
 * `pa_size` arriving from WooCommerce are two different things wanting one
 * unique slug, and without an external id the importer cannot tell them apart.
 */
final class AttributeImporter extends EntityImporter
{
    /**
     * Attribute slug => local id, for this run.
     *
     * Per-instance and not static, for the reason ProductImporter gives about
     * its own: ImportRunner::entities() builds a fresh importer per run, so it
     * cannot leak between runs and a dry run's rollback cannot leave it holding
     * ids that no longer exist.
     *
     * @var array<string, int>
     */
    private array $attributes = [];

    public function name(): string
    {
        return 'attributes';
    }

    public function conventionalFile(): string
    {
        return 'attributes.csv';
    }

    /**
     * TERMS, not attributes -- because one row of this file is one term.
     *
     * The verification contract is "rows in against rows out", and the rows are
     * terms. Counting `attributes` here would report 2 against a 2-row file
     * that happens to describe one attribute and read as a coincidence, or 1
     * against 93 and read as a disaster.
     */
    public function countImported(): ?int
    {
        return AttributeValue::query()->whereNotNull('source_term_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = $row->requireId('term_id', 'term_id', 'id');
        $termName = $row->requireText('name', 'name', 'title');
        $termSlug = $row->text('slug') ?? Str::slug($termName);

        if ($termSlug === '') {
            throw RowRejected::because("name '".$termName."' does not reduce to a usable slug");
        }

        $attributeId = $this->attribute($row, $context);

        $value = AttributeValue::query()->where('source_term_id', $termId)->first();

        /*
         * THE COLLISION IS CHECKED ON EVERY ROW, NOT ONLY ON A NEW ONE, and
         * that was a real hole found by writing the test for it rather than by
         * reading. Checking only where the term is new leaves the case where
         * the term ALREADY exists here and the export has since renamed it onto
         * a slug one of its siblings holds: the guard never runs, the UPDATE
         * goes to the database, and the row comes back as
         * "the database refused this row: SQLSTATE[23000] ... UNIQUE constraint
         * failed: attribute_values.attribute_id, attribute_values.slug" -- with
         * the whole statement and its bound values in it, and nothing telling
         * the owner which two terms are fighting or what to do.
         *
         * An existing term may not ADOPT another row, whatever the flag says:
         * it already has a row of its own, and claiming a second one would
         * merge two terms that variants are pinned to separately. So the
         * adoption path is only offered where there is nothing to merge.
         */
        if ($value === null) {
            $adopt = $this->resolveValueSlug($attributeId, $termSlug, $termId, $context, true);

            $value = $adopt === null ? new AttributeValue : AttributeValue::query()->findOrFail($adopt);
        } else {
            $this->resolveValueSlug($attributeId, $termSlug, $termId, $context, false);
        }

        $outcome = $context->apply($value, [
            'attribute_id' => $attributeId,
            'source_term_id' => $termId,
            'slug' => $termSlug,
            'name' => $termName,
        ]);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $termId, (int) $value->id);

        $pivotChanged = $this->syncProducts((int) $value->id, $this->productIds($row, $context));

        // Same correction, and the same reason, as ProductImporter's on
        // category membership: a row whose own columns did not move but whose
        // pivot did is an update, and calling it unchanged would make the
        // second-pass evidence a lie.
        if ($pivotChanged && $outcome === 'unchanged') {
            $report = $context->report->for($this->name());
            $report->unchanged--;
            $report->updated();
        }
    }

    /**
     * The local `attributes` id for this row's taxonomy, created on first sight.
     *
     * @throws RowRejected
     */
    private function attribute(Row $row, ImportContext $context): int
    {
        $taxonomy = $row->text('taxonomy', 'attribute_taxonomy');
        $slug = $row->text('attribute_name');

        if ($slug === null && $taxonomy !== null) {
            // WooCommerce's taxonomy name is the attribute name with `pa_` in
            // front of it. Same fallback the exporter uses where the definition
            // row is missing, so the two ends agree on the one value.
            $slug = preg_replace('/^pa_/', '', $taxonomy) ?? $taxonomy;
        }

        $slug = $slug === null ? null : Str::slug($slug);

        if ($slug === null || $slug === '') {
            throw RowRejected::because(
                'this row names no attribute — neither attribute_name nor taxonomy is readable, so there is '
                .'nothing to hang the term on (attribute_values.attribute_id is NOT NULL)'
            );
        }

        if (isset($this->attributes[$slug])) {
            return $this->attributes[$slug];
        }

        /*
         * `attribute_id` is empty where `wp_woocommerce_attribute_taxonomies`
         * has no row for a taxonomy that still has terms -- a real state on a
         * six-year-old shop, and the reason the column this lane added is
         * nullable.
         *
         * WITH AN ID, this is matched like every other imported row: on the
         * external id, with SlugGuard answering a slug collision.
         *
         * WITHOUT ONE, the slug is the only handle there is, and pretending
         * otherwise would break the second pass rather than protect it -- the
         * attribute this importer created last time carries a NULL external id,
         * which is indistinguishable from one the owner typed into Catalog →
         * Attributes, so putting it through SlugGuard would refuse this shop's
         * own previous import on every re-run. It is matched on the slug and
         * the owner is TOLD, in the adjustment channel they already read,
         * because that match is the one docs/IMPORT-READINESS.md rule 1 warns
         * about and it is happening for a reason they can act on: add the
         * attribute properly in WooCommerce and re-export.
         */
        $sourceId = $row->id('attribute_id', 'attribute_id');

        if ($sourceId !== null) {
            $attribute = Attribute::query()->where('source_attribute_id', $sourceId)->first();

            if ($attribute === null) {
                $attribute = $this->claimAttributeSlug($slug, $sourceId, $context);
            } elseif ($attribute->slug !== $slug) {
                // The same hole as the term-level one below, one table up: an
                // attribute this import already owns, whose slug the export has
                // since changed onto one another attribute holds. Checked here
                // so the refusal names the two rows instead of the database
                // returning the UPDATE statement.
                $this->refuseSlugMove($slug, $sourceId);
            }
        } else {
            $attribute = Attribute::query()->where('slug', $slug)->first();

            if ($attribute !== null) {
                $context->report->for($this->name())->adjusted(
                    'an attribute matched on its slug because the export carried no WooCommerce attribute id '
                    .'for it -- a slug edited in wp-admin between two passes would import as a second '
                    .'attribute and split this one\'s terms across the two',
                    $row->line,
                    $this->identify($row),
                    'attribute_id',
                    '(none in the export)',
                    'matched attributes.id '.$attribute->id.' on slug "'.$slug.'"',
                );
            } else {
                $attribute = new Attribute;
            }
        }

        $label = $row->text('attribute_label');

        $attributes = [
            'source_attribute_id' => $sourceId,
            'slug' => $slug,
            'name' => $label ?? Str::headline($slug),
        ];

        /*
         * `is_filterable` and `is_variation_axis` are NOT in this array, and
         * their absence is the point.
         *
         * Both carry a schema default that is right for a new row, and both are
         * things the owner can change on the Attributes screen. Writing them on
         * every pass would hand the owner's decision back to an export that
         * does not carry it: an attribute the owner unticked would be re-ticked
         * by the next delta, silently, with the report saying "updated".
         * `is_variation_axis` is set once, by VariationImporter, when a variant
         * is actually pinned to one of these terms.
         */
        $existed = $attribute->exists;
        $outcome = $context->apply($attribute, $attributes);

        /*
         * NOT recorded in any tally, deliberately.
         *
         * The verification contract is rows in against rows out and one row of
         * this file is one TERM. Counting the attribute write as well would
         * make a two-row file report three creations against two rows in the
         * database, which reads as a bug in the arithmetic rather than as the
         * second table this entity legitimately writes. It goes in the notes,
         * which both front ends already print.
         */
        if (! $existed) {
            $context->report->for($this->name())->note(
                'created the attribute "'.$attributes['name'].'" ('.$slug.') that this file\'s terms belong to'
            );
        } elseif ($outcome === 'updated') {
            $context->report->for($this->name())->note(
                'updated the attribute "'.$attributes['name'].'" ('.$slug.')'
            );
        }

        return $this->attributes[$slug] = (int) $attribute->id;
    }

    /**
     * An attribute this import already owns cannot move onto a slug something
     * else holds.
     *
     * `attributes.slug` is UNIQUE, and adoption is not on offer here for the
     * same reason it is not on offer for an existing term: this attribute
     * already has a row, so claiming a second one would merge two attributes
     * whose values are hung off them separately.
     *
     * @throws RowRejected
     */
    private function refuseSlugMove(string $slug, int $sourceId): void
    {
        $holder = Attribute::query()
            ->where('slug', $slug)
            ->where(function ($q) use ($sourceId): void {
                $q->whereNull('source_attribute_id')->orWhere('source_attribute_id', '!=', $sourceId);
            })
            ->first(['id', 'source_attribute_id']);

        if ($holder === null) {
            return;
        }

        throw RowRejected::because(
            "slug '".$slug."' is already held by attribute ".$holder->id
            .($holder->source_attribute_id === null
                ? ' (no WooCommerce origin)'
                : ' (WooCommerce attribute '.$holder->source_attribute_id.')')
            .', and WooCommerce attribute '.$sourceId.' already has a row here — attributes.slug is '
            .'unique, so only one of the two can keep it. Rename one of them in WooCommerce and '
            .'re-export.'
        );
    }

    /**
     * Who holds this attribute slug, and may this import have it?
     *
     * SlugGuard verbatim, because the situation is verbatim: a holder carrying
     * a DIFFERENT WooCommerce attribute id is two real things wanting one
     * unique slug and is refused; a holder with a null one did not come from
     * WooCommerce -- here that means the owner typed it into Catalog →
     * Attributes -- and is refused by default, adopted under --adopt-by-slug,
     * and reported by name either way.
     *
     * The no-external-id row is passed through the guard as well, with the term
     * id standing in, so a taxonomy whose definition row is missing still gets
     * the collision answered rather than silently adopted.
     *
     * @throws RowRejected
     */
    private function claimAttributeSlug(string $slug, ?int $sourceId, ImportContext $context): Attribute
    {
        $adopt = SlugGuard::resolve(
            Attribute::query(),
            'attributes',
            'source_attribute_id',
            $slug,
            $sourceId ?? 0,
            $context,
            $this->name(),
        );

        return $adopt === null ? new Attribute : Attribute::query()->findOrFail($adopt);
    }

    /**
     * Who holds this term slug within this attribute, and may this import
     * have it?
     *
     * NOT SlugGuard, and the difference is the unique key. `attribute_values`
     * is unique on the PAIR (attribute_id, slug) -- "50ml" under Size and
     * "50ml" under Sample Size are two legitimate rows -- and SlugGuard asks
     * about a bare `slug` column. Asking its question here would refuse a term
     * over a collision that the database does not have.
     *
     * The answers are the same three, for the same reasons: a holder from a
     * different WooCommerce term is refused, a holder with no WooCommerce
     * origin is refused by default and adopted under --adopt-by-slug, and no
     * holder at all is a new row.
     *
     * @return int|null the id of an existing row to adopt, or null for a new one
     *
     * @throws RowRejected
     */
    private function resolveValueSlug(
        int $attributeId,
        string $slug,
        int $termId,
        ImportContext $context,
        bool $mayAdopt,
    ): ?int {
        $holder = AttributeValue::query()
            ->where('attribute_id', $attributeId)
            ->where('slug', $slug)
            ->where(function ($q) use ($termId): void {
                $q->whereNull('source_term_id')->orWhere('source_term_id', '!=', $termId);
            })
            ->first(['id', 'source_term_id']);

        if ($holder === null) {
            return null;
        }

        if ($holder->source_term_id !== null) {
            throw RowRejected::because(
                "slug '".$slug."' already belongs to term ".$holder->source_term_id
                .' under the same attribute — attribute_values is unique on (attribute_id, slug), so only '
                .'one of the two can keep it. Rename one of them in WooCommerce and re-export.'
            );
        }

        if (! $mayAdopt) {
            throw RowRejected::because(
                "slug '".$slug."' is already held by another term of this attribute (id ".$holder->id
                .', with no WooCommerce origin) and term '.$termId.' already has a row here — so the slug '
                .'cannot be moved onto it without merging two terms that variants are pinned to '
                .'separately. Rename one of them in WooCommerce and re-export, or delete the row that '
                .'has no WooCommerce origin.'
            );
        }

        if (! $context->options->adoptBySlug) {
            throw RowRejected::because(
                "slug '".$slug."' is already held by a term that did not come from WooCommerce (id "
                .$holder->id.') — most likely one added in Catalog → Attributes. Re-run with '
                .'--adopt-by-slug to have the import claim that row, or delete it first.'
            );
        }

        $context->report->for($this->name())->note(
            'adopted the existing attribute_values row for slug "'.$slug.'" (id '.$holder->id
            .', no WooCommerce origin) as source_term_id '.$termId
        );

        return (int) $holder->id;
    }

    /**
     * The local product ids this term names, with the ones that are not in the
     * import reported ONCE for the row.
     *
     * @return list<int>
     */
    private function productIds(Row $row, ImportContext $context): array
    {
        $wcIds = array_values(array_unique(array_map(
            'intval',
            array_filter($row->list(',', 'product_ids', 'products'), 'is_numeric'),
        )));

        $ids = [];
        $missing = [];

        foreach ($wcIds as $wcId) {
            $localId = $context->localId('products', $wcId);

            if ($localId === null) {
                $missing[] = $wcId;

                continue;
            }

            $ids[] = $localId;
        }

        if ($missing !== []) {
            $context->report->for($this->name())->note(
                'term "'.($row->raw('slug') ?? $row->raw('name') ?? '?').'" is offered by '.count($missing)
                .' product'.(count($missing) === 1 ? '' : 's').' that '.(count($missing) === 1 ? 'is' : 'are')
                .' not in this import — wc_id '.implode(', ', array_slice($missing, 0, 10))
                .(count($missing) > 10 ? ' and '.(count($missing) - 10).' more' : '')
            );
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $productIds
     * @return bool whether anything actually moved
     */
    private function syncProducts(int $valueId, array $productIds): bool
    {
        $existing = DB::table('product_attribute_value')
            ->where('attribute_value_id', $valueId)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $toAdd = array_diff($productIds, $existing);
        $toRemove = array_diff($existing, $productIds);

        if ($toAdd === [] && $toRemove === []) {
            return false;
        }

        if ($toAdd !== []) {
            DB::table('product_attribute_value')->insert(array_map(
                static fn (int $productId): array => [
                    'attribute_value_id' => $valueId,
                    'product_id' => $productId,
                ],
                array_values($toAdd),
            ));
        }

        if ($toRemove !== []) {
            DB::table('product_attribute_value')
                ->where('attribute_value_id', $valueId)
                ->whereIn('product_id', array_values($toRemove))
                ->delete();
        }

        return true;
    }
}
