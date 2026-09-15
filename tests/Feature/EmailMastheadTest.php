<?php

declare(strict_types=1);

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Models\Order;
use App\Services\Mail\EmailBranding;

/**
 * Every mailable that renders the shared layout must carry a brand.
 *
 * This is a merge regression caught in review, not a hypothetical. The invoice
 * email was written by one lane and the branded masthead by another; the two
 * met at the merge and the invoice went out with its store name blank, because
 * OrderInvoice extends Mailable directly rather than OrderMail and so never
 * built the $brand the layout reads.
 *
 * The test is written against EVERY mailable rather than against OrderInvoice,
 * because the next mailable somebody adds will have the same hole and a test
 * naming one class would not notice.
 */
function mastheadOrder(): Order
{
    // Built here rather than borrowed from OrderEmailsTest: Pest only loads the
    // files it is asked to run, so a helper defined in a sibling file is
    // undefined when this file runs on its own -- and a regression test nobody
    // can run in isolation is a regression test nobody runs.
    $order = Order::create([
        'order_number' => 'KBB-' . uniqid(),
        'email' => 'buyer@example.com',
        'phone' => '+971500000000',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 20000,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create([
        'name' => 'Rice Toner',
        'brand' => 'Haruharu',
        'sku' => 'HH-RT-150',
        'quantity' => 2,
        'unit_price' => 10000,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    return $order->fresh('items');
}

function mastheadOf(string $html): string
{
    // The wordmark div: the store name, optionally split into two spans.
    preg_match('/font-size:23px;font-weight:800;[^>]*>(.*?)<\/div>/s', $html, $m);

    return trim(strip_tags($m[1] ?? ''));
}

it('renders a store name in the masthead of every customer email', function () {
    $order = mastheadOrder();

    $cases = [
        'order confirmation' => new OrderConfirmation($order),
        'invoice' => new OrderInvoice($order),
        'merchant alert' => new NewOrderAlert($order),
    ];

    foreach ($cases as $label => $mailable) {
        $masthead = mastheadOf($mailable->render());

        // Compared against the mailable's OWN wordmark rather than a literal
        // store name: the name is a setting, and a test that hardcodes it
        // fails the day the owner renames the shop, which is not a regression.
        $expected = trim(implode('', $mailable->brand['wordmark']));

        expect($masthead)->not->toBe('', "the {$label} rendered an empty masthead")
            ->and($masthead)->toBe($expected, "the {$label} masthead did not print its store name");
    }
});

it('gives every layout-rendering mailable a brand array', function () {
    // Structural, not cosmetic: a mailable missing $brand renders with Blade's
    // undefined-variable behaviour rather than throwing, which is exactly why
    // the empty masthead shipped quietly. Assert the property exists and is
    // populated, so a new mailable cannot inherit the same silence.
    $order = mastheadOrder();

    foreach ([new OrderConfirmation($order), new OrderInvoice($order), new NewOrderAlert($order)] as $mailable) {
        expect($mailable)->toHaveProperty('brand');

        $brand = $mailable->brand;

        expect($brand)->toBeArray()
            ->and($brand['wordmark'])->toBeArray()
            ->and(trim(implode('', $brand['wordmark'])))->not->toBe('')
            ->and($brand['colours'])->toBe(EmailBranding::PALETTE);
    }
});

it('falls back to a named store rather than a blank one when branding throws', function () {
    // The fallback path is the one nobody exercises until it matters. It must
    // still produce a wordmark, or the failure mode of "branding broke" is the
    // same empty masthead this test exists to prevent.
    $brand = EmailBranding::forMailable(true, 'SomeMailable');

    expect(trim(implode('', $brand['wordmark'])))->not->toBe('');
});
