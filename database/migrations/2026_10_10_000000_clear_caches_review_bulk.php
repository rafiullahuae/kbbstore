<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Reviews → Bulk Add and Reviews → Bulk Likes. (Lane BD)
 *
 * ROUTES are the sharp edge, the one CLAUDE.md names first: this package adds
 * three, in routes/review-bulk-admin.php, and the server serves
 * bootstrap/cache/routes-*.php. Until that file is gone, every path both
 * screens call answers 404 — and the screens are wired correctly, so the owner
 * would be looking at a working-looking form whose Create button reports a
 * server error, with no clue that the cause is a cached route table rather than
 * their data. Both screens say so in that exact case rather than showing a bare
 * failure, but the fix is this file.
 *
 * VIEWS matter as much, and for two separate reasons. The screens are a new
 * Blade partial (resources/views/admin/partials/review-bulk-screens.blade.php)
 * pulled into resources/views/admin/app.blade.php, and compiled Blade is keyed
 * by path with no content check — so the admin console would go on rendering
 * the PREVIOUS compilation of app.blade.php, without the partial, and Bulk Add
 * and Bulk Likes would keep printing the "isn't installed yet" card from a file
 * that no longer produces one. app.blade.php already exists on the server,
 * which is precisely the case that does not self-correct.
 *
 * CONFIG and SERVICES go too, for the standing reason in CLAUDE.md: OPcache on
 * a host that cannot be restarted is why packages 2.60.102-.106 are still cited
 * there.
 *
 * NO SCHEMA CHANGE. Bulk Add writes into `reviews` using the columns that table
 * has carried since 0001_01_01_000000_create_kbb_schema.php, and Bulk Likes
 * writes `reviews.helpful`. Nothing here adds a column, and in particular
 * nothing positions one with an AFTER clause — the thing that made nine earlier
 * migrations in this directory silent no-ops on MySQL (see
 * tests/Feature/MigrationConventionTest.php).
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
