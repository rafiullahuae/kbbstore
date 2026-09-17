<?php

declare(strict_types=1);

/**
 * The delivery note, the dispatch label, and the barcode both of them draw.
 *
 * Lane EK. The invoice and the packing slip were already real documents
 * (InvoiceDocumentTest pins those); the order screen offered three more as
 * buttons that toasted "not built yet". These are two of the three.
 *
 * FIVE THINGS ARE PINNED HERE, because each is a way to be wrong quietly:
 *
 *   THE GUARD. Both new paths print a buyer's name, street address and phone
 *   number laid out for a browser to show. /api/* in this app is
 *   unauthenticated by design, so being on the wrong side of that line
 *   publishes the customer database one parcel at a time. Asserted against an
 *   anonymous caller, a signed-in storefront customer and a `web`-guard user,
 *   with the middleware read back off the REGISTERED routes.
 *
 *   WHAT EACH DOCUMENT LEAVES OUT. Over the WHOLE RENDERED PAGE, never over a
 *   list of fields somebody has to remember to keep up to date. The delivery
 *   note carries no money and no currency symbol, because it travels inside a
 *   parcel that may be a gift. The label carries neither of those AND no
 *   product name, brand or SKU, because it is on the outside of the box where
 *   the driver, the hub and the neighbour all read it.
 *
 *   THE ONE FIGURE THAT IS ALLOWED. An unpaid cash-on-delivery order puts the
 *   amount to collect on the label, to the fil, because the driver is
 *   collecting it at the door. A card order does not. A COD order that has
 *   already been settled does not either.
 *
 *   THAT NEITHER MINTS AN INVOICE NUMBER. Picking a parcel and addressing a box
 *   are not issuing a financial document, and an order that is packed and then
 *   cancelled must not leave a gap in a sequence an accountant reconciles.
 *
 *   ARABIC. This shop trades in the UAE. An Arabic name and an Arabic street
 *   address must come out of every one of these documents byte-intact and
 *   inside an element that states its own direction, because the failure mode
 *   is not an error — it is a correct-looking label with the house number at
 *   the wrong end of the line.
 *
 * ON THE MONEY NEEDLES. Money::format() returns nested spans with the symbol in
 * its own bidi isolate, so "AED 473.50" never appears as adjacent characters in
 * the markup and a needle written that way would fail against correct output.
 * Every positive assertion here uses the formatter's own string. Every NEGATIVE
 * assertion looks for the bare digits and for the symbol separately, which is
 * strictly stronger: it catches a price that leaked through some other renderer
 * as well as one that came through this one.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\InvoiceDocument;
use App\Services\SettingsService;
use App\Support\Code128;
use App\Support\Money;
use App\Support\TaxRule;
use Tests\Support\InvoiceAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function dispAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Dispatch Owner',
        'email' => 'dispatch-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * One order with every awkward part filled in.
 *
 * Deliberately the same arithmetic as InvoiceDocumentTest's fixture, because
 * the two suites have to agree about what this order cost: 2 x 19900 plus
 * 1 x 6500 is 46300; less a 4000 coupon, plus 2000 delivery, 1500 gift wrapping
 * and a 1550 cash-on-delivery surcharge, is 47350. AED 473.50 — not a round
 * number of dirhams, on purpose, because Money::displayDecimals() is 0 on this
 * store and a document that rounds it to AED 474 is a document that disagrees
 * with the bank statement it is filed against.
 */
function dispOrder(array $attributes = [], array $billing = [], array $shipping = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::create(array_merge([
        'order_number' => 'KBB-DSP-' . $n,
        'email' => 'buyer-' . $n . '@example.test',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => array_merge([
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
        ], $billing),
        'shipping_address' => array_merge([
            'first_name' => 'Noura', 'last_name' => 'Al Mansoori',
            'line1' => 'Villa 7, Street 21, Al Barsha', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 55 987 6543',
        ], $shipping),
        'subtotal' => 46300,
        'discount_total' => 4000,
        'coupon_code' => 'GLOW10',
        'shipping_total' => 2000,
        'fee_total' => 3050,
        'gift_fee' => 1500,
        'is_gift' => true,
        'gift_note' => 'Happy birthday, Mama.',
        'customer_note' => 'Please ring the doorbell twice.',
        'tax_total' => 0,
        'total' => 47350,
        'shipping_method' => 'Standard delivery (1-3 working days)',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ], $attributes));

    $order->items()->create([
        'name' => 'Rice Daily Moisturizing Toner 150ml',
        'brand' => 'Haruharu Wonder',
        'sku' => 'HH-RT-150',
        'quantity' => 2,
        'unit_price' => 19900,
        'subtotal' => 39800,
        'total' => 39800,
    ]);

    $order->items()->create([
        'name' => 'Centella Ampoule',
        'brand' => 'SKIN1004',
        'sku' => 'SK-CA-030',
        'variant_attributes' => ['30ml'],
        'quantity' => 1,
        'unit_price' => 6500,
        'subtotal' => 6500,
        'total' => 6500,
    ]);

    return $order->fresh('items');
}

function dispNoteUrl(Order $order): string
{
    return '/admin-api/orders/' . $order->id . '/delivery-note';
}

function dispLabelUrl(Order $order): string
{
    return '/admin-api/orders/' . $order->id . '/shipping-label';
}

/** The page as an admin sees it. */
function dispGet(string $uri): string
{
    return test()->actingAs(dispAdmin(), 'admin')->get($uri)->assertOk()->getContent();
}

/**
 * Every money needle a leak could take, for a NEGATIVE assertion.
 *
 * The bare digit strings of every figure on the fixture order, plus the
 * currency symbol on its own. Not the formatter's output: a price that reached
 * the page through some other renderer would not carry this build's markup, and
 * a test that only looked for this build's markup would miss it.
 *
 * @return list<string>
 */
function dispMoneyNeedles(): array
{
    return [
        '473.50', '474', '463.00', '46300',  // total and subtotal
        '199.00', '65.00', '398.00',         // unit and line amounts
        '40.00', '20.00', '15.00', '15.50',  // discount, delivery, gift, COD fee
        Money::symbol(),
    ];
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on the delivery note and the dispatch label', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder(['email' => 'private-parcel@example.test']);

    foreach ([dispNoteUrl($order), dispLabelUrl($order)] as $uri) {
        $response = test()->get($uri);

        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->toBeIn([301, 302, 401, 403]);

        $body = (string) $response->getContent();

        expect($body)->not->toContain('Noura')
            ->and($body)->not->toContain('Al Barsha')
            ->and($body)->not->toContain('+971 55 987 6543')
            ->and($body)->not->toContain('private-parcel@example.test');
    }
});

it('refuses a signed-in storefront customer and a web-guard user', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();

    $customer = Customer::create([
        'email' => 'parcel-customer@example.test',
        'password' => 'secret-secret',
        'first_name' => 'Parcel',
        'last_name' => 'Customer',
    ]);

    test()->actingAs($customer, 'customer');

    foreach ([dispNoteUrl($order), dispLabelUrl($order)] as $uri) {
        expect(test()->get($uri)->getStatusCode())->not->toBe(200);
    }

    $user = User::create([
        'name' => 'Parcel Web User',
        'email' => 'parcel-webuser@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    foreach ([dispNoteUrl($order), dispLabelUrl($order)] as $uri) {
        expect(test()->get($uri)->getStatusCode())->not->toBe(200);
    }
});

it('refuses an order id that was never issued, the same way for every caller', function () {
    InvoiceAdminRoutes::wire(app());

    /*
     * Guessing a number buys nothing. An anonymous caller is refused by the
     * guard BEFORE a row is ever loaded, so the refusal is identical whether
     * the order exists or not and the endpoint is not an oracle for which ids
     * are real. An admin, who is allowed to know, gets a 404 page rather than
     * the JSON body the surrounding group would otherwise return — these routes
     * serve documents to a browser window, and `{"error":"not_found"}` as raw
     * text is a worse answer than a sentence.
     */
    $missing = '/admin-api/orders/99999999/delivery-note';

    expect(test()->get($missing)->getStatusCode())->not->toBe(200);

    test()->actingAs(dispAdmin(), 'admin')
        ->get($missing)
        ->assertStatus(404);

    test()->actingAs(dispAdmin(), 'admin')
        ->get('/admin-api/orders/99999999/shipping-label')
        ->assertStatus(404);
});

it('constrains the order id on the two new routes as well', function () {
    InvoiceAdminRoutes::wire(app());

    $added = collect(InvoiceAdminRoutes::registered())
        ->filter(fn ($r) => str_contains($r->uri(), 'delivery-note') || str_contains($r->uri(), 'shipping-label'));

    expect($added)->toHaveCount(2);

    foreach ($added as $route) {
        expect($route->wheres['id'] ?? null)->toBe('[0-9]+')
            ->and($route->gatherMiddleware())->toContain('auth:admin')
            ->and($route->gatherMiddleware())->toContain('web')
            ->and($route->methods())->toContain('GET');
    }
});

/* -------------------------------------------------------- the delivery note */

it('prints the delivery note with the goods, the address and a signature block', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();
    $html = dispGet(dispNoteUrl($order));

    expect($html)->toContain('Delivery Note')
        ->and($html)->toContain('KBB-DSP-')
        // What was handed over, in the customer's words.
        ->and($html)->toContain('Rice Daily Moisturizing Toner 150ml')
        ->and($html)->toContain('Haruharu Wonder')
        ->and($html)->toContain('Centella Ampoule')
        // Where it went, written out rather than "as above".
        ->and($html)->toContain('Noura')
        ->and($html)->toContain('Villa 7, Street 21, Al Barsha')
        ->and($html)->not->toContain('Same as the billing address')
        // And a place to sign for it, which is the whole difference between
        // this document and the packing slip.
        ->and($html)->toContain('Received by')
        ->and($html)->toContain('Signature');
});

it('puts no price of any kind on the delivery note', function () {
    InvoiceAdminRoutes::wire(app());

    $html = dispGet(dispNoteUrl(dispOrder()));

    foreach (dispMoneyNeedles() as $needle) {
        expect($html)->not->toContain($needle);
    }

    // And none of the words that introduce one.
    foreach (['Subtotal', 'Unit price', 'Grand total', 'Amount due'] as $word) {
        expect($html)->not->toContain($word);
    }
});

it('keeps the warehouse and the sender out of the customer copy', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();
    $html = dispGet(dispNoteUrl($order));

    // SKUs and tick boxes are the packing slip's vocabulary.
    expect($html)->not->toContain('HH-RT-150')
        ->and($html)->not->toContain('SK-CA-030')
        // The gift message is already on the card; printing it again on a sheet
        // in the same box hands the recipient the sender's private words twice.
        ->and($html)->not->toContain('Happy birthday, Mama.')
        // The buyer's email is a login identifier and this sheet is opened by
        // whoever opens the parcel.
        ->and($html)->not->toContain($order->email);
});

/* ------------------------------------------------------- the dispatch label */

it('prints the dispatch label with the address, the reference and a scannable code', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();
    $html = dispGet(dispLabelUrl($order));

    expect($html)->toContain('Deliver to')
        ->and($html)->toContain('Noura')
        ->and($html)->toContain('Villa 7, Street 21, Al Barsha')
        ->and($html)->toContain('United Arab Emirates')
        ->and($html)->toContain('+971 55 987 6543')
        ->and($html)->toContain($order->order_number)
        // The barcode is drawn, not fetched: bars are elements with a left
        // border, because "background graphics" is off by default in the print
        // dialog and a bar drawn with a background colour prints as nothing.
        ->and($html)->toContain('class="bc"')
        ->and($html)->toContain('border-left-width:')
        // The rule itself, not the absence of a background: the CSS comment
        // next to it explains the trap and therefore contains the very string
        // a negative assertion would look for. Pin what the bars ARE.
        ->and($html)->toContain('.bc i.b { width: 0; border-left-style: solid; border-left-color: #000; }')
        // A6, so the label is a label and not a postage stamp on an A4 page.
        ->and($html)->toContain('size: 105mm 148mm');

    /*
     * AND THE SHEET ACTUALLY WEARS THE CLASS THE A6 RULES ARE WRITTEN AGAINST.
     *
     * This pair caught a real bug and is here to keep catching it. The layout
     * built the attribute as `class="sheet@yield('sheet-class')"`, and Blade
     * does not match a directive whose @ is preceded by a word character — so
     * the directive was emitted as literal text, the label never received
     * `lbl`, every `.sheet.lbl` rule missed, and an A6 label laid itself out at
     * 210mm. Nothing errored. The @page rule was correct the whole time, which
     * is why asserting the A6 page size above is not enough on its own.
     */
    expect($html)->toContain('class="sheet lbl"')
        ->and($html)->not->toContain('@yield');

    // And the other three still get exactly `sheet`, which is the default the
    // layout yields when a document defines no class of its own.
    $invoice = dispGet('/admin-api/orders/' . $order->id . '/invoice');

    expect($invoice)->toContain('class="sheet"')
        ->and($invoice)->not->toContain('@yield');
});

it('puts the delivery phone on the label once, not twice', function () {
    InvoiceAdminRoutes::wire(app());

    // A gift: the ORDER carries the buyer's number and the SHIPPING ADDRESS
    // carries the recipient's. It is the recipient who is behind the door, so
    // that is the number the label prints — and it prints it once, because a
    // parcel with two numbers on it reads as two different numbers to anybody
    // in a hurry.
    $order = dispOrder(['phone' => '+971 50 111 1111'], [], ['phone' => '+971 55 222 2222']);

    $html = dispGet(dispLabelUrl($order));

    expect(substr_count($html, '+971 55 222 2222'))->toBe(1)
        ->and($html)->toContain('Tel +971 55 222 2222')
        // The buyer's own number is not on the outside of the parcel at all.
        ->and($html)->not->toContain('+971 50 111 1111');
});

it('says nothing on the label about what is in the box', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();
    $html = dispGet(dispLabelUrl($order));

    expect($html)->not->toContain('Rice Daily Moisturizing Toner 150ml')
        ->and($html)->not->toContain('Centella Ampoule')
        ->and($html)->not->toContain('Haruharu Wonder')
        ->and($html)->not->toContain('SKIN1004')
        ->and($html)->not->toContain('HH-RT-150')
        ->and($html)->not->toContain('SK-CA-030')
        // Private words, on the outside of a parcel.
        ->and($html)->not->toContain('Happy birthday, Mama.')
        ->and($html)->not->toContain('Please ring the doorbell twice.')
        // The driver rings the phone.
        ->and($html)->not->toContain($order->email);
});

it('prints the amount to collect on an unpaid cash-on-delivery label, to the fil', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder(['payment_method' => 'cod', 'paid_at' => null]);
    $html = dispGet(dispLabelUrl($order));

    expect($html)->toContain('Cash on delivery')
        ->and($html)->toContain(InvoiceDocument::money(47350))
        ->and($html)->toContain('473.50')
        // AED 474 is what the storefront would print for this figure, and a
        // driver who collects 474 against a receipt for 473.50 has taken 50
        // fils nobody agreed to.
        ->and($html)->not->toContain('>474<')
        ->and(Money::format(47350))->toContain('474');
});

it('prints no figure at all on a label for an order that was paid', function () {
    InvoiceAdminRoutes::wire(app());

    // Same COD order, settled. Nothing is owed at the door, so the driver must
    // not be sent to collect a second time.
    $paid = dispOrder(['payment_method' => 'cod', 'paid_at' => now()]);

    // And a card order, which was never a doorstep collection at all.
    $card = dispOrder([
        'payment_method' => 'stripe',
        'payment_method_title' => 'Card',
        'paid_at' => null,
    ]);

    foreach ([$paid, $card] as $order) {
        $html = dispGet(dispLabelUrl($order));

        expect($html)->not->toContain('collect');

        foreach (dispMoneyNeedles() as $needle) {
            expect($html)->not->toContain($needle);
        }
    }
});

/* ------------------------------------------------- neither mints a sequence */

it('allocates no invoice number for a delivery note or a label, however often either is opened', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();

    expect($order->invoice_number)->toBeNull();

    foreach (range(1, 3) as $_) {
        dispGet(dispNoteUrl($order));
        dispGet(dispLabelUrl($order));
    }

    expect($order->fresh()->invoice_number)->toBeNull()
        ->and($order->fresh()->invoiced_at)->toBeNull();

    // The invoice still mints one, so the absence above is this lane's choice
    // and not a broken allocator.
    dispGet('/admin-api/orders/' . $order->id . '/invoice');

    expect($order->fresh()->invoice_number)->not->toBeNull();
});

/* ---------------------------------------------- the invoice is still frozen */

it('sums the printed invoice lines to the amount charged, to the fil', function () {
    InvoiceAdminRoutes::wire(app());

    $order = dispOrder();
    $doc = app(InvoiceDocument::class)->present($order);

    $rows = collect($doc['totals']);
    $grand = $rows->firstWhere('strong', true);

    // Every row above the Total, added up, IS the Total. Not approximately: the
    // document exists to be filed against a bank statement.
    $sum = $rows->reject(fn ($r) => $r['strong'])->sum('fils');

    expect($sum)->toBe(47350)
        ->and($grand['fils'])->toBe(47350)
        ->and($grand['fils'])->toBe((int) $order->total);
});

it('cannot be made to restate a past order tax by changing the rate afterwards', function () {
    InvoiceAdminRoutes::wire(app());

    $settings = app(SettingsService::class);
    $settings->set('invoice_trn', '100123456700003');

    // An order that recorded its own tax on the day: 5% inclusive of 47350.
    $order = dispOrder([
        'tax_rate' => 5.0,
        'tax_basis' => TaxRule::INCLUSIVE,
        'tax_total' => 2255,
    ]);

    $before = dispGet('/admin-api/orders/' . $order->id . '/invoice');

    expect($before)->toContain('Includes VAT at 5%')
        ->and($before)->toContain(InvoiceDocument::money(2255));

    // The owner raises the shop's rate. No money moves; `total` is a column.
    $settings->set('vat_rate', 20);
    $settings->set('tax_mode', 'live');
    $settings->set('vat_basis', TaxRule::INCLUSIVE);

    $after = dispGet('/admin-api/orders/' . $order->id . '/invoice');

    // The filed document is unchanged, because it was never reading settings.
    expect($after)->toContain('Includes VAT at 5%')
        ->and($after)->toContain(InvoiceDocument::money(2255))
        ->and($after)->not->toContain('Includes VAT at 20%')
        ->and($after)->toContain(InvoiceDocument::money(47350));

    expect((int) $order->fresh()->total)->toBe(47350);
});

/* ------------------------------------------------------------------- Arabic */

it('carries an Arabic name and address through every document intact', function () {
    InvoiceAdminRoutes::wire(app());

    $name = 'نورة';
    $street = 'فيلا ٧، شارع ٢١، البرشاء';

    $order = dispOrder([], [], [
        'first_name' => $name,
        'last_name' => 'المنصوري',
        'line1' => $street,
    ]);

    foreach ([dispNoteUrl($order), dispLabelUrl($order), '/admin-api/orders/' . $order->id . '/packing-slip'] as $uri) {
        $html = dispGet($uri);

        // Byte-intact: no mangling, no HTML entities, no question marks. Blade's
        // {{ }} escapes HTML and leaves UTF-8 alone, and the response declares
        // utf-8 so a browser does not have to guess.
        expect($html)->toContain($name)
            ->and($html)->toContain($street)
            ->and($html)->toContain('charset="utf-8"');

        /*
         * AND THE MARKUP STATES ITS DIRECTION.
         *
         * This is the half that is not obvious. The bytes survive either way —
         * the failure mode of an Arabic address in an LTR document is not an
         * error or a missing glyph, it is a line that renders with the house
         * number at the wrong end. dir="auto" resolves each line's direction
         * from its own first strong character, so an Arabic line lays itself
         * out RTL and an English one beside it does not. Asserted on the
         * address block itself rather than over the whole page, where the
         * document's other dir attributes would satisfy the check whatever the
         * address did.
         */
        $block = substr($html, max(0, strpos($html, $street) - 400), 460);

        expect($block)->toContain('dir="auto"');
    }
});

it('states the direction of the seller identity too, which is also settings-driven', function () {
    InvoiceAdminRoutes::wire(app());

    $settings = app(SettingsService::class);
    $settings->set('invoice_business_name', 'كي بيوتي بليس ش.ذ.م.م');
    $settings->set('invoice_address', 'مكتب ١٩٠٢، برج بيرلنغتون');

    $html = dispGet(dispLabelUrl(dispOrder()));

    expect($html)->toContain('كي بيوتي بليس ش.ذ.م.م')
        ->and($html)->toContain('مكتب ١٩٠٢، برج بيرلنغتون');

    $block = substr($html, max(0, strpos($html, 'مكتب') - 400), 460);

    expect($block)->toContain('dir="auto"');
});

it('names an Arabic-capable font stack, since no webfont can reach this host', function () {
    InvoiceAdminRoutes::wire(app());

    $html = dispGet(dispLabelUrl(dispOrder()));

    // The stack has to name faces the PRINTING MACHINE already owns: the web
    // root is a different directory from the application root here (CLAUDE.md),
    // so an @font-face pointing into public/build/ 404s on the live site and
    // prints tofu. Asserting the absence of @font-face is the load-bearing half.
    expect($html)->toContain('Noto Sans Arabic')
        ->and($html)->toContain('Geeza Pro')
        ->and($html)->not->toContain('@font-face')
        ->and($html)->not->toContain('fonts.googleapis.com')
        ->and($html)->not->toContain('<link rel="stylesheet"');
});

it('falls back to plain text rather than drawing bars for a code it cannot encode', function () {
    InvoiceAdminRoutes::wire(app());

    // Code 128 subset B is ASCII 32-126. An Arabic order number is outside it,
    // and a document must not 500 because a value was unusual — nor print bars
    // that stand for a different string than the text beneath them.
    $order = dispOrder(['order_number' => 'طلب-١٢٣']);

    $html = dispGet(dispLabelUrl($order));

    expect($html)->toContain('طلب-١٢٣')
        ->and($html)->not->toContain('class="bc"');
});

/* ------------------------------------------------------------- the encoder */

it('encodes a known Code 128 value bar for bar', function () {
    /*
     * Worked by hand from the specification, because the pattern table cannot
     * be derived and a transcription error in it would produce a barcode that
     * scans as something other than what is printed under it.
     *
     *   "A" is ASCII 65, so its subset-B value is 65 - 32 = 33.
     *   The check character is (104 + 1 x 33) mod 103 = 137 mod 103 = 34.
     *
     * So the elements are start-B (211214), value 33 (111323), check 34
     * (131123) and stop (2331112).
     */
    expect(Code128::encode('A'))->toBe([
        2, 1, 1, 2, 1, 4,
        1, 1, 1, 3, 2, 3,
        1, 3, 1, 1, 2, 3,
        2, 3, 3, 1, 1, 1, 2,
    ]);
});

it('starts with a bar, alternates, and closes with the stop pattern', function () {
    $widths = Code128::encode('KBB-10427');

    expect($widths)->not->toBeNull();

    // 11 modules per character, plus the 13-module stop: start + 9 data + check
    // is 11 symbols, so 11 x 11 + 13 = 134.
    expect(array_sum($widths))->toBe(134)
        ->and(array_slice($widths, -7))->toBe([2, 3, 3, 1, 1, 1, 2]);

    // Every element is a legal module width.
    foreach ($widths as $w) {
        expect($w)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);
    }
});

it('refuses what it cannot represent instead of encoding half of it', function () {
    expect(Code128::encode(''))->toBeNull()
        // One byte of a UTF-8 sequence is not a subset-B character, and
        // encoding half of one produces a code that scans as mojibake.
        ->and(Code128::encode('طلب'))->toBeNull()
        // Past the label's width a code prints truncated, and a truncated
        // barcode still scans — as the wrong string.
        ->and(Code128::encode(str_repeat('X', Code128::MAX_LENGTH + 1)))->toBeNull()
        ->and(Code128::encode(str_repeat('X', Code128::MAX_LENGTH)))->not->toBeNull();
});

it('reserves the quiet zone the specification requires', function () {
    $widths = Code128::encode('KBB-10427');

    // Ten modules of white each side. A scanner that cannot see one reads
    // nothing at all, which is the commonest reason a printed code "does not
    // work".
    expect(Code128::modules($widths))->toBe(134 + 20);
});
