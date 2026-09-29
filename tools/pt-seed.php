<?php
/*
 * Seed the Lane PT preview.
 *
 * An owner, three products, and the exact arrangement the evidence asks for:
 *
 *   TWO GLOBAL TABS, one of them switched off, so the admin screen can be
 *   photographed showing both states at once.
 *
 *   A PRODUCT WITH EVERYTHING — the three built-in tabs, an inherited global
 *   tab and a tab of its own — with the Arabic for every authored string, so
 *   /ar is a real page rather than an English one behind a prefix.
 *
 *   A PRODUCT THAT HIDES A GLOBAL TAB, which is the owner's own case: a device
 *   with no patch-test advice.
 *
 *   A PRODUCT NOBODY HAS TOUCHED, which is the whole proof that applying this
 *   package moves nothing.
 *
 * MONEY IS INTEGER FILS here too, the way every other fixture in this
 * repository writes it.
 */
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

$brand = \App\Models\Brand::updateOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
$other = \App\Models\Brand::updateOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);

/* A REAL BRANCH, because the category rule inherits down it. (round 2)
   Skincare -> Toners, and the toner sits in the CHILD while the tab is written
   against the PARENT -- which is the case the whole inheritance decision is
   about and the one a screenshot has to show. */
$category = \App\Models\Category::updateOrCreate(['slug' => 'skincare'],
    ['name' => 'Skincare', 'depth' => 0, 'path' => 'skincare']);
$toners = \App\Models\Category::updateOrCreate(['slug' => 'toners'],
    ['name' => 'Toners', 'parent_id' => $category->id, 'depth' => 1, 'path' => 'skincare/toners']);
$devices = \App\Models\Category::updateOrCreate(['slug' => 'devices'],
    ['name' => 'Devices', 'depth' => 0, 'path' => 'devices']);

$pic = function (string $a, string $b): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240">'
        .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$b.'"/>'
        .'</linearGradient></defs><rect width="240" height="240" fill="url(#g)"/></svg>';

    return 'data:image/svg+xml;base64,'.base64_encode($svg);
};

$make = function (array $row) use ($brand, $category) {
    $in = $row['category'] ?? $category;
    $of = $row['brand'] ?? $brand;

    $p = \App\Models\Product::updateOrCreate(['slug' => $row['slug']], [
        'name' => $row['name'],
        'type' => $row['type'] ?? 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $row['price'],
        'stock_status' => 'instock',
        'brand_id' => $of->id,
        'category_id' => $in->id,
        'image' => $row['image'],
        'short_description' => $row['short'] ?? null,
        'description' => $row['description'] ?? null,
        'ingredients' => $row['ingredients'] ?? null,
        'how_to_use' => $row['how_to_use'] ?? null,
    ]);

    $p->categories()->syncWithoutDetaching([$in->id]);

    return $p;
};

$full = $make([
    'slug' => 'lanept-heartleaf-toner',
    'name' => 'Heartleaf 77% Soothing Toner 250ml',
    'price' => 9000,
    'category' => $toners,
    'image' => $pic('#F7C6D4', '#E0567B'),
    'short' => '<p>A gentle daily toner that calms redness.</p>',
    'description' => '<p>A daily toner built around 77% houttuynia cordata extract. '
        .'It calms visible redness, softens the look of texture and leaves skin ready for whatever '
        .'follows it.</p><p>Fragrance-free, alcohol-free and suitable for sensitive skin.</p>',
    'ingredients' => '<p>Houttuynia Cordata Extract, Water, Butylene Glycol, Glycerin, '
        .'1,2-Hexanediol, Panthenol, Sodium Hyaluronate, Allantoin.</p>',
    'how_to_use' => '<p>After cleansing, sweep over the face with a cotton pad or press in with '
        .'the palms. Morning and evening.</p>',
]);

$device = $make([
    'slug' => 'lanept-led-mask',
    'name' => 'LED Recovery Mask',
    'price' => 149000,
    'category' => $devices,
    'brand' => $other,
    'image' => $pic('#CFE0F7', '#3E62A8'),
    'short' => '<p>Seven wavelengths, twenty minutes a session.</p>',
    'description' => '<p>A rechargeable LED mask with seven wavelengths and a twenty minute '
        .'programme. Use it three times a week over clean, dry skin.</p>',
]);

// NOBODY HAS TOUCHED THIS ONE. It is here so the evidence can show a product
// page that is byte-for-byte what it was before this package.
$make([
    'slug' => 'lanept-ceramide-moisturiser',
    'name' => 'Ceramide Barrier Moisturiser 50ml',
    'price' => 11500,
    'category' => $devices,
    'brand' => $other,
    'image' => $pic('#E7DCC9', '#9A7B45'),
    'short' => '<p>A plain, unscented barrier cream.</p>',
    'description' => '<p>A plain, unscented barrier cream with ceramides NP, AP and EOP. '
        .'Nothing on this product has been customised.</p>',
]);

/* ─────────────────────────────────────────────── the two global tabs ────── */

$shipping = \App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'Shipping & returns'],
    [
        'body' => '<p>Delivered across the UAE in one to three working days. '
            .'Free over AED 199.</p><p>Returns accepted within fourteen days, unopened and in '
            .'original packaging. Message us on WhatsApp and we will arrange collection.</p>',
        'position' => 100,
        'is_enabled' => true,
    ]
);

// SWITCHED OFF, not deleted. The screen has to show that state, and the shop
// has to not show the tab.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'Ingredients policy'],
    [
        'body' => '<p>Every INCI list on this shop is copied from the box in front of us, not '
            .'from the brand\'s website. We are still checking the last of them.</p>',
        'position' => 110,
        'is_enabled' => false,
    ]
);

$patch = \App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'Patch test advice'],
    [
        'body' => '<p>Try a little on the inner arm for two days before you put a new product on '
            .'your face. Stop if it stings.</p>',
        'position' => 120,
        'is_enabled' => true,
    ]
);

/* ─────────────────────────────── round 2: one tab per targeting rule ────── */

$gift = $make([
    'slug' => 'lanept-glow-set',
    'name' => 'Glow Starter Set',
    'price' => 24900,
    'type' => 'set',
    'image' => $pic('#EFD9C2', '#B07B3A'),
    'short' => '<p>Three of our best sellers in one box.</p>',
    'description' => '<p>A toner, a serum and a barrier cream, boxed together.</p>',
]);

// CATEGORIES, and the tab is written against the PARENT. The toner sits in
// Skincare -> Toners and must inherit it; the LED mask is in Devices and must
// not. That pair is the whole inheritance decision, on one screenshot.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'Ingredients policy'],
    [
        'body' => '<p>Every INCI list on this shop is copied from the box in front of us, not '
            .'from the brand\'s website.</p>',
        'position' => 130,
        'is_enabled' => true,
        'audience' => 'categories',
        'audience_ids' => [$category->id],
    ]
);

// BRANDS.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'How we authenticate'],
    [
        'body' => '<p>We buy this brand direct. Every box carries the importer sticker and we '
            .'keep the invoice.</p>',
        'position' => 140,
        'is_enabled' => true,
        'audience' => 'brands',
        'audience_ids' => [$brand->id],
    ]
);

// SETS — a type match, so the box built next month is covered too.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'If one item is out of stock'],
    [
        'body' => '<p>We hold the box until every product in it is back, or we call you and swap '
            .'the missing one.</p>',
        'position' => 150,
        'is_enabled' => true,
        'audience' => 'sets',
        'audience_ids' => null,
    ]
);

// SPECIFIC PRODUCTS.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => null, 'source_key' => null, 'title' => 'Ships in its own crate'],
    [
        'body' => '<p>This one leaves in a rigid crate rather than a bag, so it takes a day '
            .'longer and cannot be same-day.</p>',
        'position' => 160,
        'is_enabled' => true,
        'audience' => 'products',
        'audience_ids' => [$device->id],
    ]
);

/* ───────────────────────────────────── one product's own tab, and a hide ── */

$own = \App\Models\ProductTab::updateOrCreate(
    ['product_id' => $full->id, 'source_key' => null, 'title' => 'Why we stock it'],
    [
        'body' => '<p>We bought this one four times before we listed it. It is the only toner in '
            .'the shop that every one of us still uses.</p>',
        'position' => 500,
        'is_enabled' => true,
    ]
);

// THE OWNER'S OWN CASE: a device has no patch-test advice.
\App\Models\ProductTab::updateOrCreate(
    ['product_id' => $device->id, 'source_key' => 'global:'.$patch->id],
    ['title' => '', 'body' => '', 'position' => 120, 'is_enabled' => false]
);

/* ──────────────────────────────────────────────────────── the Arabic ────── */

$ar = function (\App\Models\ProductTab $tab, string $title, string $body) {
    foreach ([['title', $title], ['body', $body]] as [$field, $value]) {
        \App\Models\Translation::updateOrCreate(
            ['locale' => 'ar', 'group' => 'product_tabs', 'item_id' => $tab->id, 'field' => $field],
            [
                'value' => $value,
                'status' => \App\Models\Translation::STATUS_PUBLISHED,
                'source' => \App\Models\Translation::SOURCE_MANUAL,
            ]
        );
    }
};

$ar($shipping, 'الشحن والإرجاع',
    '<p>التوصيل داخل الإمارات خلال يوم إلى ثلاثة أيام عمل. مجاني لما يزيد عن ١٩٩ درهمًا.</p>'
    .'<p>نقبل الإرجاع خلال أربعة عشر يومًا، بشرط أن يكون المنتج مغلقًا وبعبوته الأصلية.</p>');

$ar($own, 'لماذا نبيعه',
    '<p>اشتريناه أربع مرات قبل أن نعرضه. هو المُنقّي الوحيد في المتجر الذي ما زال كل واحد منا يستخدمه.</p>');

// And the product's own prose, so the Arabic product page is a real page rather
// than an English one behind a prefix.
foreach ([
    ['description', '<p>مُنقّي يومي لطيف يحتوي على ٧٧٪ من خلاصة نبات الهوتينيا. يهدئ الاحمرار الظاهر ويترك البشرة مهيأة لما يليه.</p>'],
    ['ingredients', '<p>خلاصة الهوتينيا كورداتا، ماء، بوتيلين جلايكول، جليسرين، بانثينول، هيالورونات الصوديوم، ألانتوين.</p>'],
    ['how_to_use', '<p>بعد التنظيف، مرّريه على الوجه بقطنة أو اضغطيه براحة اليد. صباحًا ومساءً.</p>'],
    ['name', 'تونر هارتليف المهدئ ٧٧٪ ٢٥٠ مل'],
] as [$field, $value]) {
    \App\Models\Translation::updateOrCreate(
        ['locale' => 'ar', 'group' => 'products', 'item_id' => $full->id, 'field' => $field],
        [
            'value' => $value,
            'status' => \App\Models\Translation::STATUS_PUBLISHED,
            'source' => \App\Models\Translation::SOURCE_MANUAL,
        ]
    );
}

// Arabic on, or /ar is a redirect rather than a page.
app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');

\App\Support\ProductTabs::flush();
\App\Services\Translation\TranslationStore::flush();

echo "Lane PT preview seeded.\n";
