<?php
/*
 * Seed the Lane PF preview (row 55 follow-up: section typography, speed, the
 * Signature preset). Lane HA's homepage fixture plus the two things a real
 * homepage has that it does not:
 *
 *   1. A PICTURE BANNER (slider kind, the owner's "simple only images banner"),
 *      three photographs at the size a phone camera or a designer exports —
 *      1920x800 JPEGs with grain, so the bytes are in the class of the live
 *      shop's and the LCP element is the banner, as it is on extrabeauty.ae.
 *   2. SIX #KBeautyBliss SPOTTED POSTS ticked "Homepage", so section 4 draws.
 *      Only when the table exists: the BEFORE tree (2.60.370) has no Spotted.
 *
 * Runs against whichever application tinker boots, so the same fixture seeds
 * the before tree and the after tree and the Lighthouse numbers compare the
 * same catalogue. Written into the PREVIEW's database only.
 */
use App\Models\BannerCard;
use App\Models\BannerSet;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/ha-seed.php';

$dir = public_path('uploads/pf');
@mkdir($dir, 0775, true);

$photo = function (string $file, int $w, int $h, array $rgb, int $seed) use ($dir): string {
    $img = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = $rgb;
    for ($y = 0; $y < $h; $y += 4) {
        $t = $y / $h;
        imagefilledrectangle($img, 0, $y, $w, $y + 4, imagecolorallocate($img,
            (int) ($r * (1 - $t) + 250 * $t), (int) ($g * (1 - $t) + 238 * $t), (int) ($b * (1 - $t) + 242 * $t)));
    }
    imagefilledellipse($img, (int) ($w * .7), (int) ($h * .5), (int) ($h * .8), (int) ($h * .8), imagecolorallocatealpha($img, 255, 255, 255, 80));
    mt_srand($seed);
    for ($n = 0; $n < ($w * $h) / 30; $n++) {
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($img, mt_rand(150, 255), mt_rand(150, 255), mt_rand(150, 255)));
    }
    imagejpeg($img, $dir.'/'.$file, 82);
    imagedestroy($img);

    return 'uploads/pf/'.$file;
};

$set = BannerSet::create(['name' => 'Homepage', 'slug' => 'pf-home', 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);

foreach ([[0xE8, 0x91, 0x9F], [0xC9, 0xA7, 0xD6], [0xA8, 0xD5, 0xCB]] as $i => $rgb) {
    $path = $photo('banner-'.($i + 1).'.jpg', 1920, 800, $rgb, 700 + $i);
    \App\Support\MediaRegistrar::record($path);
    \App\Support\ImageVariants::generate('/'.$path);
    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => $path, 'image_w' => 1920, 'image_h' => 800,
        'alt' => 'K-beauty offer '.($i + 1), 'heading' => '', 'body' => '', 'button_label' => '',
        'button_url' => '/shop/', 'position' => $i,
    ]);
}

$s = app(\App\Services\SettingsService::class);
$s->setModule(\App\Services\Banners::MODULE, true);
$s->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

if (Schema::hasTable('spotted_posts')) {
    foreach (range(1, 6) as $i) {
        $img = $photo('spotted-'.$i.'.jpg', 800, 1000, [0xF4 - $i * 9, 0xC4, 0xB8 + $i * 6], 900 + $i);
        \App\Models\SpottedPost::create([
            'image' => '/'.$img, 'image_alt' => 'A customer with her K-beauty haul '.$i,
            'ig_url' => 'https://www.instagram.com/p/pf'.$i.'/', 'handle' => 'glowwith'.$i,
            'caption' => 'My morning routine, day '.$i, 'link_to' => 'instagram', 'likes' => 120 * $i,
            'sort' => $i, 'on_home' => true, 'on_page' => true,
        ]);
    }
}

\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();
echo "pf seed done: banner set {$set->id}\n";
