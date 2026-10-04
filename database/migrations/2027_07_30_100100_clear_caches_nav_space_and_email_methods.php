<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for 2.60.376.
 *
 * App\Services\HeaderSettings gained "How it fills the row" (Appearance →
 * Header → Navigation), and NavRowFit reads it; the order-failed and refund
 * emails name the payment method through a new App\Support\PaymentMethodWords;
 * Emails → Settings → Send a test lists every customer email. Compiled Blade,
 * the cached config/services and opcache go. No data is written and no route
 * is added: the new select reads its default ("Spread the items (keep my text
 * size)", which the owner asked for) until the Header screen is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
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
            echo "The desktop menu now fills its row when it has nine or more items (Appearance → Header → Navigation).\n";
        }
    }

    public function down(): void {}
};
