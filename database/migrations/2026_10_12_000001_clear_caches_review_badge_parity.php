<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the rating-badge parity change.
 *
 * Three Blade files changed (store/product.blade.php, store/review-wall.blade.php,
 * admin/partials/review-badges-screen.blade.php), so the compiled view cache is
 * stale and the old badge would keep rendering from it. No route changed, so the
 * route cache is correct either way; it is cleared with the rest because the
 * cost is nil and a half-cleared cache is the harder thing to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * The inlined review stylesheet needs no help here. ProductController::
 * reviewsCss() keys its cache on the file's mtime, and a package rewrites
 * sorina-reviews.css on apply, so the new contents land under a new key on the
 * first request. Setting::map() is forgotten explicitly because its key carries
 * no such fingerprint and the badge switches are read through it.
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
        // no-op, and the badge settings are re-read from the database anyway.
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
