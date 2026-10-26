<?php

declare(strict_types=1);

/*
 * Lane SSB preview: tools/fg4-seed.php's shop (the owner's five gift ranges,
 * main and backup gifts, real gift photos) with free gifts LIVE, the "Super
 * Sale" category with the products the catalogue export puts in it, and the
 * Super Sale header showing a picture banner as the live page does (the two
 * shipped Arabic banners, standing in for the English ones the preview does
 * not have), then the live banner switched by SSB_ON (1 = on, the shipped
 * default; 0 = the page as it was).
 *   sh tools/ssb-preview.sh 11460 -- never against a real database.
 */

use App\Models\{Brand, Category, Product};
use Illuminate\Support\Facades\DB;

require __DIR__.'/fg4-seed.php';

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['mode'] = 'live';
$fg->saveConfig($config);

$web = getenv('KBB_PUBLIC_PATH') ?: public_path();
$cat = Category::firstOrCreate(['slug' => 'super-sale'], ['name' => 'Super Sale', 'path' => 'super-sale', 'depth' => 0]);
@mkdir($web.'/uploads/ss', 0755, true);
$imgs = glob(base_path('docs/freebies-options/img/*.webp'));
$rows = json_decode((string) file_get_contents(base_path('storage/catalog/products.json')), true);
$i = 0;
foreach ($rows as $r) {
    if (! in_array('Super Sale', $r['collections'], true)) {
        continue;
    }
    $brand = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($r['brand'] ?: 'kbb')], ['name' => $r['brand'] ?: 'KBB']);
    $src = $imgs[$i % count($imgs)];
    copy($src, $web.'/uploads/ss/'.$i.'-'.basename($src));
    $p = Product::updateOrCreate(['slug' => $r['slug']], [
        'wc_id' => $r['wc_id'], 'name' => $r['name'], 'brand_id' => $brand->id,
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $r['price'], 'sale_price' => $r['sale_price'], 'position' => $r['position'],
        'stock_status' => 'instock', 'image' => '/uploads/ss/'.$i.'-'.basename($src), 'category_id' => $cat->id,
    ]);
    $p->categories()->syncWithoutDetaching([$cat->id]);
    $i++;
}
echo "ssb seed: {$i} Super Sale products\n";

// The header picture the page shows today (stand-ins: the shipped Arabic pair).
@mkdir($web.'/uploads/appearance', 0755, true);
foreach (['kbb-super-sale-desktop-ar.webp', 'kbeautybliss-2026-super-sale-ar.webp'] as $f) {
    copy(resource_path('banners/'.$f), $web.'/uploads/appearance/'.$f);
}
$base = rtrim((string) config('app.url'), '/').'/uploads/appearance/';
$all = \App\Services\PageHeaders::defaults();
$all['pages']['collection:super-sale']['img'] = $base.'kbb-super-sale-desktop-ar.webp';
$all['pages']['collection:super-sale']['img_m'] = $base.'kbeautybliss-2026-super-sale-ar.webp';
$all['pages']['collection:super-sale']['alt'] = 'Super Sale';
app(\App\Services\SettingsService::class)->set(\App\Services\PageHeaders::KEY, $all);

// Arabic on and mirrored, as the live shop runs it.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');

// The gift photos' img-cache copies, as the live shop has them.
foreach (DB::table('products')->whereNotNull('image')->pluck('image') as $img) {
    \App\Support\ImageVariants::generate((string) $img);
}

$on = getenv('SSB_ON') !== '0';
app(\App\Services\SettingsService::class)->set(\App\Support\SuperSaleBanner::KEY, ['on' => $on] + \App\Support\SuperSaleBanner::defaults());
\Illuminate\Support\Facades\Cache::flush();
echo 'super sale banner: '.($on ? 'ON' : 'OFF (the page as it was)')."\n";
