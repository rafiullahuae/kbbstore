<?php
/*
 * Lane VPA preview seed: Missha BB (3 shades), TIRTIR cushion (9 shades, four
 * sold out, one on sale), Anua toner (250 / 500 ml), each option with its own
 * picture (tools/vpa-art.py), the Arabic shop on, an owner login, and one
 * English and one Arabic order holding a shade -- for the order email shots.
 * Written into the PREVIEW's database only.
 */
use App\Models\ProductVariant;

$c = \Tests\Support\VpaCatalog::seed('/uploads/vpa/missha-13.png');

$c['missha']->update(['image' => '/uploads/vpa/missha-13.png']);
$c['tirtir']->update(['image' => '/uploads/vpa/tirtir-21n.png']);
$c['anua']->update(['image' => '/uploads/vpa/anua-250.png']);

foreach (ProductVariant::with('attributeValues')->get() as $v) {
    $name = strtolower((string) $v->attributeValues->first()?->name);
    $key = match (true) {
        str_starts_with($v->sku, 'MS-') => 'missha-'.preg_replace('/^#(\d+).*/', '$1', $name),
        str_starts_with($v->sku, 'TT-') => 'tirtir-'.strtolower(strtok($name, ' ')),
        default => 'anua-'.(int) $name,
    };
    $v->update(['image' => '/uploads/vpa/'.$key.'.png']);
}

\App\Models\Setting::query()->updateOrCreate(['key' => 'language_ar_enabled'], ['value' => '1', 'autoload' => true]);
\App\Models\Setting::query()->updateOrCreate(['key' => 'language_rtl_enabled'], ['value' => '1', 'autoload' => true]);
// The live shop's own switches: "Bundle section" off on the phone and the
// options module off on the laptop -- the state in which nobody could choose
// a shade (found shooting docs/variant-options).
app(\App\Services\SettingsService::class)->set('pdpms_layout', ['on' => ['bundles' => false]]);
app(\App\Services\SettingsService::class)->set('product_sections', ['options' => ['desktop' => false, 'mobile' => false]]);
\App\Models\Setting::flushMap();

\App\Models\AdminUser::updateOrCreate(
    ['email' => 'owner@preview.test'],
    ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']
);

// Two placed orders, through the same snapshot writer the checkout uses.
$variant = ProductVariant::with('attributeValues.attribute')->where('sku', 'MS-BB-1')->first();
$tt = ProductVariant::with('attributeValues.attribute')->where('sku', 'TT-MF-21NIvory')->first();
foreach (['en' => 'VPA-EN', 'ar' => 'VPA-AR'] as $locale => $number) {
    $order = \App\Models\Order::create([
        'order_number' => $number, 'status' => 'processing', 'locale' => $locale, 'email' => 'layla@example.com',
        'subtotal' => 28400, 'total' => 28400, 'currency' => 'AED', 'payment_method' => 'cod',
        'billing_address' => ['first_name' => 'Layla', 'last_name' => 'Al Mansoori', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567'],
        'shipping_address' => ['first_name' => 'Layla', 'last_name' => 'Al Mansoori', 'city' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567'],
    ]);
    foreach ([[$c['missha'], $variant, 13900], [$c['tirtir'], $tt, 14500]] as [$p, $v, $price]) {
        $order->items()->create([
            'product_id' => $p->id, 'product_variant_id' => $v->id, 'name' => $p->name, 'brand' => $p->brand?->name,
            'sku' => $v->sku, 'variant_attributes' => class_exists(\App\Support\VariantText::class) ? \App\Support\VariantText::snapshot($v) : $v->attributeValues->pluck('name')->all(),
            'quantity' => 1, 'unit_price' => $price, 'subtotal' => $price, 'total' => $price,
        ]);
    }
}

echo "vpa seed: ".\App\Models\Product::where('type', 'variable')->count()." variable products\n";
