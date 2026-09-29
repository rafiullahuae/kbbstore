<?php
/*
 * Switch the Lane PG2 preview into Arabic, and give the twelve fixture products
 * Arabic names.
 *
 * ── WHY THIS IS A SECOND STEP AND NOT PART OF tools/pg2-seed.php ───────────
 *
 * Turning Arabic on is not invisible to the ENGLISH pages: Locale::
 * enabledCodes() is what the hreflang tags and the language switcher read, so a
 * shop with Arabic on renders a switcher in its header that a shop with Arabic
 * off does not. The contact sheets are what the owner is choosing a card from
 * and they should show the chrome this shop has TODAY, so they are taken first.
 *
 * ── WHAT THE ARABIC PAIR IS ACTUALLY FOR ───────────────────────────────────
 *
 * The showcase family is laid out on the LOGICAL axis: `inset-inline-end` for
 * the heart, `padding` and `margin-inline-end` for the button, `text-align:
 * start` for the column. There is no `[dir]` selector in it. So what the shot
 * has to show is the same card mirrored — the heart on the left of the button,
 * the eyebrow and the name right-aligned, the struck price before the sale
 * price in reading order — and that is only visible with Arabic WORDS in it. A
 * mirrored page of English proves half of it.
 */
foreach ([\App\Support\Locale::SETTING_ENABLED, \App\Support\Locale::SETTING_RTL] as $kbbKey) {
    /* BOTH ROWS. SETTING_ENABLED alone makes /ar exist and sets
       <html lang="ar"> and leaves dir="ltr", because Locale::direction() reads
       `language_rtl_enabled` separately — which is the page that proves nothing
       about a mirrored layout. */
    \App\Models\Setting::query()->updateOrCreate(
        ['key' => $kbbKey],
        ['value' => '1', 'autoload' => true]
    );
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
\App\Services\Translation\TranslationStore::flush();

/* The card's own two strings. InterfaceStrings carries the English source and
   no Arabic at all — the Arabic is typed by the owner on Content →
   Translations — so a fixture that enables Arabic and stops gets a perfectly
   mirrored page of English words. These are fixture data for a screenshot and
   are NOT a translation of the shop: nothing here writes to the repository. */
foreach ([
    'store.product_card.add_to_cart' => 'أضف إلى السلة',
    'store.product_card.view_product' => 'عرض المنتج',
    'store.product_card.quick_view' => 'عرض سريع',
    'store.product_card.save_label' => 'حفظ',
    'store.breadcrumb.home' => 'الرئيسية',
    'store.breadcrumb.shop' => 'المتجر',
] as $kbbKey => $kbbValue) {
    \App\Services\Translation\TranslationStore::put(
        'ar', 'ui', 0, $kbbKey, $kbbValue,
        \App\Models\Translation::STATUS_PUBLISHED
    );
}

/* translationGroup() and not the literal 'product': HasTranslations keys the
   table by the model's TABLE name, so the group is `products`. Written as
   'product' the rows land in a group nothing reads and the page renders the
   English column with no sign that anything was typed. */
$names = [
    'pg2-relief-sun' => 'سيروم ريليف صن بالأرز والبروبيوتيك ٥٠ مل',
    'pg2-glow-serum' => 'سيروم جلو ديب بالأرز وألفا أربوتين',
    'pg2-toner' => 'تونر',
    'pg2-barrier-cream' => 'كريم إصلاح حاجز البشرة',
    'pg2-night-cream' => 'كريم ليلي فائق الترطيب بالسيراميد والبانثينول والسكوالين ١٠٠ مل لإصلاح حاجز البشرة',
    'pg2-cleansing-oil' => 'زيت الجينسنغ المنظّف ٢١٠ مل',
    'pg2-eye-serum' => 'سيروم العين ريفايف بالجينسنغ والريتينال',
    'pg2-peach-mask' => 'قناع النوم بالخوخ ٧٧٪ والنياسيناميد',
    'pg2-booster-set' => 'طقم ميديكيوب البوستر',
    'pg2-green-plum' => 'غسول البرقوق الأخضر المنعش ١٥٠ مل',
    'pg2-apricot-gel' => 'جل المشمش المقشّر اللطيف ١٢٠ مل',
    'pg2-no-photo' => 'أمبولة السنتيلا ١٠٠ مل',
];

foreach ($names as $slug => $arabic) {
    $product = \App\Models\Product::where('slug', $slug)->first();

    if ($product === null) {
        continue;
    }

    /* PUBLISHED, not the default draft: the storefront reads published rows
       only, and a draft renders the English column with no sign that anything
       was typed. */
    \App\Services\Translation\TranslationStore::put(
        'ar', $product->translationGroup(), $product->id, 'name', $arabic,
        \App\Models\Translation::STATUS_PUBLISHED
    );
}

$category = \App\Models\Category::where('slug', 'skincare-sets')->first();

if ($category !== null) {
    \App\Services\Translation\TranslationStore::put(
        'ar', $category->translationGroup(), $category->id, 'name', 'أطقم العناية بالبشرة',
        \App\Models\Translation::STATUS_PUBLISHED
    );
}

\App\Services\Translation\TranslationStore::flush();

echo "arabic on, ".count($names)." product names translated\n";
