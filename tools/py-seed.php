<?php
/*
 * Seed for the Lane PY preview: the category title header on every category,
 * with its options. Run by tools/py-preview.sh against THIS tree (AFTER) and
 * against the base tree (BEFORE); the base has no `header_style` column, so
 * the option-sheet categories are only made when the column exists.
 *
 * WHAT IT MAKES
 *
 *   /collections/skincare/sunscreens/   a busy, dark header picture, an
 *                                       imported old-shop title ("Korean
 *                                       Sunscreens") and a long description
 *   /collections/skincare/toners/       a light header picture
 *   /collections/lip-care/              NO picture -> the light box
 *   /collections/face-masks/            no picture and no description
 *
 *   /collections/py-box-a/ .. py-box-f/ every light-box style, A to F
 *   /collections/py-t1-dark/ ..         every text treatment, 1 to 5, over
 *     py-t5-box/                        the dark picture, the light picture
 *                                       and the light box
 *
 * The option-sheet categories use the category's OWN override
 * (`header_style`), so every one is a real storefront render of a real
 * setting, not a mock.
 *
 * Arabic and right-to-left are switched ON here (preview fixture only; the
 * shop ships both off), with Arabic names and descriptions for two categories.
 */

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$after = Schema::hasColumn('categories', 'header_style');
$hasHeader = Schema::hasColumn('categories', 'header_image');
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();

$hex = static fn (string $x): array => [hexdec(substr($x, 0, 2)), hexdec(substr($x, 2, 2)), hexdec(substr($x, 4, 2))];

/* A BUSY, DARK picture: what a real product banner looks like behind words --
   saturated, high-contrast, with bottles and highlights right where the title
   sits. The point is to make the treatments earn their keep. */
$busy = function (string $path) use ($hex): void {
    $w = 1600; $h = 500;
    $im = imagecreatetruecolor($w, $h);
    [$r1, $g1, $b1] = $hex('5B1E2D'); [$r2, $g2, $b2] = $hex('E07A4F'); [$r3, $g3, $b3] = $hex('1F4E63');
    for ($x = 0; $x < $w; $x++) {
        $t = $x / ($w - 1);
        [$a, $b, $c] = $t < .5 ? [$r1 + ($r2 - $r1) * $t * 2, $g1 + ($g2 - $g1) * $t * 2, $b1 + ($b2 - $b1) * $t * 2]
            : [$r2 + ($r3 - $r2) * ($t - .5) * 2, $g2 + ($g3 - $g2) * ($t - .5) * 2, $b2 + ($b3 - $b2) * ($t - .5) * 2];
        imageline($im, $x, 0, $x, $h, imagecolorallocate($im, (int) $a, (int) $b, (int) $c));
    }
    $soft = imagecolorallocatealpha($im, 255, 255, 255, 80);
    foreach ([[160, 120, 240], [520, 400, 330], [980, 90, 280], [1360, 330, 380], [760, 260, 120]] as [$cx, $cy, $d]) {
        imagefilledellipse($im, $cx, $cy, $d, $d, $soft);
    }
    $bottle = imagecolorallocatealpha($im, 24, 12, 18, 40);
    $cap = imagecolorallocatealpha($im, 255, 255, 255, 30);
    foreach ([[90, 140, 80, 300], [210, 190, 100, 250], [360, 110, 70, 330], [1080, 120, 90, 320], [1220, 200, 120, 240], [1400, 150, 80, 300]] as [$x, $y, $bw, $bh]) {
        imagefilledrectangle($im, $x, $y, $x + $bw, $y + $bh, $bottle);
        imagefilledrectangle($im, $x + (int) ($bw * .25), $y - 36, $x + (int) ($bw * .75), $y, $cap);
    }
    $line = imagecolorallocatealpha($im, 255, 255, 255, 60);
    for ($i = 0; $i < 40; $i++) {
        imageline($im, 40 * $i, 0, 40 * $i + 300, $h, $line);
    }
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 86);
};

/* A LIGHT picture: pale, airy, the kind that swallows white words. */
$light = function (string $path) use ($hex): void {
    $w = 1600; $h = 500;
    $im = imagecreatetruecolor($w, $h);
    [$r1, $g1, $b1] = $hex('FBE9E7'); [$r2, $g2, $b2] = $hex('E3F1EF');
    for ($x = 0; $x < $w; $x++) {
        $t = $x / ($w - 1);
        imageline($im, $x, 0, $x, $h, imagecolorallocate($im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t)));
    }
    $white = imagecolorallocatealpha($im, 255, 255, 255, 30);
    foreach ([[240, 110, 260], [900, 420, 360], [1420, 120, 300]] as [$cx, $cy, $d]) {
        imagefilledellipse($im, $cx, $cy, $d, $d, $white);
    }
    $jar = imagecolorallocate($im, 232, 196, 192);
    $jar2 = imagecolorallocate($im, 214, 226, 220);
    foreach ([[1040, 130, 80, 250, $jar], [1160, 190, 110, 190, $jar2], [1310, 240, 150, 140, $jar], [180, 230, 90, 200, $jar2]] as [$x, $y, $bw, $bh, $c]) {
        imagefilledrectangle($im, $x, $y, $x + $bw, $y + $bh, $c);
    }
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 88);
};

$tile = function (string $path, string $bg, string $fg, string $label) use ($hex): void {
    $im = imagecreatetruecolor(600, 600);
    imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, ...$hex($bg)));
    imagefilledellipse($im, 300, 300, 360, 360, imagecolorallocate($im, ...$hex($fg)));
    $ink = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 5, (int) ((600 - imagefontwidth(5) * strlen($label)) / 2), 292, $label, $ink);
    @mkdir(dirname($path), 0775, true);
    imagejpeg($im, $path, 88);
};

$busy($root.'/uploads/py/sunscreens-banner.jpg');
$light($root.'/uploads/py/toners-banner.jpg');

$long = '<p>Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun. '
    .'Find water-light essences, tone-up creams and mineral formulas from Beauty of Joseon, Round Lab and Skin1004 '
    .'-- no white cast, no greasy finish, and gentle enough for sensitive skin. Reapply every two hours outdoors, '
    .'and finish your routine with one every morning.</p>';
$short = 'Lightweight Korean sunscreens with high UV protection, made for everyday wear under the UAE sun.';

$cat = function (string $slug, string $name, ?Category $parent, array $extra = []) use ($hasHeader): Category {
    if (! $hasHeader) {
        $extra = array_diff_key($extra, array_flip(['header_image', 'header_title', 'header_source', 'header_subtitle']));
    }
    $c = Category::updateOrCreate(['slug' => $slug], array_merge(['name' => $name, 'parent_id' => $parent?->id], $extra));
    $c->forceFill(['path' => $parent ? $parent->slug.'/'.$slug : $slug, 'depth' => $parent ? 1 : 0])->save();

    return $c;
};

$skin = $cat('skincare', 'Skincare', null);
$sun = $cat('sunscreens', 'Sunscreens', $skin, [
    'description' => $long,
    'header_image' => '/uploads/py/sunscreens-banner.jpg',
    'header_title' => 'Korean Sunscreens',
    'header_source' => 'cover_section (rey-global-sections 18180)',
]);
$ton = $cat('toners', 'Toners', $skin, [
    'description' => 'Hydrating, soothing and exfoliating toners for the second step of every routine.',
    'header_image' => '/uploads/py/toners-banner.jpg',
]);
$lip = $cat('lip-care', 'Lip Care', null, [
    'description' => 'Lip sleeping masks, tinted balms and oils that keep lips soft through the dry UAE air.',
]);
$mask = $cat('face-masks', 'Face Masks', null);

$sun->saveTranslations(['ar' => ['name' => 'واقيات الشمس', 'description' => '<p>واقيات شمس كورية خفيفة بحماية عالية من الأشعة فوق البنفسجية، مصممة للاستخدام اليومي تحت شمس الإمارات. بلا أثر أبيض ولا ملمس دهني، ولطيفة على البشرة الحساسة. أعيدي وضعها كل ساعتين في الخارج، واختمي بها روتينك كل صباح.</p>']]);
$lip->saveTranslations(['ar' => ['name' => 'العناية بالشفاه', 'description' => 'أقنعة نوم للشفاه وبلسم ملون وزيوت تحافظ على نعومة الشفاه في هواء الإمارات الجاف.']]);

$palette = [['FDEBD0', 'F5B041', 'BEAUTY OF JOSEON'], ['E8F6F3', '48C9B0', 'ROUND LAB'], ['FDEDEC', 'EC7063', 'SKIN1004'], ['EBF5FB', '5DADE2', 'ISNTREE'], ['F4ECF7', 'AF7AC5', 'COSRX']];
foreach ([$sun, $ton, $lip, $mask] as $ci => $c) {
    foreach ($palette as $i => [$bg, $fg, $label]) {
        $tile($root.'/uploads/products/py-'.$ci.'-'.$i.'.jpg', $bg, $fg, $label);
        $p = Product::updateOrCreate(['slug' => 'py-'.$c->slug.'-'.$i], [
            'name' => ucwords(strtolower($label)).' '.$c->name.' No. '.($i + 1), 'wc_id' => 52000 + $ci * 10 + $i,
            'price' => 6500 + $i * 500, 'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
            'type' => 'simple', 'image' => '/uploads/products/py-'.$ci.'-'.$i.'.jpg', 'category_id' => $c->id,
        ]);
        $p->categories()->sync([$c->id]);
    }
}

if ($after) {
    /* The option sheet: one category per option, each a real override. */
    foreach (['a' => 'blush', 'b' => 'cream', 'c' => 'mint', 'd' => 'lilac', 'e' => 'plain', 'f' => 'custom'] as $letter => $box) {
        $cat('py-box-'.$letter, 'Sunscreens', null, ['description' => $short, 'header_style' => ['box' => $box]]);
    }
    foreach (['1' => 'shadow', '2' => 'fade', '3' => 'frost', '4' => 'label', '5' => 'none'] as $n => $t) {
        $cat('py-t'.$n.'-dark', 'Sunscreens', null, ['description' => $short, 'header_image' => '/uploads/py/sunscreens-banner.jpg', 'header_style' => ['treatment' => $t]]);
        $cat('py-t'.$n.'-light', 'Sunscreens', null, ['description' => $short, 'header_image' => '/uploads/py/toners-banner.jpg', 'header_style' => ['treatment' => $t]]);
        $cat('py-t'.$n.'-box', 'Sunscreens', null, ['description' => $short, 'header_style' => ['treatment' => $t]]);
    }
    /* Alignment, on the default treatment, for the alignment row. */
    foreach (['start', 'center', 'end'] as $a) {
        $cat('py-align-'.$a.'-dark', 'Sunscreens', null, ['description' => $short, 'header_image' => '/uploads/py/sunscreens-banner.jpg', 'header_style' => ['align' => $a]]);
        $cat('py-align-'.$a.'-box', 'Sunscreens', null, ['description' => $short, 'header_style' => ['align' => $a]]);
    }
}

$sv = app(\App\Services\SettingsService::class);
$sv->set(\App\Support\Locale::SETTING_ENABLED, true);
$sv->set(\App\Support\Locale::SETTING_RTL, true);
$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();
\Illuminate\Support\Facades\Cache::flush();

echo 'py seed: '.($after ? 'AFTER' : 'BEFORE').', categories '.Category::count().", /collections/skincare/sunscreens/, /collections/lip-care/\n";
