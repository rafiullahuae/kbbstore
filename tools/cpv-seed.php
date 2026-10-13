<?php

/*
 * The owner's own bag on the preview shop (2.60.495 checkout build): his six
 * products at their live prices with their photos, the GLOW coupon, Cash on
 * delivery with its AED 10 fee, the UAE free-delivery rule, and the three card
 * gateways switched on in TEST mode with placeholder keys -- enough for every
 * method to be drawn and for Cash on delivery to place a real order. No key
 * here can reach a payment provider.
 *
 * Run inside the preview's environment (tools/qk13-preview.sh exports it):
 *   php artisan tinker --execute="require 'tools/cpv-seed.php';"
 * Prints the six product ids.
 */

use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;

$root = base_path();
$web = rtrim((string) env('KBB_PUBLIC_PATH', public_path()), '/');
$html = (string) @file_get_contents($root.'/docs/checkout-preview/captured/cart.html');
preg_match_all("~class=\"cth\" style=\"background-image:url\('data:image/(webp|jpeg|png);base64,([A-Za-z0-9+/=]+)'\)~", $html, $m, PREG_SET_ORDER);

$rows = [
    ['cpv-anua-pdrn-mist-mini', 'Anua - PDRN Hyaluronic Acid Hydrating Capsule Mist Mini', 12000, 8000],
    ['cpv-medicube-vanilla-deodorant', 'medicube - Vanilla & Pistachio Deodorant', 13000, 9900],
    ['cpv-medicube-gua-sha-cream', 'Medicube - PDRN Collagen Gua Sha Neck Wrinkle Cream', 16000, 13500],
    ['cpv-dr-althea-345-cream', 'Dr.Althea - 345 Relief Cream', 15500, 9900],
    ['cpv-medicube-pink-balm', 'Medicube - PDRN Pink Collagen Volume Multi Balm', 12500, 9900],
    ['cpv-dr-althea-345-mist', 'Dr.Althea - 345 Relief Cream Mist Spray', 12500, 9900],
];

@mkdir($web.'/uploads/cpv', 0775, true);
$ids = [];

foreach ($rows as $i => [$slug, $name, $price, $sale]) {
    $image = null;

    if (isset($m[$i])) {
        $ext = $m[$i][1] === 'jpeg' ? 'jpg' : $m[$i][1];
        file_put_contents($web.'/uploads/cpv/'.$slug.'.'.$ext, base64_decode($m[$i][2]));
        $image = '/uploads/cpv/'.$slug.'.'.$ext;
    }

    $p = Product::query()->updateOrCreate(['slug' => $slug], [
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => 1,
        'price' => $price, 'sale_price' => $sale, 'manage_stock' => 0, 'stock_status' => 'instock',
        'image' => $image, 'published_at' => now(),
    ]);
    $ids[] = $p->id;
}

Coupon::query()->updateOrCreate(['code' => 'glow'], ['type' => 'percent', 'amount' => 500]);

foreach (['stripe' => ['Credit / Debit Card', 1, ['publishable_key_test' => 'pk_test_preview', 'secret_key_test' => 'sk_test_preview']],
          'tabby' => ['Tabby Installments', 2, ['public_key' => 'pk_test_00000000-0000-0000-0000-000000000000', 'secret_key' => 'sk_test_00000000-0000-0000-0000-000000000000']],
          'tamara' => ['Pay Later with Tamara', 3, ['api_token' => 'preview-token', 'notification_token' => 'preview-notify']]] as $id => [$title, $pos, $config]) {
    PaymentProvider::query()->updateOrCreate(['id' => $id], ['title' => $title, 'enabled' => true, 'mode' => 'test', 'position' => $pos, 'config' => $config]);
}
PaymentProvider::query()->whereKey('cod')->update(['position' => 4]);

// The UAE's free delivery over AED 199, as on the live shop.
\Illuminate\Support\Facades\DB::table('shipping_methods')->updateOrInsert(
    ['shipping_zone_id' => 1, 'type' => 'free_shipping'],
    ['title' => 'Free shipping', 'enabled' => 1, 'cost' => 0, 'min_amount' => 19900, 'position' => 0, 'created_at' => now(), 'updated_at' => now()],
);
\Illuminate\Support\Facades\DB::table('shipping_methods')->where('shipping_zone_id', 1)->where('type', 'flat_rate')->update(['position' => 1]);

$s = app(App\Services\SettingsService::class);
$s->set('cartpage_layout', 'squeeze');
$s->set('cod_fee', 1000);
$s->set('gift_enabled', false);
app(App\Services\CheckoutPage::class)->save(['optin_on' => false]);
app(App\Services\SettingsService::class)->flush();

echo implode(',', $ids), PHP_EOL;
