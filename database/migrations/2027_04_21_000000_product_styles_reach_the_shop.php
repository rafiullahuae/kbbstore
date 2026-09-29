<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Appearance → Product styles starts working, and the shop does not move.
 *                                                                   (Lane AD)
 *
 * ── WHAT WAS WRONG ─────────────────────────────────────────────────────────
 *
 * ProductStyles::cssVariables() and ::bodyClass() were called from
 * resources/views/admin/app.blade.php AND NOWHERE ELSE. So the admin preview
 * drew them and the shop did not, and TWENTY of that screen's controls had
 * never moved a pixel of the storefront. Measured, not read:
 * ProductStylesReachTheShopTest moves every key in the schema off its default
 * and re-renders the home page, /shop, a category, a brand page and a product
 * page. Fifteen of the twenty are wired up in layouts/store.blade.php in this
 * release; five are removed as second answers to questions other screens
 * already own.
 *
 * ── WHY THIS MIGRATION HAS TO CLEAR THOSE FIFTEEN ROWS ─────────────────────
 *
 * Rule 1: nothing that already works may change, and applying a package moves
 * nothing until somebody moves a slider.
 *
 * These controls have NEVER had an effect. So any value stored against them is
 * a value the owner set, saw do nothing, and moved on from — it is not a
 * preference, it is a record of a control that lied. Wiring them up without
 * clearing them would take a year-old slider position that has never been
 * rendered and apply it to a live shop the moment the package lands: the card
 * corners, the badge colours or the brand line on every grid on the site
 * changing on their own, which is precisely what rule 1 forbids.
 *
 * So the rows go, `all()` falls back to the schema defaults, and every default
 * is the value the stylesheet was ALREADY falling back to — 14px, 1/1,
 * #E23B57, #1F9D55, #2A2228, #E8A33D, #E0567B, #FFFFFF, and all seven toggles
 * on, which makes bodyClass() the empty string. The rendered pixels are
 * identical before and after. The screen now agrees with the shop, for the
 * first time, and the next move of a slider is the first one that counts.
 *
 * ── WHAT A SHOP WITH A NON-DEFAULT STORED VALUE SEES ───────────────────────
 *
 * Nothing changes on the storefront. On the SCREEN, a control he had moved
 * reads back at its shipped value again. That is the honest outcome and it is
 * said plainly in the console line below, because it is the one thing about
 * this release somebody could otherwise notice and not understand.
 *
 * THE FIVE REMOVED KEYS ARE NOT DELETED. grid_columns is still written by the
 * Ecommerce panel's Catalogue layout and by LayoutApiController, and deleting a
 * row those still save would be a change to screens this lane does not own.
 * grid_columns_tablet, grid_columns_mobile, grid_gap and cart_label are simply
 * left where they are: they are read by nothing now, they cost four rows, and
 * removing data is not worth doing for tidiness on a live shop.
 *
 * ── AND THE COMPILED CACHES ────────────────────────────────────────────────
 *
 * No route is added, so this is not a clear_caches migration in the usual
 * sense, but resources/views/layouts/store.blade.php is REWRITTEN and every
 * storefront page extends it. storage/framework/views keys a compiled view by
 * its source path and decides staleness on file times, and an unzip's
 * timestamps are not reliably newer than what is on disk — so re-shipping the
 * source does nothing on its own and deleting the compiled copy is what makes
 * it take effect. app/Services/ProductStyles.php changes too, which is opcache
 * one layer down.
 *
 * ▲ THE `migrations` FLAG IN update.json IS WHAT DECIDES WHETHER THIS RUNS AT
 *   ALL — UpdateRunner::hasMigrations() never looks at the files. Build the
 *   package with `php artisan kbb:package <version> --since=<ref>`, never a
 *   hand-rolled script.
 */
return new class extends Migration
{
    /**
     * The fifteen that become live in this release.
     *
     * Listed by hand rather than read from ProductStyles::SCHEMA, because a
     * migration must do the same thing for ever: a later release adding a
     * sixteenth control must not make this one clear it retrospectively on a
     * server that is only now catching up.
     */
    private const NEWLY_WIRED = [
        'card_radius',
        'image_ratio',
        'show_brand',
        'show_category',
        'show_rating',
        'show_was_price',
        'show_discount',
        'show_new',
        'show_cart',
        'sale_colour',
        'new_colour',
        'price_colour',
        'star_colour',
        'cart_bg',
        'cart_fg',
    ];

    public function up(): void
    {
        $reset = 0;

        // Guarded: a fresh install runs every migration in order and this one
        // must not be the reason a brand-new database fails to build.
        try {
            $reset = DB::table('settings')->whereIn('key', self::NEWLY_WIRED)->delete();
        } catch (\Throwable $e) {
            // The settings table not being there yet is the only shape this
            // takes, and it means there is nothing to reset. Named rather than
            // swallowed silently, so a real failure is readable in the output.
            if (app()->runningInConsole()) {
                echo 'Product styles: could not read the settings table ('.$e->getMessage()."); nothing to reset.\n";
            }
        }

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings',
            'kbb.home.rails', 'kbb.admin.cats', 'kbb.admin.brands'] as $key) {
            Cache::forget($key);
        }

        // The shortcode grids cache their rendered HTML, so a card that has
        // just changed colour would otherwise keep the old markup until the TTL.
        // Guarded for the same reason as above — this runs during a fresh build
        // too, where the class's own dependencies may not be reachable yet.
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
            echo "Cleared {$cleared} compiled files. Appearance -> Product styles now actually\n"
                ."changes the shop. Card roundness, Image shape, the six colours and the seven\n"
                ."\"what the card shows\" toggles were drawn in the admin preview and emitted to\n"
                ."the storefront by nothing, on every release so far; they reach all five\n"
                ."product grids now.\n"
                ."\n"
                ."NOTHING ON YOUR SHOP MOVES. Those {$reset} stored value(s) were reset to the\n"
                ."values the pages were already rendering, because a control that has never\n"
                ."done anything has never shown you what its setting looks like. If you had\n"
                ."moved one of those sliders in the past it reads at its shipped value again --\n"
                ."move it now and you will see it take effect.\n"
                ."\n"
                ."Columns (desktop, tablet, phone), Gap between cards and Button wording are\n"
                ."gone from that screen. The column count comes from Appearance -> Site layout,\n"
                ."the gap is set per grid on purpose (/shop and the related rail are narrower\n"
                ."than the home rails), and the button's words are in Content -> Translations.\n";
        }
    }

    /**
     * Not reversible, and deliberately a no-op rather than a guess.
     *
     * The values this deleted were never rendered, so there is nothing to put
     * back that anybody has seen; and writing the schema defaults in on the way
     * down would CREATE rows where a fresh install has none, which is a
     * different state from the one this started in.
     */
    public function down(): void {}
};
