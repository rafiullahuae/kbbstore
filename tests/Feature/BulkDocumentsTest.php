<?php

declare(strict_types=1);

/**
 * Bulk printing — many orders, one document, one Print.
 *
 * Five things are pinned hardest here, because each is a way to be wrong
 * quietly:
 *
 *   THE PAGE BREAK. It is the whole feature and it is invisible in HTML. Two
 *   orders sharing a sheet of paper look perfect in a browser window and come
 *   out of the printer as a packing slip with somebody else's delivery address
 *   halfway down it. The rule is asserted in both spellings, and asserted to be
 *   lifted off the last sheet so no batch ends with a blank page.
 *
 *   THE INVOICE SEQUENCE. A bulk run must not mint numbers it throws away and
 *   must not mint two for one order. Every refusal is asserted to have left the
 *   sequence untouched — not by reading a message, but by reading the column.
 *
 *   THE GUARD. One page here carries up to a hundred buyers' names, addresses,
 *   phone numbers and totals. Asserted against an anonymous caller, a signed-in
 *   storefront customer and a `web`-guard user, with the middleware read back
 *   off the REGISTERED route rather than trusted from the harness.
 *
 *   THE SHEETS ARE THE SAME SHEETS. A bulk sheet is asserted to be byte for
 *   byte what the single-order document prints for that order. That is what
 *   makes the shared partial worth having: a change to a column heading cannot
 *   land on one and not the other.
 *
 *   WHAT IS NOT PRINTED. The three documents that are not the invoice are
 *   asserted to allocate nothing at all, over the column, because printing a
 *   picking list for an order that is later cancelled must not leave a gap in a
 *   legal sequence.
 *
 * NOTE ON ASSERTION STYLE. `expect($haystack)->not->toContain($needle, $msg)`
 * PASSES VACUOUSLY — toContain is variadic, so the message is read as a second
 * needle and the negation is satisfied by it being absent. Every absence
 * assertion below goes through str_contains() or array_diff().
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Invoices\BulkDocumentSelection;
use App\Support\AdminCapabilities;
use App\Support\Locale;
use App\Support\OrderLocale;
use Tests\Support\BulkDocumentAdminRoutes;
use Tests\Support\InvoiceAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function gcAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Bulk Owner',
        'email' => 'bulk-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** One plain order, sized so that one order is one printed page. */
function gcOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::create(array_merge([
        'order_number' => 'KBB-BULK-' . $n,
        'email' => 'buyer-' . $n . '@example.com',
        'phone' => '+971 50 123 45' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT),
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => [
            'first_name' => 'Buyer', 'last_name' => 'Number ' . $n,
            'line1' => 'Apartment ' . $n . ', Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'shipping_address' => [
            'first_name' => 'Buyer', 'last_name' => 'Number ' . $n,
            'line1' => 'Apartment ' . $n . ', Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 55 987 6543',
        ],
        'subtotal' => 19900,
        'shipping_total' => 2000,
        'tax_total' => 0,
        'total' => 21900,
        'shipping_method' => 'Standard delivery (1–3 working days)',
        'payment_method' => 'card',
        'payment_method_title' => 'Card',
        'created_at' => '2026-09-14 09:41:00',
    ], $attributes));

    $order->items()->create([
        'name' => 'Rice Daily Moisturizing Toner 150ml',
        'brand' => 'Haruharu Wonder',
        'sku' => 'HH-RT-150',
        'quantity' => 1,
        'unit_price' => 19900,
        'subtotal' => 19900,
        'total' => 19900,
    ]);

    return $order->fresh('items');
}

/** @param  list<Order>|list<int>  $orders */
function gcUrl(string $type, array $orders): string
{
    $ids = array_map(fn ($o) => $o instanceof Order ? $o->id : $o, $orders);

    return '/admin-api/orders-bulk-documents?type=' . $type . '&ids=' . implode(',', $ids);
}

/** Wire both route files: the bulk one, and the singles it is compared against. */
function gcWire(): void
{
    BulkDocumentAdminRoutes::wire(app());
    InvoiceAdminRoutes::wire(app());
}

/* ---------------------------------------------------------------- the guard */

it('refuses an anonymous caller, and allocates nothing on the way to the refusal', function () {
    gcWire();

    $orders = [gcOrder(['email' => 'private-buyer@example.test']), gcOrder()];

    $response = test()->get(gcUrl('invoice', $orders));

    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->getStatusCode())->toBeIn([301, 302, 401, 403]);

    $body = (string) $response->getContent();

    expect(str_contains($body, 'private-buyer@example.test'))->toBeFalse()
        ->and(str_contains($body, 'Marina Heights'))->toBeFalse();

    foreach ($orders as $order) {
        expect($order->fresh()->invoice_number)->toBeNull();
    }
});

it('refuses a signed-in non-admin as firmly as an anonymous one', function () {
    gcWire();

    $orders = [gcOrder(), gcOrder()];

    $shopper = Customer::create([
        'name' => 'Bulk Shopper',
        'email' => 'bulk-shopper@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($shopper, 'customer');
    expect(test()->get(gcUrl('packing-slip', $orders))->getStatusCode())->not->toBe(200);

    $user = User::create([
        'name' => 'Bulk Web User',
        'email' => 'bulk-webuser@example.test',
        'password' => 'secret-secret',
    ]);

    test()->actingAs($user, 'web');
    expect(test()->get(gcUrl('invoice', $orders))->getStatusCode())->not->toBe(200);

    foreach ($orders as $order) {
        expect($order->fresh()->invoice_number)->toBeNull();
    }
});

it('carries the admin guard, read back off the registered route', function () {
    gcWire();

    $routes = BulkDocumentAdminRoutes::registered();

    expect($routes)->toHaveCount(1);

    foreach ($routes as $route) {
        $stack = $route->gatherMiddleware();

        foreach (BulkDocumentAdminRoutes::STACK as $expected) {
            expect(in_array($expected, $stack, true))->toBeTrue(
                $route->uri() . ' is missing ' . $expected . ' from its middleware'
            );
        }

        expect($route->methods())->toContain('GET');
    }
});

it('maps the bulk route to the same capability the single documents carry', function () {
    expect(AdminCapabilities::forPath('GET', 'admin-api/orders-bulk-documents'))
        ->toBe('invoices.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders/41/invoice'))
        ->toBe('invoices.view');
});

/* ------------------------------------------------------- one sheet per order */

it('puts every selected order on the page, once, in the order it was asked for', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $a = gcOrder();
    $b = gcOrder();
    $c = gcOrder();

    // Deliberately not ascending: the pile of paper should match the pile of
    // parcels the operator ticked, not a sort this code invented.
    $html = test()->get(gcUrl('packing-slip', [$c, $a, $b]))
        ->assertStatus(200)
        ->getContent();

    foreach ([$a, $b, $c] as $order) {
        expect(substr_count($html, $order->order_number))->toBeGreaterThan(0);
    }

    $positions = array_map(fn (Order $o) => strpos($html, (string) $o->order_number), [$c, $a, $b]);

    expect($positions)->toBe(array_values(array_filter($positions)))
        ->and($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2]);

    // Three sheets, not four and not two.
    expect(substr_count($html, 'class="sheet"'))->toBe(3);
});

it('prints one sheet for an order named twice', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $order = gcOrder();

    $html = test()->get(gcUrl('packing-slip', [$order->id, $order->id, $order->id]))
        ->assertStatus(200)
        ->getContent();

    expect(substr_count($html, 'class="sheet"'))->toBe(1);
});

it('prints the sheets it can find and says which ones it could not', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $order = gcOrder();
    $ghost = $order->id + 90210;

    $html = test()->get(gcUrl('delivery-note', [$order->id, $ghost]))
        ->assertStatus(200)
        ->getContent();

    expect(substr_count($html, 'class="sheet"'))->toBe(1)
        ->and($html)->toContain((string) $order->order_number)
        ->and($html)->toContain((string) $ghost)
        ->and($html)->toContain('could not be found');

    // The report is for the operator at the screen, not for the parcel.
    expect($html)->toContain('class="bulkmiss no-print"');
});

/* ------------------------------------------- the page break, which is the point */

it('breaks the page between sheets, in both spellings, and not after the last one', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $html = test()->get(gcUrl('packing-slip', [gcOrder(), gcOrder()]))
        ->assertStatus(200)
        ->getContent();

    expect($html)
        ->toContain('.bulkdoc > .sheet { break-after: page; page-break-after: always; }')
        ->toContain('.bulkdoc > .sheet:last-child { break-after: auto; page-break-after: auto; }');
});

it('keeps A4 for the three paper documents and A6 for a run of labels', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $orders = [gcOrder(), gcOrder()];

    foreach (['invoice', 'packing-slip', 'delivery-note'] as $type) {
        $html = test()->get(gcUrl($type, $orders))->assertStatus(200)->getContent();

        expect($html)->toContain('@page { size: A4;')
            ->and(str_contains($html, '105mm 148mm'))->toBeFalse();
    }

    $labels = test()->get(gcUrl('dispatch-label', $orders))->assertStatus(200)->getContent();

    expect($labels)->toContain('@page { size: 105mm 148mm; margin: 6mm; }')
        ->and(str_contains($labels, '@page { size: A4;'))->toBeFalse()
        // Each inner sheet needs the label class a single label gets from its
        // own sheet-class section.
        ->and(substr_count($labels, 'class="sheet lbl"'))->toBe(2)
        // …and the label's own stylesheet, from the same partial.
        ->and($labels)->toContain('.sheet.lbl { width: 105mm; min-height: 148mm; padding: 8mm; }');
});

/* --------------------------------- the sheet is the single document's sheet */

it('prints exactly what the single-order document prints, for every type', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $order = gcOrder();

    $pairs = [
        'packing-slip' => '/admin-api/orders/' . $order->id . '/packing-slip',
        'delivery-note' => '/admin-api/orders/' . $order->id . '/delivery-note',
        'dispatch-label' => '/admin-api/orders/' . $order->id . '/shipping-label',
        // The invoice last, because it is the one that writes: comparing it
        // first would leave the other three comparing an order that had since
        // grown an invoice number and a PAID-side reference line.
        'invoice' => '/admin-api/orders/' . $order->id . '/invoice',
    ];

    foreach ($pairs as $type => $singleUrl) {
        $single = (string) test()->get($singleUrl)->assertStatus(200)->getContent();
        $bulk = (string) test()->get(gcUrl($type, [$order]))->assertStatus(200)->getContent();

        // The body of the single document's one sheet, between its wrapper and
        // the end of the page. Compared as a string, so a change to the shared
        // partial has to land on both or fail here.
        $open = strpos($single, '<div class="sheet');
        expect($open)->not->toBeFalse($type . ': the single document has no sheet');

        $body = substr($single, (int) strpos($single, '>', (int) $open) + 1);
        $body = substr($body, 0, (int) strrpos($body, '</div>'));
        $body = trim($body);

        expect(strlen($body))->toBeGreaterThan(400)
            ->and(str_contains($bulk, $body))->toBeTrue(
                $type . ': the bulk sheet is not the single document\'s sheet'
            );
    }
});

it('keeps every promise the single sheet makes, over the whole batch', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    // A gift going to somebody else: the packing slip and the label both reach
    // a person who must not be shown what was paid.
    $gift = gcOrder([
        'is_gift' => true,
        'gift_note' => 'Happy birthday, Mama.',
        'shipping_address' => [
            'first_name' => 'Noura', 'last_name' => 'Al Mansoori',
            'line1' => 'Villa 7, Street 21', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 55 987 6543',
        ],
    ]);
    $plain = gcOrder();

    /*
     * Asserted over the WHOLE rendered batch rather than over a list of fields.
     * DispatchDocumentsTest makes this promise for one sheet; a batch is where
     * it would be broken quietly, because the operator checks the first sheet
     * and taping the twentieth to a box is the moment nobody is reading.
     *
     * str_contains, not ->not->toContain: toContain is variadic, so a message
     * passed as a second argument is read as a second needle and the negation
     * is satisfied by it being absent. That form passes vacuously.
     */
    foreach (['packing-slip', 'delivery-note', 'dispatch-label'] as $type) {
        $html = (string) test()->get(gcUrl($type, [$gift, $plain]))->assertStatus(200)->getContent();

        // Everything after the toolbar and the stylesheet: the sheets only.
        $sheets = substr($html, (int) strpos($html, '<div class="bulkdoc">'));

        foreach (['219.00', '21900', 'AED'] as $money) {
            expect(str_contains($sheets, $money))->toBeFalse(
                $type . ' printed ' . $money . ' on a sheet that must carry no prices'
            );
        }
    }

    // …and the label, which is on the OUTSIDE of the box, names nothing in it.
    $labels = (string) test()->get(gcUrl('dispatch-label', [$gift, $plain]))->assertStatus(200)->getContent();

    foreach (['Rice Daily Moisturizing Toner', 'Haruharu Wonder', 'HH-RT-150', 'Happy birthday'] as $secret) {
        expect(str_contains($labels, $secret))->toBeFalse(
            'a dispatch label named ' . $secret . ', which is on the outside of the parcel'
        );
    }
});

/* ------------------------------------------------------- the invoice sequence */

it('gives each order in a batch its own invoice number, once', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $orders = [gcOrder(), gcOrder(), gcOrder()];

    test()->get(gcUrl('invoice', $orders))->assertStatus(200);

    $numbers = array_map(fn (Order $o) => $o->fresh()->invoice_number, $orders);

    expect($numbers)->each->toBeGreaterThan(0)
        ->and(count(array_unique($numbers)))->toBe(3);

    // Printed a second time — a reload, a prefetch, a second operator — and the
    // sequence has not moved.
    test()->get(gcUrl('invoice', $orders))->assertStatus(200);

    expect(array_map(fn (Order $o) => $o->fresh()->invoice_number, $orders))->toBe($numbers);
});

it('allocates nothing at all for a packing slip, a delivery note or a label', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $orders = [gcOrder(), gcOrder()];

    foreach (['packing-slip', 'delivery-note', 'dispatch-label'] as $type) {
        test()->get(gcUrl($type, $orders))->assertStatus(200);
    }

    foreach ($orders as $order) {
        expect($order->fresh()->invoice_number)->toBeNull()
            ->and($order->fresh()->invoiced_at)->toBeNull();
    }
});

it('refuses a batch over the cap without minting a single number', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $orders = [gcOrder(), gcOrder(), gcOrder()];

    // Real ids first, so a cap check that ran after the load would have had
    // every one of them in hand and could have allocated.
    $ids = array_merge(
        array_map(fn (Order $o) => $o->id, $orders),
        range(900000, 900000 + BulkDocumentSelection::MAX),
    );

    $response = test()->get(gcUrl('invoice', $ids));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getContent())->toContain((string) BulkDocumentSelection::MAX);

    foreach ($orders as $order) {
        expect($order->fresh()->invoice_number)->toBeNull();
    }
});

it('prints a batch of exactly the cap', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    // Two real orders padded out to the cap with ids that do not exist: this is
    // about the boundary, not about seeding a hundred orders.
    $real = [gcOrder(), gcOrder()];
    $ids = array_merge(
        array_map(fn (Order $o) => $o->id, $real),
        range(800000, 800000 + BulkDocumentSelection::MAX - 3),
    );

    expect($ids)->toHaveCount(BulkDocumentSelection::MAX);

    test()->get(gcUrl('packing-slip', $ids))->assertStatus(200);

    $ids[] = 700001;

    expect(test()->get(gcUrl('packing-slip', $ids))->getStatusCode())->toBe(400);
});

it('refuses an unknown document type, an empty selection and a batch of ghosts', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $order = gcOrder();

    // An unknown type, naming a real order: nothing is loaded, nothing minted.
    $bad = test()->get('/admin-api/orders-bulk-documents?type=shipping-manifest&ids=' . $order->id);
    expect($bad->getStatusCode())->toBe(400);

    // No ids at all, and ids that are not numbers.
    expect(test()->get('/admin-api/orders-bulk-documents?type=invoice&ids=')->getStatusCode())->toBe(400)
        ->and(test()->get('/admin-api/orders-bulk-documents?type=invoice&ids=,,,')->getStatusCode())->toBe(400)
        ->and(test()->get('/admin-api/orders-bulk-documents?type=invoice&ids=abc,-4,0')->getStatusCode())->toBe(400);

    // Ids that are numbers and are not orders.
    $ghosts = test()->get('/admin-api/orders-bulk-documents?type=invoice&ids=770001,770002');
    expect($ghosts->getStatusCode())->toBe(400)
        ->and($ghosts->getContent())->toContain('None of those orders exist');

    expect($order->fresh()->invoice_number)->toBeNull();
});

it('prints a trashed order, because its paperwork is still a record', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $live = gcOrder();
    $gone = gcOrder();
    $gone->delete();

    $html = test()->get(gcUrl('packing-slip', [$live, $gone]))
        ->assertStatus(200)
        ->getContent();

    expect(substr_count($html, 'class="sheet"'))->toBe(2)
        ->and($html)->toContain((string) $gone->order_number);
});

/* ------------------------------------------------------------------ language */

it('prints each invoice in its own order language inside one mixed batch', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $english = gcOrder(['locale' => 'en']);
    $arabic = gcOrder(['locale' => 'ar']);

    $html = test()->get(gcUrl('invoice', [$english, $arabic]))
        ->assertStatus(200)
        ->getContent();

    // Each sheet declares its own language, whatever the shell around it is.
    expect($html)->toContain('lang="en"')
        ->and($html)->toContain('lang="ar"');

    // And the shell is the operator's, not the last order's.
    expect($html)->toContain('<html lang="' . Locale::htmlLang() . '"');
});

it('leaves the picking list and the label in the operator language', function () {
    gcWire();
    test()->actingAs(gcAdmin(), 'admin');

    $arabic = gcOrder(['locale' => 'ar']);

    $html = test()->get(gcUrl('packing-slip', [$arabic]))
        ->assertStatus(200)
        ->getContent();

    // The picking sheet is read inside the building. It is rendered with no
    // locale switch at all, so its sheet carries the process locale.
    expect($html)->toContain('lang="' . Locale::htmlLang() . '"')
        ->and(str_contains($html, 'lang="ar"'))->toBeFalse();

    // …and the order's own language is still recorded and still used elsewhere.
    expect(OrderLocale::render($arabic, fn () => Locale::htmlLang()))->toBe('ar');
});

/* -------------------------------------------------------- the selection object */

it('parses, de-duplicates and caps a selection without touching the database', function () {
    $selection = BulkDocumentSelection::from('packing-slip', '7, 3,7,  11 ,,x,-2,0');

    expect($selection->ids)->toBe([7, 3, 11])
        ->and($selection->type)->toBe('packing-slip')
        ->and($selection->isLabel())->toBeFalse()
        ->and($selection->allocatesInvoiceNumbers())->toBeFalse();

    expect(BulkDocumentSelection::from('invoice', [4, '5'])->allocatesInvoiceNumbers())->toBeTrue();
    expect(BulkDocumentSelection::from('dispatch-label', '9')->isLabel())->toBeTrue();

    expect(fn () => BulkDocumentSelection::from('nope', '1'))
        ->toThrow(\App\Services\Invoices\BulkDocumentRefused::class);
    expect(fn () => BulkDocumentSelection::from('invoice', ''))
        ->toThrow(\App\Services\Invoices\BulkDocumentRefused::class);
    expect(fn () => BulkDocumentSelection::from('invoice', range(1, BulkDocumentSelection::MAX + 1)))
        ->toThrow(\App\Services\Invoices\BulkDocumentRefused::class);

    // Every type in the map names a Blade partial that exists, and a title key
    // InterfaceStrings actually defines. The second half is this lane's own
    // stand-in for StorefrontStringsAreKeyedTest: that guard reads __('…')
    // literals out of templates, and the bulk title is looked up through
    // titleKey(), which it cannot follow.
    $defined = \App\Services\Translation\InterfaceStrings::flat();

    foreach (BulkDocumentSelection::TYPES as $type => $partial) {
        $selection = BulkDocumentSelection::from($type, '1');

        expect(view()->exists($partial))->toBeTrue($type . ' names a partial that does not exist')
            ->and(array_key_exists($selection->titleKey(), $defined))
            ->toBeTrue($type . ' names a title key nothing defines: ' . $selection->titleKey())
            ->and(trim((string) __($selection->titleKey())))->not->toBe($selection->titleKey());
    }

    // And the rest of the bulk strings, which the templates DO name as
    // literals, so that a rename breaks here rather than on the printed page.
    foreach ([
        'invoice.bulk.subject',
        'invoice.bulk.sheet_of',
        'invoice.bulk.missing_headline',
        'invoice.bulk.missing_body',
        'invoice.bulk.refused_title',
        'invoice.bulk.refused_close',
    ] as $key) {
        expect(array_key_exists($key, $defined))->toBeTrue($key . ' is used and not defined');
    }
});
