<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane ARDX: Arabic spacing adds admin routes (admin-api/ar-structure/*), a
 * console command (kbb:ar-structure) and new classes (App\Support\BlockStructure,
 * App\Services\Translation\ArabicStructureRepair), so the compiled route, config,
 * event and view caches are cleared and opcache reset -- otherwise the button
 * renders but its requests 404 and PHP keeps the old bytecode. Literals only.
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
            echo "Arabic spacing: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
