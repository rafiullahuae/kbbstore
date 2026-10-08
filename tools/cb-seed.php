<?php
/*
 * Lane CB preview seed: tools/br4-seed.php's brands (anua with the owner's
 * page banner, plainbr4 with an imported header picture), plus two
 * categories shaped like the live shop's:
 *
 *   cbbanner   a header picture (the imported banner), a description, an
 *              Arabic name and description
 *   cbplain    no picture at all -- must look exactly as it does today
 *
 * The breadcrumb is switched on for phones and desktop (Appearance -> Header
 * -> Breadcrumbs), because the owner has it on.
 *
 * Written into the PREVIEW's database only; nothing here reaches a package.
 */
require __DIR__.'/br4-seed.php';

$desc = 'Cleansers for every skin type: low-pH gels, oil cleansers and balms that lift sunscreen and make-up '
    .'without stripping the barrier. The double-cleanse essentials Korean routines start with, morning and night.';

$cats = [
    ['Cleansers CB', 'cbbanner', '/uploads/brands/anua-banner.webp', $desc, 'منظفات', 'منظفات لكل أنواع البشرة، جل لطيف وزيوت تنظيف تزيل واقي الشمس والمكياج دون تجريد حاجز البشرة.'],
    ['Plain CB', 'cbplain', null, $desc, 'عادي', 'وصف عربي قصير للفئة.'],
];

foreach ($cats as $i => [$name, $slug, $image, $description, $arName, $arDesc]) {
    $cat = \App\Models\Category::query()->updateOrCreate(['slug' => $slug], [
        'name' => $name, 'description' => $description, 'header_image' => $image, 'path' => $slug, 'depth' => 0,
    ]);
    $ids = \App\Models\Product::query()->where('status', 'publish')->orderBy('id')->skip($i * 4)->limit(6)->pluck('id');
    $cat->products()->syncWithoutDetaching($ids->all());

    foreach (['name' => $arName, 'description' => $arDesc] as $field => $value) {
        \Illuminate\Support\Facades\DB::table('translations')->updateOrInsert(
            ['group' => 'categories', 'item_id' => $cat->id, 'locale' => 'ar', 'field' => $field],
            ['value' => $value, 'status' => \App\Models\Translation::STATUS_PUBLISHED, 'created_at' => now(), 'updated_at' => now()],
        );
    }
}

/*
 * The coordinator's three cases, plus the two "never a broken banner" ones:
 *   cbbanner  (b) the imported header picture only -> the banner, by itself
 *   cbown     (c) a Banner picture of its own over an imported one -> its own
 *   cbgone        an imported header picture never copied to this server
 *   cbfar         an imported header picture still on the old domain
 *   cbplain   (a) no picture -> exactly as today
 */
@mkdir(public_path('uploads/categories'), 0755, true);
$im = imagecreatetruecolor(2400, 600);
for ($x = 0; $x < 2400; $x += 8) {
    imagefilledrectangle($im, $x, 0, $x + 7, 600, (int) imagecolorallocate($im, 200 + (int) (55 * $x / 2400), 120 + (int) (80 * $x / 2400), 150));
}
imagejpeg($im, public_path('uploads/categories/cb-own.jpg'), 82);
imagedestroy($im);

foreach ([
    ['Own banner CB', 'cbown', '/uploads/brands/anua-banner.webp', ['image' => '/uploads/categories/cb-own.jpg']],
    ['Not copied CB', 'cbgone', '/uploads/categories/never-copied.jpg', null],
    ['Old domain CB', 'cbfar', 'https://kbeautybliss.com/wp-content/uploads/2024/01/cleansers-banner.jpg', null],
] as $i => [$name, $slug, $image, $layout]) {
    $cat = \App\Models\Category::query()->updateOrCreate(['slug' => $slug], [
        'name' => $name, 'description' => $desc, 'header_image' => $image, 'header_layout' => $layout, 'path' => $slug, 'depth' => 0,
    ]);
    $cat->products()->syncWithoutDetaching(\App\Models\Product::query()->where('status', 'publish')->orderBy('id')->limit(6)->pluck('id')->all());
}

foreach (['/uploads/brands/anua-banner.webp', '/uploads/categories/cb-own.jpg'] as $pic) {
    \App\Support\ImageVariants::generate($pic);
}

app(\App\Services\HeaderSettings::class)->save(['bc_mobile' => true, 'bc_desktop' => true]);

\App\Models\Setting::flushMap();
\App\Services\Translation\TranslationStore::flush();
echo "cb seed done\n";
