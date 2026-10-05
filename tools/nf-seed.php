<?php
/*
 * Seed the Lane NF preview (the shop's 404 page and Safety -> 404 page): an
 * owner account, Arabic switched on, a brand and twelve products, some on
 * sale, so the trending strip has something to show in every list.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Locale;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
Setting::flushMap();

$brand = Brand::query()->firstOrCreate(['slug' => 'glow-lab'], ['name' => 'Glow Lab']);
$cat = Category::query()->firstOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'parent_id' => null]);
$cat->forceFill(['path' => 'skincare', 'depth' => 0])->save();

$names = ['Snail Mucin Essence', 'Rice Sunscreen SPF50+', 'Centella Calming Toner', 'Berry Lip Sleeping Mask',
    'Green Tea Cleansing Foam', 'Ceramide Barrier Cream', 'Vitamin C Glow Serum', 'Hyaluronic Water Gel',
    'Mugwort Clay Mask', 'Peach Toner Pads', 'Collagen Eye Patch', 'Rose Mist'];
foreach ($names as $i => $n) {
    $p = Product::query()->updateOrCreate(['slug' => \Illuminate\Support\Str::slug($n)], [
        'name' => $n, 'brand_id' => $brand->id,
        'price' => 4900 + $i * 500, 'sale_price' => $i % 3 === 0 ? 3900 + $i * 300 : null,
        'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        'total_sales' => 100 - $i,
    ]);
    $p->categories()->syncWithoutDetaching([$cat->id]);
}

echo "nf seed done\n";
