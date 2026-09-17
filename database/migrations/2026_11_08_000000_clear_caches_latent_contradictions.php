<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the latent-contradictions sweep — Lane DZ.
 *
 * ── WHAT CHANGED ────────────────────────────────────────────────────────────
 *
 * PHP CLASSES AND ONE BLADE FILE. No route, no column, no setting key.
 *
 *   Store\ProductController          vatLine() asserted "Inclusive of" whatever
 *                                    `vat_basis` said, and read the DEFAULT
 *                                    `vat_rate` rather than the shopper's
 *                                    country's. It also carried the shop's
 *                                    authenticity claim welded to the tax
 *                                    sentence, where no admin screen could
 *                                    reach it. Both are gone: the sentence is
 *                                    App\Support\VatDisplay::shelfNote()'s, and
 *                                    the claim keeps its one home in the trust
 *                                    row, through App\Support\TrustClaims.
 *
 *   App\Support\VatDisplay           shelfNote(): the shelf-price sentence for
 *                                    one destination, from the same rule the
 *                                    checkout charges.
 *
 *   App\Services\ProductSections     The VAT module's description on Store →
 *                                    Modules quoted the old sentence, so the
 *                                    admin asserted a basis and repeated a
 *                                    trust claim of its own.
 *
 *   App\Support\Seo                  describe() and tokens(), extracted so the
 *                                    admin's per-product snippet preview can
 *                                    ask what a page will actually publish
 *                                    instead of inventing a sentence. The
 *                                    output of render() is unchanged.
 *
 *   App\Support\ProductSeo           rawDescription() / metaDescription(): the
 *                                    fallback chain, in one place.
 *
 *   App\Support\ProductTitle         head(): the product page's own title, now
 *                                    reachable because it is a {title} token.
 *
 *   Admin\AdminController            GET /admin-api/products/{id} carries
 *                                    `seo_fallback_description`. An ADDED key
 *                                    on a read endpoint: an older shell that
 *                                    does not know about it ignores it, so a
 *                                    half-applied package is the old preview
 *                                    rather than a broken one.
 *
 *   App\Support\RepeatPurchase       New. Measures what /best-sellers/ claims.
 *                                    Nothing calls it from the storefront yet —
 *                                    that wiring is the integrator's, and the
 *                                    tests say exactly where.
 *
 *   store/product.blade.php          @section('title') now calls
 *                                    ProductTitle::head(). Same string.
 *
 *   store/category.blade.php         DELETED. A design mock rendered by no
 *                                    controller and reachable at no URL, which
 *                                    carried its own hardcoded meta description
 *                                    and, until 2.60.193, its own analytics
 *                                    loaders.
 *
 * WITHOUT THIS THE PACKAGE LANDS AND THE SERVER GOES ON RUNNING THE OLD CODE.
 * The host has no shell and cannot be restarted, so OPcache holds the previous
 * copy of every class above until something lets go of it — product pages would
 * keep printing "Inclusive of 5% VAT · Authentic, sourced direct" from a class
 * file that no longer says it. The compiled Blade files go with them, because
 * store/product.blade.php is served from a compiled copy and the deleted mock
 * leaves one behind.
 *
 * THERE IS NO STALE-PAIRING HAZARD. Nothing here changed a key's write path or
 * narrowed an endpoint's shape, so a half-applied package degrades to the old
 * output rather than to a screen posting keys a controller will drop.
 *
 * Nothing here positions a column with an AFTER clause, the thing that made
 * nine earlier migrations silent no-ops on MySQL. Nothing here touches data at
 * all: it deletes compiled artefacts and resets OPcache.
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
