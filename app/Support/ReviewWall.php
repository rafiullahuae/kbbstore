<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the shop-wide reviews page at /reviews is allowed to say.
 *
 * WHY IT EXISTS. store/review-wall.blade.php shipped `var REVIEWS=[…]` —
 * TWELVE INVENTED CUSTOMERS, with names, star ratings, relative dates, review
 * bodies and "helpful" counts — and a computeStats() that derived "4.9" and
 * "Based on 128 reviews" from them. Store\PageController::reviewWall() passed
 * the view no review data at all, so an approved review in this shop's database
 * could not reach the page and the twelve strangers could not be taken off it
 * by anything the owner could do. Store\SeoFilesController submitted the URL to
 * search engines unconditionally.
 *
 * Every figure below therefore comes out of `reviews` or is not shown. There is
 * no fallback, no fixture and no default: a shop with nothing to say says
 * nothing, in words, and invites a review instead.
 *
 * ONE STATEMENT BEHIND THE HEADLINE, THE STARS AND THE BARS.
 *
 * summary() is a single `SELECT rating, COUNT(*) … GROUP BY rating` — the query
 * 2026_10_30_000000_index_review_wall_and_clear_caches.php indexed to "Using
 * index", measured at 2.0ms against 7,650 approved reviews. Total, average and
 * distribution are all derived from its rows, so the number in the headline and
 * the bars under it are computed from the same reading and cannot disagree with
 * each other. Asking StoreRating for the average and this query for the bars
 * would be two readings of the same table a cache-lifetime apart, which is the
 * exact shape of bug this repository keeps paying for.
 *
 * THE THRESHOLD IS StoreRating's, NOT A SECOND ONE. Below
 * StoreRating::MINIMUM approved reviews there is no shop-wide score here
 * either, for the reason its header gives: "One five-star review is true and is
 * not a shop-wide score." The individual reviews are still shown — each is a
 * true statement by itself — and the page says why the score is absent rather
 * than leaving a gap that reads as broken.
 *
 * DELIBERATELY NOT CACHED. StoreRating and the home page's wall each cache
 * their figure for fifteen minutes under a key of their own, and five separate
 * admin controllers already carry `Cache::forget('kbb.home.reviews')` as a
 * literal. A sixth key would mean a sixth eviction point in five files this
 * lane does not own, and the two queries here are the two that were indexed for
 * exactly this access pattern. Reading live also means the page the owner sends
 * a customer to reflects a moderation decision at once rather than a quarter of
 * an hour later. Measured cost is in tests/Feature/StorefrontQueryBudgetTest.php.
 */
final class ReviewWall
{
    /** The filters the page offers. The home page's wall links four of them. */
    public const FILTERS = [
        'all' => 'All',
        '5' => '5 ★',
        '4' => '4 ★',
        'photos' => 'With photos',
        'helpful' => 'Most helpful',
    ];

    /** Cards on the first screen. */
    public const INITIAL = 12;

    /** Cards each "load more" adds. */
    public const STEP = 12;

    /**
     * The most cards one request will render.
     *
     * "Load more" is a link carrying ?rshow=, so the number in it is a
     * visitor's to type. Without a ceiling, ?rshow=100000 is a request to
     * hydrate and render every approved review in the shop, unauthenticated
     * and for free — the same shape as the uncapped /api/products endpoint.
     * A shop with more than this many reviews needs real pagination, which is
     * a decision to take deliberately rather than by leaving the door open.
     */
    public const MAX = 120;

    /** The columns the page renders, and nothing else. */
    private const CARD_COLUMNS = [
        'id', 'product_id', 'author_name', 'rating', 'title', 'content',
        'images', 'verified', 'helpful', 'created_at',
    ];

    /**
     * The requested filter, or 'all'.
     *
     * Anything unrecognised becomes 'all' rather than an empty result: a
     * mistyped or stale link shows the shop's reviews, not a page that looks
     * like the shop has none.
     */
    public static function filter(mixed $requested): string
    {
        $key = is_string($requested) ? $requested : '';

        return array_key_exists($key, self::FILTERS) ? $key : 'all';
    }

    /** How many cards to render, clamped to the page's own steps. */
    public static function show(mixed $requested): int
    {
        $n = (int) (is_scalar($requested) ? $requested : 0);

        if ($n < self::INITIAL) {
            return self::INITIAL;
        }

        if ($n > self::MAX) {
            return self::MAX;
        }

        // Snapped to a step so the cache-unfriendly middle values a visitor can
        // type do not turn into arbitrary LIMITs.
        $steps = (int) ceil(($n - self::INITIAL) / self::STEP);

        return min(self::MAX, self::INITIAL + $steps * self::STEP);
    }

    /**
     * Counts per star, the total and the average — one grouped query.
     *
     * @return array{total:int, average:float, bars:array<int,array{n:int,pct:int}>, score:bool}
     *         `score` is whether a shop-wide figure may be published at all.
     */
    public static function summary(): array
    {
        $rows = Review::query()->approved()->real()
            ->selectRaw('rating, COUNT(*) as n')
            ->groupBy('rating')
            ->pluck('n', 'rating');

        $total = (int) $rows->sum();
        $weighted = 0;
        $bars = [];

        foreach ([5, 4, 3, 2, 1] as $star) {
            $n = (int) ($rows[$star] ?? 0);
            $weighted += $star * $n;
            $bars[$star] = ['n' => $n, 'pct' => $total ? (int) round($n / $total * 100) : 0];
        }

        return [
            'total' => $total,
            // One decimal, the precision the product page and the checkout
            // trust line both print.
            'average' => $total ? round($weighted / $total, 1) : 0.0,
            'bars' => $bars,
            'score' => $total >= StoreRating::MINIMUM,
        ];
    }

    /**
     * The cards themselves.
     *
     * A review with no text is still a review and still counts towards the
     * figures above — it is a rating the shop genuinely received. It is not a
     * CARD, because a card with nothing in it looks like a page that failed to
     * load. So the grid filters on content and the summary does not, which is
     * the same split the home page's wall makes.
     *
     * LIMIT $show + 1, so whether there is a next page is answered by the row
     * that comes back rather than by a second COUNT.
     *
     * @return array{items: Collection, more: bool, shown: int}
     */
    public static function cards(string $filter, int $show): array
    {
        $query = Review::query()->approved()->real()
            ->select(self::CARD_COLUMNS)
            ->whereNotNull('content')
            ->where('content', '<>', '')
            // Named so the card can print which product was reviewed. A
            // business review carries no product and prints none.
            ->with('product:id,name,slug');

        self::applyFilter($query, $filter);

        $rows = $query->limit($show + 1)->get();
        $more = $rows->count() > $show;

        return [
            'items' => $rows->take($show),
            'more' => $more,
            'shown' => min($rows->count(), $show),
        ];
    }

    /** @param Builder<Review> $query */
    private static function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            '5', '4' => $query->where('rating', (int) $filter),
            'photos' => $query
                ->whereNotNull('images')
                // Portable across both engines: `images` is a JSON text column
                // and an empty array is stored as the literal '[]'. No JSON
                // function is used — SqlDialectGuardTest exists because SQLite
                // and MySQL disagree about those.
                ->whereNotIn('images', ['', '[]', 'null']),
            default => null,
        };

        if ($filter === 'helpful') {
            // Ties broken by recency so the order is total and a page boundary
            // cannot show the same review twice.
            $query->orderByDesc('helpful')->orderByDesc('id');

            return;
        }

        // The index added by 2026_10_30_000000_index_review_wall_and_clear_caches
        // is (status, created_at); this is the backward scan it was measured on.
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The photo URLs on a review, cleaned of anything that is not one.
     *
     * `images` is written by Store\ReviewController (paths under
     * /uploads/reviews) and by the CSV importer, and it has been hand-edited in
     * the admin, so the column can hold a scalar, a null or a ragged array.
     *
     * AN ALLOWLIST OF SHAPES, not a blocklist of bad ones. A root-relative path
     * or an http(s) URL, and nothing else — because the value ends up in an
     * `src` attribute and the column is reachable from the admin and from a CSV
     * import. `javascript:` and `data:` are the two that matter and neither is
     * a photo; App\Support\Url::to() passes any scheme through untouched, so
     * the check has to happen before it rather than inside it.
     *
     * @return list<string>
     */
    public static function photos(Review $review): array
    {
        $images = $review->images;

        if (! is_array($images)) {
            return [];
        }

        $out = [];

        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $image = trim($image);

            if ($image === '') {
                continue;
            }

            $looksLikeAPath = str_starts_with($image, '/') && ! str_starts_with($image, '//');
            $looksLikeAUrl = (bool) preg_match('#^https?://#i', $image);

            if ($looksLikeAPath || $looksLikeAUrl) {
                $out[] = $image;
            }
        }

        return $out;
    }
}
