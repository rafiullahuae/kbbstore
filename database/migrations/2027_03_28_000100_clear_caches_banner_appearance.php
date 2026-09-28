<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the cards banner's appearance round.
 *
 * Phase 22 round 7, Lane BP.
 *
 * TWO NEW ROUTES. `routes/banners-admin.php` gains
 * `POST /admin-api/banners/sets/{set}/preview` (the row drawn from the editor's
 * unsaved buffer) and `PUT /admin-api/banners/sets/{set}/all` (the Save
 * button). The router dispatches against `bootstrap/cache/routes-*.php` rather
 * than against the source, so without this clear the new Save button 404s on
 * every press while the rest of the screen works — which reads as "saving is
 * broken", not as "the package has not finished applying".
 *
 * AND THREE CHANGED TEMPLATES, which matters as much:
 *
 *   resources/views/partials/home/cards-banner.blade.php     background, title
 *                                                            placement, button
 *                                                            colours, the dot
 *                                                            that marks the card
 *   resources/views/admin/partials/banners-screen.blade.php  the buffered editor
 *
 * Blade serves `storage/framework/views` in preference to the template, so a
 * server keeping the old compiled bundle would have the new columns, the new
 * routes, and neither screen able to use them.
 *
 * NOTHING MOVES ON THE SHOP WHEN THIS RUNS, and that is checked rather than
 * asserted. The seven new columns ship at what the page already draws —
 * `bg_mode='none'`, `title_pos='below'`, four empty colour strings whose
 * absence means "the shop's own" — so an existing set renders the same bytes it
 * rendered before the package applied. CardsBannerAppearanceTest renders a set
 * at the shipped values and compares it against the same set rendered with the
 * columns dropped; StorefrontEnglishUnchangedTest covers the rest of the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
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
            echo "Cleared {$cleared} compiled files. Appearance -> Banners now has a Save\n"
                ."button: edits to a set and its cards are held until you press it, and the\n"
                ."preview redraws from what you have typed. A set can also take a background\n"
                ."(none, a colour or a picture), its own button colours, and its title either\n"
                ."below the picture or on it. Nothing on the storefront moved: every new\n"
                ."control ships at the value the page already had.\n";
        }
    }

    public function down(): void {}
};
