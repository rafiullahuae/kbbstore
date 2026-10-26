<?php

declare(strict_types=1);

/*
 * Lane NA: tools/mac-seed.php's shop, plus 34 more products added over the last
 * five months and a "New Arrivals" category holding two hand-picked old
 * products -- then the package's own migration, exactly as it runs on the live
 * shop: the rule is switched on, the hand rows are kept aside, and the list is
 * filled with what was added in the last 3 months, newest on top. Arabic is
 * switched on so /ar/ can be photographed. Run through tools/na-preview.sh;
 * never against a real database.
 *
 *   admin: owner@example.com / preview-password
 */

use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;

require __DIR__.'/mac-seed.php';

$now = StoreTime::now();
$palette = [['#E6F3DC', '#B6D9A2', '#6E9A5A'], ['#FCEBD2', '#EFC58A', '#B98545'], ['#FFE1EA', '#FFAFC5', '#D9476F'],
    ['#E2F1E3', '#A3D0A9', '#3B7A4B'], ['#E3ECFB', '#A9C1EE', '#3A5FA8'], ['#F3E6FA', '#D2B0EA', '#7B4BA0']];
$shapes = ['bottle', 'jar', 'tube'];
$names = ['Rice Toner', 'Green Tea Seed Serum', 'Centella Ampoule', 'Snail Repair Cream', 'Mugwort Mask', 'Vitamin C Drops',
    'Ceramide Barrier Cream', 'Peptide Eye Cream', 'Heartleaf Pad', 'Propolis Light Ampoule', 'Cica Sun Cream', 'Birch Juice Moisturiser',
    'Noni Energy Ampoule', 'Calamine Pore Mask', 'Collagen Jelly Cream', 'Rose Water Toner', 'Black Rice Oil', 'Mung Bean Cleanser',
    'Ginseng Essence', 'Retinal Night Serum', 'Panthenol Gel', 'Madecassoside Cream', 'Houttuynia Toner', 'Yuja Sleeping Mask',
    'Bakuchiol Serum', 'Artemisia Essence', 'Honey Glow Mask', 'Aloe Soothing Gel', 'Oat Calming Cream', 'Lotus Cleansing Balm',
    'Matcha Clay Stick', 'Pearl Brightening Toner', 'Barley Sun Stick', 'Seaberry Facial Oil'];

$art = static function (array $c, string $shape): string {
    [$a, $b, $k] = $c;
    $body = match ($shape) {
        'tube' => '<rect x="84" y="62" width="32" height="96" rx="6" fill="#fff" opacity=".92"/><rect x="88" y="44" width="24" height="20" rx="3" fill="'.$k.'"/>',
        'jar' => '<rect x="56" y="104" width="88" height="54" rx="10" fill="#fff" opacity=".92"/><rect x="52" y="86" width="96" height="22" rx="6" fill="'.$k.'"/>',
        default => '<rect x="74" y="70" width="52" height="96" rx="12" fill="#fff" opacity=".92"/><rect x="88" y="46" width="24" height="26" rx="3" fill="'.$k.'"/>',
    };

    return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/></linearGradient></defs><rect width="200" height="200" fill="url(#g)"/>'.$body.'</svg>');
};

$brand = (int) DB::table('brands')->value('id');
$ids = [];
foreach ($names as $i => $name) {
    $ids[] = DB::table('products')->insertGetId([
        'slug' => 'na-'.($i + 1), 'name' => $name, 'sku' => 'NA-'.($i + 1), 'brand_id' => $brand, 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => (49 + $i * 3) * 100, 'stock_status' => $i % 11 === 7 ? 'outofstock' : 'instock',
        'image' => $art($palette[$i % 6], $shapes[$i % 3]),
        'created_at' => $now->copy()->subDays(2 + $i * 4)->utc()->format('Y-m-d H:i:s'), 'updated_at' => now(),
    ]);
}

// The migration set's own demo products were stamped "now"; dated a year back
// they show the age window doing its job (they stay out).
DB::table('products')->where('slug', 'not like', 'na-%')->where('slug', 'not like', 'mac-%')
    ->update(['created_at' => $now->copy()->subYear()->utc()->format('Y-m-d H:i:s')]);

$cat = DB::table('categories')->insertGetId(['name' => 'New Arrivals', 'slug' => 'new-arrivals', 'created_at' => now(), 'updated_at' => now()]);
foreach (array_slice($ids, -2) as $p) {
    DB::table('category_product')->insert(['category_id' => $cat, 'product_id' => $p]);   // hand-picked, old
}

DB::table('settings')->updateOrInsert(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);

\App\Services\SettingsService::forgetMemo();
(require __DIR__.'/../database/migrations/2027_12_23_100000_new_arrivals_automatic.php')->up();
echo 'new-arrivals members = '.DB::table('category_product')->where('category_id', $cat)->count()."\n";
