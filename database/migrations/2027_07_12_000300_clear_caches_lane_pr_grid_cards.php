<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The product grid card: no NEW / -N% pills, more products on scroll, no
 * hover on a phone, and spacing and type controls.                 (Lane PR)
 *
 * ── WHAT THE OWNER ASKED FOR, VERBATIM (2 October 2026) ────────────────────
 *
 * 1. "Turn off by default on the product grid card, new and discount tag."
 * 2. "Remove pagination from the categories and brands; it should load more
 *     products via scroll with grey loading stuff. We have built it already,
 *     just keep this on by default, the products should load automatically
 *     by default upon scroll."
 * 3. "Remove grid hover from products in mobile. The hover of grid card, I
 *     want to turn off by default on mobile devices only."
 * 4. "Inner grid spacing: I need the full control of grid card spacing like
 *     between image, title, pricing row, add to cart, and also control of
 *     font bold etc."
 *
 * The first three MOVE THE SHOP when this is applied, because he asked for
 * each of them in as many words (CLAUDE.md rule 1, as of 30 September). The
 * fourth adds controls whose every default is today's measured value and
 * which emit nothing until one is moved, so it moves nothing.
 *
 * ── WHY THIS MIGRATION DELETES THREE SETTINGS ROWS ─────────────────────────
 *
 * Each of these three reads its stored row first and its shipped default only
 * when there is none, and what this release changed is the default. A shop
 * that has ever pressed Save on Appearance → Product styles has a row for
 * `show_new` and `show_discount` (the screen posts the whole tab), and one that
 * has saved Appearance → Site layout → Loading more products has a
 * `layout_load_mode` row. On that shop the new default would never be seen and
 * the change he asked for would arrive as nothing — the outcome he asked
 * against. Lane CARD's 2027_06_05 migration deleted its pair for this reason.
 *
 * `hover_phone` and the twenty-one Spacing & type keys are NEW, so there is no
 * row to clear.
 *
 * ── AND THE COMPILED CACHES ────────────────────────────────────────────────
 *
 * components/product-card.blade.php, components/product-grid.blade.php,
 * partials/listing-batch.blade.php, partials/shop-appearance-css.blade.php and
 * store/brands.blade.php all change. storage/framework/views keys a compiled
 * view by its source path and decides staleness on file times, and an unzip's
 * timestamps are not reliably newer than what is on disk — so deleting the
 * compiled copies is what makes them take effect. The PHP (ProductStyles,
 * SiteLayout, BrandController, ListingBatch) is opcache one layer down.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL. Build the package with `php artisan kbb:package <version>
 *   --since=<ref>`, never a hand-rolled script.
 *
 * ── AND public/build MUST TRAVEL WITH IT ───────────────────────────────────
 *
 * The phone-hover block is in resources/css/kbb/kbb.css. A package without
 * public/build/ ships the setting and not the rule, and a tapped card on a
 * phone would still lift and zoom.
 */
return new class extends Migration
{
    /**
     * The three whose shipped value moved, listed by hand rather than read
     * from the schemas: a migration must do the same thing for ever, and a
     * later release moving a fourth default must not make this one clear it
     * retrospectively on a server that is only now catching up.
     */
    private const DEFAULT_MOVED = [
        'show_new',
        'show_discount',
        'layout_load_mode',
    ];

    public function up(): void
    {
        $reset = 0;

        // Guarded: a fresh install runs every migration in order and this one
        // must not be the reason a brand-new database fails to build.
        try {
            $reset = DB::table('settings')->whereIn('key', self::DEFAULT_MOVED)->delete();
        } catch (\Throwable $e) {
            if (app()->runningInConsole()) {
                echo 'Product grid card: could not read the settings table ('.$e->getMessage()."); nothing to reset.\n";
            }
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings',
            'kbb.home.rails', 'kbb.admin.cats', 'kbb.admin.brands'] as $key) {
            Cache::forget($key);
        }

        // The shortcode grids cache their rendered HTML, so a card that has just
        // lost its pills would otherwise keep the old markup until the TTL.
        try {
            \App\Support\Shortcodes::flush();
        } catch (\Throwable) {
        }

        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; {$reset} stored value(s) reset so the new defaults show.\n"
                ."\n"
                ."THIS ONE DOES CHANGE YOUR SHOP, because you asked for it:\n"
                ."  - the NEW and -% pills are off on every product card\n"
                ."    (Appearance -> Product styles -> Card content -> New badge / Discount badge)\n"
                ."  - /shop, every category and every brand page load more products as you\n"
                ."    scroll, with grey placeholders while they come\n"
                ."    (Appearance -> Site layout -> Loading more products)\n"
                ."  - a tapped card on a phone no longer lifts, shadows or zooms\n"
                ."    (Appearance -> Product styles -> Layout -> Card hover effects on phones)\n"
                ."\n"
                ."NEW, and moving nothing until you move it: card spacing and type, phone and\n"
                ."desktop, at Appearance -> Product styles -> Spacing & type.\n";
        }
    }

    /**
     * Not reversible, and deliberately a no-op rather than a guess: writing
     * the old values back would CREATE rows a shop that had none never had,
     * and the shipped defaults are in the code, so rolling the code back is
     * what restores them.
     */
    public function down(): void {}
};
