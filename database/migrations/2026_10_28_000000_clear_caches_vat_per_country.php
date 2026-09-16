<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for per-country VAT rates — Lane CP.
 *
 * TWO BLADE FILES CHANGED and neither is new:
 *
 *   resources/views/admin/app.blade.php
 *       the Business Details screen gains a "VAT shown on the receipt, by
 *       country" band, the CSS behind it, and two @json payloads
 *       (KBB_VAT_COUNTRIES, KBB_VAT_SUGGESTIONS) built from
 *       App\Support\Countries
 *
 *   resources/views/partials/checkout/order-block.blade.php
 *       the VAT row's LABEL gains a js-vat-label hook so the country-change
 *       refresh can rewrite the percentage it prints, not just the amount
 *
 * The console is ONE compiled view — app.blade.php pulls every partial in with
 * an include, so the compiled file in storage/framework/views holds all of them
 * inlined, and Laravel only recompiles when the PARENT's mtime moves. The
 * checkout partial has the mirror-image problem: it is included by the checkout
 * page and by the mobile place-order box, so its compiled copy lives inside
 * both of theirs. Either way a package that rewrote the file without clearing
 * the compiled views would ship markup the server never renders.
 *
 * NO ROUTE CHANGED. Nothing was added to routes/web.php: the country-change
 * refresh already posts to the existing /api/checkout/rates, and the
 * per-country rates already save through the existing PUT /admin-api/settings.
 * The route cache is cleared with the rest anyway, because the cost is nil and
 * a half-cleared cache is the harder thing to reason about — the same reasoning
 * as 2026_10_27_000000_clear_caches_review_screens_design.
 *
 * resources/js/kbb/checkout.js ALSO CHANGED, and this migration cannot help
 * with that one: assets are built by hand (`npx vite build` — package.json
 * still defines no build script) and served from public/build, which is a
 * different directory from the application root on this host. The package that
 * carries this migration has to carry the rebuilt bundle too, or the checkout
 * will keep running the old script and keep leaving the VAT label stale after a
 * country change.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * No schema change. vat_country_rates is a row in the existing settings table,
 * written through SettingsService, and it is deliberately NOT seeded: the table
 * ships empty so behaviour is identical to before until the owner fills it in.
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
