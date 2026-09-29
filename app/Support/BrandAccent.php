<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The owner's brand colour, and the one place that decides whether to emit it.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * It was one expression inside App\View\Composers\StoreComposer::compose(),
 * and that composer is registered for `layouts.store` AND NOTHING ELSE:
 *
 *     View::composer('layouts.store', StoreComposer::class);
 *
 * FIVE storefront views do not extend that layout — store/blog, store/post,
 * store/review-wall, store/skin-quiz and store/app each carry their own
 * <html>, <head> and inline stylesheet. `$kbbAccent` has therefore never been
 * defined on any of them, and all five hard-code `--pink:#E0567B` on their own
 * `:root` instead. Counted in their stylesheets:
 *
 *     skin-quiz    12 uses of var(--pink), 14 of var(--pink-deep)
 *     review-wall   8                       5
 *     post          1                       6
 *     blog          2                       5
 *     app           0                       0   (a different palette entirely)
 *
 * So a shop that changes its brand colour changes /shop/, the home page, the
 * cart and the checkout — and the journal, an article, the review wall and the
 * skin quiz keep the old pink, on every button, chip, star and heading. Four
 * live URLs, and nobody had noticed.
 *
 * The expression is here now and read by both callers, so the composer and the
 * five documents cannot answer this question differently.
 *
 * ── THE `null` IS THE FEATURE ──────────────────────────────────────────────
 *
 * A shop that has not moved its brand colour gets NOTHING — no `<style>`
 * element, not one byte, on any page. That is rule 1 and it is also why the
 * design default is compared here rather than at each call site: the string
 * `#E0567B` is the value the stylesheet already declares, so emitting it would
 * be a new element on forty pages that renders identically.
 *
 * The comparison is CASE-INSENSITIVE, which is not decoration: ModuleSchema's
 * colour cast upper-cases what it stores and the admin's own picker sends lower
 * case, so `#e0567b` and `#E0567B` are both "the default" and both have been in
 * this column.
 */
final class BrandAccent
{
    /** The colour the stylesheet already declares, in `resources/css/kbb/kbb.css`. */
    public const DEFAULT = '#E0567B';

    /**
     * `['base' => …, 'deep' => …]`, or null when the shop is on the default.
     *
     * @return array{base: string, deep: string}|null
     */
    public static function pair(?SettingsService $settings = null): ?array
    {
        $settings ??= app(SettingsService::class);

        $accent = (string) $settings->get('brand_accent', self::DEFAULT);

        if (! Color::isValidHex($accent) || strtolower($accent) === strtolower(self::DEFAULT)) {
            return null;
        }

        return ['base' => $accent, 'deep' => Color::darken($accent, 12)];
    }

    /**
     * The declarations, or '' — the whole of what a document needs to emit.
     *
     * The selector list is a literal here and identical to the one
     * layouts/store.blade.php has always written, so the layout and the five
     * standalone documents send the same bytes. `.kbb-checkout` and `.kbb-cart`
     * do not exist on any of the five; they are kept because ONE writer that
     * emits one string is the point, and a rule for a class that is not on the
     * page costs the page nothing and cannot be wrong.
     *
     * Rule 5: the only thing a saved value can influence is a colour that has
     * been through Color::isValidHex(), inside a declaration whose every other
     * byte is a literal in this file.
     */
    public static function css(?SettingsService $settings = null): string
    {
        $pair = self::pair($settings);

        if ($pair === null) {
            return '';
        }

        return ':root,.kbb-checkout,.kbb-cart{--pink:'.$pair['base'].';--pink-deep:'.$pair['deep'].';}';
    }
}
