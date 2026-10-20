<?php

declare(strict_types=1);

/**
 * 2.60.562 — "Ask for UAE number" beside the phone on the order screen.
 *
 * The owner, on order #56272 (+20507272773, a UAE number typed after Egypt's
 * code): "we want one button / option to require the local UAE's number from
 * the customer, so the delivery customer can reach out to deliver the order
 * ... currently we do email manual, but i want it auto upon just 1 click", and
 * "the system will also show that the missing contact number email is already
 * sent, with date and time ... so i can be sure by not sending the emails
 * twice." Before this there was no button: the team wrote each email by hand,
 * and nothing on the order said whether anyone already had.
 */

use App\Http\Controllers\Admin\OrderPhoneRequestController;
use App\Mail\OrderPhoneRequest;
use App\Models\AdminUser;
use App\Models\Order;
use App\Support\OrderLinks;
use App\Support\OrderLocale;
use App\Support\UaePhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ArabicShop;

afterEach(function () {
    Carbon::setTestNow();
});

function uaeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'Rafi ' . ucfirst($role), 'email' => 'pr-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function uaeOrder(array $o = []): Order
{
    return Order::create(array_merge([
        'order_number' => (string) random_int(56000, 59999) . random_int(10, 99),
        'email' => 'moza@example.com',
        'phone' => '+20507272773',
        'status' => 'processing',
        'currency' => 'AED',
        'locale' => 'en',
        'billing_address' => ['first_name' => 'moza', 'last_name' => 'alketbi', 'line1' => 'Street 1', 'city' => 'Sharjah', 'country' => 'AE', 'phone' => '+20507272773'],
        'shipping_address' => ['first_name' => 'moza', 'last_name' => 'alketbi', 'line1' => 'Street 1', 'city' => 'Sharjah', 'country' => 'AE', 'phone' => '+20507272773'],
        'subtotal' => 13000, 'discount_total' => 0, 'shipping_total' => 2000, 'fee_total' => 1000, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 16000,
        'shipping_method' => 'Delivery Charges', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ], $o));
}

function uaeSend(Order $order, bool $again = false, string $role = 'owner')
{
    return test()->actingAs(uaeAdmin($role), 'admin')->postJson('/admin-api/orders/' . $order->id . '/phone-request', ['again' => $again]);
}

function uaeLinkQuery(Order $order): string
{
    return (string) parse_url(OrderLinks::phoneUrl($order), PHP_URL_QUERY);
}

/* ------------------------------------------------------------ the number -- */

it('knows a UAE mobile however it is written, and nothing else', function (string $typed, ?string $e164) {
    // DEFECT: +20507272773 (Egypt's code before a UAE number) counted as UAE,
    // so the order screen never flagged the very order that started this.
    expect(UaePhone::e164($typed))->toBe($e164);
})->with([
    ['050 727 2773', '+971507272773'], ['0507272773', '+971507272773'], ['507272773', '+971507272773'],
    ['+971 50 727 2773', '+971507272773'], ['00971-50-727-2773', '+971507272773'], ['971 (58) 505 2611', '+971585052611'],
    ['+20507272773', null], ['04 123 4567', null], ['0511234567', null], ['050727277', null], ['', null], ['abc0507272773', null],
]);

/* ------------------------------------------------------- the order screen -- */

it('puts the button and the "Not a UAE mobile" tag beside the phone, from the detail payload', function () {
    // DEFECT: the console drawing the button with nothing to say whether the
    // number is a UAE one, or whether the email already went.
    $order = uaeOrder();
    $detail = test()->actingAs(uaeAdmin(), 'admin')->getJson('/admin-api/orders/' . $order->id . '/detail')->assertOk()->json();

    expect($detail['phone_request'])->toMatchArray(['uae' => false, 'has_email' => true, 'sent_label' => '', 'can' => true]);

    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($console, "+'</a>':'—')+odPhoneReq(o)+'</div>'+"))->toBe(1)
        ->and(substr_count($console, 'odPhoneReqWire(o);'))->toBe(1)
        ->and($console)->toContain('&#9888; Not a UAE mobile')
        ->and($console)->toContain("UAE number email sent · '+sesc(pr.sent_label)")
        ->and($console)->toContain("if(document.getElementById('odPhoneReq')) renderOrderDetail(o.id);");
});

it('is mounted once, for the order screen and for the customer', function () {
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/order-phone-request-admin.php';"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/order-phone.php';"))->toBe(1);
});

it('sends one email, records the date and time on the order, and says so on the screen', function () {
    // DEFECT: the email going but nothing on the order saying it had, so a
    // second person on the team sends it again.
    Mail::fake();
    Carbon::setTestNow('2026-10-20 09:52:00');
    $order = uaeOrder();

    $r = uaeSend($order)->assertOk();

    Mail::assertSent(OrderPhoneRequest::class, 1);
    Mail::assertSent(OrderPhoneRequest::class, fn ($m) => $m->hasTo('moza@example.com'));
    expect($order->fresh()->phone_request_sent_at?->toDateTimeString())->toBe('2026-10-20 09:52:00')
        ->and($r->json('panel.sent_label'))->not->toBe('')
        ->and((string) $order->notes()->latest('id')->value('content'))->toBe('UAE number requested from the customer by email (English).');
});

it('never sends twice by accident: a repeat must say so, and nothing goes within ten minutes', function () {
    // DEFECT: a second tab, or a double click, sending the customer two emails.
    // MUTATION: drop the `again` check and the second press sends -- red.
    // Drop the conditional claim and the reminder inside ten minutes sends -- red.
    Mail::fake();
    Carbon::setTestNow('2026-10-20 09:52:00');
    $order = uaeOrder();
    uaeSend($order)->assertOk();

    uaeSend($order)->assertStatus(409)->assertJsonPath('code', 'already_sent');

    Carbon::setTestNow('2026-10-20 09:58:00');
    uaeSend($order, true)->assertStatus(409)->assertJsonPath('code', 'recent');
    Mail::assertSent(OrderPhoneRequest::class, 1);
    expect($order->fresh()->phone_request_sent_at?->toDateTimeString())->toBe('2026-10-20 09:52:00');

    Carbon::setTestNow('2026-10-20 10:03:00');
    uaeSend($order, true)->assertOk();
    Mail::assertSent(OrderPhoneRequest::class, 2);
    expect($order->fresh()->phone_request_sent_at?->toDateTimeString())->toBe('2026-10-20 10:03:00')
        ->and((string) $order->notes()->latest('id')->value('content'))->toStartWith('UAE number reminder sent');
});

it('leaves no "sent" mark when nothing was sent', function () {
    // DEFECT: the time claimed before sending and kept after a failure, so the
    // screen says "sent" for an email that never went.
    Mail::fake();
    $order = uaeOrder(['email' => '']);

    uaeSend($order)->assertStatus(422);
    Mail::assertNothingSent();
    expect($order->fresh()->phone_request_sent_at)->toBeNull();
});

it('is held by the same capability as Resend confirmation, so every role that sends one sends the other', function () {
    // DEFECT (2.60.562): a capability of its own, which an account whose
    // access was saved before the patch never held -- the button did not draw.
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/orders/5/phone-request'))
        ->toBe(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/orders/5/action'));

    Mail::fake();
    uaeSend(uaeOrder(), false, 'support')->assertOk();
    Mail::assertSent(OrderPhoneRequest::class, 1);
});

it('runs the action picked in Order actions when Update is pressed, not only from the arrow', function () {
    // DEFECT: the owner picked "Resend confirmation email" and pressed Update,
    // as WooCommerce has it; Update saved the status and sent nothing, and the
    // mail log had no attempt at all.
    // MUTATION: drop the `picked` block from the odUpdate handler -- red.
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $update = substr($console, (int) strpos($console, "document.getElementById('odUpdate').onclick"), 900);

    expect($update)->toContain("var picked = (document.getElementById('odActionSel')||{}).value || '';")
        ->toContain('await odRunActionFn(picked)')
        ->and(substr_count($console, 'odRunActionFn = odRunAction;'))->toBe(1);
});

it('is refused to a role without it, and the rule fails closed', function () {
    Mail::fake();
    uaeSend(uaeOrder(), false, 'editor')->assertStatus(403);
    Mail::assertNothingSent();

    expect(OrderPhoneRequestController::MIN_GAP_MINUTES)->toBe(10);
});

/* ---------------------------------------------------------------- the email -- */

it('is the approved Option H in English: the soft line, the struck number, the green one, one button', function () {
    $order = uaeOrder();
    $html = (string) OrderLocale::render($order, fn () => (new OrderPhoneRequest($order))->render());

    expect($html)->toContain('Your UAE phone number is missing')
        ->toContain('Update your number in one tap')
        ->toContain('Hi Moza, thank you for your order!')
        ->toContain('the one on your order is from another country')
        ->toContain('text-decoration:line-through')
        ->toContain('+20507272773')
        ->toContain('#2E7D4F')
        ->toContain('Update my number')
        ->toContain('/order-phone?order=' . $order->order_number . '&amp;t=')
        ->not->toContain('YOUR UAE PHONE NUMBER');

    // A UAE-looking number that turned out wrong is never called foreign.
    $uae = uaeOrder(['phone' => '0501112233']);
    expect((string) OrderLocale::render($uae, fn () => (new OrderPhoneRequest($uae))->render()))
        ->toContain('we couldn&#039;t reach you on the number on your order')
        ->not->toContain('from another country');
});

it('goes in Arabic, right to left, with an /ar/ link, for an order placed in Arabic', function () {
    // DEFECT: an Arabic customer getting the English email, or a link that
    // opens the English page.
    ArabicShop::on();
    $order = uaeOrder(['locale' => 'ar']);
    $html = (string) OrderLocale::render($order, fn () => (new OrderPhoneRequest($order))->render());

    expect($html)->toContain('dir="rtl"')
        ->toContain('رقم هاتفك الإماراتي غير موجود')
        ->toContain('تحديث رقمي')
        ->toContain('/ar/order-phone?order=');
});

/* --------------------------------------------------------- the customer page -- */

it('opens only with its own signed link, and says the same for every bad one', function () {
    $order = uaeOrder();
    $q = uaeLinkQuery($order);

    $this->get('/order-phone?' . $q)->assertOk()->assertSee('Where can our courier reach you?')
        ->assertSee('+971', false)->assertDontSee('+20507272773')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $other = uaeOrder();
    parse_str($q, $p);
    $this->get('/order-phone?order=' . $other->order_number . '&t=' . $p['t'])->assertNotFound();
    $this->get('/order-phone?order=' . $order->order_number . '&t=1.forged')->assertNotFound();
    $pay = (string) parse_url(OrderLinks::payUrl($order), PHP_URL_QUERY);
    $this->get('/order-phone?' . $pay)->assertNotFound();
});

it('saves a UAE number on the order, its billing and shipping, and keeps the old one in a note', function () {
    // DEFECT: the courier's label reading the shipping phone, still the old one.
    $order = uaeOrder();
    $q = uaeLinkQuery($order);

    $this->post('/order-phone?' . $q, ['phone' => '50 727 2773'])->assertRedirect();

    $fresh = $order->fresh();
    expect($fresh->phone)->toBe('+971507272773')
        ->and($fresh->billing_address['phone'])->toBe('+971507272773')
        ->and($fresh->shipping_address['phone'])->toBe('+971507272773')
        ->and($fresh->billing_address['city'])->toBe('Sharjah')
        ->and($fresh->phone_updated_at)->not->toBeNull()
        ->and((string) $fresh->notes()->latest('id')->value('content'))->toBe('Customer added UAE mobile +971 50 727 2773 (was +20507272773) through the "Update my number" link.');

    $this->get('/order-phone?' . $q . '&saved=1')->assertOk()->assertSee('Thank you, Moza!')->assertSee('+971 50 727 2773');
});

it('refuses a number that is not a UAE mobile, and an order already delivered', function () {
    $order = uaeOrder();
    $q = uaeLinkQuery($order);

    $this->post('/order-phone?' . $q, ['phone' => '+20507272773'])->assertStatus(422)->assertSee('Please enter a UAE mobile number');
    expect($order->fresh()->phone)->toBe('+20507272773');

    $done = uaeOrder(['status' => 'completed']);
    $this->post('/order-phone?' . uaeLinkQuery($done), ['phone' => '0507272773'])->assertStatus(422);
    expect($done->fresh()->phone)->toBe('+20507272773');
});
