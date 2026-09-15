<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Database\Eloquent\Builder;

/**
 * `categories.slug`, `brands.slug` and `products.slug` are all UNIQUE, and this
 * decides what happens when the slug an imported row wants is already taken.
 *
 * THERE ARE TWO COMPLETELY DIFFERENT SITUATIONS HERE and conflating them is how
 * an importer merges two things that are not the same thing:
 *
 *  - The holder came from a DIFFERENT WooCommerce term or post. Two real
 *    things want one slug and only one can have it. Always refused. Which one
 *    keeps it is a content decision and the importer has no basis for making
 *    it.
 *
 *  - The holder has a NULL external id, so it demonstrably did not come from
 *    WooCommerce. In this store that is almost always the demo catalogue:
 *    2026_08_27_100000_seed_demo_catalogue runs on every install, production
 *    included, and seeds brands slugged `cosrx` and `beauty-of-joseon` and
 *    categories slugged `cleansers`, `toners`, `serums` — all of them real
 *    things this store really sells. Left alone, the genuine COSRX term is
 *    refused on the first real import, and so is every other brand the demo
 *    seed happened to guess right.
 *
 * The second case is still refused BY DEFAULT, because adopting a row by its
 * slug is exactly what rule 1 of docs/IMPORT-READINESS.md forbids: slugs get
 * edited, and a wrong adoption is silent. With `--adopt-by-slug` the importer
 * claims it instead — writing the WooCommerce id onto the placeholder so that
 * every subsequent pass matches it on the external id like everything else —
 * and reports each adoption by name, so the owner reads a list of exactly which
 * placeholder rows became which WooCommerce terms rather than a count.
 */
final class SlugGuard
{
    /**
     * Resolve the slug conflict, if there is one.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  the table, already scoped (e.g. withTrashed)
     * @param  string  $externalColumn  'source_term_id' or 'wc_id'
     * @return int|null the id of an existing row this import should ADOPT, or
     *                  null if there is no conflict
     *
     * @throws RowRejected when the conflict cannot be resolved without a decision
     */
    public static function resolve(
        Builder $query,
        string $table,
        string $externalColumn,
        string $slug,
        int $externalId,
        ImportContext $context,
        string $reportEntity,
    ): ?int {
        $holder = (clone $query)
            ->where('slug', $slug)
            ->where(function (Builder $q) use ($externalColumn, $externalId): void {
                $q->whereNull($externalColumn)->orWhere($externalColumn, '!=', $externalId);
            })
            ->first(['id', $externalColumn]);

        if ($holder === null) {
            return null;
        }

        if ($holder->{$externalColumn} !== null) {
            throw RowRejected::because(
                "slug '".$slug."' already belongs to ".$externalColumn.' '.$holder->{$externalColumn}
                .' — '.$table.'.slug is unique, so only one of the two can keep it. '
                .'Rename one of them in WooCommerce and re-export.'
            );
        }

        if (! $context->options->adoptBySlug) {
            throw RowRejected::because(
                "slug '".$slug."' is already held by a row that did not come from WooCommerce (id "
                .$holder->id.') — most likely the demo catalogue that 2026_08_27_100000_seed_demo_catalogue '
                .'seeds on every install. Re-run with --adopt-by-slug to have the import claim that row, '
                .'or delete it first.'
            );
        }

        $context->report->for($reportEntity)->note(
            'adopted the existing '.$table.' row for slug "'.$slug.'" (id '.$holder->id
            .', no WooCommerce origin) as '.$externalColumn.' '.$externalId
        );

        return (int) $holder->id;
    }
}
