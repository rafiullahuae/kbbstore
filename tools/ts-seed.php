<?php
/*
 * Seed the Lane TS preview: Lane SP3's seed -- the Super Sale category and its
 * sixteen products (which also fill the homepage rails, a category and a
 * product page), plus a PLACEHOLDER header picture on /super-sale/ in the
 * shape of the owner's own phone screenshot, so the strip can be shown under
 * that page's header. Preview database only; nothing here reaches a package.
 */
require __DIR__.'/sp3-seed.php';
echo "ts seed done\n";

// ONE picture banner on the homepage (Lane RC's fixture shape, one slide), so
// the homepage strip is shown under a real banner as on the live shop rather
// than under the hero fallback. PLACEHOLDER art, generated here, never shipped.
@mkdir(public_path('uploads/banners'), 0775, true);
foreach (['d' => [1920, 550], 'm' => [500, 600]] as $dev => [$w, $h]) {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 0xF4, 0xB6, 0xC6));
    imagefilledellipse($im, (int) ($w * .3), (int) ($h * .4), (int) ($w * .5), (int) ($h * 1.3), imagecolorallocatealpha($im, 255, 255, 255, 96));
    imagestring($im, 5, (int) ($w * .1), (int) ($h / 2 - 8), 'PLACEHOLDER HOMEPAGE BANNER '.$w.'x'.$h, imagecolorallocate($im, 60, 30, 40));
    imagepng($im, public_path('uploads/banners/ts-'.$dev.'.png'));
    imagedestroy($im);
    \App\Support\MediaRegistrar::record('uploads/banners/ts-'.$dev.'.png');
}
$tsSet = \App\Models\BannerSet::create(['name' => 'Homepage banner', 'slug' => 'homepage-banner', 'status' => 'publish', 'position' => 0, 'kind' => 'single']);
\App\Models\BannerCard::create([
    'banner_set_id' => $tsSet->id, 'image' => 'uploads/banners/ts-d.png', 'alt' => 'Placeholder banner',
    'heading' => '', 'body' => '', 'button_label' => '', 'button_url' => '/shop/',
    'image_w' => 1920, 'image_h' => 550, 'image_m' => 'uploads/banners/ts-m.png', 'image_m_w' => 500, 'image_m_h' => 600,
    'position' => 1, 'status' => 'publish',
]);
$tsSettings = app(\App\Services\SettingsService::class);
$tsSettings->set('site_url', getenv('APP_URL') ?: 'http://127.0.0.1:10260');
$tsSettings->setModule('cards_banner', true);
$tsSettings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $tsSet->id);
echo "ts banner set {$tsSet->id}\n";
