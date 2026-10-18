<?php

// Lane SBC: the eight concern collections on the PREVIEW database, each with
// in-stock products (some on sale), so Shop by Concern draws every tab.
// PREVIEW DATABASE ONLY -- run through tools/sbc-preview.sh.

use Illuminate\Support\Facades\DB;

$concerns = [
    'acne-blemish-care' => 'Acne & Blemish Care',
    'hydration-glow' => 'Hydration & Glow',
    'pigmentation' => 'Pigmentation & Dark Spots',
    'anti-aging' => 'Anti-Aging',
    'redness-sensitive-skin' => 'Redness & Sensitive Skin',
    'pore-care' => 'Pore Care',
    'glow' => 'Glow',
    'sun-care' => 'Sun Care',
];

$products = DB::table('products')->where('status', 'publish')->orderBy('id')->pluck('id')->all();
DB::table('products')->whereIn('id', $products)->update(['stock_status' => 'instock']);

// The demo product shots the preview web root already holds, as each product's photo.
$root = rtrim((string) env('KBB_PUBLIC_PATH'), '/');
foreach (DB::table('products')->whereIn('id', $products)->get(['id', 'slug']) as $row) {
    if (is_file($root.'/uploads/demo-shots/'.$row->slug.'-1-front.png')) {
        DB::table('products')->where('id', $row->id)->update(['image' => '/uploads/demo-shots/'.$row->slug.'-1-front.png']);
    }
}

// A few markdowns so the red percentage shows.
foreach (array_slice($products, 0, 37, true) as $n => $id) {
    if ($n % 3 === 0) {
        $price = (int) DB::table('products')->where('id', $id)->value('price');
        if ($price > 0) {
            DB::table('products')->where('id', $id)->update(['sale_price' => (int) round($price * 0.78)]);
        }
    }
}

$i = 0;
foreach ($concerns as $slug => $name) {
    $id = DB::table('categories')->where('slug', $slug)->value('id');
    if (! $id) {
        $id = DB::table('categories')->insertGetId(['name' => $name, 'slug' => $slug, 'path' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    }
    // Twelve products each, rotating through the catalogue.
    for ($k = 0; $k < 12; $k++) {
        $pid = $products[($i * 5 + $k) % count($products)];
        DB::table('category_product')->insertOrIgnore(['category_id' => $id, 'product_id' => $pid]);
    }
    $i++;
}

// The section ships OFF: switch it on for the preview, as Appearance → Homepage would.
$rows = [];
foreach (app(\App\Services\HomepageSections::class)->all() as $key => $row) {
    $on = $key === 'sbc';
    $rows[$key] = ['desktop' => $on ? true : $row['desktop'], 'mobile' => $on ? true : $row['mobile'], 'skin' => $row['skin'], 'order' => $row['order']];
}
app(\App\Services\SettingsService::class)->set('homepage_sections', $rows);

\Illuminate\Support\Facades\Cache::flush();
echo "sbc-seed: ".count($concerns)." concern collections, ".count($products)." products\n";
