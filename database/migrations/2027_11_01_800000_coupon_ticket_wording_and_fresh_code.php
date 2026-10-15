<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;

/*
 * 2.60.513: the coupon ticket, made to land.
 *
 * On the live shop 2.60.512's stylesheet arrived but its PHP did not take:
 * the cart panel still printed "Need Discount? Use coupon code glow on
 * checkout" (the old wording, the code in lower case -- the old CartPanel),
 * the cart page drew no ticket and the checkout no coupon line. Two causes,
 * both closed here:
 *
 *  1. The updater runs migrations in the request that copied the files, and
 *     PHP's opcode cache can still serve the OLD classes there. .512's
 *     migration read a constant only the new CartPanel has, which the old one
 *     answers with "Undefined constant" -- a failed migration, and the update
 *     is not kept. This file uses literals and SettingsService::set() only,
 *     which every release has had.
 *  2. Compiled code that outlives the copy. Every PHP file this package
 *     carries and every compiled view is invalidated in the opcode cache
 *     one by one (opcache_invalidate works where a deferred opcache_reset()
 *     has not yet taken effect), then the whole cache is reset.
 *
 * The owner: "apply the new wordings as we have in preview." So the cart
 * panel's sentence IS the new one now, whatever was stored -- English and
 * Arabic -- and the checkout's coupon line is on. The cart panel's own
 * on/off switch (Appearance -> Cart panel -> Coupon hint) is left as he set
 * it: he switches it back on himself.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(SettingsService::class);

        $settings->set('cartpanel_txt_coupon', 'Use coupon code: {coupon-code} on checkout page.');
        $settings->set('cartpanel_txt_coupon_ar', 'استخدمي كود الخصم: {coupon-code} في صفحة الدفع.');
        $settings->set('checkoutpage_cline_on', true);
        SettingsService::forgetMemo();

        $files = [
            'app/Services/CartPanel.php',
            'app/Services/CheckoutPage.php',
            'resources/views/partials/cart-drawer.blade.php',
            'resources/views/store/cart-inner.blade.php',
            'resources/views/partials/checkout/coupon-top.blade.php',
            'resources/views/admin/partials/cart-panel-screen.blade.php',
        ];

        $compiled = glob(storage_path('framework/views/*.php')) ?: [];
        $cleared = 0;

        foreach ($compiled as $file) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            if (is_file($file) && @unlink($file)) {
                $cleared++;
            }
        }

        if (function_exists('opcache_invalidate')) {
            foreach ($files as $relative) {
                @opcache_invalidate(base_path($relative), true);
            }
        }

        foreach ([
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Coupon ticket: new wording set, checkout coupon line on, {$cleared} compiled views cleared.\n";
        }
    }

    public function down(): void
    {
    }
};
