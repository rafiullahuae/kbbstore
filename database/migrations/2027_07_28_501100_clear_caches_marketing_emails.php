<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Lane MK — Marketing Emails adds routes (routes/marketing-emails-admin.php in
 * the admin-api group, routes/marketing-public.php at the storefront level),
 * a scheduled command (kbb:campaigns-step) and new views. None of that takes
 * effect on the server until the compiled route cache, the config and the
 * compiled views are dropped — CLAUDE.md's convention for every package that
 * adds a route.
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
            base_path('bootstrap/cache/events.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        foreach (['kbb.settings.map'] as $key) {
            try {
                Cache::forget($key);
            } catch (\Throwable) {
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
