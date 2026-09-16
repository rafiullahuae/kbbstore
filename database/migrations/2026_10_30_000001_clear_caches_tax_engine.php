<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the tax engine — Lane CU.
 *
 * TWO BLADE FILES CHANGED and neither is new:
 *
 *   resources/views/admin/app.blade.php
 *       Business Details is now two tabs, Business and Tax; the per-country
 *       VAT table gains a BASIS column, a one-click Gulf preset and a
 *       consequence preview; the sidebar gains a Tax row that opens the same
 *       screen on that tab; the Tax tab carries the tax_mode switch and the
 *       four vat_* fields that used to live on Store -> Ecommerce -> Checkout.
 *
 *   resources/views/partials/checkout/order-block.blade.php
 *       the VAT line is now TWO rows — one above the Total, where an
 *       exclusive tax belongs because it is part of the sum, and one below it
 *       for an inclusive or printed-only figure — with exactly one shown.
 *
 * The console is ONE compiled view: app.blade.php pulls every partial in with
 * an include, so the compiled file in storage/framework/views holds all of them
 * inlined, and Laravel only recompiles when the PARENT's mtime moves. The
 * checkout partial has the mirror-image problem: it is included by the checkout
 * page and by the mobile place-order box, so its compiled copy lives inside
 * both of theirs. Either way a package that rewrote the file without clearing
 * the compiled views would ship markup the server never renders.
 *
 * NO ROUTE CHANGED. Nothing was added to routes/web.php: the country-change
 * refresh still posts to the existing /api/checkout/rates, and the Tax tab
 * saves through the existing PUT /admin-api/settings. The route cache is
 * cleared with the rest anyway, because the cost is nil and a half-cleared
 * cache is the harder thing to reason about — the same reasoning as
 * 2026_10_28_000000_clear_caches_vat_per_country.
 *
 * resources/js/kbb/checkout.js ALSO CHANGED, and this migration cannot help
 * with that one: assets are built by hand (`npx vite build` — package.json
 * still defines no build script) and served from public/build, which is a
 * different directory from the application root on this host. THE PACKAGE THAT
 * CARRIES THIS MIGRATION MUST CARRY THE REBUILT BUNDLE TOO. Without it the
 * checkout keeps running the old script, which knows how to rewrite the VAT
 * figure but not how to move the row — so a shopper switching from an
 * inclusive country to an exclusive one would see the tax printed under a
 * Total it is not part of, and the column of figures would not add up.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * THE SCHEMA CHANGE TRAVELS IN ITS OWN MIGRATION, beside this one:
 * 2026_10_30_000000_add_tax_rate_and_basis_to_orders. No SETTING is seeded by
 * either. `tax_mode` is absent, which reads as 'display'; `vat_country_bases`
 * is absent, which reads as "every country on the shop default". So applying
 * this package changes nothing at all about what any customer is charged until
 * the owner opens the Tax tab and saves.
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
