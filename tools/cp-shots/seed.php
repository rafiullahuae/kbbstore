<?php
/* Lane CP's preview: Lane CO2's shop (two products with pictures, a set, Stripe
   in test mode against a stub, cash on delivery, Arabic on with the shipped
   drafts approved) plus four coupons, one per answer the coupon box can give:
     SAVE10   10% off            -> applied
     OLD20    expired yesterday  -> "That code has expired."
     BIG900   minimum AED 900    -> "Your basket does not meet the minimum..."
     NOPE     (no such coupon)   -> "That code is not valid."
   PREVIEW FIXTURE ONLY. */
require __DIR__.'/../co2-shots/seed.php';

use App\Models\Coupon;

Coupon::updateOrCreate(['code' => 'SAVE10'], ['type' => 'percent', 'amount' => 1000]); // hundredths of a percent: 1000 = 10%
Coupon::updateOrCreate(['code' => 'OLD20'], ['type' => 'percent', 'amount' => 2000, 'expires_at' => now()->subDay()]);
Coupon::updateOrCreate(['code' => 'BIG900'], ['type' => 'fixed_cart', 'amount' => 5000, 'minimum_amount' => 90000]);

echo "cp: coupons SAVE10, OLD20 (expired), BIG900 (minimum)\n";

// The delivery-notes box on (Appearance -> Checkout page), so the walk fills
// every field the owner listed, notes included.
app(\App\Services\CheckoutPage::class)->save(['notes_on' => true]);

// The cart page's own coupon box (Modules -> cart_coupon_field, default OFF),
// switched on so the walk can say whether it behaves like checkout's.
\App\Models\ModuleToggle::updateOrCreate(['module' => 'cart_coupon_field'], ['enabled' => true]);
