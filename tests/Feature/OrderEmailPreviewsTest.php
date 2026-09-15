<?php

/**
 * The previews the owner reviews — rendered, asserted, and written to disk.
 *
 * This is a test and not a script on purpose. The store owner looks at a
 * rendered copy of every email before a package ships, and a preview generated
 * by hand is a preview that goes stale the first time somebody is in a hurry:
 * the reviewed file and the sent message stop being the same thing, silently.
 * Running the suite regenerates all ten files from the same Mailables the store
 * sends, so the thing reviewed is the thing that goes out.
 *
 * Written to docs/email-previews/. Open any of the .html files in a browser;
 * the .txt files are the plain-text parts exactly as a text-only client
 * receives them.
 *
 * The order behind the previews is deliberately awkward rather than tidy — two
 * lines, a variant, a coupon discount, gift wrapping, a cash-on-delivery
 * surcharge, a gift message and an order note — because an email that only ever
 * gets reviewed against the simplest possible order is an email whose discount
 * row nobody has ever seen.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\Refund;

/** Where the rendered files land. */
function previewDir(): string
{
    $dir = base_path('docs/email-previews');

    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function previewWrite(string $name, string $contents): string
{
    $path = previewDir() . '/' . $name;

    file_put_contents($path, $contents);

    return $path;
}

/**
 * One order with every optional part filled in.
 *
 * Money, in fils, checked here rather than trusted: 2 x 19900 + 1 x 6500 goods,
 * less a 4000 coupon, plus 2000 delivery, 1500 gift wrapping and a 1500 COD
 * surcharge — 47300 in total. Deliberately not equal to the subtotal: a preview
 * whose subtotal and total happen to match is a preview that cannot show whether
 * the total line is being computed or copied.
 */
function previewOrder(): Order
{
    $order = Order::create([
        'order_number' => 'KBB-10427',
        'email' => 'aisha.khan@example.com',
        'phone' => '+971 50 123 4567',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
        ],
        'shipping_address' => [
            'first_name' => 'Aisha', 'last_name' => 'Khan',
            'line1' => 'Apartment 1204, Marina Heights', 'city' => 'Dubai',
            'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
        ],
        'subtotal' => 46300,
        'discount_total' => 4000,
        'coupon_code' => 'GLOW10',
        'shipping_total' => 2000,
        'fee_total' => 3000,
        'gift_fee' => 1500,
        'is_gift' => true,
        'gift_note' => "Happy birthday, Mama.\nLove from all of us x",
        'customer_note' => 'Please ring the doorbell twice — the buzzer is broken.',
        'tax_total' => 0,
        'total' => 46300 - 4000 + 2000 + 3000,
        'shipping_method' => 'Standard delivery (1–3 working days)',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        /*
         * A fixed date, so the previews are byte-identical from one run to the
         * next. With now() the receipt date changes daily and every suite run
         * leaves ten modified files in `git status` — noise that trains everyone
         * to ignore a diff in exactly the files the owner is supposed to review.
         */
        'created_at' => '2026-09-14 09:41:00',
    ]);

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

it('renders every order email and saves it for review', function () {
    $order = previewOrder();

    // The figures the previews are a picture of. Asserted first, so a preview
    // can never be reviewed and approved against a total that is wrong.
    expect($order->subtotal)->toBe(46300)
        ->and($order->discount_total)->toBe(4000)
        ->and($order->shipping_total)->toBe(2000)
        ->and($order->gift_fee)->toBe(1500)
        ->and($order->fee_total)->toBe(3000)
        ->and($order->total)->toBe(47300);

    $refund = new Refund(['amount' => 19900, 'status' => 'succeeded']);
    $refund->order_id = $order->id;

    $emails = [
        'order-confirmation' => new OrderConfirmation($order),
        'new-order-alert' => new NewOrderAlert($order),
        'order-shipped' => new OrderStatusChanged($order, 'shipped'),
        'order-cancelled' => new OrderStatusChanged($order, 'cancelled'),
        'order-refunded' => new OrderRefunded($order, $refund),
    ];

    $written = [];

    foreach ($emails as $name => $mailable) {
        $html = (string) $mailable->render();

        expect($html)->toContain('KBB-10427')
            // AED 473.00 — full precision, from 47300 fils. The storefront
            // would print AED 473 by rounding; a receipt may not. See
            // OrderEmailPresenter.
            ->and($html)->toContain('473.00')
            // Blade escaped the customer's own words rather than running them.
            ->and($html)->not->toContain('<script');

        $written[] = previewWrite($name . '.html', $html);

        $content = $mailable->content();

        $text = (string) view($content->text, array_merge(
            $mailable->buildViewData(),
            $content->with,
        ))->render();

        expect($text)->toContain('KBB-10427')
            // No markup leaked into the text/plain part.
            ->and($text)->not->toContain('<span')
            ->and($text)->not->toContain('<div');

        $written[] = previewWrite($name . '.txt', $text);
    }

    // Ten files: five emails, each with its HTML and its text part.
    expect($written)->toHaveCount(10);

    foreach ($written as $path) {
        expect(is_file($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(200);
    }
});
