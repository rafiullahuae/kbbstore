<?php

declare(strict_types=1);

/**
 * The checkout's fields, and the order they produce.
 *
 * Phase 8's last open item asked for the checkout to be moved onto a shared
 * field component "and to be tested against a real order". Nine hand-written
 * copies of the same WooCommerce row had already drifted — a trailing space in
 * one class attribute, an empty `class=""` on one label, a `data-input-classes`
 * on one input alone, one label using a plain space where the other five used
 * `&nbsp;`, and one field claiming `autocomplete="given-name"` for a box that
 * holds the whole name. They are one component now, so they cannot.
 *
 * WHAT THIS FILE PINS, AND WHY IN THIS SHAPE.
 *
 * The first half reads the RENDERED page. A class name searched for in the
 * bytes of a storefront page matches the inlined stylesheet as readily as the
 * markup, so every count here comes from a pattern that matches a TAG with its
 * attributes, via preg_match_all — the trap PhoneShopperLayoutTest documents at
 * length.
 *
 * The second half places a real order. Its point is not that an order can be
 * placed — CheckoutPlacementTest does that — but that the order placed through
 * the FORM THIS PAGE RENDERS is the same row as before the fields moved. So the
 * post body is not written out by hand: it is scraped from the rendered
 * checkout, field by field, exactly as a browser would gather it. A field whose
 * `name` changed, or which stopped rendering at all, changes the posted body
 * and shows up as a different order row rather than as a passing test.
 *
 * The expected row is written out in full and in absolute numbers. Those
 * numbers were taken from a run against the tree as it stood BEFORE the fields
 * moved (2.60.199, with checkout.blade.php holding all nine rows inline), so a
 * failure here means the refactor changed what a shopper is charged.
 *
 * Assertions carrying an explanation use toBeTrue/toBeFalse: Pest's toContain()
 * is variadic and reads a second string as another needle, not as a message.
 */

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create([
        'id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/** A basket worth AED 400, optionally carrying a coupon. */
function cfcCart(?Coupon $coupon = null): Cart
{
    $product = Product::create([
        'slug' => 'cfc-serum',
        'name' => 'Ginseng Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 200,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20000]);

    return $cart;
}

/**
 * A browser carrying this cart's cookie.
 *
 * EncryptCookies is off for the same reason CheckoutPlacementTest turns it off:
 * a plain token handed to that middleware is decrypted, fails and is dropped,
 * leaving the controller with no cart at all.
 */
function cfcShopper(Cart $cart)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

/** The checkout page, with style and script stripped so only markup is left. */
function cfcMarkup(Cart $cart): string
{
    $response = cfcShopper($cart)->get('/checkout');

    expect($response->getStatusCode())->toBe(200, 'the checkout did not render');

    return (string) preg_replace(
        ['#<style\b[^>]*>.*?</style>#is', '#<script\b[^>]*>.*?</script>#is', '#<!--.*?-->#s'],
        '',
        (string) $response->getContent()
    );
}

/** Every `<input>`, `<select>` and `<textarea>` tag on the page, as written. */
function cfcControls(string $html): array
{
    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $html, $m);

    return $m[0];
}

/** The one control carrying this id, or null. */
function cfcControl(string $html, string $id): ?string
{
    foreach (cfcControls($html) as $tag) {
        if (preg_match('/\bid=["\']'.preg_quote($id, '/').'["\']/', $tag)) {
            return $tag;
        }
    }

    return null;
}

/**
 * Whether the tag carries a bare `required`.
 *
 * NOT `\brequired\b`: a word boundary sits between the hyphen and the "r" of
 * `aria-required`, so that pattern matches the announcement as readily as the
 * constraint and cannot tell the two apart — which is the exact distinction
 * this file exists to pin.
 */
function cfcRequired(string $tag): bool
{
    return preg_match('/(?<![-\w])required\b/i', $tag) === 1;
}

/** What a textarea is rendered holding, which is its text and not an attribute. */
function cfcTextareaValue(string $html, string $id): string
{
    return preg_match(
        '/<textarea\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/textarea>/is',
        $html,
        $m
    ) ? $m[1] : '';
}

/** One attribute's value off a tag, or null when the attribute is absent. */
function cfcAttr(string $tag, string $attr): ?string
{
    return preg_match('/\b'.preg_quote($attr, '/').'=["\']([^"\']*)["\']/i', $tag, $m) ? $m[1] : null;
}

/**
 * The body a browser would post from this page: every named control that is
 * successful, with the value the page rendered into it.
 *
 * Checkboxes and radios contribute only when checked, which is what makes the
 * WhatsApp opt-in and the chosen delivery rate land in the body the way they
 * really do. Unchecked boxes are left out, exactly as a browser leaves them
 * out — that is why `is_gift` and `create_account` are absent below.
 */
function cfcFormBody(string $html): array
{
    $body = [];

    foreach (cfcControls($html) as $tag) {
        $name = cfcAttr($tag, 'name');

        if ($name === null || $name === '') {
            continue;
        }

        $type = strtolower((string) (cfcAttr($tag, 'type') ?? 'text'));

        if (in_array($type, ['checkbox', 'radio'], true)) {
            if (preg_match('/\bchecked\b/i', $tag)) {
                $body[$name] = cfcAttr($tag, 'value') ?? 'on';
            }

            continue;
        }

        if (str_starts_with(strtolower($tag), '<select')) {
            // The option the page pre-selected is what a browser would send.
            if (preg_match('/<select\b[^>]*\bname=["\']'.preg_quote($name, '/').'["\'][^>]*>(.*?)<\/select>/is', $html, $sel)
                && preg_match('/<option\b[^>]*\bselected\b[^>]*>/i', $sel[1], $opt)) {
                $body[$name] = cfcAttr($opt[0], 'value') ?? '';
            }

            continue;
        }

        $body[$name] = cfcAttr($tag, 'value') ?? '';
    }

    return $body;
}

/*
|------------------------------------------------------------------------------
| The rendered markup
|------------------------------------------------------------------------------
*/

it('renders every checkout field through the shared component, with its hooks intact', function () {
    $html = cfcMarkup(cfcCart());

    /*
     * The nine rows the component now renders, and what each one has to keep.
     *
     * `.form-row` and `.input-text` are not decoration: they are the selectors
     * the phone rules added in 2.60.197 hang off —
     *
     *     @media (max-width:820px){ .kbb-checkout .input-text,
     *         .kbb-checkout .form-row input[type="email"], ... {font-size:16px} }
     *     @media (max-width:900px){ .kbb-checkout .input-text, ... {min-height:44px} }
     *
     * so a field that loses either goes back under iOS's 16px zoom threshold
     * and under the 44px touch target, silently.
     */
    $expected = [
        // id                    label                autocomplete                              inputmode  required
        ['billing_email',        'Email address',     'section-billing billing email',          'email',   true],
        ['billing_phone',        'Phone',             'section-billing billing tel',            'tel',     false],
        ['billing_first_name',   'Full name',         'section-billing billing name',           null,      true],
        ['billing_address_1',    'Address',           'section-billing billing address-line1',  null,      true],
        ['billing_state',        'Emirate',           'section-billing billing address-level1', null,      true],
        ['billing_city',         'City / area',       'section-billing billing address-level2', null,      true],
        ['billing_country',      'Country',           'section-billing billing country',        null,      true],
        ['customer_note',        'Delivery notes',    null,                                     null,      false],
    ];

    foreach ($expected as [$id, $label, $autocomplete, $inputmode, $required]) {
        $tag = cfcControl($html, $id);

        expect($tag)->not->toBeNull("#{$id} is no longer rendered on the checkout.");

        expect(cfcAttr($tag, 'name'))->toBe($id,
            "#{$id} posts under a different name than its id, so the controller's rules miss it.");

        expect(str_contains((string) cfcAttr($tag, 'class'), 'input-text'))->toBeTrue(
            "#{$id} lost .input-text. That class is what raises the field to 16px and to a 44px ".
            'touch target on a phone; without it an iPhone zooms the page on focus and does not '.
            'zoom back.');

        expect(cfcAttr($tag, 'autocomplete'))->toBe($autocomplete,
            "#{$id} no longer offers the browser the right autofill token.");

        expect(cfcAttr($tag, 'inputmode'))->toBe($inputmode,
            "#{$id}'s inputmode changed, so a phone offers a different keyboard.");

        expect(cfcRequired($tag))->toBe($required,
            "#{$id}'s required attribute changed. It is what stops an empty submission in the ".
            'browser; the server enforces the same rule either way, but losing it sends the '.
            'shopper on a round trip to be told so.');

        // Exactly one <label for="{id}">, and it says what it always said.
        preg_match_all(
            '/<label\b[^>]*\bfor=["\']'.preg_quote($id, '/').'["\'][^>]*>(.*?)<\/label>/is',
            $html,
            $labels
        );

        expect(count($labels[0]))->toBe(1, "#{$id} should have exactly one label pointing at it.");

        expect(str_contains(html_entity_decode(strip_tags($labels[1][0])), $label))->toBeTrue(
            "#{$id}'s label no longer reads \"{$label}\": ".trim(strip_tags($labels[1][0])));

        // And the row around it, which carries the WooCommerce classes the
        // sheet's bridge block selects on.
        preg_match_all(
            '/<p\b[^>]*\bid=["\']'.preg_quote($id, '/').'_field["\'][^>]*>/i',
            $html,
            $rows
        );

        expect(count($rows[0]))->toBe(1, "#{$id}_field should be exactly one .form-row on the page.");

        expect(str_contains((string) cfcAttr($rows[0][0], 'class'), 'form-row'))->toBeTrue(
            "#{$id}_field is no longer a .form-row, so the checkout sheet stops styling it.");
    }
});

it('marks a required checkout field to the browser and to a screen reader alike', function () {
    /*
     * ShopperPathTruthTest found these fields carrying `aria-required="true"`
     * and no `required`: an announcement with no constraint behind it, so
     * `form.checkValidity()` returned true on a completely empty checkout and
     * the shopper was posted, refused and returned to the top of a long page.
     *
     * The component now emits both together or neither, which is the only way
     * the two cannot drift apart again. Country was the field still doing it —
     * a select that told assistive technology it was required and told the
     * browser nothing.
     */
    $html = cfcMarkup(cfcCart());

    foreach (cfcControls($html) as $tag) {
        if (cfcAttr($tag, 'aria-required') !== 'true') {
            continue;
        }

        expect(cfcRequired($tag))->toBeTrue(
            'This control announces itself as required to a screen reader but carries no '.
            '`required` attribute, so the browser never stops an empty submission: '.$tag);
    }
});

it('keeps the checkout free of an inline font size, which no stylesheet can undo', function () {
    // The other half of the 16px rule. The sheet raises these fields on a
    // phone; an inline size on the element would beat it.
    foreach (cfcControls(cfcMarkup(cfcCart())) as $tag) {
        expect(preg_match('/\bstyle=["\'][^"\']*font-size/i', $tag))->toBe(0,
            'A checkout control carries an inline font-size, which the phone rules cannot '.
            'override: '.$tag);
    }
});

it('gives a shopper back what they typed when the server refuses the order', function () {
    /*
     * Every row on this page reads `old()`, and the component now writes that
     * value into the tag rather than nine call sites doing it by hand. What a
     * refactor can silently break here is the escaping: a value written out
     * twice comes back as `O&amp;#039;Brien` in the box, and the shopper has to
     * retype an address they already typed. So the round trip is exercised
     * with a name and a street that both contain characters HTML cares about.
     *
     * The submission is refused on purpose -- no payment method -- because that
     * is the only path that re-renders this page with the posted values on it.
     */
    $cart = cfcCart();

    $typed = [
        'billing_email' => 'noor@example.com',
        'billing_first_name' => "Noor O'Brien-Al Suwaidi",
        'billing_address_1' => 'Flat 3 & 4, "The Gate" Tower',
        'billing_city' => 'Al Reem Island',
        'billing_state' => 'Abu Dhabi',
        'billing_country' => 'AE',
        'customer_note' => 'Ring the bell <twice>, please.',
    ];

    $before = Order::count();

    cfcShopper($cart)->post('/checkout/place', $typed)->assertSessionHasErrors();

    expect(Order::count())->toBe($before, 'the refused submission created an order anyway');

    // Follow the shopper back to the page they were returned to.
    $html = cfcMarkup($cart);

    foreach ($typed as $field => $value) {
        if ($field === 'billing_country') {
            continue;   // a select; its selected option is checked elsewhere
        }

        $tag = cfcControl($html, $field);

        expect($tag)->not->toBeNull("#{$field} is no longer rendered on the checkout.");

        $shown = $field === 'customer_note'
            ? cfcTextareaValue($html, $field)
            : (string) cfcAttr($tag, 'value');

        expect(html_entity_decode($shown, ENT_QUOTES | ENT_HTML5))->toBe($value,
            "#{$field} did not come back with what the shopper typed. Got: ".$shown);
    }
});

/*
|------------------------------------------------------------------------------
| The real order
|------------------------------------------------------------------------------
*/

it('places a real order from the rendered form and writes the row it wrote before', function () {
    /*
     * A coupon, a delivery charge and a cash-on-delivery fee, all three at
     * once, because they are the three lines that interact:
     *
     *     goods        2 x AED 200.00   = 40000
     *     coupon       10% of goods     = -4000
     *     delivery     flat rate        =  2000
     *     COD fee                       =  1500
     *                                    ------
     *     total                           39500
     */
    $coupon = Coupon::create([
        'code' => 'GLOW10',
        'type' => 'percent',
        'amount' => 1000,          // 10.00%, stored as percent x 100
        'usage_count' => 0,
    ]);

    app(SettingsService::class)->set('cod_fee', 1500);
    SettingsService::forgetMemo();

    $cart = cfcCart($coupon);

    // The body a browser would send: read off the page, not written out here.
    $body = cfcFormBody(cfcMarkup($cart));

    /*
     * The page renders these empty for a guest, so the shopper types them. Set
     * on top of the scraped body rather than instead of it, so a field that
     * stopped rendering is still a failure — the keys below have to already
     * exist.
     */
    foreach ([
        'billing_email' => 'Layla@Example.COM',
        'billing_phone' => '+971 50 123 4567',
        'billing_first_name' => 'Layla Al Mansoori',
        'billing_address_1' => 'Villa 12, Street 7',
        'billing_city' => 'Al Reem Island',
        'billing_state' => 'Abu Dhabi',
        'customer_note' => 'Leave with the concierge, please.',
    ] as $field => $value) {
        expect(array_key_exists($field, $body))->toBeTrue(
            "The checkout no longer posts `{$field}`, so an order placed from this page would ".
            'be missing it.');

        $body[$field] = $value;
    }

    // What the page itself chose, and what the shopper never touches.
    expect($body['billing_country'] ?? null)->toBe('AE',
        'The country select no longer pre-selects a country, so a browser would post nothing.');
    expect($body['billing_kbb_whatsapp'] ?? null)->toBe('1',
        'The WhatsApp opt-in is no longer ticked by default, which changes what every order '.
        'records without anyone asking for it.');
    expect($body['shipping_method'] ?? null)->not->toBeNull(
        'No delivery rate is pre-selected, so a browser would post no shipping_method.');

    $body['payment_method'] = 'cod';

    $response = cfcShopper($cart)->post('/checkout/place', $body);

    $order = Order::latest('id')->first();

    expect($order)->not->toBeNull('No order was created from the rendered checkout form.');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('order='.$order->order_number);

    /*
     * The row, in full and in absolute numbers. These are the values the same
     * post produced against 2.60.199 before the fields moved onto the
     * component; every one of them is something a customer or the shop's
     * accounts would notice.
     */
    expect([
        'email' => $order->email,
        'phone' => $order->phone,
        'currency' => $order->currency,
        'customer_note' => $order->customer_note,
        'is_gift' => (bool) $order->is_gift,
        'gift_fee' => (int) $order->gift_fee,
        'gift_note' => $order->gift_note,
        'subtotal' => (int) $order->subtotal,
        'discount_total' => (int) $order->discount_total,
        'shipping_total' => (int) $order->shipping_total,
        'fee_total' => (int) $order->fee_total,
        'total' => (int) $order->total,
        'payment_method' => $order->payment_method,
        'payment_method_title' => $order->payment_method_title,
    ])->toBe([
        // Lower-cased on save, which is why it was typed in mixed case above.
        'email' => 'layla@example.com',
        'phone' => '+971 50 123 4567',
        'currency' => 'AED',
        'customer_note' => 'Leave with the concierge, please.',
        'is_gift' => false,
        'gift_fee' => 0,
        'gift_note' => null,
        'subtotal' => 40000,
        'discount_total' => 4000,
        'shipping_total' => 2000,
        'fee_total' => 1500,
        'total' => 39500,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    /*
     * And the address, which is the half the fields feed directly. "Full name"
     * is one box on the page and two columns on the order; splitName() in the
     * controller is what cuts it, and this is the assertion that notices if the
     * single-name field ever stops posting under `billing_first_name`.
     *
     * SORTED BEFORE COMPARING, because the two engines disagree about the key
     * order of a JSON column and nothing else. SQLite stores the document as
     * the text it was handed, so it reads back in insertion order; MySQL parses
     * it into its own binary form, which orders an object's keys by key length
     * and then lexically, and hands that order back. Both hold exactly the same
     * seven pairs. toBe() on an array is identical(), which compares order as
     * well as content, so the unsorted form of this assertion passes on SQLite
     * and fails on MySQL — an engine-split test, which is a defect in itself:
     * it would have to be read and dismissed by hand on every MySQL run
     * forever. Sorting drops the one thing the engines differ on and keeps the
     * strict value comparison, which is the part worth having.
     */
    $billing = $order->billing_address;
    $shipping = $order->shipping_address;
    ksort($billing);
    ksort($shipping);

    $expectedAddress = [
        'first_name' => 'Layla',
        'last_name' => 'Al Mansoori',
        'line1' => 'Villa 12, Street 7',
        'city' => 'Al Reem Island',
        'state' => 'Abu Dhabi',
        'country' => 'AE',
        'phone' => '+971 50 123 4567',
    ];
    ksort($expectedAddress);

    expect($billing)->toBe($expectedAddress);
    expect($shipping)->toBe($billing);
});
