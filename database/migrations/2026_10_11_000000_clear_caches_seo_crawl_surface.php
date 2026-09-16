<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the SEO crawl-surface package.
 *
 * No route or config change ships with it, but two of the three things a
 * compiled cache can hold stale do:
 *
 *   - VIEWS, and this is the one that matters. resources/views/layouts/store
 *     .blade.php now decides two things it did not decide before: whether the
 *     page carries noindex (App\Support\Indexability) and that the canonical
 *     always names the slashed form of the URL. Compiled Blade is keyed by the
 *     source path, and Laravel recompiles when the source is NEWER than the
 *     compiled copy — which is a filesystem-timestamp comparison, and a
 *     package unzipped onto this host is not guaranteed to win it. A stale
 *     compiled layout is the worst possible failure here because it is
 *     invisible: every page renders perfectly and goes on telling Google that
 *     /cart is indexable. store/post.blade.php and store/page.blade.php are in
 *     the same position for the h1 demotion.
 *
 *   - OPCACHE. App\Support\Indexability and App\Support\BodyHeadings are new
 *     files, so nothing holds an old copy of them — but Facets, ShopController,
 *     CollectionController and SeoFilesController all changed, and a worker
 *     that still holds the previous compiled copy of SeoFilesController serves
 *     the old sitemap, which is the one listing /brands/ and no brand pages at
 *     all.
 *
 * bootstrap/cache is cleared too, for the same reason every clear_caches_*
 * migration in this directory clears it: it is cheap, it is idempotent, and
 * getting it wrong once costs more than doing it unnecessarily every time.
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
