<?php

declare(strict_types=1);

/*
 * Lane IR: the Image SEO preview shop. Run through tools/ir-preview.sh, never
 * against a real database.
 *
 *   owner@example.com / preview-password    Full Admin
 *   manager@example.com / preview-password  Store Manager (has media.image_seo)
 *   editor@example.com / preview-password   Content Editor (must get 403)
 *
 * Real pictures (GD) under the preview's web root with the names a camera, the
 * uploader and WordPress give them, real img-cache copies, a WebP with its
 * original kept, a picture two products share, one inside a description and a
 * Journal post, one only on a variant, and a Spotted card.
 */

use App\Models\Setting;
use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use App\Support\MediaUsageWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

foreach ([['Rafi Ullah', 'owner@example.com', 'owner'], ['Store Manager', 'manager@example.com', 'manager'], ['Content Editor', 'editor@example.com', 'editor']] as [$name, $email, $role]) {
    DB::table('admin_users')->updateOrInsert(['email' => $email], [
        'name' => $name, 'password' => Hash::make('preview-password'), 'role' => $role, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

$site = rtrim((string) config('app.url'), '/');
Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => $site]);

/** A product shot: a tinted backdrop, a bottle, a label. */
$picture = static function (string $rel, array $rgb, string $label, string $shape = 'bottle', string $type = 'jpg'): void {
    $img = imagecreatetruecolor(1000, 1000);
    [$r, $g, $b] = $rgb;
    for ($y = 0; $y < 1000; $y += 4) {
        $k = $y / 1000;
        imagefilledrectangle($img, 0, $y, 999, $y + 3, imagecolorallocate($img, (int) ($r + (255 - $r) * $k * .6), (int) ($g + (255 - $g) * $k * .6), (int) ($b + (255 - $b) * $k * .6)));
    }
    $white = imagecolorallocate($img, 252, 252, 250);
    $ink = imagecolorallocate($img, (int) ($r * .45), (int) ($g * .45), (int) ($b * .45));
    match ($shape) {
        'jar' => [imagefilledrectangle($img, 300, 520, 700, 800, $white), imagefilledrectangle($img, 280, 440, 720, 530, $ink)],
        'tube' => [imagefilledrectangle($img, 420, 260, 580, 820, $white), imagefilledrectangle($img, 440, 180, 560, 270, $ink)],
        'patch' => [imagefilledellipse($img, 380, 520, 260, 170, $white), imagefilledellipse($img, 620, 520, 260, 170, $white)],
        default => [imagefilledrectangle($img, 380, 320, 620, 820, $white), imagefilledrectangle($img, 450, 200, 550, 330, $ink)],
    };
    imagestring($img, 5, 330, 880, $label, $ink);
    $file = public_path($rel);
    @mkdir(dirname($file), 0775, true);
    $type === 'webp' ? imagewebp($img, $file, 82) : imagejpeg($img, $file, 86);
};

$brand = static fn (string $name, string $slug) => DB::table('brands')->insertGetId(['name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
$cat = static fn (string $name, string $slug) => DB::table('categories')->insertGetId(['name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);

$medicube = $brand('Medicube', 'ir-medicube');
$anua = $brand('Anua', 'ir-anua');
$cosrx = $brand('COSRX', 'ir-cosrx');
$boj = $brand('Beauty of Joseon', 'ir-beauty-of-joseon');
$eye = $cat('Eye Care', 'ir-eye-care');
$toner = $cat('Toners', 'ir-toners');
$sun = $cat('Sunscreen', 'ir-sunscreen');

$pics = [
    'uploads/products/20261005-101010-a1b2c3.jpg' => [[244, 160, 190], 'PDRN EYE PATCHES', 'patch'],
    'uploads/products/20261005-101011-d4e5f6.jpg' => [[236, 150, 180], 'PDRN - BOX', 'jar'],
    'uploads/products/IMG_1234.jpg' => [[230, 170, 200], 'ON SKIN', 'patch'],
    'wp-content/uploads/2023/05/DSC00042.jpg' => [[220, 140, 170], 'INGREDIENTS', 'jar'],
    'uploads/products/20261005-101015-zz9900.jpg' => [[250, 190, 210], 'PDRN PINK', 'patch'],
    'uploads/products/20261006-090000-aa11bb.jpg' => [[170, 210, 150], 'HEARTLEAF TONER', 'bottle'],
    'uploads/products/20261006-090001-shared.jpg' => [[150, 200, 140], 'HEARTLEAF LINE', 'jar'],
    'uploads/products/20261006-090002-oil111.jpg' => [[200, 220, 160], 'CLEANSING OIL', 'bottle'],
    'uploads/products/Relief_Sun_SPF50.jpg' => [[240, 220, 170], 'RELIEF SUN', 'tube'],
];

foreach ($pics as $rel => [$rgb, $label, $shape]) {
    $picture($rel, $rgb, $label, $shape);
}

// COSRX: a WebP whose JPEG original was kept (WebpBulk's keep_original).
$picture('uploads/products/20261001-000000-snail1.jpg', [240, 200, 150], 'SNAIL 96', 'bottle');
$picture('uploads/products/20261001-000000-snail1.webp', [240, 200, 150], 'SNAIL 96', 'bottle', 'webp');
DB::table('webp_conversions')->insert(['from_path' => 'uploads/products/20261001-000000-snail1.jpg', 'to_path' => 'uploads/products/20261001-000000-snail1.webp',
    'origin' => 'bulk', 'status' => 'converted', 'refs_done' => true, 'created_at' => now(), 'updated_at' => now()]);

foreach (array_merge(array_keys($pics), ['uploads/products/20261001-000000-snail1.webp']) as $rel) {
    MediaRegistrar::record($rel);
    ImageVariants::generate('/'.$rel);
}

$u = static fn (string $rel) => $site.'/'.$rel;
$product = static function (array $row) {
    return DB::table('products')->insertGetId($row + ['status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()]);
};

$medi = $product(['name' => 'Medicube PDRN Eye Patches', 'slug' => 'medicube-pdrn-eye-patches', 'sku' => 'MDC-PDRN-60', 'brand_id' => $medicube, 'category_id' => $eye, 'price' => 9900, 'type' => 'variable',
    'image' => $u('uploads/products/20261005-101010-a1b2c3.jpg'),
    'images' => json_encode([$u('uploads/products/20261005-101011-d4e5f6.jpg'), '/uploads/products/IMG_1234.jpg', '/wp-content/uploads/2023/05/DSC00042.jpg']),
    'short_description' => 'Salmon PDRN hydrogel eye patches.',
    'description' => '<p>Place one patch under each eye for 20 minutes.</p><p><img src="/uploads/products/IMG_1234.jpg" alt="patch on skin" width="500" height="500"></p>']);
DB::table('product_variants')->insert(['product_id' => $medi, 'sku' => 'MDC-PDRN-60-PINK', 'price' => 9900, 'image' => $u('uploads/products/20261005-101015-zz9900.jpg'), 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

$toner1 = $product(['name' => 'Anua Heartleaf 77% Soothing Toner', 'slug' => 'anua-heartleaf-77-toner', 'sku' => 'ANU-HL77', 'brand_id' => $anua, 'category_id' => $toner, 'price' => 7600,
    'image' => $u('uploads/products/20261006-090000-aa11bb.jpg'), 'images' => json_encode([$u('uploads/products/20261006-090001-shared.jpg')])]);
$oil = $product(['name' => 'Anua Heartleaf Pore Control Cleansing Oil', 'slug' => 'anua-heartleaf-cleansing-oil', 'sku' => 'ANU-HL-CO', 'brand_id' => $anua, 'category_id' => $toner, 'price' => 8400,
    'image' => $u('uploads/products/20261006-090002-oil111.jpg'), 'images' => json_encode([$u('uploads/products/20261006-090001-shared.jpg')])]);
$snail = $product(['name' => 'COSRX Advanced Snail 96 Mucin Power Essence', 'slug' => 'cosrx-snail-96-essence', 'sku' => 'CRX-SN96', 'brand_id' => $cosrx, 'category_id' => $toner, 'price' => 6900,
    'image' => '/uploads/products/20261001-000000-snail1.webp']);
$sunP = $product(['name' => 'Beauty of Joseon Relief Sun: Rice + Probiotics SPF50+', 'slug' => 'boj-relief-sun', 'sku' => 'BOJ-RS50', 'brand_id' => $boj, 'category_id' => $sun, 'price' => 6500,
    'image' => '/uploads/products/Relief_Sun_SPF50.jpg', 'image_alts' => json_encode(['/uploads/products/Relief_Sun_SPF50.jpg' => 'Beauty of Joseon Relief Sun SPF50+ tube'])]);

foreach ([[$medi, $eye], [$toner1, $toner], [$oil, $toner], [$snail, $toner], [$sunP, $sun]] as [$p, $c]) {
    DB::table('category_product')->insert(['product_id' => $p, 'category_id' => $c]);
}

DB::table('posts')->insert(['title' => 'Our eye-patch routine', 'slug' => 'eye-patch-routine', 'status' => 'published', 'body' => '<p>Two patches, twenty minutes.</p><img src="'.$u('uploads/products/IMG_1234.jpg').'" alt="">', 'created_at' => now(), 'updated_at' => now()]);

if (DB::getSchemaBuilder()->hasTable('spotted_posts')) {
    DB::table('spotted_posts')->insert(['image' => '/uploads/products/20261005-101011-d4e5f6.jpg', 'handle' => 'kbeautybliss', 'caption' => 'PDRN patches', 'product_id' => $medi, 'link_to' => 'product', 'sort' => 0, 'on_home' => true, 'created_at' => now(), 'updated_at' => now()]);
}

// The demo catalogue's own shots: the migration set catalogues them in the
// library, this throwaway web root does not have the files. Drawn, so the
// Media Library screenshots show no broken tile that is not this lane's.
foreach (DB::table('products')->get(['slug', 'name', 'sku', 'wc_id', 'short_description']) as $row) {
    if (\App\Support\DemoProductShots::isDemo($row)) {
        \App\Support\DemoProductShots::ensureFor((string) $row->slug, (string) $row->name);
    }
}

MediaUsageWriter::rebuild();

// The ids the shot script needs (cart, product editor): beside the database,
// never in the web root.
file_put_contents(dirname((string) config('database.connections.sqlite.database')).'/ids.json', json_encode(['medi' => $medi, 'toner' => $toner1, 'oil' => $oil, 'snail' => $snail, 'sun' => $sunP]));

// What the migration set cached (home sections, counts) predates this
// catalogue; the before/after comparison must start from the shop as seeded.
\Illuminate\Support\Facades\Cache::flush();

echo "seeded Image SEO preview: 5 products, ".count($pics)." pictures + a WebP pair\n";
