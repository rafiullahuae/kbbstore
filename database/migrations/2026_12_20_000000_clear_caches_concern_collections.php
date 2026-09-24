<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for concern-led collections, the LocalBusiness address
 * fields and the SEO audit's alt-text check.  Lane S, round 2.
 *
 * ── ONE NEW ROUTE, WHICH IS WHY THIS MIGRATION EXISTS ────────────────────
 *
 * GET /concern/{concern}/, declared in routes/concern-collections.php and
 * required from routes/web.php beside the four existing collection routes. On
 * this host the route table is COMPILED — bootstrap/cache/routes-*.php — and a
 * route added to a route file does not exist until that file is gone. Without
 * this migration the package applies cleanly and /concern/acne/ answers 404
 * with nothing in the log to explain it. CLAUDE.md records the convention:
 * every package that adds a route ships a clear_caches_* migration.
 *
 * ONE CHANGED BLADE, resources/views/admin/app.blade.php — the "Where the shop
 * is" section on Store → Business Details → Business. It is compiled into
 * storage/framework/views and keyed by PATH rather than by contents, and the
 * freshness check is a filemtime compare, so an unzip's timestamps are not
 * reliably newer than what is already on disk.
 *
 * ── NOTHING MOVES WHEN THIS IS APPLIED ───────────────────────────────────
 *
 * All three changes ship inert, which is rule 1 of this project.
 *
 * /concern/acne/ IS A 404 ON THE DAY THIS LANDS, and deliberately. A concern
 * page exists only when it has copy AND at least
 * App\Support\ConcernCollections::MIN_PRODUCTS live, in-stock products tagged
 * for it — and nothing in this package tags a single product. The owner makes
 * the page exist by tagging products for "Acne & blemishes" under
 * Catalog → Build my routine. Until he does, /sitemap.xml does not mention it
 * either: the sitemap asks ConcernCollections the same question the router
 * does, so the two cannot disagree. /sitemap.xml is byte-identical after this
 * migration.
 *
 * THE EIGHT ADDRESS SETTINGS SHIP BLANK. App\Support\BusinessAddress publishes
 * nothing at all unless a street, a city and a recognised country are filled
 * in, so the Organization node in every page's JSON-LD is byte-identical until
 * somebody opens Store → Business Details and types an address.
 *
 * THE AUDIT'S NEW CHECK IS READ-ONLY, on an owner-only endpoint, and reports on
 * data that already exists.
 *
 * NO SETTING ROWS ARE WRITTEN, AND NO SCHEMA CHANGE. `products.image_alts`,
 * which the new audit check reads, was added by
 * 2026_10_05_000000_add_product_editor_columns and is not created here.
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
            echo "Cleared {$cleared} compiled files; Store -> Business Details -> Business\n"
                ."now has the shop's address, and /concern/acne/ goes live once you have\n"
                ."tagged three products for \"Acne & blemishes\" under Catalog -> Build my\n"
                ."routine. Nothing on the shop moves until you do.\n";
        }
    }

    public function down(): void {}
};
