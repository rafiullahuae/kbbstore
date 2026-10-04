<?php
/*
 * Lane HC preview seed: five brands, five categories and thirty products with
 * real WebP pictures, so the All sections list, the source picker's preview
 * and the storefront rails have something to show. The preview's own database
 * only; nothing here reaches a package.
 */
use App\Models\{AdminUser, Brand, Category, Product};

app(\App\Services\SettingsService::class)->set('demo_content', false);
AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$brands = [];
foreach (['COSRX', 'Anua', 'Beauty of Joseon', 'Round Lab', 'Torriden'] as $i => $n) {
    $brands[] = Brand::updateOrCreate(['slug' => \Illuminate\Support\Str::slug($n)], ['name' => $n, 'position' => $i]);
}
$cats = [];
foreach (['Toners', 'Serums', 'Sunscreens', 'Cleansers', 'Moisturisers'] as $i => $n) {
    $cats[] = Category::updateOrCreate(['slug' => strtolower($n)], ['name' => $n, 'position' => $i, 'depth' => 0]);
}
@mkdir(public_path('uploads/hc'), 0755, true);
$words = ['Snail Mucin Essence', 'Heartleaf Toner', 'Relief Sun SPF50', 'Dokdo Toner', 'Dive-In Serum', 'Green Tea Cleanser',
    'Glow Serum', 'Barrier Cream', 'Low pH Cleanser', 'Rice Toner', 'Vitamin C Serum', 'Ginseng Cream', 'Birch Sun Cream',
    'Mugwort Essence', 'Ceramide Cream'];
$n = 0;
foreach ($brands as $bi => $b) {
    foreach (array_slice($words, $bi * 3, 6) as $wi => $w) {
        $n++;
        $p = Product::updateOrCreate(['slug' => 'hc-'.$n], [
            'name' => $b->name.' '.$w, 'brand_id' => $b->id, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
            'stock_status' => $n % 7 === 0 ? 'outofstock' : 'instock', 'price' => 4500 + $n * 700, 'sale_price' => $n % 4 === 0 ? 3900 + $n * 500 : null,
            'total_sales' => 1000 - $n * 13, 'featured' => $n % 3 === 0, 'sku' => 'HC-'.$n, 'wc_id' => 90000 + $n,
            'rating' => 4.6, 'review_count' => 10 + $n,
        ]);
        $rel = 'uploads/hc/p'.$p->id.'.webp';
        $im = imagecreatetruecolor(600, 600);
        imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, 250, 244, 246));
        imagefilledrectangle($im, 220, 110, 380, 520, imagecolorallocate($im, 60 + ($n * 37) % 180, 110 + ($n * 23) % 120, 150));
        imagewebp($im, public_path($rel), 80);
        imagedestroy($im);
        $p->image = '/'.$rel;
        $p->save();
        $p->categories()->syncWithoutDetaching([$cats[$n % 5]->id]);
    }
}
// The UAE zone with a free-delivery method at AED 199, as the live shop has,
// so the Top strip draws its whole line.
if (! \App\Models\ShippingMethod::query()->where('type', 'free_shipping')->exists()) {
    $uae = \App\Models\ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    \App\Models\ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    \App\Models\ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'free_shipping', 'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1]);
}
// A published article with a tag (the homepage blog cards) and a grid section
// that is a carousel on both widths (Cards in view, arrows).
\App\Models\Post::updateOrCreate(['slug' => 'hc-routine-guide'], ['title' => 'The 7-step Korean routine, simplified', 'status' => 'published',
    'published_at' => now()->subDay(), 'tag' => 'Routines', 'excerpt' => 'Which steps matter, and which you can skip.', 'body' => str_repeat('Skincare words. ', 400)]);
\App\Models\GridSection::updateOrCreate(['slug' => 'hc-new-in'], ['name' => 'New in', 'status' => 'publish', 'position' => 1,
    'show_heading' => true, 'heading' => 'New in this week', 'subheading' => 'Fresh from Seoul.', 'source' => 'newest', 'include_children' => false,
    'count' => 10, 'mobile_count' => 8, 'desktop_layout' => 'carousel', 'desktop_cols' => 4, 'mobile_layout' => 'carousel', 'mobile_cols' => 2,
    'skin' => '', 'card_label' => '', 'show_rank' => false, 'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '']);
\App\Services\GridSections::flush();
\App\Http\Controllers\Store\HomeController::flushCache();
echo "hc seed done: {$n} products\n";
