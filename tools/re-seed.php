<?php
/*
 * Seed the Lane RE preview: "Buy these together" — the carousel of four, the
 * peek, the struck total and the savings line, and the bundle discount through
 * the cart, the checkout and the order.
 *
 * Lane RB's seed first (his shelves, the pictures, the section on), then:
 *
 *   - prices like the ones on the owner's phone screenshot, "AED 108 AED 153",
 *     on the companions a sunscreen draws, so the price line is as long as the
 *     one that ran out of its box;
 *   - five products in the section (the carousel case);
 *   - tiers of 5 / 10 / 15 % only when RE_TIERS=1, so a "before" run leaves
 *     them at the shipped 0;
 *   - a percentage coupon (GLOW10) and a fixed one (SAVE20) for the checkout
 *     shots, and a flat delivery rate so the checkout has a delivery line.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */

require __DIR__.'/rb-seed.php';

use App\Models\Product;

$settings = app(\App\Services\SettingsService::class);

// The owner's screenshot: "AED 108 AED 153". The companions a sunscreen draws
// (moisturiser, toner, cleansing oil, mask) are given long struck pairs.
$long = [
    'Dynasty Cream 50ml' => [15300, 10800],
    'Heartleaf 77% Soothing Toner 250ml' => [15300, 10800],
    'Ginseng Cleansing Oil 210ml' => [12900, 9900],
    'Heartleaf Pore Control Cleansing Oil' => [15300, 10800],
    'Lip Sleeping Mask Berry' => [11900, 8900],
];

foreach ($long as $name => [$price, $sale]) {
    Product::query()->where('name', $name)->update(['price' => $price, 'sale_price' => $sale]);
}

// The page's own product: a sunscreen at a plain price.
Product::query()->where('slug', 'relief-sun-rice-probiotics-spf50')->update(['price' => 6900, 'sale_price' => null]);

$settings->set('bt_count', (int) (getenv('RE_COUNT') ?: 5));

if (getenv('RE_TIERS') === '1') {
    $settings->set('bt_tier_3', 5);
    $settings->set('bt_tier_4', 10);
    $settings->set('bt_tier_5', 15);
}

\App\Models\Coupon::updateOrCreate(['code' => 'GLOW10'], ['type' => 'percent', 'amount' => 1000, 'description' => 'Preview: 10% off']);
\App\Models\Coupon::updateOrCreate(['code' => 'SAVE20'], ['type' => 'fixed_cart', 'amount' => 2000, 'description' => 'Preview: AED 20 off']);

// Arabic on, with the section's strings approved, for the RTL shot.
require __DIR__.'/pdp-arabic-on.php';
foreach (\App\Services\Translation\ArabicInterfaceDrafts::all() as $kbbKey => $kbbValue) {
    if (str_starts_with($kbbKey, 'store.buy_together.')) {
        \App\Services\Translation\TranslationStore::put('ar', 'ui', 0, $kbbKey, $kbbValue, \App\Models\Translation::STATUS_PUBLISHED);
    }
}

// The harness reads ids from here (an ordinary line for the coupon shots).
$extra = Product::query()->where('slug', 'lip-sleeping-mask-berry')->value('id');
file_put_contents($root.'/re-ids.json', json_encode(['extra' => (int) $extra]));

\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();

echo 're seed done: count '.$settings->get('bt_count').', tiers '.(getenv('RE_TIERS') === '1' ? '5/10/15' : 'off')."\n";
