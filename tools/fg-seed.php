<?php

declare(strict_types=1);

/*
 * Lane FG: tools/mac-seed.php's shop plus the owner's free-gift bands with
 * every gift picked (a sheet mask, the three minis, the three full-size
 * products), delivery to the UAE, Cash on delivery, Arabic on, and five
 * "band" products priced to land a one-line basket in each band. Run through
 * tools/fg-preview.sh; never against a real database.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/mac-seed.php';

foreach (['ShippingSeeder', 'PaymentProviderSeeder'] as $seeder) {
    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\'.$seeder, '--force' => true]);
}
\App\Models\PaymentProvider::query()->where('id', 'cod')->update(['enabled' => true]);
app(\App\Services\SettingsService::class)->set('language_ar_enabled', '1');

$web = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($web.'/uploads/gifts', 0777, true);
foreach (glob(base_path('docs/freebies-options/img/*.webp')) as $f) {
    copy($f, $web.'/uploads/gifts/'.basename($f));
}
$url = rtrim((string) config('app.url'), '/').'/uploads/gifts/';

$brand = static fn (string $n) => DB::table('brands')->where('name', $n)->value('id')
    ?? DB::table('brands')->insertGetId(['name' => $n, 'slug' => 'fg-'.\Illuminate\Support\Str::slug($n), 'created_at' => now(), 'updated_at' => now()]);

$gift = static function (string $name, string $brandName, ?string $img, int $stock) use ($brand, $url) {
    return DB::table('products')->insertGetId([
        'slug' => 'fg-'.\Illuminate\Support\Str::slug($name), 'name' => $name, 'brand_id' => $brand($brandName), 'type' => 'simple',
        'status' => 'publish', 'is_visible' => false, 'gift_only' => true, 'price' => 2500, 'manage_stock' => true, 'stock' => $stock,
        'stock_status' => 'instock', 'image' => $img ? $url.$img.'.webp' : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
};

$g = [
    'mask' => $gift('Tea Tree Sheet Mask', 'Mediheal', 'mask', 400),
    'm1' => $gift('Black Rice Hyaluronic Toner Mini', 'haruharu wonder', 'haruharu', 80),
    'm2' => $gift('Mugwort Calming Clay Mask Mini', 'Isntree', 'isntree', 80),
    'm3' => $gift('Mung Bean Milk Peeling Gel Mini', 'beplain', null, 80),
    'f1' => $gift('The Glow Set', 'AXIS-Y', 'glowset', 30),
    'f2' => $gift('Senka Perfect Whip Fresh Beauty Face Foam', 'Shiseido', 'senka', 30),
    'f3' => $gift('Rose PDRN Peptide Glowy Balm Petal Pink', 'haruharu wonder', null, 30),
];

$picks = [
    10000 => [['mask', 2]],
    30000 => [['mask', 3], ['m1', 1]],
    50000 => [['mask', 3], ['m1', 1], ['m2', 1], ['f2', 1]],
    100000 => [['mask', 5], ['m1', 1], ['m2', 1], ['f2', 1], ['f1', 1]],
    200000 => [['mask', 12], ['m1', 1], ['m2', 2], ['m3', 2], ['f1', 1], ['f2', 1], ['f3', 1]],
];
DB::table('free_gift_range_items')->delete();
foreach (DB::table('free_gift_ranges')->get() as $r) {
    foreach ($picks[(int) $r->min_fils] ?? [] as $i => [$k, $q]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $r->id, 'product_id' => $g[$k], 'quantity' => $q, 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
    }
}

// A one-line basket in each band.
foreach ([150, 350, 650, 1200, 2400] as $i => $aed) {
    DB::table('products')->insert([
        'slug' => 'fg-band-'.$aed, 'name' => 'Glow Ritual Set '.$aed, 'brand_id' => $brand('COSRX'), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => $aed * 100, 'manage_stock' => false, 'stock_status' => 'instock',
        'image' => $url.['b-serum', 'b-cream', 'b-aqua', 'cream', 'mist'][$i].'.webp', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

app(\App\Services\FreeGifts::class)->compile();
if (getenv('FG_ON') !== '0') {
    app(\App\Services\FreeGifts::class)->saveConfig(['on' => true] + app(\App\Services\FreeGifts::class)->config());
}
\Illuminate\Support\Facades\Cache::flush();
echo "free gifts seeded: ".DB::table('free_gift_range_items')->count()." picks, switch ".(getenv('FG_ON') === '0' ? 'OFF' : 'ON')."\n";
