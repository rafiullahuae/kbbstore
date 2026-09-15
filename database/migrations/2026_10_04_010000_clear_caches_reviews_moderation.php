<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the Reviews moderation package (Lane AM).
 *
 * WHY THIS PACKAGE NEEDS ONE — all three caches are live here, which is unusual.
 *
 *   - ROUTES. routes/reviews-admin.php adds five routes. CLAUDE.md is explicit:
 *     a route added to this application does not take effect until the compiled
 *     route cache is cleared, because the server has no shell and the cache is
 *     only ever rebuilt by a migration like this one. Without it the moderation
 *     screen loads and every request it makes returns 404, which reads as "the
 *     package did not apply" when in fact it did.
 *
 *   - VIEWS. resources/views/admin/app.blade.php changed: the whole Reviews
 *     region was replaced. Compiled Blade is keyed by path, so a stale copy of
 *     a file that already exists is exactly the case that never self-corrects —
 *     the console would go on rendering the old screen, calling the old
 *     endpoints, indefinitely.
 *
 *   - OPCACHE. App\Support\ReviewStatus, App\Support\ProductRating and
 *     App\Http\Controllers\Admin\ReviewsApiController are NEW classes, which
 *     OPcache handles cleanly. The trap is the data migration beside this one:
 *     it calls into ReviewStatus and ProductRating, and a worker holding a
 *     stale compiled tree is how "the migration ran and changed nothing" gets
 *     reported.
 *
 * NO SCHEMA CHANGE HERE. The package's one table —
 * `review_status_backfill`, the reversal journal — is created by
 * 2026_10_04_000000_normalise_review_statuses.php, which is the only thing that
 * writes it, so the two can never be applied apart. Nothing in this package
 * positions a column with an AFTER clause: that is the clause that made nine
 * earlier migrations in this repo silent no-ops on MySQL, where an ALTER naming
 * a column that does not exist yet is an error and the hasColumn guards around
 * them made that error look clean.
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
