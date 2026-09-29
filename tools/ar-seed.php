<?php
/*
 * Seed the Lane AR preview.
 *
 * The question this lane was given is "what does /ar actually render", so the
 * fixture's job is to put every surface the brief names on a page that can be
 * photographed: the home page, a product page, a SET's page (its contents panel
 * and its buy column), the cart, the checkout, the shoppable-video rail with
 * its arrows, and the product tabs.
 *
 * ── THE LANGUAGE STATE COMES FROM THE ENVIRONMENT, NOT FROM HERE ───────────
 *
 * KBB_AR_STATE is one of en | ar | rtl and tools/ar-preview.sh sets it. The
 * middle state is the one that matters: `language_ar_enabled` on and
 * `language_rtl_enabled` OFF is what the shop serves the moment the owner
 * flips the one switch he has been told about, and it is the state
 * Tests\Support\ArabicShop::on() puts the suite in -- so it is the state every
 * lane's "it mirrors correctly" screenshot was actually taken in.
 *
 * NOTHING HERE IS SEEDED BY THIS LANE'S DIFF. Both switches are absent by
 * default on the shop and this lane does not change that.
 */
$state = getenv('KBB_AR_STATE') ?: 'ar';

$sv = app(\App\Services\SettingsService::class);

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand  = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$brand2 = \App\Models\Brand::updateOrCreate(['slug' => 'beauty-of-joseon'], ['name' => 'Beauty of Joseon']);

$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="300">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="240" height="300" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$palette = [
    ['#F7C6D4', '#E0567B'], ['#CDE7D6', '#3E8E62'], ['#FFE6B8', '#E0922F'],
    ['#D9D2F2', '#7B6CF0'], ['#C9E6F2', '#2F84A8'], ['#F2D5C9', '#B8613C'],
    ['#E3F2C9', '#7A9E35'], ['#F2C9E8', '#A83C8C'], ['#C9D2F2', '#3C4FA8'],
    ['#F2E8C9', '#A89A3C'], ['#D2F2C9', '#4CA83C'], ['#F2C9C9', '#A83C3C'],
];

$make = function (string $name, string $slug, int $fils, ?array $colours, string $status, $brandId) use ($pic) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $fils, 'brand_id' => $brandId,
        'image' => $colours === null ? null : $pic($colours[0], $colours[1]),
        'status' => $status, 'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple',
    ]);
};

/* ── THE CATALOGUE ─────────────────────────────────────────────────────────
 * Twelve products, so a grid has enough rows to overflow if it is going to. */
$names = [
    'Heartleaf 77% Soothing Toner 250ml', 'Azelaic Acid 10 Serum 30ml',
    'Rice 70 Glow Milky Toner 250ml', 'Ceramide Daily Moisturiser 100ml',
    'Relief Sun Rice Probiotics SPF50+', 'Green Plum Refreshing Cleanser 150ml',
    'Revive Eye Serum Ginseng Retinal 30ml', 'Glow Deep Serum Rice Alpha Arbutin',
    'Dynasty Cream Rich Repair 50ml', 'Calming Cica Ampoule 40ml',
    'Radiance Cleansing Balm 100ml', 'Matte Sun Stick Mugwort 18g',
];

$ids = [];
foreach ($names as $i => $name) {
    $ids[] = $make($name, 'lanear-'.($i + 1), 4900 + ($i * 610), $palette[$i % 12], 'publish',
        $i % 2 === 0 ? $brand->id : $brand2->id)->id;
}

/* The product page this lane photographs. It needs a short description (the buy
   column prints it), a description, an INCI list and a how-to — the last two
   because they are what the product TABS are drawn from, and the tab strip is
   one of the seven surfaces the brief names. */
$plain = \App\Models\Product::find($ids[0]);
$plain->forceFill([
    'short_description' => 'A daily soothing toner with 77% heartleaf extract, for skin that reacts to everything.',
    'description'  => '<p>A gentle, watery toner that calms redness and preps the skin for the rest of the routine.</p>',
    'ingredients'  => '<p>Houttuynia Cordata Extract, Water, Butylene Glycol, 1,2-Hexanediol, Glycerin, Panthenol.</p>',
    'how_to_use'   => '<p>After cleansing, pour onto a cotton pad or into the palms and press gently over the face.</p>',
])->save();

/* An explicit authored tab as well, so the strip has a tab whose BODY is a
   translatable long field (ProductTab::$translatable, `body` — one of
   TranslationStore::LONG_FIELDS). */
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => $plain->id, 'source_key' => 'lanear-authored'],
    ['title' => 'How we chose it', 'body' => '<p>Picked for the UAE climate: light enough for summer, no fragrance.</p>',
     'position' => 9, 'is_enabled' => true]
);

/* ── THE SET ───────────────────────────────────────────────────────────────
 * A priced set with three members, so the contents panel prints its rows AND
 * the buy column prints "You save". */
$set = \App\Models\Product::updateOrCreate(['slug' => 'lanear-glow-starter-set'], [
    'name' => 'Glow Starter Set', 'type' => 'set', 'brand_id' => null,
    'image' => $pic('#FBD9E4', '#C9587F'),
    'short_description' => 'Three steps, one box.',
    'description' => '<p>The products that go together, in one box.</p>',
    'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
    'set_price_mode' => \App\Support\SetPricing::MODE_FIXED, 'set_discount' => null,
    'price' => 18900,
]);
\App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();
foreach (array_slice($ids, 0, 3) as $i => $memberId) {
    \App\Models\ProductSetItem::create([
        'set_product_id' => $set->id, 'member_product_id' => $memberId,
        'quantity' => $i === 0 ? 2 : 1, 'position' => $i,
    ]);
}
\App\Support\SetPricing::forget((int) $set->id);

/* ── THE SHOPPABLE-VIDEO RAIL ──────────────────────────────────────────────
 * Six clips, which is past the cap at 1280, so the rail's NEXT/PREVIOUS arrows
 * are drawn and can be photographed. The files are written by
 * tools/ar-media.sh; a tile whose file is missing still draws its poster and
 * its arrows, which is all this lane measures. */
$section = \App\Models\UgcSection::updateOrCreate(['handle' => 'shop-the-look'],
    ['title' => 'Shop the look', 'status' => 'publish']);

$clips = [
    ['lanear-v1', 'the 10-step routine, honestly', 'real-a'],
    ['lanear-v2', 'niacinamide, four weeks in',    'real-b'],
    ['lanear-v3', 'glass skin in six steps',       'real-a'],
    ['lanear-v4', 'sunscreen that does not pill',  'real-b'],
    ['lanear-v5', 'cleansing balm, first try',     'real-a'],
    ['lanear-v6', 'what I actually repurchase',    'real-b'],
];

$pos = 0;
foreach ($clips as $i => [$slug, $title, $media]) {
    $v = \App\Models\UgcVideo::updateOrCreate(['slug' => $slug], [
        'title' => $title, 'caption' => $title, 'status' => 'publish',
        'rights_status' => 'granted', 'rights_granted_at' => now(),
        'file_path' => '/uploads/ugc/'.$media.'.webm', 'teaser_path' => null,
        'poster_path' => '/uploads/ugc/'.$media.'.jpg',
        'width' => 270, 'height' => 480, 'duration_ms' => 6000,
        'creator_handle' => '@extrabeauty', 'source_platform' => 'upload',
        'published_at' => now()->subDay(),
    ]);
    $v->products()->sync([$ids[$i]]);
    $section->videos()->syncWithoutDetaching([$v->id => ['position' => $pos++]]);
}

$sv->setModuleSetting('shoppable_video', 'home_section', 'shop-the-look');

/* ── THE LANGUAGE STATE ────────────────────────────────────────────────────
 *
 * PREVIEW FIXTURE ONLY. The shop seeds neither row and this lane does not
 * change that — see App\Support\Locale::enabled()'s "off by default" note.
 */
if ($state !== 'en') {
    $sv->set(\App\Support\Locale::SETTING_ENABLED, true);
}
if ($state === 'rtl') {
    $sv->set(\App\Support\Locale::SETTING_RTL, true);
}

$sv->flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
\App\Services\Translation\TranslationStore::flush();
app(\App\Services\UgcRail::class)->flush();
\App\Support\Shortcodes::flush();

echo 'lane-ar preview seeded: state=', $state,
    ' products=', \App\Models\Product::count(),
    ' clips=', \App\Models\UgcVideo::count(),
    ' set=', $set->id, ' product=', $plain->id, "\n";
echo 'ar_enabled=', var_export(\App\Support\Locale::enabled('ar'), true),
    ' rtl_enabled=', var_export(\App\Support\Locale::rtlEnabled(), true), "\n";
