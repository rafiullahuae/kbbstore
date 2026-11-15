<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane GBX2: the claimed gift offer (the bonus) is drawn INSIDE the Free
 * gifts box -- partials/free-gifts/box.blade.php and extras-th, the cart
 * panel, cart page and checkout views, App\Support\GiftBoxBonus -- with its
 * clock on the bonus. Compiled views would keep drawing the separate row
 * until they are cleared. Same body as every clear_caches_*.
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
            echo "Cleared {$cleared} compiled files; the bonus gift now sits inside the Free gifts box.\n";
        }
    }

    public function down(): void {}
};
