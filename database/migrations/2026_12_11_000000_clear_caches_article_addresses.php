<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the article-address preview — Lane A, Phase 13.
 *
 * TWO NEW ROUTES SHIP WITH THIS PACKAGE, in routes/import-articles-admin.php:
 *
 *     GET /admin-api/import/article-addresses
 *     GET /admin-api/import/article-addresses.csv
 *
 * The route table is compiled on the server and the host has no shell, so a
 * route added by an update package does not exist until `bootstrap/cache/
 * routes-*.php` is gone. Without this file the download the owner is told to
 * take before cutover 404s on a site whose routes are cached, and the thing he
 * would be missing is the list of articles the import is going to refuse —
 * which he would then discover after the cutover, from Search Console.
 * CLAUDE.md makes the pairing a convention for exactly this reason.
 *
 * `config.php`, `services.php` and `packages.php` go too, for the reason every
 * clear_caches migration here gives: a compiled config left beside a cleared
 * route cache is the file most likely to be stale for an unrelated reason, and
 * the cost is one rebuild on the next request.
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
            echo "Cleared {$cleared} compiled files; Store -> Import can now list the articles\n";
            echo "whose addresses this storefront already owns.\n";
        }
    }

    public function down(): void {}
};
