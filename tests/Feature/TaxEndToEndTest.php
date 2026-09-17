<?php

declare(strict_types=1);

/**
 * Lane DQ — one order, followed from the shopper's country to the invoice.
 *
 * ── WHY THIS FILE EXISTS BESIDE TaxEngineTest ──────────────────────────────
 *
 * TaxEngineTest proves each hop of the tax engine in isolation: the rule
 * resolves, the order records it, the invoice reprints it. This file proves the
 * hops AGREE WITH EACH OTHER. Those are different properties, and the second is
 * the one a customer notices: every individual figure can be right and the
 * column still fail to add up, or the email can say one thing and the invoice
 * beside it another.
 *
 * So the assertions here are mostly equalities BETWEEN two paths rather than
 * against a constant:
 *
 *   - what the three order writers charge for the same basket;
 *   - what the three documents print for the same stored order;
 *   - what a column of printed rows sums to versus what the card was charged.
 *
 * ── EVERY FIGURE IS AN EXACT INTEGER OF FILS ───────────────────────────────
 *
 * Money is integer fils (AED x 100) throughout this application. A test that
 * asserted "about AED 63" would pass on 6310 and on 6311, and the gap between
 * those two is the entire subject of this file. Nothing here compares a
 * formatted string where an integer will do, which also keeps these assertions
 * independent of `currency_decimals` — Money::displayDecimals() is 0 on this
 * shop, so the STOREFRONT prints whole dirhams while the receipt prints fils,
 * and a string comparison would be pinning that display choice rather than the
 * arithmetic.
 *
 * ── THE ROUNDING RULE, STATED ONCE ─────────────────────────────────────────
 *
 * App\Support\TaxRule::taxOn() rounds HALF UP (away from zero on a positive
 * amount), ONCE, on the whole taxable base, in integer arithmetic — never per
 * line and summed, because n roundings do not add up to one rounding. Section 2
 * pins both directions and the exact-half case, which is the only one where a
 * rule can be got wrong without it showing.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\OrderTax;
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

    // The UAE at AED 20 and the Gulf at AED 150 — the same miniature of the two
    // production zones TaxEngineTest builds, so a country change moves a real
    // delivery charge as well as a tax rate.
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

/*
|------------------------------------------------------------------------------
| Helpers
|------------------------------------------------------------------------------
|
| Named e2e* rather than reusing TaxEngineTest's tax* helpers: Pest loads only
| the files in the current run, so a file that borrowed another file's global
| functions would pass in a full-suite run and fatal when run on its own.
*/

/** Every settings write goes through the service, then both memos are dropped. */
function e2eSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

/** The shop as the owner leaves it once he has filled in the Tax tab. */
function e2eLive(array $rates = [], array $bases = []): void
{
    e2eSet('tax_mode', VatDisplay::MODE_LIVE);

    if ($rates !== []) {
        e2eSet(VatDisplay::COUNTRY_RATES_KEY, json_encode($rates));
    }

    if ($bases !== []) {
        e2eSet(VatDisplay::COUNTRY_BASES_KEY, json_encode($bases));
    }
}

function e2eCart(int $unitPriceFils = 10000, int $qty = 1, string $country = 'AE'): Cart
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
            'slug' => 'e2e-' . Str::random(8),
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

function e2eTotals(Cart $cart, ?string $country = null): array
{
    return app(CartService::class)->totals($cart, $country, null, (int) (
        app(\App\Services\ShippingService::class)->ratesFor($country ?? 'AE', null, 0, false)[0]['cost'] ?? 0
    ));
}

function e2eShopper(Cart $cart)
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function e2eForm(array $overrides = []): array
{
    return array_merge([
        'billing_email' => 'buyer@example.com',
        'billing_first_name' => 'Aisha',
        'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk',
        'billing_city' => 'Dubai',
        'billing_state' => 'Dubai',
        'billing_country' => 'AE',
        'payment_method' => 'cod',
    ], $overrides);
}

function e2eAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Tax Owner',
        'email' => 'e2e-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Place a storefront order for this basket and hand back the row that was written. */
function e2ePlace(Cart $cart, array $form = []): Order
{
    e2eShopper($cart)->post('/checkout/place', e2eForm($form))->assertRedirect();

    return Order::latest('id')->first();
}

/**
 * The rows a document prints, as [label => fils], plus what they sum to.
 *
 * Both presenters return the identical row shape — a list of
 * {label, fils, html, plain, strong} with exactly one `strong` row, the Total —
 * which is what lets one helper read an email and an invoice and lets the tests
 * below compare them row for row.
 *
 * @return array{rows: array<string,int>, sum: int, total: int}
 */
function e2eBreakdown(array $document): array
{
    $rows = [];
    $sum = 0;
    $total = null;

    foreach ($document['totals'] as $row) {
        if ($row['strong']) {
            $total = (int) $row['fils'];

            continue;
        }

        $rows[$row['label']] = (int) $row['fils'];
        $sum += (int) $row['fils'];
    }

    return ['rows' => $rows, 'sum' => $sum, 'total' => (int) $total];
}

/*
|------------------------------------------------------------------------------
| 1. INCLUSIVE VERSUS EXCLUSIVE: WHAT THE SHOPPER ACTUALLY PAYS
|------------------------------------------------------------------------------
|
| The owner's own example, in his own words: "for uae the vat i can set
| inclusive, for Saudi i can set exclusive". The two have to differ in the one
| way that matters — the amount charged — and agree in every other.
*/

it('charges an inclusive country the shelf price and finds the tax inside it', function () {
    // AE 5% inclusive. AED 100 of goods, AED 20 delivery.
    e2eLive(['AE' => '5'], ['AE' => TaxRule::INCLUSIVE]);

    $order = e2ePlace(e2eCart(10000));

    // The taxable base is subtotal - discount + delivery = 12000 fils, and the
    // shopper pays exactly that. Nothing was ADDED.
    expect($order->total)->toBe(12000)
        ->and($order->subtotal)->toBe(10000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->tax_basis)->toBe(TaxRule::INCLUSIVE);

    // 12000 x 500 / 10500 = 571.428..., so 571 fils of that 12000 IS the tax.
    expect($order->tax_total)->toBe(571);

    // And the tax is a portion OF the total, never a row above it.
    expect(OrderTax::recorded($order)['added'])->toBeFalse(
        'an inclusive tax must never be added to the total'
    );
});

it('charges an exclusive country the shelf price plus the tax on top', function () {
    // SA 15% exclusive. AED 100 of goods, AED 150 Gulf delivery.
    e2eLive(['SA' => '15'], ['SA' => TaxRule::EXCLUSIVE]);

    $order = e2ePlace(
        e2eCart(10000, 1, 'SA'),
        // `billing_state` is `required` on this form for every country, not
        // only for the emirates, so a Gulf order still names its region.
        ['billing_country' => 'SA', 'billing_state' => 'Riyadh', 'billing_city' => 'Riyadh']
    );

    // Base 10000 + 15000 = 25000. 15% OF that is 3750, ADDED.
    expect($order->subtotal)->toBe(10000)
        ->and($order->shipping_total)->toBe(15000)
        ->and($order->tax_total)->toBe(3750)
        ->and($order->total)->toBe(28750)
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE);

    expect(OrderTax::recorded($order)['added'])->toBeTrue(
        'an exclusive tax is part of the sum and belongs above the total'
    );
});

it('leaves one basket costing different money in the two countries, and only that', function () {
    e2eLive(['AE' => '5', 'SA' => '5'], ['AE' => TaxRule::INCLUSIVE, 'SA' => TaxRule::EXCLUSIVE]);

    // The SAME rate in both, so the only variable left is the basis.
    $inclusive = e2eTotals(e2eCart(10000), 'AE');
    $exclusive = e2eTotals(e2eCart(10000), 'SA');

    // Identical taxable arithmetic on each side, different consequence.
    expect($inclusive['tax_added'])->toBeFalse()
        ->and($exclusive['tax_added'])->toBeTrue();

    // The inclusive shopper pays the base; the exclusive one pays base + tax.
    expect($inclusive['total'])->toBe($inclusive['taxable_base'])
        ->and($exclusive['total'])->toBe($exclusive['taxable_base'] + $exclusive['tax_charged']);
});

/*
|------------------------------------------------------------------------------
| 2. ROUNDING: HALF UP, ONCE, ON THE WHOLE BASE
|------------------------------------------------------------------------------
|
| The brief asks which way it rounds and whether the total charged and the tax
| line printed agree about it. They are the same integer by construction, and
| these tests are what stops that staying true only by accident.
*/

it('rounds an exclusive tax that lands exactly on half a fil upwards', function () {
    /*
     * THE EXACT-HALF CASE, which is the only one that can distinguish the
     * rules. AED 40.10 of goods + AED 20.00 delivery = a base of 6010 fils.
     * 6010 x 5% = 300.5 fils exactly — half a fil, dead on the boundary.
     *
     * Half UP, so 301. A truncating implementation would say 300 and nothing
     * else in the suite would notice, because every other figure in it divides.
     */
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $order = e2ePlace(e2eCart(4010));

    expect($order->subtotal + $order->shipping_total)->toBe(6010)
        ->and($order->tax_total)->toBe(301)
        ->and($order->total)->toBe(6311);

    // Stated directly against the rule as well, so the property survives even
    // if the checkout stops being the thing that reaches it.
    expect((new TaxRule(5.0, TaxRule::EXCLUSIVE))->taxOn(6010))->toBe(301);
});

it('rounds an inclusive extraction to the nearest fil in both directions', function () {
    $rule = new TaxRule(5.0, TaxRule::INCLUSIVE);

    /*
     * Neither of these divides evenly — an inclusive extraction at 5% is
     * base/21, which is a whole number of fils only every twenty-first fil.
     *
     *   12000 / 21 = 571.428...  -> 571, the nearest is DOWN
     *   10100 / 21 = 480.952...  -> 481, the nearest is UP
     *
     * Nearest, not "always down" and not "always up". A floor would give 480
     * for the second and under-report the tax on the receipt by a fil.
     */
    expect($rule->taxOn(12000))->toBe(571)
        ->and($rule->taxOn(10100))->toBe(481);

    // And an inclusive extraction never moves the total, however it rounds.
    expect($rule->grossOf(12000))->toBe(12000)
        ->and($rule->grossOf(10100))->toBe(10100);
});

it('keeps the tax charged and the tax printed the same integer when it does not divide', function () {
    // The rounding happens ONCE. If the charge and the printed line reached it
    // by two routes they could land a fil apart, and the receipt would state a
    // tax figure the card was never charged.
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = e2eCart(4010);
    $totals = e2eTotals($cart, 'AE');

    expect($totals['tax_charged'])->toBe(301)
        ->and($totals['vat']['amount'])->toBe(301)
        ->and($totals['total'])->toBe(6311);

    $order = e2ePlace(e2eCart(4010));

    // The same figure again once it is a stored row, and again on the receipt.
    $email = e2eBreakdown(app(OrderEmailPresenter::class)->present($order));
    $invoice = e2eBreakdown(app(InvoiceDocument::class)->present($order));

    expect($order->tax_total)->toBe(301)
        ->and($email['rows']['VAT at 5%'])->toBe(301)
        ->and($invoice['rows']['VAT at 5%'])->toBe(301);
});

it('rounds once on the whole base rather than once per line', function () {
    /*
     * Three lines of AED 40.10 and no delivery-free trickery: rounding each
     * line and summing gives 3 x 301 = 903, while rounding the one base gives
     * 5% of 14030 = 701.5 -> 702... the two answers are simply different
     * numbers, and only the second is what the customer is charged.
     */
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $order = e2ePlace(e2eCart(4010, 3));

    $base = $order->subtotal + $order->shipping_total;   // 12030 + 2000
    expect($base)->toBe(14030)
        ->and($order->tax_total)->toBe(702)
        ->and($order->tax_total)->not->toBe(3 * 301,);

    // order_items carries no per-line tax at all, by design — numbers that do
    // not sum to the order's are worse than no numbers.
    expect((int) $order->items()->sum('tax_total'))->toBe(0);
});

/*
|------------------------------------------------------------------------------
| 3. THE PRINTED LINES SUM TO THE AMOUNT CHARGED
|------------------------------------------------------------------------------
|
| Subtotal + delivery + tax - discount + fees = total, exactly, in fils, on the
| confirmation email, on the invoice and on the admin screen — all three read
| from the STORED order rather than recomputed live.
*/

it('adds a full exclusive order up to the penny on the email and the invoice alike', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    e2eSet('cod_fee', 1500);
    e2eSet('gift_enabled', '1');
    e2eSet('gift_fee', 1000);

    // Everything at once: goods, delivery, a gift fee, a COD surcharge and tax.
    $order = e2ePlace(e2eCart(4010), ['is_gift' => '1']);

    $email = e2eBreakdown(app(OrderEmailPresenter::class)->present($order));
    $invoice = e2eBreakdown(app(InvoiceDocument::class)->present($order));

    // The column of rows sums to the Total row, on both documents.
    expect($email['sum'])->toBe($email['total'])
        ->and($invoice['sum'])->toBe($invoice['total']);

    // And that Total is the figure actually stored against the order.
    expect($email['total'])->toBe((int) $order->total)
        ->and($invoice['total'])->toBe((int) $order->total);

    // The two documents print the same rows as each other, not merely two
    // columns that happen to sum alike.
    expect($email['rows'])->toBe($invoice['rows']);

    // Spelled out once, so a future reader can see the shape of the order.
    expect($email['rows'])->toBe([
        'Subtotal' => 4010,
        'Delivery' => 2000,
        'Gift wrapping' => 1000,
        'Cash on delivery fee' => 1500,
        'VAT at 5%' => 301,
    ]);
});

it('adds an inclusive order up without counting its tax twice', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::INCLUSIVE]);

    $order = e2ePlace(e2eCart(10000));

    $email = app(OrderEmailPresenter::class)->present($order);
    $invoice = app(InvoiceDocument::class)->present($order);

    $emailRows = e2eBreakdown($email);
    $invoiceRows = e2eBreakdown($invoice);

    // The rows still sum to the total...
    expect($emailRows['sum'])->toBe($emailRows['total'])
        ->and($invoiceRows['sum'])->toBe($invoiceRows['total']);

    // ...precisely BECAUSE the tax is not among them. An inclusive tax is a
    // portion of the figures above it; a row would make the column overstate
    // what was charged by 571 fils.
    expect(array_keys($emailRows['rows']))->not->toContain('VAT at 5%');

    // It is stated underneath instead, as a note, at the same exact figure.
    expect($email['vatNote']['fils'])->toBe(571)
        ->and($invoice['vatNote']['fils'])->toBe(571)
        ->and($invoice['vatNote']['label'])->toBe('Includes VAT at 5%');
});

it('shows the admin the same arithmetic the customer was sent', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    e2eSet('cod_fee', 1500);

    $order = e2ePlace(e2eCart(4010));

    e2eAdmin();
    $json = test()->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json();

    // Back to fils: the screen speaks AED, the ledger speaks fils, and only
    // one of them is exact.
    $fils = static fn (float|int $aed): int => (int) round($aed * 100);

    expect($fils($json['subtotal_aed']))->toBe(4010)
        ->and($fils($json['shipping_total_aed']))->toBe(2000)
        ->and($fils($json['discount_total_aed']))->toBe(0)
        ->and($fils($json['fee_total_aed']))->toBe(1500)
        ->and($fils($json['vat']['amount_aed']))->toBe(301)
        ->and($fils($json['total_aed']))->toBe((int) $order->total);

    // The admin screen's own figures add up, on the same identity as the
    // documents: subtotal - discount + delivery + fees + added tax = total.
    expect(
        $fils($json['subtotal_aed'])
        - $fils($json['discount_total_aed'])
        + $fils($json['shipping_total_aed'])
        + $fils($json['fee_total_aed'])
        + $fils($json['vat']['amount_aed'])
    )->toBe($fils($json['total_aed']));

    // And it says which of the two kinds of tax figure this is, in words.
    expect($json['vat']['label'])->toBe('VAT at 5% (added to the total)');
});

it('adds a discounted order up with the tax charged on what was actually paid', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $coupon = \App\Models\Coupon::create([
        'code' => 'E2ETEN', 'type' => 'percent', 'amount' => 1000,
    ]);

    $cart = e2eCart(10000);
    $cart->update(['coupon_id' => $coupon->id]);

    $order = e2ePlace($cart->fresh(['items']));

    // 10000 - 1000 discount + 2000 delivery = 11000 taxable, +5% = 550.
    expect($order->discount_total)->toBe(1000)
        ->and($order->tax_total)->toBe(550)
        ->and($order->total)->toBe(11550);

    $email = e2eBreakdown(app(OrderEmailPresenter::class)->present($order));

    // The discount prints NEGATIVE, so the column still sums to the total.
    expect($email['rows']['Discount (E2ETEN)'])->toBe(-1000)
        ->and($email['sum'])->toBe($email['total'])
        ->and($email['total'])->toBe(11550);
});

/*
|------------------------------------------------------------------------------
| 4. A RATE CHANGE DOES NOT REACH BACKWARDS
|------------------------------------------------------------------------------
|
| The owner can move Saudi Arabia from 5% to 15% next year. Last year's invoice
| is a document somebody filed with an authority, and it has to keep saying what
| it said on the day.
*/

it('reprints a placed order at the rate it was charged, everywhere it is printed', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $order = e2ePlace(e2eCart(4010));

    $before = [
        'tax' => (int) $order->tax_total,
        'total' => (int) $order->total,
        'email' => e2eBreakdown(app(OrderEmailPresenter::class)->present($order)),
        'invoice' => e2eBreakdown(app(InvoiceDocument::class)->present($order)),
    ];

    // A year passes and the owner triples the rate and flips the basis.
    e2eSet(VatDisplay::COUNTRY_RATES_KEY, json_encode(['AE' => '15']));
    e2eSet(VatDisplay::COUNTRY_BASES_KEY, json_encode(['AE' => TaxRule::INCLUSIVE]));

    $reread = Order::find($order->id);

    expect((int) $reread->tax_total)->toBe($before['tax'])
        ->and((int) $reread->total)->toBe($before['total'])
        ->and((float) $reread->tax_rate)->toBe(5.0)
        ->and($reread->tax_basis)->toBe(TaxRule::EXCLUSIVE);

    // The documents are re-rendered from scratch AFTER the settings changed.
    expect(e2eBreakdown(app(OrderEmailPresenter::class)->present($reread)))->toBe($before['email'])
        ->and(e2eBreakdown(app(InvoiceDocument::class)->present($reread)))->toBe($before['invoice']);

    // Including the words, not only the figures — "VAT at 5%", not "at 15%".
    expect(array_keys($before['invoice']['rows']))->toContain('VAT at 5%');

    // And the admin screen, which is the third reader of the same record.
    e2eAdmin();
    $json = test()->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json();

    expect((int) round($json['vat']['amount_aed'] * 100))->toBe($before['tax'])
        ->and($json['vat']['label'])->toBe('VAT at 5% (added to the total)')
        ->and((int) round($json['total_aed'] * 100))->toBe($before['total']);
});

it('does not invent a tax record for an order placed before the engine was switched on', function () {
    // The shipped default: tax_mode absent, so nothing is charged or recorded.
    $order = e2ePlace(e2eCart(10000));

    expect((int) $order->tax_total)->toBe(0)
        ->and($order->tax_rate)->toBeNull()
        ->and($order->tax_basis)->toBeNull()
        ->and(OrderTax::recorded($order))->toBeNull();

    $before = e2eBreakdown(app(InvoiceDocument::class)->present($order));

    // The owner now switches the engine on at 15% exclusive.
    e2eLive(['AE' => '15'], ['AE' => TaxRule::EXCLUSIVE]);

    $reread = Order::find($order->id);

    // The old order is untouched: no backfilled rate, no new row, same total.
    expect(OrderTax::recorded($reread))->toBeNull()
        ->and((int) $reread->total)->toBe(12000)
        ->and(e2eBreakdown(app(InvoiceDocument::class)->present($reread)))->toBe($before);
});

it('still recomputes the VAT NOTE live on an order that recorded no tax — the one place history is not frozen', function () {
    /*
     * ── A RESIDUAL, PINNED HERE SO IT IS A DECISION RATHER THAN A SURPRISE ──
     *
     * Everything an order RECORDED is frozen: the test above proves a rate
     * change cannot move the rows, the total or the printed rate. But an order
     * with NO record — everything placed before the engine, and everything
     * placed while the shop is in the shipped `display` mode — has nothing to
     * read back, so InvoiceDocument::vatNote() and OrderEmailPresenter::vatNote()
     * fall through to asking VatDisplay LIVE:
     *
     *     $line = $this->vat->line((int) $order->total);
     *
     * That is deliberate — it is precisely the pre-lane behaviour those two
     * methods are documented as preserving, and D-64's display line had no
     * per-order rate to freeze. The consequence is worth stating plainly all
     * the same: change the GLOBAL `vat_rate` and every display-mode invoice's
     * "of which VAT" note reprints at the new rate, because that note was never
     * a record of anything that was charged.
     *
     * Nothing here says that is right or wrong. It says it is TRUE, so that if
     * the owner decides an old receipt must not move, the change is a visible
     * failure in this test rather than a silent discovery on a filed document.
     * Note the total itself does NOT move: no money is restated, only the note.
     */
    $order = e2ePlace(e2eCart(10000));

    expect(OrderTax::recorded($order))->toBeNull('this order deliberately has no tax record');

    $before = app(InvoiceDocument::class)->present($order)['vatNote'];

    // 5% inclusive is the shipped default rule, extracted from a 12000 total.
    expect($before['fils'])->toBe(571)
        ->and($before['label'])->toBe('Includes VAT at 5%');

    // The owner raises the global rate, years later.
    e2eSet('vat_rate', 20);

    $after = app(InvoiceDocument::class)->present(Order::find($order->id))['vatNote'];

    // The NOTE moves...
    expect($after['fils'])->toBe(2000)
        ->and($after['label'])->toBe('Includes VAT at 20%');

    // ...and the money does not. The total is the figure that was charged.
    expect((int) Order::find($order->id)->total)->toBe(12000);
});

/*
|------------------------------------------------------------------------------
| 5. THE COUNTRY CHANGES AND BOTH HALVES MOVE TOGETHER
|------------------------------------------------------------------------------
|
| The failure shape is a delivery line that updates while the tax does not, so
| these assert on the PAIR rather than on either half.
*/

it('moves the delivery charge and the tax together when the shopper changes country', function () {
    e2eLive(['AE' => '5', 'SA' => '15'], ['AE' => TaxRule::INCLUSIVE, 'SA' => TaxRule::EXCLUSIVE]);

    $cart = e2eCart(10000);

    $uae = e2eShopper($cart)->postJson('/api/checkout/rates', ['country' => 'AE'])->assertOk()->json();
    $saudi = e2eShopper($cart)->postJson('/api/checkout/rates', ['country' => 'SA'])->assertOk()->json();

    // BOTH halves differ. Either one alone moving is the bug.
    expect($uae['shipping'])->not->toBe($saudi['shipping'])
        ->and($uae['vat']['formatted'])->not->toBe($saudi['vat']['formatted'])
        ->and($uae['total'])->not->toBe($saudi['total']);

    // And the row moves side: inclusive sits under the Total as a note,
    // exclusive above it as part of the sum.
    expect($uae['vat']['added'])->toBeFalse()
        ->and($saudi['vat']['added'])->toBeTrue();

    // The figures behind the strings, exactly, from the one computation the
    // endpoint and the page share.
    $uaeTotals = e2eTotals($cart, 'AE');
    $saTotals = e2eTotals($cart, 'SA');

    expect($uaeTotals['shipping'])->toBe(2000)
        ->and($uaeTotals['tax_charged'])->toBe(571)      // 12000 inclusive at 5%
        ->and($uaeTotals['total'])->toBe(12000)
        ->and($saTotals['shipping'])->toBe(15000)
        ->and($saTotals['tax_charged'])->toBe(3750)      // 25000 exclusive at 15%
        ->and($saTotals['total'])->toBe(28750);
});

it('recomputes the tax off the new delivery charge, not the old one', function () {
    /*
     * Delivery is INSIDE the taxable base, so a country change that moves the
     * delivery charge has to move the tax even when the RATE is identical.
     * This is the case a "the rate did not change, so the tax cannot have"
     * shortcut gets wrong.
     */
    e2eLive(['AE' => '5', 'SA' => '5'], ['AE' => TaxRule::EXCLUSIVE, 'SA' => TaxRule::EXCLUSIVE]);

    $cart = e2eCart(10000);

    $uae = e2eTotals($cart, 'AE');
    $saudi = e2eTotals($cart, 'SA');

    // Same rate, same basis, same goods — and a different tax, because the
    // delivery charge inside the base is different.
    expect($uae['tax_charged'])->toBe(600)        // (10000 + 2000)  x 5%
        ->and($saudi['tax_charged'])->toBe(1250); // (10000 + 15000) x 5%
});

it('remembers the country the shopper picked so the rest of the shop agrees', function () {
    e2eLive(['SA' => '15'], ['SA' => TaxRule::EXCLUSIVE]);

    $cart = e2eCart(10000);

    e2eShopper($cart)
        ->postJson('/api/checkout/rates', ['country' => 'SA'])
        ->assertOk()
        ->assertSessionHas(\App\Support\ShopperCountry::SESSION_KEY, 'SA');
});

/*
|------------------------------------------------------------------------------
| 6. WHAT IS TAXABLE IS DECIDED IN EXACTLY ONE PLACE
|------------------------------------------------------------------------------
|
| THE POLICY IS NOT SET HERE AND IS NOT CHANGED HERE. The owner has not decided
| whether a delivery charge, a gift-wrap fee or a cash-on-delivery surcharge is
| a taxable supply — that is a question for his accountant. What these tests pin
| is that the code has ONE answer to it rather than one per path, so that when
| he does decide, the change is one line and not a hunt.
|
| Today: DELIVERY is inside the base; GIFT WRAP and the COD SURCHARGE are
| outside it. All three, on all three order-writing paths.
*/

it('taxes the delivery charge, and says so identically on every path', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $cart = e2eCart(10000);
    $totals = e2eTotals($cart, 'AE');

    // The base is subtotal - discount + delivery, so delivery is IN it.
    expect($totals['taxable_base'])->toBe(12000)
        ->and($totals['taxable_base'])->toBe($totals['subtotal'] - $totals['discount'] + $totals['shipping'])
        ->and($totals['tax_charged'])->toBe(600);

    // And the order's own reconstruction of the base agrees with the cart's.
    $order = e2ePlace(e2eCart(10000));

    expect(OrderTax::base($order))->toBe(12000)
        ->and((int) $order->tax_total)->toBe(600);
});

it('leaves the gift-wrap fee and the COD surcharge outside the tax base on the storefront', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    e2eSet('cod_fee', 1500);
    e2eSet('gift_enabled', '1');
    e2eSet('gift_fee', 1000);

    $plain = e2ePlace(e2eCart(10000));
    $withFees = e2ePlace(e2eCart(10000), ['is_gift' => '1']);

    // AED 25 of fees were added to the second order and the TAX DID NOT MOVE.
    expect((int) $withFees->fee_total)->toBe(2500)
        ->and((int) $withFees->gift_fee)->toBe(1000)
        ->and((int) $withFees->tax_total)->toBe((int) $plain->tax_total)
        ->and((int) $withFees->tax_total)->toBe(600);

    // The fees land on the total untaxed: base + tax + fees.
    expect((int) $withFees->total)->toBe(12000 + 600 + 2500);
});

it('leaves the COD surcharge outside the tax base on the API checkout too', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    e2eSet('cod_fee', 1500);

    $product = Product::create([
        'slug' => 'e2e-api-' . Str::random(6), 'name' => 'API Serum',
        'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha', 'email' => 'e2e-api@example.com', 'country' => 'AE'],
        'method' => 'cod',
    ])->assertCreated();

    $order = Order::latest('id')->first();

    // The same 600 the storefront charges for the same basket, with the COD
    // fee sitting outside it exactly as it does there.
    expect((int) $order->fee_total)->toBe(1500)
        ->and((int) $order->tax_total)->toBe(600)
        ->and((int) $order->total)->toBe(12000 + 600 + 1500);
});

it('leaves the COD surcharge outside the tax base on a back-office order too', function () {
    \Tests\ManualOrders::registerRoutes();
    PaymentProvider::query()->delete();
    ShippingZone::query()->delete();
    \Tests\ManualOrders::shop();
    $admin = \Tests\ManualOrders::admin();
    $customer = \Tests\ManualOrders::customer();
    $item = \Tests\ManualOrders::product('Sheet mask', 10000);

    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);
    e2eSet('cod_fee', 1500);

    test()->actingAs($admin, 'admin')
        ->postJson('/admin-api/manual-orders', \Tests\ManualOrders::payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => $item->id, 'quantity' => 1]],
        ]))->assertCreated();

    $order = Order::latest('id')->first();

    expect((int) $order->fee_total)->toBe(1500)
        ->and((int) $order->tax_total)->toBe(600)
        ->and((int) $order->total)->toBe(12000 + 600 + 1500);
});

/*
|------------------------------------------------------------------------------
| 7. THE THREE ORDER WRITERS DO THE SAME ARITHMETIC
|------------------------------------------------------------------------------
|
| Two implementations of one sum is the defect. The storefront and the manual
| builder both go through CartService::totals(); the API checkout cannot (it
| builds no Cart and takes no coupon) and so asks VatDisplay the same question
| on the same base. These tests are what keeps that "same" honest.
*/

it('charges one basket the same money through all three doors', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    // --- the storefront ---------------------------------------------------
    $storefront = e2ePlace(e2eCart(10000));

    // --- the public API ---------------------------------------------------
    $product = Product::create([
        'slug' => 'e2e-three-' . Str::random(6), 'name' => 'Three Doors Serum',
        'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha', 'email' => 'e2e-three@example.com', 'country' => 'AE'],
        'method' => 'cod',
    ])->assertCreated();

    $api = Order::latest('id')->first();

    // Same goods, same destination, same delivery — so the same tax, basis,
    // rate and total, to the fil.
    expect([$api->subtotal, $api->shipping_total, $api->tax_total, $api->total])
        ->toBe([$storefront->subtotal, $storefront->shipping_total, $storefront->tax_total, $storefront->total]);

    expect($api->tax_basis)->toBe($storefront->tax_basis)
        ->and((float) $api->tax_rate)->toBe((float) $storefront->tax_rate);
});

it('taxes an API order for the country it records, even when the caller sends none', function () {
    /*
     * ── LANE DQ FOUND THIS, AND IT IS WHY THIS TEST IS HERE ────────────────
     *
     * `customer.country` is `nullable` on /api/checkout/session, which is a
     * PUBLIC, UNAUTHENTICATED endpoint (CLAUDE.md). Omitting it is therefore a
     * reachable state and not a theoretical one.
     *
     * Every other figure on the order resolved that absence to 'AE': the
     * delivery was priced against AE and the address was recorded as AE. The
     * tax quote alone was handed the raw `?? null`, and VatDisplay::ruleFor(null)
     * answers the GLOBAL DEFAULT rule rather than AE's own row.
     *
     * So the same basket came to AED 220.00 through this door and AED 231.00
     * through the storefront, for an order that said "AE" on its face — and
     * leaving a nullable field out was the cheaper of the two. Same shape as
     * the `?? null` defect already recorded thirty lines above it in that file.
     */
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $product = Product::create([
        'slug' => 'e2e-nocountry-' . Str::random(6), 'name' => 'No Country Serum',
        'status' => 'publish', 'is_visible' => true, 'price' => 20000, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        // No country at all.
        'customer' => ['name' => 'Aisha', 'email' => 'e2e-nocountry@example.com'],
        'method' => 'cod',
    ])->assertCreated();

    $order = Order::latest('id')->first();

    // The order records AE, so it must be taxed as AE: 5% EXCLUSIVE.
    expect($order->billing_address['country'])->toBe('AE')
        ->and($order->tax_basis)->toBe(TaxRule::EXCLUSIVE)
        ->and((int) $order->tax_total)->toBe(1100)
        ->and((int) $order->total)->toBe(23100);
});

it('records the country in one spelling however the caller spelled it', function () {
    e2eLive(['AE' => '5'], ['AE' => TaxRule::EXCLUSIVE]);

    $product = Product::create([
        'slug' => 'e2e-case-' . Str::random(6), 'name' => 'Lower Case Serum',
        'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/checkout/session', [
        'items' => [['slug' => $product->slug, 'qty' => 1]],
        'customer' => ['name' => 'Aisha', 'email' => 'e2e-case@example.com', 'country' => 'ae'],
        'method' => 'cod',
    ])->assertCreated();

    $order = Order::latest('id')->first();

    // One normalised answer, used for the delivery, the tax and the address —
    // so the country on the invoice cannot be a different spelling from the
    // country the order was charged for.
    expect($order->billing_address['country'])->toBe('AE')
        ->and($order->shipping_address['country'])->toBe('AE')
        ->and((int) $order->tax_total)->toBe(600);
});

/*
|------------------------------------------------------------------------------
| 8. NOTHING IS SAID WHERE THERE IS NOTHING TRUE TO SAY
|------------------------------------------------------------------------------
|
| A zero rate, a country with no rule and a shop with the line switched off all
| have to print NOTHING — not "VAT AED 0.00", which asserts a tax was charged.
*/

it('prints no tax line at all for a country with a zero rate', function () {
    e2eLive(['AE' => '0'], ['AE' => TaxRule::EXCLUSIVE]);

    $totals = e2eTotals(e2eCart(10000), 'AE');

    expect($totals['vat'])->toBeNull('a zero rate has no line to print')
        ->and($totals['tax_charged'])->toBe(0)
        ->and($totals['total'])->toBe(12000);

    $order = e2ePlace(e2eCart(10000));

    // Nothing on the documents either — no row, and no "of which" note.
    $email = app(OrderEmailPresenter::class)->present($order);
    $invoice = app(InvoiceDocument::class)->present($order);

    expect($email['vatNote'])->toBeNull()
        ->and($invoice['vatNote'])->toBeNull()
        ->and(array_keys(e2eBreakdown($email)['rows']))->toBe(['Subtotal', 'Delivery']);

    // And the admin screen says nothing rather than "VAT at 0%".
    e2eAdmin();
    expect(test()->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json('vat'))
        ->toBeNull();
});

it('falls back to the default rule for a country with no row of its own', function () {
    // A table with one Saudi row in it and nothing for Kuwait.
    e2eLive(['SA' => '15'], ['SA' => TaxRule::EXCLUSIVE]);

    $kuwait = app(VatDisplay::class)->ruleFor('KW');
    $default = app(VatDisplay::class)->defaultRule();

    expect($kuwait->rate)->toBe($default->rate)
        ->and($kuwait->basis)->toBe($default->basis);

    // A country nobody has ever heard of does the same thing rather than
    // dividing by zero or throwing.
    expect(app(VatDisplay::class)->ruleFor('ZZ')->rate)->toBe($default->rate);
});

it('divides nothing by zero when the rate is zero or the basket is empty', function () {
    $zero = new TaxRule(0.0, TaxRule::INCLUSIVE);

    expect($zero->taxOn(12000))->toBe(0)
        ->and($zero->grossOf(12000))->toBe(12000);

    // An empty or negative base is answered rather than computed on.
    $five = new TaxRule(5.0, TaxRule::INCLUSIVE);

    expect($five->taxOn(0))->toBe(0)
        ->and($five->taxOn(-100))->toBe(0);
});

it('keeps the checkout summary silent, not zeroed, when there is no tax', function () {
    // No tax rules at all and the line switched off.
    e2eSet('vat_enabled', '0');

    $cart = e2eCart(10000);
    $html = e2eShopper($cart)->get('/checkout')->assertOk()->getContent();

    /*
     * ASSERTED ON ELEMENTS, NOT ON A SUBSTRING. The page ships its own CSS, and
     * `.vat` and `.js-vat-row` both appear in a stylesheet on this very page —
     * a str_contains() check would match the rule rather than the row and pass
     * whatever the markup did.
     */
    preg_match_all('/<div\b[^>]*\bclass="[^"]*\bjs-vat-row\b[^"]*"[^>]*>/i', $html, $rows);

    expect($rows[0])->not->toBeEmpty('the checkout should still carry both VAT rows in the DOM');

    // Every one of them is hidden, and none carries a money figure.
    foreach ($rows[0] as $row) {
        expect($row)->toContain('hidden');
    }

    // No stray "AED 0" beside a word that claims tax was charged.
    expect($html)->not->toContain("You're paying VAT");
});

/*
|------------------------------------------------------------------------------
| 9. THE TAX TAB IS REACHABLE FROM BUSINESS DETAILS
|------------------------------------------------------------------------------
|
| Reported once as not visible. The admin shell is a JavaScript console, so the
| tab is emitted by script rather than served as markup — which means a check
| has to look inside the SCRIPT, and must not be satisfied by the page's own
| stylesheet, where `.bd-tab` is also defined.
*/

it('reaches the Tax tab from Business Details, and from the sidebar', function () {
    $html = view('admin.app')->render();

    /*
     * EVERY ASSERTION BELOW MATCHES AN ELEMENT WITH AN ATTRIBUTE ON IT.
     *
     * `bd-tab`, `bd-jump` and `on` are all class names this page defines in its
     * own inlined stylesheet, so a str_contains() for any of them matches the
     * CSS rule and passes whether or not a tab is ever drawn — the trap
     * CLAUDE.md's sibling lanes have already hit. A `<button ... data-bdtab>`
     * shape cannot be produced by a stylesheet.
     */

    // 1. The Business Details tab strip emits a button that opens the Tax tab.
    expect(preg_match_all('/<button\b[^>]*\bdata-bdtab="tax"/i', $html))
        ->toBeGreaterThan(0, 'Business Details emits no button that opens the Tax tab');

    // 2. That tab has a panel to show once it is pressed. A tab strip with no
    //    panel behind it is exactly what "I cannot see the Tax tab" looks like.
    expect(preg_match_all('/\bdata-bdpanel="tax"/i', $html))
        ->toBeGreaterThan(0, 'the Tax tab has no panel to open');

    // 3. The sidebar carries a Tax row of its own, so the word the owner is
    //    scanning the list for is in the list.
    expect(preg_match("/\[\s*'tax'\s*,\s*'Tax'\s*,/", $html))
        ->toBe(1, 'the sidebar has no Tax row');

    // 4. And the old home of the VAT rate points at the new one rather than
    //    dead-ending where he last saw it.
    expect(preg_match_all('/<button\b[^>]*\bclass="bd-jump"[^>]*\bdata-bdtab="tax"/i', $html))
        ->toBeGreaterThan(0, 'nothing points from the old VAT rate to the Tax tab');
});
