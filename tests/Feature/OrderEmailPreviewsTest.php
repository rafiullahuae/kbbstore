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
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;

/**
 * The store, as the owner will have configured it.
 *
 * WHY THE PREVIEWS ARE SEEDED AND NOT LEFT BLANK. The support block and the
 * signature only print where the store has values for them, which is right —
 * a receipt advertising a WhatsApp number nobody answers is worse than no
 * block at all. But it means an unseeded preview shows an email with the two
 * new blocks missing, and the owner would be reviewing a page that is not the
 * one their customers will receive.
 *
 * These are the store's own published contact details, the same ones the
 * storefront header and footer already carry as their defaults. They are a
 * fixture, here, and nowhere near the templates: EmailBranding reads every one
 * of them from settings and there is no hardcoded number or handle anywhere in
 * app/ or resources/views/emails/.
 *
 * `org_logo` is deliberately NOT set. The logo switch (Store → Modules → Order
 * emails → "Logo in order emails") is on by default, but this store has no
 * logo image saved, so what the masthead really prints today is the two-part
 * wordmark from Header settings — and that is what the owner should be looking
 * at. EmailBrandingTest covers the image path.
 */
function previewStore(): void
{
    $settings = app(SettingsService::class);

    $settings->set('store_name', 'K Beauty Bliss');
    $settings->set('brand_whatsapp', '+971 58 505 2611');
    $settings->set('social_instagram', 'kbeauty.bliss');

    app(MailSettings::class)->save([
        'mail_from_address' => 'hello@kbeautybliss.com',
        'mail_from_name' => 'K Beauty Bliss',
        'mail_signature' => 'With love,|the K Beauty Bliss team',
    ]);

    Setting::flushMap();
    SettingsService::forgetMemo();
}

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
    previewStore();

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

        $customerFacing = $name !== 'new-order-alert';

        expect($html)->toContain('KBB-10427')
            /*
             * AED 473, from 47300 fils, and the decimals are GONE ON PURPOSE
             * — Lane FA.
             *
             * This used to assert "473.00": a receipt may not round, so every
             * emailed figure printed at the currency's full precision. The
             * principle is unchanged and OrderEmailPresenter still states it.
             * What changed is that this preview order is whole dirhams in
             * every column, and on a whole-dirham order "AED 473.00" and
             * "AED 473" state the same money — so the wide form was no longer
             * buying truth, only decimals on a shop whose owner asked for
             * none ("no decimals. if any decimals comes. adjust to the
             * price").
             *
             * The safety net is asserted rather than assumed: the second
             * expectation below renders the SAME email for an order carrying
             * fils and requires the full precision back. A change that simply
             * rounded receipts would pass the first and fail the second.
             */
            ->and($html)->toContain('473')
            ->and(str_contains($html, '473.00'))->toBeFalse()
            // Blade escaped the customer's own words rather than running them.
            ->and($html)->not->toContain('<script');

        // Quantity as its own column, in every email that lists what was
        // bought. The preview order has a line of 2 and a line of 1, so a
        // template that printed the wrong cell would show 1 twice.
        expect($html)->toContain('>Qty<')
            ->and($html)->toContain('each');

        // The support block and the signature: on for the customer, off for the
        // merchant's own alert. Asserted both ways round, because a block that
        // is always shown is not a decision.
        if ($customerFacing) {
            expect($html)->toContain('We are here if you need us')
                ->and($html)->toContain('wa.me/97158505261')
                ->and($html)->toContain('instagram.com/kbeauty.bliss')
                ->and($html)->toContain('hello@kbeautybliss.com')
                ->and($html)->toContain('the K Beauty Bliss team');
        } else {
            expect($html)->not->toContain('We are here if you need us')
                ->and($html)->not->toContain('wa.me/');
        }

        $written[] = previewWrite($name . '.html', $html);

        $content = $mailable->content();

        $text = (string) view($content->text, array_merge(
            $mailable->buildViewData(),
            $content->with,
        ))->render();

        expect($text)->toContain('KBB-10427')
            // No markup leaked into the text/plain part.
            ->and($text)->not->toContain('<span')
            ->and($text)->not->toContain('<div')
            // The text twin names all three figures the HTML columns carry.
            ->and($text)->toContain('QTY 2')
            ->and($text)->toContain('each');

        if ($customerFacing) {
            expect($text)->toContain('WE ARE HERE IF YOU NEED US')
                ->and($text)->toContain('the K Beauty Bliss team');
        }

        $written[] = previewWrite($name . '.txt', $text);
    }

    /*
     * THE OTHER HALF OF THE WIDTH RULE, ASSERTED AND NOT ASSUMED — Lane FA.
     *
     * The receipt above prints whole dirhams because every figure on that
     * order is one. An order carrying fils — one placed before the policy, or
     * one whose tax genuinely cannot be made whole — must still print at full
     * precision, as a whole column, so that the figures still sum. That is the
     * safety net Lane EZ put up and this lane keeps; a change that simply
     * rounded receipts would pass every assertion above and fail here.
     *
     * The same order, moved 40 fils, rendered through the same mailable.
     */
    $order->forceFill([
        'subtotal' => 46340,
        'total' => 46340 - 4000 + 2000 + 3000,
    ])->save();

    $legacy = (string) (new OrderConfirmation($order->fresh()))->render();

    expect($legacy)->toContain('473.40')
        ->and($legacy)->toContain('463.40')
        /*
         * And the column still adds up at that width, every row printed
         * exactly and none of them rounded:
         *
         *   Subtotal        463.40
         *   Discount       − 40.00
         *   Delivery         20.00
         *   Gift wrapping    15.00
         *   COD fee          15.00
         *   Total           473.40
         */
        ->and($legacy)->toContain('40.00')
        ->and($legacy)->toContain('20.00')
        ->and($legacy)->toContain('15.00');

    // Ten files: five emails, each with its HTML and its text part.
    expect($written)->toHaveCount(10);

    foreach ($written as $path) {
        expect(is_file($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(200);
    }
});
