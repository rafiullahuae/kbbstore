<?php

declare(strict_types=1);

/**
 * Lane CU — tax that is CHARGED, per country, inclusive or exclusive.
 *
 * ── WHAT THIS FEATURE IS, AND WHAT IT REPLACES ─────────────────────────────
 *
 * Decision D-64 said VAT was a display line: printed, never charged, never
 * stored. The owner overturned it on 2026-09-16, in three messages quoted in
 * full in App\Support\TaxRule's header, the middle one being "also i will need
 * control to inclusive VAT or exclusive." Exclusive VAT has exactly one
 * meaning — the tax is added on top — so there was no way to give him the
 * switch and keep the line display-only.
 *
 * ── THE PROPERTY THIS FILE EXISTS TO DEFEND ────────────────────────────────
 *
 * A package that silently starts charging customers more is the worst outcome
 * available here, so the whole engine is behind one setting, `tax_mode`, which
 * ships absent and reads as 'display'. Section 1 below is the part of this file
 * that must never be weakened: with nothing set, every figure on every path is
 * the figure it was before this lane, to the fil, and every order writes
 * tax_total 0 with a null rate and a null basis so that every reader downstream
 * takes the branch it took yesterday.
 *
 * ── AND THE SECOND ONE: AN INVOICE SAYS WHAT IT SAID ───────────────────────
 *
 * The rate and basis are recorded ON the order. A shop that moves Saudi Arabia
 * from 5% to 15% next year must not have last year's invoices reprint at 15%.
 * Section 6 proves that by changing the setting after the order exists and
 * reading the document again.
 *
 * ── MONEY IS INTEGER FILS ──────────────────────────────────────────────────
 *
 * Every assertion here is an exact integer. "About AED 218" would pass on
 * 21,800 and on 21,799, and the difference between those is the whole subject.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\VatDisplay;
use Illuminate\Support\Str;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    // The UAE at AED 20 and the Gulf at AED 150 — the same miniature of
    // production's two zones VatPerCountryTest builds, so a country change
    // moves a real delivery charge as well as the tax.
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);
});

/** Every settings write goes through the service — see VatPerCountryTest's header. */
function taxSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/** The shop as the owner would leave it after filling in the Tax tab. */
function taxLive(array $rates = [], array $bases = []): void
{
    taxSet('tax_mode', VatDisplay::MODE_LIVE);

    if ($rates !== []) {
        taxSet(VatDisplay::COUNTRY_RATES_KEY, json_encode($rates));
    }

    if ($bases !== []) {
        taxSet(VatDisplay::COUNTRY_BASES_KEY, json_encode($bases));
    }
}

function taxCart(int $unitPriceFils = 10000, int $qty = 1, string $country = 'AE'): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => $country,
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => Product::create([
            'slug' => 'tax-' . Str::random(8),
            'name' => 'Rice Probiotics Toner',
            'status' => 'publish',
            'is_visible' => true,
            'price' => $unitPriceFils,
            'stock_status' => 'instock',
        ])->id,
        'quantity' => $qty,
        'unit_price' => $unitPriceFils,
    ]);

    return $cart->fresh(['items']);
}

function taxTotals(Cart $cart, ?string $country = null): array
{
    return app(CartService::class)->totals($cart, $country, null, (int) (
        app(\App\Services\ShippingService::class)->ratesFor($country ?? 'AE', null, 0, false)[0]['cost'] ?? 0
    ));
}

function taxShopper(Cart $cart)
{
    return test()
        // withCredentials(), or the /api/* group does not see the cart cookie
        // at all and the country-change endpoint answers "Your bag is empty".
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function taxCheckoutForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $overrides);
}

function taxAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Tax Owner',
        'email' => 'tax-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/*
|------------------------------------------------------------------------------
| 1. THE DAY THE PACKAGE IS APPLIED, NOTHING HAPPENS
|------------------------------------------------------------------------------
|
| The load-bearing section. Every assertion here is "the same as before".
*/

it('ships switched off, so the shop is still only printing a VAT line', function () {
    expect(app(VatDisplay::class)->mode())->toBe(VatDisplay::MODE_DISPLAY)
        ->and(app(VatDisplay::class)->live())->toBeFalse();

    expect(Setting::query()->where('key', 'tax_mode')->exists())
        ->toBeFalse('the package seeded a tax mode instead of leaving the shop as it found it');
});

it('leaves the cart total exactly where it was, with the same VAT figure printed', function () {
    $totals = taxTotals(taxCart(10000), 'AE');

    // AED 100 goods + AED 20 delivery. The default rate is 5% inclusive, so
    // the printed portion is 120.00 x 5 / 105 = 5.71 and the total is untouched.
    expect($totals['total'])->toBe(12000)
        ->and($totals['vat']['amount'])->toBe(571)
        ->and($totals['vat']['added'])->toBeFalse()
        ->and($totals['tax_charged'])->toBe(0)
        ->and($totals['tax_rate'])->toBeNull()
        ->and($totals['tax_basis'])->toBeNull();
});

it('writes a storefront order with no tax record at all', function () {
    $cart = taxCart(20000);

    taxShopper($cart)->post('/checkout/place', taxCheckoutForm())->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->tax_total)->toBe(0)
        ->and($order->tax_rate)->toBeNull()
        ->and($order->tax_basis)->toBeNull()
        ->and($order->total)->toBe(22000);
});

/*
|------------------------------------------------------------------------------
| 2. A SHOP ON `flat` TODAY DOES NOT START CHARGING
|------------------------------------------------------------------------------
|
| `flat` was never "exclusive". It printed rate x total and charged nothing, and
| it keeps meaning exactly that — including after the owner switches the engine
| on, because a basis is a per-row decision and nothing promotes one.
*/

it('keeps a flat-basis shop printing the same figure and charging nothing', function () {
    taxSet('vat_basis', TaxRule::FLAT);

    $before = taxTotals(taxCart(10000), 'AE');

    // 120.00 x 5% = 6.00 printed, total untouched.
    expect($before['total'])->toBe(12000)->and($before['vat']['amount'])->toBe(600);

    // And the same again once the engine is live: flat charges nothing, ever.
    taxLive();

    $after = taxTotals(taxCart(10000), 'AE');

    expect($after['total'])->toBe(12000, 'switching the engine on made a flat shop charge more')
        ->and($after['vat']['amount'])->toBe(600)
        ->and($after['vat']['added'])->toBeFalse()
        ->and($after['tax_charged'])->toBe(0, 'a flat basis claimed tax was inside the total')
        ->and($after['tax_basis'])->toBe(TaxRule::FLAT);
});

/*
|------------------------------------------------------------------------------
| 3. THE OWNER'S OWN EXAMPLE
|------------------------------------------------------------------------------
|
| "for uae the vat i can set inclusive, for Saudi i can set exclusive"
*/

it('charges the UAE inclusively and Saudi Arabia exclusively, from one table', function () {
    taxLive(
        ['AE' => '5', 'SA' => '15'],
        ['AE' => TaxRule::INCLUSIVE, 'SA' => TaxRule::EXCLUSIVE],
    );

    $uae = taxTotals(taxCart(10000), 'AE');

    // AED 100 + AED 20 delivery = 120.00. Inclusive: the shopper pays 120.00
    // and 5.71 of it is tax.
    expect($uae['total'])->toBe(12000)
        ->and($uae['tax_charged'])->toBe(571)
        ->and($uae['tax_basis'])->toBe(TaxRule::INCLUSIVE)
        ->and($uae['tax_rate'])->toBe(5.0)
        ->and($uae['vat']['added'])->toBeFalse();

    $saudi = taxTotals(taxCart(10000), 'SA');

    // AED 100 + AED 150 delivery = 250.00, +15% = 287.50 charged.
    expect($saudi['taxable_base'])->toBe(25000)
        ->and($saudi['tax_charged'])->toBe(3750)
        ->and($saudi['total'])->toBe(28750, 'an exclusive rate did not reach the total')
        ->and($saudi['tax_basis'])->toBe(TaxRule::EXCLUSIVE)
        ->and($saudi['vat']['added'])->toBeTrue()
        ->and($saudi['vat']['amount'])->toBe(3750);
});

it('falls back to the default rate and basis for a country with no row', function () {
    taxLive(['SA' => '15'], ['SA' => TaxRule::EXCLUSIVE]);
    taxSet('vat_rate', 5);
    taxSet('vat_basis', TaxRule::INCLUSIVE);

    $kuwait = taxTotals(taxCart(10000), 'KW');

    expect($kuwait['tax_basis'])->toBe(TaxRule::INCLUSIVE)
        ->and($kuwait['tax_rate'])->toBe(5.0)
        ->and($kuwait['total'])->toBe(25000);
});

it('ignores a basis for a country that has no rate of its own', function () {
    // A row that could only come from a hand-edited database or an older
    // build. Honouring it would apply an exclusive basis to the GLOBAL rate
    // for a country the owner cannot see in his own table.
    taxLive([], ['SA' => TaxRule::EXCLUSIVE]);

    expect(app(VatDisplay::class)->countryBases())->toBe([]);
    expect(app(VatDisplay::class)->ruleFor('SA')->basis)->toBe(TaxRule::INCLUSIVE);
    expect(taxTotals(taxCart(10000), 'SA')['total'])->toBe(25000);
});

it('refuses to charge a tax it is not allowed to print', function () {
    // One switch governs both halves. A shop with the VAT line hidden and an
    // exclusive rate set would be adding a surcharge with nothing on the page
    // to explain it.
    taxLive(['SA' => '15'], ['SA' => TaxRule::EXCLUSIVE]);
    taxSet('vat_enabled', false);

    $saudi = taxTotals(taxCart(10000), 'SA');

    expect($saudi['total'])->toBe(25000, 'tax was charged while the VAT line was switched off')
        ->and($saudi['tax_charged'])->toBe(0)
        ->and($saudi['vat'])->toBeNull();
});

/*
|------------------------------------------------------------------------------
| 4. THE HARD PARTS: COUPONS, THE FREE-DELIVERY BAR, ROUNDING
|------------------------------------------------------------------------------
*/

it('taxes what the customer actually pays, not what the coupon took off', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = taxCart(20000);
    // `amount` is HUNDREDTHS of a percent for a percentage coupon — 10% is 1000.
    $coupon = Coupon::create(['code' => 'TEN', 'type' => 'percent', 'amount' => 1000]);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    $totals = taxTotals($cart->fresh(['items']), 'AE');

    // 200.00 goods − 20.00 coupon + 20.00 delivery = 200.00 taxable,
    // +5% = 10.00 tax, 210.00 charged.
    expect($totals['discount'])->toBe(2000)
        ->and($totals['taxable_base'])->toBe(20000)
        ->and($totals['tax_charged'])->toBe(1000, 'tax was computed before the discount')
        ->and($totals['total'])->toBe(21000);
});

it('measures the free-delivery bar on the product total, so it does not move with the tax', function () {
    /*
     * The threshold means "spend this much on product". Measuring it on a
     * tax-inclusive figure would make it easier to reach in a 15% country than
     * in a 0% one — the shop giving delivery away for less real revenue in its
     * highest-taxed market — and would move the progress bar when the shopper
     * changed country for no reason they could see.
     */
    ShippingMethod::create([
        'shipping_zone_id' => ShippingZone::where('name', 'All UAE')->first()->id,
        'type' => 'free_shipping', 'title' => 'Free delivery',
        'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
    ]);

    taxLive(['AE' => '15'], ['AE' => TaxRule::EXCLUSIVE]);

    // AED 180 of product. With 15% on top the gross is over AED 199; the bar
    // must still say the shopper is AED 19 short.
    $totals = taxTotals(taxCart(18000), 'AE');

    expect($totals['subtotal'])->toBe(18000)
        ->and($totals['total'])->toBeGreaterThan(19900)
        ->and($totals['free_shipping_remaining'])->toBe(1900, 'the bar was measured on a tax-inclusive figure')
        ->and($totals['free_shipping_unlocked'])->toBeFalse();
});

it('rounds the tax once, on the order, in exact integers', function () {
    /*
     * PER-LINE ROUNDING DOES NOT SUM TO THE ORDER'S. Three lines of 3333 fils
     * at 5% exclusive round to 167 each — 501 — while the order's own base of
     * 9999 rounds to 500. One fil, on every invoice with an awkward basket,
     * and the invoice would disagree with what the card was charged.
     */
    $rule = new TaxRule(5.0, TaxRule::EXCLUSIVE);

    $perLine = $rule->taxOn(3333) * 3;
    $onOrder = $rule->taxOn(9999);

    expect($perLine)->toBe(501)
        ->and($onOrder)->toBe(500)
        ->and($perLine)->not->toBe($onOrder, 'this example no longer demonstrates anything');

    // And the engine uses the second one.
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    $totals = taxTotals(taxCart(3333, 3), 'AE');

    expect($totals['taxable_base'])->toBe(9999 + 2000)
        ->and($totals['tax_charged'])->toBe($rule->taxOn(11999));

    /*
     * INTEGER ARITHMETIC, for the reason CouponService::discountFor() sets out
     * about the identical sum. 17.5% of 20,000 fils is exactly 3,500; a float
     * path can land the wrong side of a half fil on values binary cannot hold.
     */
    expect((new TaxRule(17.5, TaxRule::EXCLUSIVE))->taxOn(20000))->toBe(3500)
        ->and((new TaxRule(5.0, TaxRule::INCLUSIVE))->taxOn(10000))->toBe(476)
        ->and((new TaxRule(15.0, TaxRule::INCLUSIVE))->taxOn(10000))->toBe(1304);
});

/*
|------------------------------------------------------------------------------
| 5. EVERY PATH THAT WRITES AN ORDER
|------------------------------------------------------------------------------
*/

it('charges and records the tax on a storefront order', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    taxSet('cod_fee', 1500);

    $cart = taxCart(20000);
    taxShopper($cart)->post('/checkout/place', taxCheckoutForm())->assertRedirect();

    $order = Order::latest('id')->first();

    // 200.00 + 20.00 delivery = 220.00 taxable, +5% = 11.00, +15.00 COD fee.
    expect($order->subtotal)->toBe(20000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->tax_total)->toBe(1100)
        ->and($order->fee_total)->toBe(1500)
        ->and($order->total)->toBe(22000 + 1100 + 1500)
        ->and((float) $order->tax_rate)->toBe(5.0)
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE);

    // The order adds up: subtotal − discount + delivery + tax + fees = total.
    expect($order->subtotal - $order->discount_total + $order->shipping_total + $order->tax_total + $order->fee_total)
        ->toBe($order->total);
});

it('charges and records the tax on the public API checkout too', function () {
    /*
     * /api/* is UNAUTHENTICATED (CLAUDE.md) and this endpoint creates real
     * orders and takes real payments. A tax engine that ran on the storefront
     * and not here would produce orders whose totals do not add up.
     */
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $product = Product::create([
        'slug' => 'api-tax-' . Str::random(6), 'name' => 'API Serum',
        'status' => 'publish', 'is_visible' => true, 'price' => 20000, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha', 'email' => 'api@example.com', 'country' => 'AE'],
        'method' => 'cod',
    ])->assertCreated();

    $order = Order::latest('id')->first();

    expect($order->subtotal)->toBe(20000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->tax_total)->toBe(1100)
        ->and($order->total)->toBe(23100)
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE);
});

it('charges and records the tax on a back-office order', function () {
    \Tests\ManualOrders::registerRoutes();
    // shop() creates its own zones and its own COD provider; beforeEach above
    // already made both, and payment_providers.id is the primary key.
    PaymentProvider::query()->delete();
    ShippingZone::query()->delete();
    \Tests\ManualOrders::shop();
    $admin = \Tests\ManualOrders::admin();
    $customer = \Tests\ManualOrders::customer();
    $item = \Tests\ManualOrders::product('Sheet mask', 1500);

    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/manual-orders', \Tests\ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
        ]))->assertCreated();

    $order = Order::latest('id')->first();

    // AED 15 + AED 20 delivery = 35.00 taxable, +5% = 1.75.
    expect($order->subtotal)->toBe(1500)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->tax_total)->toBe(175)
        ->and($order->total)->toBe(3675)
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE);
});

it('sends the moved total and the new row position back from the country-change endpoint', function () {
    taxLive(['AE' => '5', 'SA' => '15'], ['AE' => TaxRule::INCLUSIVE, 'SA' => TaxRule::EXCLUSIVE]);

    $cart = taxCart(10000);

    $uae = taxShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk();

    expect($uae->json('vat.added'))->toBeFalse();
    expect(strip_tags((string) $uae->json('total')))->toContain('120');

    $saudi = taxShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA'])->assertOk();

    /*
     * 100.00 + 150.00 delivery = 250.00 taxable, +15% = 287.50 charged, and
     * the string now reads AED 287.50 rather than AED 288 — Lane FA.
     *
     * THIS IS THE ONE CASE WHERE "NO DECIMALS" CANNOT HOLD, and the assertion
     * is where it is written down. The owner's answer to a basket that did not
     * add up was "no decimals. if any decimals comes. adjust to the price",
     * and App\Support\WholeDirhams makes that true of every figure the shop
     * SETS. An EXCLUSIVE VAT rate is not one of those: 15% of a whole-dirham
     * base is AED 37.50, the tax is added on top, and the total the customer
     * is charged carries fils however tidy the inputs were. Rounding it would
     * mean charging or recording a tax that is not the rate — which is not the
     * shop's to round.
     *
     * So the ledger WIDENS instead. Money::receiptDecimals() takes the whole
     * column to the currency's precision the moment one figure needs it, which
     * is why this reads 287.50 and not 288: honest beats tidy, and a receipt
     * that rounds a charged total is a receipt that misstates money.
     *
     * Reachable only with tax_mode = 'live' AND a country on an exclusive
     * basis — neither of which is the shipped configuration.
     */
    expect($saudi->json('vat.added'))->toBeTrue('the endpoint never told the page the row has to move');
    expect(strip_tags((string) $saudi->json('total')))->toContain('287.50');
    /*
     * str_contains() inside toBeFalse(). The old ->not->toContain('288',
     * $message) asserted nothing -- toContain() is VARIADIC, so the message was
     * a second needle and `not` passed because the total never contains that
     * sentence. Measured with '288' appended to the total: still green.
     */
    expect(str_contains(strip_tags((string) $saudi->json('total')), '288'))->toBeFalse(
        'an exclusive tax was rounded into a total nobody was charged');
});

/*
|------------------------------------------------------------------------------
| 6. THE ORDER KEEPS ITS OWN RECORD
|------------------------------------------------------------------------------
*/

it('reprints an old invoice at the rate it was charged, not the rate set later', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = taxCart(20000);
    taxShopper($cart)->post('/checkout/place', taxCheckoutForm())->assertRedirect();

    $order = Order::latest('id')->first();
    expect($order->tax_total)->toBe(1100);

    // Next year the owner raises the UAE to 15% — the country row and the
    // shop default together, which is what changing a rate really looks like.
    taxSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '15']));
    taxSet('vat_rate', 15);

    $document = app(\App\Services\Invoices\InvoiceDocument::class)->present($order->fresh());

    $vatRow = collect($document['totals'])->first(fn ($r) => str_contains((string) $r['label'], 'VAT'));

    expect($vatRow)->not->toBeNull('the invoice stopped showing the tax it charged');
    expect($vatRow['fils'])->toBe(1100, 'the invoice reprinted at the rate set afterwards');
    expect((string) $vatRow['label'])->toContain('5%');
});

it('leaves a historical order reading zero, and does not invent a rate for it', function () {
    /*
     * Every order in the table was placed under D-64. NULL on the two new
     * columns means "this order has no tax record", and every reader branches
     * on it. Backfilling a figure would be inventing a tax record.
     */
    $order = Order::create([
        'order_number' => 'KBB-OLD-1', 'email' => 'old@example.com', 'status' => 'completed',
        'currency' => 'AED', 'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 22000,
    ]);

    expect($order->tax_rate)->toBeNull()
        ->and($order->tax_basis)->toBeNull()
        ->and($order->tax_total)->toBe(0)
        ->and(\App\Support\OrderTax::recorded($order))->toBeNull();

    /*
     * ── AND IT PRINTS NO TAX FIGURE AT ALL — LANE DU ───────────────────────
     *
     * This used to assert the opposite: that the old order "still prints the
     * display-only note the shop was showing then". It did not print the note
     * the shop was showing THEN — it printed the note the shop is showing NOW,
     * recomputed at print time from today's `vat_rate`. Raise the rate and this
     * order's invoice restated its own tax figure, years after it was filed.
     *
     * The figure on that note was never recoverable: `settings` keeps one row
     * per key with no history, so nothing anywhere records what the rate was on
     * the day. Lane DU therefore removed the recomputation rather than freezing
     * or backfilling it — an order with no tax record gets no tax figure. The
     * reasoning is in InvoiceDocument::vatNote().
     *
     * Note what has NOT changed, which is the more important half: no rate is
     * invented on the order, no VAT row appears among the figures that sum to
     * the Total, and the Total is untouched.
     */
    $document = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);

    expect($document['vatNote'])->toBeNull('an old order states a VAT figure recomputed from today\'s settings');
    expect(collect($document['totals'])->contains(fn ($r) => str_contains((string) $r['label'], 'VAT')))
        ->toBeFalse('an old order started printing VAT as a row that adds to its total');
    expect($document['totalFils'])->toBe(22000);
});

it('keeps an inclusive order tax out of the rows that sum to the total', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::INCLUSIVE]);

    $cart = taxCart(20000);
    taxShopper($cart)->post('/checkout/place', taxCheckoutForm())->assertRedirect();

    $order = Order::latest('id')->first();

    expect($order->tax_total)->toBeGreaterThan(0, 'an inclusive live order recorded no tax at all')
        ->and($order->total)->toBe(22000, 'an inclusive basis charged the customer extra');

    $document = app(\App\Services\Invoices\InvoiceDocument::class)->present($order);

    $rows = collect($document['totals']);

    expect($rows->contains(fn ($r) => str_contains((string) $r['label'], 'VAT')))
        ->toBeFalse('the contained VAT was added as a row, so the column no longer sums to the total');

    expect($rows->filter(fn ($r) => ! $r['strong'])->sum('fils'))
        ->toBe($order->total, 'the invoice rows do not add up to what was charged');

    expect($document['vatNote']['fils'])->toBe($order->tax_total);
});

/*
|------------------------------------------------------------------------------
| 7. REFUNDS
|------------------------------------------------------------------------------
*/

it('lets a full refund give back the tax as well', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = taxCart(20000);
    taxShopper($cart)->post('/checkout/place', taxCheckoutForm())->assertRedirect();

    $order = Order::latest('id')->first();
    $order->forceFill(['paid_at' => now()])->save();

    $refunder = app(\App\Services\Payments\PaymentRefunder::class);

    expect($refunder->capturedFils($order->fresh()))
        ->toBe($order->total, 'the refund ceiling does not include the tax that was charged');

    $outcome = $refunder->refund($order->fresh(), $order->total, 'customer changed their mind', 'owner');

    expect($outcome->code)->not->toBe('over_captured');
    expect($outcome->ok)->toBeTrue($outcome->message);
    expect($refunder->refundedFils($order->fresh()))->toBe($order->total);
});

/*
|------------------------------------------------------------------------------
| 8. THE SETTINGS ROUND TRIP
|------------------------------------------------------------------------------
*/

it('actually writes the per-country bases instead of reporting success and dropping them', function () {
    /*
     * SETTING_RULES carries a standing warning: a key that is not on it is
     * dropped from the payload while the endpoint still answers ok. This
     * asserts the CONSEQUENCE — a reader sees the new basis — not that a 200
     * came back.
     */
    taxAdmin();

    $response = test()->putJson('/admin-api/settings', [
        'settings' => [
            'tax_mode' => VatDisplay::MODE_LIVE,
            'vat_country_rates' => json_encode(['SA' => '15']),
            'vat_country_bases' => json_encode(['SA' => TaxRule::EXCLUSIVE]),
        ],
    ])->assertOk();

    expect($response->json('rejected'))->toBeNull('a tax key was rejected by the settings allowlist');

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(VatDisplay::class)->live())->toBeTrue()
        ->and(app(VatDisplay::class)->ruleFor('SA')->basis)->toBe(TaxRule::EXCLUSIVE)
        ->and(app(VatDisplay::class)->ruleFor('SA')->rate)->toBe(15.0);
});

it('refuses a basis it has never emitted, and saves nothing at all', function () {
    taxAdmin();

    $response = test()->putJson('/admin-api/settings', [
        'settings' => ['vat_country_bases' => json_encode(['SA' => 'reverse_charge'])],
    ])->assertStatus(422);

    expect(str_contains((string) $response->json('message'), 'Saudi Arabia'))
        ->toBeTrue('the refusal did not name the country at fault');

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(VatDisplay::class)->countryBases())->toBe([]);
});

it('refuses a tax mode it has never emitted', function () {
    taxAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['tax_mode' => 'whenever']])
        ->assertStatus(422);

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(VatDisplay::class)->mode())->toBe(VatDisplay::MODE_DISPLAY);
});

it('no longer offers the VAT fields on the Ecommerce screen, so one screen owns them', function () {
    taxAdmin();

    $tabs = test()->get('/admin-api/ecommerce')->assertOk()->json('tabs');

    $offered = [];

    foreach ($tabs as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $offered[] = $field['name'];
            }
        }
    }

    expect(array_values(array_filter($offered, fn ($n) => str_starts_with($n, 'vat_'))))
        ->toBe([], 'two screens still edit the VAT settings');

    // And the consequence: posting one there is not stored.
    test()->postJson('/admin-api/ecommerce', ['settings' => ['vat_basis' => TaxRule::EXCLUSIVE]])
        ->assertOk()
        ->assertJsonPath('saved', 0);

    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(app(VatDisplay::class)->defaultRule()->basis)->toBe(TaxRule::INCLUSIVE);
});

/*
|------------------------------------------------------------------------------
| 9. THE SCREEN THE OWNER WENT LOOKING FOR
|------------------------------------------------------------------------------
|
| "ALSO i can not see the TAX seperate tab on the Business Setting page."
*/

it('puts a Tax tab on the Business Details screen, where he went to look for it', function () {
    $html = view('admin.app')->render();

    // An ELEMENT with the class, not a bare string: a class-name search of the
    // console also matches its own inlined CSS.
    expect(preg_match_all('/<[a-z]+[^>]*class="[^"]*\bbd-tab\b/i', $html))
        ->toBeGreaterThan(0, 'Business Details has no tab strip at all');

    expect(str_contains($html, 'data-bdtab="tax"'))
        ->toBeTrue('there is no Tax tab on the Business Details screen');

    expect(str_contains($html, "data-bdpanel=\"tax\""))
        ->toBeTrue('the Tax tab has no panel to show');
});

it('leaves no dead end where the VAT rate used to be', function () {
    $html = view('admin.app')->render();

    expect(str_contains($html, 'Now on the Tax tab'))
        ->toBeTrue('the VAT rate vanished from Business Details with nothing saying where it went');
});

it('gives the sidebar a Tax row that opens the same screen', function () {
    $html = view('admin.app')->render();

    expect(str_contains($html, "['tax','Tax'"))
        ->toBeTrue('the sidebar has no Tax entry, so the word he is scanning for is not in the list');

    expect(str_contains($html, "if(id==='tax'){ _go(id); return renderStoreSettings('tax'); }"))
        ->toBeTrue('the sidebar Tax row does not open the Tax tab');
});

it('offers one-click presets as the way in, and still asks him to confirm them', function () {
    /*
     * THE SHARED MECHANISM, NOT A SECOND ONE. Lane CY built pstBarHtml/pstBind
     * and App\Support\CountryPresets for the delivery lines and made it
     * reusable; this screen registers a group and renders the same bar. Two
     * implementations of "offer the owner one-click preset rows for a country
     * table" would drift, and this project has already had to merge two screens
     * that did.
     */
    $html = view('admin.app')->render();

    expect(str_contains($html, "pstBarHtml('tax'"))
        ->toBeTrue('the Tax tab does not render the shared preset bar');

    expect(str_contains($html, "pstBind('taxPresets'"))
        ->toBeTrue('the shared preset bar on the Tax tab is never bound, so its chips do nothing');

    expect(str_contains($html, 'KBB_VAT_SUGGESTIONS'))
        ->toBeTrue('the per-country suggestion chips are not on the screen');

    expect(preg_match('/[Cc]onfirm (?:them|these|the rate)/', $html))
        ->toBe(1, 'the screen never asks the owner to confirm the suggested rates');

    expect(str_contains($html, 'not tax advice'))
        ->toBeTrue('the screen presents the suggested rates as though they were authoritative');
});

it('reads its suggested rates off the shared group rather than a list of its own', function () {
    // The figures the chips offer are CountryPresets::TAX's, so changing a rate
    // there changes the chips, the "fill in all" button and the console's own
    // KBB_VAT_SUGGESTIONS together.
    $group = \App\Support\CountryPresets::forConsole(\App\Support\CountryPresets::TAX);

    expect(collect($group['rows'])->pluck('value', 'code')->all())
        ->toBe(['AE' => '5', 'SA' => '15', 'KW' => '0', 'QA' => '0', 'BH' => '10', 'OM' => '5']);

    // No {country} anywhere in a numeric group, so the console's automatic-name
    // tick box — which only appears for a group with a dynamic row — does not
    // show up on a screen full of percentages.
    expect(collect($group['rows'])->contains(fn (array $r) => $r['dynamic']))
        ->toBeFalse('a rate group offered the automatic-country-name control');

    $html = view('admin.app')->render();

    expect(str_contains($html, 'CountryPresets::TAX'))
        ->toBeTrue('the console does not emit the tax preset group, so its chips have nothing to offer');
});

/*
 * A PRESET WRITES NOTHING UNTIL SAVE. The delivery lane proved this pair by
 * mutation and the failure is worse here: a delivery sentence that leaked into
 * the database is a promise the owner did not make, while a RATE that leaked
 * changes what a receipt says about money, in a country he never opened.
 *
 * Two tests, deliberately, because they fail for different reasons. The first
 * asserts the database was not touched; the second asserts the consequence —
 * what a Saudi shopper is actually charged and told — because a rate could
 * reach a shopper by a route that leaves no settings row at all.
 */
it('never pre-saves a tax rate, a basis or a mode it merely suggests', function () {
    view('admin.app')->render();

    foreach (['vat_country_rates', 'vat_country_bases', 'tax_mode'] as $key) {
        expect(Setting::query()->where('key', $key)->exists())
            ->toBeFalse("merely opening the console saved {$key}");
    }
});

it('leaves a Saudi shopper untaxed until the owner has actually saved a rate', function () {
    // The console offers Saudi Arabia 15%. Rendering it must not move one fil
    // of anything a Saudi shopper is charged or shown.
    $before = taxTotals(taxCart(10000), 'SA');

    view('admin.app')->render();

    SettingsService::forgetMemo();
    Setting::flushMap();
    app(SettingsService::class)->flush();

    $after = taxTotals(taxCart(10000), 'SA');

    // AED 100 goods + AED 150 Gulf delivery, and the shop's own 5% inclusive
    // default — not the 15% the chip suggests, and nothing added on top.
    expect($after['total'])->toBe(25000)
        ->and($after['total'])->toBe($before['total'], 'opening the console changed what a Saudi shopper pays')
        ->and($after['tax_charged'])->toBe(0)
        ->and($after['tax_rate'])->toBeNull()
        ->and(app(VatDisplay::class)->rate('SA'))->toBe(5.0, 'the suggested Saudi rate reached the live shop')
        ->and(app(VatDisplay::class)->countryRates())->toBe([])
        ->and(app(VatDisplay::class)->countryBases())->toBe([]);

    // And the receipt line still says 5%, which is what the shop is set to.
    expect($after['vat']['label'])->toBe("You're paying VAT (5%)");
});

it('gives every row a basis control and warns on the one that takes money', function () {
    $html = view('admin.app')->render();

    expect(str_contains($html, 'data-vat-basis='))
        ->toBeTrue('the per-country table has no basis column, so the owner cannot set one');

    expect(str_contains($html, 'vr-charges'))
        ->toBeTrue('an exclusive row looks exactly like the two that charge nothing');
});

/*
|------------------------------------------------------------------------------
| 10. THE CHECKOUT SUMMARY STILL ADDS UP
|------------------------------------------------------------------------------
*/

it('draws an exclusive tax above the Total and an inclusive one below it', function () {
    taxLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = taxCart(10000);
    $html = taxShopper($cart)->get('/checkout')->assertOk()->getContent();

    $addAt = strpos($html, 'js-vat-row vat-add');
    $totalAt = strpos($html, 'js-total-row');
    $noteAt = strpos($html, 'js-vat-row vat-note');

    expect($addAt)->not->toBeFalse('the adding VAT row is not on the page at all');
    expect($addAt)->toBeLessThan((int) $totalAt, 'an exclusive tax is printed under the total it is part of');
    expect($noteAt)->toBeGreaterThan((int) $totalAt);

    /*
     * The adding row is the visible one; the note is hidden.
     *
     * Asserted on the `hidden` ATTRIBUTE rather than on an inline
     * `style="display:none"` — Lane DE. Both rows carried the style because the
     * attribute did nothing on this page until store/checkout.blade.php began
     * shipping `.kbb-checkout [hidden]{display:none!important}`; they now use
     * the attribute, like the gift row and the delivery line beside them. What
     * is being asserted is unchanged: exactly one of the pair is on screen, and
     * it is the one on the correct side of the Total.
     */
    expect(preg_match('/js-vat-row vat-add"[^>]*hidden/', $html))
        ->toBe(0, 'the row that carries the charged tax is hidden');
    expect(preg_match('/js-vat-row vat-note"[^>]*hidden/', $html))
        ->toBe(1, 'both VAT rows are visible at once');
});

it('keeps the front end able to move the row when the country changes', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));

    expect(str_contains($js, 'js-vat-row'))
        ->toBeTrue('the country-change refresh cannot move the VAT row, only rewrite its figure');

    expect(str_contains($js, 'data.vat.added'))
        ->toBeTrue('the refresh ignores which side of the Total the tax belongs on');
});
