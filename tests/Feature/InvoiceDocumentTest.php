<?php

declare(strict_types=1);

/**
 * The printable invoice and the packing slip.
 *
 * Four things are pinned hardest here, because each is a way to be wrong
 * quietly:
 *
 *   THE GUARD. An invoice prints a buyer's full name, street address, phone
 *   number, what they bought and what they paid, laid out for a browser to
 *   show. /api/* in this app is unauthenticated by design, so being on the
 *   wrong side of that line publishes the customer database one order at a time.
 *   Both routes are asserted against an anonymous caller, a signed-in storefront
 *   customer and a `web`-guard user, and the middleware is read back off the
 *   REGISTERED routes rather than trusted from the harness that mounted them.
 *
 *   THE MONEY. Seeded to exact fils and asserted to exact fils, on an order
 *   with a coupon, gift wrapping AND a payment surcharge. The total is 47350
 *   fils on purpose: Money::displayDecimals() is 0 on this store, so the
 *   storefront would print "AED 474" for it. An invoice that overstates a
 *   charge by 50 fils is a document that disagrees with the bank statement it
 *   is filed against, so the page is asserted to carry 473.50 and asserted not
 *   to carry 474 anywhere at all.
 *
 *   THE SNAPSHOT. A line whose product has been renamed, repriced and
 *   soft-deleted since must still print what was bought at what it cost.
 *
 *   WHAT THE PACKING SLIP LEAVES OUT. It goes in the parcel, and a gift order
 *   arrives at the recipient's door. No price, no total, no currency symbol —
 *   asserted over the whole rendered page, not over a list of fields somebody
 *   has to remember to keep up to date.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\SettingsService;
use Tests\Support\InvoiceAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function invAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Invoice Owner',
        'email' => 'invoice-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/**
 * One order with every awkward part filled in.
 *
 * Money, in fils, checked in the test rather than trusted here: 2 x 19900 plus
 * 1 x 6500 of goods is 46300; less a 4000 coupon, plus 2000 delivery, 1500 gift
 * wrapping and a 1550 cash-on-delivery surcharge, is 47350 — deliberately NOT
 * equal to the subtotal, and deliberately not a round number of dirhams.
 */
function invDocOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::create(array_merge([
        'order_number' => 'KBB-DOC-' . $n,
        'email' => 'aisha.khan-' . $n . '@example.com',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
        ],
        'shipping_address' => [
            'first_name' => 'Noura', 'last_name' => 'Al Mansoori',
            'line1' => 'Villa 7, Street 21, Al Barsha', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 55 987 6543',
        ],
        'subtotal' => 46300,
        'discount_total' => 4000,
        'coupon_code' => 'GLOW10',
        'shipping_total' => 2000,
        'fee_total' => 3050,
        'gift_fee' => 1500,
        'is_gift' => true,
        'gift_note' => "Happy birthday, Mama.\nLove from all of us x",
        'customer_note' => 'Please ring the doorbell twice — the buzzer is broken.',
        'tax_total' => 0,
        'total' => 47350,
        'shipping_method' => 'Standard delivery (1–3 working days)',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'created_at' => '2026-09-14 09:41:00',
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

function invUrl(Order $order): string
{
    return '/admin-api/orders/' . $order->id . '/invoice';
}

function invSlipUrl(Order $order): string
{
    return '/admin-api/orders/' . $order->id . '/packing-slip';
}

/**
 * The exact markup a figure renders as on the invoice.
 *
 * Asserted against the real string rather than against "AED 473.50": the symbol
 * sits in its own bidi isolate inside the amount span (Money::format does that
 * deliberately, so an RTL symbol cannot swap places with the digits), which
 * means the two are not adjacent characters in the HTML at all. A test looking
 * for them side by side would fail against correct output.
 */
function invMoney(int $fils): string
{
    return InvoiceDocument::money($fils);
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller on both printable documents', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder(['email' => 'private-buyer@example.test']);

    foreach ([invUrl($order), invSlipUrl($order)] as $uri) {
        $response = test()->get($uri);

        expect($response->getStatusCode())->not->toBe(200)
            ->and($response->getStatusCode())->toBeIn([301, 302, 401, 403]);

        $body = $response->getContent();

        expect($body)->not->toContain('Aisha')
            ->and($body)->not->toContain('Marina Heights')
            ->and($body)->not->toContain('+971 50 123 4567')
            ->and($body)->not->toContain('private-buyer@example.test');
    }

    // And nothing was allocated on the way to the refusal — an anonymous hit on
    // the invoice URL must not consume a number out of the sequence.
    expect($order->fresh()->invoice_number)->toBeNull();
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    // A shopper signed into the storefront. The `customer` guard is deliberately
    // separate from `admin`; this asserts the separation is real, not documented.
    $shopper = Customer::create([
        'name' => 'Invoice Shopper',
        'email' => 'invoice-shopper@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');

    expect(test()->get(invUrl($order))->getStatusCode())->not->toBe(200)
        ->and(test()->get(invSlipUrl($order))->getStatusCode())->not->toBe(200);

    // And a site user on the default `web` guard, which is neither of those.
    $user = User::create([
        'name' => 'Invoice Web User',
        'email' => 'invoice-webuser@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');

    expect(test()->get(invUrl($order))->getStatusCode())->not->toBe(200)
        ->and(test()->get(invSlipUrl($order))->getStatusCode())->not->toBe(200);

    expect($order->fresh()->invoice_number)->toBeNull();
});

it('carries the admin guard on every route this lane registers', function () {
    InvoiceAdminRoutes::wire(app());

    $routes = InvoiceAdminRoutes::registered();

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        // Read off the registered route, not off the harness that mounted it.
        expect($middleware)->toContain('auth:admin')
            ->and($middleware)->toContain('web');

        // GET only. Neither document changes anything the caller chooses.
        expect($route->methods())->toContain('GET');
    }
});

it('registers no invoice route outside the admin-api prefix', function () {
    InvoiceAdminRoutes::wire(app());

    $loose = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'InvoiceController'))
        ->reject(fn ($r) => str_starts_with($r->uri(), 'admin-api/'))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    // /api/* is unauthenticated in this app. An invoice route that landed there
    // would be a customer-database leak with a print button on it.
    expect($loose)->toBe([]);
});

it('constrains the order id so a stray segment never reaches an int parameter', function () {
    InvoiceAdminRoutes::wire(app());

    foreach (InvoiceAdminRoutes::registered() as $route) {
        expect($route->wheres['id'] ?? null)->toBe('[0-9]+');
    }
});

/* ------------------------------------------------------------- the invoice */

it('prints the whole invoice for an admin', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    $html = test()->actingAs(invAdmin(), 'admin')
        ->get(invUrl($order))
        ->assertOk()
        ->getContent();

    /*
     * "Invoice", not "Tax Invoice" — Lane DE. This order carries no tax record
     * and no TRN, and InvoiceDocument::docType() will not head a document as a
     * tax document on nothing. InvoiceDocTypeTest covers the whole decision.
     */
    expect($html)->toContain('>Invoice</div>')
        ->and($html)->not->toContain('Tax Invoice')
        ->and($html)->toContain($order->order_number)
        // The allocated number, zero padded.
        ->and($html)->toContain('01000')
        ->and($html)->toContain('Rice Daily Moisturizing Toner 150ml')
        ->and($html)->toContain('Haruharu Wonder')
        ->and($html)->toContain('HH-RT-150')
        ->and($html)->toContain('30ml')
        // Billing and delivery are different people on this order, and both
        // belong on the document.
        ->and($html)->toContain('Aisha Khan')
        ->and($html)->toContain('Marina Heights')
        ->and($html)->toContain('Noura Al Mansoori')
        ->and($html)->toContain('Al Barsha')
        // Payment through Order::paymentLabel(), never the bare gateway id.
        ->and($html)->toContain('Cash on delivery')
        ->and($html)->not->toContain('>cod<')
        ->and($html)->toContain('Standard delivery (1–3 working days)')
        ->and($html)->toContain('GLOW10')
        ->and($html)->toContain('Gift wrapping')
        // The store's own identity, from settings.
        ->and($html)->toContain('K-Beauty Bliss')
        // A4, and a print rule that hides the toolbar.
        ->and($html)->toContain('size: A4')
        ->and($html)->toContain('@media print');
});

it('states every figure to the exact fil and never to the rounded dirham', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    // The figures the page is a picture of, asserted first, so the rendering can
    // never be reviewed and approved against a total that is wrong.
    expect($order->subtotal)->toBe(46300)
        ->and($order->discount_total)->toBe(4000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->gift_fee)->toBe(1500)
        ->and($order->fee_total)->toBe(3050)
        ->and($order->tax_total)->toBe(0)
        ->and($order->total)->toBe(47350);

    $doc = app(InvoiceDocument::class)->present($order);

    $fils = [];
    foreach ($doc['totals'] as $row) {
        $fils[$row['label']] = $row['fils'];
    }

    expect($fils['Subtotal'])->toBe(46300)
        // Off the bill, so the document shows it negative although the column
        // stores it positive.
        ->and($fils['Discount (GLOW10)'])->toBe(-4000)
        ->and($fils['Delivery'])->toBe(2000)
        ->and($fils['Gift wrapping'])->toBe(1500)
        // fee_total minus the gift fee: the surcharge on its own, named after
        // the gateway that charged it.
        ->and($fils['Cash on delivery fee'])->toBe(1550)
        ->and($fils['Total'])->toBe(47350)
        ->and($doc['totalFils'])->toBe(47350);

    // The rows add up to the total, so an accountant adding the column by hand
    // reaches the same number the customer was charged.
    expect(array_sum(array_slice($fils, 0, -1)))->toBe(47350);

    // Per line, to the fil.
    expect($doc['items'][0]['unitFils'])->toBe(19900)
        ->and($doc['items'][0]['lineFils'])->toBe(39800)
        ->and($doc['items'][1]['unitFils'])->toBe(6500)
        ->and($doc['items'][1]['lineFils'])->toBe(6500);

    $html = test()->actingAs(invAdmin(), 'admin')->get(invUrl($order))->getContent();

    expect($html)->toContain(invMoney(47350))
        ->and($html)->toContain(invMoney(19900))
        ->and($html)->toContain(invMoney(39800))
        ->and($html)->toContain(invMoney(6500))
        ->and($html)->toContain(invMoney(1550))
        // The digits, stated plainly as well, so this reads as an assertion
        // about the amount and not only about the markup around it.
        ->and($html)->toContain('473.50')
        ->and($html)->toContain('199.00')
        // The storefront's rounded rendering of the same total — "AED 474",
        // fifty fils more than was charged. It may not appear anywhere on a
        // financial document.
        ->and(\App\Support\Money::format(47350))->toContain('474')
        ->and($html)->not->toContain('474');
});

it('renders money exactly as the emailed receipt does', function () {
    // Two documents describing one order. If these two helpers ever drift the
    // customer has a receipt and an invoice that disagree about the price.
    foreach ([0, 1, 50, 6500, 21550, 47350, -4000] as $fils) {
        expect(InvoiceDocument::money($fils))->toBe(OrderEmailPresenter::html($fils))
            ->and(InvoiceDocument::moneyPlain($fils))->toBe(OrderEmailPresenter::plain($fils));
    }

    // The trap this rule exists for, stated once in a test rather than only in
    // a comment: the storefront rendering of 21550 fils is not the invoice's.
    expect(InvoiceDocument::money(21550))->toContain('215.50')
        ->and(\App\Support\Money::format(21550))->toContain('216');
});

/* ------------------------------------------------------------------- VAT */

it('shows VAT as a portion of the total and never as an addition to it', function () {
    InvoiceAdminRoutes::wire(app());

    /*
     * ── THE FIGURE MOVED, AND THAT IS THE POINT — LANE DU ──────────────────
     *
     * This asserted 2255 fils, which is 5% inclusive of 47350 — the ORDER
     * TOTAL, fees and all. That was VatDisplay's live display line, asked for
     * at print time, and Lane DU removed it because it reprinted at whatever
     * rate the settings held on the day somebody opened the document.
     *
     * The order now carries its own record, so the note is the recorded figure:
     * 5% inclusive of the TAXABLE BASE, 46300 - 4000 + 2000 = 44300, which is
     * 2110. The gap between 2255 and 2110 is the AED 30.50 of gift wrapping and
     * cash-on-delivery surcharge that the tax engine has never treated as a
     * taxable supply (see VatDisplay's header) but that the old display line
     * silently taxed anyway. Two arithmetics on one shop, and only one of them
     * was the engine's.
     */
    $order = invDocOrder(['tax_rate' => 5, 'tax_basis' => \App\Support\TaxRule::INCLUSIVE, 'tax_total' => 2110]);
    $doc = app(InvoiceDocument::class)->present($order);

    expect($doc['vatNote'])->not->toBeNull()
        ->and($doc['vatNote']['fils'])->toBe(2110)
        ->and($doc['vatNote']['label'])->toBe('Includes VAT at 5%');

    // It is not a totals row, so it cannot be added in by anything that walks
    // them, and the total is untouched.
    $labels = array_column($doc['totals'], 'label');

    expect($labels)->not->toContain('VAT')
        ->and($doc['totalFils'])->toBe(47350);

    $html = test()->actingAs(invAdmin(), 'admin')->get(invUrl($order))->getContent();

    expect($html)->toContain('Includes VAT at 5%')
        ->and($html)->toContain(invMoney(2110))
        ->and($html)->toContain('21.10');
});

it('says nothing about VAT on an invoice for an order that recorded none', function () {
    InvoiceAdminRoutes::wire(app());

    /*
     * The order the shipped `display` mode produces: tax_total 0, no rate, no
     * basis. It used to get VatDisplay's live line printed under its Total; it
     * now gets nothing, because nothing about its tax is recoverable and a
     * document that states no tax is not wrong where one that states the wrong
     * tax is. See InvoiceDocument::vatNote().
     */
    $order = invDocOrder();

    expect(\App\Support\OrderTax::recorded($order))->toBeNull()
        ->and(app(InvoiceDocument::class)->present($order)['vatNote'])->toBeNull();

    $html = test()->actingAs(invAdmin(), 'admin')->get(invUrl($order))->getContent();

    // 'Includes VAT', the words the note prints — not the class name, which a
    // search of rendered HTML would also find in the inlined stylesheet.
    expect(str_contains($html, 'Includes VAT'))
        ->toBeFalse('an order with no tax record still has a VAT figure printed under its Total');
});

it('prints an imported order s own stored tax instead of a second computed one', function () {
    InvoiceAdminRoutes::wire(app());

    // A WooCommerce order carries a real tax_total, already inside its total.
    // Restating it as a different, freshly computed number would put two VAT
    // figures on one invoice.
    $order = invDocOrder(['tax_total' => 2000]);

    $doc = app(InvoiceDocument::class)->present($order);

    $fils = [];
    foreach ($doc['totals'] as $row) {
        $fils[$row['label']] = $row['fils'];
    }

    expect($fils['VAT'])->toBe(2000)
        ->and($doc['vatNote'])->toBeNull();
});

it('says nothing about VAT when the owner has switched the line off', function () {
    app(SettingsService::class)->set('vat_enabled', false);

    $doc = app(InvoiceDocument::class)->present(invDocOrder());

    expect($doc['vatNote'])->toBeNull();
});

/* --------------------------------------------------------------- escaping */

it('escapes a customer who typed markup into the gift message', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder([
        'gift_note' => '<script>alert(1)</script>',
        'customer_note' => '<img src=x onerror=alert(2)>',
        'billing_address' => [
            'first_name' => '"><script>alert(3)</script>',
            'last_name' => 'O\'Brien & Sons',
            'line1' => '<b>Villa 9</b>', 'city' => 'Dubai', 'country' => 'AE',
        ],
    ]);

    foreach ([invUrl($order), invSlipUrl($order)] as $uri) {
        $html = test()->actingAs(invAdmin(), 'admin')->get($uri)->assertOk()->getContent();

        // The document carries no <script> tag of its own — the print button is
        // an inline onclick — so any script tag on the page would be one the
        // customer put there.
        expect($html)->not->toContain('<script')
            // The tag never forms: the angle brackets are entities, so the
            // browser paints the customer's text and runs none of it.
            ->and($html)->not->toContain('<img src=x')
            ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
            ->and($html)->toContain('&lt;img src=x onerror=alert(2)&gt;')
            ->and($html)->toContain('O&#039;Brien &amp; Sons');
    }
});

/* ----------------------------------------------------------- the snapshot */

it('prints the line as bought even after the product is renamed and deleted', function () {
    InvoiceAdminRoutes::wire(app());

    $product = Product::create([
        'slug' => 'inv-snapshot-' . uniqid(),
        'name' => 'Original Name At Purchase',
        'status' => 'publish', 'is_visible' => true,
        'price' => 19900, 'stock_status' => 'instock', 'position' => 1,
    ]);

    $order = invDocOrder();
    $order->items()->create([
        'product_id' => $product->id,
        'name' => 'Original Name At Purchase',
        'brand' => 'Haruharu Wonder',
        'sku' => 'SNAP-1',
        'quantity' => 1,
        'unit_price' => 19900,
        'subtotal' => 19900,
        'total' => 19900,
    ]);

    // Everything the catalogue is allowed to do to a product after the sale.
    $product->update(['name' => 'Renamed Since The Sale', 'price' => 29900]);
    $product->delete();

    $html = test()->actingAs(invAdmin(), 'admin')
        ->get(invUrl($order->fresh('items')))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Original Name At Purchase')
        ->and($html)->not->toContain('Renamed Since The Sale')
        ->and($html)->toContain(invMoney(19900))
        ->and($html)->not->toContain(invMoney(29900));
});

it('still prints an invoice for an order that has been trashed', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();
    $order->delete();

    // A record of a sale does not stop existing because a row was tidied away.
    test()->actingAs(invAdmin(), 'admin')
        ->get(invUrl($order))
        ->assertOk()
        ->assertSee($order->order_number);
});

it('answers a browser with a page rather than a JSON body when the order is gone', function () {
    InvoiceAdminRoutes::wire(app());

    $response = test()->actingAs(invAdmin(), 'admin')->get('/admin-api/orders/999999/invoice');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain('nothing to print');
});

/* ------------------------------------------------------- when it allocates */

it('allocates the number on the first view of the invoice and never again', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();
    $admin = invAdmin();

    expect($order->invoice_number)->toBeNull();

    test()->actingAs($admin, 'admin')->get(invUrl($order))->assertOk();

    $first = $order->fresh()->invoice_number;

    expect($first)->toBe(1000);

    // A reload, a browser prefetch, a second admin — all the same number.
    for ($i = 0; $i < 3; $i++) {
        test()->actingAs($admin, 'admin')->get(invUrl($order))->assertOk();
    }

    expect($order->fresh()->invoice_number)->toBe($first);

    // And the sequence advanced once, not four times.
    $next = invDocOrder();
    test()->actingAs($admin, 'admin')->get(invUrl($next))->assertOk();

    expect($next->fresh()->invoice_number)->toBe(1001);
});

it('does not consume a number when only the packing slip is printed', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    test()->actingAs(invAdmin(), 'admin')->get(invSlipUrl($order))->assertOk();

    // A picking list is printed for orders that may never be invoiced. Burning
    // a number on one leaves a hole in a legal sequence.
    expect($order->fresh()->invoice_number)->toBeNull();
});

/* ------------------------------------------------------- the packing slip */

it('prints the packing slip with everything the packer needs', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    $html = test()->actingAs(invAdmin(), 'admin')
        ->get(invSlipUrl($order))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Packing Slip')
        ->and($html)->toContain($order->order_number)
        ->and($html)->toContain('Rice Daily Moisturizing Toner 150ml')
        ->and($html)->toContain('HH-RT-150')
        ->and($html)->toContain('30ml')
        // The delivery address, written out rather than collapsed to "as above":
        // the person reading it is at a packing bench.
        ->and($html)->toContain('Noura Al Mansoori')
        ->and($html)->toContain('Villa 7, Street 21, Al Barsha')
        ->and($html)->toContain('Happy birthday, Mama.')
        ->and($html)->toContain('Please ring the doorbell twice')
        // Three items across two lines.
        ->and($html)->toContain('Gift');
});

it('puts no price of any kind on the packing slip', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    // Invoice it first, so the failure mode being guarded against — a document
    // that grows prices once the order has money attached to it — is live.
    test()->actingAs(invAdmin(), 'admin')->get(invUrl($order))->assertOk();

    $html = test()->actingAs(invAdmin(), 'admin')
        ->get(invSlipUrl($order))
        ->assertOk()
        ->getContent();

    // Asserted over the whole page rather than over a list of fields somebody
    // has to remember to keep up to date. This sheet goes in the parcel, and a
    // gift order arrives at the recipient's door.
    expect($html)->not->toContain('AED')
        ->and($html)->not->toContain('473.50')
        ->and($html)->not->toContain('199.00')
        ->and($html)->not->toContain('398.00')
        ->and($html)->not->toContain('Subtotal')
        ->and($html)->not->toContain('Total')
        ->and($html)->not->toContain('Discount')
        ->and($html)->not->toContain('GLOW10')
        ->and($html)->not->toContain('VAT');
});

it('carries the invoice number on the packing slip once one exists', function () {
    InvoiceAdminRoutes::wire(app());

    $order = invDocOrder();

    test()->actingAs(invAdmin(), 'admin')->get(invUrl($order))->assertOk();

    // A warehouse matching a parcel to a document is helped by it.
    test()->actingAs(invAdmin(), 'admin')
        ->get(invSlipUrl($order))
        ->assertOk()
        ->assertSee('01000');
});

/* ------------------------------------------------------ the seller details */

it('prints the business details the owner has entered', function () {
    InvoiceAdminRoutes::wire(app());

    $settings = app(SettingsService::class);
    $settings->set('invoice_business_name', 'K Beauty Bliss Trading LLC');
    $settings->set('invoice_address', "Office 1902, Burlington Tower\nBusiness Bay, Dubai\nUnited Arab Emirates");
    $settings->set('invoice_trn', '100123456700003');
    $settings->set('invoice_footer', 'Payment received in full. Returns within 14 days, unopened.');

    $html = test()->actingAs(invAdmin(), 'admin')
        ->get(invUrl(invDocOrder()))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('K Beauty Bliss Trading LLC')
        ->and($html)->toContain('Burlington Tower')
        ->and($html)->toContain('Business Bay, Dubai')
        ->and($html)->toContain('TRN 100123456700003')
        ->and($html)->toContain('Returns within 14 days');
});

it('prints no tax registration number when none has been entered', function () {
    // An invoice showing an invented TRN is worse than one showing none.
    // The order needs a tax record, or there is no VAT note to carry a TRN at
    // all — which is its own test above, not this one.
    $doc = app(InvoiceDocument::class)->present(
        invDocOrder(['tax_rate' => 5, 'tax_basis' => \App\Support\TaxRule::INCLUSIVE, 'tax_total' => 2110])
    );

    expect($doc['seller']['trn'])->toBe('')
        ->and($doc['vatNote']['trn'])->toBe('');

    InvoiceAdminRoutes::wire(app());

    $html = test()->actingAs(invAdmin(), 'admin')->get(invUrl(invDocOrder()))->getContent();

    expect($html)->not->toContain('TRN');
});

/* -------------------------------------------------------- the addresses */

it('writes the country out and prints an emirate once', function () {
    $doc = app(InvoiceDocument::class)->present(invDocOrder([
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204', 'city' => 'Dubai', 'state' => 'Dubai',
            'country' => 'AE',
        ],
    ]));

    // "AE" is right for a varchar(2) column and wrong on a document a courier
    // or an accountant reads. "Dubai Dubai" is what city + state gives you in
    // an emirate whose city shares its name.
    expect($doc['billTo'])->toBe([
        'Aisha Khan',
        'Apartment 1204',
        'Dubai',
        'United Arab Emirates',
    ]);
});

it('keeps a state that is genuinely different from the city', function () {
    $doc = app(InvoiceDocument::class)->present(invDocOrder([
        'billing_address' => [
            'first_name' => 'Sara', 'last_name' => 'Ahmed',
            'line1' => 'Street 4', 'city' => 'Jeddah', 'state' => 'Makkah',
            'country' => 'SA',
        ],
    ]));

    expect($doc['billTo'])->toBe([
        'Sara Ahmed',
        'Street 4',
        'Jeddah Makkah',
        'Saudi Arabia',
    ]);
});

it('prints an unknown country code rather than dropping the country', function () {
    $doc = app(InvoiceDocument::class)->present(invDocOrder([
        'billing_address' => ['first_name' => 'X', 'city' => 'Somewhere', 'country' => 'ZZ'],
    ]));

    // Better a code than no country at all on a parcel.
    expect($doc['billTo'])->toContain('ZZ');
});

it('falls back to the store identity rows when no invoice details are set', function () {
    $seller = app(InvoiceDocument::class)->seller();

    // A fresh install prints something correct rather than a blank masthead.
    expect($seller['name'])->toBe('K-Beauty Bliss')
        ->and($seller['addressLines'])->toBe([]);
});
