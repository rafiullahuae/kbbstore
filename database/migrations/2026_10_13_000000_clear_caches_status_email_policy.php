<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the per-status order-email controls.
 *
 * TWO ROUTES ARE NEW, and that is why this file exists rather than being
 * optional tidying. routes/mail-admin.php gained
 *
 *     GET  /admin-api/mail/status-emails
 *     POST /admin-api/mail/status-emails
 *
 * and this host serves from a compiled route cache. Until it is dropped the
 * router has no idea those paths exist, the admin screen's fetch 404s, and the
 * tick boxes look broken in a way that has nothing to do with the code that
 * draws them. CLAUDE.md names this as the standing convention: every package
 * that adds a route ships a clear_caches_* migration.
 *
 * The view cache goes with it. resources/views/admin/app.blade.php changed —
 * the order screen's "Email the customer about this change" tick box and the
 * settings list — and a stale compiled view would keep rendering the screen
 * without them.
 *
 * THE MODULE TOGGLE MAP IS FORGOTTEN TOO. `email_order_shipped` and
 * `email_order_cancelled` are read through SettingsService::moduleEnabled(),
 * which holds its answer in Cache::rememberForever(). Nothing about applying a
 * package invalidates that by itself, and a forever-cache is exactly the kind
 * that outlives the reason it was populated. Cleared here so the first request
 * after the update reads the toggles rather than a snapshot of them.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * No schema change. The per-status setting is a `module_toggles` row that
 * updateOrCreate writes on first use, and the per-order decision is never
 * persisted at all — it governs one save and is gone with the response.
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

        // Best effort on both: on a host with a cold or missing cache store
        // these are no-ops, and every value behind them is re-read from the
        // database. A missing cache store is not a failed migration.
        try {
            Cache::forget('kbb.settings.map');
        } catch (\Throwable) {
            // Nothing to do.
        }

        try {
            // Spelled out rather than imported: SettingsService::MODULES_KEY is
            // private, and widening a shared service's visibility so that one
            // migration can read a string is a worse trade than the string.
            // OrderStatusMailPolicyTest pins this spelling against the private
            // constant, so the two cannot drift apart silently.
            Cache::forget('kbb.modules');
        } catch (\Throwable) {
            // Nothing to do.
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
