<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Tag;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WooCommerce `product_tag` terms, and the products filed under them.
 *
 * `docs/FV-IMPORT-AT-VOLUME.md` §11 lists `tags` and `product_tag` as simply
 * absent after a clean full-volume import: "Product tags, and the tag archives
 * Google has indexed."
 *
 * ONE FILE CARRIES BOTH HALVES, and that is the export contract's decision
 * rather than this importer's: `tags.csv` is the terms WITH a `product_ids`
 * column, because "a tags file without the pivot needs a second file before
 * anything can use it". So a tag row writes a `tags` row and reconciles that
 * tag's whole membership in `product_tag`, and there is no second pass to
 * arrange.
 *
 * MATCHED ON `source_term_id`, never on the slug, for the reason
 * CategoryImporter states: slugs get edited in wp-admin between the full import
 * and the cutover delta, and an importer matching on one files the edited tag
 * as a brand new row.
 *
 * THE MEMBERSHIP IS RECONCILED, NOT APPENDED. A product removed from a tag in
 * WooCommerce between two passes has to come off it here, so the pivot is
 * computed as a difference in both directions -- exactly the shape
 * ProductImporter::syncCategories() uses, and for its second reason as well:
 * re-inserting a pair that is already there fails on a composite primary key
 * rather than duplicating, so the difference is the only form that is also
 * idempotent.
 *
 * WHAT THIS SCHEMA HAS NO HOME FOR. `tags` is four columns -- slug, name,
 * source_term_id, timestamps. The export's `description`, `parent` and `count`
 * have nowhere to go: this application has no tag description, tags do not
 * nest, and `count` is WordPress's own cached figure which the pivot makes
 * derivable anyway. They are deliberately NOT read, so the runner's discard
 * channel names all three with a sample value in one consolidated line -- which
 * is the designed way for the owner to approve a loss rather than discover it
 * (docs/FV-IMPORT-AT-VOLUME.md §10). Reading them into variables nobody writes
 * would remove them from that list and lose nothing less.
 */
final class TagImporter extends EntityImporter
{
    public function name(): string
    {
        return 'tags';
    }

    public function conventionalFile(): string
    {
        return 'tags.csv';
    }

    /**
     * Tags the export supplied.
     *
     * Nothing seeds this table -- 2026_08_27_100000_seed_demo_catalogue seeds
     * products, brands and categories and no tags at all -- so on this shop the
     * whole table is imported. It is still counted on the external id, because
     * the owner can add a tag in the admin and a count that included it would
     * report a surplus that is not one.
     */
    public function countImported(): ?int
    {
        return Tag::query()->whereNotNull('source_term_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = $row->requireId('term_id', 'term_id', 'id', 'tag_id');
        $name = $row->requireText('name', 'name', 'title');

        $slug = $row->text('slug') ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
        }

        $tag = Tag::query()->where('source_term_id', $termId)->first();

        if ($tag === null) {
            $adopt = SlugGuard::resolve(
                Tag::query(), 'tags', 'source_term_id', $slug, $termId, $context, $this->name(),
            );

            $tag = $adopt === null ? new Tag : Tag::query()->findOrFail($adopt);
        } elseif ($tag->slug !== $slug) {
            /*
             * A TAG THIS IMPORT ALREADY OWNS, RENAMED IN WP-ADMIN ONTO A SLUG
             * ANOTHER TAG HOLDS.
             *
             * SlugGuard is only reached where the row is new, which is the
             * shape BrandImporter and CategoryImporter share -- so this case
             * goes straight to the database, and `tags.slug` is UNIQUE. The
             * runner does catch the driver's refusal and put it in the report,
             * but what it puts there is the whole UPDATE statement with its
             * bound values and no word about which two tags are fighting.
             *
             * Adoption is deliberately NOT offered: this term already has a row
             * here, and claiming a second one would merge two tags whose
             * product membership is kept separately.
             */
            $this->refuseSlugMove($slug, $termId);
        }

        $outcome = $context->apply($tag, [
            'source_term_id' => $termId,
            'slug' => $slug,
            'name' => $name,
        ]);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $termId, (int) $tag->id);

        $pivotChanged = $this->syncProducts((int) $tag->id, $this->productIds($row, $context));

        /*
         * A tag whose own three columns did not move but whose membership did
         * IS an update. ProductImporter carries the same correction for
         * category membership and gives the reason: reporting it as unchanged
         * would make the idempotency evidence a lie, and "unchanged on the
         * second pass" is the only evidence this importer offers that it did
         * the same thing twice.
         */
        if ($pivotChanged && $outcome === 'unchanged') {
            $report = $context->report->for($this->name());
            $report->unchanged--;
            $report->updated();
        }
    }

    /**
     * @throws RowRejected
     */
    private function refuseSlugMove(string $slug, int $termId): void
    {
        $holder = Tag::query()
            ->where('slug', $slug)
            ->where(function ($q) use ($termId): void {
                $q->whereNull('source_term_id')->orWhere('source_term_id', '!=', $termId);
            })
            ->first(['id', 'source_term_id']);

        if ($holder === null) {
            return;
        }

        throw RowRejected::because(
            "slug '".$slug."' is already held by tag ".$holder->id
            .($holder->source_term_id === null
                ? ' (no WooCommerce origin)'
                : ' (term '.$holder->source_term_id.')')
            .', and term '.$termId.' already has a row here — tags.slug is unique, so only one of the '
            .'two can keep it. Rename one of them in WooCommerce and re-export.'
        );
    }

    /**
     * The local product ids this tag names, with the ones that are not in this
     * import reported ONCE for the row rather than once each.
     *
     * A tag on a six-year-old shop can name a product that was trashed, and
     * ProductImporter refuses trashed products by name. One note per missing
     * product would put hundreds of identical lines in a report the owner is
     * meant to read; the count and a sample is the same fact in a form that
     * survives being read.
     *
     * @return list<int>
     */
    private function productIds(Row $row, ImportContext $context): array
    {
        $wcIds = array_values(array_unique(array_map(
            'intval',
            array_filter($row->list(',', 'product_ids', 'products', 'product_wc_ids'), 'is_numeric'),
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
                'tag "'.($row->raw('slug') ?? $row->raw('name') ?? '?').'" names '.count($missing)
                .' product'.(count($missing) === 1 ? '' : 's').' that '.(count($missing) === 1 ? 'is' : 'are')
                .' not in this import and '.(count($missing) === 1 ? 'was' : 'were').' not filed under it — '
                .'wc_id '.implode(', ', array_slice($missing, 0, 10))
                .(count($missing) > 10 ? ' and '.(count($missing) - 10).' more' : '')
            );
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $productIds
     * @return bool whether anything actually moved
     */
    private function syncProducts(int $tagId, array $productIds): bool
    {
        $existing = DB::table('product_tag')
            ->where('tag_id', $tagId)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $toAdd = array_diff($productIds, $existing);
        $toRemove = array_diff($existing, $productIds);

        if ($toAdd === [] && $toRemove === []) {
            return false;
        }

        if ($toAdd !== []) {
            DB::table('product_tag')->insert(array_map(
                static fn (int $productId): array => ['tag_id' => $tagId, 'product_id' => $productId],
                array_values($toAdd),
            ));
        }

        if ($toRemove !== []) {
            DB::table('product_tag')
                ->where('tag_id', $tagId)
                ->whereIn('product_id', array_values($toRemove))
                ->delete();
        }

        return true;
    }
}
