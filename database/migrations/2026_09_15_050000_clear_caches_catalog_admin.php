<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Categories & Attributes package.
 *
 * ROUTES. This package adds routes/catalog-admin.php — eleven endpoints under
 * /admin-api/categories and /admin-api/attributes. A compiled route cache
 * knows about none of them, so until it is cleared both tabs load, issue their
 * first GET, take the 404 as "no rows", and draw an empty screen. That is the
 * same failure mode the whole change exists to remove: a screen that looks
 * like it works. Same convention every route-adding package here follows.
 *
 * VIEWS. resources/views/admin/app.blade.php changed, and the admin console is
 * that one compiled Blade file. A stale storage/framework/views copy keeps
 * serving the previous build of the page, which still carries the hard-coded
 * preview screens — invented categories and invented attributes — over a
 * working API. Clearing it is what makes the new tabs actually appear.
 *
 * No schema change: `categories`, `attributes` and `attribute_values` already
 * carry every column these screens read and write, so there is nothing to add
 * and, in particular, no ->after() anywhere near this package.
 *
 * OPcache too, since the controllers are new classes on a host where the
 * previous compiled map has no entry for them.
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
