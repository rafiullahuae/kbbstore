<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the editor's combined Images panel.
 *
 * No route is added here, so the sharp edge is VIEWS and it is sharp for the
 * reason that does not announce itself: the only changed Blade file,
 * resources/views/admin/partials/product-editor-screen.blade.php, ALREADY
 * EXISTS on the server, and compiled Blade is keyed by path with no content
 * check. An existing file is exactly the case that never self-corrects. The
 * console would go on serving the previous compiled copy, the main image and
 * the gallery would stay stacked in two separate cards, and the package would
 * look inert rather than broken — which is worse, because there is nothing to
 * report and nothing in any log.
 *
 * OPcache goes too, on the standing grounds that the host cannot be restarted
 * — the reason packages 2.60.102-.106 are still cited in CLAUDE.md.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations in this repo silent no-ops on
 * MySQL.
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
