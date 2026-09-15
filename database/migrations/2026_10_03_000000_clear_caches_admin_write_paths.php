<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the admin write-paths package (Lane AJ).
 *
 * WHY THIS PACKAGE NEEDS ONE. No route is added, so the route cache is not the
 * risk here — OPcache is, and it is the worse of the two.
 *
 *   - PHP. AdminController is an EXISTING class whose methods changed, which is
 *     the failure mode that does not announce itself: a worker holding a stale
 *     compiled copy does not fail to autoload, it keeps serving the OLD code.
 *     In this package that specifically means PUT /admin-api/products/{id}
 *     would go on accepting `status = 'active'` and go on answering 200 while
 *     the product leaves the storefront — the exact defect the package exists
 *     to stop, still live after a "successful" update. CLAUDE.md requires a
 *     clear_caches migration for any package changing a PHP class, and this one
 *     changes a class every admin request touches.
 *
 *   - VIEWS. resources/views/admin/app.blade.php changed: the Catalog list's
 *     draft badge compared `p.status` against 'active', a value this schema has
 *     no concept of, so it marked every LIVE product as a draft. Compiled Blade
 *     is keyed by path, so a stale copy of a file that already exists is exactly
 *     the case that does not self-correct — the console would keep rendering the
 *     old comparison from the compiled view indefinitely.
 *
 * No schema change. This package adds no column and alters no table: it corrects
 * a status vocabulary, two relations that were being written as columns, the
 * money parse on the product editor, and three reads of columns that do not
 * exist. Nothing here positions a column with an AFTER clause either — the
 * clause that made nine earlier migrations in this repo silent no-ops on MySQL,
 * where an ALTER naming a column that does not exist yet is an error and the
 * hasColumn guards around them made that error look clean.
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
