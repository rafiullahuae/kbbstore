<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.562 adds three routes (routes/order-phone.php: the customer's "Update my
 * number" page; routes/order-phone-request-admin.php: the order screen's "Ask
 * for UAE number") and changes the checkout's coupon line view. A compiled
 * route table and compiled views keep serving the old shape until they are
 * cleared (CLAUDE.md, "How this ships"). Literals only: no application class
 * is loaded.
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
            echo "UAE number request: cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
