<?php

/**
 * "Payment not received" -- to the shop, 30 minutes after an order that is
 * still unpaid. (17 October 2026)
 *
 * What it looked like on the shop: order 56251 (Pay Later with Tamara) was
 * never paid and Tamara had no record of it, but the owner's inbox only ever
 * had "New order 56251 — AED 563", sent at placement. Nothing told him the
 * payment had not gone through; he had to open the order to see "Not paid".
 * The owner: "i only need to send pending payment email to admin as well. so
 * i can be sure that payment is failed."
 *
 * MUTATION: drop the unpaidAlertStage() call from OrderReminders::sweep() and
 * the first test is red; drop 'merchant_unpaid' from OrderEmailLog::KINDS and
 * claim() refuses, so nothing is ever sent (red).
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderPaymentReminder;
use App\Mail\UnpaidOrderAlert;
use App\Models\Order;
use App\Services\Mail\MailSettings;
use App\Services\Mail\OrderReminders;
use App\Services\SettingsService;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailSettings::class)->save(['mail_merchant_address' => 'shop@example.test']);
});

afterEach(function () {
    Carbon::setTestNow();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function uoaOrder(array $over = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'KBB-UOA-' . uniqid(),
        'email' => 'buyer-' . uniqid() . '@example.com',
        'phone' => '+971500000000',
        'status' => 'pending',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khalil', 'line1' => 'Villa 21', 'city' => 'Abu Dhabi', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khalil', 'line1' => 'Villa 21', 'city' => 'Abu Dhabi', 'country' => 'AE'],
        'subtotal' => 56300, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 56300,
        'shipping_method' => 'Free delivery',
        'payment_method' => 'tamara',
        'payment_method_title' => 'Pay Later with Tamara',
    ], $over));

    $order->items()->create([
        'name' => 'Anua - PDRN Glass Skin Set', 'brand' => 'Anua', 'quantity' => 1, 'unit_price' => 56300,
        'subtotal' => 56300, 'total' => 56300,
    ]);

    return $order->fresh('items');
}

it('tells the shop "Payment not received" once, 30 minutes after an unpaid Tamara order, and notes it on the order', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-17 08:50:00');
    $order = uoaOrder();
    $sweep = fn () => app(OrderReminders::class)->sweep(10);

    Carbon::setTestNow('2026-10-17 09:19:00');
    $sweep();
    Mail::assertNotSent(UnpaidOrderAlert::class);

    Carbon::setTestNow('2026-10-17 09:20:00');
    $sweep();
    $sweep(); // claimed: a second sweep sends nothing more

    Mail::assertSent(UnpaidOrderAlert::class, 1);
    Mail::assertSent(UnpaidOrderAlert::class, fn (UnpaidOrderAlert $m) => $m->hasTo('shop@example.test'));
    Mail::assertSent(OrderPaymentReminder::class, 1); // the customer's own reminder is unchanged

    expect($order->notes()->where('content', 'like', '%Payment not received%')->count())->toBe(1)
        ->and(DB::table('order_emails')->where('order_id', $order->id)->where('kind', 'merchant_unpaid')->count())->toBe(1);
});

it('says "Payment not received" and "do not pack it", not "pick, pack and mark it dispatched"', function () {
    $mail = new UnpaidOrderAlert(uoaOrder(['order_number' => '56251']));

    expect($mail->envelope()->subject)->toBe('Payment not received — order 56251 — AED 563');

    $html = $mail->render();

    expect($html)->toContain('Payment not received')
        ->toContain('#C0392B')
        ->toContain('Still not paid 30 minutes after it was placed (Pay Later with Tamara).')
        ->not->toContain('pick, pack and mark it dispatched');

    // The new-order alert itself is byte-for-byte what it was: green, "New order".
    $plain = (new NewOrderAlert(uoaOrder(['order_number' => '56252'])))->render();
    expect($plain)->toContain('#2E9E6B')->toContain('pick, pack and mark it dispatched')->not->toContain('Payment not received');
});

it('sends nothing for a paid order, cash on delivery, or with its switch off -- and still sends with the customer reminder off', function () {
    Mail::fake();
    Carbon::setTestNow('2026-10-17 08:00:00');
    uoaOrder(['status' => 'processing', 'paid_at' => now()]);
    uoaOrder(['payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery']);

    Carbon::setTestNow('2026-10-17 09:00:00');
    app(OrderReminders::class)->sweep(10);
    Mail::assertNotSent(UnpaidOrderAlert::class);

    app(SettingsService::class)->setModule('email_merchant_unpaid', false);
    app(SettingsService::class)->setModule('email_order_reminder_1', false);
    uoaOrder(['created_at' => now()->subMinutes(40)]);
    app(OrderReminders::class)->sweep(10);
    Mail::assertNotSent(UnpaidOrderAlert::class);

    // Its own switch: the customer's reminder off does not silence the shop.
    app(SettingsService::class)->setModule('email_merchant_unpaid', true);
    app(OrderReminders::class)->sweep(10);
    Mail::assertSent(UnpaidOrderAlert::class, 1);
    Mail::assertNotSent(OrderPaymentReminder::class);
});
