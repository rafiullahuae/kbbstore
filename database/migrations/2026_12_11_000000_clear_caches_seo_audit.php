<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the SEO Audit screen and the sitemap's image
 * entries.
 *
 * ── ONE NEW ROUTE, WHICH IS WHY THIS MIGRATION EXISTS ────────────────────
 *
 * GET /admin-api/seo-audit, declared in routes/seo-audit-admin.php and
 * required from routes/web.php inside the existing admin-api group. On this
 * host the route table is COMPILED — bootstrap/cache/routes-*.php — and a
 * route added to a route file does not exist until that file is gone. Without
 * this migration the package applies cleanly, the screen ships, and every
 * scan returns 404 with nothing in the log to explain it. CLAUDE.md records
 * the convention: every package that adds a route ships a clear_caches_*
 * migration.
 *
 * ONE CHANGED BLADE, resources/views/admin/app.blade.php, compiled into
 * storage/framework/views and keyed by PATH rather than by contents — the
 * freshness check is a filemtime compare and an unzip's timestamps are not
 * reliably newer than what is already on disk.
 *
 * ── NO DEFAULT MOVES ─────────────────────────────────────────────────────
 *
 * The sitemap gained the ability to carry <image:image> entries and it is OFF.
 * `sitemap_images` is absent from the settings table after this migration, and
 * SeoSettings resolves an absent key to its declared default of '0', so
 * /sitemap.xml emits the bytes it emits today until somebody ticks the box at
 * Store → SEO & Meta → Settings · Sitemap. That is rule 1: a new setting ships
 * at the value the page already has.
 *
 * NO SETTING ROWS ARE WRITTEN, AND NO SCHEMA CHANGE.
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
            echo "Cleared {$cleared} compiled files; Store -> SEO & Meta -> SEO Audit is live at\n"
                ."/admin-api/seo-audit, and the sitemap can carry product images once the\n"
                ."Sitemap band's image box is ticked (it ships off).\n";
        }
    }

    public function down(): void {}
};
