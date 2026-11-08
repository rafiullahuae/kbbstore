<?php

declare(strict_types=1);

/*
 * Lane GP: after tools/fg2-seed.php -- four photos in the Media Library
 * (uploads/products/gp-*.webp, real files with `media` rows, so the picker can
 * offer them) and three gift-only products as the quick panel makes them, one
 * of them given on a past order. Run through tools/gp-preview.sh; never
 * against a real database.
 */

use App\Models\Media;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

$web = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($web.'/uploads/products', 0777, true);

$urls = [];
foreach (['mask', 'cream', 'deo', 'haruharu'] as $name) {
    $src = base_path('docs/freebies-options/img/'.$name.'.webp');
    $rel = 'uploads/products/gp-'.$name.'.webp';
    copy($src, $web.'/'.$rel);
    [$w, $h] = @getimagesize($web.'/'.$rel) ?: [600, 600];
    Media::query()->updateOrCreate(['path' => $rel], ['filename' => basename($rel), 'original_name' => basename($rel), 'mime' => 'image/webp',
        'size' => (int) filesize($web.'/'.$rel), 'width' => (int) $w, 'height' => (int) $h]);
    $urls[$name] = Media::urlFor($rel);
}

$made = [];
foreach ([
    ['Centella Calming Mini Mask', 'mask', 4500, 2990],
    ['Hydra Barrier Cream Travel Size', 'cream', 6000, null],
    ['Yuja Niacin Mini Toner', 'haruharu', 3800, 2500],
] as [$name, $img, $cut, $sale]) {
    $p = new Product;
    $p->forceFill(['name' => $name, 'slug' => 'gift-'.\Illuminate\Support\Str::slug($name), 'type' => 'simple', 'status' => 'publish',
        'price' => $cut, 'sale_price' => $sale, 'image' => $urls[$img], 'manage_stock' => false, 'stock' => null, 'stock_status' => 'instock',
        'gift_only' => true, 'is_visible' => false])->save();
    $made[$name] = $p;
}

// One of them already went out on an order: the list says so, and remove keeps it.
$oid = DB::table('orders')->where('order_number', 'like', 'FG2-%')->value('id');
if ($oid) {
    DB::table('order_items')->insert(['order_id' => $oid, 'product_id' => $made['Yuja Niacin Mini Toner']->id, 'name' => 'Yuja Niacin Mini Toner', 'quantity' => 1,
        'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true, 'created_at' => now(), 'updated_at' => now()]);
}

\Illuminate\Support\Facades\Cache::flush();
echo 'gp: '.count($urls).' library photos, '.count($made)." gift-only products\n";
