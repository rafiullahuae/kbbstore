<?php
/*
 * Lane PS — the fixture the 5 October PageSpeed report (extrabeauty.ae, mobile
 * 92 / desktop 92) is reproduced on. Lane PF's homepage (ha-seed: products,
 * brands, posts; a picture slider; six Spotted posts) plus the three things that
 * report blamed and that fixture does not have:
 *
 *   1. PRODUCT PHOTOGRAPHS AT THE LIVE SHOP'S ADDRESS AND WEIGHT, WITH NO COPIES.
 *      Every tile in the report is `/wp-content/uploads/YYYY/MM/<name>.webp`,
 *      1000-1100 px square, 100-520 KB, and carries NO srcset -- the card only
 *      emits one when ImageVariants finds copies on disk, so on the live box
 *      none exist for them. "Improve image delivery" wanted 3,434 KiB back.
 *   2. SPOTTED PICTURES AS THE ADMIN UPLOADS THEM: /uploads/appearance/, 586 x
 *      699, with the 200/400 copies MediaUploadController makes on upload. The
 *      report shows them drawn at 219 x 284 from the original (~100 KB each).
 *   3. A PHONE PICTURE on the first slide, as the live hero has
 *      (`<source media="(max-width: 767.98px)">`, 864 x 920).
 *
 * The pictures are DRAWN with grain so their bytes are in the live class.
 * Written into the PREVIEW's database and web root only.
 */

use App\Models\BannerCard;
use App\Models\Product;

require __DIR__.'/pf-seed.php';

$grain = function (string $abs, int $w, int $h, array $rgb, int $seed, int $q, string $fmt = 'webp'): void {
    @mkdir(dirname($abs), 0775, true);
    $im = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = $rgb;
    for ($y = 0; $y < $h; $y += 2) {
        $t = $y / $h;
        imagefilledrectangle($im, 0, $y, $w, $y + 2, imagecolorallocate($im,
            (int) ($r * (1 - $t) + 248 * $t), (int) ($g * (1 - $t) + 240 * $t), (int) ($b * (1 - $t) + 244 * $t)));
    }
    imagefilledrectangle($im, (int) ($w * .36), (int) ($h * .22), (int) ($w * .64), (int) ($h * .86), imagecolorallocate($im, 255, 255, 255));
    imagefilledellipse($im, (int) ($w * .5), (int) ($h * .2), (int) ($w * .2), (int) ($w * .2), imagecolorallocate($im, 60, 50, 56));
    mt_srand($seed);
    for ($n = 0; $n < ($w * $h) / 12; $n++) {
        imagesetpixel($im, mt_rand(0, $w - 1), mt_rand(0, $h - 1),
            imagecolorallocate($im, mt_rand(120, 255), mt_rand(120, 255), mt_rand(120, 255)));
    }
    $fmt === 'jpg' ? imagejpeg($im, $abs, $q) : imagewebp($im, $abs, $q);
    imagedestroy($im);
};

$pal = [[0xF8, 0xBB, 0xD0], [0xBB, 0xDE, 0xFB], [0xC8, 0xE6, 0xC9], [0xFF, 0xE0, 0xB2], [0xE1, 0xBE, 0xE7], [0xB2, 0xEB, 0xF2]];

/* 1. products: /wp-content/uploads/2026/09/ps-<id>.webp, 1000 px, no copies.
      One in eight is a 1080 px JPEG, as the report's heaviest tile is. */
foreach (Product::query()->orderBy('id')->get() as $i => $p) {
    $jpg = $i % 8 === 3;
    $w = $jpg ? 1080 : 1000;
    $rel = 'wp-content/uploads/2026/09/ps-'.$p->id.($jpg ? '.jpg' : '.webp');
    $grain(public_path($rel), $w, $w, $pal[$i % 6], 5000 + $p->id, $jpg ? 88 : 88, $jpg ? 'jpg' : 'webp');
    $p->image = '/'.$rel;
    $p->images = [];
    $p->save();
}

/* 2. The Spotted GRID (what the live homepage draws: `ul.spt-sgl`), whose six
      pictures are settings (spotted_grid_N_img) uploaded through the admin:
      /uploads/appearance/, 586 x 699, with the copies MediaUploadController
      makes on upload. */
foreach (range(1, 6) as $n) {
    $rel = 'uploads/appearance/20261005-0848'.(20 + $n).'-ps'.$n.'.webp';
    $grain(public_path($rel), 586, $n % 2 ? 699 : 704, $pal[$n % 6], 7000 + $n, 90);
    \App\Support\ImageVariants::generate('/'.$rel);
    app(\App\Services\SettingsService::class)->set(\App\Services\SpottedSettings::PREFIX.'grid_'.$n.'_img', '/'.$rel);
}

/* 3. The first slide's phone picture, 864 x 920, with copies (an upload). */
$first = BannerCard::query()->orderBy('banner_set_id', 'desc')->orderBy('position')->first();
if ($first !== null) {
    $rel = 'uploads/banners/20261005-105831-psPhone1.webp';
    $grain(public_path($rel), 864, 920, [0xB2, 0xEB, 0xF2], 8100, 85);
    \App\Support\ImageVariants::generate('/'.$rel);
    $first->image_m = $rel;
    $first->image_m_w = 864;
    $first->image_m_h = 920;
    $first->save();
}

/* 4. The two-column feature WITHOUT photos, as the live homepage draws it: the
      report's link-name failure is `a.hs-fim` with only a gradient. */
app(\App\Services\SettingsService::class)->set('home_ft_l_img', '');
app(\App\Services\SettingsService::class)->set('home_ft_r_img', '');

\App\Services\SettingsService::forgetMemo();
\Illuminate\Support\Facades\Cache::flush();
echo "ps seed done\n";
