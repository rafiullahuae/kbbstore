<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Store -> Customers -> "Send account invite". (Lane PQ)
 *
 * routes/customers-admin.php gains the /admin-api/customers/invites/* group and
 * routes/auth-customer.php gains /my-account/welcome/{token}/, the page a guest
 * lands on to choose a password. A route added by a package does not take
 * effect until the compiled route cache is cleared (CLAUDE.md), and
 * resources/views/admin/app.blade.php now includes
 * partials/customer-invites.blade.php, whose compiled copy is keyed by path.
 *
 * Same body as 2027_07_10_000000_clear_caches_unfinished_drafts.php.
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
