<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.514: the two coupon routes get a rate-limit counter of their own
 * (routes/web.php, routes/checkout-line.php), so the compiled route cache
 * must go. Literals only -- nothing this package adds is called here, because
 * the updater can still be running the old classes (see 2.60.513).
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
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_invalidate')) {
            foreach (['routes/web.php', 'routes/checkout-line.php'] as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Coupon routes: own rate-limit counter. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
