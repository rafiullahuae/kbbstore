<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the mobile tap-target and price-wrapping pass.
 *
 * No route and no Blade changed here — the change is CSS plus the rebuilt
 * bundles under public/build, and the manifest that names them.
 *
 * The manifest is why this migration exists. `@vite` resolves every stylesheet
 * through public/build/manifest.json, and the compiled Blade views under
 * storage/framework/views cache the <link> tags that resolution produced. A
 * package that ships new hashed CSS without clearing those compiled views
 * leaves the old filenames in the markup, and the server answers 404 for
 * stylesheets that the package did not upload under their old names — the
 * storefront renders unstyled. Clearing the compiled views forces @vite to read
 * the new manifest on the next request.
 *
 * OPcache is cleared for the same standing reason as every other migration in
 * this set: the host cannot be restarted or shelled into, so a file a package
 * writes is not the PHP the server runs until OPcache lets go of the old copy.
 * That is the failure behind the withdrawn packages 2.60.102-.106 in CLAUDE.md.
 *
 * No schema change, and nothing here positions a column with an AFTER clause.
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
