<?php
/*
 * Lane BR4 preview seed. tools/br3-seed.php's catalogue and owner, and three
 * brands shaped like the live shop's:
 *
 *   anua      the OWNER'S PAGE BANNER on (Catalog -> Brands -> Edit -> Banner),
 *             the green banner picture, no header_image -- the shape of the
 *             live Anua that never showed the Panel; a round logo; the long
 *             description the live page carries.
 *   plainbr4  no page banner, the picture as its imported header_image -- BR3's
 *             own test brand; no logo.
 *   tintbr4   a page banner with a heading and a line but NO picture (Tint),
 *             and no description of its own.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

@mkdir(public_path('uploads/brands'), 0755, true);
copy(base_path('docs/brand-header-preview/anua-banner.webp'), public_path('uploads/brands/anua-banner.webp'));

$im = imagecreatetruecolor(400, 400);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledellipse($im, 200, 200, 380, 380, imagecolorallocate($im, 255, 255, 255));
imagefilledellipse($im, 200, 200, 300, 300, imagecolorallocate($im, 31, 122, 53));
imagestring($im, 5, 182, 192, 'anua', imagecolorallocate($im, 255, 255, 255));
imagepng($im, public_path('uploads/brands/anua-logo.png'));
imagedestroy($im);

$desc = 'Anua believes that healthy skin does not depend solely on skin care products but also on having a relaxed mind '
    .'and regulated lifestyle. The brand focuses on choosing pure, organic ingredients to maximize effectiveness and '
    .'minimize irritation, with heartleaf at the heart of its best-loved toners and cleansers.';

$brands = [
    ['Anua', 'anua', '/uploads/brands/anua-logo.png', '#1f7a35', null,
        ['enabled' => true, 'style' => 'full', 'image' => '/uploads/brands/anua-banner.webp', 'heading' => 'Anua', 'subheading' => 'Pure, gentle K-beauty with heartleaf.', 'tone' => 'light', 'overlay' => 35], $desc],
    ['Plainbr4', 'plainbr4', null, null, '/uploads/brands/anua-banner.webp', null, $desc],
    ['Tintbr4', 'tintbr4', null, null, null,
        ['enabled' => true, 'style' => 'tint', 'heading' => 'Tint heading', 'subheading' => 'A line under the tint heading.', 'tint' => '#2f7d4a'], ''],
];

foreach ($brands as $i => [$name, $slug, $logo, $colour, $headerImage, $banner, $description]) {
    $brand = \App\Models\Brand::query()->updateOrCreate(['slug' => $slug], [
        'name' => $name, 'description' => $description, 'logo' => $logo, 'logo_color' => $colour,
        'header_image' => $headerImage, 'header_layout' => null,
        'banner' => \App\Support\PageBanner::sanitize($banner),
    ]);
    \App\Models\Product::query()->where('status', 'publish')->orderBy('id')->skip($i * 4)->limit(4)->get()
        ->each(fn ($p) => $p->forceFill(['brand_id' => $brand->id])->save());
}

// The Arabic shop on, and the two Read more drafts approved -- as if the owner
// had reviewed them under Translation -> Strings -- so the RTL shot shows them.
\App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']);
\App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1']);
\Illuminate\Support\Facades\DB::table('translations')->where('locale', 'ar')->whereIn('field', ['store.brands.read_more', 'store.brands.read_less'])
    ->update(['status' => \App\Models\Translation::STATUS_PUBLISHED]);
\App\Services\Translation\TranslationStore::flush();

\App\Models\Setting::flushMap();
echo "br4 seed done\n";
