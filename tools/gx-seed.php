<?php
/*
 * Lane GX (gallery stand-in): tools/spd-seed.php at live scale, plus ONE
 * product with a six-shot gallery (main + 5) so a tap can be measured on a
 * shot that has not been warmed. Used as SPD_SEED for tools/spd-preview.sh.
 */
require __DIR__.'/spd-seed.php';

$p = \App\Models\Product::query()->where('slug', 'spd-product-500')->firstOrFail();
$gallery = [];
for ($g = 1; $g <= 5; $g++) {
    $rel = 'wp-content/uploads/2025/gal/gx-'.$p->id.'-'.$g.'.jpg';
    foreach (array_merge([''], array_map(fn ($w) => \App\Support\ImageVariants::DIR.'/'.$w.'/', \App\Support\ImageVariants::WIDTHS)) as $root) {
        $from = public_path($root.'wp-content/uploads/spd-base/o'.(($g * 5) % 24 === 5 ? 6 : ($g * 5) % 24).'.jpg');
        if (! is_file($from)) {
            continue;
        }
        $to = public_path($root.$rel);
        @mkdir(dirname($to), 0775, true);
        @link($from, $to) || @copy($from, $to);
    }
    $gallery[] = '/'.$rel;
}
\Illuminate\Support\Facades\DB::table('products')->where('id', $p->id)->update(['images' => json_encode($gallery)]);
