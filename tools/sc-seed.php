<?php
/*
 * Seed for the Lane SC preview: Catalog -> Shop by Concern. The seven concern
 * collections, a few products already in them, and eight recent products in
 * none -- texts taken from the live pages read on 16 October 2026 -- with
 * flat-colour thumbnails drawn here. PREVIEW DATABASE ONLY.
 */
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Rafi (owner)', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$now = now();
$cat = function (string $slug, string $name) use ($now): int {
    $id = DB::table('categories')->where('slug', $slug)->value('id');

    return $id ? (int) $id : (int) DB::table('categories')->insertGetId(['slug' => $slug, 'name' => $name, 'path' => $slug, 'created_at' => $now, 'updated_at' => $now]);
};

$concern = [];
foreach (\App\Services\ConcernSync\ConcernSync::CONCERNS as $slug => $name) {
    $concern[$slug] = $cat($slug, $name);
}
$types = ['Face Serums' => $cat('face-serums', 'Face Serums'), 'Face Creams' => $cat('face-creams', 'Face Creams'),
    'Toners' => $cat('toners', 'Toners'), 'Face Masks' => $cat('face-masks', 'Face Masks'),
    'Shampoos' => $cat('shampoos', 'Shampoos'), 'Exfoliators' => $cat('exfoliators', 'Exfoliators')];

$brand = function (string $name) use ($now): int {
    $slug = \Illuminate\Support\Str::slug($name);
    $id = DB::table('brands')->where('slug', $slug)->value('id');

    return $id ? (int) $id : (int) DB::table('brands')->insertGetId(['name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
};

@mkdir(public_path('uploads/sc'), 0777, true);
$i = 0;
$colours = [[247, 214, 224], [214, 234, 248], [253, 235, 208], [220, 245, 225], [235, 222, 250], [250, 230, 210], [225, 240, 240], [245, 245, 220]];
$thumb = function (string $slug) use (&$i, $colours): string {
    $im = imagecreatetruecolor(240, 240);
    [$r, $g, $b] = $colours[$i++ % count($colours)];
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagefilledrectangle($im, 90, 40, 150, 200, imagecolorallocate($im, max(0, $r - 60), max(0, $g - 60), max(0, $b - 60)));
    imagepng($im, public_path('uploads/sc/'.$slug.'.png'));

    return '/uploads/sc/'.$slug.'.png';
};

$product = function (string $slug, string $name, string $brandName, string $type, string $short, array $concerns = []) use ($now, $thumb, $brand, $types, $concern): void {
    $id = (int) DB::table('products')->insertGetId([
        'slug' => $slug, 'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'brand_id' => $brand($brandName), 'image' => $thumb($slug),
        'short_description' => '<p>'.$short.'</p>', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('category_product')->insert(['category_id' => $types[$type], 'product_id' => $id]);
    foreach ($concerns as $c) {
        DB::table('category_product')->insert(['category_id' => $concern[$c], 'product_id' => $id]);
    }
};

// Already filed.
$product('anua-heartleaf-77-soothing-toner', 'Anua - Heartleaf 77% Soothing Toner', 'Anua', 'Toners', 'Heartleaf extract calms redness.', ['redness-sensitive-skin']);
$product('cosrx-bha-blackhead-power-liquid', 'COSRX - BHA Blackhead Power Liquid', 'COSRX', 'Exfoliators', '4% betaine salicylate for blackheads.', ['pore-care', 'acne-blemish-care']);

// Recent, in no concern (oldest first, so the newest has the highest id).
$product('aprilskin-pink-aloe-vita-toner', 'APRILSKIN - Pink Aloe Vita Toner', 'APRILSKIN', 'Toners', 'Formulated with 80% pink aloe vera to hydrate, cool and soothe. Niacinamide, tea tree and vitamin C derivatives help manage excess sebum and improve the appearance of dullness.');
$product('aprilskin-txa-niacinamide-99-night-wrapping-mask', 'APRILSKIN - TXA Niacinamide 99 Night Wrapping Mask', 'APRILSKIN', 'Face Masks', 'An overnight mask for dark spots, uneven tone and dullness: 5% niacinamide, 1% tranexamic acid (TXA), vitamin C and multiple forms of hyaluronic acid.');
$product('medicube-zero-pore-pad-mild', 'Medicube - Zero Pore Pad Mild', 'Medicube', 'Exfoliators', 'Toner pads with salicylic acid and PHA that gently exfoliate dead skin cells and excess sebum while minimizing the appearance of enlarged pores.');
$product('some-by-mi-cica-peptide-anti-hair-loss-derma-scalp-treatment', 'SOME BY MI - Cica Peptide Anti Hair Loss Derma Scalp Treatment', 'SOME BY MI', 'Shampoos', 'Cica and peptides for a healthier-looking scalp.');
$product('purito-seoul-azelaic-acid-10-kojic-tea-tree-serum', 'PURITO SEOUL - Azelaic Acid 10 Kojic Tea Tree Serum', 'PURITO SEOUL', 'Face Serums', '10% azelaic acid, 10% tea tree leaf water and kojic acid to target blemishes, post-acne marks and uneven skin tone.');
$product('purito-seoul-retinol-retinal-2000-nad-serum', 'PURITO SEOUL - Retinol Retinal 2000 NAD+ Serum', 'PURITO SEOUL', 'Face Serums', '1,000 ppm retinol, 1,000 ppm retinal and 100 ppm NAD+ to target visible signs of ageing; with adenosine and ceramide NP.');
$product('purito-seoul-txa-6-niacinamide-10-retinal-serum', 'PURITO SEOUL - TXA 6 Niacinamide 10 Retinal Serum', 'PURITO SEOUL', 'Face Serums', '6% tranexamic acid, 10% niacinamide and encapsulated retinal for dark spots, uneven tone and fine lines.');
$product('sungboon-editor-deep-collagen-retinol-power-boosting-capsule-cream', 'SUNGBOON EDITOR - Deep Collagen Retinol Power Boosting Capsule Cream', 'SUNGBOON EDITOR', 'Face Creams', 'Retinol, low-molecular-weight collagen, nine types of peptides and eight types of hyaluronic acid for fine lines and a plump, smooth complexion.');

echo "Lane SC seed: ".DB::table('products')->count()." products.\n";
