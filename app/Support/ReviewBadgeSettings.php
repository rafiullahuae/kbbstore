<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The vocabulary of the review badge / rating capsule settings, in one place.
 *
 * WHY THIS FILE EXISTS, AND WHAT IT IS CAREFUL NOT TO DO.
 *
 * Two console screens — Reviews → Badge Themes ('rev-badge') and Reviews →
 * Rating Capsule ('rev-capsule') — were entries in REV_SRC pointing at
 * `kbb-admin-badgethemes.html` and `kbb-capsule-editor.html`, standalone files
 * this repo has never shipped, so both printed the "isn't installed yet" card.
 *
 * THE SETTINGS THEY WERE DRAWN FOR ALREADY EXIST AND ARE ALREADY READ. Every
 * key below is consulted by resources/views/store/product.blade.php on every
 * product page:
 *
 *   review_capsule_style  decides $showCap / $showRate
 *   review_badge_heart    the heart on the capsule
 *   review_badge_avg      the numeric average
 *   review_badge_count    the review-count chip
 *   review_badge_sold     the "12k+ sold" note (only over 999 sales)
 *   review_badge_label    the count wording, {n} substituted
 *   review_badge_colour   the star colour, on BOTH the capsule and the inline
 *                         rating line
 *
 * SO NO NEW KEY IS INVENTED HERE. Not one. The brief for these screens said to
 * grep for the reader before adding a control, and the answer for all seven was
 * "already read" — which is why a "Badge Themes" screen is a preset picker over
 * values that already mean something, not a new subsystem.
 *
 * AND THERE IS ALREADY A WRITER, WHICH IS THE THING WORTH KNOWING.
 * Admin\EcommerceApiController's schema exposes all seven under Store →
 * Ecommerce → Product page → "Review badges". These screens are therefore a
 * SECOND writer of the same seven rows, not the first. That is deliberate and
 * it is stated on both screens, because two screens that quietly edit the same
 * setting is how an owner comes to believe one of them is broken. What these
 * two add over the Ecommerce form is a live preview of the badge the shopper
 * actually sees, and presets; what they must never add is a key of their own,
 * which would be a second source of truth for the same pixel.
 *
 * Shaped after App\Support\ReviewSettings, which is the precedent in this repo
 * for "one schema, read by the screen and by the storefront, so the two cannot
 * disagree about what unset means".
 */
final class ReviewBadgeSettings
{
    /** The four values `review_capsule_style` may hold, and what each does. */
    public const STYLES = [
        'capsule' => 'Capsule only',
        'inline' => 'Inline only',
        'both' => 'Capsule and inline',
        'off' => 'Hidden',
    ];

    /** The longest `review_badge_label` that may be stored. */
    public const LABEL_MAX = 60;

    /**
     * key => [type, default]
     *
     * Every default is the literal resources/views/store/product.blade.php
     * already passes as the second argument to $settings->get(), so a store
     * that has never opened either screen behaves exactly as it does today and
     * these packages are a no-op until somebody changes something on purpose.
     * ReviewBadgeScreenTest pins that agreement against the RENDERED PAGE
     * rather than against this comment.
     */
    public const SCHEMA = [
        'review_capsule_style' => ['enum', 'capsule'],
        'review_badge_heart' => ['bool', true],
        'review_badge_avg' => ['bool', true],
        'review_badge_count' => ['bool', true],
        'review_badge_label' => ['text', '{n} reviews'],
        'review_badge_sold' => ['bool', true],
        'review_badge_colour' => ['colour', '#E8A33D'],
    ];

    /**
     * The presets the Badge Themes screen offers.
     *
     * A "theme" here is nothing but a named set of values for the six
     * appearance keys — it is NOT stored, and there is no `review_badge_theme`
     * row anywhere. Applying one writes the six keys and nothing else; the
     * active theme is worked out by comparing what is stored against these
     * (see activeTheme()). That is the whole reason this screen needed no new
     * key: a preset that remembered itself would be a second source of truth
     * about a colour the storefront reads from somewhere else.
     *
     * `review_capsule_style` is deliberately NOT part of any theme. It decides
     * WHETHER the badge is shown at all and where, which is a different
     * question from what it looks like, and a theme that silently switched the
     * badge back on would be a surprise rather than a style.
     */
    public const THEMES = [
        'classic' => [
            'label' => 'Classic gold',
            'note' => 'The badge this store ships with — gold stars, the heart, the count and the sold note.',
            'values' => [
                'review_badge_heart' => true,
                'review_badge_avg' => true,
                'review_badge_count' => true,
                'review_badge_label' => '{n} reviews',
                'review_badge_sold' => true,
                'review_badge_colour' => '#E8A33D',
            ],
        ],
        'minimal' => [
            'label' => 'Minimal',
            'note' => 'Stars and the score, nothing else. The quietest option on a busy product page.',
            'values' => [
                'review_badge_heart' => false,
                'review_badge_avg' => true,
                'review_badge_count' => false,
                'review_badge_label' => '{n} reviews',
                'review_badge_sold' => false,
                'review_badge_colour' => '#111111',
            ],
        ],
        'trust' => [
            'label' => 'Trust green',
            'note' => 'Leads with the number of shoppers rather than with the score.',
            'values' => [
                'review_badge_heart' => true,
                'review_badge_avg' => true,
                'review_badge_count' => true,
                'review_badge_label' => 'Loved by {n} shoppers',
                'review_badge_sold' => true,
                'review_badge_colour' => '#15A85A',
            ],
        ],
        'blush' => [
            'label' => 'Blush',
            'note' => 'K-beauty pink. The same information as Classic, a softer colour.',
            'values' => [
                'review_badge_heart' => true,
                'review_badge_avg' => true,
                'review_badge_count' => true,
                'review_badge_label' => '{n} reviews',
                'review_badge_sold' => false,
                'review_badge_colour' => '#E86A9A',
            ],
        ],
        'mono' => [
            'label' => 'Mono',
            'note' => 'Grey stars, no heart, no sold note — for a monochrome theme.',
            'values' => [
                'review_badge_heart' => false,
                'review_badge_avg' => true,
                'review_badge_count' => true,
                'review_badge_label' => '{n} reviews',
                'review_badge_sold' => false,
                'review_badge_colour' => '#6B7280',
            ],
        ],
    ];

    /**
     * Every setting, normalised, ready for a screen or a controller.
     *
     * @return array<string, bool|string>
     */
    public static function all(SettingsService $settings): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => [, $default]) {
            $out[$key] = self::normalise($key, $settings->get($key, $default));
        }

        return $out;
    }

    /**
     * Fold any stored value onto the schema.
     *
     * Nothing here trusts the type it is handed: these rows live in a longText
     * column that has held whatever a WordPress export, a hand-edited row or an
     * older build put there.
     */
    public static function normalise(string $key, mixed $value): bool|string
    {
        [$type, $default] = self::SCHEMA[$key]
            ?? throw new \InvalidArgumentException("Unknown review badge setting [{$key}].");

        return match ($type) {
            // The same fold ReviewSettings uses, and for the same reason: '0'
            // and '' are what a false becomes through a text column, but an
            // older build wrote words, and (bool) reads 'false' as true.
            'bool' => ! in_array(
                mb_strtolower(trim((string) (is_bool($value) ? ($value ? '1' : '0') : $value))),
                ['', '0', 'false', 'off', 'no', 'null'],
                true
            ),
            'enum' => isset(self::STYLES[(string) $value]) ? (string) $value : (string) $default,
            'colour' => self::colour((string) $value, (string) $default),
            default => self::label((string) $value, (string) $default),
        };
    }

    /**
     * A strict hex colour, or the default.
     *
     * NOT COSMETIC VALIDATION. product.blade.php interpolates this value
     * straight into a style attribute:
     *
     *     <span class="sr-cap-stars" style="color:{{ $badgeColour }}">
     *
     * Blade escapes the quotes, so the attribute cannot be broken out of — but
     * the value is still inside a style attribute, and `red;position:fixed;
     * inset:0;z-index:9999` is a perfectly good CSS payload that never needs a
     * quote. Anything that is not #rgb or #rrggbb is refused here and the
     * default stands, so nothing but a colour can reach that attribute.
     *
     * Three-digit shorthand is expanded rather than refused: it is valid CSS, a
     * colour picker may well emit it, and storing one canonical form means the
     * theme comparison in activeTheme() is a string compare.
     */
    private static function colour(string $value, string $default): string
    {
        $clean = strtoupper(trim($value));

        if (preg_match('/^#([0-9A-F]{3})$/', $clean, $m) === 1) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        if (preg_match('/^#[0-9A-F]{6}$/', $clean) === 1) {
            return $clean;
        }

        return strtoupper($default);
    }

    /**
     * The count wording. Trimmed, de-tagged, bounded, never empty.
     *
     * An empty label is not "hide the count" — the count has its own switch —
     * it is a blank gap next to the stars, so an empty value falls back to the
     * default the storefront already ships.
     */
    private static function label(string $value, string $default): string
    {
        $clean = trim(strip_tags($value));

        if ($clean === '') {
            return $default;
        }

        return mb_substr($clean, 0, self::LABEL_MAX);
    }

    /**
     * Which preset the stored values correspond to, or 'custom'.
     *
     * Derived, never stored — see the note on THEMES. Compared after
     * normalise() on both sides, so a hand-edited '#e8a33d' still reads as
     * Classic rather than as Custom.
     *
     * @param  array<string, bool|string>  $current  as returned by all()
     */
    public static function activeTheme(array $current): string
    {
        foreach (self::THEMES as $name => $theme) {
            $same = true;

            foreach ($theme['values'] as $key => $value) {
                if (($current[$key] ?? null) !== self::normalise($key, $value)) {
                    $same = false;
                    break;
                }
            }

            if ($same) {
                return $name;
            }
        }

        return 'custom';
    }

    /**
     * The themes with their values normalised, so the screen can draw swatches
     * without carrying a second copy of the defaults.
     *
     * @return array<string, array{label: string, note: string, values: array<string, bool|string>}>
     */
    public static function themes(): array
    {
        $out = [];

        foreach (self::THEMES as $name => $theme) {
            $values = [];

            foreach ($theme['values'] as $key => $value) {
                $values[$key] = self::normalise($key, $value);
            }

            $out[$name] = ['label' => $theme['label'], 'note' => $theme['note'], 'values' => $values];
        }

        return $out;
    }
}
