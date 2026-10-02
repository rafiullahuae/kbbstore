<?php
/*
 * Seed for the Lane PT preview: the old shop's category banner as the title
 * background, and the link at the end of the Anua foam's description.
 *
 * The SAME seed runs against the base tree (BEFORE) and this branch (AFTER).
 * What differs is only what the code can do with it:
 *
 *   BEFORE  the base tree: no `header_*` columns, so the export's banner has
 *           nowhere to land -- the category page draws its plain title, and
 *           the foam's "Face Cleansers" link goes to kbeautybliss.com.
 *   AFTER   this tree: categories.csv WITH exporter 1.11.0's banner columns
 *           goes through the REAL ImportRunner, the banner files are dropped
 *           into wp-content/uploads the way the owner copies them, and the
 *           REAL picture pass (MediaRewrite) re-points the rows at them. The
 *           old-site link is left in place for the admin shot to fix.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$after = Schema::hasColumn('categories', 'header_image');
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();

/* A picture that reads as a banner in a screenshot: a two-tone wash, soft
   discs, and a label. Wide, the shape a category banner is. */
$banner = function (string $path, int $w, int $h, string $from, string $to, string $label): void {
    $hex = static fn (string $x): array => [hexdec(substr($x, 0, 2)), hexdec(substr($x, 2, 2)), hexdec(substr($x, 4, 2))];
    $im = imagecreatetruecolor($w, $h);
    [$r1, $g1, $b1] = $hex($from);
    [$r2, $g2, $b2] = $hex($to);
    for ($x = 0; $x < $w; $x++) {
        $t = $x / max(1, $w - 1);
        imageline($im, $x, 0, $x, $h, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
    }
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 90);
    foreach ([[0.18, 0.35, 0.5], [0.78, 0.6, 0.7], [0.5, 1.1, 0.9]] as [$cx, $cy, $s]) {
        imagefilledellipse($im, (int) ($w * $cx), (int) ($h * $cy), (int) ($h * $s), (int) ($h * $s), $soft);
    }
    $ink = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 3, 14, $h - 22, $label, $ink);
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 86);
};

$tile = function (string $path, string $bg, string $fg, string $label): void {
    $hex = static fn (string $x): array => [hexdec(substr($x, 0, 2)), hexdec(substr($x, 2, 2)), hexdec(substr($x, 4, 2))];
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, ...$hex($bg)));
    imagefilledellipse($im, 300, 300, 360, 360, imagecolorallocate($im, ...$hex($fg)));
    $ink = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, (int) ((600 - imagefontwidth(5) * strlen($label)) / 2), 292, $label, $ink);
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 88);
};

$brand = Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);

/* The two categories, exactly as the 1.10.1 import left them on the live shop. */
$skin = Category::updateOrCreate(['slug' => 'skincare'], ['name' => 'Skincare', 'source_term_id' => 15]);
$skin->forceFill(['path' => 'skincare', 'depth' => 0, 'parent_id' => null])->save();

$sun = Category::updateOrCreate(['slug' => 'sunscreens'], [
    'name' => 'Sunscreens', 'source_term_id' => 120, 'parent_id' => $skin->id,
    'description' => 'Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun.',
]);
$sun->forceFill(['path' => 'skincare/sunscreens', 'depth' => 1])->save();

$wash = Category::updateOrCreate(['slug' => 'face-washes'], [
    'name' => 'Face Cleansers', 'source_term_id' => 22, 'parent_id' => $skin->id,
    'description' => 'Gentle foams and gels for a clean, comfortable second step.',
]);
$wash->forceFill(['path' => 'skincare/face-washes', 'depth' => 1])->save();

$palette = [['FDEBD0', 'F5B041', 'BEAUTY OF JOSEON'], ['E8F6F3', '48C9B0', 'ROUND LAB'], ['FDEDEC', 'EC7063', 'SKIN1004'], ['EBF5FB', '5DADE2', 'ISNTREE'], ['F4ECF7', 'AF7AC5', 'COSRX']];

foreach ($palette as $i => [$bg, $fg, $label]) {
    $tile($root.'/uploads/products/sun-'.$i.'.jpg', $bg, $fg, $label);
    $p = Product::updateOrCreate(['slug' => 'sunscreen-'.$i], [
        'name' => ucwords(strtolower($label)).' Sun Cream SPF50+ PA++++ 50ml', 'wc_id' => 31000 + $i,
        'price' => 6500 + $i * 500, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'image' => '/uploads/products/sun-'.$i.'.jpg', 'category_id' => $sun->id,
    ]);
    $p->categories()->sync([$sun->id]);
}

if ($after) {
    /* categories.csv as exporter 1.11.0 writes it for these two terms. */
    $dir = storage_path('framework/testing/ptb-preview-export');
    @mkdir($dir, 0775, true);
    $long = '<p>Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun. '
        .'Find water-light essences, tone-up creams and mineral formulas from <a href="https://kbeautybliss.com/brand/beauty-of-joseon/">Beauty of Joseon</a>, '
        .'Round Lab and Skin1004 -- no white cast, no greasy finish, and gentle enough for sensitive skin. '
        .'Reapply every two hours outdoors, and finish your routine with one every morning.</p>';
    $rows = [
        ['term_id', 'name', 'slug', 'parent', 'description', 'image', 'position', 'banner_image', 'banner_source_key', 'title_override', 'subtitle'],
        ['15', 'Skincare', 'skincare', '0', '', '', '0', '', '', '', ''],
        ['120', 'Sunscreens', 'sunscreens', '15', $long, '', '3',
            'https://kbeautybliss.com/wp-content/uploads/2023/05/sunscreens-banner.jpg', 'cover_section (rey-global-sections 18180)', 'Korean Sunscreens', ''],
        ['22', 'Face Cleansers', 'face-washes', '15', '<p>Gentle foams and gels for a clean, comfortable second step.</p>', '', '1',
            'https://kbeautybliss.com/wp-content/uploads/2023/05/cleansers-banner.jpg', 'cover_section (rey-global-sections 18181)', '', ''],
    ];
    $fh = fopen($dir.'/categories.csv', 'w');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '');
    }
    fclose($fh);

    $report = (new \App\Services\Import\ImportRunner)->run(new \App\Services\Import\ImportOptions(
        directory: $dir, only: ['categories'], runKey: 'ptb-preview', restart: true,
    ));
    $r = $report->for('categories');
    echo "categories: created {$r->created}, updated {$r->updated}, unchanged {$r->unchanged}, rejected {$r->rejectedCount()}\n";

    /* The owner copies wp-content/uploads across; the picture pass re-points. */
    $banner($root.'/wp-content/uploads/2023/05/sunscreens-banner.jpg', 1600, 500, 'F7B267', 'F25C54', 'sunscreens-banner.jpg');
    $banner($root.'/wp-content/uploads/2023/05/cleansers-banner.jpg', 1600, 500, '7FC8A9', '4D8CA8', 'cleansers-banner.jpg');

    $rewrite = new \App\Services\Import\MediaRewrite;
    $mine = array_values(array_filter($rewrite->propose(['kbeautybliss.com']), static fn ($p) => $p['field'] === 'header_image'));
    echo 'picture pass: '.count($mine).' banner(s) proposed, '.$rewrite->apply($mine)." re-pointed\n";

}

/* The foam AFTER the import, as on the live shop: it was imported before this
   package, so its old link is still there for the admin shot to fix. */
/* The owner's case: the Anua foam, whose copy ends with a link to the old site. */
$tile($root.'/uploads/products/anua-foam.jpg', 'F4F7EC', '9DBF7A', 'ANUA FOAM');
$foam = Product::updateOrCreate(['slug' => 'anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml'], [
    'name' => 'Anua Heartleaf Quercetinol Pore Deep Cleansing Foam 150ml',
    'wc_id' => 30412, 'price' => 8900, 'brand_id' => $brand->id, 'category_id' => $wash->id,
    'image' => '/uploads/products/anua-foam.jpg', 'status' => 'publish', 'is_visible' => true,
    'stock_status' => 'instock', 'type' => 'simple',
    'short_description' => '<p>A low-pH foam that deep-cleans pores while heartleaf and quercetinol keep skin calm.</p>',
    'description' => "<p>Anua's Heartleaf Quercetinol Pore Deep Cleansing Foam lifts oil, sebum and the day's sunscreen in one wash, "
        ."without the tight, squeaky feel of a harsh cleanser.</p>\n"
        .'<p>Get premium <a href="https://kbeautybliss.com/face-washes/" rel="noopener noreferrer" target="_blank">Face Cleansers</a> '
        .'at unbeatable prices only at K-Beauty Bliss</p>',
]);
$foam->categories()->sync([$wash->id]);

if ($after) {
    echo 'links to the old site waiting: '.\App\Services\Import\OldSiteLinks::summarise((new \App\Services\Import\OldSiteLinks)->propose())['links']."\n";
}


\Illuminate\Support\Facades\Cache::flush();

echo 'ptb seed: '.($after ? 'AFTER' : 'BEFORE').", /collections/skincare/sunscreens/, /product/{$foam->slug}/\n";
