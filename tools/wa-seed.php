<?php
require __DIR__.'/rf-seed.php';
echo "wa seed done\n";
\App\Models\AdminUser::updateOrCreate(['email'=>'owner@preview.test'],['name'=>'Preview Owner','password'=>'preview-secret-1','role'=>'owner']);
// Real WebP photographs for every product, so the button has work to do.
foreach (\App\Models\Product::query()->get() as $i => $prod) {
    $rel = 'uploads/wa/p'.$prod->id.'.webp';
    @mkdir(public_path('uploads/wa'), 0755, true);
    $im = imagecreatetruecolor(800, 800);
    imagefilledrectangle($im, 0, 0, 800, 800, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 300, 140, 500, 700, imagecolorallocate($im, 60 + ($i * 37) % 180, 120, 160));
    imagewebp($im, public_path($rel), 85);
    imagedestroy($im);
    $prod->image = '/'.$rel; $prod->save();
}
echo "wa admin + webp done\n";
