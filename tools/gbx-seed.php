<?php

declare(strict_types=1);

/*
 * Lane GBX: tools/fgv-seed.php's shop (full size, minis, masks and one
 * variation in every range, gifts in TESTING for 127.0.0.1) with the homepage
 * gift section on, so the box, the checkout summary and the homepage rows can
 * be photographed in the full size -> mini -> mask order.
 *   FG_SEED=gbx-seed.php sh tools/fg-preview.sh 12340 -- never against a real database.
 */

require __DIR__.'/fgv-seed.php';

use Illuminate\Support\Facades\DB;

// The tint is a mini, not a mask: move it (and one count) to Mini products.
$tint = (int) DB::table('products')->where('slug', 'fgv-glow-lip-tint')->value('id');
foreach (DB::table('free_gift_range_items')->where('product_id', $tint)->where('kind', 'mask')->pluck('range_id') as $rid) {
    DB::table('free_gift_range_items')->where('range_id', $rid)->where('product_id', $tint)->update(['kind' => 'mini']);
    DB::table('free_gift_ranges')->where('id', $rid)->whereNotNull('need_mask')->update(['need_mask' => DB::raw('need_mask - 1'), 'need_mini' => DB::raw('coalesce(need_mini, 0) + 1')]);
}
DB::table('products')->where('id', $tint)->update(['gift_kind' => 'mini']);

$fg = app(\App\Services\FreeGifts::class);
$config = $fg->freshConfig();
$config['home'] = ['on' => true] + ($config['home'] ?? []);
$fg->saveConfig($config);
$fg->compile();
\Illuminate\Support\Facades\Cache::flush();
echo "gbx: homepage gift section on\n";

/*
 * PREVIEW DATA for the popup / criteria / glow designs (tools/gbx-kit.js):
 * each gift's photo, brand, regular and current price, and the homepage
 * section's bands -- written to the preview's own webroot, read by the shots
 * script and handed to the page inline (no request). Realistic prices first,
 * some on sale, so "cut price and price" has something to cut.
 */
foreach ([
    'Senka Perfect Whip%' => [3500, 2900], 'The Glow Set' => [12000, null], 'Black Rice Hyaluronic%' => [2500, 1990],
    'Mugwort Calming%' => [2200, null], 'Tea Tree Sheet Mask' => [900, 700], 'Mung Bean%' => [2000, null], 'Rose PDRN%' => [6900, 5900],
] as $like => [$reg, $sale]) {
    DB::table('products')->where('name', 'like', $like)->update(['price' => $reg, 'sale_price' => $sale]);
}
DB::table('product_variants')->where('product_id', $tint)->update(['price' => 4500, 'sale_price' => 3900]);
$fg->compile();
\Illuminate\Support\Facades\Cache::flush();

$out = [];
foreach (['en', 'ar'] as $lang) {
    app()->setLocale($lang);
    app()->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    $g = app(\App\Services\FreeGifts::class);
    $gifts = [];
    foreach ($g->ranges() as $r) {
        foreach ($g->linesFor($r) as $l) {
            $row = \App\Services\FreeGifts::rows([$l])[0];
            $item = $l['variant'] ?? $l['product'];
            $gifts[$row['name']] = ['name' => $row['name'], 'brand' => $row['brand'], 'img' => \App\Support\ImageVariants::variantUrl($row['image'], 400),
                'srcset' => \App\Support\ImageVariants::srcsetFor($row['image']), 'reg' => (int) $item->price, 'now' => (int) $item->effectivePrice()];
        }
    }
    $home = $g->home(request());
    $crit = array_map(static fn (array $r) => ['amount' => $r['amount'], 'words' => $r['words'], 'count' => $r['count'],
        'thumbs' => array_map(static fn (array $t) => ['name' => $t['name'], 'img' => \App\Support\ImageVariants::variantUrl($t['image'], 200), 'q' => $t['quantity']], $r['thumbs'])], $home['rows'] ?? []);
    $out[$lang] = ['gifts' => $gifts, 'crit' => $crit];
}
app()->setLocale('en');
file_put_contents((getenv('KBB_PUBLIC_PATH') ?: public_path()).'/gbx-data.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'gbx: preview data, '.count($out['en']['gifts']).' gifts, '.count($out['en']['crit'])." bands\n";
