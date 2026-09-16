<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The vocabulary of the review settings, in one place.
 *
 * WHY THIS FILE EXISTS. Seven `sr_*` keys were read by
 * resources/views/partials/reviews.blade.php and written by NOTHING. Not a
 * seeder, not a migration, not a controller, not a screen — grep the tree and
 * the only occurrences outside that one Blade are its compiled copy under
 * storage/framework/views. Review Settings was an <iframe> to
 * `kbb-admin-reviews-settings.html`, a standalone file this repo has never
 * shipped, so the owner of a store running 3,700+ live reviews could not see
 * or change a single one of the values that govern them.
 *
 * So the defaults below are not a wish list. Each one is the literal the
 * storefront already hard-codes today, which is what makes this package a
 * no-op until somebody deliberately changes something:
 *
 *   sr_show_stars/tabs/date  true, 4 cols, 4 photos  reviews.blade.php's own
 *                                                    $settings->get() defaults
 *   sr_sort        'newest'   Store\ProductController's ->latest()
 *   sr_max_reviews 200        the same method's ->limit(200)
 *   sr_empty_text  the ♡ line the .sr-empty div prints verbatim
 *   sr_rate_limit  5          Store\ReviewController::submit()'s
 *                             RateLimiter::tooManyAttempts($key, 5)
 *
 * THREE OF THESE KEYS WERE HALF-CONNECTED, AND THAT IS THE DEFECT THIS FIXES.
 * `sr_allow_submit`, `sr_allow_photos` and `sr_max_photos` were consulted by
 * the VIEW and ignored by the CONTROLLER: turning submissions off hid the
 * "Write a Review" button while POST /reviews went on accepting anything
 * posted straight at it, turning photos off hid the file input while the
 * upload handler still moved files into public/uploads/reviews, and the form
 * told the shopper "up to 4" while validation allowed six. A control that the
 * storefront only half obeys is worse than a missing one, so the readers are
 * wired in the same change that gives them a screen.
 *
 * MAX_PHOTOS IS A CEILING, NOT A DEFAULT. Store\ReviewController::MAX_PHOTOS
 * is 6 and stays 6: it is the hard bound on what the server will ever accept,
 * and a setting may only tighten it. The owner cannot type 50 into a box and
 * turn an upload form into free disk space.
 */
final class ReviewSettings
{
    public const SORTS = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'highest' => 'Highest rated first',
        'lowest' => 'Lowest rated first',
        'helpful' => 'Most helpful first',
    ];

    /** The hard ceiling on `sr_max_photos`. Mirrors Store\ReviewController::MAX_PHOTOS. */
    public const PHOTO_CEILING = 6;

    /**
     * key => [type, default, min, max]
     *
     * `min`/`max` are the clamp for int fields and are ignored otherwise. They
     * are applied on READ as well as on write, so a row hand-edited in the
     * database — or left behind by an older build — cannot put the storefront
     * outside the range the screen would allow.
     */
    public const SCHEMA = [
        'sr_show_stars' => ['bool', true, null, null],
        'sr_show_tabs' => ['bool', true, null, null],
        'sr_show_date' => ['bool', true, null, null],
        'sr_grid_cols' => ['int', 4, 1, 6],
        'sr_sort' => ['enum', 'newest', null, null],
        'sr_max_reviews' => ['int', 200, 4, 500],
        'sr_empty_text' => ['text', 'Be the first to share your thoughts ♡', null, null],
        'sr_allow_submit' => ['bool', true, null, null],
        'sr_allow_photos' => ['bool', true, null, null],
        'sr_max_photos' => ['int', 4, 1, self::PHOTO_CEILING],
        'sr_rate_limit' => ['int', 5, 1, 50],
    ];

    /** The longest `sr_empty_text` that may be stored. */
    public const TEXT_MAX = 200;

    /**
     * Every setting, normalised, ready for a view or a controller.
     *
     * @return array<string, bool|int|string>
     */
    public static function all(SettingsService $settings): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => [$type, $default, $min, $max]) {
            $out[$key] = self::normalise($key, $settings->get($key, $default));
        }

        return $out;
    }

    /** One setting, normalised. */
    public static function get(SettingsService $settings, string $key): bool|int|string
    {
        $spec = self::SCHEMA[$key] ?? null;

        if ($spec === null) {
            throw new \InvalidArgumentException("Unknown review setting [{$key}].");
        }

        return self::normalise($key, $settings->get($key, $spec[1]));
    }

    /**
     * Fold any stored value onto the schema.
     *
     * Values arrive from a longText column that has held whatever a WordPress
     * export, a hand-edited row or an older build put there, so nothing here
     * trusts the type it is handed.
     */
    public static function normalise(string $key, mixed $value): bool|int|string
    {
        [$type, $default, $min, $max] = self::SCHEMA[$key]
            ?? throw new \InvalidArgumentException("Unknown review setting [{$key}].");

        return match ($type) {
            // '0' and '' are the two shapes a false reaches us as once it has
            // been round-tripped through a text column, and PHP's own (bool)
            // already reads both as false -- but 'false' and 'off' are not,
            // and an older build wrote words.
            'bool' => ! in_array(
                mb_strtolower(trim((string) (is_bool($value) ? ($value ? '1' : '0') : $value))),
                ['', '0', 'false', 'off', 'no', 'null'],
                true
            ),
            'int' => max((int) $min, min((int) $max, (int) $value)),
            'enum' => isset(self::SORTS[(string) $value]) ? (string) $value : (string) $default,
            // Trimmed and bounded, never empty: an empty empty-state is a blank
            // gap where the page should say something.
            default => self::text((string) $value, (string) $default),
        };
    }

    private static function text(string $value, string $default): string
    {
        $clean = trim(strip_tags($value));

        if ($clean === '') {
            return $default;
        }

        return mb_substr($clean, 0, self::TEXT_MAX);
    }

    /**
     * Apply `sr_sort` to a review query.
     *
     * Every branch ends with a tiebreak on `id`, because a page of reviews that
     * reorders itself between two loads for rows with the same rating is the
     * kind of thing an owner reports as "reviews are disappearing". MySQL and
     * SQLite are both free to return equal rows in any order without one.
     */
    public static function applySort(\Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query, string $sort): mixed
    {
        return match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('id'),
            'lowest' => $query->orderBy('rating')->orderByDesc('id'),
            'helpful' => $query->orderByDesc('helpful')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
