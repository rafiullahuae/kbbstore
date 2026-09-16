<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Review;
use Illuminate\Support\Facades\Cache;

/**
 * What this shop's customers have actually said, shop-wide.
 *
 * WHY IT EXISTS. partials/checkout/reassurance.blade.php printed
 * "4.8 · loved by 2,300+ UAE customers" at the moment of payment. It was the
 * DEFAULT of `reassure_rating_text` — a literal in the Blade, shown on a shop
 * with no reviews at all, and there was no admin screen to change it.
 *
 * That is the defect RatingsTellTheTruthTest already exists for, one screen
 * further along: the owner reported the product page advertising "4.9 · 3,204
 * reviews" while the admin correctly said no product had a single approved
 * review. A rating a shopper reads while deciding to pay is exactly the claim
 * that must come from the reviews table and nowhere else.
 *
 * APPROVED ONLY, which is the same question every other reader of this table
 * asks — Store\ProductController::reviewSummary(), the home page's review wall
 * and Support\ProductRating all filter on `status = approved`, so the figure at
 * the payment step cannot disagree with the figure on the product page.
 *
 * NOTHING IS SHOWN BELOW `MINIMUM`. One five-star review is true and is not a
 * shop-wide score; printing "5.0 from 1 review" beside a pay button reads as a
 * reputation and is not one. The threshold is a display judgement rather than a
 * claim, which is why it lives here as a constant and not as another setting
 * for the owner to get wrong.
 *
 * CACHED FOR FIFTEEN MINUTES, and deliberately not evicted on moderation. The
 * home page's review wall already accepts exactly that staleness over exactly
 * these rows; a trust line being a quarter of an hour behind is not worth
 * wiring a fourth eviction point into three admin controllers this lane does
 * not own. It also keeps /checkout inside its query budget.
 */
final class StoreRating
{
    /** Approved reviews needed before a shop-wide score is shown at all. */
    public const MINIMUM = 5;

    private const CACHE_KEY = 'kbb.store.rating';

    /**
     * @return array{average: float, total: int, stars: int}|null
     *         null when the shop has not earned a figure yet.
     */
    public static function summary(): ?array
    {
        $row = Cache::remember(self::CACHE_KEY, 900, function () {
            $aggregate = Review::query()->approved()
                ->selectRaw('COUNT(*) c, AVG(rating) a')
                ->first();

            return [
                'total' => (int) ($aggregate->c ?? 0),
                // One decimal, the same precision the product page prints.
                'average' => round((float) ($aggregate->a ?? 0), 1),
            ];
        });

        // A cache written by an older build could hold anything at all; never
        // let a stale entry take the checkout down.
        if (! is_array($row) || ! isset($row['total'], $row['average'])) {
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        $total = (int) $row['total'];

        if ($total < self::MINIMUM) {
            return null;
        }

        $average = (float) $row['average'];

        return [
            'average' => $average,
            'total' => $total,
            // Filled stars follow the real average rather than a configured
            // number, so the picture and the figure cannot disagree.
            'stars' => max(1, min(5, (int) round($average))),
        ];
    }

    /** The sentence itself: "4.8 from 137 reviews", or null. */
    public static function line(): ?string
    {
        $summary = self::summary();

        if ($summary === null) {
            return null;
        }

        return number_format($summary['average'], 1)
            . ' from ' . number_format($summary['total'])
            . ' ' . ($summary['total'] === 1 ? 'review' : 'reviews');
    }

    /** Called by anything that has just changed what the reviews table says. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
