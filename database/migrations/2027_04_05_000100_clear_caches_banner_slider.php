<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the picture slider.
 *
 * Phase 22 round 8, Lane BN2.
 *
 * NO NEW ROUTE, and that is worth saying rather than leaving the reader to
 * infer it: the second banner type is a COLUMN on `banner_sets`, not a second
 * module, so every endpoint it uses — the screen's payload, the Save button,
 * both previews — is one `routes/banners-admin.php` already registers. The
 * compiled route table does not need to change and nothing on the admin screen
 * can 404 because of this package.
 *
 * WHAT DOES HAVE TO BE CLEARED IS BLADE. Four templates changed:
 *
 *   resources/views/partials/home/slider-banner.blade.php    new — the section
 *   resources/views/store/home.blade.php                     picks the partial
 *                                                            from the set's kind
 *   resources/views/admin/partials/banners-screen.blade.php  the type picker and
 *                                                            the slider controls
 *   (the cards banner's own partial is untouched)
 *
 * Blade serves `storage/framework/views` in preference to the template, so a
 * server keeping the old compiled bundle would have the new columns, the new
 * screen and a homepage that still hard-names the cards partial — a slider the
 * owner can build, publish and never see.
 *
 * NOTHING MOVES ON THE SHOP WHEN THIS RUNS. `kind` ships at `cards`, which is
 * what every row in that table already is, and the other three columns are read
 * only by a slider — of which there are none until somebody makes one.
 * SliderBannerTest renders a set at the shipped values and compares it byte for
 * byte against the same set rendered before these columns existed;
 * StorefrontEnglishUnchangedTest covers the rest of the shop.
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
            echo "Cleared {$cleared} compiled files. Appearance -> Banners now offers a\n"
                ."SECOND banner type: Banner type -> Picture slider. Pictures only, one at a\n"
                ."time, with real previous/next buttons and a row of thin bars along the\n"
                ."bottom that both show which picture is up and jump to it. Four looks to\n"
                ."choose from under Banner type -> Look. Nothing on the storefront moved:\n"
                ."every existing set is still a cards banner and draws the same bytes.\n";
        }
    }

    public function down(): void {}
};
