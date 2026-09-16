<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one definition of "is this product on the storefront right now".
 *
 * WHY SCHEDULING IS A READ-TIME TEST AND NOT A CRON.
 *
 * This host is shared hosting with no shell access, no cron and no queue
 * worker — CLAUDE.md says so, and the import screen exists precisely because
 * `php artisan` cannot be run there. So a scheduled publish cannot be a job
 * that flips a column at the appointed minute; there is nothing to run it.
 *
 * The precedent is already in this codebase and it is the right one:
 * Product::effectivePrice() honours sale_starts_at / sale_ends_at by comparing
 * them to now() at the moment the price is read, with no scheduler anywhere. A
 * scheduled publish works the same way. `published_at` is a timestamp, and the
 * product is visible when that timestamp has passed. Nothing has to fire; the
 * product becomes live because the clock moved, which is also why it is correct
 * the first time anybody looks rather than the first time a job happens to run.
 *
 * NULL MEANS "ALWAYS HAS BEEN PUBLISHED", which is what makes this safe to add
 * to a table with rows already in it. Every existing product has published_at
 * NULL and stays exactly as visible as it was; only a row an operator
 * deliberately schedules ever carries a future value.
 *
 * WHY THE PREDICATE LIVES HERE AND NOT ONLY IN scopeVisible().
 *
 * Most of the storefront asks Product::visible(), and that scope calls
 * schedule() below, so those ~40 call sites are correct for free. But not
 * everything goes through Eloquent. Store\SeoFilesController builds the
 * sitemap from `DB::table('products')` with a hand-written status filter, and a
 * model scope cannot reach a query builder. A sitemap that lists a product
 * whose page 404s is a soft-404 reported in Search Console, which is the exact
 * opposite of what scheduling a launch is for.
 *
 * raw() is that controller's one-line adoption path, and it is written against
 * the query builder so it works for either. It is column-guarded the same way
 * that controller already guards its own filters, so it is safe to call against
 * a schema where the migration has not run yet.
 */
final class ProductVisibility
{
    /**
     * The scheduling gate alone: published now, or published at no particular
     * time, but not published later.
     *
     * Grouped in a closure so the OR cannot leak out and disjoin whatever
     * conditions the caller has already put on the query. `->where(...)
     * ->orWhereNull(...)` written flat at the top level of a query that already
     * filters on status turns `A AND B` into `A AND (B) OR (C)`, which matches
     * every scheduled product in the table. The closure is the parentheses.
     */
    public static function schedule(mixed $query, ?string $column = 'published_at'): mixed
    {
        $now = now()->format('Y-m-d H:i:s');

        return $query->where(function ($q) use ($column, $now) {
            $q->whereNull($column)->orWhere($column, '<=', $now);
        });
    }

    /**
     * Everything the storefront checks, for a caller that is not using the
     * Eloquent model — `DB::table('products')`, a join, a raw count.
     *
     * Each condition is guarded on the column existing, matching the style
     * Store\SeoFilesController already uses, so this is callable from a
     * migration or against a partially-migrated schema without exploding.
     *
     * $table qualifies the column names for a caller that has joined products
     * to something else and would otherwise get an ambiguous-column error.
     *
     * ONE READ OF THE COLUMN LIST, NOT ONE PER COLUMN.
     *
     * The four guards below used to be four Schema::hasColumn() calls, and each
     * of those is a round trip to information_schema — four questions about one
     * table, asked one column at a time, on every call. The cost landed on real
     * pages: /korean-skincare-brands is in the main navigation and ran SIX
     * queries of which these were FOUR, and /sitemap.xml — which a crawler
     * fetches far more often than a shopper fetches anything — ran eighteen of
     * which twelve were introspection. Two thirds of the brand index's database
     * work was the database describing itself.
     *
     * information_schema is also the worst place to spend a query on shared
     * hosting. It is not a table but a view over server-wide metadata, so on a
     * host where one MySQL instance carries every tenant's schemas the cost
     * scales with the NEIGHBOURS' tables, not with this shop's.
     *
     * STILL READ FRESH ON EVERY CALL, and deliberately not memoised in a
     * static. The comment above says raw() is callable from a migration and
     * against a partially-migrated schema, and that is exactly where a
     * process-level memo answers with the shape the table had before the ALTER
     * — the Setting::map() trap CLAUDE.md records, moved into the one helper
     * that must survive a schema mid-change. One read per call is correct under
     * every caller and is still a quarter of the queries.
     */
    public static function raw(mixed $query, string $table = 'products'): mixed
    {
        $columns = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('products'));

        $has = static fn (string $column): bool => isset($columns[$column]);

        $col = static fn (string $column): string => $table === '' ? $column : $table . '.' . $column;

        if ($has('status')) {
            $query->where($col('status'), '=', 'publish');
        }

        if ($has('is_visible')) {
            $query->where($col('is_visible'), '=', true);
        }

        if ($has('deleted_at')) {
            $query->whereNull($col('deleted_at'));
        }

        if ($has('published_at')) {
            self::schedule($query, $col('published_at'));
        }

        return $query;
    }

    /**
     * The same decision for a model already in memory, without a query.
     *
     * Used by the IndexNow hook, which has the saved row and needs to know
     * whether telling Google to crawl it right now would be telling the truth.
     */
    public static function isLive(mixed $product): bool
    {
        if (($product->status ?? null) !== 'publish') {
            return false;
        }

        if (! ($product->is_visible ?? false)) {
            return false;
        }

        $publishedAt = $product->published_at ?? null;

        return $publishedAt === null || ! now()->lt($publishedAt);
    }
}
