<?php
/*
 * Lane HT -- product photographs and believable sales / dates / sale prices on
 * the ARF preview catalogue, so the Product tabs previews show four different
 * product sets with real-looking cards. PREVIEW DATABASE ONLY; run after
 * `sh tools/arf-preview.sh <port> fresh` via:
 *
 *   sh tools/arf-artisan.sh tinker --execute="require 'tools/ht-seed.php';"
 *
 * Touches no other lane's seed. Pictures go to <preview webroot>/uploads/ht.
 */
use App\Models\Product;

$dir = public_path('uploads/ht');
@mkdir($dir, 0755, true);

$pal = [[252,228,236,244,143,177],[227,242,253,100,181,246],[232,245,233,129,199,132],[255,243,224,255,183,77],[243,229,245,186,104,200],[224,247,250,77,208,225],[255,248,225,255,213,79],[251,233,231,255,138,101]];

$bottle = function (string $file, int $k) use ($pal, $dir): string {
    $w = $h = 800;
    $im = imagecreatetruecolor($w, $h);
    [$r1, $g1, $b1, $r2, $g2, $b2] = $pal[$k % 8];
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h * .35;
        imageline($im, 0, $y, $w, $y, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
    }
    $tall = $k % 3 !== 0;
    $bw = (int) ($w * ($tall ? .24 : .36));
    $bh = (int) ($h * ($tall ? .6 : .34));
    $x = (int) (($w - $bw) / 2);
    $y0 = (int) (($h - $bh) / 2) + 30;
    imagefilledrectangle($im, $x + (int) ($bw * .3), $y0 - (int) ($bh * .14), $x + (int) ($bw * .7), $y0, imagecolorallocate($im, 60, 50, 56));
    imagefilledrectangle($im, $x, $y0, $x + $bw, $y0 + $bh, imagecolorallocate($im, $r2, $g2, $b2));
    imagefilledrectangle($im, $x + (int) ($bw * .14), $y0 + (int) ($bh * .36), $x + (int) ($bw * .86), $y0 + (int) ($bh * .64), imagecolorallocate($im, 255, 255, 255));
    imagewebp($im, $dir.'/'.$file, 80);
    imagedestroy($im);

    return '/uploads/ht/'.$file;
};

foreach (Product::query()->orderBy('id')->get() as $i => $p) {
    $p->image = $bottle('p'.$p->id.'.webp', $i);
    $p->total_sales = ($i * 37) % 400 + 5;
    $p->created_at = now()->subDays(($i * 11) % 90);
    if ($i % 4 === 1 && $p->price > 0) {
        $p->sale_price = (int) round($p->price * .8);
    }
    $p->stock_status = 'instock';
    $p->save();
}

\Illuminate\Support\Facades\Cache::flush();
echo Product::count().' products with photographs'.PHP_EOL;
