<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane CK (22 October 2026) adds three GETs -- checkout-page/live,
 * cart-page/live and cart-panel/live, the real pages behind the Text sizes
 * live previews -- and changes the three Appearance screens and the checkout,
 * cart and drawer views. A compiled route table and compiled views keep
 * serving the old shape until they are cleared (CLAUDE.md, "How this ships").
 * Literals only: no application class is loaded. Nothing in the database
 * moves: every new setting reads its default until it is saved.
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
            echo "Checkout text sizes: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
