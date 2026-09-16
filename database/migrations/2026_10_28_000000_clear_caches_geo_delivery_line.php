<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the per-country delivery line — Lane CO.
 *
 * THREE BLADE FILES CHANGED and both storefront ones decide what a shopper is
 * promised about delivery, which is why this migration is not optional here:
 *
 *   store/home.blade.php                      the band under the hero, which
 *                                             printed a UAE delivery promise to
 *                                             every visitor on earth
 *   partials/checkout/delivery-line.blade.php now rendered-and-hidden rather
 *                                             than omitted, so the country-change
 *                                             refresh has an element to reveal
 *   admin/app.blade.php                       Store -> Delivery & Shipping gains
 *                                             a Delivery lines tab
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING CHANGES. The server serves Blade
 * from storage/framework/views, so the old compiled home page goes on printing
 * the old unconditional promise from a file that is no longer on disk — and the
 * symptom is not an error, it is the defect continuing to look unfixed.
 *
 * The console is ONE compiled view: admin/app.blade.php pulls every partial in
 * with @include, so a package that rewrote only a partial would leave the old
 * copy serving from the parent's cached compile. app.blade.php itself changed
 * in this pass, so that trap is not armed — cleared unconditionally anyway, for
 * the same reason 2026_10_27_000000_clear_caches_review_screens_design gives.
 *
 * bootstrap/app.php CHANGED TOO, and that is the other reason the cached
 * bootstrap files below are dropped rather than only the views. The middleware
 * stack now exempts one cookie from encryption — `kbb_tz`, which the browser
 * itself writes and which EncryptCookies was silently discarding, so the
 * time-zone tier of country detection had never fired in a real browser. A
 * stale bootstrap/cache/config.php would leave that exemption unapplied and the
 * only geo signal this host is certain to have would go on being read as null.
 *
 * NO ROUTE CHANGED. The admin screen posts to PUT /admin-api/settings, which
 * has existed since long before this lane; adding `delivery_texts` to
 * AdminController::SETTING_RULES is what lets that existing endpoint accept it.
 * The route cache is dropped with the rest anyway, because the cost is nil and
 * a half-cleared cache is the harder thing to reason about.
 *
 * OPcache is the other half, and it is the reason this matters more than usual
 * here: two new classes arrive in this package (App\Support\ShopperCountry and
 * App\Support\DeliveryLine) and two existing controllers change. The host
 * cannot be restarted or shelled into, so the PHP a package writes is not the
 * PHP the server runs until OPcache lets go of the old copy — the standing
 * reason behind the withdrawn packages 2.60.102-.106 recorded in CLAUDE.md.
 *
 * NO SCHEMA CHANGE. `delivery_texts` is a row in the existing `settings` table,
 * written through SettingsService::set(), which json-encodes a non-scalar. There
 * is deliberately no seeder for it: no delivery window outside the UAE has been
 * measured, so the feature ships empty and every country behaves exactly as it
 * does today until the owner types a line himself.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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
