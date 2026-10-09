<?php

declare(strict_types=1);

/*
 * Lane QK6: tools/mac-seed.php's shop plus what the checkout needs to be
 * photographed. Run through tools/qk6-preview.sh; never against a real database.
 *
 *   UAE zone   "Delivery Charges" AED 20, "Free Delivery" over AED 199 (as live)
 *   Oman zone  "GCC Delivery" AED 50, no free delivery
 *   GLOW       10% off, chosen for the cart panel by the shipped QK3 migration
 *   Two products: AED 119 (the owner's screenshot) and AED 99.
 */

use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

require __DIR__.'/mac-seed.php';

ShippingZone::query()->delete();
$uae = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free Delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);
$om = ShippingZone::create(['name' => 'Oman', 'position' => 1]);
ShippingZoneLocation::create(['shipping_zone_id' => $om->id, 'type' => 'country', 'code' => 'OM']);
ShippingMethod::create(['shipping_zone_id' => $om->id, 'type' => 'flat_rate', 'title' => 'GCC Delivery', 'cost' => 5000, 'enabled' => true, 'position' => 0]);

PaymentProvider::query()->updateOrCreate(['id' => 'cod'], ['title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

foreach ([['QK6 Glow Serum', 'qk6-serum', 119], ['QK6 Toner Pad', 'qk6-pad', 99]] as [$name, $slug, $price]) {
    Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price * 100, 'is_visible' => true,
        'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

Coupon::create(['code' => 'GLOW', 'type' => 'percent', 'amount' => 1000]);

\App\Services\SettingsService::forgetMemo();
(require __DIR__.'/../database/migrations/2027_10_15_170000_cart_panel_coupon_hint_glow.php')->up();
\App\Services\SettingsService::forgetMemo();

// The Arabic shop as it would read once the owner has approved the shipped
// drafts: every interface draft published, right-to-left on. Preview only.
\Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->where('group', 'ui')
    ->update(['status' => \App\Models\Translation::STATUS_PUBLISHED]);
\App\Services\Translation\TranslationStore::flush();

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
$sv->flush();
\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();

if (getenv('KBB_PUBLIC_PATH')) {
    file_put_contents(getenv('KBB_PUBLIC_PATH').'/qk6-ids.json', json_encode(
        Product::query()->where('slug', 'like', 'qk6-%')->orderBy('id')->pluck('id', 'slug')->all()
    ));
}
echo 'qk6 seeded; cart panel coupon_id = '.app(\App\Services\CartPanel::class)->get('coupon_id')."\n";
