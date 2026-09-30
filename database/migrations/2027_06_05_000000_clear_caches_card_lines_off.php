<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The card shows the name, the rating, the price and the button.    (Lane CARD)
 *
 * ── WHAT THE OWNER ASKED FOR, VERBATIM ─────────────────────────────────────
 *
 * "i like this option, but i want to hide the brand name, category name by
 *  default. only name, rating (if any), pricing and cart buttons also make sure
 *  the grid must remain same heighted overall even if the name of the product
 *  is long. i need all equal height in desktop and mobile both. apply this
 *  everywhere."
 *
 * And, the same week, about defaults in general:
 *
 * "whatever i said, keep applying on the site, don't let me know that change
 *  from the backend, i have the options on backend, i want to apply such things
 *  directly to the site to save time."
 *
 * So this release MOVES the shop: the brand line and the category eyebrow come
 * off every product tile the moment it is applied, and the card's rows are
 * reserved so that every tile in a grid is the same height at every width. Both
 * lines are still controls — Appearance → Product styles → Card content →
 * "Brand name" and "Category label" — because a change with no way back is
 * worse than no change. He simply does not have to go and find them.
 *
 * ── WHY THIS MIGRATION DELETES TWO SETTINGS ROWS ───────────────────────────
 *
 * ProductStyles::all() falls back to the schema default ONLY when the row is
 * absent, and what this release changed is the default. A shop that has ever
 * pressed Save on Appearance → Product styles has a row for each of these
 * fifteen keys — the screen posts the whole tab — so on that shop the new
 * default would never be seen and the card would arrive unchanged, which is the
 * one outcome he asked against.
 *
 * Two rows, named. `show_rating` is NOT among them: it did not change value,
 * only the place the decision is taken, and a shop that has switched the stars
 * off chose that.
 *
 * ── AND THE COMPILED CACHES, WHICH IS THE OTHER HALF ───────────────────────
 *
 * resources/views/components/product-card.blade.php is REWRITTEN — it reads
 * three keys off ProductStyles now and omits the markup, rather than leaving
 * the hiding to a stylesheet that reaches three pages of the shop.
 * storage/framework/views keys a compiled view by its SOURCE PATH and decides
 * staleness on file times, and an unzip's timestamps are not reliably newer
 * than what is already on disk. So re-shipping the Blade does nothing on its
 * own: deleting the compiled copy is what makes it take effect, and every
 * storefront page with a grid on it draws that component.
 *
 * app/Services/ProductStyles.php changes too, which is opcache one layer down.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script. CLAUDE.md records five packages whose migrations were
 *   shipped and never ran; this one carries a visible change, so a package that
 *   skipped it would ship a card that looks exactly as it did before.
 *
 * ── AND public/build MUST TRAVEL WITH IT ───────────────────────────────────
 *
 * The equal heights are six grid tracks in resources/css/kbb/kbb-grid-skins.css
 * and in the second copy of that sheet inside kbb.css. `npx vite build` is
 * manual here and CI does not run it, so a package without public/build/ ships
 * the hiding and not the reservation: the two lines would come off and the rows
 * of unreviewed products would still be 26px short.
 */
return new class extends Migration
{
    /**
     * The two whose shipped value moved, listed by hand.
     *
     * Not read from ProductStyles::SCHEMA, for the reason
     * 2027_04_21_000000_product_styles_reach_the_shop.php gives: a migration
     * must do the same thing for ever, and a later release moving a third
     * default must not make this one clear it retrospectively on a server that
     * is only now catching up.
     */
    private const DEFAULT_MOVED = [
        'show_brand',
        'show_category',
    ];

    public function up(): void
    {
        $reset = 0;

        // Guarded: a fresh install runs every migration in order and this one
        // must not be the reason a brand-new database fails to build.
        try {
            $reset = DB::table('settings')->whereIn('key', self::DEFAULT_MOVED)->delete();
        } catch (\Throwable $e) {
            // The settings table not being there yet is the only shape this
            // takes, and it means there is nothing to reset. Named rather than
            // swallowed silently, so a real failure is readable in the output.
            if (app()->runningInConsole()) {
                echo 'Product card: could not read the settings table ('.$e->getMessage()."); nothing to reset.\n";
            }
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings',
            'kbb.home.rails', 'kbb.admin.cats', 'kbb.admin.brands'] as $key) {
            Cache::forget($key);
        }

        // The shortcode grids cache their rendered HTML, so a card that has just
        // lost two lines would otherwise keep the old markup until the TTL.
        // Guarded for the same reason as above.
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
            echo "Cleared {$cleared} compiled files. The product card now shows the product\n"
                ."name, the rating where there is one, the price and the Add to cart button --\n"
                ."the brand line and the category label above the name are gone from every\n"
                ."product grid on the site.\n"
                ."\n"
                ."THIS ONE DOES CHANGE YOUR SHOP, because you asked for it: \"i want to hide\n"
                ."the brand name, category name by default\". {$reset} stored value(s) were\n"
                ."cleared so the new default is what you see.\n"
                ."\n"
                ."Both are still switches, at Appearance -> Product styles -> Card content:\n"
                ."\"Brand name\" and \"Category label\". Turn either back on and it comes back\n"
                ."on every grid at once.\n"
                ."\n"
                ."And every card in a grid is now exactly the same height -- on a desktop and\n"
                ."on a phone -- whether a product has a long name, a short name, reviews or\n"
                ."none. Measured before: a row of products with no reviews was 26px shorter\n"
                ."than the rows around it.\n";
        }
    }

    /**
     * Not reversible, and deliberately a no-op rather than a guess.
     *
     * Writing `1` back in on the way down would CREATE rows where a shop that
     * had none before this ran has none, which is a different state from the one
     * this started in; and the shipped default is in the code, so rolling the
     * code back is what puts the two lines back.
     */
    public function down(): void {}
};
