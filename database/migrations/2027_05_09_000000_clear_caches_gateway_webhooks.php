<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Store → Gateway webhooks.                (Lane TM)
 *
 * ── WHY THIS SHIPS WITH A PACKAGE THAT ADDS NO ROUTE ────────────────────────
 *
 * It does not add one, and that is worth saying first: all seven endpoints this
 * screen calls were already live, already mounted inside the `admin-api` group
 * in routes/web.php, and already mapped in AdminCapabilities. The defect was
 * that nothing in the console called any of them.
 *
 * The staleness here is the OTHER kind, the one
 * 2026_12_20_000001_clear_caches_set_appearance.php sets out: A CHANGED BLADE.
 * resources/views/admin/app.blade.php gains one `@include`, one TITLES row and
 * one LATE_RENDERED entry, and resources/views/admin/partials/
 * tamara-connection-screen.blade.php arrives beside it. Both are compiled into
 * storage/framework/views, a compiled copy is keyed by PATH rather than by
 * contents, and the freshness check is a filemtime compare — an unzip's
 * timestamps are not reliably newer than what is already on disk.
 *
 * A stale compiled console here is the SAME failure this whole lane is about,
 * arriving a second time by a different road: the package lands complete and
 * the admin draws yesterday's console, with no Gateway webhooks row in the
 * sidebar and no way to register the webhook. Nothing 500s and nothing is
 * logged, which is exactly how the original went unnoticed for a release.
 *
 * bootstrap/cache/routes-*.php goes with it. Not because a route changed, but
 * because the compiled route table is what decides whether
 * /admin-api/payments/tamara/* answers at all on this host, and this is the
 * first package in which anything reachable by a human depends on that. If the
 * gateway's own clear_caches migration was ever applied to a host whose cache
 * did not in fact get rebuilt, every button on the new screen 404s while
 * rendering perfectly — and the screen says so in those words rather than
 * drawing an empty panel, which is a good failure and still a dead screen.
 *
 * ── WHAT CHANGED ───────────────────────────────────────────────────────────
 *
 *   resources/views/admin/partials/tamara-connection-screen.blade.php
 *                                   the screen. Tamara's webhook state, its
 *                                   registration, the removal (which confirms
 *                                   in place first), the basket limits and the
 *                                   recovery sweep; and Tabby's per-market
 *                                   webhook registration, which had the same
 *                                   defect one gateway over. It registers its
 *                                   own sidebar row inside Store and wraps
 *                                   window.go, so one include, one TITLES row
 *                                   and one LATE_RENDERED entry are the whole
 *                                   of the change to the console.
 *
 *   app/Console/Commands/TamaraWebhookCommand.php
 *   app/Console/Commands/TamaraLimitsCommand.php
 *                                   the same two jobs from the Cloudways shell,
 *                                   for the day the console or its route cache
 *                                   is the broken thing. They call
 *                                   TamaraGateway's own methods rather than
 *                                   reimplementing them.
 *
 * NO SETTING ROWS ARE WRITTEN and no default is moved. The screen reads state
 * the gateway already stores and writes nothing until a button is pressed, so
 * applying this package leaves the storefront byte-identical and leaves every
 * gateway exactly as configured as it was.
 *
 * NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files. Store -> Gateway webhooks can now\n"
                ."register the Tamara webhook that carries declines and expiries -- there\n"
                ."was no way to do that from anywhere before, because Tamara's own\n"
                ."merchant portal has no screen for it either. Without it a refused order\n"
                ."sat at 'pending' for ever, holding its stock and its coupon.\n"
                ."\n"
                ."Nothing on the shop has moved and no gateway setting has changed. Open\n"
                ."Store -> Payments -> Tamara, press 'Webhook & limits...', then\n"
                ."'Register the webhook'. docs/TM-GATEWAY-WEBHOOKS.md is the walkthrough.\n";
        }
    }

    public function down(): void {}
};
