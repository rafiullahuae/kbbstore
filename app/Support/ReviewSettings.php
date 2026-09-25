<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ModuleSchema;
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
     * The eleven settings, in App\Services\ModuleSchema's shape — Lane M3.
     *
     * ── WHAT MOVED, AND WHAT DID NOT ────────────────────────────────────────
     *
     * This constant used to be `key => [type, default, min, max]`: its own
     * positional shape, with no label, no help, `enum` where the schema says
     * `select` and the bounds in the slots the schema reads as `label` and
     * `default`. Round 2 named that as the blocker and it was a real one —
     * ModuleSchema's positional form is `[type, label, default, help,
     * options]`, so the two lists agree on position 0 and on nothing else, and
     * a schema read as if it were the other is a silent mis-reading rather than
     * an error. So the constant is written out in the ASSOCIATIVE form, which
     * has no positions to confuse.
     *
     * The VALUES are the same eleven, with the same defaults and the same
     * bounds, and the answers are pinned call by call against 347 calls
     * recorded off the parent revision
     * (tests/Fixtures/module-settings-baseline.txt, replayed by
     * ModuleSettingsEquivalenceTest). No label or help is invented: this
     * screen draws its own controls in its own partial and has never read a
     * label off here, and inventing eleven would be eleven strings nothing
     * displays that the next reader would take for the wording on the page.
     *
     * ── THE THREE THINGS THIS SCREEN ASKS OF THE SCHEMA ─────────────────────
     *
     *   bool => 'words+null'  These settings have always folded the literal
     *       string `null` to false, where ModuleSchema's `words` reads it as
     *       true. It is the third boolean dialect round 2 could not migrate
     *       past. It is DECLARED rather than flattened, because five of the
     *       eleven keys are switches on a live screen and a fold that moved
     *       `null` would turn five off controls on.
     *   blank => 'default'    An emptied empty-state line is a blank gap where
     *       the page should say something — the reason text() had a fallback.
     *   markup => 'strip'     strip_tags, which this class has always run on
     *       that one string. A wording rule, not a safety one: the escaping
     *       that makes it safe to print is Blade's, at the print site.
     *
     * `min`/`max` are the int clamp, in `options` where ModuleSchema's clamped
     * int arm reads them. Applied on READ as well as on write — see
     * ModuleSchema::coerceRead(), which as of this round re-derives every
     * stored value rather than trusting it, so the sentence this docblock has
     * always carried is now true of the whole schema instead of only here.
     */
    public const SCHEMA = [
        'sr_show_stars' => ['type' => 'bool', 'default' => true, 'store' => ModuleSchema::STORE_SETTING],
        'sr_show_tabs' => ['type' => 'bool', 'default' => true, 'store' => ModuleSchema::STORE_SETTING],
        'sr_show_date' => ['type' => 'bool', 'default' => true, 'store' => ModuleSchema::STORE_SETTING],
        'sr_grid_cols' => ['type' => 'int', 'default' => 4, 'options' => ['min' => 1, 'max' => 6], 'store' => ModuleSchema::STORE_SETTING],
        'sr_sort' => ['type' => 'select', 'default' => 'newest', 'options' => self::SORTS, 'store' => ModuleSchema::STORE_SETTING],
        'sr_max_reviews' => ['type' => 'int', 'default' => 200, 'options' => ['min' => 4, 'max' => 500], 'store' => ModuleSchema::STORE_SETTING],
        'sr_empty_text' => ['type' => 'text', 'default' => 'Be the first to share your thoughts ♡', 'store' => ModuleSchema::STORE_SETTING],
        'sr_allow_submit' => ['type' => 'bool', 'default' => true, 'store' => ModuleSchema::STORE_SETTING],
        'sr_allow_photos' => ['type' => 'bool', 'default' => true, 'store' => ModuleSchema::STORE_SETTING],
        'sr_max_photos' => ['type' => 'int', 'default' => 4, 'options' => ['min' => 1, 'max' => self::PHOTO_CEILING], 'store' => ModuleSchema::STORE_SETTING],
        'sr_rate_limit' => ['type' => 'int', 'default' => 5, 'options' => ['min' => 1, 'max' => 50], 'store' => ModuleSchema::STORE_SETTING],
    ];

    /**
     * This screen's point on ModuleSchema's seven policy axes.
     *
     * `invalid => 'default'` is what every arm of the old normalise() did: an
     * unusable value fell back, none of them refused. `clamp => true` is the
     * int arm's `max($min, min($max, (int) $value))`. The other four are the
     * defaults and are written out anyway, because a reader of this constant
     * should not have to hold DEFAULT_POLICY in their head to know what an
     * over-long paste does here.
     */
    public const POLICY = [
        'max' => self::TEXT_MAX,
        'blank' => 'default',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'words+null',
        'markup' => 'strip',
    ];

    /** The longest `sr_empty_text` that may be stored. */
    public const TEXT_MAX = 200;

    /** One normalised field of the schema, memoised by ModuleSchema. */
    private static function field(string $key): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY)[$key]
            ?? throw new \InvalidArgumentException("Unknown review setting [{$key}].");
    }

    /**
     * Every setting, normalised, ready for a view or a controller.
     *
     * @return array<string, bool|int|string>
     */
    public static function all(SettingsService $settings): array
    {
        $out = [];

        foreach (ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY) as $key => $f) {
            $out[$key] = self::normalise($key, $settings->get($key, $f['default']));
        }

        return $out;
    }

    /** One setting, normalised. */
    public static function get(SettingsService $settings, string $key): bool|int|string
    {
        return self::normalise($key, $settings->get($key, self::field($key)['default']));
    }

    /**
     * Fold any stored value onto the schema.
     *
     * Values arrive from a longText column that has held whatever a WordPress
     * export, a hand-edited row or an older build put there, so nothing here
     * trusts the type it is handed.
     *
     * ── ONE CAST, NOT A TWELFTH COPY OF ONE (Lane M3) ───────────────────────
     *
     * The body used to be four arms of its own. Every one of them is now a
     * point on App\Services\ModuleSchema's policy axes — see POLICY — which
     * means this screen's rules are the rules the other sixteen modules are
     * checked against rather than a lookalike beside them. The two axis values
     * that did not exist before this round, `bool => 'words+null'` and
     * `markup => 'strip'`, were added for exactly this: to move the code and
     * not the answers.
     *
     * `cast()` answers null for "will not store this", which cannot happen here
     * — `invalid => 'default'` is declared, so every arm substitutes — but the
     * fallback is written out rather than assumed, because the day somebody
     * changes that policy this method's signature stops being honest.
     */
    public static function normalise(string $key, mixed $value): bool|int|string
    {
        $field = self::field($key);
        $cast = ModuleSchema::cast($field, $value);

        /** @var bool|int|string */
        return $cast === null ? $field['default'] : $cast;
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
