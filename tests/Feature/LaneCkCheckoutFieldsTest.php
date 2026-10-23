<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Str;

/**
 * THE CHECKOUT'S FIELD LABELS (Lane CK, 22 October 2026), from the owner's own
 * phone screenshot of the live checkout:
 *
 *   - the lifted mini-headings ("Phone", "Building / Apartment or Villa",
 *     "Emirate", "Country") were PINK -> now GREY, the label's own muted ink;
 *   - every label carried a pink "*" -> none, until the browser calls a
 *     required field user-invalid (or the inline-validation module marks its
 *     row), in English and in Arabic -- CSS only since Lane CK2, no script.
 *     `required` and aria-required stay, so a screen reader still hears
 *     "required", and the server's rules do not move.
 */
function ckFieldsPage(string $prefix = ''): string
{
    if ($prefix === '/ar') {
        \Tests\Support\ArabicShop::on();
    }

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $p = Product::create(['slug' => 'ck-fl-'.Str::random(8), 'name' => 'Rice Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 250, 'stock_status' => 'instock']);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 25000]);

    return test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($prefix.'/checkout')->assertOk()->getContent();
}

function ckCheckoutCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
}

it('lifts the floating label in grey, the same muted ink it rests in', function () {
    $css = ckCheckoutCss();

    // MUTATION: put color:var(--pink-deep) back on the lifted rule. RED.
    expect($css)->toMatch('/\.kbb-checkout \.fld\.kbb-fl select ~ label\{\s*top:14px;[^}]*color:var\(--muted\)\}/')
        // The old pink is not in the stylesheet at all: it is printed inline only
        // when chosen (LaneCkTextSizesTest).
        ->and($css)->not->toContain('cop-flpink')
        // A wrong field still turns its label red; the coupon stays green.
        ->and($css)->toContain('.kbb-checkout .form-row.woocommerce-invalid .fld.kbb-fl > label')
        ->and($css)->toContain('.kbb-checkout .coupon .fld.kbb-fl input:is(:focus,:not(:placeholder-shown)) ~ label{color:#1F7D52;text-transform:none}');
});

it('hides every "*" until a required field has failed, in CSS alone', function () {
    $css = ckCheckoutCss();

    // MUTATION: delete display:none. RED -- the asterisks are back.
    expect($css)->toContain('.kbb-checkout .form-row label .required{display:none;color:var(--pink);')
        // Shown by the browser's own :user-invalid, or the inline-validation module's row class.
        ->and($css)->toContain('.kbb-checkout :is(.form-row:has(:user-invalid),.woocommerce-invalid) .required{display:inline}');
});

it('keeps required and aria-required on every required field, and the star in the markup for later', function (string $prefix) {
    $html = ckFieldsPage($prefix);

    foreach (['billing_email', 'billing_first_name', 'billing_phone', 'billing_address_1', 'billing_state', 'billing_country'] as $name) {
        expect($html)->toMatch('/<(input|select)[^>]*name="'.$name.'"[^>]*required aria-required="true"/');
        expect(preg_match('#<label for="'.$name.'"[^>]*>.*?</label>#s', $html, $m))->toBe(1, $name);
        expect($m[0])->toContain('<span class="required" aria-hidden="true">*</span>');
    }
})->with(['English' => [''], 'Arabic' => ['/ar']]);

it('adds no JavaScript for the star: checkout.js is untouched', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));

    // Lane CK's focusout / capture-phase listeners are gone (app.js back to byte-identical).
    expect($js)->not->toContain('kbb-req-miss')
        ->and($js)->not->toContain('markEmptyRequired')
        ->and($js)->not->toContain('markIfEmpty');
});
