<?php

declare(strict_types=1);

/**
 * The brand line on cart and checkout rows, switched per device.
 *
 * THE OWNER'S REQUEST (2 October 2026), on his cart page with "NIDA",
 * "ARENCIA", "ROHTO MENTHOLATUM" above every product: "on cart backend control
 * page, give option to hide un-hide the brands names. on desktop and mobile
 * seperate options. keep for desktop ON by default, and OFF for mobile
 * devices. same on checkout rows."
 *
 * Cart: Appearance → Cart page → Product rows · spacing and size / Product
 * rows · phone → "Show the brand name" (ci_brand_on true, ci_brand_on_m
 * false), hidden by CartPage::rowCss() at the rows' own phone width.
 * Checkout: Appearance → Checkout page → Desktop · Product rows / Mobile ·
 * Product rows → "Show the brand name" (d_row_brand true, m_row_brand false).
 * The checkout rows had no brand line before; it is drawn when either switch
 * is on and hidden per device at the page's 900px.
 *
 * MUTATIONS, RUN:
 *   - make CartPage::brandRule() return '' : the first case is red (the brand
 *     still shows on a phone);
 *   - swap the two media queries in brandRule(): the second case is red;
 *   - drop the `$kbbBrandD || $kbbBrandM` guard in summary-items: the fourth
 *     case is red (a brand line drawn with both switches off);
 *   - delete the co-rb-nom rule from kbb-checkout.css: the fifth case is red.
 */

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Str;

function cbsCart(): \Tests\TestCase
{
    $brand = Brand::create(['name' => 'Nida <b>Lab</b>', 'slug' => 'cbs-nida-'.Str::random(6)]);
    $product = Product::create([
        'slug' => 'cbs-'.Str::random(8), 'name' => 'Salmon PDRN Peptide Serum', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 11500, 'stock_status' => 'instock',
        'brand_id' => $brand->id,
    ]);
    $cart = Cart::create([
        'token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 11500]);

    return test()->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function cbsSet(array $values): void
{
    $s = app(SettingsService::class);
    foreach ($values as $key => $value) {
        $s->set($key, $value);
    }
    SettingsService::forgetMemo();
}

it('ships the cart brand line on a desktop and off on a phone', function () {
    SettingsService::forgetMemo();

    expect(app(CartPage::class)->rowCss())
        ->toBe('@media (max-width:600px){.kbb-cartpage .items .ci .cbrand{display:none}}');

    $html = cbsCart()->get('/cart')->assertOk()->getContent();

    // The line is still in the page (a desktop shows it), escaped, and the
    // phone rule is printed with it.
    expect($html)->toContain('<div class="cbrand">Nida &lt;b&gt;Lab&lt;/b&gt;</div>')
        ->and($html)->toContain('<style id="kbb-cartrows">@media (max-width:600px){.kbb-cartpage .items .ci .cbrand{display:none}}</style>');
});

it('answers every combination of the two cart switches, at the rows own phone width', function () {
    cbsSet(['cartpage_ci_brand_on' => true, 'cartpage_ci_brand_on_m' => true]);
    expect(app(CartPage::class)->rowCss())->toBe('');

    cbsSet(['cartpage_ci_brand_on' => false, 'cartpage_ci_brand_on_m' => true]);
    expect(app(CartPage::class)->rowCss())->toBe('@media (min-width:601px){.kbb-cartpage .items .ci .cbrand{display:none}}');

    cbsSet(['cartpage_ci_brand_on' => false, 'cartpage_ci_brand_on_m' => false]);
    expect(app(CartPage::class)->rowCss())->toBe('.kbb-cartpage .items .ci .cbrand{display:none}');

    cbsSet(['cartpage_ci_brand_on' => true, 'cartpage_ci_brand_on_m' => false, 'cartpage_ci_bp' => 700]);
    expect(app(CartPage::class)->rowCss())->toEndWith('@media (max-width:700px){.kbb-cartpage .items .ci .cbrand{display:none}}');
});

it('draws the checkout brand line, marked off for a phone, by default', function () {
    SettingsService::forgetMemo();

    $html = cbsCart()->get('/checkout/')->assertOk()->getContent();

    expect(substr_count($html, '<div class="b co-rb-nom">Nida &lt;b&gt;Lab&lt;/b&gt;</div>'))->toBe(1);
});

it('draws no checkout brand line when both switches are off, and the right mark for each', function () {
    app(CheckoutPage::class)->save(['d_row_brand' => false, 'm_row_brand' => false]);
    SettingsService::forgetMemo();
    expect(cbsCart()->get('/checkout/')->getContent())->not->toContain('Nida &lt;b&gt;Lab');

    app(CheckoutPage::class)->save(['d_row_brand' => false, 'm_row_brand' => true]);
    SettingsService::forgetMemo();
    expect(cbsCart()->get('/checkout/')->getContent())->toContain('<div class="b co-rb-nod">Nida &lt;b&gt;Lab&lt;/b&gt;</div>');

    app(CheckoutPage::class)->save(['d_row_brand' => true, 'm_row_brand' => true]);
    SettingsService::forgetMemo();
    expect(cbsCart()->get('/checkout/')->getContent())->toContain('<div class="b">Nida &lt;b&gt;Lab&lt;/b&gt;</div>');
});

it('hides each mark at the checkout page own 900px', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    expect($css)->toContain('@media (min-width:901px){.kbb-checkout .cinfo .b.co-rb-nod{display:none}}')
        ->and($css)->toContain('@media (max-width:900px){.kbb-checkout .cinfo .b.co-rb-nom{display:none}}');
});

it('puts both switches on the tabs the owner will look for them on', function () {
    expect(CartPage::SCHEMA['ci_brand_on'][2])->toBeTrue()
        ->and(CartPage::SCHEMA['ci_brand_on_m'][2])->toBeFalse()
        ->and(CheckoutPage::SCHEMA['d_row_brand'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['m_row_brand'][2])->toBeFalse()
        ->and(CartPage::TABS['rowsize'][2])->toContain('ci_brand_on')
        ->and(CartPage::TABS['rowphone'][2])->toContain('ci_brand_on_m')
        ->and(CheckoutPage::TABS['desktop_rows'][2])->toContain('d_row_brand')
        ->and(CheckoutPage::TABS['mobile_rows'][2])->toContain('m_row_brand');
});
