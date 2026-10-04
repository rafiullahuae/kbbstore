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
 *
 * The brand page (SiteLayout's new Brand page tab: every product on one page,
 * no Shop all button, no Popular right now / View all, and the description
 * typed on the page itself now printed) reads its three defaults the same way.
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
            echo "The menu keeps its text size and spreads its items (Appearance → Header → Navigation → How it fills the row); brand pages show the name, the description and every product (Appearance → Site layout → Brand page).\n";
        }
    }

    public function down(): void {}
};
