<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * The redesigned account area (2.60.597): new shop routes
 * (/my-account/profile, its two POSTs), so the route cache is cleared with
 * compiled views and config, and opcache reset. Literals only: no application
 * class is loaded. Nothing in the database moves here.
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
            echo "Account area: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
