<?php
/*
 * Lane SPD -- the four page types the owner named, at live scale, for the
 * before/after: Lane LH's homepage (tools/lh-seed.php, which brings Lane SP's
 * live-scale catalogue), plus
 *   - a BRAND with a banner picture (header_image, 2400x700, as imported),
 *   - a CATEGORY with its Banner picture AND a phone picture (img_phone),
 *   - a CONTENT PAGE (privacy-policy, which has a route) with its own Header picture,
 *   - eight REVIEWS with phone-camera photographs (1080x1440 JPEG) on the
 *     product the owner measured,
 *   - the three marketing pixels switched on (fake ids), so the deferred
 *     loader's wake-up is part of what is measured.
 * Every picture also gets its img-cache copies -- the steady state the live
 * shop is in after an upload (MediaUploadController::sizeCopies).
 */
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Services\Analytics;
use App\Services\SettingsService;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\DB;

require __DIR__.'/lh-seed.php';

$spdDraw = static function (string $rel, int $w, int $h, int $seed): string {
    $file = public_path($rel);
    @mkdir(dirname($file), 0775, true);
    $img = imagecreatetruecolor($w, $h);
    mt_srand($seed);
    $base = [mt_rand(150, 240), mt_rand(120, 220), mt_rand(140, 230)];
    for ($y = 0; $y < $h; $y += 4) {
        $t = $y / $h;
        imagefilledrectangle($img, 0, $y, $w, $y + 4, imagecolorallocate($img,
            (int) ($base[0] * (1 - $t) + 245 * $t), (int) ($base[1] * (1 - $t) + 235 * $t), (int) ($base[2] * (1 - $t) + 240 * $t)));
    }
    imagefilledellipse($img, (int) ($w * .6), (int) ($h * .5), (int) (min($w, $h) * .6), (int) (min($w, $h) * .6), imagecolorallocate($img, 255, 255, 255));
    // Photographic noise, so the JPEG weighs what a real photograph weighs.
    for ($n = 0; $n < ($w * $h) / 6; $n++) {
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($img, mt_rand(90, 255), mt_rand(90, 255), mt_rand(90, 255)));
    }
    imagejpeg($img, $file, 85);
    imagedestroy($img);
    ImageVariants::generate('/'.$rel);
    ImageVariants::generateWide('/'.$rel);

    return '/'.$rel;
};

// ── brand ──────────────────────────────────────────────────────────────────
$brandId = (int) DB::table('products')->whereNotNull('brand_id')->groupBy('brand_id')
    ->orderByRaw('count(*) desc')->value('brand_id');
$brand = Brand::query()->findOrFail($brandId);
$brand->forceFill(['header_image' => $spdDraw('uploads/brands/spd-brand-banner.jpg', 2400, 700, 11)])->save();

// ── category ───────────────────────────────────────────────────────────────
$catId = (int) DB::table('category_product')->groupBy('category_id')->orderByRaw('count(*) desc')->value('category_id');
$cat = Category::query()->findOrFail($catId);
$cat->forceFill([
    'header_layout' => json_encode(['image' => $spdDraw('uploads/categories/spd-cat-banner.jpg', 2400, 700, 12)]),
    'header_style' => json_encode(['img_phone' => $spdDraw('uploads/categories/spd-cat-phone.jpg', 1080, 900, 13)]),
])->save();

// ── content page ───────────────────────────────────────────────────────────
// A page with its own route (routes/web.php names each content page).
$page = Page::query()->where('slug', 'privacy-policy')->firstOrFail();
$page->forceFill(['header_layout' => json_encode(['image' => $spdDraw('uploads/pages/spd-page-banner.jpg', 2400, 700, 14)])])->save();

// ── reviews with photographs, on the product page measured ────────────────
$product = Product::query()->where('status', 'publish')->orderBy('id')->firstOrFail();
Review::query()->where('product_id', $product->id)->delete();
for ($n = 0; $n < 8; $n++) {
    $photos = [];
    for ($k = 0; $k < ($n % 3) + 1; $k++) {
        $photos[] = $spdDraw('uploads/reviews/spd-r'.$n.'-'.$k.'.jpg', 1080, 1440, 100 + $n * 10 + $k);
    }
    Review::create(['product_id' => $product->id, 'author_name' => 'Shopper '.$n, 'author_email' => 'spd'.$n.'@preview.test',
        'rating' => 5 - ($n % 2), 'title' => 'Lovely texture', 'content' => str_repeat('Soft, light and absorbs fast. ', 3 + $n % 4),
        'status' => 'approved', 'verified' => true, 'helpful' => $n % 4, 'images' => $photos, 'created_at' => now()->subDays(3 + $n * 9)]);
}

// ── the three marketing pixels (fake ids; their hosts are unreachable here) ─
$settings = app(SettingsService::class);
$settings->setModule(Analytics::MODULE, true);
$settings->setModuleSetting(Analytics::MODULE, 'ga4_id', 'G-SPDLANE123');
$settings->setModuleSetting(Analytics::MODULE, 'meta_id', '111122223333555');
$settings->setModuleSetting(Analytics::MODULE, 'tiktok_id', 'CSPDLANE0000');

file_put_contents(storage_path('spd-urls.json'), json_encode([
    'product' => '/product/'.$product->slug.'/',
    'brand' => $brand->url(),
    'category' => $cat->url(),
    'page' => '/privacy-policy',
], JSON_UNESCAPED_SLASHES));
echo "SPD seed done\n";
