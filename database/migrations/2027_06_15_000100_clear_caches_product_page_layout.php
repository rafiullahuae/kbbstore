<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Product page → Layout package. (Lane PDP2, R4)
 *
 * NO ROUTE IS ADDED — Admin\ProductPageApiController's two paths already exist
 * and the Layout half travels through them — so the route cache is not the
 * reason this file is here. THE VIEW CACHE IS, and there are three templates in
 * this package whose stale copy fails silently rather than loudly:
 *
 *   resources/views/admin/app.blade.php
 *       NEW: the tabbed Layout area on Appearance → Product page. Compiled
 *       Blade is keyed by path, so a stale copy of this 22,000-line shell is
 *       the case that never self-corrects: the console keeps serving yesterday's
 *       screen, the tab strip simply is not there, and every endpoint behind it
 *       answers perfectly. This is the exact failure 2026_10_05_000002 was
 *       written for, one screen along.
 *
 *   resources/views/store/product.blade.php
 *       NEW: one @include, on the end of an existing line inside @push('styles').
 *       A stale copy means the <style> block carrying the owner's spacing and
 *       type never reaches the page — every slider on the new screen saves,
 *       reports success and moves nothing. "A control wired end to end in the
 *       source, shipped, and moving nothing" is BuiltCssIsCurrentTest's own
 *       docblock and this project has paid for it once already.
 *
 *   resources/views/partials/product-layout-css.blade.php
 *       NEW, and included from the above.
 *
 * OPCACHE matters here too, and for one class in particular:
 *
 *   App\Http\Controllers\Admin\ProductPageApiController
 *       `save()` no longer requires `sections`. A worker holding the previous
 *       compiled copy does not fail to autoload — it serves the old code, and
 *       the old code 422s every Layout-only save with "The sections field is
 *       required", which reads like a bug in the new screen and is not one.
 *
 *   New: App\Services\ProductLayout, App\Support\DemoProductDetails.
 *
 * THE COMPILED STYLESHEET IS NOT A CACHE AND IS NOT CLEARED HERE.
 * resources/css/kbb/kbb-product.css gained thirty `var(--pl-…, <literal>)`
 * reads, so the package must carry the rebuilt public/build/assets/
 * kbb-product-<hash>.css AND public/build/manifest.json. Ship the manifest
 * without the sheet, or the sheet without the manifest, and the page asks for
 * the old hash — which is still on disk, so there is no 404 and no error
 * anywhere and the page renders with yesterday's stylesheet looking untouched.
 * docs/PDP-LEDGER-HANDOVER.md §1 carries that warning in full.
 *
 * SCHEMA DOES CHANGE in this package: 2027_06_15_000000 backfills the demo
 * products' detail tabs. It runs before this file by filename order, which is
 * what the ordering is for.
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
