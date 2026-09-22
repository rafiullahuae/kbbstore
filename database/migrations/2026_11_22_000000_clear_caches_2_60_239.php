<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views for 2.60.239.
 *
 * WHY IT NEEDS ITS OWN, and cannot lean on any earlier clear_caches migration:
 * a migration runs once. An earlier one has either already run on this host, in
 * which case it will not run again and clears nothing for this package, or it
 * is arriving in the same zip, in which case it runs before these files land.
 * Either way this package needs a clear of its own, and a package that changes
 * a Blade file without one is how 2.60.102-.106 shipped inert.
 *
 * Compiled Blade is keyed by the view's PATH, never by its contents, and its
 * freshness check is a filemtime comparison. An update package is an unzip, so
 * the timestamps it lands are whatever the archive carried and are not reliably
 * newer than the compiled copy the running site wrote. A stale copy of a file
 * that already exists is exactly the case that does not self-correct.
 *
 * WHAT IS STALE IF THIS DOES NOT RUN. Every visible change in the package:
 *
 *   - resources/views/store/cart-inner.blade.php and cart-squeeze.blade.php —
 *     the trust row keeps its text chips instead of the drawn payment marks,
 *     and the delivery row and the quantity stepper ignore their new controls.
 *   - resources/views/admin/partials/cart-page-screen.blade.php — the Cart page
 *     preview disagrees with the shop it is a preview of, which is worse than
 *     no preview, because it is what the sliders are set by.
 *   - resources/views/admin/app.blade.php — the Mobile Header preview keeps the
 *     duplicated rule that made four of its own controls move nothing.
 *
 * NO SCHEMA CHANGE, and no route is added by this package. The route and config
 * caches are cleared anyway: it costs nothing, and the new checkout setting is
 * read through the settings service, which a stale config cache can sit in
 * front of.
 *
 * Best-effort, like every clear_caches migration in this set. A file that
 * cannot be unlinked mid-update must not fail the package and strand the site
 * half-updated: a stale cache is a visible bug, a failed migration is an outage.
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
            echo "Cleared {$cleared} compiled files; the cart's trust row now draws real payment\n";
            echo "marks, the delivery row and the quantity stepper take their new sizes, and both\n";
            echo "admin previews agree with the shop again.\n";
        }
    }

    public function down(): void {}
};
