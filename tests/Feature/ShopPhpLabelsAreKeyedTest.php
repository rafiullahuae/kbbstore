<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\Facets;
use App\Support\Locale;

/**
 * Lane FB — the storefront labels that render from PHP, and stayed English on
 * an Arabic page after every Blade file had been converted.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * Lane EU converted 94 Blade templates and stopped at its stated boundary: a
 * string inside a .blade.php file. These are the ones that are not, and they
 * are not decoration — they are the shop's filter and sort controls:
 *
 *   App\Support\Facets::SORTS      seven sort options, 'Featured' to 'Name A–Z'
 *   App\Support\Facets::BUCKETS    four price bands
 *   ShopController::chips()        'On sale' and 'In stock'
 *   ShopController::heading()      'Shop all', its subtitle, and three crumbs
 *   ShopController::breadcrumbTrail()  'Home' and 'Shop', into BreadcrumbList
 *
 * An Arabic shopper on /ar/shop/ got an Arabic page around an English control
 * strip: the sort select, every price band, and the chips naming the filters
 * they had just applied.
 *
 * ── WHY A CONST COULD NOT SIMPLY BE WRAPPED ─────────────────────────────────
 *
 * SORTS and BUCKETS are `const`. A constant expression cannot call __(), and
 * even if it could it would be resolved once per process and then be wrong for
 * the second request — the Setting::map() trap CLAUDE.md records, in a new
 * place. So the constants stay exactly as they are (they are also the English
 * source, and BUCKETS carries the filter's own min/max bounds beside the
 * label), and the shopper's wording comes from sortLabels() / bucketLabels(),
 * resolved per request.
 *
 * ── THE LABEL-VERSUS-VALUE HAZARD, WHICH THIS PAIR DOES NOT HAVE ────────────
 *
 * Translating a string that is also compared against breaks the thing it
 * labels — store/blog.blade.php has exactly that defect and is reported rather
 * than fixed by this lane. These two are safe, and the last case below is what
 * says so rather than assuming it: every comparison in Facets::sort() and in
 * ShopController is against the ARRAY KEY ('plow', 'u54'), never the label, so
 * the label is free to change language. That case fails if anyone ever routes a
 * comparison through the label.
 */
function fbArabicOn(): void
{
    Setting::query()->updateOrCreate(
        ['key' => Locale::SETTING_ENABLED],
        ['value' => '1', 'autoload' => true]
    );

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** One published Arabic string, written the way the admin screen writes it. */
function fbArabic(string $key, string $value): void
{
    TranslationStore::put(
        'ar',
        'ui',
        0,
        $key,
        $value,
        Translation::STATUS_PUBLISHED,
        Translation::SOURCE_MANUAL,
    );
}

it('says the sort options in the shopper\'s language', function () {
    fbArabicOn();

    fbArabic('store.shop.sort_plow', 'السعر: من الأقل');
    fbArabic('store.shop.sort_rating', 'الأعلى تقييماً');

    $html = $this->get('/ar/shop/')->assertOk()->getContent();

    expect($html)
        ->toContain('السعر: من الأقل')
        ->and($html)->toContain('الأعلى تقييماً')
        ->and($html)->not->toContain('>Price: low to high<')
        ->and($html)->not->toContain('>Top rated<');
});

it('says the price bands in the shopper\'s language', function () {
    fbArabicOn();

    fbArabic('store.shop.price_u54', 'أقل من ٥٤');

    $html = $this->get('/ar/shop/')->assertOk()->getContent();

    expect($html)
        ->toContain('أقل من ٥٤')
        ->and($html)->not->toContain('Under AED 54');
});

it('names an applied filter in the shopper\'s language on the chip that removes it', function () {
    /*
     * The chips are the worst of the group to leave in English: they name the
     * filter the shopper just applied, and they are the control for undoing it.
     */
    fbArabicOn();

    fbArabic('store.shop.chip_on_sale', 'عروض');
    fbArabic('store.shop.chip_in_stock', 'متوفر');

    $html = $this->get('/ar/shop/?sale=1&instock=1')->assertOk()->getContent();

    expect($html)
        ->toContain('عروض')
        ->and($html)->toContain('متوفر')
        ->and($html)->not->toContain('>On sale<')
        ->and($html)->not->toContain('>In stock<');
});

it('says the listing\'s own heading and crumb in the shopper\'s language', function () {
    fbArabicOn();

    fbArabic('store.shop.title_all', 'كل المنتجات');
    fbArabic('store.shop.sub_default', 'عناية كورية أصلية.');
    fbArabic('store.breadcrumb.shop', 'المتجر');

    $html = $this->get('/ar/shop/')->assertOk()->getContent();

    expect($html)
        ->toContain('كل المنتجات')
        ->and($html)->toContain('عناية كورية أصلية.')
        ->and($html)->toContain('المتجر')
        ->and($html)->not->toContain('>Shop all<')
        ->and($html)->not->toContain('Authentic Korean skincare, curated for the UAE.');
});

it('carries the shopper\'s language into the breadcrumb schema as well as the page', function () {
    /*
     * breadcrumbTrail() feeds BreadcrumbList JSON-LD, which is what Google
     * prints under an Arabic result. Leaving it English does not show on the
     * page, which is exactly why it would have stayed English.
     */
    fbArabicOn();

    fbArabic('store.breadcrumb.home', 'الرئيسية');
    fbArabic('store.breadcrumb.shop', 'المتجر');

    $html = $this->get('/ar/shop/')->assertOk()->getContent();

    expect($html)->toContain('BreadcrumbList');

    /*
     * The trail inside the JSON-LD, not merely somewhere on the page — the
     * visible crumb says "Home" too, so an unscoped assertion would pass on a
     * page whose schema was still entirely English.
     *
     * preg_match_ALL: the page emits several ld+json blocks (Organization,
     * WebSite, BreadcrumbList) and the trail is not the first. And the encoder
     * sets JSON_HEX_QUOT, so a quote is \u0022 and the English would read
     * \u0022name\u0022:\u0022Home\u0022 — matched here in the shape it is
     * actually written in, not the shape it would take unescaped.
     */
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

    $jsonLd = implode(' ', $m[1]);

    expect($jsonLd)->toContain('BreadcrumbList')
        ->and($jsonLd)->toContain('الرئيسية')
        ->and($jsonLd)->toContain('المتجر')
        ->and($jsonLd)->not->toContain('Home')
        ->and($jsonLd)->not->toContain('Shop');
});

it('keeps an English source in the strings table for every label the constants carry', function () {
    /*
     * THE DRIFT GUARD. The constants remain the English source and the bounds;
     * InterfaceStrings holds the wording that is actually rendered. Two copies
     * of one sentence drift, and the way this pair would drift is silent: the
     * English changes in Facets, the Arabic goes on being a translation of the
     * sentence that used to be there.
     */
    $missing = [];

    foreach (Facets::SORTS as $key => $english) {
        $stringKey = 'store.shop.sort_' . $key;

        if (InterfaceStrings::english($stringKey) !== $english) {
            $missing[$stringKey] = $english;
        }
    }

    foreach (Facets::BUCKETS as $key => [$english, , ]) {
        $stringKey = 'store.shop.price_' . str_replace('-', '_', $key);

        if (InterfaceStrings::english($stringKey) !== $english) {
            $missing[$stringKey] = $english;
        }
    }

    expect($missing)->toBe([], sprintf(
        "A label in Facets has no matching English in InterfaceStrings:\n\n%s\n\n"
        . 'The constant is the English source; the strings table is what renders. '
        . 'They have to say the same thing or the translation is of a sentence that '
        . 'is no longer on the page.',
        implode("\n", array_map(
            fn (string $k, string $v): string => "  {$k} => \"{$v}\"",
            array_keys($missing),
            $missing
        ))
    ));
});

it('compares sort and price filters by their key, never by their label', function () {
    /*
     * The property that makes translating these safe at all — and the one the
     * Journal's tag filter does not have. Every sort key must still select, in
     * a language where no label is the English the code was written against.
     */
    fbArabicOn();

    foreach (array_keys(Facets::SORTS) as $orderby) {
        fbArabic('store.shop.sort_' . $orderby, 'ترتيب-' . $orderby);
    }

    foreach (array_keys(Facets::SORTS) as $orderby) {
        $this->get('/ar/shop/?orderby=' . $orderby)
            ->assertOk()
            // The chosen option is still the chosen one: selected by key, with
            // a label that is now Arabic.
            ->assertSee('ترتيب-' . $orderby, false);
    }

    foreach (array_keys(Facets::BUCKETS) as $bucket) {
        $this->get('/ar/shop/?price=' . $bucket)->assertOk();
    }
});
