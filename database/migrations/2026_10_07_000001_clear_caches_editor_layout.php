<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the product editor's panel arrangement. (Lane AS)
 *
 * ROUTES are the sharp edge. This package adds three paths under /admin-api,
 * and the live host serves a compiled route cache — a host with no shell where
 * the only way to rebuild that cache is a migration like this one. Without it
 * the rearranged screen renders in full, the operator drags a panel, and the
 * save 404s: the layout appears to work until the page is reloaded and every
 * panel is back where it started, with nothing anywhere saying why.
 *
 * VIEWS matter just as much here and for the less obvious reason. The only
 * changed Blade file, resources/views/admin/partials/product-editor-screen
 * .blade.php, ALREADY EXISTS on the server, and compiled Blade is keyed by
 * path with no content check — so an existing file is exactly the case that
 * does not self-correct. The console would keep serving the previous compiled
 * copy, the arrange controls would never appear at all, and the package would
 * look inert rather than broken.
 *
 * CONFIG and SERVICES go too: a new controller class and a new model are
 * autoloaded, and OPcache on a host that cannot be restarted is the standing
 * reason packages 2.60.102-.106 are still cited in CLAUDE.md.
 *
 * No schema change HERE — the table is created by
 * 2026_10_07_000000_create_admin_screen_layouts.php, which runs first — and
 * nothing in this package positions a column with an AFTER clause, the thing
 * that made nine earlier migrations in this repo silent no-ops on MySQL.
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
