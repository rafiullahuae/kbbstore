<?php
/*
 * (Lane PHA) Seed the preview for the Arabic header pictures: Lane PH's seed,
 * the Arabic language switched on, and four PLACEHOLDER pictures (English and
 * Arabic, desktop and phone) set on /super-sale/'s header. Preview database
 * and the preview's own webroot under storage/ only; nothing reaches a package.
 */
require __DIR__.'/ph-seed.php';

use App\Models\Media;
use App\Models\Setting;
use App\Services\PageHeaders;
use App\Services\SettingsService;
use App\Support\Locale;

@mkdir(public_path('uploads/pha'), 0755, true);
$pics = [];
foreach (['img' => ['EN', 1600, 420, [255, 214, 228]], 'img_m' => ['EN', 900, 600, [255, 214, 228]], 'img_ar' => ['AR', 1600, 420, [214, 236, 255]], 'img_m_ar' => ['AR', 900, 600, [214, 236, 255]]] as $k => [$lang, $w, $h, $rgb]) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    $ink = imagecolorallocate($im, 90, 20, 60);
    $phone = str_starts_with($k, 'img_m');
    $label = 'PLACEHOLDER '.$lang.' '.($phone ? 'PHONE' : 'DESKTOP').' '.$w.'x'.$h;
    $s = $phone ? 5 : 8;
    $big = imagecreatetruecolor(imagefontwidth(5) * strlen($label), imagefontheight(5));
    imagefill($big, 0, 0, imagecolorallocate($big, ...$rgb));
    imagestring($big, 5, 0, 0, $label, imagecolorallocate($big, 90, 20, 60));
    $bw = imagesx($big) * $s / 2; $bh = imagesy($big) * $s / 2;
    imagecopyresized($im, $big, (int) (($w - $bw) / 2), (int) (($h - $bh) / 2), 0, 0, (int) $bw, (int) $bh, imagesx($big), imagesy($big));
    imagerectangle($im, 6, 6, $w - 7, $h - 7, $ink);
    $rel = 'uploads/pha/sale-'.strtolower($lang).'-'.($phone ? 'phone' : 'desktop').'.png';
    imagepng($im, public_path($rel));
    Media::query()->updateOrCreate(['path' => $rel], ['filename' => basename($rel), 'mime' => 'image/png', 'width' => $w, 'height' => $h, 'alt' => '']);
    $pics[$k] = '/'.$rel;
}

Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
$mode = getenv('PHA_PICS') ?: 'all';   // all | en  (en = the Arabic pictures not set: "before")
$all = PageHeaders::defaults();
$bag = $all['pages']['collection:super-sale'];
foreach ($pics as $k => $v) {
    if ($mode === 'all' || ! str_ends_with($k, '_ar')) {
        $bag[$k] = $v;
    }
}
$all['pages']['collection:super-sale'] = $bag;
app(SettingsService::class)->set(PageHeaders::KEY, $all);
Setting::flushMap();
echo "pha seed done ($mode)\n";
