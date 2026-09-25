<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the homepage preview.
 *
 * A CHANGED ROUTE TABLE. `routes/web.php` gains
 * `require __DIR__.'/homepage-preview-admin.php';` inside the admin-api group,
 * and the router dispatches against `bootstrap/cache/routes-*.php` rather than
 * against the source. Without this clear, `POST /admin-api/homepage/preview`
 * does not exist on the server, and the Preview card answers 404 on a screen
 * that otherwise looks finished -- the worst shape of failure, because the
 * button is there and the operator concludes the feature is broken rather than
 * unapplied.
 *
 * AND A CHANGED COMPILED VIEW, which matters as much here and is easy to
 * forget. The card, its two viewport buttons and the freshness logic live in
 * `resources/views/admin/app.blade.php`, and Blade serves
 * `storage/framework/views` in preference to the template. A server that keeps
 * the old compiled admin bundle has the route and no button to reach it.
 *
 * NOTHING IS WRITTEN AND NOTHING MOVES. The preview renders the storefront
 * homepage from the arrangement on screen and writes nothing -- the settings
 * row is untouched, no cache key is evicted, and the reader handed to the
 * renderer throws on save(). No setting is added, and no section changes the
 * value it ships at: a shop that has never opened this screen serves the same
 * bytes it served before, which the lane proved by fetching `/` with and
 * without the change (88,667 bytes both times, differing by the CSRF field
 * alone).
 *
 * WHY THE ROUTE SITS UNDER `/homepage/`. `AdminCapabilities::RULES` already
 * carries `['*', 'admin-api/homepage/**', 'content.manage']`, and `**` matches
 * everything beneath it. A prefix of its own would fall through to the closed
 * owner-only default, which `AdminCapabilityMapTest` fails by name -- so the
 * prefix is the authorisation, not a naming preference.
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
            echo "Cleared {$cleared} compiled files; Appearance -> Homepage now has a Preview\n"
                ."card that draws the real storefront homepage from the arrangement on screen,\n"
                ."at 1280 and at 390, without saving anything. Nothing on the shop moves until\n"
                ."you press Save as before.\n";
        }
    }

    public function down(): void {}
};
