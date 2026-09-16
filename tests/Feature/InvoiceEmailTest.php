<?php

declare(strict_types=1);

/**
 * "Email invoice", the action that refused for months because there was nothing
 * to send.
 *
 * Its old message — "Not available yet — this needs the invoice PDF, which is
 * not built" — was true when it was written. It stops being true with this
 * package, and a button that refuses for a reason that is no longer the reason
 * is exactly the defect ResendConfirmationTest was written about: the resend
 * button went on saying "outbound email is not configured for this store" long
 * after the store was sending order email.
 *
 * What is pinned here:
 *
 *   IT SENDS, AND IT REPORTS. The mailer answers honestly rather than
 *   swallowing, because a person pressed a button and is waiting. A failure is
 *   a 422 carrying the reason, never a 200 with a tick on it.
 *
 *   THE NUMBER IS ALLOCATED BEFORE THE SEND, EXACTLY ONCE. An emailed invoice
 *   with no number on it is not an invoice, and a customer who is sent two
 *   copies with two numbers has two documents against one debt.
 *
 *   THE EMAIL AND THE PRINTED PAGE ARE THE SAME DOCUMENT. Same number, same
 *   figures, to the fil — they render one InvoiceDocument array.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Models\AdminUser;
use App\Models\Order;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Invoices\InvoiceNumbers;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;

function invMailAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Invoice Mail Owner',
        'email' => 'invoice-mail-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** The same awkward order the printable documents are tested against. */
function invMailOrder(array $attributes = []): Order
{
    static $n = 0;
    $n++;

    $order = Order::create(array_merge([
        'order_number' => 'KBB-MAIL-' . $n,
        'email' => 'shopper-' . $n . '@kbb.test',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE',
        ],
        'subtotal' => 46300,
        'discount_total' => 4000,
        'coupon_code' => 'GLOW10',
        'shipping_total' => 2000,
        'fee_total' => 3050,
        'gift_fee' => 1500,
        'is_gift' => true,
        'gift_note' => 'Happy birthday, Mama.',
        'tax_total' => 0,
        'total' => 47350,
        'shipping_method' => 'Standard delivery (1–3 working days)',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'created_at' => '2026-09-14 09:41:00',
    ], $attributes));

    $order->items()->create([
        'name' => 'Rice Daily Moisturizing Toner 150ml',
        'brand' => 'Haruharu Wonder', 'sku' => 'HH-RT-150',
        'quantity' => 2, 'unit_price' => 19900, 'subtotal' => 39800, 'total' => 39800,
    ]);

    $order->items()->create([
        'name' => 'Centella Ampoule',
        'brand' => 'SKIN1004', 'sku' => 'SK-CA-030',
        'variant_attributes' => ['30ml'],
        'quantity' => 1, 'unit_price' => 6500, 'subtotal' => 6500, 'total' => 6500,
    ]);

    return $order->fresh('items');
}

/* -------------------------------------------------------------- the mailer */

it('sends the invoice to the customer and says which one it sent', function () {
    Mail::fake();

    $order = invMailOrder();

    $result = app(OrderMailer::class)->emailInvoice($order);

    expect($result['ok'])->toBeTrue()
        ->and($result['invoice_number'])->toBe(1000)
        ->and($result['message'])->toContain('01000')
        ->and($result['message'])->toContain($order->email);

    Mail::assertSent(OrderInvoice::class);
});

it('allocates the invoice number before sending, and only once', function () {
    Mail::fake();

    $order = invMailOrder();

    expect($order->invoice_number)->toBeNull();

    $first = app(OrderMailer::class)->emailInvoice($order);
    $second = app(OrderMailer::class)->emailInvoice($order->fresh());

    // Two sends, one number. The customer has two copies of ONE invoice, which
    // is a re-send; two numbers would be two documents against one debt.
    expect($first['invoice_number'])->toBe(1000)
        ->and($second['invoice_number'])->toBe(1000)
        ->and($order->fresh()->invoice_number)->toBe(1000);

    Mail::assertSent(OrderInvoice::class, 2);
});

it('re-sends the invoice a printed document already carries rather than minting a new one', function () {
    Mail::fake();

    $order = invMailOrder();

    // The owner printed it first, which is what allocates.
    $printed = app(InvoiceNumbers::class)->allocate($order);

    $result = app(OrderMailer::class)->emailInvoice($order->fresh());

    expect($result['invoice_number'])->toBe($printed);
});

it('does not fire a confirmation or a merchant alert as a side effect', function () {
    Mail::fake();

    app(OrderMailer::class)->emailInvoice(invMailOrder());

    // A third email landing in the owner's inbox because they sent a customer
    // an invoice would be a lie about what happened.
    Mail::assertNotSent(OrderConfirmation::class);
    Mail::assertNotSent(NewOrderAlert::class);
});

it('refuses an order with no email address rather than throwing', function () {
    Mail::fake();

    $order = invMailOrder();
    $order->forceFill(['email' => ''])->save();

    $result = app(OrderMailer::class)->emailInvoice($order);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('no email address');

    Mail::assertNothingSent();

    // And no number was burned on an invoice that could never be delivered.
    expect($order->fresh()->invoice_number)->toBeNull();
});

it('is not gated on the automatic order-email switch', function () {
    Mail::fake();

    // The five module keys guard emails the store sends BY ITSELF. This one only
    // ever happens because the owner pressed a button on a screen they are
    // looking at, so a switch whose only effect is to refuse that press is a
    // trap rather than a setting.
    app(SettingsService::class)->setModule('email_order_confirmation', false);

    expect(app(OrderMailer::class)->emailInvoice(invMailOrder())['ok'])->toBeTrue();

    Mail::assertSent(OrderInvoice::class);
});

/* ------------------------------------------------------- what the email says */

it('renders the same figures the printable invoice does, to the fil', function () {
    Mail::fake();

    $order = invMailOrder();
    app(OrderMailer::class)->emailInvoice($order);

    $mailable = new OrderInvoice($order->fresh('items'));
    $html = (string) $mailable->render();

    $doc = app(InvoiceDocument::class)->present($order->fresh('items'));

    expect($doc['totalFils'])->toBe(47350);

    /*
     * "Invoice", not "Tax Invoice" — Lane DE. The heading is
     * InvoiceDocument::docType() now, and this order carries no tax and no TRN,
     * so the document does not claim to be a tax document. InvoiceDocTypeTest
     * covers every state of that decision.
     */
    expect($html)->toContain('>Invoice</div>')
        ->and($html)->not->toContain('Tax Invoice')
        ->and($html)->toContain('01000')
        ->and($html)->toContain($order->order_number)
        // 47350 fils at the currency's real precision, not the storefront's
        // rounded "AED 474".
        ->and($html)->toContain(InvoiceDocument::money(47350))
        ->and($html)->toContain('473.50')
        ->and($html)->not->toContain('474')
        ->and($html)->toContain('Cash on delivery')
        ->and($html)->toContain('GLOW10')
        ->and($html)->toContain('Includes VAT at 5%')
        ->and($html)->toContain(InvoiceDocument::money(2255));
});

it('puts the invoice number in the subject and no money in it', function () {
    $order = invMailOrder();
    app(InvoiceNumbers::class)->allocate($order);

    $subject = (new OrderInvoice($order->fresh('items')))->envelope()->subject;

    // Subjects are quoted in notification previews, in shared screenshots and in
    // every mail server's log along the way.
    expect($subject)->toContain('01000')
        ->and($subject)->toContain($order->order_number)
        ->and($subject)->not->toContain('473')
        ->and($subject)->not->toContain('AED');
});

it('carries a plain-text part with no markup in it', function () {
    $order = invMailOrder();
    app(InvoiceNumbers::class)->allocate($order);

    $mailable = new OrderInvoice($order->fresh('items'));
    $content = $mailable->content();

    $text = (string) view($content->text, array_merge($mailable->buildViewData(), $content->with))->render();

    // Upper-cased docType(), not a literal — Lane DE. No tax, no TRN, so the
    // text part heads the document the same way the HTML part does.
    expect($text)->toContain('INVOICE')
        ->and($text)->not->toContain('TAX INVOICE')
        ->and($text)->toContain('01000')
        ->and($text)->toContain('473.50')
        ->and($text)->not->toContain('<span')
        ->and($text)->not->toContain('<div');
});

it('escapes a gift message somebody typed markup into', function () {
    $order = invMailOrder(['gift_note' => '<script>alert(1)</script>']);
    app(InvoiceNumbers::class)->allocate($order);

    $html = (string) (new OrderInvoice($order->fresh('items')))->render();

    expect($html)->not->toContain('<script');
});

/* ---------------------------------------------------------- the admin action */

it('runs from the order screen and returns the invoice number', function () {
    Mail::fake();

    $order = invMailOrder();

    $body = test()->actingAs(invMailAdmin(), 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/action', ['action' => 'email_invoice'])
        ->assertOk()
        ->json();

    expect($body['ok'])->toBeTrue()
        ->and($body['invoice_number'])->toBe(1000);

    Mail::assertSent(OrderInvoice::class);
    expect($order->fresh()->invoice_number)->toBe(1000);
});

it('reports a refusal through the endpoint instead of claiming success', function () {
    Mail::fake();

    $order = invMailOrder();
    $order->forceFill(['email' => ''])->save();

    test()->actingAs(invMailAdmin(), 'admin')
        ->postJson('/admin-api/orders/' . $order->id . '/action', ['action' => 'email_invoice'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});

it('offers email invoice as a real action and no longer as a placeholder', function () {
    $order = invMailOrder();

    $body = test()->actingAs(invMailAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk()
        ->json();

    expect($body['actions']['real'])->toContain('email_invoice')
        ->and($body['actions']['placeholder'])->not->toContain('email_invoice')
        // resend_confirmation became real when order email shipped but was
        // never put back on this list, so the dropdown stopped offering it.
        ->and($body['actions']['real'])->toContain('resend_confirmation');
});

it('no longer claims the invoice PDF is not built', function () {
    $source = file_get_contents(app_path('Http/Controllers/Admin/AdminOrderController.php'));

    expect($source)->not->toContain('needs the invoice PDF');
});

it('hands the order screen the two document URLs, both inside admin-api', function () {
    $order = invMailOrder();

    $body = test()->actingAs(invMailAdmin(), 'admin')
        ->getJson('/admin-api/orders/' . $order->id . '/detail')
        ->assertOk()
        ->json();

    expect($body['invoice_url'])->toContain('/admin-api/orders/' . $order->id . '/invoice')
        ->and($body['packing_slip_url'])->toContain('/admin-api/orders/' . $order->id . '/packing-slip')
        // Never /api/*, which is unauthenticated in this app.
        ->and($body['invoice_url'])->not->toContain('/api/')
        ->and($body['packing_slip_url'])->not->toContain('/api/');

    // Reading the order screen must not allocate a number; only opening the
    // invoice does.
    expect($order->fresh()->invoice_number)->toBeNull();
});
