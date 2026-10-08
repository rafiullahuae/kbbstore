<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane DW2: Platform -> Domain switch as a numbered installer adds one route,
 * POST /admin-api/domain-switch/step (Verify, Mark as done, Skip, Return to
 * it, Reset), in routes/domain-switch-admin.php. A compiled route cache would
 * hide it, so the compiled files and the cached role map are cleared here --
 * the same body every other clear_caches_* migration carries.
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

        try {
            \Illuminate\Support\Facades\Cache::forget(\App\Support\AdminRoles::CACHE_KEY);
        } catch (\Throwable) {
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
