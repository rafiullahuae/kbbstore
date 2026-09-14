<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the customer password-reset / email-verification
 * package.
 *
 * Three reasons, any one sufficient:
 *
 *   - ROUTES. routes/auth-customer.php adds POST /my-account/forgot,
 *     GET /my-account/reset/{id}/{token}, POST /my-account/reset and the three
 *     /my-account/verify paths. A compiled route cache knows none of them. The
 *     symptom is specific and bad: the forgot form already exists and already
 *     posts to /my-account/forgot, so until the cache is cleared it keeps
 *     404ing exactly as it has been, and the package looks like it did nothing.
 *
 *   - CONFIG. config/auth.php changed — the `customers` password broker now
 *     points at `customer_password_reset_tokens` rather than sharing
 *     `password_reset_tokens` with the `users` broker. A stale
 *     bootstrap/cache/config.php keeps the old table name, which does not error:
 *     it quietly writes customer tokens into the admin table, and that is a
 *     cross-provider token confusion rather than a visible failure.
 *
 *   - VIEWS. Four new Blade templates, two of them rendered into message
 *     bodies. Compiled views are keyed by path so new files are not the risk;
 *     resources/views/store/account/forgot.blade.php is, since its compiled
 *     copy predates a working POST target.
 *
 * OPcache too: config/auth.php and the controllers are resolved on a worker
 * that may still hold the previous compiled copies.
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
