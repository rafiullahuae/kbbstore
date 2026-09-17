<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\ReviewBadgeSettings;
use App\Support\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reviews → Badge Themes and Reviews → Rating Capsule.
 *
 * ONE CONTROLLER FOR TWO SCREENS, because they edit ONE set of seven settings
 * and a second controller would be a second place for the defaults to live.
 * The split between the screens is a split of question, not of data:
 *
 *   Rating Capsule  where the rating appears and what is in it —
 *                   review_capsule_style plus the four content switches.
 *   Badge Themes    what it looks like — the presets, the star colour, and
 *                   the sold note.
 *
 * Both screens save through update() and both draw from show(), so neither can
 * hold a value the other would disagree with.
 *
 * THERE IS ALREADY ANOTHER WRITER OF THESE SEVEN ROWS.
 * Admin\EcommerceApiController exposes all of them under Store → Ecommerce →
 * Product page → "Review badges". That is not a bug and this does not replace
 * it: it is a long generic settings form, and these two screens are the nav
 * entries the owner actually has for this job, with a preview of the badge as
 * the shopper sees it. Both screens say so on the screen itself, because two
 * places quietly editing one setting is how an owner comes to believe one of
 * them is broken. See App\Support\ReviewBadgeSettings for the full inventory
 * and for why no new key was invented.
 *
 * NOTHING HERE TOUCHES A REVIEW ROW, A RATING OR A COUNT. These settings govern
 * how an already-computed score is DRAWN. The average itself comes from
 * Store\ProductController::reviewSummary() and the denormalised pair from
 * App\Support\ProductRating, neither of which is reachable from this file —
 * which is the reason the preview below asks the database for a real product's
 * real numbers instead of inventing some.
 */
class ReviewBadgeApiController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /** GET /admin-api/review-badges */
    public function show(): JsonResponse
    {
        $current = ReviewBadgeSettings::all($this->settings);

        return response()->json([
            'settings' => $current,
            'styles' => ReviewBadgeSettings::STYLES,
            'themes' => ReviewBadgeSettings::themes(),
            'active_theme' => ReviewBadgeSettings::activeTheme($current),
            'label_max' => ReviewBadgeSettings::LABEL_MAX,
            'sample' => $this->sample(),
        ]);
    }

    /**
     * PUT /admin-api/review-badges
     *
     * Every field is `sometimes`. The Capsule screen saves five keys and the
     * Themes screen saves six; a partial save is the normal case here, not an
     * edge one, and a missing field must not reset the half of the set the
     * other screen owns.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'review_capsule_style' => ['sometimes', 'string', Rule::in(array_keys(ReviewBadgeSettings::STYLES))],
            'review_badge_heart' => ['sometimes', 'boolean'],
            'review_badge_avg' => ['sometimes', 'boolean'],
            'review_badge_count' => ['sometimes', 'boolean'],
            'review_badge_sold' => ['sometimes', 'boolean'],
            /*
             * `nullable`, for the reason ReviewSettingsApiController's own
             * header sets out: Laravel's global ConvertEmptyStringsToNull runs
             * before validation, so an owner who clears this box sends NULL and
             * a bare `string` rule answered 422 "The review badge label field
             * must be a string." Measured. Clearing it is a legitimate request
             * meaning "put the default wording back", and
             * ReviewBadgeSettings::label() already does exactly that with a
             * blank value — so the rule was refusing the one thing the
             * normaliser was written to handle.
             */
            'review_badge_label' => ['sometimes', 'nullable', 'string', 'max:' . ReviewBadgeSettings::LABEL_MAX],
            // Validated here AND normalised again below. The rule is the
            // message the owner sees; ReviewBadgeSettings::normalise() is what
            // actually decides what may reach a style attribute, and it is the
            // one the storefront shares.
            'review_badge_colour' => ['sometimes', 'string', 'regex:/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
        ]);

        foreach ($data as $key => $value) {
            $this->settings->set($key, ReviewBadgeSettings::normalise($key, $value));
        }

        return $this->show();
    }

    /**
     * POST /admin-api/review-badges/theme
     *
     * Applying a preset is a write of the six appearance keys and nothing else
     * — there is no `review_badge_theme` row, deliberately. See the note on
     * ReviewBadgeSettings::THEMES.
     */
    public function applyTheme(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', Rule::in(array_keys(ReviewBadgeSettings::THEMES))],
        ]);

        foreach (ReviewBadgeSettings::THEMES[$data['theme']]['values'] as $key => $value) {
            $this->settings->set($key, ReviewBadgeSettings::normalise($key, $value));
        }

        return $this->show();
    }

    /**
     * A real product's real numbers for the preview.
     *
     * The most-reviewed product, its APPROVED average and count — the same
     * question every storefront reader asks (Review::scopeApproved,
     * ProductRating::refresh, ProductController::reviewSummary all filter on
     * status = 'approved'). A preview built on invented numbers is a preview
     * that can look right while the page looks wrong, and "12k+ sold" in
     * particular only ever appears above 999 sales, so the owner has to be able
     * to see whether their own best seller clears that bar.
     *
     * Falls back to a clearly-labelled specimen when the store has no approved
     * review yet, with `real` saying which of the two it handed back.
     *
     * @return array<string, mixed>
     */
    private function sample(): array
    {
        $row = DB::table('reviews')
            ->join('products', 'products.id', '=', 'reviews.product_id')
            ->select('products.id', 'products.name', 'products.total_sales')
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('AVG(reviews.rating) as a')
            ->where('reviews.status', '=', ReviewStatus::APPROVED)
            ->groupBy('products.id', 'products.name', 'products.total_sales')
            // LIMIT 1 over a count that ties constantly — two products with
            // three approved reviews each is the ordinary case on this shop,
            // and this picks the one the badge names. `products.id` decides
            // it rather than the planner.
            ->orderByDesc('n')
            ->orderByDesc('products.id')
            ->limit(1)
            ->first();

        if ($row === null) {
            return [
                'real' => false,
                'product' => 'Example product',
                'rating' => 4.8,
                'count' => 126,
                'total_sales' => 1240,
            ];
        }

        return [
            'real' => true,
            'product' => (string) $row->name,
            'rating' => round((float) $row->a, 2),
            'count' => (int) $row->n,
            'total_sales' => (int) ($row->total_sales ?? 0),
        ];
    }
}
