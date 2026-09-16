<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the "media library everywhere" change.
 *
 * Two Blade files changed and both are admin console views:
 * admin/app.blade.php (the shared image field — SEO share image, organisation
 * logo, brand logo, category image, attribute swatch) and
 * admin/partials/category-tree-screen.blade.php (the category edit dialog).
 *
 * The compiled view cache is the whole reason this migration exists. Both files
 * build their markup inside <script> blocks, so the raw "Choose File" the owner
 * reported is baked into storage/framework/views/*.php; without this the server
 * would keep serving the compiled copy and the button would still be there
 * after the package applied. No route changed — the picker is a pure consumer
 * of /admin-api/media and /admin-api/media/upload, which both predate it — so
 * the route cache is correct either way. It is cleared with the rest because
 * the cost is nil and a half-cleared cache is the harder thing to reason about.
 *
 * OPcache is the other half. The host cannot be restarted or shelled into, so
 * the PHP a package writes is not the PHP the server runs until OPcache lets go
 * of the old copy — the standing reason behind the withdrawn packages
 * 2.60.102-.106 recorded in CLAUDE.md.
 *
 * Setting::map()'s cached copy is deliberately NOT forgotten here. No setting
 * changed, and this migration has no business evicting a key another lane's
 * work may be mid-write on.
 *
 * No schema change, and nothing here positions a column with an AFTER clause,
 * the thing that made nine earlier migrations silent no-ops on MySQL.
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
