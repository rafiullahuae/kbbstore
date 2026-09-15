<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the Customers aggregate fix.
 *
 * THIS MIGRATION EXISTS BECAUSE ITS ABSENCE COST A RELEASE.
 *
 * 2.60.121 changed CustomersApiController and shipped no new migration. Every
 * migration in that package had already run when 2.60.120 was applied, so
 * applying .121 ran none at all — and every opcache_reset() in this project
 * lives inside a clear_caches_* migration. The corrected controller was on
 * disk and the server kept executing the previous compiled copy, returning
 * the identical MySQL 1140 error and making a correct fix look like a wrong
 * one.
 *
 * CLAUDE.md already says every package ships one of these. The rule was
 * followed for route changes and skipped for a plain class change, which is
 * exactly the case OPcache punishes: a route change is visible immediately
 * when it 404s, stale bytecode is invisible and looks like the fix failing.
 *
 * So: any package that changes a PHP class ships one of these, not only the
 * ones that add routes.
 *
 * VIEWS too — admin/app.blade.php reports the build number alongside a failed
 * Customers load, and a stale compiled Blade would keep showing the old
 * wording while the new controller answers underneath it.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
