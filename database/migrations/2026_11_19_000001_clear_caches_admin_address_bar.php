<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled admin console for the address-bar fix.
 *
 * The only file in this package's console change is
 * `resources/views/admin/app.blade.php`, and compiled Blade is keyed by the
 * view's PATH, never by its contents, with a filemtime comparison for
 * freshness. An update package is an unzip, so the timestamps it lands are
 * whatever the archive carried and are not reliably newer than the compiled
 * copy the running site wrote.
 *
 * Without this, the owner applies the package and the address bar still reads
 * `#payments/stripe` on every screen — the exact symptom he reported, with the
 * fix apparently applied, which is the most expensive kind of non-fix.
 *
 * No route changes and no schema changes. The route cache is cleared anyway
 * because it costs nothing on a host where a compiled route table is what
 * decides whether a path exists at all.
 *
 * Best-effort, like every clear_caches migration here: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the admin address bar now follows the screen\n";
            echo "and #payments/stripe reloads to Payments instead of the dashboard.\n";
        }
    }

    public function down(): void {}
};
