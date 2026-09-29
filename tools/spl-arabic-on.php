<?php
/*
 * Switch the Lane SPL preview into Arabic, and publish the set's own words.
 *
 * ── WHY THIS IS A SECOND STEP AND NOT PART OF tools/spl-seed.php ───────────
 *
 * Turning Arabic on is not invisible to the ENGLISH pages: App\Support\Locale::
 * enabledCodes() is what the hreflang tags and the language switcher read, so a
 * shop with Arabic on renders a switcher in its header and a pair of <link
 * rel="alternate"> in its <head> that a shop with Arabic off does not. The
 * eight English screenshots are the ones the owner is choosing a layout from,
 * and they should show the chrome this shop has TODAY — so they are taken
 * first, against a fixture with Arabic off, and this runs afterwards.
 *
 * ── WHAT IS TRANSLATED, AND WHAT DELIBERATELY IS NOT ───────────────────────
 *
 * The product's own name, blurb and story, and its three members' names —
 * because the thing the Arabic pair has to show is a page of Arabic WORDS with
 * a mirrored geometry, and a page of English words in a right-to-left column
 * proves only half of it.
 *
 * The four preview banners stay English. They are this lane's own chrome, they
 * name the four designs by their English letters, and they are deleted the day
 * the owner picks one. Translating a label that exists to be thrown away would
 * put four throwaway sentences into a table the owner is paying a human
 * translator to fill.
 */

/* Arabic on, and every memo told. This application memoises settings in three
   places that do not know about each other — Setting::map()'s process-level
   static (the trap CLAUDE.md records), SettingsService' own memo and cache, and
   TranslationStore's — and a script that writes the row and flushes only one of
   them leaves a request that still believes the shop is English. The failure
   then looks like a missing translation rather than a stale memo.
   Tests\Support\ArabicShop::on() does exactly this for the suite; it is
   spelled out here rather than called because the test autoloader is not
   something a preview harness should depend on. */
foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $kbbKey) {
    /* BOTH ROWS, and the second is the one that is easy to miss. SETTING_ENABLED
       alone makes /ar exist and sets <html lang="ar"> — and leaves
       dir="ltr", because App\Support\Locale::direction() reads
       `language_rtl_enabled` separately. The first Arabic shot of this lane
       came back in English-looking left-to-right geometry with lang="ar" on it,
       which is exactly the page that proves nothing about a mirrored layout. */
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => $kbbKey],
        ['value' => '1', 'autoload' => true]
    );
}
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();

$set = \App\Models\Product::where('slug', 'spl-glow-ritual-set')->firstOrFail();

$arabic = [
    'spl-glow-ritual-set' => [
        'name' => 'طقم الإشراق اليومي',
        'short_description' => 'ثلاث خطوات في علبة واحدة — نظّفي، هدّئي الاحمرار، ثم اختمي بالترطيب.',
        'description' => '<p>التونر الذي استغرق عاماً كاملاً لإعادة تركيبه، والسيروم الذي يطلب الجميع إعادة توفيره، '
            .'والكريم الذي يجعل الاثنين يدومان حتى الصباح. معبّأ في علبة هدايا جاهزة.</p>',
    ],
    'spl-heartleaf-toner' => ['name' => 'تونر هارتليف المهدّئ ٧٧٪ ٢٥٠ مل'],
    'spl-relief-serum' => ['name' => 'سيروم ريليف صن بالأرز والبروبيوتيك ٥٠ مل'],
    'spl-birch-cream' => ['name' => 'كريم عصير البتولا المرطّب ٨٠ مل'],
    'spl-revive-eye' => ['name' => 'سيروم العين ريفايف بالجينسنغ والريتينال ٣٠ مل'],
    'spl-peach-mask' => ['name' => 'قناع النوم بالخوخ ٧٧٪ والنياسيناميد ١٠٠ مل'],
    'spl-dokdo-oil' => ['name' => 'زيت دوكدو المنظّف ٢٠٠ مل'],
    'spl-green-plum' => ['name' => 'غسول البرقوق الأخضر المنعش ١٥٠ مل'],
    'spl-apricot-gel' => ['name' => 'جل المشمش المقشّر اللطيف ١٢٠ مل'],
    'spl-starter-duo-set' => [
        'name' => 'الثلاثية الأولى',
        'short_description' => 'الثلاثة التي يبدأ بها الجميع.',
    ],
];

/* ── THE UI STRINGS THIS PAGE PRINTS ────────────────────────────────────────
   App\Services\Translation\InterfaceStrings carries the ENGLISH source for every
   key and no Arabic at all — the Arabic is typed by the owner on Content →
   Translations and published from there. So a fixture that enables Arabic and
   stops gets a perfectly mirrored page of English words, which shows the
   geometry and nothing about the language. These are the keys these four
   drawings actually print. They are fixture data for a screenshot and are NOT
   a translation of the shop: nothing here writes to the repository. */
foreach ([
    'store.set.page_eyebrow' => 'الطقم',
    'store.set.show_all' => 'عرض :count منتج آخر|عرض :count منتجات أخرى',
    'store.set.show_fewer' => 'عرض أقل',
    'store.set.page_heading' => 'ما الذي يحتويه الطقم',
    'store.set.page_separately' => 'السعر منفصلاً',
    'store.set.page_set_price' => 'سعر الطقم',
    'store.set.saving' => 'توفّر :amount',
    'store.set.whats_inside' => 'ما بالداخل',
    'store.set.contents' => 'في هذا الطقم · :count منتج|في هذا الطقم · :count منتجات',
    'store.set.count_note' => ':count منتج|:count منتجات',
    'store.product.stock_in' => 'متوفر · جاهز للشحن',
    'store.product.buy_now' => 'اشترِ الآن',
    'store.product.details_eyebrow' => 'التفاصيل',
    'store.product.details_heading' => 'تفاصيل المنتج',
    'store.product.tab_how_to_use' => 'طريقة الاستخدام',
    'store.product.trust_pay_later' => 'تابي وتمارا',
    'store.product_card.add_to_cart' => 'أضف إلى السلة',
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
   English column with no sign that anything was typed — which is what the first
   Arabic shot of this lane showed. */
foreach ($arabic as $slug => $fields) {
    $product = \App\Models\Product::where('slug', $slug)->first();

    if ($product === null) {
        continue;
    }

    foreach ($fields as $field => $value) {
        /* PUBLISHED, not the default draft: the storefront reads published
           rows only, and a draft would render the English column with no sign
           that anything had been typed. */
        \App\Services\Translation\TranslationStore::put(
            'ar', $product->translationGroup(), $product->id, $field, $value,
            \App\Models\Translation::STATUS_PUBLISHED
        );
    }
}

\App\Services\Translation\TranslationStore::flush();

echo "arabic on, set #{$set->id} translated\n";
