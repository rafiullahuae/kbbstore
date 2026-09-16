<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Content -> HTML Blocks. (Lane BC)
 *
 * ROUTES are the sharp edge this time, the one CLAUDE.md names: this package
 * adds five, in routes/html-blocks-admin.php, and the server serves
 * bootstrap/cache/routes-*.php. Until that file is gone, every path the new
 * screen calls answers 404 — and the screen is wired correctly, so the owner
 * would be looking at an empty list with no clue that the cause is a cached
 * route table rather than their data.
 *
 * VIEWS matter as much, for two separate reasons. The screen is a new Blade
 * partial pulled into resources/views/admin/app.blade.php, and compiled Blade
 * is keyed by path with no content check — so the admin console would keep
 * rendering the PREVIOUS compilation, without the partial, and the HTML Blocks
 * entry would go on printing "isn't installed yet" from a file that no longer
 * says that. And store/page.blade.php and store/post.blade.php now run their
 * content through the @shortcodes directive; those two files already exist on
 * the server, which is exactly the case that does not self-correct. Stale,
 * they render a placed block as literal "[kbb_block slug=...]" text to
 * shoppers.
 *
 * CONFIG and SERVICES go too, for the standing reason in CLAUDE.md: OPcache on
 * a host that cannot be restarted is why packages 2.60.102-.106 are still
 * cited there.
 *
 * No schema change: the `blocks` table is created by the migration beside this
 * one. Nothing here adds a column, and in particular nothing positions one
 * with an AFTER clause — the thing that made nine earlier migrations in this
 * directory silent no-ops on MySQL.
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
