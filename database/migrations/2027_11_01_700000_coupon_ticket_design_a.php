<?php

declare(strict_types=1);

use App\Services\CartPanel;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 2.60.512: the coupon ticket (design A, the owner's pick).
 *
 *  - The cart panel's and cart page's wording becomes the owner's sentence,
 *    "Use coupon code: {coupon-code} on checkout page." -- moved ONLY where
 *    the stored text is still the old shipped wording (or never stored); a
 *    sentence the owner wrote himself is left alone.
 *  - Appearance -> Checkout page -> Coupon line goes back ON, as asked: "also
 *    on checkout page i need above the summary row ... the coupon will
 *    actually applied, not copy."
 *  - Views and stylesheets changed, so compiled views and caches are cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'txt_coupon' => [CartPanel::COUPON_TEXT_OLD, CartPanel::COUPON_TEXT],
            'txt_coupon_ar' => [CartPanel::COUPON_TEXT_AR_OLD, CartPanel::COUPON_TEXT_AR],
        ] as $key => [$old, $new]) {
            if (DB::table('settings')->where('key', 'cartpanel_'.$key)->value('value') === $old) {
                app(CartPanel::class)->save([$key => $new]);
            }
        }

        app(CheckoutPage::class)->save(['cline_on' => true]);
        SettingsService::forgetMemo();

        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/events.php'),
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Coupon ticket: wording moved where untouched; checkout coupon line on. Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void
    {
    }
};
