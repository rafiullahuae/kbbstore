<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the coupon editor package (Lane BT).
 *
 * NEW ROUTES, NO SCHEMA CHANGE. routes/coupons-admin.php gains six paths under
 * /admin-api/coupons/manage — the list, the product/category lookup, and
 * create / read / update / delete for one coupon. On this host the route table
 * is compiled, so every one of them 404s until the cache is dropped, and the
 * new screen renders as "the coupon endpoints are not registered on this
 * server yet" while looking perfectly correct in the repo. That is the exact
 * failure the convention in CLAUDE.md exists to prevent: a route added without
 * a clear_caches migration beside it.
 *
 * The view glob matters as much as the route one here. The screen is a Blade
 * partial (resources/views/admin/partials/coupon-editor-screen.blade.php)
 * pulled into resources/views/admin/app.blade.php, and a stale compiled view
 * of that file leaves the console with the Add Coupon button missing and no
 * error anywhere — the same "looks fine, does nothing" shape as the 404.
 *
 * OPcache is the third half of it: App\Http\Controllers\Admin\
 * CouponAdminApiController is a new class, and a worker holding compiled
 * bytecode for the old route table will not find it.
 *
 * Nothing here uses ->after() — there is no ALTER at all. See
 * tests/Feature/MigrationConventionTest.php for why that matters on this host.
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
