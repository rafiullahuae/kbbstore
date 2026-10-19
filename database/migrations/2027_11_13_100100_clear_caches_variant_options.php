<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * Lane VPA adds two routes (routes/variant-options-admin.php: Product editor
 * -> Options) and changes shop views (the card's shade dots, the product
 * page's swatches, the basket, the checkout, the order documents). A compiled
 * route table and compiled views keep serving the old shape until they are
 * cleared (CLAUDE.md, "How this ships"), so they go, as with every package
 * that adds a route. Literals only: no application class is loaded.
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
            echo "Variant options: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
