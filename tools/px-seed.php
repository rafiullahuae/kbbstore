<?php
/*
 * Seed the Lane PX preview: Lane RF's fixture (pdp-seed carries a sold-out
 * serum beside a variable, in-stock ampoule, and a toner with reviews and a
 * brand), the preview owner, and a plain WebP photograph on every product so
 * the "Sold out" pill is shot sitting on a real picture.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/rf-seed.php';

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

@mkdir(public_path('uploads/px'), 0755, true);
foreach (\App\Models\Product::query()->get() as $i => $prod) {
    if ($prod->image) {
        continue;
    }
    $rel = 'uploads/px/p'.$prod->id.'.webp';
    $im = imagecreatetruecolor(800, 800);
    imagefilledrectangle($im, 0, 0, 800, 800, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 300, 140, 500, 700, imagecolorallocate($im, 60 + ($i * 37) % 180, 120, 160));
    imagewebp($im, public_path($rel), 85);
    imagedestroy($im);
    $prod->image = '/'.$rel;
    $prod->save();
}

// A later seed puts the serum back on the shelf; the shots need it sold out.
\App\Models\Product::where('slug', 'pdp-sold-out-serum')->update(['stock_status' => 'outofstock']);

// /ar and its mirror, for the RTL shots (copied from tools/bg-seed.php), and
// this lane's one Arabic string PUBLISHED in the preview only -- the package
// seeds it as a draft for the owner to approve.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, true);
\App\Services\Translation\TranslationStore::put(
    'ar', \App\Models\Translation::GROUP_UI, 0, 'store.product_card.sold_out',
    \App\Services\Translation\ArabicInterfaceDrafts::all()['store.product_card.sold_out'],
    \App\Models\Translation::STATUS_PUBLISHED, \App\Models\Translation::SOURCE_MANUAL,
);

echo "px seed done\n";
