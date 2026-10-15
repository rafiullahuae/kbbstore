<?php
/*
 * Lane AR round 2 -- the Arabic "first draft" preview fixture.
 *
 * Runs AFTER tools/ar-seed.php (state=rtl: Arabic on, mirrored on). PREVIEW
 * DATABASE ONLY; nothing here is a migration and nothing reaches the shop.
 *
 *   1. every shipped Arabic interface draft is PUBLISHED (the owner's
 *      "Approve all" press, tools/ar-publish-drafts.php);
 *   2. the demo catalogue becomes 32 real-looking products with real photos
 *      from the live shop (copied into the preview web root);
 *   3. Arabic content is written through the shop's own storage --
 *      TranslationStore::put(..., published) for products, categories,
 *      brands, pages, menu items, the blog post and the video rail, and the
 *      shop's own `*_ar` settings where the copy is a setting;
 *   4. a signed-in customer with two addresses, three orders and a wishlist,
 *      a coupon, shipping zones and the four payment methods.
 *
 * Arabic: Modern Standard, warm, Gulf-friendly, feminine address (the shipped
 * drafts' choice). Brand and product names stay Latin, INCI stays English,
 * units / SPF / PA++++ stay Latin.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Str;

require __DIR__.'/ar-publish-drafts.php';

$sv = app(\App\Services\SettingsService::class);
$PUB = Translation::STATUS_PUBLISHED;
$ar = function (string $group, int $id, string $field, string $value, ?string $en = null) use ($PUB) {
    TranslationStore::put('ar', $group, $id, $field, $value, $PUB, Translation::SOURCE_MANUAL, $en);
};

foreach (['ShippingSeeder', 'TaxSeeder', 'PaymentProviderSeeder', 'DemoReviewsSeeder'] as $seeder) {
    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\'.$seeder, '--force' => true]);
}
\App\Models\PaymentProvider::query()->whereIn('id', ['cod', 'tabby', 'tamara', 'stripe'])->update(['enabled' => true]);

/* ── PHOTOS: the live shop's own product shots, served from the preview root ── */
$web = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($web.'/uploads/products', 0777, true);
foreach (glob(base_path('storage/ar-logs/photos/*.webp')) as $f) {
    copy($f, $web.'/uploads/products/'.basename($f));
}
$img = fn (string $file): string => '/uploads/products/'.$file;

/* ── BRANDS ─────────────────────────────────────────────────────────────── */
$brandRows = [
    'anua' => ['Anua', 'Calm, clean formulas built around heartleaf and PDRN — the Seoul brand behind the toner everyone restocks.',
        'تركيبات هادئة ونقية تعتمد على الهارتليف و PDRN — العلامة الكورية من سيول التي يقف خلفها التونر الذي تعيد الجميع شراءه.'],
    'aplb' => ['APLB', 'Body care with the actives of a face serum: glutathione, niacinamide and collagen.',
        'عناية بالجسم بمكوّنات سيروم الوجه: الجلوتاثيون والنياسيناميد والكولاجين.'],
    'aprilskin' => ['APRILSKIN', 'Bright, playful skincare from Korea, famous for its carrot and TXA ranges.',
        'عناية كورية مبهجة ومرحة، اشتهرت بمجموعتي الجزر و TXA.'],
    'arencia' => ['Arencia', 'Fresh, small-batch Korean skincare made with fermented rice and real vitamin C.',
        'عناية كورية طازجة تُصنع بكميات صغيرة من الأرز المخمّر وفيتامين C الحقيقي.'],
    'celimax' => ['celimax', 'Derm-inspired Korean skincare: retinal, noni and pore care that stays gentle.',
        'عناية كورية مستوحاة من طب الجلد: ريتينال ونوني وعناية بالمسام بلطف دائم.'],
    'dalba' => ["d'Alba", 'Italian white truffle meets Korean skincare — the mist serum that started a trend.',
        'الكمأة البيضاء الإيطالية تلتقي بالعناية الكورية — سيروم الرذاذ الذي أطلق صيحة كاملة.'],
    'dr-althea' => ['Dr. Althea', 'Soothing, barrier-first skincare from Korea, loved for the 345 Relief range.',
        'عناية كورية مهدّئة تبدأ بحاجز البشرة، محبوبة بفضل مجموعة 345 Relief.'],
    'dr-ceuracle' => ['Dr. Ceuracle', 'Enzyme and probiotic cleansers for skin that wants clean without the squeak.',
        'منظفات بالإنزيمات والبروبيوتيك لبشرة نظيفة دون شدّ أو جفاف.'],
    'foodology' => ['FOODOLOGY', "Korea's favourite beauty-from-within brand: collagen jellies and daily sticks.",
        'العلامة الكورية المفضلة للجمال من الداخل: جيلي الكولاجين وأعواد يومية.'],
    'haruharu-wonder' => ['haruharu wonder', 'Black rice, bamboo and fermentation — clean Korean skincare with a cult following.',
        'الأرز الأسود والخيزران والتخمير — عناية كورية نظيفة لها جمهور مخلص.'],
    'lilyeve' => ['lilyeve', 'New-generation exosome care for scalp and lashes, made in Korea.',
        'عناية من الجيل الجديد بالإكسوسومات لفروة الرأس والرموش، صُنعت في كوريا.'],
    'medicube' => ['medicube', 'Clinic-born Korean skincare and devices, from PDRN pads to the Age-R range.',
        'عناية وأجهزة كورية وُلدت في العيادات، من وسادات PDRN إلى مجموعة Age-R.'],
    'skin1004' => ['SKIN1004', 'Madagascar centella in every bottle — soothing, simple and made for sensitive skin.',
        'سنتيلا مدغشقر في كل عبوة — مهدّئة وبسيطة ومصممة للبشرة الحساسة.'],
    'some-by-mi' => ['SOME BY MI', 'The AHA·BHA·PHA miracle range that made clear skin a 30-day project.',
        'مجموعة AHA·BHA·PHA المعجزة التي جعلت البشرة الصافية مشروع 30 يومًا.'],
];
$brands = [];
foreach ($brandRows as $slug => [$name, $en, $arText]) {
    $b = Brand::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $en]);
    $ar('brands', $b->id, 'description', $arText, $en);
    $brands[$slug] = $b->id;
}

/* ── CATEGORIES ─────────────────────────────────────────────────────────── */
$catRows = [
    'cleansers' => ['Cleansers', 'منظفات', 'Gel, foam and enzyme cleansers that leave skin clean, soft and never tight.',
        'منظفات جل ورغوة وإنزيمات تترك البشرة نظيفة وناعمة دون أي شعور بالشدّ.'],
    'toners' => ['Toners', 'تونر', 'Watery toners, pads and mists — the step that preps skin for everything after it.',
        'تونر مائي ووسادات ورذاذ — الخطوة التي تهيّئ بشرتك لكل ما يأتي بعدها.'],
    'serums' => ['Serums', 'سيروم', 'Concentrated serums and ampoules for glow, calm and an even tone.',
        'سيرومات وأمبولات مركّزة لإشراقة وهدوء ولون موحّد.'],
    'moisturisers' => ['Moisturisers', 'مرطبات', 'Creams and lotions that lock in hydration and support the skin barrier.',
        'كريمات ولوشن تحبس الترطيب وتدعم حاجز البشرة.'],
    'sunscreens' => ['Sunscreens', 'واقيات الشمس', 'Light Korean sun care with SPF50+ PA++++ that wears beautifully in the UAE heat.',
        'واقيات شمس كورية خفيفة بحماية SPF50+ PA++++ تبقى مريحة في حرارة الإمارات.'],
    'masks' => ['Masks', 'أقنعة', 'Sheet masks, gel masks and patches for a quick reset.',
        'ماسكات ورقية وجل ولصقات لانتعاش سريع.'],
];
$cats = [];
foreach ($catRows as $slug => [$name, $arName, $en, $arText]) {
    $c = Category::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => '<p>'.$en.'</p>']);
    $ar('categories', $c->id, 'name', $arName, $name);
    $ar('categories', $c->id, 'description', '<p>'.$arText.'</p>', '<p>'.$en.'</p>');
    $cats[$slug] = $c->id;
}

/* ── THE CATALOGUE ──────────────────────────────────────────────────────────
 * [name, brand, category, price AED, sale AED|null, photo, Arabic short line]
 */
$catalogue = [
    ['Azelaic 10 Hyaluron Redness Soothing Pad', 'anua', 'toners', 99, 79, 'anua-azelaic-10-hyaluron-redness-soothing-pad.webp',
        'وسادات تونر بحمض الأزيليك 10% والهيالورونيك تهدّئ الاحمرار وتنعّم الملمس في خطوة واحدة.'],
    ['PDRN + Hyaluron Ultimate Glass Skin Set', 'anua', 'serums', 289, 229, 'anua-pdrn-hyaluron-ultimate-glass-skin-set-2.webp',
        'مجموعة البشرة الزجاجية الكاملة: سيروم PDRN وكريم الهيالورونيك لإشراقة ممتلئة بالترطيب.'],
    ['PDRN Hyaluronic Acid Hydrating Capsule Mist Mini', 'anua', 'toners', 59, null, 'Anua-PDRN-Hyaluronic-Acid-Hydrating-Capsule-Mist-Mini.webp',
        'رذاذ ترطيب صغير بكبسولات PDRN، رفيق حقيبتك لانتعاش فوري خلال اليوم.'],
    ['Glutathione Niacinamide Body Wash', 'aplb', 'cleansers', 69, null, 'aplb-glutathione-niacinamide-body-wash.webp',
        'غسول للجسم بالجلوتاثيون والنياسيناميد ينظّف بلطف ويمنح بشرتك إشراقة موحّدة.'],
    ['Carrotene IPMP Exfoliating Body Wash', 'aprilskin', 'cleansers', 75, null, 'aprilskin-carrotene-ipmp-exfoliating-body-wash.webp',
        'غسول مقشّر للجسم بالجزر و IPMP يزيل الخلايا الميتة ويترك البشرة ناعمة كالحرير.'],
    ['Real Carrotene Acne Foam Cleanser', 'aprilskin', 'cleansers', 55, null, 'aprilskin-real-carrotene-acne-foam-cleanser.webp',
        'رغوة منظفة بالجزر للبشرة المعرّضة للحبوب، تنقّي المسام دون جفاف.'],
    ['TXA Niacinamide 99 Deep Cleanser', 'aprilskin', 'cleansers', 65, null, 'aprilskin-txa-niacinamide-99-deep-cleanser.webp',
        'منظف عميق بـ TXA والنياسيناميد يزيل الشوائب ويدعم لونًا أكثر صفاءً.'],
    ['TXA Niacinamide Collagen 99 Capsule Gel Mask Set', 'aprilskin', 'masks', 89, null, 'aprilskin-txa-niacinamide-collagen-99-capsule-gel-mask-set.webp',
        'مجموعة أقنعة جل بالكبسولات مع الكولاجين و TXA لبشرة مشدودة ومشرقة.'],
    ['TXA Niacinamide Toning Shot 99 Serum', 'aprilskin', 'serums', 85, null, 'aprilskin-txa-niacinamide-toning-shot-99-serum.webp',
        'سيروم مركّز بـ TXA والنياسيناميد يستهدف التصبّغات ويوحّد لون البشرة.'],
    ['Vitamin C Booster Shot', 'arencia', 'serums', 119, null, 'Arencia-Vitamin-C-Booster-Shot.webp',
        'جرعة فيتامين C طازجة لإشراقة فورية ونضارة تدوم.'],
    ['The Vita-A Retinal Shot Tightening Booster', 'celimax', 'serums', 95, null, 'celimax-The-Vita-A-Retinal-Shot-Tightening-Booster.webp',
        'معزّز بالريتينال يشدّ البشرة وينعّم الخطوط الدقيقة بلطف مناسب للمبتدئات.'],
    ['Double Serum All In One Multi Balm', 'dalba', 'moisturisers', 129, null, 'dalba-double-serum-all-in-one-multi-balm.webp',
        'بلسم متعدد الاستخدامات بالكمأة البيضاء يرطّب الوجه والشفاه أينما كنتِ.'],
    ['White Truffle Vital Spray Serum', 'dalba', 'serums', 139, 109, 'dalba-white-truffle-vital-spray-serum.webp',
        'سيروم رذاذ بالكمأة البيضاء يمنح ترطيبًا وإشراقة فوق المكياج أو من دونه.'],
    ['345 Relief Cream Mask Set', 'dr-althea', 'masks', 79, null, 'dr-althea-345-relief-cream-mask-set-6.webp',
        'ماسكات كريمية مهدّئة من مجموعة 345 Relief لبشرة مرتاحة ومرطّبة.'],
    ['Rapid Hypochlorous Acid Rescue Mist 60ml', 'dr-althea', 'toners', 49, null, 'dr-althea-rapid-hypochlorous-acid-rescue-mist-mini.webp',
        'رذاذ إنقاذ سريع بحمض الهيبوكلوروس يهدّئ البشرة المتهيّجة في ثوانٍ.'],
    ['Pro Balance Night Enzyme Wash', 'dr-ceuracle', 'cleansers', 89, null, 'dr-ceuracle-pro-balance-night-enzyme-wash.webp',
        'غسول إنزيمي ليلي بالبروبيوتيك يقشّر بلطف ويوازن البشرة.'],
    ['Black Rice 10 Hyaluronic Cream Unscented', 'haruharu-wonder', 'moisturisers', 105, null, 'haruharu-wonder-black-rice-10-hyaluronic-cream-unscented.webp',
        'كريم بالأرز الأسود والهيالورونيك دون عطر، ترطيب عميق للبشرة الحساسة.'],
    ['Black Rice 5 Ceramide Barrier Moisturizing Cream', 'haruharu-wonder', 'moisturisers', 115, 92, 'haruharu-wonder-black-rice-5-ceramide-barrier-moisturizing-cream-5.webp',
        'كريم مرطّب بالأرز الأسود و5 أنواع من السيراميد يقوّي حاجز البشرة ويحبس الترطيب طوال اليوم.'],
    ['Black Rice Night Knight Retinol Serum', 'haruharu-wonder', 'serums', 99, null, 'haruharu-wonder-black-rice-night-knight-retinol-serum.webp',
        'سيروم ليلي بالريتينول والأرز الأسود لتجديد البشرة أثناء نومك.'],
    ['Black Rice Probiotics Barrier Essence', 'haruharu-wonder', 'toners', 95, null, 'haruharu-wonder-black-rice-probiotics-barrier-essence.webp',
        'إيسنس بالبروبيوتيك والأرز الأسود يقوّي حاجز البشرة ويمنحها نعومة.'],
    ['PDRN Pink Collagen Jelly Eye Mask 6 pairs', 'medicube', 'masks', 45, null, 'medicube-PDRN-Pink-Collagen-Jelly-Eye-Mask-6-pairs.webp',
        'لصقات جل للعين بالكولاجين و PDRN تخفّف الانتفاخ وتنعش النظرة.'],
    ['Madagascar Centella Hyalu-Cica Water-Fit Sun Serum SPF50+ PA++++ 50ml', 'skin1004', 'sunscreens', 79, null, 'SKIN-1004-Madagascar-Centella-Hyalu-Cica-Water-Fit-Sun-Serum-50ml.webp',
        'سيروم واقٍ من الشمس بقوام مائي خفيف وحماية SPF50+ PA++++، دون أثر أبيض وبلمسة منعشة.'],
    ['AHA BHA PHA Miracle Calming Body Lotion', 'some-by-mi', 'moisturisers', 69, null, 'some-by-mi-aha-bha-pha-miracle-calming-body-lotion.webp',
        'لوشن مهدّئ للجسم بالأحماض الثلاثة ينعّم الملمس ويقلّل الحبوب.'],
    ['PDRN Collagen Melting Patch For Glass Skin', 'anua', 'masks', 52, null, 'Anua-PDRN-Collagen-Melting-Patch-For-Glass-Skin.webp',
        'لصقات ذائبة بالكولاجين و PDRN لبشرة زجاجية ممتلئة بالنضارة.'],
    /* the lanear-* rows from tools/ar-seed.php, renamed in order */
    ['Lash Boost Serum', 'dr-althea', 'serums', 75, null, 'dr-althea-lash-boost-serum-5.webp',
        'سيروم للرموش يغذّيها ويقوّيها لمظهر أكثف وأطول.'],
    ['345 Relief Cream Mist Spray', 'dr-althea', 'toners', 85, null, 'Dr.Althea-345-Relief-Cream-Mist-kbeautybliss.webp',
        'رذاذ كريمي مهدّئ يرطّب ويريح البشرة خلال اليوم.'],
    ['Coleology Cutting Powder', 'foodology', 'masks', 139, 119, 'foodology-coleology-cutting-powder.webp',
        'مسحوق يومي من FOODOLOGY ضمن روتين الجمال من الداخل.'],
    ['Collagen Centella Jelly', 'foodology', 'masks', 99, null, 'foodology-collagen-centella-jelly-2.webp',
        'جيلي الكولاجين بالسنتيلا، جمال من الداخل بطعم لذيذ.'],
    ['Black Bamboo Daily Smoothing Body Oil', 'haruharu-wonder', 'moisturisers', 89, null, 'haruharu-wonder-black-bamboo-daily-smoothing-body-oil-5.webp',
        'زيت جسم خفيف بالخيزران الأسود ينعّم البشرة دون أي أثر دهني.'],
    ['Professional Repairing Hair Perfume Serum', 'dalba', 'serums', 79, null, 'dalba-professional-repairing-hair-perfume-serum.webp',
        'سيروم معطّر لإصلاح الشعر يمنحه لمعانًا وعطرًا يدوم.'],
    ['Grow Turn Exosome Brush Ampoule', 'lilyeve', 'serums', 159, null, 'lilyeve-Grow-Turn-Exosome-Brush-Ampoule.webp',
        'أمبولة بالإكسوسومات مع فرشاة لتدليك فروة الرأس وتغذيتها.'],
    ['Red Acne Body Wash', 'medicube', 'cleansers', 65, null, 'medicube-red-acne-body-wash.webp',
        'غسول للجسم من medicube للبشرة المعرّضة للحبوب في الظهر والصدر.'],
];

$demo = Product::query()->whereNull('wc_id')->where('type', 'simple')->orderByRaw("slug like 'lanear-%'")->orderBy('id')->get()->values();
$ids = [];
foreach ($catalogue as $i => [$name, $brandSlug, $catSlug, $price, $sale, $photo, $arShort]) {
    $p = $demo[$i] ?? null;
    if ($p === null) {
        break;
    }
    $enShort = Str::limit($name, 60, '').' — a K-Beauty Bliss favourite, 100% authentic and shipped from the UAE.';
    $p->forceFill([
        'name' => $name,
        'slug' => $p->slug === 'lanear-1' ? 'lanear-1' : Str::slug($name),
        'brand_id' => $brands[$brandSlug],
        'category_id' => $cats[$catSlug],
        'price' => $price * 100,
        'sale_price' => $sale === null ? null : $sale * 100,
        'image' => $img($photo),
        'images' => [],
        'short_description' => $enShort,
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'featured' => $i < 8,
        'total_sales' => 400 - $i * 11,
    ])->save();
    $p->categories()->sync([$cats[$catSlug]]);
    $ar('products', $p->id, 'short_description', $arShort, $enShort);
    $ids[$i] = $p->id;
}
/* Anything left of the placeholder rows is hidden, so no gradient tile is shown. */
Product::query()->whereNull('wc_id')->where('type', 'simple')->whereNotIn('id', $ids)->update(['status' => 'draft']);

/* ── THREE PRODUCTS, FULLY WRITTEN IN BOTH LANGUAGES ─────────────────────── */
$full = [
    0 => [ // Anua Azelaic pad
        'gallery' => ['anua-azelaic-10-hyaluron-redness-soothing-pad.webp', 'anua-pdrn-hyaluron-ultimate-glass-skin-set-2.webp', 'Anua-PDRN-Collagen-Melting-Patch-For-Glass-Skin.webp', 'Anua-PDRN-Hyaluronic-Acid-Hydrating-Capsule-Mist-Mini.webp'],
        'en_short' => 'Soft, double-sided toner pads with 10% azelaic acid and hyaluronic acid that calm visible redness and smooth texture in one swipe.',
        'ar_short' => 'وسادات تونر ناعمة بوجهين، بحمض الأزيليك 10% والهيالورونيك، تهدّئ الاحمرار الظاهر وتنعّم ملمس البشرة بمسحة واحدة.',
        'en' => '<p>Anua’s <strong>Azelaic 10 Hyaluron Redness Soothing Pad</strong> is the quickest way to calm a flushed, uneven complexion. Each pad is soaked in an essence with 10% azelaic acid, five types of hyaluronic acid and heartleaf, so redness settles while the skin stays plump and hydrated.</p>'
            .'<h3>Benefits</h3><ul><li>Calms visible redness and post-blemish marks</li><li>Gently smooths rough texture without stripping</li><li>Deep hydration from five hyaluronic acids</li><li>Suitable for sensitive and acne-prone skin</li></ul>'
            .'<h3>Made in Korea</h3><p>Formulated and manufactured in South Korea and imported directly to the UAE. 100% authentic.</p>'
            .'<h3>Size</h3><p>70 pads (160ml)</p>'
            .'<h3>About the brand</h3><p>Anua builds calm, clean formulas around Korean botanicals. Its heartleaf toner became a global best seller, and the azelaic range brings the same gentleness to redness care.</p>',
        'ar' => '<p>وسادات <strong>Azelaic 10 Hyaluron Redness Soothing Pad</strong> من Anua هي أسرع طريقة لتهدئة البشرة المحمرّة وغير المتجانسة. كل وسادة مشبعة بإيسنس يحتوي على 10% من حمض الأزيليك وخمسة أنواع من حمض الهيالورونيك والهارتليف، فيهدأ الاحمرار بينما تبقى بشرتكِ ممتلئة ومرطّبة.</p>'
            .'<h3>الفوائد</h3><ul><li>تهدّئ الاحمرار الظاهر وآثار الحبوب</li><li>تنعّم الملمس الخشن بلطف دون أن تجرّد البشرة من زيوتها</li><li>ترطيب عميق بخمسة أنواع من الهيالورونيك</li><li>مناسبة للبشرة الحساسة والمعرّضة للحبوب</li></ul>'
            .'<h3>صُنع في كوريا</h3><p>تُركّب وتُصنّع في كوريا الجنوبية وتُستورد مباشرة إلى الإمارات. أصلية 100%.</p>'
            .'<h3>الحجم</h3><p>70 وسادة (160ml)</p>'
            .'<h3>عن العلامة</h3><p>تبني Anua تركيبات هادئة ونقية حول النباتات الكورية. أصبح تونر الهارتليف من الأكثر مبيعًا عالميًا، وتنقل مجموعة الأزيليك اللطف نفسه إلى العناية بالاحمرار.</p>',
        'ing' => '<p>Water, Butylene Glycol, Glycerin, Azelaic Acid, Houttuynia Cordata Extract, Sodium Hyaluronate, Hydrolyzed Hyaluronic Acid, Panthenol, Niacinamide, Allantoin, 1,2-Hexanediol.</p>',
        'en_how' => '<ol><li>After cleansing, take one pad and sweep it over the face from the centre outwards.</li><li>Flip the pad and press the smooth side over red areas for 1–2 minutes.</li><li>Pat in the remaining essence and follow with serum and moisturiser.</li></ol>',
        'ar_how' => '<ol><li>بعد التنظيف، خذي وسادة واحدة وامسحي بها الوجه من المنتصف إلى الخارج.</li><li>اقلبي الوسادة وضعي الجهة الناعمة على المناطق المحمرّة لمدة 1–2 دقيقة.</li><li>ربّتي على بقية الإيسنس ثم أكملي بالسيروم والمرطّب.</li></ol>',
    ],
    17 => [ // haruharu ceramide cream
        'gallery' => ['haruharu-wonder-black-rice-5-ceramide-barrier-moisturizing-cream-5.webp', 'haruharu-wonder-black-rice-10-hyaluronic-cream-unscented.webp', 'haruharu-wonder-black-rice-probiotics-barrier-essence.webp'],
        'en_short' => 'A rich-but-breathable barrier cream with fermented black rice and five ceramides that locks in moisture all day.',
        'ar_short' => 'كريم غني لكنه خفيف على البشرة، بالأرز الأسود المخمّر وخمسة أنواع من السيراميد، يحبس الترطيب طوال اليوم.',
        'en' => '<p>The <strong>Black Rice 5 Ceramide Barrier Moisturizing Cream</strong> from haruharu wonder repairs and protects a stressed skin barrier. Fermented black rice brings antioxidants, while five ceramides rebuild the moisture barrier so skin feels comfortable even in air-conditioning.</p>'
            .'<h3>Benefits</h3><ul><li>Strengthens the skin barrier with five ceramides</li><li>Long-lasting moisture without a greasy finish</li><li>Antioxidant care from fermented black rice</li><li>Fragrance-free and gentle on sensitive skin</li></ul>'
            .'<h3>Made in Korea</h3><p>Made in South Korea with vegan-friendly ingredients. Imported directly by K-Beauty Bliss.</p>'
            .'<h3>Size</h3><p>50ml</p>'
            .'<h3>About the brand</h3><p>haruharu wonder pioneered fermented black rice skincare. Its clean, minimal formulas are made for every skin type and every season.</p>',
        'ar' => '<p>كريم <strong>Black Rice 5 Ceramide Barrier Moisturizing Cream</strong> من haruharu wonder يصلح حاجز البشرة المجهد ويحميه. يمنح الأرز الأسود المخمّر مضادات الأكسدة، بينما تعيد خمسة أنواع من السيراميد بناء حاجز الترطيب، فتبقى بشرتكِ مرتاحة حتى في أجواء المكيّف.</p>'
            .'<h3>الفوائد</h3><ul><li>يقوّي حاجز البشرة بخمسة أنواع من السيراميد</li><li>ترطيب يدوم طويلًا دون ملمس دهني</li><li>عناية مضادة للأكسدة من الأرز الأسود المخمّر</li><li>خالٍ من العطور ولطيف على البشرة الحساسة</li></ul>'
            .'<h3>صُنع في كوريا</h3><p>صُنع في كوريا الجنوبية بمكوّنات نباتية. تستورده K-Beauty Bliss مباشرة.</p>'
            .'<h3>الحجم</h3><p>50ml</p>'
            .'<h3>عن العلامة</h3><p>haruharu wonder رائدة في العناية بالأرز الأسود المخمّر. تركيباتها النظيفة والبسيطة مصممة لكل أنواع البشرة وفي كل المواسم.</p>',
        'ing' => '<p>Water, Glycerin, Oryza Sativa (Rice) Bran Extract, Butylene Glycol, Caprylic/Capric Triglyceride, Ceramide NP, Ceramide AP, Ceramide EOP, Ceramide NS, Ceramide AS, Panthenol, Squalane, Cholesterol, Hydrogenated Lecithin.</p>',
        'en_how' => '<ol><li>Use as the last step of your morning and evening routine.</li><li>Warm a pea-sized amount between your fingertips.</li><li>Press gently over the face and neck until absorbed.</li></ol>',
        'ar_how' => '<ol><li>استخدميه كخطوة أخيرة في روتينكِ الصباحي والمسائي.</li><li>دفّئي كمية بحجم حبة البازلاء بين أطراف أصابعكِ.</li><li>اضغطي بلطف على الوجه والرقبة حتى يمتصّ تمامًا.</li></ol>',
    ],
    21 => [ // SKIN1004 sun serum
        'gallery' => ['SKIN-1004-Madagascar-Centella-Hyalu-Cica-Water-Fit-Sun-Serum-50ml.webp', 'dr-althea-rapid-hypochlorous-acid-rescue-mist-mini.webp', 'anua-azelaic-10-hyaluron-redness-soothing-pad.webp'],
        'en_short' => 'A watery sun serum with SPF50+ PA++++ that disappears into the skin — no white cast, no stickiness, perfect under make-up.',
        'ar_short' => 'سيروم واقٍ من الشمس بقوام مائي وحماية SPF50+ PA++++ يذوب في البشرة — دون أثر أبيض ولا لزوجة، ومثالي تحت المكياج.',
        'en' => '<p>SKIN1004’s <strong>Hyalu-Cica Water-Fit Sun Serum</strong> is the sunscreen people who hate sunscreen reach for. Its serum texture with hyaluronic acid and Madagascar centella hydrates as it protects, with broad-spectrum SPF50+ PA++++ that stays comfortable in the UAE sun.</p>'
            .'<h3>Benefits</h3><ul><li>High protection: SPF50+ PA++++</li><li>Lightweight serum texture with no white cast</li><li>Hydrating hyaluronic acid and soothing centella</li><li>Sits beautifully under make-up</li></ul>'
            .'<h3>Made in Korea</h3><p>Made in South Korea. Every bottle is sourced from the brand’s official distributor.</p>'
            .'<h3>Size</h3><p>50ml</p>'
            .'<h3>About the brand</h3><p>SKIN1004 is built on centella asiatica from Madagascar. Its simple, soothing formulas are a favourite for sensitive skin around the world.</p>',
        'ar' => '<p>سيروم <strong>Hyalu-Cica Water-Fit Sun Serum</strong> من SKIN1004 هو الواقي الذي تختاره حتى من لا تحب واقيات الشمس. قوامه السيرومي بحمض الهيالورونيك وسنتيلا مدغشقر يرطّب أثناء الحماية، مع حماية واسعة الطيف SPF50+ PA++++ تبقى مريحة تحت شمس الإمارات.</p>'
            .'<h3>الفوائد</h3><ul><li>حماية عالية: SPF50+ PA++++</li><li>قوام سيروم خفيف دون أثر أبيض</li><li>ترطيب بالهيالورونيك وتهدئة بالسنتيلا</li><li>يجلس بشكل مثالي تحت المكياج</li></ul>'
            .'<h3>صُنع في كوريا</h3><p>صُنع في كوريا الجنوبية. كل عبوة من الموزّع الرسمي للعلامة.</p>'
            .'<h3>الحجم</h3><p>50ml</p>'
            .'<h3>عن العلامة</h3><p>تعتمد SKIN1004 على سنتيلا آسياتيكا من مدغشقر. تركيباتها البسيطة والمهدّئة مفضّلة لدى أصحاب البشرة الحساسة حول العالم.</p>',
        'ing' => '<p>Water, Ethylhexyl Methoxycinnamate, Propanediol, Diethylamino Hydroxybenzoyl Hexyl Benzoate, Centella Asiatica Extract, Sodium Hyaluronate, Niacinamide, Glycerin, Ethylhexyl Triazone, Bis-Ethylhexyloxyphenol Methoxyphenyl Triazine.</p>',
        'en_how' => '<ol><li>Apply as the last step of your morning skincare.</li><li>Use two finger-lengths for the face and neck.</li><li>Reapply every two hours when you are outdoors.</li></ol>',
        'ar_how' => '<ol><li>ضعيه كخطوة أخيرة في روتين العناية الصباحي.</li><li>استخدمي كمية بطول إصبعين للوجه والرقبة.</li><li>أعيدي وضعه كل ساعتين عند التواجد في الخارج.</li></ol>',
    ],
];
foreach ($full as $i => $f) {
    $p = Product::find($ids[$i]);
    $p->forceFill([
        'short_description' => $f['en_short'],
        'description' => $f['en'],
        'ingredients' => $f['ing'],
        'how_to_use' => $f['en_how'],
        'images' => array_map($img, array_slice($f['gallery'], 1)),
        'image' => $img($f['gallery'][0]),
    ])->save();
    $ar('products', $p->id, 'short_description', $f['ar_short'], $f['en_short']);
    $ar('products', $p->id, 'description', $f['ar'], $f['en']);
    $ar('products', $p->id, 'how_to_use', $f['ar_how'], $f['en_how']);
}
$hero = Product::find($ids[0]);

/* Product tab on lanear-1 (authored by tools/ar-seed.php): Arabic title/body. */
foreach (\App\Models\ProductTab::query()->get() as $tab) {
    $ar('product_tabs', $tab->id, 'title', 'لماذا اخترناه', $tab->title);
    $ar('product_tabs', $tab->id, 'body', '<p>اخترناه لمناخ الإمارات: خفيف بما يكفي للصيف ودون عطر.</p>', $tab->body);
}

/* The set (tools/ar-seed.php): a real name and picture. */
$set = Product::where('slug', 'lanear-glow-starter-set')->first();
$set->forceFill(['name' => 'Glass Skin Starter Set', 'image' => $img('anua-pdrn-hyaluron-ultimate-glass-skin-set-2.webp'),
    'short_description' => 'Three steps, one box: pad, serum and sun care.', 'brand_id' => $brands['anua']])->save();
\App\Models\ProductSetItem::where('set_product_id', $set->id)->delete();
foreach ([$ids[0], $ids[8], $ids[21]] as $n => $m) {
    \App\Models\ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $m, 'quantity' => 1, 'position' => $n]);
}
\App\Support\SetPricing::forget((int) $set->id);
$ar('products', $set->id, 'short_description', 'ثلاث خطوات في علبة واحدة: وسادات التونر والسيروم والحماية من الشمس.', $set->short_description);

/* ── REVIEWS on the hero product: two English, two Arabic, as live has. ── */
Review::query()->where('product_id', $hero->id)->delete();
$reviews = [
    ['Mariam A.', 5, 'My redness is finally calm', 'I have rosacea-prone skin and these pads are the only thing that calms it down after the gym. Delivery to Dubai Marina took one day.'],
    ['نورة الكتبي', 5, 'أفضل وسادات جربتها', 'خفيفة جدًا ولا تسبب أي تهيّج، لاحظت الفرق في الاحمرار بعد أسبوع واحد. التوصيل إلى أبوظبي كان سريعًا والتغليف رائع.'],
    ['Sarah K.', 4, 'Great, a bit wet', 'Works well on my cheeks. The pads are very soaked so I use half a pad each time — which makes the tub last longer!'],
    ['ريم', 5, 'منتج أصلي 100%', 'طلبته مرتين من المتجر والمنتج أصلي ومطابق لما أشتريه من كوريا. أنصح فيه للبشرة الحساسة 💕'],
];
foreach ($reviews as $n => [$who, $stars, $title, $body]) {
    Review::create(['product_id' => $hero->id, 'author_name' => $who, 'author_email' => 'r'.$n.'@preview.test', 'rating' => $stars,
        'title' => $title, 'content' => $body, 'status' => 'approved', 'verified' => true, 'helpful' => 3 - min($n, 3),
        'created_at' => now()->subDays(3 + $n * 9)]);
}
if (class_exists(\App\Support\ProductRating::class) && method_exists(\App\Support\ProductRating::class, 'refresh')) {
    \App\Support\ProductRating::refresh([$hero->id]);
} else {
    $hero->forceFill(['rating' => 4.75, 'review_count' => 4])->save();
}

/* ── MENU LABELS ────────────────────────────────────────────────────────── */
$menuAr = [
    'Brands' => 'العلامات التجارية', 'Skincare' => 'العناية بالبشرة', 'Cleansing Oils' => 'زيوت التنظيف',
    'Face Washes' => 'غسول الوجه', 'Exfoliators' => 'مقشّرات', 'Toners' => 'تونر', 'Face Serums' => 'سيروم الوجه',
    'Eye Care' => 'العناية بالعين', 'Face Masks' => 'ماسكات الوجه', 'Moisturizers' => 'مرطبات', 'Lip Care' => 'العناية بالشفاه',
    'Sunscreens' => 'واقيات الشمس', 'Hair Care' => 'العناية بالشعر', 'Skincare Sets' => 'مجموعات العناية',
    'Super Sale' => 'تخفيضات كبرى', 'Beauty Devices' => 'أجهزة التجميل', 'Everything Under 54 AED' => 'كل شيء بأقل من 54 درهمًا',
    'Blog' => 'المدوّنة', 'Shop' => 'تسوّقي', 'Home' => 'الرئيسية', 'About Us' => 'من نحن', 'Contact Us' => 'تواصلي معنا',
    'FAQs' => 'الأسئلة الشائعة', 'Privacy Policy' => 'سياسة الخصوصية', 'Terms & Conditions' => 'الشروط والأحكام',
    'Shipping & Delivery' => 'الشحن والتوصيل', 'Refund and Returns Policy' => 'سياسة الاسترجاع والاستبدال',
    'Track my order' => 'تتبّعي طلبكِ', 'Track My Order' => 'تتبّعي طلبكِ', 'New In' => 'وصل حديثًا', 'Best Sellers' => 'الأكثر مبيعًا',
];
foreach (MenuItem::query()->get() as $mi) {
    if (isset($menuAr[$mi->label])) {
        $ar('menu_items', $mi->id, 'label', $menuAr[$mi->label], $mi->label);
    }
}

/* ── STATIC PAGES ───────────────────────────────────────────────────────── */
$pagesAr = require __DIR__.'/arr-pages.php';
foreach ($pagesAr as $slug => [$title, $content]) {
    $page = Page::where('slug', $slug)->first();
    if ($page) {
        $ar('pages', $page->id, 'title', $title, $page->title);
        $ar('pages', $page->id, 'content', $content, $page->content);
    }
}

/* ── THE JOURNAL: one post, both languages ──────────────────────────────── */
$postEn = require __DIR__.'/arr-post.php';
$post = Post::updateOrCreate(['slug' => 'korean-skincare-routine-for-uae-summer'], [
    'title' => $postEn['en_title'], 'excerpt' => $postEn['en_excerpt'], 'body' => $postEn['en_body'],
    'cover' => $img('anua-pdrn-hyaluron-ultimate-glass-skin-set-2.webp'), 'tag' => 'Routines', 'author' => 'K-Beauty Bliss',
    'status' => 'published', 'published_at' => now()->subDays(4),
]);
$ar('posts', $post->id, 'title', $postEn['ar_title'], $post->title);
$ar('posts', $post->id, 'excerpt', $postEn['ar_excerpt'], $post->excerpt);
$ar('posts', $post->id, 'body', $postEn['ar_body'], $post->body);
$post2 = Post::updateOrCreate(['slug' => 'how-to-layer-toner-pads'], [
    'title' => 'How to use toner pads the Korean way', 'excerpt' => 'Swipe, press, mask: three ways to get more from every pad.',
    'body' => '<p>Toner pads are the most versatile step in a Korean routine.</p>', 'cover' => $img('anua-azelaic-10-hyaluron-redness-soothing-pad.webp'),
    'tag' => 'How-to', 'author' => 'K-Beauty Bliss', 'status' => 'published', 'published_at' => now()->subDays(11),
]);
$ar('posts', $post2->id, 'title', 'كيف تستخدمين وسادات التونر على الطريقة الكورية', $post2->title);
$ar('posts', $post2->id, 'excerpt', 'امسحي، اضغطي، واستخدميها كماسك: ثلاث طرق لتستفيدي أكثر من كل وسادة.', $post2->excerpt);
$ar('posts', $post2->id, 'body', '<p>وسادات التونر هي أكثر خطوة متعددة الاستخدامات في الروتين الكوري.</p>', $post2->body);

/* ── THE VIDEO RAIL ─────────────────────────────────────────────────────── */
$clipAr = ['روتين الخطوات العشر بصراحة', 'النياسيناميد بعد أربعة أسابيع', 'بشرة زجاجية في ست خطوات',
    'واقي شمس لا يتكتّل', 'بلسم التنظيف لأول مرة', 'ما أعيد شراءه فعلًا'];
foreach (\App\Models\UgcVideo::query()->orderBy('id')->get()->values() as $n => $v) {
    if (isset($clipAr[$n])) {
        $ar('ugc_videos', $v->id, 'title', $clipAr[$n], $v->title);
        $ar('ugc_videos', $v->id, 'caption', $clipAr[$n], $v->caption);
    }
    if (isset($ids[$n])) {
        $v->products()->sync([$ids[$n]]);
    }
}
foreach (\App\Models\UgcSection::query()->get() as $s) {
    $ar('ugc_sections', $s->id, 'heading', 'تسوّقي الإطلالة', (string) $s->heading);
}

/* ── A CUSTOMER WITH HISTORY ────────────────────────────────────────────── */
$me = Customer::updateOrCreate(['email' => 'mariam@preview.test'], [
    'name' => 'Mariam Al Hashimi', 'first_name' => 'Mariam', 'last_name' => 'Al Hashimi',
    'password' => 'preview-pass-1', 'phone' => '+971501234567', 'email_verified_at' => now(),
]);
$me->addresses()->delete();
$home = $me->addresses()->create(['type' => 'shipping', 'label' => 'Home', 'is_default' => true, 'first_name' => 'Mariam', 'last_name' => 'Al Hashimi',
    'line1' => 'Villa 14, Street 23', 'line2' => 'Al Barsha 2', 'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567']);
$me->addresses()->create(['type' => 'shipping', 'label' => 'Office', 'is_default' => false, 'first_name' => 'Mariam', 'last_name' => 'Al Hashimi',
    'line1' => 'Office 902, Al Maqam Tower', 'line2' => 'ADGM Square, Al Maryah Island', 'city' => 'Al Maryah Island', 'state' => 'Abu Dhabi', 'country' => 'AE', 'phone' => '+971501234567']);
Order::query()->where('customer_id', $me->id)->delete();
$addr = ['first_name' => 'Mariam', 'last_name' => 'Al Hashimi', 'line1' => 'Villa 14, Street 23', 'line2' => 'Al Barsha 2',
    'city' => 'Al Barsha', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567', 'email' => 'mariam@preview.test'];
$orders = [
    [10512, 'processing', 1, [[0, 2], [17, 1]], 'tabby', 'Tabby: Pay in 4 Installments'],
    [10467, 'shipped', 9, [[21, 1], [12, 1], [20, 3]], 'stripe', 'Credit / Debit Card'],
    [10390, 'completed', 34, [[1, 1]], 'cod', 'Cash on delivery'],
];
foreach ($orders as [$num, $status, $ago, $lines, $pm, $pmt]) {
    $sub = 0; $items = [];
    foreach ($lines as [$k, $q]) {
        $p = Product::find($ids[$k]);
        $unit = (int) ($p->sale_price ?: $p->price);
        $sub += $unit * $q;
        $items[] = ['product_id' => $p->id, 'name' => $p->name, 'brand' => optional($p->brand)->name, 'quantity' => $q,
            'unit_price' => $unit, 'subtotal' => $unit * $q, 'total' => $unit * $q, 'tax_total' => 0];
    }
    $ship = $sub >= 19900 ? 0 : 2000;
    $o = Order::create(['order_number' => (string) $num, 'customer_id' => $me->id, 'email' => $me->email, 'phone' => $me->phone,
        'status' => $status, 'currency' => 'AED', 'billing_address' => $addr, 'shipping_address' => $addr,
        'subtotal' => $sub, 'discount_total' => 0, 'shipping_total' => $ship, 'fee_total' => 0, 'tax_total' => 0, 'total' => $sub + $ship,
        'shipping_method' => $ship ? 'Delivery Charges' : 'Free delivery', 'payment_method' => $pm, 'payment_method_title' => $pmt,
        'locale' => 'ar', 'paid_at' => $pm === 'cod' ? null : now()->subDays($ago), 'created_at' => now()->subDays($ago), 'updated_at' => now()->subDays($ago)]);
    foreach ($items as $it) {
        $o->items()->create($it);
    }
}
$me->forceFill(['orders_count' => 3, 'last_order_at' => now()->subDay()])->save();

/* ── A COUPON the basket can show ───────────────────────────────────────── */
/* ── SHOP BY SKIN CONCERN: the seven concern collections its chips link to, and
   the carousel layout with arrows on both devices, so the arrows can be checked. */
$concerns = [
    'pores' => ['Pore Care', 'المسام الواسعة/الانسداد'], 'dark-spots' => ['Dark Spots', 'التصبغات/عدم توحد اللون'],
    'acne-care' => ['Acne & Blemish Care', 'حب الشباب'], 'anti-aging' => ['Anti-aging', 'مكافحة علامات التقدم في السن'],
    'hydration' => ['Hydration & Glow', 'البشرة الجافة والمجففة'], 'sensitive-skin' => ['Redness & Sensitive Skin', 'الاحمرار/الحساسية'],
    'oily-skin' => ['Oily Skin', 'البشرة الدهنية والشوائب'],
];
foreach ($concerns as $slug => [$en, $arName]) {
    $c = Category::updateOrCreate(['slug' => $slug], ['name' => $en]);
    $ar('categories', $c->id, 'name', $arName, $en);
    $c->products()->syncWithoutDetaching(array_slice($ids, crc32($slug) % 20, 4));
}
/* The cart page in its Squeezed layout with the recommended rail, so the rail's
   arrows can be seen working in Arabic (Appearance → Cart page → Layout). */
$sv->set('store_name', 'K-Beauty Bliss'); // as on the live shop
$sv->set('cartpage_layout', 'squeeze');
$sv->set('cartpage_rec_ids', json_encode(array_slice($ids, 1, 10)));
foreach (['home_cc_layout_d' => 'scroll', 'home_cc_layout_m' => 'scroll', 'home_cc_arrows_m' => '1'] as $k => $v) {
    $sv->set($k, $v);
}

$glow = \App\Models\Coupon::updateOrCreate(['code' => 'GLOW10'], ['type' => 'percent', 'amount' => 1000, 'description' => 'Preview coupon']);
// The cart panel's coupon hint, chosen (Appearance → Cart panel → Coupon hint).
$sv->set('cartpanel_coupon_id', (string) $glow->id);
/* Free delivery from AED 1,000 IN THE PREVIEW ONLY, so the seven-line basket
   (AED 828) shows the free-delivery bar partly filled -- the owner asked to see
   that bar fill from the right. The shop's own threshold is AED 199. */
\App\Models\ShippingMethod::query()->where('type', 'free_shipping')->where('min_amount', 19900)->update(['min_amount' => 100000]);

/* ── THE OWNER'S 15 OCTOBER DECISION: NOTHING IN LATIN LETTERS ──────────────
 * tools/arr-arabic.php: brand and product names, Latin-free copy, Arabic
 * reviews, brand menu labels, and the shop/payment names inside the published
 * interface strings. Written last, so it supersedes the rows written above. */
$AR = require __DIR__.'/arr-arabic.php';
foreach ($AR['brands'] as $slug => [$name, $desc]) {
    $b = Brand::where('slug', $slug)->first();
    if ($b === null) {
        continue;
    }
    $ar('brands', $b->id, 'name', $name, $b->name);
    if ($desc !== null) {
        $ar('brands', $b->id, 'description', $desc, (string) $b->description);
    }
}
foreach ($AR['products'] as $i => [$name, $short]) {
    if (! isset($ids[$i])) {
        continue;
    }
    $p = Product::find($ids[$i]);
    $ar('products', $p->id, 'name', $name, $p->name);
    $ar('products', $p->id, 'short_description', $short, (string) $p->short_description);
}
$ar('products', $set->id, 'name', $AR['set'][0], $set->name);
$ar('products', $set->id, 'description', '<p>المنتجات التي تكمّل بعضها، في علبة واحدة.</p>', (string) $set->description);
$ar('products', $set->id, 'short_description', $AR['set'][1], (string) $set->short_description);
foreach ($AR['full'] as $i => $f) {
    $p = Product::find($ids[$i]);
    $ar('products', $p->id, 'short_description', $f['short'], (string) $p->short_description);
    $ar('products', $p->id, 'description', $f['desc'], (string) $p->description);
    $ar('products', $p->id, 'ingredients', $f['ing'], (string) $p->ingredients);
}
Review::query()->where('product_id', $hero->id)->delete();
foreach ($AR['reviews'] as $n => [$who, $stars, $title, $body]) {
    Review::create(['product_id' => $hero->id, 'author_name' => $who, 'author_email' => 'r'.$n.'@preview.test', 'rating' => $stars,
        'title' => $title, 'content' => $body, 'status' => 'approved', 'verified' => true, 'helpful' => 3 - min($n, 3),
        'created_at' => now()->subDays(3 + $n * 9)]);
}
\App\Support\ProductRating::refresh([$hero->id]);
foreach (MenuItem::query()->get() as $mi) {
    if (isset($AR['menu'][$mi->label])) {
        $ar('menu_items', $mi->id, 'label', $AR['menu'][$mi->label], $mi->label);
    }
}
$uiChanged = 0;
foreach (Translation::query()->where('locale', 'ar')->where('group', Translation::GROUP_UI)->where('status', $PUB)->get() as $row) {
    $v = (string) $row->value;
    $new = $v;
    foreach ($AR['ui_words'] as $from => $to) {
        // Whole words only, never inside a hashtag, a :placeholder or an address.
        $new = preg_replace('/(?<![#:\/\w.@-])'.preg_quote($from, '/').'(?![\w@-]|\.\w)/u', $to, $new);
    }
    if ($new !== $v) {
        $row->value = $new;
        $row->save();
        $uiChanged++;
    }
}
echo "ui rows with Latin brand words rewritten: {$uiChanged}\n";

/* The orders were placed on /ar: their lines carry the Arabic name, as
   App\Support\OrderLocale writes it at checkout. */
TranslationStore::flush();
foreach (\App\Models\OrderItem::query()->get() as $line) {
    $arName = $line->product_id ? collect($AR['products'])->get(array_search($line->product_id, $ids, true))[0] ?? null : null;
    if ($arName !== null) {
        $line->forceFill(['name_localised' => $arName])->save();
    }
}

\App\Support\SearchSpelling::flush();
TranslationStore::flush();
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
$sv->flush();

file_put_contents(base_path('storage/ar-logs/preview-ids.json'), json_encode([
    'hero' => $hero->slug, 'cream' => Product::find($ids[17])->slug, 'sun' => Product::find($ids[21])->slug,
    'ids' => $ids, 'post' => $post->slug, 'set' => $set->slug,
    'orders' => Order::query()->where('customer_id', $me->id)->pluck('id'), 'address' => $home->id,
], JSON_PRETTY_PRINT));
echo 'arr-seed: products ', count($ids), ', published ar rows ', Translation::query()->where('locale', 'ar')->where('status', $PUB)->count(), "\n";
