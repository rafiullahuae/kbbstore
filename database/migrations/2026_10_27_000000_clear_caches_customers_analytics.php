<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Dashboard / Analytics / Quiz Leads rebuild.
 *
 * A ROUTE CHANGED, so this one is not optional. routes/quiz-leads-admin.php
 * adds PUT /admin-api/quiz-leads/{id}, and a route added to routes/web.php does
 * nothing at all until the compiled route cache is dropped — the standing rule
 * in CLAUDE.md. Without this migration the package would apply cleanly, the
 * Quiz Leads screen would render its new status control, and every attempt to
 * use it would 404 against the cached route table.
 *
 * The view cache is stale too: resources/views/admin/app.blade.php carries all
 * three rebuilt screens, and the compiled copy would keep serving the old
 * markup — including the "Conversion" tile that was never a conversion rate and
 * the unescaped concern chips.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md. AdminController and AdminCapabilities
 * both changed here, and the capability map failing closed means a stale copy
 * would 403 the new route for everyone but an owner rather than fail loudly.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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

        // Best effort: on a host with no cache table or a cold store this is a
        // no-op, and the settings are re-read from the database anyway.
        try {
            Cache::forget('kbb.settings.map');
        } catch (\Throwable) {
            // Nothing to do — a missing cache store is not a failed migration.
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
