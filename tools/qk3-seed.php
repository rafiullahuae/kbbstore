<?php

declare(strict_types=1);

/*
 * Lane QK3: tools/mac-seed.php's shop plus three coupons, so the cart panel's
 * coupon hint can be photographed. Run through tools/qk3-preview.sh; never
 * against a real database.
 *
 *   GLOW      10% off, no expiry      -> chosen by the shipped data migration
 *   SPRING15  15% off, expired
 *   VIP20     AED 20 off, used up
 *
 * The owner, the admin: owner@example.com / preview-password (mac-seed.php).
 */

use App\Models\Coupon;

require __DIR__.'/mac-seed.php';

Coupon::create(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000]);
Coupon::create(['code' => 'SPRING15', 'type' => 'percent', 'amount' => 1500, 'expires_at' => now()->subWeek()]);
Coupon::create(['code' => 'VIP20', 'type' => 'fixed_cart', 'amount' => 2000, 'usage_limit' => 5, 'usage_count' => 5]);

// The package's own migration, exactly as it will run on the live shop: the
// choice is unset, GLOW exists, so GLOW is chosen and snapshotted.
\App\Services\SettingsService::forgetMemo();
(require __DIR__.'/../database/migrations/2027_10_15_170000_cart_panel_coupon_hint_glow.php')->up();
\App\Services\SettingsService::forgetMemo();
echo 'coupon_id = '.app(\App\Services\CartPanel::class)->get('coupon_id')."\n";
\Illuminate\Support\Facades\Cache::flush();
