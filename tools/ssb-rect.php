<?php

/*
 * Lane SSB preview: switch the banner's computer picture to "Rectangle design"
 * with a 1440 x 700 stand-in (a gradient and words, drawn here, never shipped),
 * or back to the shipped "Single image":
 *   SSB_RECT=1 sh tools/ssb-tinker.sh "require 'tools/ssb-rect.php';"
 *   SSB_RECT=0 sh tools/ssb-tinker.sh "require 'tools/ssb-rect.php';"
 */
use App\Services\SettingsService;
use App\Support\SuperSaleBanner;

$settings = app(SettingsService::class);
$c = SuperSaleBanner::config($settings->all());

if (getenv('SSB_RECT') === '1') {
    $web = getenv('KBB_PUBLIC_PATH') ?: public_path();
    @mkdir($web.'/uploads/appearance', 0755, true);
    $im = imagecreatetruecolor(1440, 700);
    for ($x = 0; $x < 1440; $x++) {
        $t = $x / 1439;
        imageline($im, $x, 0, $x, 699, imagecolorallocate($im, (int) (236 - 40 * $t), (int) (120 + 60 * $t), (int) (160 + 50 * $t)));
    }
    imagefilledellipse($im, 1060, 360, 520, 520, imagecolorallocate($im, 255, 236, 242));
    $ink = imagecolorallocate($im, 90, 20, 50);
    imagestring($im, 5, 60, 60, 'RECTANGLE DESIGN - 1440 x 700 (preview stand-in)', $ink);
    imagewebp($im, $web.'/uploads/appearance/ssb-rect-preview.webp', 82);
    imagedestroy($im);
    $c['mode_d'] = 'rect';
    $c['model_d'] = '/uploads/appearance/ssb-rect-preview.webp';
} else {
    $c['mode_d'] = 'single';
    $c['model_d'] = '';
}

$settings->set(SuperSaleBanner::KEY, $c);
echo 'super sale banner: computer picture '.$c['mode_d']."\n";
