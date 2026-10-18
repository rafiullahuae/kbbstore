<?php

// Lane SBC: a photo-like background for two tabs on the PREVIEW database --
// a soft bokeh scene with a product composited into it (GD), written to the
// preview web root, then set as the Acne and Hydration tabs' photos through
// the same ModuleSchema write the admin's Save uses. `off` removes them.
//   tools/sbc-artisan.sh tinker --execute="\$argv=['on']; require 'tools/sbc-photo.php';"

use App\Services\HomepageContent;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\ShopByConcern;

$mode = $argv[0] ?? 'on';
$root = rtrim((string) env('KBB_PUBLIC_PATH'), '/');
@mkdir($root.'/uploads/sbc', 0777, true);

$make = function (string $file, array $from, array $to, string $shot) use ($root) {
    $w = 1000; $h = 1100;
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $c = imagecolorallocate($im, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
        imageline($im, 0, $y, $w, $y, $c);
    }
    mt_srand(7);
    for ($i = 0; $i < 46; $i++) {
        $r = mt_rand(30, 160);
        $col = imagecolorallocatealpha($im, 255, mt_rand(200, 255), mt_rand(210, 255), mt_rand(70, 110));
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $r, $r, $col);
    }
    for ($i = 0; $i < 6; $i++) {
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
    }
    $p = @imagecreatefrompng($root.'/uploads/demo-shots/'.$shot);
    if ($p) {
        imagecopyresampled($im, $p, 420, 420, 0, 0, 620, 620, imagesx($p), imagesy($p));
    }
    imagejpeg($im, $root.'/uploads/sbc/'.$file, 84);
    // What MediaUploadController does on an upload: the img-cache copies.
    \App\Support\ImageVariants::generate('/uploads/sbc/'.$file);

    return '/uploads/sbc/'.$file;
};

$rows = ShopByConcern::defaults();

if ($mode === 'on') {
    $rows[0]['img'] = $make('acne.jpg', [250, 196, 205], [255, 236, 226], 'advanced-snail-96-mucin-power-essence-1-front.png');
    $rows[1]['img'] = $make('hydration.jpg', [190, 222, 248], [236, 247, 255], '1025-dokdo-toner-1-front.png');
}

ModuleSchema::write(app(SettingsService::class), 'homepage_content', HomepageContent::SCHEMA, [
    'home_sbc_tabs' => $mode === 'on' ? json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
]);
\Illuminate\Support\Facades\Cache::flush();
echo "sbc-photo: {$mode}\n";
