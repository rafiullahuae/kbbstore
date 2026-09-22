<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled admin console for 2.60.242.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN: the whole package. It is one Blade file,
 * resources/views/admin/partials/cart-page-screen.blade.php, and everything in
 * it is a change to that screen — the Desktop tab's preview moving below the
 * controls, its heading, the proportional measurements and the centred address
 * popup. A stale compiled copy is the screen exactly as it was.
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct — and it
 * is how 2.60.102-.106 shipped inert.
 *
 * NO SCHEMA CHANGE, NO NEW SETTING and NO NEW ROUTE. Every control this screen
 * draws already shipped in 2.60.241; this package only changes how they are
 * laid out and previewed.
 *
 * Best-effort, like every clear_caches migration in this set: a file that
 * cannot be unlinked mid-update must not fail the package and strand the site
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
            echo "Cleared {$cleared} compiled files; Appearance -> Cart page -> Desktop now shows its\n";
            echo "preview full width, under the controls.\n";
        }
    }

    public function down(): void {}
};
