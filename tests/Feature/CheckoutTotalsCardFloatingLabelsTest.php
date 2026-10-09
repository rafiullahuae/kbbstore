<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnglishRenderWalk;

/**
 * =============================================================================
 * THE CHECKOUT'S TOTALS CARD AND FLOATING LABELS (Lane CD)
 * =============================================================================
 *
 * The owner, 1: "ON DESKTOP checkout: the summary should bar should have only
 * products, rest of the sub total, delivery fees etc rows should be above the
 * place order button. please adjust it nicely"
 *
 * The owner, 2: "i told you that input fields will not dedicated heading, the
 * heading it self will show as place holder, and upon click the placeholder
 * will set as tiny heading inside the input fields, same like we have on
 * Create account page. i need the same."
 *
 * Appearance -> Checkout page -> Fields & attention:
 *   "Desktop: totals above Place order"   ON (asked for)
 *   "Floating labels on checkout fields"  ON (asked for)
 *
 * What the shop looked like before: on a laptop the folded summary row hid the
 * subtotal, delivery, fees and Total under it, so Place order sat under a row
 * with nothing between them; and every field had an 11px heading above its box.
 */
beforeEach(function () {
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set('cod_fee', 700);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    if (! Route::has('checkout.lineUpdate')) {
        Route::middleware('web')->group(base_path('routes/checkout-line.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }
});

function cdCart(): Cart
{
    $product = Product::create([
        'slug' => 'cd-' . uniqid(), 'name' => 'Card Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 15550, 'stock_status' => 'instock',
    ]);

    $cart = Cart::create(['token' => bin2hex(random_bytes(16)), 'status' => 'active', 'currency' => 'AED', 'shipping_country' => 'AE']);
    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15550]);

    return $cart->fresh('items');
}

function cdShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function cdPage(Cart $cart): string
{
    return cdShopper($cart)->get('/checkout/')->assertOk()->getContent();
}

function cdSet(array $values): void
{
    app(CheckoutPage::class)->save($values);
    SettingsService::forgetMemo();
}

function cdAside(string $html): string
{
    $from = strpos($html, '<aside class="summary');

    return $from === false ? '' : substr($html, $from, strpos($html, '</aside>', $from) - $from);
}

/** The .cotot card's inside: from its opener to the VAT note that closes it. */
function cdCard(string $html): ?string
{
    return preg_match('#<div class="cotot">(.*?<div class="sumrow vat js-vat-row vat-note"[^\n]*</div>\n)</div>\n#s', $html, $m)
        ? $m[1] : null;
}

// ── 1. THE TOTALS CARD ──────────────────────────────────────────────────────

it('puts every totals row in one card directly above Place order, and leaves the summary the products', function () {
    // MUTATION: default `sum_totals` to false, or drop the opener in
    // order-block.blade.php. RED: no card, and the folded row hides the totals.
    $aside = cdAside(cdPage(cdCart()));
    $card = cdCard($aside);

    expect($card)->not->toBeNull();

    foreach (['kbb-freeship-slot', 'js-subtotal', 'js-coupons', 'js-shipping', 'js-gift-row', 'js-fee-row',
        'js-vat-row vat-add', 'js-total-row', 'js-total-row-fee', 'js-vat-row vat-note'] as $class) {
        expect($card)->toContain($class);
    }

    // No product line in the card, and every product line ahead of it.
    expect($card)->not->toContain('class="ci"')
        ->and(strpos($aside, 'class="co-items"'))->toBeLessThan(strpos($aside, '<div class="cotot">'));

    // Between the card and Place order: the legal notice and nothing that adds up.
    preg_match('#vat-note"[^\n]*</div>\n</div>\n(.*?)<button type="button" class="place"#s', $aside, $gap);
    expect($gap)->not->toBeEmpty()
        ->and($gap[1])->not->toContain('sumrow')
        ->and($gap[1])->not->toContain('<div class="ci"');

    // The folded row hides the products and NOT the card (CSS, no script).
    expect(cdPage(cdCart()))->toContain(':is(.sumtabs,.co-items,.kbb-freeship-slot,.sumrow,.js-coupons,.peekfade,.viewfull):not(.cotot *){display:none!important}');

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    expect($css)->toContain("@media(min-width:901px){\n  .kbb-checkout .summary .cotot{");
});

it('keeps one card per copy of the order block and no new copy of any total', function () {
    // The block was drawn twice before (summary column, phone box) and still
    // is: the card wraps it, it does not duplicate it. MUTATION: render the
    // order block a third time for the card. RED.
    $html = cdPage(cdCart());

    expect(substr_count($html, '<div class="cotot">'))->toBe(2)
        ->and(substr_count($html, 'class="sumrow tot js-total-row"'))->toBe(2)
        ->and(substr_count($html, 'class="sumrow tot js-total-row-fee"'))->toBe(2)
        ->and(substr_count($html, 'class="kbb-order-slot"'))->toBe(2);
});

it('brings the summary back exactly as Lane CK left it with the switch off', function () {
    cdSet(['sum_totals' => false]);

    $html = cdPage(cdCart());

    expect($html)->not->toContain('cotot"')
        ->and($html)->toContain('<div class="kbb-freeship-slot">')
        ->and($html)->toContain(':is(.sumtabs,.co-items,.kbb-freeship-slot,.sumrow,.js-coupons,.peekfade,.viewfull){display:none!important}')
        ->and($html)->toMatch('#<div class="sumrow vat js-vat-row vat-note"[^\n]*</div>\n\n#');
});

it('points every live totals update at the card, and the row total stays the order total', function () {
    /*
     * The paths that rewrite the totals: the country / emirate refresh and the
     * gift toggle write every .js-* on the page; the coupon and the quantity
     * steppers replace .kbb-order-slot wholesale with the server's orderHtml;
     * the summary row copies its total from that slot. MUTATION: render the
     * card outside .kbb-order-slot, or drop a class from the card. RED.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'))
        . (string) file_get_contents(resource_path('views/store/checkout.blade.php'));

    preg_match_all("#querySelectorAll\\('\\.(js-[a-z-]+|kbb-freeship-slot)'\\)#", $js, $m);
    $classes = array_values(array_unique($m[1]));

    expect($classes)->toContain('js-total')->toContain('js-subtotal')->toContain('js-shipping')->toContain('kbb-freeship-slot');

    $aside = cdAside(cdPage(cdCart()));
    $card = (string) cdCard($aside);

    foreach ($classes as $class) {
        expect($card)->toContain($class);
    }

    // The card lives inside the slot the wholesale swap and the row's observer use.
    expect($aside)->toMatch('#<div class="kbb-order-slot"><div class="cotot">#')
        ->and($js)->toContain("document.querySelectorAll('.kbb-order-slot').forEach((el) => { el.innerHTML = data.orderHtml; });");
});

it('re-renders the card in every refresh, with the same total the row is given', function () {
    Coupon::create(['code' => 'CDTEN', 'type' => 'percent', 'amount' => 1000]);
    $cart = cdCart();

    // Coupon: 2 x 155.50 = 311, minus 10% = 279.90 -> AED 280, + 20 delivery.
    $r = cdShopper($cart)->postJson('/checkout/coupon', ['code' => 'CDTEN', 'country' => 'AE'])->assertOk()->json();

    expect($r['ok'])->toBeTrue()
        ->and($r['orderHtml'])->toStartWith('<div class="cotot"><div class="kbb-freeship-slot">');
    preg_match('#<span class="js-total">(.*?)</span></div>#s', $r['orderHtml'], $tot);
    expect($tot[1] ?? null)->toBe($r['total']);

    // The cash-on-delivery total rides in the same card.
    expect((string) cdCard($r['orderHtml']))->toContain('<span class="js-total-fee">');

    // Quantity: the same block, the same wrapper.
    $line = $cart->fresh('items')->items->first();
    $q = cdShopper($cart)->postJson('/checkout/line', ['item_id' => $line->id, 'quantity' => 3])->assertOk()->json();
    expect($q['orderHtml'])->toStartWith('<div class="cotot">');

    // And the page: the row's total is the card's total, to the character.
    $html = cdPage($cart->fresh('items'));
    preg_match('#<button type="button" class="cosr".*?</button>#s', $html, $row);
    preg_match('#<div class="sumrow tot js-total-row"><span>[^<]*</span><span class="js-total">(.*?)</span></div>#s', (string) cdCard(cdAside($html)), $card);
    expect($row[0] ?? '')->toContain('<span class="js-total">' . ($card[1] ?? 'missing') . '</span>');
});

// ── 2. FLOATING LABELS ──────────────────────────────────────────────────────

it('gives every checkout field the Create account form\'s floating label, keeping its name, id and autocomplete', function () {
    /*
     * MUTATION: default `float_labels` to false. RED -- every label is back
     * above its box. The control comes BEFORE its <label for>, because
     * `:placeholder-shown ~ label` is the whole mechanism.
     */
    $html = cdPage(cdCart());

    $fields = [
        'billing_first_name' => ['user', 'section-billing billing name', 'Full name'],
        'billing_phone' => ['phone', 'section-billing billing tel', 'Phone'],
        'billing_email' => ['mail', 'section-billing billing email', 'Email address'],
        // Since Lane AD (the owner: "Building / Apartment or Villa ... Area /
        // Street and the Emirates will work as City") the address boxes are
        // these three; the City box is gone and line 2 carries the area.
        'billing_address_1' => ['pin', 'section-billing billing address-line1', 'Building / Apartment or Villa'],
        'billing_state' => ['map', 'section-billing billing address-level1', 'Emirate'],
        'billing_address_2' => ['city', 'section-billing billing address-line2', 'Area / Street'],
        'billing_country' => ['globe', 'section-billing billing country', 'Country'],
    ];

    foreach ($fields as $id => [$icon, $autocomplete, $label]) {
        $ok = preg_match('#<p class="form-row[^"]*" id="' . $id . '_field"[^>]*>(.*?)</p>#s', $html, $row);
        expect($ok)->toBe(1, $id);

        $svg = trim((string) file_get_contents(resource_path('views/partials/icon-' . $icon . '.blade.php')));

        expect($row[1])->toStartWith('<span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">' . $svg . '</span>')
            ->and($row[1])->toMatch('#name="' . $id . '" id="' . $id . '"#')
            ->and($row[1])->toContain('autocomplete="' . $autocomplete . '"')
            ->and($row[1])->toContain(' required aria-required="true"')
            ->and($row[1])->toMatch('#(?:/>|</select>)<label for="' . $id . '" class="required_field">' . preg_quote($label, '#') . '&nbsp;<span class="required" aria-hidden="true">\*</span>#')
            ->and($row[1])->toEndWith('</label></span>');
    }

    // Inputmode and hints survive; a field with no hint gets the account
    // form's single-space placeholder so :placeholder-shown can read it.
    expect($html)->toContain('name="billing_phone" id="billing_phone" placeholder="+971 5x xxx xxxx" required aria-required="true" autocomplete="section-billing billing tel" inputmode="tel"')
        ->and($html)->toContain('name="billing_email" id="billing_email" placeholder="you@email.com" required aria-required="true" autocomplete="section-billing billing email" inputmode="email"')
        // City has no hint of its own since the Emirate became a list (Lane
        // AD); the list takes no placeholder, its "Select" line is an option.
        ->and($html)->toContain('<select class="input-text" name="billing_state" id="billing_state" required')
        ->and($html)->not->toMatch('#<select[^>]*placeholder#');
});

it('floats the discount code, the account password and the gift message the same way', function () {
    $html = cdPage(cdCart());

    expect($html)->toMatch('#<span class="fld kbb-fl kbb-fl-coupon ico"><span class="lead" aria-hidden="true"><svg[^\n]*</svg>\n</span><input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder=" " autocomplete="off"><label for="kbb_coupon_code">Enter promo code</label></span>#')
        ->and($html)->toMatch('#<input type="password" class="input-text" name="account_password" id="account_password"\s+placeholder=" " autocomplete="new-password" minlength="8"><label for="account_password">Choose a password \(8 characters or more\)</label></span>#')
        ->and($html)->toMatch('#<textarea name="gift_note" id="gift_note" class="input-text" rows="3" maxlength="600"\s+placeholder=" "></textarea><label for="gift_note">Your message, printed on the gift card</label></span>#');
});

it('lifts the label in CSS alone, on focus, on a value and on browser autofill', function () {
    // MUTATION: drop `:-webkit-autofill` -- a saved address prints the label
    // over the value. RED.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    expect($css)->toContain('.kbb-checkout .fld.kbb-fl :is(input,textarea):is(:focus,:not(:placeholder-shown),:-webkit-autofill,:autofill) ~ label,')
        ->and($css)->toContain('.kbb-checkout .fld.kbb-fl select ~ label{')
        ->and($css)->toContain('transform:translateY(calc(-8px - var(--fld-gap,3px)));font-size:10.5px;letter-spacing:.04em;color:var(--pink-deep)}')
        ->and($css)->toContain('.kbb-checkout .fld.kbb-fl :is(input,textarea):not(:focus)::placeholder{color:transparent}')
        // inset-inline-start, not left: Arabic puts the icon and label on the right.
        ->and($css)->toContain('.kbb-checkout .fld.kbb-fl > label{position:absolute;inset-inline-start:15px')
        ->and($css)->not->toMatch('#\.kbb-fl[^{]*\{[^}]*\bleft:#')
        // No font-size on the control itself: the text-size sliders and the
        // 16px touch floor (kbb.css) still decide it.
        ->and($css)->not->toMatch('#\.fld\.kbb-fl(?:\.ico)? :is\(input,select,textarea\)\{[^}]*font-size#');
});

it('keeps the server rules and the inline validation hooks the fields always had', function () {
    // Required on the server as well as in the markup; the inline validator
    // still finds .form-row and .input-text around every control.
    cdSet(['float_labels' => true]);
    $html = cdPage(cdCart());

    foreach (['billing_first_name', 'billing_phone', 'billing_email', 'billing_address_1', 'billing_state', 'billing_address_2', 'billing_country'] as $id) {
        expect($html)->toMatch('#<p class="form-row [^"]*validate-required[^"]*" id="' . $id . '_field"[^>]*><span class="woocommerce-input-wrapper fld kbb-fl ico">.*?class="input-text" name="' . $id . '"#s');
    }

    cdShopper(cdCart())->post('/checkout/place', [])
        ->assertSessionHasErrors(['billing_first_name', 'billing_phone', 'billing_email', 'billing_address_1']);
});

it('floats the cart page\'s discount code too, and prints its CSS only where the field is', function () {
    // MUTATION: drop the `@if ($kbbCartFl)` branch in cart-inner. RED.
    app(SettingsService::class)->setModule('cart_coupon_field', true);
    SettingsService::forgetMemo();
    $cart = cdCart();

    $html = cdShopper($cart)->get('/cart')->assertOk()->getContent();
    expect(substr_count($html, 'id="kbbCartCoupon"'))->toBe(1)
        ->and($html)->toMatch('#<span class="fld kbb-fl kbb-fl-coupon ico"><span class="lead" aria-hidden="true"><svg[^\n]*</svg>\n</span><input type="text" id="kbbCartCoupon" placeholder=" " autocomplete="off"><label for="kbbCartCoupon">Discount code</label></span>#')
        ->and(substr_count($html, '/* FLOATING LABEL on the discount code (Lane CD)'))->toBe(1)
        // Logical sides: in Arabic the icon sits at the right and so must its
        // padding. It shipped `... 13px 5px 40px` once and the code ran under
        // the icon in RTL.
        ->and($html)->toContain('padding-inline-start:40px}');

    cdSet(['float_labels' => false]);
    $off = cdShopper($cart)->get('/cart')->assertOk()->getContent();
    expect($off)->toContain('<input type="text" id="kbbCartCoupon" placeholder="Discount code" autocomplete="off">')
        ->and($off)->not->toContain('kbb-fl');
});

it('puts every label back above its box with the switch off', function () {
    cdSet(['float_labels' => false]);

    $html = cdPage(cdCart());

    expect($html)->not->toContain('kbb-fl')
        ->and($html)->toContain('<p class="form-row form-row-wide validate-required" id="billing_first_name_field" data-priority="10"><label for="billing_first_name" class="required_field">Full name&nbsp;<span class="required" aria-hidden="true">*</span></label><span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="billing_first_name" id="billing_first_name" placeholder="First and last name"')
        ->and($html)->toContain('<input type="text" name="coupon_code" class="input-text" id="kbb_coupon_code" placeholder="Enter promo code" autocomplete="off">')
        ->and($html)->not->toContain('placeholder=" "');
});

it('renders the checkout byte for byte as before this lane with both switches off', function () {
    /*
     * The strongest form of "OFF = today's layout exactly": the same request
     * rendered from resources/views as they stood when this lane branched
     * (bb1f0f2d, 2.60.412) and from the working tree, masked the way the
     * English walk masks (CSRF token). MUTATION: drop the `$kbbRowCot`
     * condition on the fold rule in summary-row.blade.php, or the `@if` round
     * either half of the card in order-block.blade.php. RED.
     */
    // Lane PY's payment boxes ship ON since; their own "Today" style is the
    // byte-for-byte way back (CheckoutPaymentBoxesTest), so it is set here and
    // this test goes on comparing what it was written to compare.
    // Lane AD's Emirate list ships ON too; OFF is its byte-for-byte way back
    // (AddressRegionsCheckoutTest), so it is set here for the same reason.
    // Lane PO's "Remember my details" ships ON too; OFF draws nothing
    // (CheckoutPlaceOrderOnceTest), so it is set here for the same reason.
    // Lane QK6's back links and delivery labels: the links are OFF and the
    // labels ON since, as the owner asked; ON / OFF respectively are their
    // byte-for-byte way back (CheckoutCouponLineTest, CheckoutDeliveryLabelsTest).
    cdSet(['sum_totals' => false, 'float_labels' => false, 'pay_style' => 'plain', 'state_list' => false, 'remember_on' => false,
        'shop_link' => true, 'cart_link' => true, 'dl_on' => false, 'dl_note_on' => false]);
    // Lane LG2's lotus logo ships ON in the checkout's header and drawer;
    // "Text only (as before)" is its byte-for-byte way back (LogoLockupTest),
    // so it is set here for the same reason.
    app(\App\Services\HeaderSettings::class)->save(['logo_style' => 'text']);
    // Lane QK8's WhatsApp button is OFF on checkout, as asked; ON is its
    // byte-for-byte way back (WhatsAppButtonTabTest), so it is set here too.
    app(\App\Services\WhatsAppButton::class)->save(['show_checkout' => true]);
    \App\Services\SettingsService::forgetMemo();
    $cart = cdCart();

    $current = config('view.paths');

    try {
        EnglishRenderWalk::useViewPath(EnglishRenderWalk::baseViews('bb1f0f2d3b75d8ef1af2da0072d7514b3f4af0e8'));
        $before = EnglishRenderWalk::mask(cdPage($cart));
        EnglishRenderWalk::useViewPath($current[0]);
        $after = EnglishRenderWalk::mask(cdPage($cart));
        // Lane IN (merged after this lane branched) adds one head line on
        // every page carrying the Site App tags: the install-offer catcher.
        // Not this lane's, so it is set aside here rather than pinned.
        $after = (string) preg_replace("#<script>addEventListener\\('beforeinstallprompt',[^<]*</script>\n#", '', $after, 1);
        // Lane M4 (merged after this lane branched) adds the phone menu's quick
        // links (V4 two-tone) under its search field on every page with the
        // menu. Not this lane's either; StorefrontEnglishUnchangedTest pins it.
        $after = (string) preg_replace('#    <div class="mm-chips"><div class="mm-chipr">.*?</div></div>\n#', '', $after, 1);
        // Lane TP (also later) adds the two policy links under Place order in
        // each copy of the order block; StorefrontEnglishUnchangedTest and
        // PolicyLinksTest pin them.
        $after = str_replace('    <p class="kbb-pol"><a href="/delivery/">Shipping &amp; Delivery</a><a href="/refund_returns/">Returns Information</a></p>'."\n", '', $after);
        // Lane CO (later) hands place()'s refusal to the sold-out dialog in
        // placing-overlay's script; CheckoutCardRetryTest pins it.
        $after = str_replace([
            "        \n        var refused = window.KBB && window.KBB.refused;\n        if (refused && data.code === 'sold_out') { down(); if (refused(data)) { return; } }\n\n",
            "        if (refused) { refused(data, (document.getElementById('kbbPlacingNotice') || {}).firstElementChild); }\n",
        ], '', $after);
        // The Firefox hotfix (after 2.60.430) marks the Place order buttons
        // autocomplete="off" and adds two blocks to placing-overlay's script;
        // CheckoutFirefoxRestoreTest pins them.
        $after = str_replace(' data-place="1" autocomplete="off">', ' data-place="1">', $after);
        // The Firefox password fix (2.60.432): the hidden account password is
        // switched off while hidden. CheckoutPlaceOrderNeverSilentTest pins it.
        $after = str_replace(' pw.disabled = !box.checked;', '', $after);
        $after = str_replace("    /* Header only: a body _token is read first by Laravel and a reload can bring\n       back a stale one; window.KBB.csrf is this load's. */\n    init.body.delete('_token');\n", '', $after);
        $after = (string) preg_replace("#  /\\* Firefox restores a button's `disabled` across a reload \\(Chrome does not\\),\n.*?if \\(!event\\.persisted\\) \\{ liven\\(\\); \\} \\}\\);\n\n#s", '', $after, 1);
        // Lane PO (later) changes the place-order overlay -- the words for a
        // press that cannot go ahead, the card's back/forward unlock. That
        // block is the overlay's, not this lane's; CheckoutPlacingOverlayTest
        // and CheckoutPlaceOrderNeverSilentTest pin it, so it is set aside on
        // both sides rather than chased string by string.
        // Lane QK6 (later) moves Full name and Phone from Contact to the top
        // of Shipping Details, as the owner asked, with no switch: set aside
        // on both sides; CheckoutCouponLineTest pins every attribute of both.
        $before = (string) preg_replace('#<span class="n">1</span> Contact.*?</h2>\n\K.*?id="billing_email_field".*?</p>\n\s*</div>\n#s', '', $before, 1);
        $after = (string) preg_replace('#<span class="n">1</span> Contact.*?</h2>\n\K.*?id="billing_email_field".*?</p>\n#s', '', $after, 1);
        $after = (string) preg_replace('#<span class="n">2</span> Shipping Details</h2>\n\K.*?id="billing_phone_field".*?</p>\n#s', '', $after, 1);
        // Lane QK8 (later) moves the title block out of the left column to the
        // top of the grid, as the owner asked ("i want the summary section
        // the checkout page heading"), with the back arrow in it: set aside on
        // both sides; CheckoutQk8Test and CheckoutCouponLineTest pin it.
        $before = (string) preg_replace('#                <div class="co-titlebar">\n.*?\n                </div>\n\n#s', '', $before, 1);
        $after = (string) preg_replace('#            <div class="co-titlebar">\n.*?\n            </div>\n\n#s', '', $after, 1);
        $overlay = '#<!--kbb-placing-->.*?<!--/kbb-placing-->#s';
        $before = (string) preg_replace($overlay, '', $before, 1);
        $after = (string) preg_replace($overlay, '', $after, 1);
    } finally {
        EnglishRenderWalk::useViewPath($current[0]);
    }

    expect(strlen($before))->toBeGreaterThan(20000)
        ->and($after)->toBe($before, EnglishRenderWalk::firstDifference($before, $after));
});

it('ships both switches on the checkout screen, on, where the owner will look', function () {
    expect(CheckoutPage::SCHEMA['sum_totals'][1])->toBe('Desktop: totals above Place order')
        ->and(CheckoutPage::SCHEMA['sum_totals'][2])->toBeTrue()
        ->and(CheckoutPage::SCHEMA['float_labels'][1])->toBe('Floating labels on checkout fields')
        ->and(CheckoutPage::SCHEMA['float_labels'][2])->toBeTrue()
        ->and(CheckoutPage::TABS['cues'][0])->toBe('Fields & attention')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('sum_totals')
        ->and(CheckoutPage::TABS['cues'][2])->toContain('float_labels');
});
