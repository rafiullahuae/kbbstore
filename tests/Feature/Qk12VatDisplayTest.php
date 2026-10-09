<?php

declare(strict_types=1);

/**
 * Lane QK12, chunk C -- the VAT figure stops being shown, and only shown.
 *
 * The owner, 9 October, on phone screenshots of the checkout ("VAT inclusive
 * (5%)  AED 21.67" over Place order, and "VAT inclusive (5%) AED 20.57" in the
 * Order summary at the top of the phone page):
 *
 *   "remove the vat price that comes above place order button, only mention 5%
 *    VAT included."   -- and then, on the wording: "5% VAT inclusive".
 *   "remove the vat value also from the cart page. but at the backend the VAT
 *    will be calculated properly. only i don't want to display the value to
 *    the customer for confusion."
 *
 * Appearance -> Checkout page -> Trust & reviews -> "Show VAT amount to
 * customers", OFF as asked, with "VAT line wording" / "— Arabic" beside it.
 * Both copies of the order block print "5% VAT inclusive" and no figure; the
 * country-change refresh keeps it a sentence. The cart page and the cart panel
 * never printed a VAT figure (cart-inner: "No VAT note"); pinned below so one
 * cannot appear there.
 *
 * DISPLAY ONLY: the stored order -- total, tax_total, tax_rate, tax_basis --
 * is the same to the fil with the switch off and on.
 */

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\VatDisplay;
use Illuminate\Support\Str;

beforeEach(function () {
    qk12cFresh();
    \App\Models\PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => 'SA']);
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
});

function qk12cFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

function qk12cSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    qk12cFresh();
}

function qk12cCart(int $unitPriceFils = 10000): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);
    $cart->items()->create([
        'product_id' => Product::create([
            'slug' => 'qk12c-'.Str::random(8), 'name' => 'Rice Probiotics Toner', 'status' => 'publish',
            'is_visible' => true, 'price' => $unitPriceFils, 'stock_status' => 'instock',
        ])->id,
        'quantity' => 1,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart;
}

function qk12cShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** The "of which" VAT rows of a rendered checkout. */
function qk12cVatNotes(string $html): array
{
    preg_match_all('#<div class="sumrow vat js-vat-row vat-note"[^>]*>(.*?)</div>#', $html, $m);

    return $m[1];
}

it('prints "5% VAT inclusive" and no amount in both of the checkout\'s summaries', function () {
    // THE DEFECT: both rows read "VAT inclusive (5%)" and then the figure,
    // "AED 5.71" here (AED 21.67 on the owner's basket). Mutation: vat_amount's
    // default -> true -> red, the amount is back in both.
    expect(CheckoutPage::SCHEMA['vat_amount'][2])->toBeFalse();
    qk12cSet('vat_label', 'VAT inclusive ({rate}%)');

    $html = qk12cShopper(qk12cCart())->get('/checkout/')->assertOk()->getContent();
    $notes = qk12cVatNotes($html);

    expect($notes)->toHaveCount(2, 'the phone summary and the one over Place order')
        ->and($notes[0])->toBe('<span class="js-vat-short">5% VAT inclusive</span>')
        ->and($notes[1])->toBe($notes[0]);

    // No `.js-vat` in the note row, so no refresh can write a figure into it.
    expect(implode('', $notes))->not->toContain('js-vat"')->not->toContain('AED');
});

it('takes the rate from the destination, exactly as the old label did', function () {
    // Mutation: hard-code "5" in vatSentence()'s caller -> red on the 15.
    qk12cSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['SA' => '15']));
    $cart = qk12cCart();

    $sa = qk12cShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA', 'state' => 'Riyadh'])->assertOk();
    $ae = qk12cShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE', 'state' => 'Dubai'])->assertOk();

    expect($sa->json('vat.short'))->toBe('15% VAT inclusive')
        ->and($ae->json('vat.short'))->toBe('5% VAT inclusive')
        // the figure still travels for the switched-on page and the exclusive
        // row -- the DISPLAY is what changed, not the arithmetic
        ->and($sa->json('vat.formatted'))->not->toBe($ae->json('vat.formatted'));

    // checkout.js writes the sentence by textContent into .js-vat-short.
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->toContain("if (data.vat && typeof data.vat.short === 'string') {")
        ->and($js)->toContain("document.querySelectorAll('.js-vat-short').forEach((el) => {\n                    el.textContent = data.vat.short;");
});

it('draws the label and the amount again with the switch on, as before', function () {
    qk12cSet('vat_label', 'VAT inclusive ({rate}%)');
    app(CheckoutPage::class)->save(['vat_amount' => true]);
    qk12cFresh();

    $notes = qk12cVatNotes(qk12cShopper(qk12cCart())->get('/checkout/')->assertOk()->getContent());

    // AED 120 with delivery, 5% inclusive: 5.71 of it is the tax.
    expect($notes[0])->toBe('<span class="js-vat-label">VAT inclusive (5%)</span><span class="js-vat"><span class="woocommerce-Price-amount amount" dir="ltr"><span class="woocommerce-Price-currencySymbol" dir="auto">AED</span> 5.71</span></span>');
    expect(app(CheckoutPage::class)->vatSentence('5'))->toBeNull();
});

it('uses the owner\'s own wording, English and Arabic, with {rate} filled', function () {
    app(CheckoutPage::class)->save(['vat_text' => 'Prices include {rate}% VAT', 'vat_text_ar' => 'شامل {rate}% ضريبة']);
    qk12cFresh();

    expect(app(CheckoutPage::class)->vatSentence('5'))->toBe('Prices include 5% VAT');
    expect(CheckoutPage::SCHEMA['vat_text'][2])->toBe('{rate}% VAT inclusive')
        ->and(CheckoutPage::SCHEMA['vat_text_ar'][2])->toBe('شامل ضريبة القيمة المضافة {rate}%');

    // Escaped on the page: a setting is never printed raw.
    app(CheckoutPage::class)->save(['vat_text' => '<b>{rate}%</b> VAT']);
    qk12cFresh();
    $notes = qk12cVatNotes(qk12cShopper(qk12cCart())->get('/checkout/')->assertOk()->getContent());
    expect($notes[0])->toBe('<span class="js-vat-short">&lt;b&gt;5%&lt;/b&gt; VAT</span>');
});

it('keeps the exclusive row and its figure, because there the tax is added to the total', function () {
    // A charge that is part of the sum has to stay visible, or the column does
    // not add up to what is taken. Only the "of which" note changes.
    qk12cSet('tax_mode', VatDisplay::MODE_LIVE);
    qk12cSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '5']));
    qk12cSet(VatDisplay::COUNTRY_BASES_KEY, json_encode(['AE' => TaxRule::EXCLUSIVE]));

    $html = qk12cShopper(qk12cCart())->get('/checkout/')->assertOk()->getContent();

    expect($html)->toMatch('#<div class="sumrow vat js-vat-row vat-add"><span class="js-vat-label">[^<]+</span><span class="js-vat"><span class="woocommerce-Price-amount amount" dir="ltr"><span class="woocommerce-Price-currencySymbol" dir="auto">AED</span> 6</span></span></div>#');
});

it('never shows a VAT figure on the cart page or in the cart panel', function () {
    // "remove the vat value also from the cart page": there is none to remove
    // -- cart-inner says so ("No VAT note") -- and this keeps it that way.
    $html = qk12cShopper(qk12cCart())->get('/cart/')->assertOk()->getContent();

    expect($html)->not->toContain('js-vat')
        ->and($html)->not->toMatch('/VAT[^<]{0,40}<[^>]*>\s*AED/i');
});

/* ───────────────── the calculation is exactly what it was ───────────────── */

/** Place a COD order for a fresh AED 100 basket and read back the stored tax. */
function qk12cPlaced(): array
{
    $cart = qk12cCart(10000);
    qk12cShopper($cart)->post('/checkout/place', [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai',
        'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod',
    ])->assertRedirect();

    $o = Order::latest('id')->first();

    return [
        'total' => (int) $o->total, 'subtotal' => (int) $o->subtotal, 'shipping' => (int) $o->shipping_total,
        'tax_total' => (int) $o->tax_total, 'tax_rate' => (string) $o->tax_rate, 'tax_basis' => (string) $o->tax_basis,
    ];
}

it('stores the same VAT on a placed order with the amount hidden as with it shown', function () {
    // AE 5% inclusive, live: AED 100 + AED 20 delivery = 12000 fils, of which
    // 12000 x 5/105 = 571 fils is the tax. The figure TaxEndToEndTest pins.
    qk12cSet('tax_mode', VatDisplay::MODE_LIVE);
    qk12cSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '5']));
    qk12cSet(VatDisplay::COUNTRY_BASES_KEY, json_encode(['AE' => TaxRule::INCLUSIVE]));

    $hidden = qk12cPlaced();                                   // the shipped state: amount OFF

    app(CheckoutPage::class)->save(['vat_amount' => true]);    // the old display
    qk12cFresh();
    $shown = qk12cPlaced();

    expect($hidden)->toBe($shown)
        ->and($hidden['total'])->toBe(12000)
        ->and($hidden['tax_total'])->toBe(571)
        ->and($hidden['tax_basis'])->toBe(TaxRule::INCLUSIVE);
});
