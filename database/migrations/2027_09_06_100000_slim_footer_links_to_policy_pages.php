<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Services\SlimFooter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The cart and checkout footer's two links go to the real policy pages.
 *
 * The owner, 6 October, on the checkout: "remove these links. only in the
 * footer link, replace those two links." The slim footer under cart and
 * checkout shipped "Shipping policy" -> /shipping-policy and "Terms of
 * service" -> /terms-of-service, and neither path is a route here: both were
 * 404s. They now read "Shipping & Delivery" -> /delivery/ and "Returns
 * Information" -> /refund_returns/ (SlimFooter::SCHEMA defaults).
 *
 * A saved row moves only while it still holds the old default, so a link the
 * owner typed himself is left alone. Rows never saved follow the new default
 * with no write at all.
 */
return new class extends Migration
{
    private const MOVES = [
        'l1_text' => ['Shipping policy', 'Shipping & Delivery'],
        'l1_url'  => ['/shipping-policy', '/delivery/'],
        'l2_text' => ['Terms of service', 'Returns Information'],
        'l2_url'  => ['/terms-of-service', '/refund_returns/'],
    ];

    public function up(): void
    {
        $settings = app(SettingsService::class);
        $moved = 0;

        foreach (self::MOVES as $key => [$old, $new]) {
            $full = SlimFooter::PREFIX.$key;

            if (DB::table('settings')->where('key', $full)->exists() && $settings->get($full, null) === $old) {
                $settings->set($full, $new);
                $moved++;
            }
        }

        if ($moved > 0) {
            $settings->flush();
        }

        if (app()->runningInConsole()) {
            echo "Cart & checkout footer links: Shipping & Delivery and Returns Information ({$moved} saved setting(s) moved).\n"
                ."  Appearance -> Footer (the slim footer under cart and checkout) -> First link / Second link\n";
        }
    }

    public function down(): void {}
};
