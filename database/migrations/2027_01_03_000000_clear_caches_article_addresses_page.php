<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled caches for the article-address PAGE — Lane U3, Phase 13.
 *
 * ONE NEW ROUTE SHIPS WITH THIS PACKAGE, in routes/import-articles-page.php:
 *
 *     GET /admin-api/import/article-addresses-page
 *
 * The route table is compiled on the server, so a route added by an update
 * package does not exist until `bootstrap/cache/routes-*.php` is gone. CLAUDE.md
 * makes the pairing a convention and this route is exactly the case it is for:
 * the thing behind it is the list of live articles the import is going to
 * refuse, read once, before a cutover. A 404 here is not noticed until Search
 * Console reports the articles missing, by which time the old site is off.
 *
 * THE VIEW CACHE MATTERS MORE HERE THAN IT USUALLY DOES. This route's whole
 * body is a Blade template — `resources/views/admin/article-addresses.blade.php`
 * — and a compiled view from a previous version left in
 * `storage/framework/views` is a page that renders the old columns against the
 * new payload. Every clear_caches migration in this project already sweeps
 * that directory; this one depends on it rather than merely benefiting from it.
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
            echo "Cleared {$cleared} compiled files; Store -> Import now has a PAGE listing\n";
            echo "every article whose address this storefront already owns, with the live\n";
            echo "URL each one is indexed at. It writes nothing.\n";
        }
    }

    public function down(): void {}
};
