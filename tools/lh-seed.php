<?php
/*
 * Lane LH (mobile Speed Index) -- the owner's homepage, as close as this
 * container can draw it: Lane SP's live-scale catalogue (tools/spd-seed.php,
 * which already brings Lane PF's three-slide picture slider), with the slides
 * re-cut to what extrabeauty.ae actually serves -- 1410x513 WebP under
 * uploads/banners/, the LCP element of his PageSpeed report -- and every
 * other homepage default left as shipped (WhatsApp float, footer app row).
 */
use App\Models\BannerCard;

require __DIR__.'/spd-seed.php';

$dir = public_path('uploads/banners');
@mkdir($dir, 0775, true);

$draw = static function (int $w, int $h, int $i): \GdImage {
    $img = imagecreatetruecolor($w, $h);
    $pal = [[0xE8, 0x91, 0x9F], [0xC9, 0xA7, 0xD6], [0xA8, 0xD5, 0xCB]][$i % 3];
    for ($y = 0; $y < $h; $y += 3) {
        $t = $y / $h;
        imagefilledrectangle($img, 0, $y, $w, $y + 3, imagecolorallocate($img,
            (int) ($pal[0] * (1 - $t) + 250 * $t), (int) ($pal[1] * (1 - $t) + 238 * $t), (int) ($pal[2] * (1 - $t) + 242 * $t)));
    }
    imagefilledellipse($img, (int) ($w * .72), (int) ($h * .5), (int) ($h * .8), (int) ($h * .8), imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, (int) ($w * .08), (int) ($h * .3), (int) ($w * .45), (int) ($h * .42), imagecolorallocate($img, 60, 40, 50));
    imagefilledrectangle($img, (int) ($w * .08), (int) ($h * .5), (int) ($w * .35), (int) ($h * .56), imagecolorallocate($img, 120, 90, 100));
    mt_srand(500 + $i);
    for ($n = 0; $n < ($w * $h) / 25; $n++) {
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($img, mt_rand(150, 255), mt_rand(150, 255), mt_rand(150, 255)));
    }

    return $img;
};

/* His slides carry their own PHONE picture, 864 x 920 (the <source> in his
 * report), so on a phone the banner is a tall portrait frame -- about half the
 * 412 x 823 viewport -- not a 150px strip. */
foreach (BannerCard::query()->orderBy('position')->get() as $i => $card) {
    $out = [];
    foreach (['' => [1410, 513], '-m' => [864, 920]] as $suffix => [$w, $h]) {
        $img = $draw($w, $h, $i);
        $rel = 'uploads/banners/lh-slide-'.($i + 1).$suffix.'.webp';
        imagewebp($img, public_path($rel), 80);
        imagedestroy($img);
        \App\Support\MediaRegistrar::record($rel);
        \App\Support\ImageVariants::generate('/'.$rel);
        $out[$suffix] = [$rel, $w, $h];
    }
    $card->update(['image' => $out[''][0], 'image_w' => 1410, 'image_h' => 513,
        'image_m' => $out['-m'][0], 'image_m_w' => 864, 'image_m_h' => 920]);
}

\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();
echo "lh seed done\n";
