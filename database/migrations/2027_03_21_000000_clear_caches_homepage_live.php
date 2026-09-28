<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for homepage live editing.
 *
 * A CHANGED ROUTE TABLE. `routes/web.php` gains
 * `require __DIR__.'/homepage-live-admin.php';` inside the admin-api group, and
 * the router dispatches against `bootstrap/cache/routes-*.php` rather than
 * against the source. Without this clear, `POST /admin-api/homepage/live` does
 * not exist on the server and the Live preview tab answers 404 on a screen that
 * otherwise looks finished -- the worst shape of failure, because the tab is
 * there and the operator concludes the feature is broken rather than unapplied.
 *
 * AND A CHANGED COMPILED VIEW, which matters as much here and is easy to
 * forget. The tab, its two viewport buttons, the section panel and the control
 * renderer live in `resources/views/admin/partials/homepage-content-screen
 * .blade.php`, and Blade serves `storage/framework/views` in preference to the
 * template. A server that keeps the old compiled admin bundle has the route and
 * no tab to reach it.
 *
 * NOTHING IS WRITTEN AND NOTHING MOVES. The endpoint renders the storefront
 * homepage from the arrangement on screen and writes nothing: the settings row
 * is untouched, no cache key is evicted, and the reader handed to the renderer
 * throws on save(). NO SETTING IS ADDED by this release, and no section changes
 * the value it ships at -- the three controls the panel draws (Show on desktop,
 * Show on mobile, Grid style) are the same three `HomepageSections` has always
 * stored, drawn from its own SECTION_SCHEMA rather than from a second list, and
 * they are saved through the endpoint that already saved them. A shop that has
 * never opened this screen serves the same bytes it served before, which is
 * what tests/Feature/HomepageLiveEditTest.php's "the shop itself never carries
 * a selection hook" case asserts against the rendered page.
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
            echo "Cleared {$cleared} compiled files; Appearance -> Homepage content now has a\n"
                ."Live preview tab. Click any section in the picture and its own controls open\n"
                ."beside it; the picture redraws as you change them and nothing reaches the shop\n"
                ."until you press Save. No setting changed and nothing on the storefront moved.\n";
        }
    }

    public function down(): void {}
};
