<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the site-wide delivery claims — Lane CZ.
 *
 * TWO BLADE FILES CHANGED AND BOTH DECIDE WHAT A SHOPPER IS TOLD, which is why
 * this migration is not optional here:
 *
 *   partials/announcement.blade.php   the strip of site chrome. Its
 *                                     free-delivery figure is now the one for
 *                                     the country the VISITOR is in, it names
 *                                     no country, and where the shop records no
 *                                     free delivery there it prints nothing
 *                                     rather than a figure of the template's
 *                                     own. (This partial is included by no view
 *                                     in the repository today — see the note in
 *                                     tests/Feature/SitewideDeliveryClaimsTest —
 *                                     so the change is dormant until the
 *                                     integrator wires it in. It is still
 *                                     recompiled here: a stale compiled copy of
 *                                     a partial that is about to be included is
 *                                     exactly the surprise this file exists to
 *                                     prevent.)
 *
 *   store/home.blade.php              four claims. The delivery band's threshold
 *                                     and the ticker's now come from the shop's
 *                                     own shipping configuration for the
 *                                     visitor's country instead of, respectively,
 *                                     a setting with no admin writer and a
 *                                     number typed into the template. The
 *                                     ticker's delivery sentence comes from
 *                                     App\Support\DeliveryLine, the reader the
 *                                     band, the checkout and the product page
 *                                     already share. The About block no longer
 *                                     states a delivery window as a statistic,
 *                                     and the trust row's delivery card is built
 *                                     from what the shop records or is not shown.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND NOTHING CHANGES. The server serves Blade
 * from storage/framework/views, so the old compiled home page would go on
 * printing the old figures out of a file that is no longer on disk — and the
 * symptom is not an error, it is the defect continuing to look unfixed. That is
 * the shape this repo has been bitten by before.
 *
 * OPcache is the other half, and it matters here because two PHP classes change
 * and both are on the storefront's hot path: ShippingService gains
 * thresholdHere(), which resolves that figure for the visitor's country and
 * memoises it on the Request, and StoreComposer calls it instead of asking for
 * the shop's own country. The host cannot be restarted or shelled into, so the
 * PHP a package writes is not the PHP the server runs until OPcache lets go of
 * the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. A server left running the old composer
 * would hand every visitor the same threshold while the new templates print it,
 * which is the worst of both: per-visitor markup fed a one-country number.
 *
 * NO ROUTE CHANGED and no schema changed. The route cache is dropped with the
 * rest anyway, because the cost is nil and a half-cleared cache is the harder
 * thing to reason about.
 *
 * `free_shipping_threshold` IS DELIBERATELY NOT DELETED FROM THE SETTINGS TABLE.
 * After this change it has no reader at all — it had exactly one, the home
 * page's delivery band, and no writer anywhere — but a migration that deletes a
 * row an owner may have typed into is not recoverable, while a row nothing reads
 * is merely dead. The same restraint Lane CT applied to `trust_delivery_text`.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made nine
 * earlier migrations silent no-ops on MySQL.
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
