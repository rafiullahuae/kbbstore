<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;
use App\Services\CartPanel;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\LangSwitchGlyphs;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lane HLB, 17 October 2026: the header language switch and the wishlist heart
 * moved into the cart panel on phones -- the design the owner approved in the
 * round-3 live preview ("ok proceed to the patch, but with super care without
 * distubing anything else"; tools/hl3-live.cjs, docs/lane-hl/round3).
 *
 * His words this pins: "the language switcher MUST not come in between the
 * cart and user icons"; "for wishlist, i need only the heart icon ... at the
 * end of the cart panel, same for arabic"; the Arabic menu icon mirrored.
 */
function hlbArabic(bool $on, bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => $on ? '1' : '0', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $on && $rtl ? '1' : '0', 'autoload' => true]);
    hlbFlush();
}

function hlbFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

function hlbHeader(string $html): string
{
    $start = strpos($html, '<header');
    $end = strpos($html, '</header>', (int) $start);

    return substr($html, (int) $start, (int) $end - (int) $start);
}

function hlbProduct(string $name): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 3900,
        'stock_status' => 'instock',
    ]);
}

/** A page with the given cookies sent as they are (the shop's own cookie names). */
function hlbPage(Tests\TestCase $test, string $uri, array $cookies = []): string
{
    $req = $test->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class);
    foreach ($cookies as $k => $v) {
        $req = $req->withUnencryptedCookie($k, $v);
    }

    return $req->get($uri)->assertOk()->getContent();
}

/*
 * Arabic OFF -- the switch has no second language to offer, so the header
 * prints NO byte of it. MUTATION: drop the `isset($links[$here])` guard in
 * LangSwitch::header() and ... it still holds, because links() is [] -- drop
 * the `count($alts) > 1` guard in links() instead and this is red.
 */
it('prints no switch, no class and no rule hook while Arabic is off', function () {
    hlbArabic(false);
    $header = hlbHeader(hlbPage($this, '/'));

    expect($header)->not->toContain('lsw')
        ->and($header)->not->toContain('has-lswd')
        ->and($header)->toContain('<div class="hin">')
        ->and($header)->toContain('<div class="hact">' . "\n");
});

/*
 * With the cart panel's heart tab switched OFF as well, the phone header is the
 * old one: the heart carries no `ib-wcp`, so no rule hides it, and the panel
 * has no third tab. MUTATION: print ' ib-wcp' unconditionally and this is red.
 */
it('leaves the heart in the phone header and the panel with two tabs when the wishlist tab is off', function () {
    hlbArabic(false);
    app(CartPanel::class)->save(['wish_tab_m' => false]);
    hlbFlush();

    $html = hlbPage($this, '/', ['kbb_wishlist' => '1,2']);

    expect(hlbHeader($html))->toContain('<a class="ib" href="/my-wishlist/"')
        ->and($html)->not->toContain('ib-wcp')
        ->and($html)->not->toContain('data-kctab="wish"')
        ->and($html)->not->toContain('id="kcWish"');
});

/*
 * The order the owner set: [pill][capsule][account][heart][cart]. "the
 * language switcher MUST not come in between the cart and user icons".
 * MUTATION: move {!! $kbbLsw['html'] !!} after the account span and this is red.
 */
it('prints the switch before the account icon, never between the account and cart icons', function () {
    hlbArabic(true);

    foreach (['/', '/ar/'] as $uri) {
        $hact = hlbHeader(hlbPage($this, $uri));
        $hact = substr($hact, (int) strpos($hact, '<div class="hact">'));

        $pill = strpos($hact, '<nav class="lsw lsw-d"');
        $capsule = strpos($hact, '<a class="ib lsw lsw-m"');
        $account = strpos($hact, '<span class="ib-acct"');
        $cart = strpos($hact, 'data-kbb-cart');

        expect($pill)->toBe(strlen('<div class="hact">'), $uri)
            ->and($capsule)->toBeGreaterThan($pill)
            ->and($account)->toBeGreaterThan($capsule)
            ->and($cart)->toBeGreaterThan($account)
            ->and(substr_count($hact, 'lsw lsw-d'))->toBe(1)
            ->and(substr_count($hact, 'lsw lsw-m'))->toBe(1)
            ->and(strpos(substr($hact, (int) $account), 'lsw'))->toBeFalse();
    }
});

/*
 * English page: EN is the filled half, the Arabic half a link to /ar/ drawn
 * as Cairo 700 paths (English pages do not load Cairo, and a typed "ع" sat
 * 3.9-4.6px low in every fallback face measured); the capsule names the
 * language you switch TO, also drawn. Every <svg> has width and height (the
 * header freeze: an unsized svg fills the screen without the stylesheet).
 * MUTATION: delete width="7.3" from LangSwitchGlyphs::AIN and this is red.
 */
it('draws the Arabic words on English pages as sized svgs, and links this page in Arabic', function () {
    hlbArabic(true);
    $header = hlbHeader(hlbPage($this, '/'));

    expect($header)->toContain('<nav class="lsw lsw-d" aria-label="Language"><span lang="en" aria-current="true">EN</span><a href="/ar/" lang="ar" hreflang="ar" aria-label="العربية">' . LangSwitchGlyphs::AIN . '</a></nav>')
        ->and($header)->toContain('<a class="ib lsw lsw-m" href="/ar/" lang="ar" hreflang="ar" aria-label="العربية"><span>' . LangSwitchGlyphs::ARABIC . '</span></a>');

    foreach ([LangSwitchGlyphs::AIN, LangSwitchGlyphs::ARABIC] as $svg) {
        expect($svg)->toMatch('/^<svg [^>]*\bwidth="[\d.]+" height="[\d.]+"[^>]*aria-hidden="true"/')
            ->and(substr_count($svg, '<svg'))->toBe(1);
    }
});

/*
 * Arabic page: Cairo is loaded there, so the words are text; EN links back to
 * the English page, and the capsule says "English".
 */
it('prints real text on Arabic pages and links back to the English page', function () {
    hlbArabic(true);
    $header = hlbHeader(hlbPage($this, '/ar/'));

    expect($header)->toContain('<nav class="lsw lsw-d" aria-label="اللغة"><a href="/" lang="en" hreflang="en" aria-label="English">EN</a><span lang="ar" aria-current="true">ع</span></nav>')
        ->and($header)->toContain('<a class="ib lsw lsw-m" href="/" lang="en" hreflang="en" aria-label="English"><span>English</span></a>')
        ->and($header)->not->toContain('class="lsw-g"');
});

/*
 * Appearance → Header → Icons → Language switch: Off / Phone / Computer / Both.
 * The `has-lswd` hook that moves the search and support block exists only
 * while the computer pill does. MUTATION: print has-lswd on `phone` and red.
 */
it('prints only what the admin choice asks for', function (string $mode, bool $pill, bool $capsule) {
    hlbArabic(true);
    app(HeaderSettings::class)->save(['lang_switch' => $mode]);
    hlbFlush();

    $header = hlbHeader(hlbPage($this, '/'));

    expect(str_contains($header, 'lsw lsw-d'))->toBe($pill)
        ->and(str_contains($header, 'lsw lsw-m'))->toBe($capsule)
        ->and(str_contains($header, '<div class="hin has-lswd">'))->toBe($pill);
})->with([
    'off' => ['off', false, false],
    'phone' => ['phone', false, true],
    'computer' => ['computer', true, false],
    'both' => ['both', true, true],
]);

/*
 * The setting is a select: it stores one of its four options or the default.
 * MUTATION: drop the options list from the schema entry and the markup case
 * below is red.
 */
it('stores only one of its own options', function () {
    $hd = app(HeaderSettings::class);

    expect(HeaderSettings::SCHEMA['lang_switch'][2])->toBe('both')
        ->and(array_keys(HeaderSettings::SCHEMA['lang_switch'][4]))->toBe(['off', 'phone', 'computer', 'both'])
        ->and(HeaderSettings::TABS['icons'][2])->toContain('lang_switch')
        ->and($hd->cast('lang_switch', 'phone'))->toBe('phone')
        ->and($hd->cast('lang_switch', '"><script>alert(1)</script>'))->toBe('both')
        ->and($hd->cast('lang_switch', ''))->toBe('both');
});

/*
 * The Arabic menu icon: the three lines drawn from the right -- the short
 * middle line ends on the inline-start side -- as its own path, no transform.
 * English keeps its path byte for byte. MUTATION: swap the two paths and red.
 */
it('draws the three-lines menu icon from the right on a mirrored page only', function () {
    hlbArabic(true);

    expect(hlbPage($this, '/ar/'))->toContain('<svg class="ln" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.5 6.5h-17M20.5 12h-11M20.5 17.5h-17"/></svg>')
        ->and(hlbPage($this, '/'))->toContain('<svg class="ln" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5h17M3.5 12h11M3.5 17.5h17"/></svg>');

    // Arabic on but the mirrored layout off: the page is not RTL, so the icon is not mirrored.
    hlbArabic(true, rtl: false);
    expect(hlbPage($this, '/ar/'))->toContain('<path d="M3.5 6.5h17M3.5 12h11M3.5 17.5h17"/>');
});

/*
 * The cart panel: Cart · Browsed · ♥ -- the heart LAST, before the close
 * button, outlined while the wishlist is empty and filled once it is not, no
 * count. The phone header's heart carries `ib-wcp` (hidden at 900px and under)
 * while this tab is on. MUTATION: print HEART_FULL unconditionally and the
 * empty case is red.
 */
it('puts a heart-only tab last in the cart panel, outlined when empty and filled when not', function () {
    hlbArabic(false);

    $empty = hlbPage($this, '/');
    $tabs = substr($empty, (int) strpos($empty, '<div class="kc-tabs">'), 900);

    expect($tabs)->toMatch('/data-kctab="cart".*data-kctab="browsed".*data-kctab="wish".*class="kc-x"/s')
        ->and($tabs)->toContain('aria-label="Wishlist">' . \App\Support\CartPanelLists::HEART_EMPTY . '</button>')
        ->and($tabs)->not->toContain('<span class="n">0</span></button>' . "\n" . '        <button class="kc-tab kc-wt"')
        ->and($empty)->toContain('Your wishlist is empty.');

    $a = hlbProduct('Heart Serum');
    $b = hlbProduct('Heart Toner');
    $full = hlbPage($this, '/', ['kbb_wishlist' => $b->id . ',' . $a->id]);
    $pane = substr($full, (int) strpos($full, '<div id="kcWish"'));

    expect($full)->toContain('aria-label="Wishlist">' . \App\Support\CartPanelLists::HEART_FULL . '</button>')
        ->and(strpos($pane, 'Heart Toner'))->toBeLessThan(strpos($pane, 'Heart Serum'))
        ->and($pane)->toContain('data-wrow="' . $a->id . '"')
        ->and($pane)->toContain('class="kc-wrm on" data-kbb-wish="' . $a->id . '" aria-label="Remove from wishlist"')
        ->and($pane)->toContain('class="kc-badd" data-add="' . $a->id . '"')
        ->and($pane)->not->toContain('Your wishlist is empty.');
});

/*
 * The heart leaves the PHONE header only where the capsule takes its slot --
 * the approved design. Without the capsule (Arabic off, or the switch set to
 * Computer/Off) the heart stays: its empty slot would go to the logo, which
 * measured 128 -> 168px wide at 320 with the lotus 27x20 -> 35x26, and the
 * header is frozen ("logo previous size"). MUTATION: drop `$kbbLsw['phone'] &&`
 * from $kbbWcp in partials/header and the Arabic-off line is red.
 */
it('moves the heart out of the phone header only where the capsule takes its slot', function () {
    $hook = '<a class="ib ib-wcp" href="/my-wishlist/"';

    hlbArabic(false);
    expect(hlbHeader(hlbPage($this, '/')))->not->toContain('ib-wcp');

    hlbArabic(true);
    expect(hlbHeader(hlbPage($this, '/')))->toContain($hook)
        ->and(hlbHeader(hlbPage($this, '/ar/')))->toContain('<a class="ib ib-wcp" href="/ar/my-wishlist/"');

    app(HeaderSettings::class)->save(['lang_switch' => 'computer']);
    hlbFlush();
    expect(hlbHeader(hlbPage($this, '/')))->not->toContain('ib-wcp');

    app(HeaderSettings::class)->save(['lang_switch' => 'both']);
    app(CartPanel::class)->save(['wish_tab_m' => false]);
    hlbFlush();
    expect(hlbHeader(hlbPage($this, '/')))->not->toContain('ib-wcp');
});

it('speaks Arabic in the heart tab on /ar', function () {
    hlbArabic(true);
    $html = hlbPage($this, '/ar/');

    expect($html)->toContain('data-kctab="wish" aria-label="المفضلة"')
        ->and($html)->toContain('قائمة المفضلة فارغة.');
});

/*
 * ONE QUERY FOR BOTH LISTS. The wishlist is folded into the Browsed query, so
 * a shopper with a browsing history pays nothing for the tab, and 3 saved
 * products cost what 40 do (the panel lists the newest 20). The composer is
 * driven directly, as BrowsedTabWithoutCartTest explains, so the query log is
 * honest. MUTATION: fetch the wishlist with its own Product::query() and the
 * "browsed only = browsed + wishlist" line is red.
 */
it('reads the wishlist in the same query as Browsed, flat however long the list', function () {
    hlbArabic(false);
    $viewed = hlbProduct('Viewed');
    $saved = collect(range(1, 40))->map(fn (int $i) => hlbProduct("Saved {$i}"))->pluck('id')->all();
    app(CartPanel::class)->wishTab(); // settings warm, as on a real page

    $count = function (array $cookies): array {
        $composer = new App\View\Composers\CartDrawerComposer(
            app(App\Services\CartService::class),
            app(SettingsService::class),
            Illuminate\Http\Request::create('/', 'GET', [], $cookies),
        );
        $view = view('partials.cart-drawer');
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $composer->compose($view);
        $n = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return [$n, $view->getData()['wish']->count()];
    };

    [$browsedOnly] = $count(['kbb_viewed' => (string) $viewed->id]);
    [$three, $w3] = $count(['kbb_viewed' => (string) $viewed->id, 'kbb_wishlist' => implode(',', array_slice($saved, 0, 3))]);
    [$forty, $w40] = $count(['kbb_viewed' => (string) $viewed->id, 'kbb_wishlist' => implode(',', $saved)]);

    expect($w3)->toBe(3)
        ->and($w40)->toBe(\App\Support\CartPanelLists::WISH_MAX)
        ->and($three)->toBe($browsedOnly)
        ->and($forty)->toBe($three);
});

/*
 * A heart pressed anywhere redraws the tab in the reply to the request the
 * heart already makes -- no request of its own. Without pane=1 (a page with no
 * heart tab) the reply is what it was. MUTATION: drop the `pane` branch from
 * WishlistController::toggle() and the first expectation is red.
 */
it('returns the redrawn heart tab with the toggle reply only when asked', function () {
    hlbArabic(false);
    $p = hlbProduct('Toggle Me');

    $with = $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->post('/wishlist/toggle', ['product_id' => $p->id, 'pane' => 1])->assertOk()->json();
    $without = $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
        ->post('/wishlist/toggle', ['product_id' => $p->id])->assertOk()->json();

    expect($with['pane'] ?? null)->toContain('data-wrow="' . $p->id . '"')
        ->and($without)->not->toHaveKey('pane');
});

/*
 * Appearance → Cart panel → Content → Wishlist tab (phones): ships ON, his
 * request, in the Content tab, with Arabic wording beside the English.
 */
it('ships the wishlist tab on, in Content, with its wording in both languages', function () {
    expect(CartPanel::SCHEMA['wish_tab_m'][2])->toBeTrue()
        ->and(CartPanel::TABS['content'][2])->toContain('wish_tab_m')
        ->and(CartPanel::TABS['wording'][2])->toContain('txt_tab_wish')
        ->and(CartPanel::TABS['wording_ar'][2])->toContain('txt_tab_wish_ar');
});
