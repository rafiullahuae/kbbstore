<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the side-by-side product gallery. (Lane AY)
 *
 * This package adds NO route, so the usual sharp edge is not the one here.
 * What it ships is a rebuilt stylesheet under a new content hash
 * (kbb-product-<hash>.css) plus the manifest entry that points at it, and the
 * failure that follows from skipping this migration is the quiet kind.
 *
 * VIEWS are the reason. resources/views/store/product.blade.php resolves the
 * stylesheet through @vite, which reads public/build/manifest.json at RENDER
 * time — but the rendered result is then cached as compiled Blade, and
 * compiled Blade is keyed by path with no content check. The product page is a
 * file that ALREADY EXISTS on the server, which is exactly the case that does
 * not self-correct: the server would keep emitting a <link> to the PREVIOUS
 * hash. That file is still on disk (the package adds the new hash, it does not
 * delete the old one), so nothing 404s and nothing appears in a log. The page
 * simply renders in the old layout, and the package looks inert rather than
 * broken — the hardest state to diagnose from a host with no shell.
 *
 * CONFIG and SERVICES go too, for the standing reason in CLAUDE.md: OPcache on
 * a host that cannot be restarted is why packages 2.60.102-.106 are still
 * cited there.
 *
 * No schema change at all. Nothing here adds a column, and in particular
 * nothing positions one with an AFTER clause — the thing that made nine
 * earlier migrations in this repo silent no-ops on MySQL.
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
