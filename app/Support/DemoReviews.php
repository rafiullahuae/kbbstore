<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Review;
use Database\Seeders\DemoReviewsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which review rows are invented — one answer, covering BOTH of the two
 * mechanisms that write them.
 *
 * WHY THIS EXISTS. Demo reviews arrive by two completely separate routes and
 * until now nothing in the application asked about either one:
 *
 *   1. Database\Seeders\DemoReviewsSeeder stamps `source = 'demo'` on every row
 *      it writes. It runs from DatabaseSeeder on a fresh install and from
 *      2026_10_11_000002_seed_demo_reviews.php when the owner has the Demo
 *      Content switch on.
 *   2. Admin\DemoContentController::seedReviews() writes its rows through
 *      Review::create() and records them in `demo_seed_log`, which is what its
 *      Remove button deletes by. It did not stamp `source` at all.
 *
 * So `source = 'demo'` alone missed every row the admin panel seeded, and
 * DemoSeed alone missed every row the seeder wrote. A storefront figure that
 * excluded only one of them would still be publishing invented numbers, which
 * is the whole defect. Both halves are asked here, together, once.
 *
 * (seedReviews() now stamps `source` as well, so the two agree for anything
 * seeded from here on. The log half still has to be asked, because rows already
 * on the server were written before it did.)
 *
 * WHY EXCLUDED RATHER THAN LABELLED. The established rule on this repo is
 * "figures exclude demo; lists show it and mark it" — see App\Support\DemoSeed.
 * For reviews the list IS the figure: a fabricated person's name, star rating
 * and "Verified" tick on a public product page is the claim, not a decoration
 * around one. Publishing invented customer reviews is unlawful in the UAE, the
 * EU and the UK, and a label a crawler does not read is not a defence. So on
 * the storefront demo reviews are excluded outright; inside the admin they stay
 * visible and are marked, which is how the owner finds and removes them.
 *
 * WHAT READS THIS. Everything a shopper or a crawler can reach:
 * Store\ProductController (the page summary, the review list and the
 * schema.org aggregateRating), Store\HomeController's review wall,
 * Support\ReviewWall (/reviews), Support\StoreRating (the checkout trust
 * line), Support\ProductRating (which writes `products.rating` and
 * `products.review_count`, and so reaches every shop card, `?sort=rating`,
 * `?sort=popular` and the `top_rated` shortcode), and the two public
 * Api controllers.
 */
final class DemoReviews
{
    /** The `source` value DemoReviewsSeeder stamps. Named once, not retyped. */
    public const SOURCE = DemoReviewsSeeder::SOURCE;

    /**
     * Restrict a review query to rows a real person really wrote.
     *
     * whereNotExists rather than whereNotIn for the reason DemoSeed::exclude()
     * gives at length: `NOT IN (subquery)` collapses to zero rows the moment
     * the subquery yields one NULL. The `source` half is a plain column test
     * and is written so a NULL source (every real row written before the
     * column was used) still passes — `!=` alone would drop them all, because
     * NULL != 'demo' is NULL rather than true on both engines.
     *
     * The source test is wrapped in its own closure so the OR inside it cannot
     * escape and disjoin the caller's other conditions.
     *
     * @template TModel of Review
     *
     * @param  Builder<TModel>  $query
     * @param  string|null  $table  qualify the column names when the query joins
     * @return Builder<TModel>
     */
    public static function exclude(Builder $query, ?string $table = null): Builder
    {
        $model = $query->getModel();
        $prefix = ($table ?? $model->getTable()) . '.';
        $sourceColumn = $prefix . 'source';

        $query->where(function ($q) use ($sourceColumn) {
            $q->whereNull($sourceColumn)->orWhere($sourceColumn, '!=', self::SOURCE);
        });

        if (! DemoSeed::tableExists()) {
            return $query;
        }

        $idColumn = $prefix . $model->getKeyName();

        return $query->whereNotExists(function ($sub) use ($idColumn) {
            $sub->select(DB::raw(1))
                ->from(DemoSeed::TABLE)
                ->whereColumn(DemoSeed::TABLE . '.record_id', $idColumn)
                ->where(DemoSeed::TABLE . '.model', Review::class);
        });
    }

    /**
     * The same restriction on a PLAIN query builder.
     *
     * ProductRating::refresh() aggregates `reviews` through the query builder
     * rather than Eloquent, so there is no model to infer a table or a key
     * from and both have to be named outright. Same predicate as exclude(),
     * written once in each of the two dialects the codebase actually uses —
     * the alternative is a figure on the shop card computed from one
     * definition of "demo" and a figure on the product page computed from
     * another, which is the drift DemoSeed warns about.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    public static function excludeQuery(
        \Illuminate\Database\Query\Builder $query,
        string $table = 'reviews',
        string $keyName = 'id',
    ): \Illuminate\Database\Query\Builder {
        $sourceColumn = $table . '.source';

        $query->where(function ($q) use ($sourceColumn) {
            $q->whereNull($sourceColumn)->orWhere($sourceColumn, '!=', self::SOURCE);
        });

        if (! DemoSeed::tableExists()) {
            return $query;
        }

        $idColumn = $table . '.' . $keyName;

        return $query->whereNotExists(function ($sub) use ($idColumn) {
            $sub->select(DB::raw(1))
                ->from(DemoSeed::TABLE)
                ->whereColumn(DemoSeed::TABLE . '.record_id', $idColumn)
                ->where(DemoSeed::TABLE . '.model', Review::class);
        });
    }

    /**
     * The ids of every demo review, as a SET (id => true).
     *
     * For the admin's list screens, which show demo rows and badge them rather
     * than hiding them. Keyed for membership testing once per rendered row,
     * the same shape and the same reason as DemoSeed::idsFor().
     *
     * @return array<int, true>
     */
    public static function ids(): array
    {
        $ids = Review::query()
            ->where('source', self::SOURCE)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        foreach (DemoSeed::idsFor(Review::class) as $id => $_) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    /**
     * How many invented review rows are sitting in the table.
     *
     * COUNTED IN THE DATABASE, AND AS THE COMPLEMENT OF exclude().
     *
     * This used to be `count(self::ids())`, which plucked every demo id into
     * PHP to measure the array's length. Harmless at a handful of rows and not
     * harmless here: the seeded wall is 12,481 reviews, and DemoSeed::counts()
     * now asks this question on the dashboard, the orders screen, the analytics
     * screen and the customers screen — four of the admin's most-loaded
     * endpoints, each of which would have dragged twelve thousand integers
     * across the wire to learn one number.
     *
     * Subtracting the real rows from all rows rather than writing an
     * is-demo predicate is deliberate. The negation of exclude() is exactly the
     * kind of second definition this class exists to prevent: it would have to
     * restate the NULL-source rule and the log lookup, and the day one of them
     * changed, the figure the owner is shown would stop describing the rows
     * actually being hidden. Built this way it cannot disagree with exclude(),
     * because it IS exclude().
     */
    public static function count(): int
    {
        $all = Review::query()->count();
        $real = self::exclude(Review::query())->count();

        return max(0, $all - $real);
    }

    /**
     * Memoised in the CONTAINER, not a process-level static — the same choice
     * DemoSeed::tableExists() makes and for the reason CLAUDE.md records
     * against Setting::map(): a static would hold one answer across a queue
     * worker's lifetime and across a test's seeding. The container is rebuilt
     * per request and per test, so the memo lasts exactly as long as it is
     * true.
     */
    private const LOG_MEMO_KEY = 'kbb.demo_reviews.logged_ids';

    /**
     * Is this row one the demo content seeded?
     *
     * For ADMIN list screens, which show demo rows and mark them — the other
     * half of the rule. Takes the values a listing row already carries rather
     * than querying per row: the `source` half is free, and the log half is
     * one query for the whole listing however many rows it renders.
     */
    public static function isDemo(?string $source, int $id): bool
    {
        if ($source === self::SOURCE) {
            return true;
        }

        $app = app();

        if (! $app->bound(self::LOG_MEMO_KEY)) {
            $app->instance(self::LOG_MEMO_KEY, DemoSeed::idsFor(Review::class));
        }

        return isset($app->make(self::LOG_MEMO_KEY)[$id]);
    }
}
