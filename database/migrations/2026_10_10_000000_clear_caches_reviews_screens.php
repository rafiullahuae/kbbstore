<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the four Reviews screens. (Lane BE)
 *
 * ROUTES first, the sharp edge CLAUDE.md names: this package adds ten, in
 * routes/reviews-screens-admin.php, and the server serves
 * bootstrap/cache/routes-*.php. Until that file is gone every path the four
 * screens call answers 404 — and the screens are wired correctly, so the owner
 * would be looking at four screens that each say "could not be loaded" with no
 * clue that the cause is a cached route table rather than their data. The
 * Export / Import screen is the worst of the four to get this wrong on: its
 * import button would answer 404 to a file the owner had just spent an evening
 * preparing.
 *
 * VIEWS, for the same reason Lane BC recorded. Four new Blade partials are
 * pulled into resources/views/admin/app.blade.php, and compiled Blade is keyed
 * by path with no content check — so the console would keep rendering the
 * PREVIOUS compilation of app.blade.php, without the partials, and all four
 * Reviews entries would go on printing "isn't installed yet" from a file that
 * no longer says that. app.blade.php itself changed too (the four ids joined
 * LIVE_RENDERED), and that change is invisible until the compiled copy goes.
 *
 * CONFIG and SERVICES go too, for the standing reason in CLAUDE.md: OPcache on
 * a host that cannot be restarted is why packages 2.60.102-.106 are still cited
 * there.
 *
 * NO SCHEMA CHANGE. Nothing in this package adds a column, a table or an index
 * — the review import writes `reviews.source` and `reviews.source_id`, which
 * have existed since the Phase 0 schema, and relies on the unique index over
 * the pair that 2026_09_22_000000_add_import_external_ids already adds. In
 * particular nothing here positions a column with an AFTER clause, the thing
 * that made nine earlier migrations in this directory silent no-ops on MySQL.
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
