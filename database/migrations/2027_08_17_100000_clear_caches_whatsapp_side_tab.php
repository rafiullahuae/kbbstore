<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the compiled views after the side tab ships. (Lane WS)
 *
 * The package changes the WhatsApp button partial and the cart and checkout
 * templates; a server that kept their compiled copies would go on printing
 * the old ones. No route is added. Same body as
 * 2027_07_31_200000_clear_caches_whatsapp_button.
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
            echo "On phones, the cart and the checkout now show a slim 24/7 Support tab on the left edge instead of the round WhatsApp button. Change or switch it off at Appearance → WhatsApp button → Cart & checkout · phone.\n";
        }
    }

    public function down(): void {}
};
