<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Product pages name their own picture for Google (2.60.579) changes only
 * app/Support/Seo.php. Without a migration nothing calls opcache_reset(), and
 * the server can keep running the previous compiled Seo.php after the package
 * is applied. Literals only: no application class is loaded. Nothing in the
 * database moves here.
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
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Product primary image: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
