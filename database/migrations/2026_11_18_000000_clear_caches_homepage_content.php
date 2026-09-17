<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Drop the compiled routes AND the compiled views (Lane FO, Phase 15 —
 * Appearance → Homepage content).
 *
 * BOTH, because this package changes both halves.
 *
 * THE ROUTES. routes/homepage-content-admin.php adds
 * GET and POST /admin-api/homepage/content. This host serves a COMPILED route
 * table and has no shell, so until bootstrap/cache/routes-v7.php is gone the
 * new endpoints 404 — and a 404 from a stale route cache and a 500 from a query
 * read identically from the console, which is why the screen's own error
 * handler names the two apart. The screen would load, say "Could not load", and
 * an owner would reasonably conclude the feature did not ship.
 *
 * THE VIEWS, for the reason every clear_caches migration in this set gives:
 * Blade names a compiled file by a hash of the view's PATH, never its contents,
 * and the freshness check is a filemtime comparison. An update package is an
 * unzip, and the timestamps it lands are whatever the archive carried — not
 * reliably newer than a compiled file the running site wrote. Two views move in
 * this package and both fail silently if their stale copy survives:
 *
 *   resources/views/admin/app.blade.php   gains one @include for the new screen
 *                                         (admin/partials/homepage-content-screen
 *                                         .blade.php). Stale, the sidebar row
 *                                         never registers and Appearance has no
 *                                         Homepage content row at all.
 *   resources/views/store/home.blade.php  now escapes the hero headline and
 *                                         prints the About paragraph only when
 *                                         there is one. Stale, the OLD template
 *                                         keeps printing the headline unescaped
 *                                         — which is the sink this package
 *                                         closes, still open, while the console
 *                                         beside it invites the owner to type
 *                                         into exactly that field.
 *
 * That last one is the reason this migration is not optional. Everywhere else a
 * stale view means a feature that has not arrived; here it would mean a feature
 * that arrived without its guard.
 *
 * NOTHING IS SEEDED. `home_banners` is left ABSENT, which is what makes
 * HomepageContent::slides() fall through to DEFAULT_SLIDES — the three slides
 * this shop has been rendering all along, carried across byte for byte. So
 * applying this package changes the storefront by nothing at all, and the first
 * write is the owner's. Seeding the defaults would reach the same screen state
 * by a longer route and would also convert "the owner has never touched this"
 * into "the owner has approved this", which is the distinction the editor
 * exists to restore.
 *
 * Best-effort throughout, like the rest of this set: a file that cannot be
 * unlinked mid-update must not fail the package and strand the site
 * half-updated. A stale cache is a visible bug; a failed migration is an outage.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            base_path('bootstrap/cache/routes-v7.php'),
            base_path('bootstrap/cache/routes.php'),
            base_path('bootstrap/cache/config.php'),
            storage_path('framework/views/*.php'),
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
            echo "Cleared {$cleared} compiled files; /admin-api/homepage/content is now\n";
            echo "reachable, and the homepage stops printing the hero headline unescaped.\n";
        }
    }

    /** Nothing to undo: deleting a cache is not a change to reverse. */
    public function down(): void {}
};
