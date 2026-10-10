<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane RV — the review request email. Adds two route files
 * (routes/review-request-public.php: the review page and its unsubscribe;
 * routes/review-request-admin.php: Emails → Customer emails → Review request →
 * Schedule & rules) and new Blade views. A route added on the live host does
 * nothing until the compiled route cache is dropped (CLAUDE.md), so this
 * clears the compiled caches, as every package that adds a route does.
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

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
