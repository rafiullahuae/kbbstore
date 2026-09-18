<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled route table for the Journal's two legacy redirects.
 *
 * WHAT SHIPS WITH THIS. routes/kbb-journal-legacy.php, and the five-line
 * replacement in routes/web.php that requires it — the exact anchor is in that
 * file's header and in docs/GA-SKINCARE-GUIDE.md §5. After it, /blog and
 * /post/{slug} 301 to the canonical form of their destination (trailing slash
 * kept, /ar kept) instead of to a near-miss of it.
 *
 * WHY THE MIGRATION. The host is shared hosting with no shell: a package is an
 * unzip, and a compiled route table left behind by the running site is what
 * decides which paths exist at all. This exact failure is already on the
 * record — 2026_09_13_140000_clear_caches_2_60_93 was written because without
 * it "the new /skincare-guide/ URLs keep 404ing and the old ones keep serving",
 * and 2026_09_14_170000 for the move this change finishes. A route file whose
 * behaviour changed but whose compiled copy did not is the same non-fix a third
 * time: the owner applies the package, /ar/blog still lands on the English
 * Journal, and nothing anywhere says why.
 *
 * Compiled Blade goes too. Nothing in this change touches a view — this clears
 * them because the cache is keyed by path with a filemtime comparison, and an
 * unzip lands whatever timestamps the archive carried.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; /blog and /post/{slug} now send\n";
            echo "a reader to the canonical address -- trailing slash kept, and an\n";
            echo "Arabic reader stays in Arabic instead of landing on the English page.\n";
        }
    }

    public function down(): void {}
};
