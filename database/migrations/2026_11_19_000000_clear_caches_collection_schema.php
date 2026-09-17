<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the listing-schema and document-locale package
 * (Lane FQ).
 *
 * NO SCHEMA CHANGE AND NO NEW ROUTE. This migration exists only for the caches,
 * and it is here rather than left to UpdateRunner::clearCaches() for the reason
 * every other clear_caches_* file in this directory gives: that call is wrapped
 * in a try/catch that swallows a failure so a cache which will not clear cannot
 * strand a half-applied update. A migration runs after the files land, reports
 * what it did, and is recorded in the release row.
 *
 *   - VIEWS, and this is the one that matters here. Five storefront templates
 *     change their <html> element -- store/blog, store/post, store/skin-quiz,
 *     store/review-wall and store/app -- from the literal lang="en" to
 *     Locale::htmlLang() and Locale::direction(). Compiled Blade is keyed by the
 *     view's PATH and its freshness check is a filemtime comparison, and an
 *     update package is an unzip, so the timestamps it lands are whatever the
 *     archive carried. A stale compiled blog.blade.php goes on serving
 *     <html lang="en"> on /ar/skincare-guide/ -- which is exactly the bug this
 *     package fixes, still present, with the fix apparently applied. That is the
 *     worst shape a defect can take on a host with no shell.
 *
 *   - OPCACHE. App\Support\CollectionSchema and App\Http\Middleware\CacheHeaders
 *     are new classes; App\Support\Seo, App\Support\Facets,
 *     Store\ShopController, Store\CollectionController, Store\BrandController
 *     and Admin\SchemaInspectorApiController have all changed. A worker holding
 *     the old Seo beside a new controller would be handed `type => collection`
 *     and a row set it has no branch for, and would publish the page with no
 *     CollectionPage node at all -- silently, and only on some workers, which is
 *     the hardest kind of report to act on.
 *
 *   - CONFIG AND THE FRAGMENT CACHE, for consistency with the rest of this set.
 *     Nothing here reads a new config key, and the shop's cached sidebar is
 *     untouched; clearing them costs one cold page.
 *
 * NOT CLEARED, deliberately: public/img-cache. It is generated, it is expensive
 * to remake, and nothing in this package changes how a variant is produced or
 * named. docs/IMAGE-PIPELINE-AND-CACHE.md §6 says the directory is safe to
 * delete at any time; that is not a reason to delete it.
 *
 * Best-effort throughout, like every other clear_caches migration in this set:
 * a file that cannot be unlinked mid-update must not fail the package and
 * strand the site half-updated. A stale cache is a visible bug; a failed
 * migration is an outage.
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
            echo "Cleared {$cleared} compiled files; category, brand and the four\n";
            echo "curated listings now publish a CollectionPage/ItemList, and the\n";
            echo "five standalone documents take their <html lang> and dir from\n";
            echo "the locale they are served in.\n";
            echo "App\\Http\\Middleware\\CacheHeaders ships INERT -- it needs one\n";
            echo "line in bootstrap/app.php, which no package may write. See\n";
            echo "docs/FQ-CACHE-HEADERS.md.\n";
        }
    }

    /** Nothing to undo. Deleting a cache is not a change to reverse. */
    public function down(): void {}
};
