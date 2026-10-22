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
 *   - every label carried a pink "*" -> none, until a required field is left
 *     empty (blurred empty, or found empty by Place order), in English and in
 *     Arabic. `required` and aria-required stay, so a screen reader still
 *     hears "required", and the server's rules do not move.
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
        // The old pink only under the switch, and with no added weight.
        ->and($css)->toContain('.kbb-checkout:where(.cop-flpink) .fld.kbb-fl select ~ label{color:var(--pink-deep)}')
        // A wrong field, or one left empty, still turns its label red; the coupon stays green.
        ->and($css)->toContain('.kbb-checkout .form-row.kbb-req-miss .fld.kbb-fl > label{color:var(--sale)}')
        ->and($css)->toContain('.kbb-checkout .coupon .fld.kbb-fl input:is(:focus,:not(:placeholder-shown)) ~ label{color:#1F7D52;text-transform:none}');
});

it('hides every "*" until a required field is left empty, and shows it then', function () {
    $css = ckCheckoutCss();

    // MUTATION: delete the display:none rule. RED -- the asterisks are back.
    expect($css)->toContain('.kbb-checkout .form-row .required{display:none}')
        ->and($css)->toContain('.kbb-checkout .form-row.kbb-req-miss .required{display:inline}')
        ->and($css)->toContain('.kbb-checkout .form-row:has(:is(input,textarea):placeholder-shown:user-invalid) .required{display:inline}')
        ->and($css)->toContain('.kbb-checkout .form-row:has(select:user-invalid) .required{display:inline}');
});

it('keeps required and aria-required on every required field, and the star in the markup for later', function (string $prefix) {
    $html = ckFieldsPage($prefix);

    foreach (['billing_email', 'billing_first_name', 'billing_phone', 'billing_address_1', 'billing_state', 'billing_country'] as $name) {
        expect($html)->toMatch('/<(input|select)[^>]*name="'.$name.'"[^>]*required aria-required="true"/');
        expect(preg_match('#<label for="'.$name.'"[^>]*>.*?</label>#s', $html, $m))->toBe(1, $name);
        expect($m[0])->toContain('<span class="required" aria-hidden="true">*</span>');
    }
})->with(['English' => [''], 'Arabic' => ['/ar']]);

it('marks the row from the existing checkout script, with no request and no timer', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    $start = (int) strpos($js, 'function markIfEmpty(');
    $chunk = substr($js, $start, (int) strpos($js, 'function rememberDetails(', $start) - $start);

    // MUTATION: drop the focusout listener. RED.
    expect($chunk)->toContain("form.addEventListener('focusout'")
        ->and($chunk)->toContain("classList.toggle('kbb-req-miss'")
        ->and($chunk)->not->toContain('fetch(')
        ->and($chunk)->not->toContain('setTimeout')
        ->and($chunk)->not->toContain('getBoundingClientRect')
        ->and($js)->toContain('markEmptyRequired(form);')
        // Place order and Enter show every empty required field's star -- on window, in the capture
        // phase, ahead of the overlay's listener that stops the press (MUTATION: drop the true. RED in Chromium).
        ->and($chunk)->toContain("window.addEventListener('click', (event) => {")
        ->and($chunk)->toContain('}, true);')
        ->and($chunk)->not->toContain('preventDefault')
        ->and($chunk)->not->toContain('stopPropagation');
});
