<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the health check that actually looks at the shop.
 *
 * A CHANGED ROUTE, and the router dispatches against
 * bootstrap/cache/routes-*.php rather than against the source. `/_kbb-health`
 * keeps its URI, its name and its token gate, but it is no longer the closure
 * that stood in routes/web.php -- it is routes/update-health.php. Without this
 * clear, a server whose route table was compiled before the package landed goes
 * on serving the old closure, and the old closure is the defect: it runs
 * SELECT 1 and returns JSON, and never renders a page.
 *
 * WHY THAT MATTERS ENOUGH TO SHIP A MIGRATION FOR IT. 2.60.260 removed a class
 * that every product tile resolves out of the container. The home page, /shop,
 * every category, every brand and every product page answered 500. The health
 * check said `{"ok":true}` and the update was kept. An update that takes the
 * shop down is the exact thing that check exists to refuse, and it could not
 * see it.
 *
 * ▲ THIS ONE CHANGES WHICH UPDATES ARE KEPT, and that is a departure from
 * "nothing that already works may change" rather than an accident. A package
 * that leaves the home page or the first visible product page unable to render
 * will now be rolled back automatically, where before it would have been kept.
 * That is the point. `KBB_HEALTH_DEEP=false` in .env is the escape hatch for
 * the one case where the strictness is wrong -- a shop already broken for an
 * unrelated reason cannot otherwise install the package that repairs it.
 *
 * What changed:
 *
 *   app/Services/Update/StorefrontHealth.php
 *                              renders the home page and the first visible
 *                              product page through the HTTP kernel, in the
 *                              process the runner has already reached over
 *                              HTTP -- not a second outbound request, which
 *                              deadlocks a single-worker FPM pool. Asserts
 *                              status 200, a body that ends in </html> (the
 *                              case a status code cannot reach: a fatal
 *                              mid-render flushes the buffer with 200 already
 *                              sent), a length floor, and that the product
 *                              page names its own slug. The slug is DATA read
 *                              from the database a moment earlier, never copy
 *                              -- a lane may rewrite every visible string on
 *                              that page; it may not ship one that no longer
 *                              knows which product it is.
 *
 *   app/Services/Update/UpdateRunner.php
 *                              reads the failure reason out of the response
 *                              body instead of stopping at the status line, so
 *                              Core Updates prints why rather than "HTTP 503".
 *
 *   app/Services/Update/ClassDependencyScan.php
 *                              refuses a package whose PHP and Blade files
 *                              name an App\ class that is neither in the
 *                              package nor already on the server. That is
 *                              exactly what 2.60.260 did: it shipped three
 *                              templates resolving App\Services\VariantPricing
 *                              while the class itself sat in a package that had
 *                              not been applied.
 *
 *   routes/update-health.php   the endpoint itself.
 *
 * AN EMPTY CATALOGUE STILL PASSES: the product half reports `skipped` and the
 * overall answer stays true. And one unrenderable product row cannot brick
 * updates for ever -- it tries the first three visible products and passes if
 * any of them renders, while a shop whose product pages are all down, which is
 * 2.60.260 exactly, fails.
 *
 * NO SETTING ROWS ARE WRITTEN. The only operator switch is KBB_HEALTH_DEEP in
 * .env, and its default is on.
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
            echo "Cleared {$cleared} compiled files; the health check the updater relies on now\n"
                ."renders the home page and a product page instead of running SELECT 1. An update\n"
                ."that breaks either one is rolled back automatically. KBB_HEALTH_DEEP=false in\n"
                .".env turns that back off if a shop is already broken for another reason.\n";
        }
    }

    public function down(): void {}
};
