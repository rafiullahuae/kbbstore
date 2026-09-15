<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the email-rule hardening.
 *
 * Five controllers swapped Laravel's `email` rule for App\Rules\StorefrontEmail
 * — registration, checkout, the newsletter, review submission and the admin
 * mail test. A worker on stale bytecode keeps the old rule, which accepts the
 * two CRLF shapes the advisory is about, and the change would look applied
 * while doing nothing.
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
