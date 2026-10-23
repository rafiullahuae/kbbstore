<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Tablet fixes (2.60.580): heading buttons under their section, and the bag's
 * checkout button wraps instead of cutting its label. The bag's styles are in
 * a Blade view, so the compiled views are cleared and opcache reset. Literals
 * only: no application class is loaded. Nothing in the database moves here.
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
            echo "Tablet buttons: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
