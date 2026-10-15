<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\CartPanel;
use App\Services\SettingsService;
use App\Services\Translation\ArabicDraftCorrections;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Support\ArabicWording;
use App\Support\HomeArabic;
use App\Support\HomeSections;
use App\Support\LocalDate;
use App\Support\Locale;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Lane AR round 2 — the Arabic RTL first draft the owner asked to see:
 * "every single thing RTL for arabic ... without any messed up, in any
 * sections, anywhere, including the arrows" and, on 15 October, "nothing
 * should be in english".
 *
 * One case per CLASS of defect found walking every /ar page at 390 and 1280
 * beside its English twin (docs/lane-ar-shots/round2/). Each comment says what
 * the defect looked like on the shop, and each ends with the mutation that
 * turns it red. English is the other half of every case: whatever /ar gets, an
 * English page must not move a byte (StorefrontEnglishUnchangedTest).
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

afterEach(function () {
    app()->setLocale('en');
});

function rtl2Css(string $file): string
{
    return (string) file_get_contents(resource_path('css/kbb/'.$file));
}

function rtl2Built(string $entry): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/'.$entry]['file']));
}

/*
 * 1. LIST BULLETS HUNG OFF THE RIGHT EDGE. `.dcontent ul{margin:6px 0 0 18px}`
 *    is a physical LEFT margin: in Arabic the bullets sat on the right with no
 *    indent, flush against the panel edge, and the description text touched it.
 *    A margin-left reader misses it because it is a four-value shorthand.
 *    Mutation: put `margin:6px 0 0 18px` back in either file and this is red.
 */
it('indents product description lists from the inline start, in source and in the built bundle', function () {
    foreach (['kbb.css', 'kbb-product.css'] as $file) {
        expect(rtl2Css($file))->not->toContain('ul{margin:6px 0 0 18px')
            ->and(rtl2Css($file))->toContain('.dcontent ul{margin:6px 0 0;margin-inline-start:18px}');
    }
    expect(rtl2Css('kbb-product.css'))->toContain('.acc-b-in ul{margin:6px 0 0;margin-inline-start:18px}')
        ->and(rtl2Css('kbb.css'))->toContain('.authside ul{margin:0;padding:0;padding-inline-start:17px');

    // The shop serves public/build, not resources: a fix that was never built ships inert.
    expect(rtl2Built('kbb.css'))->toContain('margin-inline-start:18px')
        ->and(rtl2Built('kbb-product.css'))->not->toContain('margin:6px 0 0 18px');
});

/*
 * 2. LATIN TEXT IN AN ARABIC CARD TOOK THE CARD'S DIRECTION.
 *    "345 Relief Cream Mist Spray" painted as "Relief Cream Mist 345 Spray";
 *    a clamped name ended "…Ultimate Glass" with the ellipsis at its START; an
 *    English review read ".Sarah K" and "...ry soaked so I use half".
 *    `unicode-bidi: plaintext` gives each line its own first letter's
 *    direction — an Arabic line is untouched. Scoped to [dir="rtl"].
 *    Mutation: drop the rule, or its [dir="rtl"] scope, and this is red.
 */
it('gives an untranslated Latin name or review its own direction, only in the Arabic document', function () {
    $css = rtl2Css('kbb.css');

    expect($css)->toContain('[dir="rtl"] .kbb-card-nm{unicode-bidi:plaintext}')
        ->toContain('[dir="rtl"] .sr-nm,[dir="rtl"] .sr-title,[dir="rtl"] .sr-tx,[dir="rtl"] .sr-reply{unicode-bidi:plaintext}')
        // the card's second copy carries the same rule (GridSkinCopiesTest)
        ->and(rtl2Css('kbb-grid-skins.css'))->toContain('[dir="rtl"] .kbb-card-nm{unicode-bidi:plaintext}')
        ->and(preg_match('/(^|})\s*\.kbb-card-nm\s*\{[^}]*unicode-bidi/', $css))->toBe(0);
});

/*
 * 3. THE TRUST STRIP RAN ITS ARABIC INTO THE NEXT COLUMN at 390px:
 *    "بطاقة، دفع عند الاستلامعلى مدار الساعة" — two columns' words touching.
 *    `.ktr b` is nowrap with a <br>, sized for English. Arabic wraps.
 *    Mutation: delete the :lang(ar) rule and this is red.
 */
it('lets the trust strip wrap in Arabic and only in Arabic', function () {
    expect(rtl2Css('kbb.css'))->toContain(':lang(ar) .ktr b{white-space:normal}')
        ->and(rtl2Built('kbb.css'))->toContain(':lang(ar) .ktr b{white-space:normal}');
});

/*
 * 4. THE RATING BARS FILLED FROM THE RIGHT BUT FADED THE WRONG WAY: the
 *    gradient's dark end sat at the start of the bar, so a 75% bar looked like
 *    it was emptying. Mutation: drop the [dir="rtl"] 270deg rule.
 */
it('mirrors the rating bar gradient with the bar', function () {
    expect(rtl2Css('sorina-reviews.css'))->toContain('[dir="rtl"] .sr-bf{background:linear-gradient(270deg,');
});

/*
 * 5. DATES WERE ENGLISH, AND REORDERED: "12 Oct 2026" painted "Oct 2026 12" on
 *    every review card; the orders list read "Oct 2026 · 3 6 منتجات".
 *    Mutation: return $date->format($format) for Arabic too.
 */
it('writes dates in Arabic on /ar and byte-identically in English', function () {
    $d = Carbon::parse('2026-10-17 10:00:00');

    expect(LocalDate::format($d, 'j M Y'))->toBe($d->format('j M Y'))
        ->and(LocalDate::format($d, 'D, j M'))->toBe('Sat, 17 Oct')
        ->and(LocalDate::format(null, 'j M Y', '—'))->toBe('—');

    app()->setLocale('ar');
    expect(LocalDate::format($d, 'j M Y'))->toBe('17 أكتوبر 2026')
        ->and(LocalDate::format($d, 'D, j M'))->toBe('السبت 17 أكتوبر')
        ->and(LocalDate::format($d, 'j F Y'))->not->toMatch('/[A-Za-z]/');
});

/*
 * 6. SETTINGS WORDING HAD NO ARABIC: "2-pack bundle" painted "pack bundle-2",
 *    "1–3 days fast delivery all over UAE" painted "...UAE 3–1", "100%
 *    authentic" painted "authentic 100%". A shipped default is now an interface
 *    string with an Arabic draft; the owner's own English stays his.
 *    Mutation: make of() translate on English, or drop the honesty rule.
 */
it('prints a shipped settings default in Arabic on /ar and leaves English and custom wording alone', function () {
    expect(ArabicWording::of('2-pack bundle'))->toBe('2-pack bundle');

    app()->setLocale('ar');
    expect(ArabicWording::of('2-pack bundle'))->toBe((string) __('store.wording.pack_2'))
        ->and(ArabicWording::of('My own tier name'))->toBe('My own tier name')
        ->and(ArabicWording::of(''))->toBe('');

    expect(array_keys(ArabicWording::ENGLISH))->toBe(array_keys(ArabicWording::ARABIC));

    $flat = InterfaceStrings::flat();
    $drafts = ArabicInterfaceDrafts::all();
    foreach (ArabicWording::ENGLISH as $key => $english) {
        expect($flat['store.'.$key] ?? null)->toBe($english)
            ->and($drafts['store.'.$key] ?? '')->not->toBe('');
    }
});

it('keeps every code-built sentence word for word in English', function () {
    expect((string) __('store.wording.view_of', ['base' => 'Anua Pad', 'n' => 2, 'total' => 4]))->toBe('Anua Pad, view 2 of 4')
        ->and((string) __('store.wording.vat_inclusive', ['rate' => '5']))->toBe('Inclusive of 5% VAT')
        ->and((string) __('store.wording.seo_shop', ['total' => 33]))->toBe('Browse every K-Beauty Bliss product — 33 authentic Korean skincare picks, from serums to beauty devices.');
});

/*
 * 7. "AED" ON THE ARABIC SHOP. The owner, by name: "AED" → "د.إ". Only the
 *    code-shaped default is swapped, only for the dirham, only on /ar.
 *    Mutation: drop the locale check and the English test is red.
 */
it('prints the dirham as د.إ on /ar and AED in English', function () {
    Money::forgetConfig();
    expect(Money::symbol())->toBe('AED');

    app()->setLocale('ar');
    expect(Money::symbol())->toBe('د.إ')
        ->and(Money::plain(19900, 0))->toContain('د.إ');
});

/*
 * 8. THE HOMEPAGE'S OWN WORDS WERE ENGLISH ON /ar ("Loved most", the hero).
 *    Mutation: return the English from HomeSections::text() on /ar.
 */
it('gives every homepage heading an Arabic twin with the honest fallback', function () {
    $c = HomeSections::settings();
    expect(HomeSections::text($c, 'home_bs_title'))->toBe('Best-Selling Korean Skincare in the UAE');

    app()->setLocale('ar');
    expect(HomeSections::text($c, 'home_bs_title'))->toBe(HomeArabic::TEXT['home_bs_title'])
        ->and(HomeSections::text(['home_bs_title' => 'My own heading'] + $c, 'home_bs_title'))->toBe('My own heading')
        ->and(HomeSections::text(['home_bs_title' => ''] + $c, 'home_bs_title'))->toBe('')
        ->and(HomeSections::text(['home_bs_title_ar' => 'عنواني'] + $c, 'home_bs_title'))->toBe('عنواني');

    foreach (array_keys(HomeArabic::SCHEMA) as $key) {
        expect(\App\Services\HomepageContent::SCHEMA)->toHaveKey($key);
    }
    $tabbed = array_merge(...array_values(array_map(fn ($t) => $t[2], \App\Services\HomepageContent::TABS)));
    expect(array_diff(array_keys(HomeArabic::SCHEMA), $tabbed))->toBe([]);
});

it('prints the cart panel words in Arabic on /ar only', function () {
    expect(app(CartPanel::class)->text('txt_subtotal'))->toBe('Subtotal');

    app()->setLocale('ar');
    expect(app(CartPanel::class)->text('txt_subtotal'))->toBe('المجموع الفرعي');
});

/*
 * 9. "#KBeautyBliss" PAINTED "KBeautyBliss#" in an Arabic line ("لحظات
 *    #KBeautyBliss", the Spotted heading and button). The `#` is a neutral and
 *    went to the Arabic side. Translated lines hold a Latin hashtag in an
 *    LRI…PDI isolate. Mutation: drop isolateHashtags() from the loader.
 */
it('holds a Latin hashtag left-to-right inside a translated line', function () {
    \Tests\Support\ArabicShop::on();
    \Tests\Support\ArabicShop::string('store.footer.link_spotted', 'لحظات #KBeautyBliss');
    app()->setLocale('ar');

    expect((string) __('store.footer.link_spotted'))->toBe("لحظات \u{2066}#KBeautyBliss\u{2069}");
});

/*
 * 10. GRAMMAR. The owner: "do as per the arabic grammar and local language."
 *     A hundred drafts addressed the shopper in the masculine beside feminine
 *     ones on the same screen. Every correction must still find its draft (a
 *     correction whose `old` no longer matches silently does nothing).
 *     Mutation: change any `old` by one letter and this is red.
 */
it('applies every Arabic draft correction and leaves no masculine imperative behind in them', function () {
    $drafts = ArabicInterfaceDrafts::all();

    foreach (ArabicDraftCorrections::ROUND2 as $key => [$old, $new]) {
        expect($drafts[$key] ?? null)->toBe($new, $key);
    }
});

/*
 * 11. ARROWS THAT MOVED BUT DID NOT TURN. Shop by skin concern, the homepage
 *     grid sections and the cart page's rail put "next" on the left in Arabic
 *     (logical insets) with the English chevron still pointing right.
 *     Mutation: drop any one selector from the flip and this is red.
 */
it('turns every carousel chevron that moved to the mirrored side', function () {
    $rule = '[dir="rtl"] .hs-cc-arr svg,[dir="rtl"] .gs-arr svg,[dir="rtl"] .cpg-arr svg{transform:scaleX(-1)}';

    expect(rtl2Css('kbb.css'))->toContain($rule)
        ->and(rtl2Built('kbb.css'))->toContain('[dir=rtl] .hs-cc-arr svg');
});

/*
 * 12. THE CART PAGE'S RAIL DID NOTHING IN ARABIC. scrollLeft runs negative in
 *     a right-to-left scroller; address-sheet's script scrolled "next" by a
 *     POSITIVE amount (towards the start: no movement), left "previous" dimmed
 *     for good and never dimmed "next". Measured at 1280 after the fix: next
 *     sits left, one press moves scrollLeft 0 → -539 (English 0 → 540), and
 *     the two arrows dim at the ends exactly as in English.
 *     The fix is emitted ONLY into a mirrored page, so English cart HTML is
 *     unchanged. Mutation: drop the @if guard (English moves) or the minus sign
 *     in scrollBy (Arabic stalls again).
 */
it('drives the cart rail the right way in RTL, and only emits that fix into a mirrored page', function () {
    $sheet = (string) file_get_contents(resource_path('views/partials/address-sheet.blade.php'));
    $fix = (string) file_get_contents(resource_path('views/partials/rtl-cart-rail.blade.php'));

    expect($sheet)->toContain("@if (\\App\\Support\\Locale::isRtl())\n@include('partials.rtl-cart-rail')\n@endif\n@endpush")
        ->and(substr_count($sheet, "@include('partials.rtl-cart-rail')"))->toBe(1)
        ->and($fix)->toContain('left: -rail.clientWidth * 0.86 * Number(arrow.dataset.cpgRail)')
        ->and($fix)->toContain('at = -Math.round(rail.scrollLeft)')
        ->and($fix)->not->toMatch('/getBoundingClientRect|offsetWidth|offsetLeft/');
});

/*
 * 14. LATIN IN WHAT GOOGLE READS ON /ar. The super-sale page's BreadcrumbList
 *    said "Home" / "Shop" on /ar URLs (CollectionController wrote literals the
 *    visible crumb had long since swapped for keys), and a shop whose name is
 *    not "K-Beauty Bliss" titled its Arabic homepage with the English sentence
 *    hard-coded in home.blade.php. English is the same words from the same keys.
 *    Mutation: put 'Home' back in any of the three controllers, or the literal
 *    back in home.blade.php, and this is red.
 */
it('names structured-data crumbs and the fallback home title from keys, not English literals', function () {
    foreach (['CollectionController', 'PageController', 'RoutineController'] as $c) {
        expect((string) file_get_contents(app_path('Http/Controllers/Store/'.$c.'.php')))
            ->not->toContain("['name' => 'Home',")
            ->not->toContain("['name' => 'Shop',");
    }
    expect((string) file_get_contents(resource_path('views/store/home.blade.php')))
        ->toContain("@section('title', \\App\\Support\\ArabicWording::of('K-Beauty Bliss · Authentic Korean skincare in the UAE'))");

    expect(ArabicWording::of('K-Beauty Bliss · Authentic Korean skincare in the UAE'))
        ->toBe('K-Beauty Bliss · Authentic Korean skincare in the UAE')
        ->and((string) __('store.breadcrumb.home'))->toBe('Home');
    app()->setLocale('ar');
    expect(ArabicWording::of('K-Beauty Bliss · Authentic Korean skincare in the UAE'))->toBe((string) __('store.wording.home_title'))
        ->and(ArabicInterfaceDrafts::all()['store.wording.home_title'])->not->toMatch('/[A-Za-z]{3,}/');
});

/*
 * 15. EVERY /ar LINK RE-READ THE WHOLE SETTINGS MAP. LocaleSlugs::policy() went
 *    through SeoSettings::get(), which reads and re-cleans the `kbb.settings.map`
 *    cache entry on every call, and Url::to() asks it once per link: 162 reads
 *    of the full map on one Arabic category page, 4 on its English twin --
 *    a file open + unserialize each on the live host's file cache. Same page,
 *    measured after: 6 in total. Mutation: put SeoSettings::get() back in
 *    LocaleSlugs::policy() and the Arabic count is in the hundreds.
 */
it('reads the settings map a handful of times on an Arabic page, the same as English', function () {
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
    $category = \App\Models\Category::query()->whereHas('products')->firstOrFail();

    $count = function (string $path): int {
        $n = 0;
        \Illuminate\Support\Facades\Event::listen([\Illuminate\Cache\Events\CacheHit::class, \Illuminate\Cache\Events\CacheMissed::class], static function ($e) use (&$n): void {
            if ($e->key === 'kbb.settings.map') {
                $n++;
            }
        });
        SettingsService::forgetMemo();
        test()->get($path)->assertOk();

        return $n;
    };

    $count('/collections/'.$category->slug.'/');
    $en = $count('/collections/'.$category->slug.'/');
    $count('/ar/collections/'.$category->slug.'/');
    $ar = $count('/ar/collections/'.$category->slug.'/');

    expect($ar)->toBeLessThanOrEqual($en + 3, "Arabic read the settings map {$ar} times, English {$en}");
});
