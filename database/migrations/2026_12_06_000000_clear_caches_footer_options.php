<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the footer's second round of options, and for the
 * checkout screen's side-by-side mobile tabs.
 *
 * NO NEW ROUTES. /admin-api/slim-footer shipped in 2.60.251 and the nineteen
 * new fields travel inside its existing response, so the route table is
 * cleared here only because it costs nothing. What matters is:
 *
 *   THREE CHANGED BLADES, compiled into storage/framework/views and keyed by
 *   PATH rather than by contents — the freshness check is a filemtime compare,
 *   and an unzip's timestamps are not reliably newer than what is on disk. A
 *   stale copy here is an Appearance → Footer screen offering four tabs while
 *   the endpoint answers with four, drawing a bar that has none of the new
 *   shapes.
 *
 * What changed:
 *
 *   app/Services/SlimFooter.php
 *   resources/views/partials/slim-footer.blade.php
 *                                   nineteen more controls, all of them
 *                                   shipping at the value the bar already had.
 *                                   Shape and alignment are kept as TWO
 *                                   controls rather than one list of sixteen:
 *                                   four structures times four alignments is
 *                                   sixteen looks from eight words, and every
 *                                   option added later would otherwise double
 *                                   the list.
 *
 *                                   Also a third link, a small-print line and
 *                                   an optional row of payment marks. The
 *                                   marks are App\Support\PaymentMarkArt's
 *                                   hardcoded constant and these switches
 *                                   choose which of the six is printed; that
 *                                   class's own header explains why artwork
 *                                   assembled from a setting would be a
 *                                   stored-XSS sink on the page orders are
 *                                   placed from. They sit on a white chip
 *                                   because scheme artwork is drawn for a
 *                                   light ground and went nearly invisible on
 *                                   the dark tone.
 *
 *   resources/views/admin/partials/slim-footer-screen.blade.php
 *                                   a fourth tab, and a preview that draws
 *                                   every one of the new options at both
 *                                   widths.
 *
 *   resources/views/admin/partials/checkout-page-screen.blade.php
 *                                   the four Mobile tabs put their preview
 *                                   beside the controls. Only those four: the
 *                                   desktop mock stands for a 1040px page with
 *                                   two columns in it, and drawn 372px wide it
 *                                   shows nothing anybody can judge. Folds back
 *                                   to one column below 1180px.
 *
 * NO DEFAULT CHANGES THE PAGE. Every new control ships at what the bar was
 * already doing, so a shop that has turned the footer on sees it unchanged.
 *
 * NO SETTING ROWS ARE WRITTEN, AND NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; the footer has four shapes, four alignments,\n"
                ."five tones and a row of payment marks, and the checkout screen's Mobile tabs\n"
                ."show their preview beside the controls.\n";
        }
    }

    public function down(): void {}
};
