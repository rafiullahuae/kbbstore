<?php
/*
 * Lane BR3 preview seed: Lane RF's catalogue, an owner account, and a brand
 * "Anua" with design A's own banner (docs/brand-header-preview/anua-banner.webp),
 * a round logo drawn here with GD, its green as the logo colour, a description,
 * and eight of the catalogue's products -- so the Panel header has everything
 * it draws. A second brand, "Plainbr3", has the same banner and no own layout.
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

$desc = 'Anua believes healthy skin comes from a relaxed mind. Its gentle formulas use clean, '
    .'soothing ingredients like heartleaf to calm and balance sensitive skin.';

foreach ([['Anua', 'anua', '/uploads/brands/anua-logo.png', '#1f7a35'], ['Plainbr3', 'plainbr3', null, null]] as $i => [$name, $slug, $logo, $colour]) {
    $brand = \App\Models\Brand::query()->updateOrCreate(['slug' => $slug], [
        'name' => $name, 'description' => $desc, 'logo' => $logo, 'logo_color' => $colour,
        'header_image' => '/uploads/brands/anua-banner.webp', 'header_layout' => null,
    ]);
    \App\Models\Product::query()->where('status', 'publish')->orderBy('id')->skip($i * 4)->limit(4)->get()
        ->each(fn ($p) => $p->forceFill(['brand_id' => $brand->id])->save());
}

\App\Models\Setting::flushMap();
echo "br3 seed done\n";
