<?php
/*
 * Switch the Lane PDP preview into Arabic, and give the fixture Arabic words.
 *
 * ── WHY THIS IS A SECOND STEP AND NOT PART OF tools/pdp-seed.php ───────────
 *
 * Turning Arabic on is not invisible to the ENGLISH pages:
 * App\Support\Locale::enabledCodes() is what the hreflang tags and the language
 * switcher read, so a shop with Arabic on renders a switcher in its header and
 * a pair of <link rel="alternate"> in its <head> that a shop with Arabic off
 * does not. The ten English drawings are the ones the owner is choosing from,
 * and they should show the chrome this shop has TODAY — so they are taken
 * first, against a fixture with Arabic off, and this runs afterwards.
 *
 * ── BOTH SETTING ROWS, AND THE SECOND IS THE ONE THAT IS EASY TO MISS ──────
 *
 * SETTING_ENABLED alone makes Arabic exist and sets <html lang="ar"> — and
 * leaves dir="ltr", because App\Support\Locale::direction() reads
 * `language_rtl_enabled` separately. An Arabic shot in left-to-right geometry
 * proves nothing about a mirrored layout, which is the only reason this lane
 * takes one: the tab row has to scroll the other way, the rating bar has to
 * fill from the right, and candidate C's segmented fill has to slide the other
 * way — all three by `inset-inline-start` and `inline-size` rather than by a
 * [dir] rule, so all three are claims that have to be photographed.
 *
 * ── AND EVERY MEMO HAS TO BE TOLD ─────────────────────────────────────────
 *
 * This application memoises settings in places that do not know about each
 * other — Setting::map()'s PROCESS-LEVEL static (the trap CLAUDE.md records),
 * SettingsService' own memo and cache, and TranslationStore's. A script that
 * writes the row and flushes only one of them leaves a request that still
 * believes the shop is English, and the failure then looks like a missing
 * translation rather than a stale memo.
 */
foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $kbbKey) {
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => $kbbKey],
        ['value' => '1', 'autoload' => true]
    );
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();

/* ── THE UI STRINGS THESE FIVE DRAWINGS ACTUALLY PRINT ─────────────────────
 *
 * App\Services\Translation\InterfaceStrings carries the ENGLISH source for
 * every key and no Arabic at all — the Arabic is typed by the owner on
 * Content → Translations and published from there. A fixture that enables
 * Arabic and stops gets a perfectly mirrored page of ENGLISH words, which shows
 * the geometry and nothing about the language.
 *
 * These are fixture data for a screenshot. Nothing here writes to the
 * repository and nothing here is a translation of the shop.
 */
foreach ([
    'store.product.stock_in' => 'متوفر · جاهز للشحن',
    'store.product.stock_sold_out' => 'نفدت الكمية',
    'store.product.choose_option' => 'اختر الحجم',
    'store.product.read_more' => 'اقرأ المزيد',
    'store.product.tab_description' => 'الوصف',
    'store.product.tab_ingredients' => 'المكوّنات',
    'store.product.tab_how_to_use' => 'طريقة الاستخدام',
    'store.product.review_badge_label' => '{n} تقييم',
    'store.product_card.add_to_cart' => 'أضف إلى السلة',
    'store.footer.pay_cod' => 'الدفع عند الاستلام',
    'store.breadcrumb.home' => 'الرئيسية',
    'store.breadcrumb.shop' => 'المتجر',
] as $kbbKey => $kbbValue) {
    \App\Services\Translation\TranslationStore::put(
        'ar', 'ui', 0, $kbbKey, $kbbValue,
        \App\Models\Translation::STATUS_PUBLISHED
    );
}

/* translationGroup() and not the literal 'product': App\Support\HasTranslations
   keys the table by the model's TABLE name, so the group is `products`. Written
   as 'product' the rows land in a group nothing reads and the page renders the
   English column with no sign that anything was typed. */
$arabic = [
    'pdp-heartleaf-toner' => [
        'name' => 'تونر هارتليف المهدّئ ٧٧٪ ٢٥٠ مل',
        'short_description' => 'تونر يومي لطيف مبني على خلاصة الهارتليف بنسبة ٧٧٪، '
            .'صُمّم للبشرة التي تتفاعل مع كل شيء. يهدّئ الاحمرار، ويفكّك ما ترسّب في '
            .'المسام طوال الليل، ويترك حاجز البشرة كما وجده — بلا كحول، وبلا عطر '
            .'مضاف، وبلا زيوت عطرية. استخدميه صباحاً ومساءً على بشرة رطبة، قبل '
            .'السيروم، وامنحيه ثلاثين ثانية ليتشرّب قبل الخطوة التالية.',
        'ingredients' => '<p>خلاصة الهارتليف ٧٧٪، ماء، بيوتيلين غلايكول، غليسرين، '
            .'بانثينول، بيتايين، هيالورونات الصوديوم، ألانتوين، ماديكاسوسايد، '
            .'خلاصة السنتيلا الآسيوية.</p><p>خالٍ من الكحول والعطر المضاف والزيوت '
            .'العطرية واللون الصناعي.</p>',
        'how_to_use' => '<ol><li>نظّفي البشرة واتركيها رطبة لا جافة.</li>'
            .'<li>ضعي ضختين أو ثلاثاً في راحة اليد.</li>'
            .'<li>اضغطيه على البشرة بدل مسحه.</li>'
            .'<li>انتظري ثلاثين ثانية ثم أتبعيه بالسيروم والمرطّب.</li></ol>',
    ],
    'pdp-glow-ritual-set' => [
        'name' => 'طقم الإشراق اليومي',
        'short_description' => 'ثلاث خطوات وأربع دقائق تقريباً: غسول الأرز، وإسانس '
            .'الجينسنغ، وواقي الشمس — في علبة واحدة بأقل من سعرها منفصلة.',
    ],
    'pdp-member-cleanser' => ['name' => 'غسول البرقوق الأخضر المنعش ١٥٠ مل'],
    'pdp-member-essence' => ['name' => 'إسانس الجينسنغ ١٥٠ مل'],
    'pdp-member-sun' => ['name' => 'واقي الشمس بالأرز والبروبيوتيك SPF50+'],
    'pdp-sold-out-serum' => ['name' => 'سيروم حمض الأزيليك ١٠٪ ٣٠ مل'],
];

foreach ($arabic as $slug => $fields) {
    $product = \App\Models\Product::where('slug', $slug)->first();

    if ($product === null) {
        continue;
    }

    foreach ($fields as $field => $value) {
        /* PUBLISHED, not the default draft: the storefront reads published rows
           only, and a draft would render the English column with no sign that
           anything had been typed. */
        \App\Services\Translation\TranslationStore::put(
            'ar', $product->translationGroup(), $product->id, $field, $value,
            \App\Models\Translation::STATUS_PUBLISHED
        );
    }
}

/* The two GLOBAL tabs, which are rows in `product_tabs` rather than columns on a
   product — App\Support\ProductTabs::translated() reads them out of the same
   store under the tabs table's own group. Without these the Arabic tab row
   reads three Arabic titles and two English ones, which is a worse picture than
   five English ones. */
foreach ([
    'Shipping & returns' => 'الشحن والإرجاع',
    'Authenticity' => 'ضمان الأصالة',
] as $english => $ar) {
    $tab = \App\Models\ProductTab::whereNull('product_id')->where('title', $english)->first();

    if ($tab !== null) {
        \App\Services\Translation\TranslationStore::put(
            'ar', $tab->translationGroup(), $tab->id, 'title', $ar,
            \App\Models\Translation::STATUS_PUBLISHED
        );
    }
}

\App\Services\Translation\TranslationStore::flush();

echo "pdp arabic on\n";
