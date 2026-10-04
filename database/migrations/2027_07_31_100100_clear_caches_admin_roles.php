<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for Lane RL: editable roles under Platform → Users & Roles.
 *
 * routes/admin-roles.php adds twelve admin-api routes (the Roles and Members
 * tabs and the edit-presence heartbeat), which do not exist in a cached route table until it is rebuilt; the
 * console gains a partial, so compiled Blade goes too. No data is written here:
 * 2027_07_31_100000_create_admin_roles seeds the roles, and every account keeps
 * exactly the access it had.
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
            echo "Roles are now editable: Platform → Users & Roles → Roles. Every account keeps the access it had.\n";
        }
    }

    public function down(): void {}
};
