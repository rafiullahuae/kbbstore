<?php
/*
 * Seed the Lane WP preview (WebP images): Lane RF's catalogue, the preview
 * owner, and REAL JPEG/PNG files under uploads/ referenced the way the live
 * shop references them — product image and gallery, a brand logo with
 * transparency, a page banner setting and a picture inside a description — so
 * the dry run and the bulk run have honest work to measure.
 *
 * Written into the PREVIEW's database and web root only.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$photo = static function (int $w, int $h, int $seed): string {
    mt_srand($seed);
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 246, 241, 236));
    for ($i = 0; $i < 900; $i++) {
        $c = imagecolorallocate($im, mt_rand(120, 255), mt_rand(90, 200), mt_rand(110, 210));
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(8, 90), mt_rand(8, 90), $c);
    }
    imagefilledrectangle($im, (int) ($w * .38), (int) ($h * .18), (int) ($w * .62), (int) ($h * .88), imagecolorallocate($im, 214, 88, 120));
    ob_start();
    imagejpeg($im, null, 92);
    return (string) ob_get_clean();
};

@mkdir(public_path('uploads/products'), 0755, true);
@mkdir(public_path('uploads/logos'), 0755, true);
$base = rtrim((string) config('app.url'), '/');

foreach (\App\Models\Product::query()->orderBy('id')->limit(8)->get() as $i => $p) {
    $rel = 'uploads/products/wp-photo-'.$p->id.'.jpg';
    file_put_contents(public_path($rel), $photo(1600, 1600, $p->id));
    \App\Support\MediaRegistrar::record($rel, 'Product '.$p->id.'.jpg', 'image/jpeg');
    $p->image = $base.'/'.$rel;
    $p->images = [$base.'/'.$rel];
    if ($i === 0) {
        $p->description = '<p><img src="/'.$rel.'" width="800" height="800" alt=""></p>'.$p->description;
    }
    $p->save();
}

// A cut-out logo: transparent PNG, noisy enough that WebP wins.
$logo = imagecreatetruecolor(600, 300);
imagealphablending($logo, false);
imagesavealpha($logo, true);
imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 0, 0, 0, 127));
mt_srand(3);
for ($i = 0; $i < 400; $i++) {
    imagefilledellipse($logo, mt_rand(150, 450), mt_rand(60, 240), mt_rand(10, 60), mt_rand(10, 60), imagecolorallocatealpha($logo, mt_rand(0, 255), mt_rand(0, 120), mt_rand(60, 200), 0));
}
imagepng($logo, public_path('uploads/logos/wp-brand-logo.png'));
\App\Support\MediaRegistrar::record('uploads/logos/wp-brand-logo.png', 'brand-logo.png', 'image/png');
if ($b = \App\Models\Brand::query()->orderBy('id')->first()) {
    $b->logo = $base.'/uploads/logos/wp-brand-logo.png';
    $b->save();
}
app(\App\Services\SettingsService::class)->set('og_default_image', $base.'/uploads/products/wp-photo-'.\App\Models\Product::query()->orderBy('id')->value('id').'.jpg');

echo "wp seed done\n";
