<?php
/*
 * Seed the Lane HA preview (master plan row 55, the new homepage): the demo
 * catalogue plus what each new section needs to draw something real —
 * product photographs, fourteen brands (some with a banner photo and a logo,
 * some with neither, to show the fallbacks), three journal posts with covers,
 * orders and page views from the last seven days for Trending, and a
 * variable product whose options start under AED 54.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

(new \Database\Seeders\DatabaseSeeder)->run();

$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$serif = '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf';
$dir = public_path('uploads/ha');
@mkdir($dir, 0755, true);

$pal = [[252,228,236,244,143,177],[227,242,253,100,181,246],[232,245,233,129,199,132],[255,243,224,255,183,77],[243,229,245,186,104,200],[224,247,250,77,208,225],[255,248,225,255,213,79],[251,233,231,255,138,101]];

$bottle = function (string $file, int $w, int $h, int $k, bool $photo = false) use ($pal, $dir) {
    $im = imagecreatetruecolor($w, $h);
    [$r1,$g1,$b1,$r2,$g2,$b2] = $pal[$k % 8];
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $c = imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t * .35), (int) ($g1 + ($g2 - $g1) * $t * .35), (int) ($b1 + ($b2 - $b1) * $t * .35));
        imageline($im, 0, $y, $w, $y, $c);
    }
    $bw = (int) ($w * .26); $bh = (int) ($h * ($photo ? .5 : .62));
    $x = (int) (($w - $bw) / 2); $y0 = (int) (($h - $bh) / 2) + 20;
    imagefilledrectangle($im, $x + (int) ($bw * .3), $y0 - (int) ($bh * .12), $x + (int) ($bw * .7), $y0, imagecolorallocate($im, 60, 50, 56));
    imagefilledrectangle($im, $x, $y0, $x + $bw, $y0 + $bh, imagecolorallocate($im, $r2, $g2, $b2));
    imagefilledrectangle($im, $x + (int) ($bw * .15), $y0 + (int) ($bh * .35), $x + (int) ($bw * .85), $y0 + (int) ($bh * .65), imagecolorallocate($im, 255, 255, 255));
    imagewebp($im, $dir.'/'.$file, 82);
    imagedestroy($im);

    return '/uploads/ha/'.$file;
};

foreach (Product::query()->get() as $i => $p) {
    $p->image = $bottle('p'.$p->id.'.webp', 800, 800, $i);
    $p->total_sales = 400 - $i * 13;
    $p->save();
}

// Fourteen brands, as the owner's preview has: the demo's eight plus six.
$extra = ['Haruharu Wonder', 'Goodal', 'I\'m From', 'SOME BY MI', 'Jumiso', 'Arencia'];
$cats = Category::query()->pluck('id')->all();
foreach ($extra as $i => $name) {
    $b = Brand::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
    for ($j = 0; $j < 2; $j++) {
        $p = Product::create([
            'slug' => Str::slug($name).'-essence-'.$j, 'name' => $name.' Calming Essence '.($j + 1), 'brand_id' => $b->id,
            'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'price' => 3900 + 1000 * $i + 500 * $j, 'total_sales' => 30 + $i,
        ]);
        $p->image = $bottle('x'.$i.$j.'.webp', 800, 800, $i + $j + 3);
        $p->save();
        $p->categories()->syncWithoutDetaching([$cats[($i + $j) % count($cats)]]);
    }
}

// Logos (rendered type) and banner photos for most brands; two keep neither,
// to show the name-in-type fallback on the phone and the soft panel on a laptop.
foreach (Brand::query()->orderBy('id')->get() as $i => $b) {
    if ($i % 7 === 6) {
        continue;
    }
    $lw = 480; $lh = 160;
    $im = imagecreatetruecolor($lw, $lh);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    $txt = $i % 3 === 0 ? strtoupper($b->name) : $b->name;
    $f = $i % 2 ? $serif : $font;
    $size = 46;
    do { $box = imagettfbbox($size, 0, $f, $txt); $size -= 2; } while (($box[2] - $box[0]) > $lw - 30 && $size > 12);
    imagettftext($im, $size, 0, (int) (($lw - ($box[2] - $box[0])) / 2), (int) (($lh + $size) / 2), imagecolorallocate($im, 42, 34, 40), $f, $txt);
    imagepng($im, $dir.'/logo'.$b->id.'.png');
    imagedestroy($im);
    $b->logo = '/uploads/ha/logo'.$b->id.'.png';
    if ($i % 5 !== 4) {
        $b->banner = ['enabled' => false, 'image' => $bottle('brand'.$b->id.'.webp', 800, 1000, $i, true), 'image_alt' => $b->name.' skincare on a soft pastel backdrop'];
    }
    $b->save();
}

// Three journal articles with covers.
foreach ([
    ['The 7-step Korean skincare routine, explained', 'Routines', 'Why each step exists and which ones you can skip on a busy morning.'],
    ['Snail mucin, centella and niacinamide: what each one does', 'Ingredients', 'Three ingredients you will see on every K-beauty label, and what they are for.'],
    ['How to choose a sunscreen for oily skin', 'Sun care', 'Textures, finishes and how much to apply — a short guide to daily SPF.'],
] as $i => [$title, $tag, $excerpt]) {
    Post::create([
        'slug' => Str::slug($title), 'title' => $title, 'tag' => $tag, 'excerpt' => $excerpt,
        'body' => '<p>'.$excerpt.'</p>', 'status' => 'published', 'published_at' => now()->subDays(3 - $i),
        'cover' => $bottle('post'.$i.'.webp', 1280, 800, $i + 5, true),
    ]);
}

// Trending: real orders and views in the last seven days, on products that
// are NOT the lifetime best sellers, so the two rows visibly differ.
$tail = Product::query()->visible()->orderBy('total_sales')->limit(8)->get();
foreach ($tail as $i => $p) {
    $oid = DB::table('orders')->insertGetId([
        'order_number' => 'HA'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'email' => "buyer{$i}@preview.test",
        'status' => 'processing', 'currency' => 'AED', 'subtotal' => 10000, 'total' => 10000,
        'created_at' => now()->subDays($i % 6), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert(['order_id' => $oid, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => 8 - $i,
        'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000, 'created_at' => now(), 'updated_at' => now()]);
}

// A variable product whose own `price` is NULL and whose cheapest option is
// AED 45 — it belongs under AED 54 by the price a shopper can pay.
$v = Product::create(['slug' => 'ha-variable-cushion', 'name' => 'Glow Cushion (3 shades)', 'type' => 'variable', 'status' => 'publish',
    'is_visible' => true, 'stock_status' => 'instock', 'price' => null, 'brand_id' => Brand::query()->value('id'), 'total_sales' => 390,
    ]);
$v->image = $bottle('var.webp', 800, 800, 2);
$v->save();
foreach ([4500, 6900] as $j => $price) {
    DB::table('product_variants')->insert(['product_id' => $v->id, 'sku' => 'HA-V'.$j, 'price' => $price, 'stock_status' => 'instock',
        'position' => $j, 'created_at' => now(), 'updated_at' => now()]);
}

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);
\Illuminate\Support\Facades\Cache::flush();
echo "ha seed done\n";
