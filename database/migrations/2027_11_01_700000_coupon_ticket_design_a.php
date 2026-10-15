<?php

declare(strict_types=1);

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
        // ▲ LITERALS AND THE SETTINGS WRITER ONLY -- never a constant or method
        // this package adds. The updater runs migrations in the request that
        // copied the files, where PHP's opcode cache can still hold the OLD
        // CartPanel; the first version of this file read
        // CartPanel::COUPON_TEXT_OLD, which that old class does not have
        // ("Undefined constant"), and the update rolled back (2.60.512).
        $settings = app(SettingsService::class);

        foreach ([
            'cartpanel_txt_coupon' => ['Need Discount? Use coupon code {coupon-code} on checkout', 'Use coupon code: {coupon-code} on checkout page.'],
            'cartpanel_txt_coupon_ar' => ['تحتاج خصمًا؟ استخدم كود الخصم {coupon-code} عند الدفع', 'استخدمي كود الخصم: {coupon-code} في صفحة الدفع.'],
        ] as $key => [$old, $new]) {
            if (DB::table('settings')->where('key', $key)->value('value') === $old) {
                $settings->set($key, $new);
            }
        }

        $settings->set('checkoutpage_cline_on', true);
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
