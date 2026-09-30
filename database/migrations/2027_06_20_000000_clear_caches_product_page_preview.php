<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Product page preview panel. (Lane PDP2, R5)
 *
 *     "where's the preview on the product controls page? i need a proper
 *      preview of mobile and desktop both."
 *
 * NO ROUTE IS ADDED. The two frames load `/product/{slug}` — the shipped page,
 * which every visitor already reaches — and the panel's data comes back on the
 * GET `admin-api/product-page` this screen already makes. So the route cache is
 * not the reason this file exists. THE VIEW CACHE IS, and both templates in
 * this package fail SILENTLY from a stale copy rather than loudly:
 *
 *   resources/views/admin/app.blade.php
 *       The panel and its stylesheet are both INLINE in this 23,000-line shell.
 *       There is no hashed asset to bust: compiled Blade is keyed by path, so a
 *       stale copy serves yesterday's screen — thirty sliders and no preview,
 *       which is the exact complaint this package answers — while the endpoint
 *       underneath answers perfectly and the package reports as applied.
 *
 *   resources/views/store/pdp-preview/_layout.blade.php
 *       Gained `@include('partials.product-layout-css')`, which round 4's
 *       handover reported missing under "found and not fixed". Stale, and the
 *       five design drafts keep drawing an opened detail tab at the shipped
 *       size while the shop draws it at the owner's — a disagreement between
 *       two pages he can have open side by side, with no error anywhere.
 *
 * OPCACHE matters for three classes:
 *
 *   App\Services\ProductLayout
 *       NEW: `PROPS` and `vars()`. A worker holding the previous compiled copy
 *       does not fail to autoload — `ProductLayout::PROPS` on a class that has
 *       no such constant is a fatal Error inside the admin JSON endpoint, so
 *       the whole screen fails to load rather than loading without a preview.
 *
 *   App\Http\Controllers\Admin\ProductPageApiController
 *       `show()` now answers a `preview` key. Old code, and the key is simply
 *       absent: the console draws "There is no visible product to draw yet" on
 *       a shop full of products, which reads like a catalogue bug.
 *
*
 * NO SCHEMA CHANGE, NO STYLESHEET CHANGE. Nothing under resources/css or
 * resources/js is touched by this round, so public/build is unchanged and the
 * manifest warning in docs/PDP-LEDGER-HANDOVER.md §1 does not apply to this
 * package. It applies to the one before it, unchanged.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
