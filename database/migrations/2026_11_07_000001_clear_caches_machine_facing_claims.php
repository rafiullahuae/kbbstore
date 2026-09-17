<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the machine-facing claims sweep — Lane DV.
 *
 * ── WHAT CHANGED ────────────────────────────────────────────────────────────
 *
 * TWO PHP CLASSES AND ONE SETTING. No route, no column, no Blade file.
 *
 *   App\Support\Seo                       The sitelinks SearchAction
 *                                         advertised /shop?q={search_term_
 *                                         string} and nothing in this
 *                                         application has ever read `q` — the
 *                                         shop's own form posts `s` to /shop/.
 *                                         A visitor typing into the search box
 *                                         Google draws under this shop's result
 *                                         was sent to a URL that ignored what
 *                                         they typed. It now names the
 *                                         parameter the page actually filters
 *                                         on, at the address the page
 *                                         canonicalises to.
 *
 *                                         The Offer also published a bare price
 *                                         with no statement of whether tax is
 *                                         inside it. Under an `exclusive` rule
 *                                         it is not, so a result showing AED
 *                                         100 led to a checkout charging AED
 *                                         105 with nothing anywhere saying so.
 *                                         A UnitPriceSpecification now carries
 *                                         valueAddedTaxIncluded, built from the
 *                                         same price and currency the Offer
 *                                         already holds, and emitted only where
 *                                         every destination gives the same
 *                                         answer.
 *
 *   Store\SeoFilesController              /llms.txt offered two "key pages" and
 *                                         both were redirects: /blog 301s to
 *                                         /skincare-guide/ and /shop
 *                                         canonicalises to /shop/.
 *
 *   seo_default_description               Rewritten by the migration beside
 *                                         this one, which carries the full
 *                                         reasoning. Both settings caches are
 *                                         flushed there, at the write.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE SERVER GOES ON RUNNING THE OLD CODE.
 * The host has no shell and cannot be restarted, so OPcache holds the previous
 * copy of both classes until something lets go of it — the searchbox keeps
 * advertising `q`, the Offer keeps saying nothing about tax, and llms.txt keeps
 * naming two redirects. The compiled Blade files go with it because
 * layouts/store.blade.php calls Seo::render() from a compiled copy.
 *
 * THERE IS NO STALE-PAIRING HAZARD. Nothing here changed a key's write path or
 * an endpoint's shape, so a half-applied package is the old output rather than
 * a screen posting keys a controller will drop.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL.
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
