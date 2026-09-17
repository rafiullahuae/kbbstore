<?php

/**
 * The two modules ported in Lane EH, switched on and off against real pages.
 *
 * Not "the class exists" and not "the setting saves". Every test here flips the
 * switch the owner would flip on Store → Modules and then asks for the page a
 * shopper would ask for, because the defects this repo keeps finding all live in
 * the gap between those two:
 *
 *   `single_name`   — the toggle saved; the checkout ignored it.
 *   quick view      — a button, a modal, CSS, a route and a controller, and no
 *                     listener.
 *   `seo_engine`    — a working form writing into a void.
 *
 * Each module is pinned three ways, following Phase3ModuleSwitchesTest's shape,
 * because two are not enough: ON gives the new behaviour, OFF gives the old one
 * BACK, and the default is what a store that has touched nothing receives. A
 * switch that only ever adds is not a switch.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use App\Support\CheckoutLegalNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function ehModule(string $key, bool $on): void
{
    app(SettingsService::class)->setModule($key, $on);
}

/** Read through the registry, built fresh: all() caches for the life of an instance. */
function ehModuleOn(string $key): bool
{
    return (new ModuleRegistry(app(SettingsService::class)))->on($key);
}

afterEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
});

/* ─────────────────────────── legal_notice ─────────────────────────── */

/**
 * A cart with something in it, so /checkout/ renders rather than redirecting
 * an empty cart back to the shop.
 *
 * The cart is built as a row and handed over in the cart cookie, the shape the
 * other checkout suites use. EncryptCookies is dropped for the same reason
 * bootstrap/app.php exempts kbb_tz: a cookie set without Laravel's envelope
 * arrives as nothing at all, and a checkout that quietly has no cart looks
 * exactly like a checkout whose module is switched off — which would make this
 * whole file pass for the wrong reason.
 */
function ehCheckoutHtml(): string
{
    $product = Product::create([
        'slug' => 'eh-legal-'.uniqid(),
        'name' => 'EH Legal Notice Product',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
    ]);

    $cart = \App\Models\Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5000]);

    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $cart->token)
        ->get('/checkout/')->assertOk()->getContent();
}

/** How many times the notice is on the page. */
function ehNoticeCount(string $html): int
{
    // preg_match_all and not str_contains: order-block renders twice, once for
    // the desktop aside and once for the mobile place-order box, and "is it
    // there" and "how many" are different questions. Counting also means the
    // page's own inlined CSS cannot be mistaken for the element — a class name
    // appears in the stylesheet too.
    return preg_match_all('/<p class="kbb-legal">/', $html);
}

it('ships the legal notice on, saying nothing, so applying the package changes no checkout', function () {
    // ON by default, as the plugin ships it...
    expect(ModuleRegistry::REGISTRY['legal_notice'][3])->toBeTrue();

    DB::table('module_toggles')->where('module', 'legal_notice')->delete();
    Cache::forget('kbb.modules');

    expect(ehModuleOn('legal_notice'))->toBeTrue();

    // ...and with no wording saved it renders nothing at all. This pairing is
    // the whole safety argument: a module that is on by default AND carried a
    // sentence of its own would print new legal wording above Place order on a
    // live store the moment the package applied.
    expect(ehNoticeCount(ehCheckoutHtml()))->toBe(0);
});

it('prints the notice above Place order once the owner writes one', function () {
    ehModule('legal_notice', true);
    app(SettingsService::class)->set(CheckoutLegalNotice::KEY, 'By ordering you accept our {terms} and our {privacy}.');

    $html = ehCheckoutHtml();

    expect(ehNoticeCount($html))->toBeGreaterThan(0);
    expect($html)->toContain('By ordering you accept our');

    // The placeholders became real links to the two pages the register form
    // already links to, rather than being printed as literal braces.
    expect($html)->toContain('/terms-and-conditions/')
        ->toContain('/privacy-policy/')
        ->not->toContain('{terms}')
        ->not->toContain('{privacy}');
});

it('leaves no trace on the checkout when the notice is switched off', function () {
    app(SettingsService::class)->set(CheckoutLegalNotice::KEY, 'By ordering you accept our {terms}.');

    ehModule('legal_notice', false);

    $off = ehCheckoutHtml();

    // Not merely hidden: the element is not rendered, and neither is the
    // wording, so the switch cannot be defeated by reading the source.
    expect(ehNoticeCount($off))->toBe(0);
    expect($off)->not->toContain('By ordering you accept our');

    // And back on restores it, rather than needing a cache clear or a redeploy.
    ehModule('legal_notice', true);

    expect(ehNoticeCount(ehCheckoutHtml()))->toBeGreaterThan(0);
});

it('treats a cleared box as “say nothing” rather than falling back to a default', function () {
    /*
     * SettingsService::get() returns its default only when the ROW IS ABSENT.
     * An owner who clears the input stores '', which is a real value and must
     * mean "do not say this" — the trap TrustClaims and SupportContact both
     * carry, here for a legal sentence where getting it wrong means the shop
     * keeps asserting something the owner deliberately withdrew.
     */
    ehModule('legal_notice', true);

    $settings = app(SettingsService::class);
    $settings->set(CheckoutLegalNotice::KEY, 'Something the owner later thought better of.');

    expect(CheckoutLegalNotice::html($settings))->not->toBeNull();

    $settings->set(CheckoutLegalNotice::KEY, '');

    expect(CheckoutLegalNotice::html($settings))->toBeNull();

    // A box holding only whitespace is a cleared box too.
    $settings->set(CheckoutLegalNotice::KEY, "   \n  ");

    expect(CheckoutLegalNotice::html($settings))->toBeNull();
});

it('escapes the owner’s wording instead of letting it put markup on the checkout', function () {
    $settings = app(SettingsService::class);
    $settings->set(CheckoutLegalNotice::KEY, 'Read <script>alert(1)</script> our {terms}.');

    $html = CheckoutLegalNotice::html($settings);

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');

    // The links still work: the text is escaped first and the anchors put in
    // after, so exactly two tags can reach the page and they are the two this
    // class wrote.
    expect($html)->toContain('<a href=');
});

/* ───────────────────────────── brands ───────────────────────────── */

function ehBrand(): Brand
{
    return Brand::create([
        'name' => 'EH Test Brand',
        'slug' => 'eh-test-brand-'.uniqid(),
    ]);
}

it('has brands on by default, because the brand pages have been serving all along', function () {
    /*
     * A deliberate divergence from the plugin, which ships this off, and the
     * same argument seo_engine's row carries: the default has to be measured
     * against what this shop does WITHOUT the switch. Without it the directory,
     * the per-brand pages and the two 301s all answer, so off-by-default would
     * mean this package 404ing three live URL families on apply.
     */
    expect(ModuleRegistry::REGISTRY['brands'][3])->toBeTrue();

    DB::table('module_toggles')->where('module', 'brands')->delete();
    Cache::forget('kbb.modules');

    expect(ehModuleOn('brands'))->toBeTrue();
});

it('turns the existing brands toggle on rather than 404ing a live store', function () {
    // The alignment migration, asserted the way Phase3ModuleSwitchesTest asserts
    // seo_engine's: the value that survives a full migrate is the one that keeps
    // the storefront behaving as it did.
    expect(ehModuleOn('brands'))->toBeTrue();
});

it('serves the brand directory and a brand page while brands is on', function () {
    $brand = ehBrand();

    ehModule('brands', true);

    test()->get('/korean-skincare-brands/')->assertOk();
    test()->get('/korean-skincare-brands/'.$brand->slug.'/')->assertOk();

    // The legacy addresses still redirect rather than 404ing.
    test()->get('/brands/')->assertRedirect();
});

it('404s every brand address, redirects included, when brands is switched off', function () {
    $brand = ehBrand();

    ehModule('brands', false);

    test()->get('/korean-skincare-brands/')->assertNotFound();
    test()->get('/korean-skincare-brands/'.$brand->slug.'/')->assertNotFound();

    /*
     * The two 301s go with them, and that is the point of gating in the
     * constructor rather than per action: a redirect that still answered would
     * send the shopper to a page that 404s, which is a worse answer than either
     * end giving the same one.
     */
    test()->get('/brands/')->assertNotFound();
    test()->get('/brand/'.$brand->slug.'/')->assertNotFound();

    // And back on restores all of it.
    ehModule('brands', true);

    test()->get('/korean-skincare-brands/')->assertOk();
});

it('takes the home page brand strip down with the module, not just the brand pages', function () {
    ehBrand();

    ehModule('brands', true);

    $on = test()->get('/')->assertOk()->getContent();

    ehModule('brands', false);

    $off = test()->get('/')->assertOk()->getContent();

    /*
     * A module switched off has to leave NO trace on the storefront. A brand
     * strip still sitting on the home page with every tile linking to a page
     * that now 404s is the loudest trace there is.
     *
     * Counted off the strip's own link, not off a class name: the class appears
     * in this page's inlined stylesheet whether the section renders or not.
     */
    $count = fn (string $html) => preg_match_all('~href="[^"]*/korean-skincare-brands/"~', $html);

    expect($count($on))->toBeGreaterThan(0);
    expect($count($off))->toBe(0);
});

it('stops the header linking to brand pages it would now 404', function () {
    /*
     * FOUND BY THE TEST ABOVE, which is why it is its own test now.
     *
     * Gating BrandController was not enough. The primary menu's "Brands" item
     * is authored menu data pointing at /korean-skincare-brands/, and it kept
     * rendering in the header of EVERY page with the module off — a link
     * straight to a 404. The page count matters more than the home page did:
     * this one was on all of them.
     */
    ehBrand();

    ehModule('brands', false);

    foreach (['/', '/shop'] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        // Matched on the href rather than on a class name: the class appears in
        // this page's inlined stylesheet whether the item renders or not.
        expect(preg_match_all('~href="[^"]*/korean-skincare-brands/?"~', $html))
            ->toBe(0, "a brand link survives in the chrome of {$url}");
        expect(preg_match_all('~href="[^"]*/brand/[^"]*"~', $html))
            ->toBe(0, "a per-brand link survives in the chrome of {$url}");
    }

    // On, the nav item is back — the filter hides it, it does not delete the
    // owner's menu row.
    ehModule('brands', true);

    expect(preg_match_all('~href="[^"]*/korean-skincare-brands/?"~', test()->get('/shop')->assertOk()->getContent()))
        ->toBeGreaterThan(0);
});

it('leaves the shop’s own brand filter alone, which is not a brand page', function () {
    /*
     * The module gates brand PAGES, not the catalogue's brand facet.
     * /shop/?filter_brands={slug} is what Brand::url() returns and what the
     * shop's filters actually run on — URL Contract U-05 — and it is a shop
     * listing that keeps working with the module off. Over-matching here would
     * quietly strip the filter out of the sidebar.
     */
    expect(\App\Support\BrandUrls::matches('/shop/?filter_brands=cosrx'))->toBeFalse();
    expect(\App\Support\BrandUrls::matches('/shop/'))->toBeFalse();

    // And the four addresses the module really owns are all matched, with or
    // without a trailing slash, so the gate and the nav filter cannot disagree.
    expect(\App\Support\BrandUrls::matches('/korean-skincare-brands/'))->toBeTrue();
    expect(\App\Support\BrandUrls::matches('/korean-skincare-brands/cosrx/'))->toBeTrue();
    expect(\App\Support\BrandUrls::matches('/brands'))->toBeTrue();
    expect(\App\Support\BrandUrls::matches('/brand/cosrx/'))->toBeTrue();
});
