<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the part-by-part import upload. (Lane IE2)
 *
 * THE ROUTE CACHE IS THE REASON THIS FILE EXISTS. routes/import-parts-admin.php
 * registers four new paths under the admin-api group, and CLAUDE.md is explicit:
 * "A route added in `routes/web.php` will not take effect until the compiled
 * route cache is cleared, so every package that adds a route also ships a
 * `clear_caches_*` migration."
 *
 * Without it the screen's feature probe finds no endpoint, falls back to the
 * one-request upload, and the owner is back to a browser error with no number
 * in it on the Orders zip — a package that reports as applied and changes
 * nothing he can see.
 *
 * THE VIEW CACHE MATTERS TOO. The uploader is inline in
 * resources/views/admin/partials/import-parts-screen.blade.php, which
 * resources/views/admin/app.blade.php includes. Compiled Blade is keyed by
 * path, so a stale copy serves the screen without the slicing code while the
 * endpoints answer perfectly underneath.
 *
 * OPCACHE matters for two classes that are NEW rather than changed —
 * App\Services\ImportConsole\UploadParts and
 * App\Http\Controllers\Admin\ImportPartsController — and for
 * App\Support\AdminCapabilities, which gained two RULES entries. A worker
 * holding the previous compiled AdminCapabilities falls through to the
 * `admin-api/import/**` wildcard, which grants the same capability, so that
 * one degrades to the right answer for the wrong reason. The two new classes
 * simply have to be loadable.
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
