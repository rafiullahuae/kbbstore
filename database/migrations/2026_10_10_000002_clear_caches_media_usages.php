<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the `media_usages` index. (Lane BF)
 *
 * NO ROUTE IS ADDED BY THIS PACKAGE, and that is worth saying rather than
 * leaving the reader to check: the Media Library's routes already exist in
 * routes/media-library-admin.php and this lane changed what two of their
 * handlers read, not their paths. The cached route table is still cleared
 * below, because it costs nothing and because the one time it is skipped on
 * the assumption that nothing moved is the time something did.
 *
 * WHAT ACTUALLY NEEDS CLEARING HERE IS bootstrap/cache/services.php.
 * AppServiceProvider::boot() now calls App\Support\MediaUsageWriter::listen(),
 * which is what registers the model hooks that keep the table honest. A stale
 * compiled services/packages manifest on a host where OPcache cannot be
 * restarted is exactly the shape of failure CLAUDE.md still cites packages
 * 2.60.102-.106 for — and the failure mode is quiet: the screen works, the
 * table simply stops being written, and every image saved after the package
 * landed reads as unused.
 *
 * CONFIG and VIEWS go too, for the standing reason.
 *
 * No schema change: the table is created by the migration beside this one, and
 * nothing here positions a column with an AFTER clause — the thing that made
 * nine earlier migrations in this directory silent no-ops on MySQL.
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
