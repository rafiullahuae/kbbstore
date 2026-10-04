<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear the caches for Lane CT: Growth & Marketing → Cart Tracking.
 *
 * TEN NEW ROUTES (routes/cart-tracking-admin.php, required inside the
 * admin-api group), so the compiled route table goes — without this the
 * screen opens on "not in the server's route table yet". A new Blade partial
 * (admin/partials/cart-tracking-screen) and a new storefront view
 * (errors/kbb-blocked), so compiled views go. Config and services, and
 * opcache. cart.js and checkout.js now send the X-KBB-Hm header and travel
 * rebuilt in public/build.
 *
 * The block-list file under storage/framework is rebuilt by the ip_blocks
 * migration that runs before this one.
 *
 * WHAT MOVES ON THE SHOP: carts are tracked from the next add-to-cart, and
 * "Ask bots to leave" answers 403 to scripted clients on the cart and checkout
 * — both on because the owner asked for them. No page a shopper reads changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            base_path('bootstrap/cache/routes-v7.php'),
            base_path('bootstrap/cache/routes.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
            storage_path('framework/views/*.php'),
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
            echo "Growth & Marketing → Cart Tracking is live: carts are tracked, and blocks are enforced.\n";
        }
    }

    public function down(): void {}
};
