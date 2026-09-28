<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled route table for the address scheme.
 *
 * WHAT SHIPS WITH THIS. routes/kbb-brands-blog.php and
 * routes/kbb-journal-legacy.php, which together register every address the
 * scheme adds and every 301 off the ones it retires:
 *
 *     /collections/{path}/     the category archive   (was /product-category/…)
 *     /brands/, /brands/{slug}/  the brand directory and landing pages
 *     /blog/, /blog/{slug}/    the journal and its articles
 *
 * plus the forwarding routes for /product-category/…, /korean-skincare-brands/…,
 * /brand/{slug}/, /skincare-guide/… and /{slug}/ at the site root.
 *
 * WHY THE MIGRATION. A package is an unzip, and a compiled route table left
 * behind by the running site is what decides which paths exist at all. This
 * exact failure is already on the record twice — 2026_09_13_140000 was written
 * because without it "the new /skincare-guide/ URLs keep 404ing and the old ones
 * keep serving", and 2026_11_21_000000 for the move before this one. Without
 * this file the owner applies the package and every new address 404s while every
 * old one goes on serving a page whose canonical names an address the shop does
 * not answer — which is worse than not moving at all.
 *
 * It also clears App\Http\Middleware\CheckRedirects' cached source index. That
 * index is keyed on `redirects.source` and the migration beside this one
 * (..._000100_url_scheme_redirect_rows) rewrites rows; a stale index would go on
 * claiming addresses whose rows have been deleted.
 *
 * Compiled Blade goes too: several storefront views changed, the cache is keyed
 * by path with a filemtime comparison, and an unzip lands whatever timestamps
 * the archive carried.
 *
 * No schema change and nothing to undo, so down() is empty.
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

        // The redirect index, which is a cache entry rather than a file. Wrapped
        // because a cache store that is not reachable during an update must not
        // fail the package — the same rule the unlinks above follow.
        try {
            \App\Http\Middleware\CheckRedirects::flushIndex();
        } catch (\Throwable) {
            // A stale index is a visible bug; a failed migration is an outage.
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files. Category archives are now at\n";
            echo "/collections/, brands at /brands/ and articles at /blog/; every old\n";
            echo "address 301s onto its final home in a single hop.\n";
        }
    }

    public function down(): void {}
};
