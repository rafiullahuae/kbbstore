<?php

declare(strict_types=1);

/*
 * Lane GBX, the LIVE preview page (docs/gbx-previews/live/index.html):
 * tools/gbx-seed.php's shop with the owner's six live bands in place of the
 * seeded ones --
 *   AED 50-193 2 masks · 194-349 2 masks + 1 full size · 350-499 4 masks + 2
 *   full size · 500-999 4 masks + 3 full size · 1,000-1,599 5 masks + 3 full
 *   size · 1,600+ 4 masks + 4 full size
 * -- then each band's REAL box markup, rendered by the shop's own partial
 * (partials.free-gifts.box with FreeGifts::box()), in English and Arabic, plus
 * the popup data, written to the webroot as gbx-live.json for
 * tools/gbx-live-build.cjs.
 *   FG_SEED=gbx-live-seed.php sh tools/fg-preview.sh 12360 -- never against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/gbx-seed.php';

$url = rtrim((string) config('app.url'), '/').'/uploads/gifts/';
$brand = static fn (string $n) => DB::table('brands')->where('name', $n)->value('id')
    ?? DB::table('brands')->insertGetId(['name' => $n, 'slug' => 'gbx-'.\Illuminate\Support\Str::slug($n), 'created_at' => now(), 'updated_at' => now()]);
$product = static function (string $name, string $brandName, string $img, string $kind, int $reg, ?int $sale) use ($brand, $url): int {
    $id = DB::table('products')->where('name', $name)->value('id');
    $row = ['brand_id' => $brand($brandName), 'image' => $url.$img.'.webp', 'gift_kind' => $kind, 'price' => $reg, 'sale_price' => $sale,
        'status' => 'publish', 'type' => 'simple', 'manage_stock' => true, 'stock' => 200, 'stock_status' => 'instock', 'updated_at' => now()];
    if ($id) {
        DB::table('products')->where('id', $id)->update($row);

        return (int) $id;
    }

    return DB::table('products')->insertGetId($row + ['slug' => 'gbx-'.\Illuminate\Support\Str::slug($name), 'name' => $name, 'is_visible' => false, 'gift_only' => true, 'created_at' => now()]);
};

$g = [
    'senka' => $product('Senka Perfect Whip Fresh Beauty Face Foam', 'Shiseido', 'senka', 'full', 3500, 2900),
    'set' => $product('The Glow Set', 'AXIS-Y', 'glowset', 'full', 12000, null),
    'cream' => $product('Ceramide Barrier Moisture Cream', 'Isntree', 'cream', 'full', 6900, 5500),
    'tint' => $product('Fresh That Lasts Lip Tint', 'medicube', 'deo', 'full', 4500, 3900),
    'tea' => $product('Tea Tree Sheet Mask', 'Mediheal', 'mask', 'mask', 900, 700),
    'patch' => $product('Collagen Hydrogel Eye Patch', 'Petitfee', 'patch', 'mask', 1500, null),
];

// [min AED, max AED, [mask, full], picks [key, qty, kind]] -- the owner's live bands.
$bands = [
    [50, 193, [2, 0], [['tea', 2, 'mask']]],
    [194, 349, [2, 1], [['tea', 2, 'mask'], ['senka', 1, 'full']]],
    [350, 499, [4, 2], [['tea', 2, 'mask'], ['patch', 2, 'mask'], ['senka', 1, 'full'], ['set', 1, 'full']]],
    [500, 999, [4, 3], [['tea', 2, 'mask'], ['patch', 2, 'mask'], ['senka', 1, 'full'], ['set', 1, 'full'], ['cream', 1, 'full']]],
    [1000, 1599, [5, 3], [['tea', 3, 'mask'], ['patch', 2, 'mask'], ['senka', 1, 'full'], ['set', 1, 'full'], ['cream', 1, 'full']]],
    [1600, null, [4, 4], [['tea', 2, 'mask'], ['patch', 2, 'mask'], ['senka', 1, 'full'], ['set', 1, 'full'], ['cream', 1, 'full'], ['tint', 1, 'full']]],
];
DB::table('free_gift_range_items')->delete();
DB::table('free_gift_ranges')->delete();
foreach ($bands as $i => [$min, $max, [$mask, $full], $picks]) {
    $w = \App\Services\FreeGifts::criteriaWording(['mask' => $mask, 'mini' => 0, 'full' => $full]);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100 + 99, 'label_en' => $w['en'], 'label_ar' => $w['ar'],
        'sort' => $i, 'enabled' => true, 'need_mask' => $mask, 'need_mini' => 0, 'need_full' => $full, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($picks as $j => [$key, $qty, $kind]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $g[$key], 'quantity' => $qty, 'kind' => $kind, 'role' => 'primary', 'sort' => $j, 'created_at' => now(), 'updated_at' => now()]);
    }
}
$fg = app(\App\Services\FreeGifts::class);
$fg->compile();
\Illuminate\Support\Facades\Cache::flush();

$baskets = [150, 300, 450, 750, 1200, 2000]; // one basket inside each band
$strip = static fn (string $html): string => preg_replace('#(https?:)?//127\.0\.0\.1:\d+/uploads/gifts/#', 'images/', $html);
$out = ['baskets' => $baskets];
foreach (['en', 'ar'] as $lang) {
    app()->setLocale($lang);
    app()->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    \App\Services\SettingsService::forgetMemo();
    $fg = app(\App\Services\FreeGifts::class);
    $boxes = [];
    foreach ($baskets as $aed) {
        $boxes[] = $strip(view('partials.free-gifts.box', $fg->box(['subtotal' => $aed * 100, 'item_count' => 1], 'AE'))->render());
    }
    $gifts = [];
    foreach ($fg->ranges() as $r) {
        foreach ($fg->linesFor($r) as $l) {
            $row = \App\Services\FreeGifts::rows([$l])[0];
            $gifts[$row['name']] = ['name' => $row['name'], 'brand' => $row['brand'], 'img' => $strip($row['image']), 'srcset' => '',
                'reg' => (int) $l['product']->price, 'now' => (int) $l['product']->effectivePrice()];
        }
    }
    $home = $fg->home(request());
    $crit = array_map(static fn (array $r) => ['amount' => $r['amount'], 'words' => $r['words'], 'count' => $r['count'],
        'thumbs' => array_map(static fn (array $t) => ['name' => $t['name'], 'img' => $strip($t['image']), 'q' => $t['quantity']], $r['thumbs'])], $home['rows'] ?? []);
    $out[$lang] = ['boxes' => $boxes, 'gifts' => $gifts, 'crit' => $crit];
}
app()->setLocale('en');
file_put_contents((getenv('KBB_PUBLIC_PATH') ?: public_path()).'/gbx-live.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'gbx-live: '.count($out['en']['crit']).' bands, '.count($out['en']['gifts'])." gifts, boxes rendered EN + AR\n";
