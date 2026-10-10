<?php
/*
 * Seed the Lane RV preview (the review request email and its page): three
 * products with real photographs, a completed order from "Rafi" two days ago,
 * its review request as the sweep would have sent it — with a KNOWN token,
 * written to the preview folder so the shot script can open the page — and the
 * email itself rendered to the web root in English and Arabic.
 *
 * Written into the PREVIEW's database and web root only; nothing here reaches
 * a package.
 */

use App\Mail\ReviewRequestMail;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Services\Reviews\ReviewRequests;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

$dir = getenv('RV_DIR') ?: storage_path('framework/testing/rv-preview');
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
$settings = app(SettingsService::class);
$settings->set('store_name', 'K Beauty Bliss');

$photo = static function (int $seed, array $rgb): string {
    mt_srand($seed);
    $w = 900;
    $im = imagecreatetruecolor($w, $w);
    imagefilledrectangle($im, 0, 0, $w, $w, imagecolorallocate($im, 252, 244, 246));
    for ($i = 0; $i < 260; $i++) {
        $c = imagecolorallocatealpha($im, mt_rand(230, 255), mt_rand(190, 230), mt_rand(200, 235), 60);
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $w), mt_rand(20, 140), mt_rand(20, 140), $c);
    }
    $body = imagecolorallocate($im, ...$rgb);
    imagefilledrectangle($im, 330, 220, 570, 780, $body);
    imagefilledellipse($im, 450, 220, 240, 90, $body);
    imagefilledrectangle($im, 380, 120, 520, 220, imagecolorallocate($im, 60, 50, 56));
    ob_start();
    imagejpeg($im, null, 88);

    return (string) ob_get_clean();
};

@mkdir($root . '/uploads/products', 0755, true);
$catalogue = [
    ['PDRN Pink Collagen Jelly Eye Mask', 'Medicube', [236, 120, 160], ['size' => '60 patches']],
    ['Relief Sun: Rice + Probiotics SPF50+', 'Beauty of Joseon', [246, 214, 160], ['size' => '50 ml']],
    ['Heartleaf 77% Soothing Toner', 'Anua', [170, 205, 170], []],
];
$products = [];

foreach ($catalogue as $i => [$name, $brandName, $rgb, $variant]) {
    $brand = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brandName)], ['name' => $brandName]);
    $rel = 'uploads/products/rv-' . ($i + 1) . '.jpg';
    file_put_contents($root . '/' . $rel, $photo($i + 7, $rgb));
    $products[] = [Product::updateOrCreate(['slug' => 'rv-' . \Illuminate\Support\Str::slug($name)], [
        'name' => $name, 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => 1, 'price' => 8900,
        'stock_status' => 'instock', 'type' => 'simple', 'image' => '/' . $rel,
    ]), $brandName, $variant];
}

$address = ['first_name' => 'Rafi', 'last_name' => 'Ullah', 'line1' => 'Marina Heights', 'city' => 'Dubai', 'country' => 'AE'];
$order = Order::create([
    'order_number' => 'KBB-10412', 'email' => 'rafi@preview.test', 'status' => 'processing', 'currency' => 'AED',
    'billing_address' => $address, 'shipping_address' => $address,
    'subtotal' => 26700, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 26700,
    'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
]);
$order->created_at = now()->subDays(3);
$order->save();

foreach ($products as [$p, $brandName, $variant]) {
    $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'brand' => $brandName, 'quantity' => 1, 'unit_price' => 8900,
        'subtotal' => 8900, 'total' => 8900, 'variant_attributes' => $variant]);
}

$order->update(['status' => 'completed']);
DB::table('review_requests')->where('order_id', $order->id)->update(['completed_at' => now()->subDays(2)->subMinute()]);

// As the sweep sends it — but with a token this script knows.
$token = ReviewRequests::newToken();
$ids = array_map(fn ($r) => $r[0]->id, $products);
DB::table('review_requests')->where('order_id', $order->id)->update([
    'status' => 'sent', 'token_hash' => ReviewRequests::hash($token), 'expires_at' => now()->addDays(60),
    'sent_at' => now(), 'products' => json_encode($ids),
]);
@mkdir($dir, 0755, true);
file_put_contents($dir . '/token.txt', $token);

$order = $order->fresh('items');

// An email picture must live on a PUBLIC https host (MailImage::origin()), so
// for the render the shop is told it is the live one; the shot script serves
// that host's requests from this preview. The markup is exactly what a
// customer gets.
$siteUrlBefore = $settings->get('site_url');
$settings->set('site_url', 'https://extrabeauty.ae');
SettingsService::forgetMemo();
\App\Models\Setting::flushMap();
file_put_contents($root . '/rv-email-en.html', (string) (new ReviewRequestMail($order, $token, $ids))->render());
file_put_contents($root . '/rv-email-en-subject.txt', (string) (new ReviewRequestMail($order, $token, $ids))->envelope()->subject);

// Arabic: the drafts this package seeds, approved in THIS preview database only
// (on the live shop the owner approves them in Translation → Progress), and the
// mirrored layout on, as an Arabic shop has it.
DB::table('translations')->where('locale', 'ar')->where(function ($q) {
    $q->where('field', 'like', 'email.review_request.%')->orWhere('field', 'like', 'store.review_request.%')
        ->orWhere('field', 'like', 'email.kit.%')->orWhere('field', 'like', 'email.common.%');
})->update(['status' => \App\Models\Translation::STATUS_PUBLISHED]);
$settings->set(\App\Support\Locale::SETTING_ENABLED, '1');
$settings->set(\App\Support\Locale::SETTING_RTL, '1');
\App\Services\Translation\TranslationStore::flush();
SettingsService::forgetMemo();
\App\Models\Setting::flushMap();
app()->setLocale('ar');
$ar = new ReviewRequestMail($order, $token, $ids);
file_put_contents($root . '/rv-email-ar.html', (string) $ar->render());
file_put_contents($root . '/rv-email-ar-subject.txt', (string) $ar->envelope()->subject);
app()->setLocale('en');
$settings->set('site_url', $siteUrlBefore ?? '');
// The shop itself stays English for the page shots.
$settings->set(\App\Support\Locale::SETTING_RTL, '0');
$settings->set(\App\Support\Locale::SETTING_ENABLED, '0');

echo "rv seed done: order {$order->id}, token in {$dir}/token.txt\n";
