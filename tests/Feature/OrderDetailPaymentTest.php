<?php

declare(strict_types=1);

/*
 * Lane PU — Store → Orders → (an order).
 *
 * The owner's report, on Order #33415 (Cash on delivery, Processing, imported
 * from WooCommerce):
 *
 *   1. "I can not edit the address and customer info" — Billing "Edit" was an
 *      <a href="#"> with no handler; Shipping "Edit" opened a raw JSON textarea
 *      whose endpoint stored whatever array it was given, unvalidated and with
 *      no record of who changed what.
 *   2. "Order history ... goes to all customers page" — go('customers').
 *   3. "a big green box ... 'Paid with <payment method name>'".
 *   4. "remove the Capture function" — "Not captured … Capture AED X" was drawn
 *      on every COD order, delivered or not, and on Stripe orders Stripe had
 *      already captured.
 *   5. "Mark as paid" with a manual reference when an unpaid order moves to a
 *      paid status.
 *
 * Every case says what the defect looked like and how to make it red again.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Refund;
use App\Support\AdminCapabilities;
use App\Support\OrderPaymentPanel;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderDetailAdminRoutes;

/* ------------------------------------------------------------------ fixtures */

function puAdmin(string $role = 'owner'): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'PU ' . ucfirst($role),
        'email' => 'pu-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

function puCustomer(?string $email = null): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(['name' => 'Aisha Khan ' . $n, 'email' => $email ?? 'pu-c-' . $n . '@example.test']);
}

/** An order shaped the way OrderImporter writes one from WooCommerce HPOS. */
function puOrder(array $attributes = []): Order
{
    static $n = 33400;
    $n++;

    $at = $attributes['at'] ?? null;
    unset($attributes['at']);

    $address = [
        'first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Gate 2', 'line2' => 'Apt 1204',
        'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567',
    ];

    $order = Order::create(array_merge([
        'wc_order_id' => $n,
        'order_number' => (string) $n,
        'email' => 'pu-guest-' . $n . '@example.test',
        'phone' => '+971501234567',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 31700,
        'shipping_total' => 2000,
        'total' => 33700,
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'billing_address' => $address,
        'shipping_address' => $address,
        'origin' => 'woocommerce-import',
    ], $attributes));

    if ($at !== null) {
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        $order->refresh();
    }

    return $order;
}

function puConsole(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** The body of one console function, from its declaration to the next one. */
function puFn(string $name): string
{
    $src = puConsole();
    $start = strpos($src, '  function ' . $name . '(');
    expect($start)->not->toBeFalse("the console no longer defines {$name}()");
    $end = strpos($src, "\n  function ", $start + 10);

    return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
}

beforeEach(function () {
    OrderDetailAdminRoutes::wire($this->app);
});

/* =============================================== 1. Billing / Shipping edit */

it('ROOT CAUSE: gives Billing and Shipping "Edit" a handler, not a dead href="#" or a JSON textarea', function () {
    /*
     * THE DEFECT, reproduced in Chromium on an imported-shape #33415 before
     * this lane: clicking Billing "Edit" changed location.hash from "#orders"
     * to "" and nothing else — the markup was `BILLING <a href="#">Edit</a>`
     * and wireOrderDetail() had no line that referred to it. Shipping "Edit"
     * toggled #odShipJson, a monospace textarea of the raw snapshot.
     *
     * MUTATION: put `'<div class="odcollabel">BILLING <a href="#">Edit</a></div>'`
     * back in odOverviewAddressesCard -> red on the first expectation.
     */
    $card = puFn('odOverviewAddressesCard');
    $wire = puFn('wireOrderDetail');

    expect($card)->not->toContain('BILLING <a href="#">Edit</a>')
        ->and($card)->toContain("editBtn('billing')")
        ->and($card)->toContain("editBtn('shipping')")
        ->and($card)->toContain('data-odedit="')
        ->and(puConsole())->not->toContain('odShipJson')
        ->and($wire)->toContain("querySelectorAll('#content [data-odedit]')")
        ->and($wire)->toContain('odAddressModal(o, b.dataset.odedit)');
});

it('reads the address keys every writer stores, so the shipping name and emirate are no longer blank', function () {
    /*
     * The screen printed `a.name` and `a.emirate`. The checkout, New Order and
     * the WooCommerce import all write first_name / last_name / state, so the
     * shipping name was blank on every order. MUTATION: make odAddrName()
     * return `a.name` only -> red.
     */
    $name = puFn('odAddrName');
    $lines = puFn('odAddrLines');

    expect($name)->toContain('a.first_name, a.last_name')
        ->and($lines)->toContain('a.state')
        ->and($lines)->toContain('a.company')
        ->and($lines)->toContain('a.postcode')
        ->and($lines)->toContain('.map(sesc)');
});

it('refuses the unvalidated array the address endpoint used to store verbatim', function () {
    /*
     * Before: `'address' => ['required', 'array']` and the array written as
     * it came. A browser could store a 50 KB blob, nested arrays, or markup
     * that every invoice and label prints. MUTATION: restore that validation
     * in AdminOrderController::updateAddress -> 200 and the junk stored.
     */
    puAdmin();
    $order = puOrder();

    $this->putJson("/admin-api/orders/{$order->id}/address", [
        'type' => 'shipping',
        'address' => ['city' => '<script>alert(1)</script>', 'line1' => str_repeat('x', 500), 'country' => 'ZZ'],
    ])->assertStatus(422)->assertJsonValidationErrors(['address.city', 'address.line1', 'address.country']);

    expect($order->fresh()->shipping_address['city'])->toBe('Dubai');
});

it('saves an edited billing address, email and phone, and notes what changed and who changed it', function () {
    $admin = puAdmin('manager');
    $order = puOrder(['billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Gate 2',
        'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971501234567', 'legacy_key' => 'kept']]);

    $this->putJson("/admin-api/orders/{$order->id}/address", [
        'type' => 'billing',
        'address' => [
            'first_name' => ' Aisha ', 'last_name' => 'Khan', 'company' => 'Bliss Trading LLC',
            'line1' => 'Marina Gate 2', 'line2' => '', 'city' => 'Sharjah', 'state' => 'Sharjah',
            'postcode' => '', 'country' => 'ae', 'phone' => '+971 55 000 1111',
            'evil' => 'ignored',
        ],
        'email' => 'Aisha.New@Example.test',
    ])->assertOk()->assertJson(['ok' => true, 'changed' => 5]);

    $fresh = $order->fresh();

    expect($fresh->billing_address)->toBe([
        'first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Gate 2',
        'city' => 'Sharjah', 'state' => 'Sharjah', 'country' => 'AE', 'phone' => '+971 55 000 1111',
        'legacy_key' => 'kept', 'company' => 'Bliss Trading LLC',
    ])
        ->and($fresh->email)->toBe('aisha.new@example.test')
        ->and($fresh->phone)->toBe('+971 55 000 1111')
        // Shipping untouched.
        ->and($fresh->shipping_address['city'])->toBe('Dubai');

    $note = $fresh->notes()->first();
    expect($note->author)->toBe($admin->name)
        ->and($note->content)->toContain('Billing details edited by ' . $admin->name)
        ->and($note->content)->toContain('City: Dubai → Sharjah')
        ->and($note->content)->toContain('Company: (blank) → Bliss Trading LLC')
        ->and($note->content)->toContain('Email: ');
});

it('writes nothing and no note when nothing changed', function () {
    puAdmin();
    $order = puOrder();

    $this->putJson("/admin-api/orders/{$order->id}/address", [
        'type' => 'shipping',
        'address' => $order->shipping_address,
    ])->assertOk()->assertJson(['changed' => 0]);

    expect($order->notes()->count())->toBe(0);
});

it('changes and clears the order customer, with a note, and refuses a customer that does not exist', function () {
    puAdmin();
    $was = puCustomer();
    $now = puCustomer();
    $order = puOrder(['customer_id' => $was->id, 'email' => $was->email]);

    $this->putJson("/admin-api/orders/{$order->id}/customer", ['customer_id' => $now->id])->assertOk();
    expect($order->fresh()->customer_id)->toBe($now->id)
        // The email is the billing editor's business, not this one's.
        ->and($order->fresh()->email)->toBe($was->email)
        ->and($order->notes()->pluck('content')->implode("\n"))->toContain('Customer changed by')
        ->and($order->notes()->pluck('content')->implode("\n"))->toContain('#' . $now->id);

    $this->putJson("/admin-api/orders/{$order->id}/customer", ['customer_id' => 999999])->assertStatus(422);
    $this->putJson("/admin-api/orders/{$order->id}/customer", ['customer_id' => null])->assertOk();
    expect($order->fresh()->customer_id)->toBeNull()
        ->and($order->notes()->pluck('content')->implode("\n"))->toContain('→ guest checkout');
});

it('searches customers by name or email, escaping LIKE wildcards, and returns only id, name and email', function () {
    puAdmin();
    puCustomer('needle@example.test');
    puCustomer('other@example.test');

    $rows = $this->getJson('/admin-api/order-customer-search?search=needle')->assertOk()->json('customers');
    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toBe(['id', 'name', 'email']);

    // "%" is a literal here, not "match everything".
    expect($this->getJson('/admin-api/order-customer-search?search=%25%25')->json('customers'))->toBe([]);
});

/* ============================================== 2. Order history popup */

it('opens THIS customer\'s order history in a popup instead of navigating to all customers', function () {
    /*
     * Before: `custHist.onclick = function(e){ e.preventDefault(); go('customers'); }`
     * — measured in Chromium, the click landed on the Customers screen.
     * MUTATION: put go('customers') back -> red.
     */
    $wire = puFn('wireOrderDetail');

    expect($wire)->not->toContain("go('customers')")
        ->and($wire)->toContain('odHistoryModal(o, 1)');
});

it('lists the customer\'s orders newest first, ten a page, with the Customer history card\'s own totals', function () {
    puAdmin();
    $c = puCustomer();
    $stranger = puCustomer();

    $orders = [];
    for ($i = 0; $i < 12; $i++) {
        $o = puOrder(['customer_id' => $c->id, 'email' => $c->email, 'status' => $i === 3 ? 'cancelled' : 'completed',
            'total' => 10000 + $i * 100, 'at' => now()->subDays(30 - $i)]);
        $o->items()->create(['name' => 'Toner', 'quantity' => 2, 'unit_price' => 100, 'subtotal' => 200, 'total' => 200]);
        $o->items()->create(['name' => 'Serum', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100]);
        $orders[] = $o;
    }
    puOrder(['customer_id' => $stranger->id, 'email' => $stranger->email]);

    $current = end($orders);
    $page1 = $this->getJson("/admin-api/orders/{$current->id}/customer-orders")->assertOk()->json();
    $card = $this->getJson("/admin-api/orders/{$current->id}/detail")->assertOk()->json('customer_history');

    expect($page1['orders'])->toHaveCount(10)
        ->and($page1['total'])->toBe(12)
        ->and($page1['last_page'])->toBe(2)
        ->and($page1['orders'][0]['id'])->toBe($current->id)
        ->and($page1['orders'][0]['current'])->toBeTrue()
        ->and($page1['orders'][0]['items'])->toBe(3)
        ->and($page1['summary'])->toBe($card)
        // Allowlisted: nothing of the customer row beyond name and email.
        ->and(array_keys($page1['customer']))->toBe(['id', 'name', 'email', 'guest'])
        ->and(array_keys($page1['orders'][0]))->toBe(['id', 'order_number', 'created_at', 'date_label', 'status', 'items', 'total_aed', 'method', 'trashed', 'current']);

    $dates = array_column($page1['orders'], 'created_at');
    $sorted = $dates;
    rsort($sorted);
    expect($dates)->toBe($sorted);

    $page2 = $this->getJson("/admin-api/orders/{$current->id}/customer-orders?page=2")->assertOk()->json();
    expect($page2['orders'])->toHaveCount(2)
        ->and(collect($page1['orders'])->pluck('id')->intersect(collect($page2['orders'])->pluck('id')))->toBeEmpty();
});

it('matches a guest by email, the way the Customer history card does', function () {
    puAdmin();
    $a = puOrder(['customer_id' => null, 'email' => 'guest.buyer@example.test']);
    puOrder(['customer_id' => null, 'email' => 'guest.buyer@example.test']);
    puOrder(['customer_id' => null, 'email' => 'someone.else@example.test']);

    $json = $this->getJson("/admin-api/orders/{$a->id}/customer-orders")->assertOk()->json();

    expect($json['total'])->toBe(2)
        ->and($json['customer']['guest'])->toBeTrue()
        ->and($json['customer']['email'])->toBe('guest.buyer@example.test');

    // And the link is drawn for a guest, not only for a registered customer.
    expect(puFn('odOverviewAddressesCard'))->toContain('(c.id || o.email)');
});

it('escapes every value the history popup prints', function () {
    $m = puFn('odHistoryModal');

    expect($m)->toContain('sesc(r.order_number)')
        ->and($m)->toContain('sesc(r.date_label')
        ->and($m)->toContain('sesc(odStatusLabel(r.status))')
        ->and($m)->toContain('sesc(String(r.total_aed))')
        ->and($m)->toContain('sesc(who.name')
        // Ids are coerced to numbers before they reach an attribute.
        ->and($m)->toContain('data-odhist="\'+(+r.id)+\'"');
});

/* ============================================== 3. The payment panel */

it('shows a cash-on-delivery order in Processing as amber "to collect", although the import stamped date_paid', function () {
    /*
     * WooCommerce sets date_paid on a COD order when it reaches Processing,
     * so every imported COD order carries `paid_at` for cash still in the
     * customer's hand. MUTATION: in OrderPaymentPanel::for() make COD
     * "collected" when `paid_at !== null` -> this goes green and red here.
     */
    $order = puOrder(['paid_at' => now()->subDays(3)]);

    $p = OrderPaymentPanel::for($order, 0, ['capturable' => true]);

    expect($p['state'])->toBe('cod_due')
        ->and($p['tone'])->toBe('amber')
        ->and($p['headline'])->toBe('Cash on delivery — AED 337 to collect')
        ->and($p['to_collect_aed'])->toEqual(337)
        ->and($p['can_record_cash'])->toBeTrue()
        ->and($p['capture']['offered'])->toBeFalse();

    /*
     * And the grey sentence under the heading stops saying "Paid on 29 Sep"
     * directly above that amber box. MUTATION: put `(o.paid_at?' Paid on '`
     * back in renderOrderDetail -> red.
     */
    expect(puConsole())->toContain("(paidSaid?' Paid on '+fmtDT(o.paid_at)")
        ->and(puConsole())->not->toContain("(o.paid_at?' Paid on '+fmtDT(o.paid_at)");
});

it('turns cash on delivery green on Completed, and on a cash-received record', function () {
    $done = puOrder(['status' => 'completed', 'completed_at' => '2026-09-20 10:00:00']);
    $recorded = puOrder(['status' => 'shipped', 'captured_at' => '2026-09-21 12:00:00', 'captured_total' => 33700, 'capture_ref' => 'cod:1']);

    $a = OrderPaymentPanel::for($done, 0, []);
    $b = OrderPaymentPanel::for($recorded, 0, []);

    expect($a['state'])->toBe('cod_collected')->and($a['tone'])->toBe('green')
        ->and($a['headline'])->toStartWith('Paid — cash collected on 20 September 2026')
        ->and($b['state'])->toBe('cod_collected')
        // The bookkeeping `cod:` reference is not shown as a capture id.
        ->and($b['capture_ref'])->toBeNull();
});

it('says "Paid with" the method on a paid gateway order, with its transaction id, date and refunds', function () {
    $order = puOrder(['payment_method' => 'stripe', 'payment_method_title' => 'Credit or debit card',
        'transaction_id' => 'pi_3Q8xYz', 'paid_at' => '2026-10-01 09:30:00']);

    $p = OrderPaymentPanel::for($order, 2500, []);

    expect($p['state'])->toBe('paid')->and($p['tone'])->toBe('green')
        ->and($p['headline'])->toBe('Paid with Credit or debit card')
        ->and($p['transaction_id'])->toBe('pi_3Q8xYz')
        ->and($p['date_label'])->not->toBeNull()
        ->and($p['refunds'])->toEqual(['total_aed' => 25, 'any' => true, 'net_aed' => 312]);
});

it('says "Not paid" for every unpaid status, red for failed and cancelled', function (string $status, string $tone) {
    $p = OrderPaymentPanel::for(puOrder(['status' => $status, 'payment_method' => 'ziina', 'payment_method_title' => 'Ziina']), 0, []);

    expect($p['state'])->toBe('unpaid')
        ->and($p['headline'])->toBe('Not paid')
        ->and($p['tone'])->toBe($tone)
        ->and($p['detail'])->toContain('Ziina');
})->with([
    ['pending', 'grey'], ['onhold', 'grey'], ['draft', 'grey'], ['failed', 'red'], ['cancelled', 'red'],
]);

it('puts the panel on the order screen payload and draws every word of it escaped', function () {
    puAdmin();
    $order = puOrder();

    $json = $this->getJson("/admin-api/orders/{$order->id}/detail")->assertOk()->json();

    expect($json['payment']['state'])->toBe('cod_due')
        ->and($json['can'])->toBe(['edit' => true, 'customer' => true, 'payment' => true, 'money' => true])
        ->and(array_column($json['payment_methods'], 'id'))->toContain('cod');

    $fn = puFn('odPaymentPanel');
    expect($fn)->toContain('sesc(p.headline)')
        ->and($fn)->toContain('sesc(p.method_title)')
        ->and($fn)->toContain('sesc(p.transaction_id)')
        ->and($fn)->toContain('sesc(p.detail)')
        ->and($fn)->toContain('sesc(p.tone)');

    // It is drawn where the owner asked: in the Items card, beside the totals.
    expect(puFn('odItemsCard'))->toContain("'<div class=\"odpayrow\">'+odPaymentPanel(o)+");
});

/* ============================================== 4. Capture */

it('removes the "Not captured … Capture" box, and offers Capture only on an authorised Tabby or Tamara order', function () {
    /*
     * Before: odCapturePanel() drew "Not captured. … Capture AED X" for any
     * SettlesPayments gateway — every COD order (CashOnDelivery has no window,
     * so it is always "capturable") and every Stripe order, which Stripe
     * Checkout had already captured. Measured on the preview: #33415 (COD,
     * Processing) and #33422 (Stripe, paid) both drew the button.
     * MUTATION: drop the method check from OrderPaymentPanel::capture() -> the
     * COD and Stripe rows below go red.
     */
    expect(puConsole())->not->toContain('Not captured.')
        ->and(puConsole())->not->toContain('function odCapturePanel');

    $settled = ['capturable' => true, 'window' => 'x', 'days_left' => 12, 'expiring' => false];

    $cod = puOrder();
    $stripe = puOrder(['payment_method' => 'stripe', 'paid_at' => now()]);
    $wooTabby = puOrder(['payment_method' => 'tabby_installments', 'paid_at' => now()]);
    $tabby = puOrder(['payment_method' => 'tabby', 'payment_method_title' => 'Pay in 4 with Tabby', 'paid_at' => now()]);
    $tabbyNotAuthorised = puOrder(['payment_method' => 'tabby', 'status' => 'processing']);

    expect(OrderPaymentPanel::for($cod, 0, $settled)['capture']['offered'])->toBeFalse()
        ->and(OrderPaymentPanel::for($stripe, 0, $settled)['capture']['offered'])->toBeFalse()
        ->and(OrderPaymentPanel::for($wooTabby, 0, $settled)['capture']['offered'])->toBeFalse()
        ->and(OrderPaymentPanel::for($tabbyNotAuthorised, 0, $settled)['capture']['offered'])->toBeFalse()
        ->and(OrderPaymentPanel::for($tabby, 0, $settled)['capture']['offered'])->toBeTrue()
        ->and(OrderPaymentPanel::for($tabby, 0, ['capturable' => false])['capture']['offered'])->toBeFalse();

    // The capture endpoint itself is untouched — Tabby and Tamara need it.
    expect(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/capture'))->toBe('orders.money');
});

/* ============================================== 5. Mark as paid */

it('marks a pending order paid and moves it to Processing in one write, with a note naming the admin', function () {
    $admin = puAdmin();
    PaymentProvider::updateOrCreate(['id' => 'tabby'], ['title' => 'Pay in 4 with Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    $order = puOrder(['status' => 'pending', 'payment_method' => 'ziina', 'payment_method_title' => 'Ziina', 'at' => now()->subHours(5)]);

    $this->postJson("/admin-api/orders/{$order->id}/mark-paid", [
        'payment_method' => 'ziina',
        'reference' => '  ZN-7781-2209  ',
        'paid_at' => now()->setTimezone(\App\Support\StoreTime::timezone())->subHour()->format('Y-m-d\TH:i'),
        'status' => 'processing',
        'notify' => false,
    ])->assertOk()->assertJson(['ok' => true, 'status' => 'processing']);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe('processing')
        ->and($fresh->transaction_id)->toBe('ZN-7781-2209')
        ->and($fresh->payment_method_title)->toBe('Ziina')
        ->and($fresh->paid_at)->not->toBeNull()
        ->and(abs($fresh->paid_at->diffInMinutes(now()->subHour())))->toBeLessThan(2);

    $notes = $fresh->notes()->pluck('content')->implode("\n");
    expect($notes)->toContain('Marked paid manually by ' . $admin->name)
        ->and($notes)->toContain('reference ZN-7781-2209');

    $panel = OrderPaymentPanel::for($fresh, 0, []);
    expect($panel['headline'])->toBe('Paid with Ziina')
        ->and($panel['transaction_id'])->toBe('ZN-7781-2209');
});

it('records cash received on a COD order as collected, and a manual Tabby or Tamara payment as taken', function () {
    puAdmin();
    PaymentProvider::updateOrCreate(['id' => 'tamara'], ['title' => 'Tamara', 'enabled' => true, 'mode' => 'test', 'position' => 2]);

    $cod = puOrder(['status' => 'shipped']);
    $this->postJson("/admin-api/orders/{$cod->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => now()->format('Y-m-d\TH:i')])
        ->assertOk();
    $cod->refresh();
    expect($cod->status)->toBe('shipped')
        ->and($cod->captured_at)->not->toBeNull()
        ->and($cod->captured_total)->toBe(33700)
        ->and(OrderPaymentPanel::for($cod, 0, [])['state'])->toBe('cod_collected');

    /*
     * Measured on the preview before this branch: a failed Tamara order marked
     * paid by hand came back offering Capture — a call to Tamara against a
     * reference somebody typed. MUTATION: drop AUTHORISE_THEN_CAPTURE from the
     * condition in ManualPayment::record() -> captured_at stays null, red.
     */
    $tamara = puOrder(['status' => 'pending', 'payment_method' => 'tamara', 'payment_method_title' => 'Tamara']);
    $this->postJson("/admin-api/orders/{$tamara->id}/mark-paid", [
        'payment_method' => 'tamara', 'reference' => 'TMR-1', 'paid_at' => now()->format('Y-m-d\TH:i'), 'status' => 'processing',
    ])->assertOk();
    $tamara->refresh();
    expect($tamara->captured_at)->not->toBeNull()
        ->and(OrderPaymentPanel::for($tamara, 0, ['capturable' => true])['capture']['offered'])->toBeFalse();
});

it('refuses a method the shop does not have, a future date, markup in the reference, and a paid-to-paid move', function () {
    puAdmin();
    $pending = puOrder(['status' => 'pending']);
    $date = now()->format('Y-m-d\TH:i');

    $this->postJson("/admin-api/orders/{$pending->id}/mark-paid", ['payment_method' => 'bitcoin', 'paid_at' => $date])
        ->assertStatus(422);
    $this->postJson("/admin-api/orders/{$pending->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => now()->addDays(2)->format('Y-m-d\TH:i')])
        ->assertStatus(422)->assertJson(['message' => 'The payment date cannot be in the future.']);
    $this->postJson("/admin-api/orders/{$pending->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date, 'reference' => "a\nb"])
        ->assertStatus(422)->assertJsonValidationErrors(['reference']);
    $this->postJson("/admin-api/orders/{$pending->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date, 'reference' => str_repeat('x', 192)])
        ->assertStatus(422)->assertJsonValidationErrors(['reference']);
    $this->postJson("/admin-api/orders/{$pending->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date, 'status' => 'cancelled'])
        ->assertStatus(422)->assertJsonValidationErrors(['status']);

    $processing = puOrder(['status' => 'processing']);
    $this->postJson("/admin-api/orders/{$processing->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date, 'status' => 'completed'])
        ->assertStatus(422);

    $cancelled = puOrder(['status' => 'cancelled']);
    $this->postJson("/admin-api/orders/{$cancelled->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date])
        ->assertStatus(422);

    expect($pending->fresh()->status)->toBe('pending')
        ->and($pending->fresh()->paid_at)->toBeNull()
        ->and($processing->fresh()->status)->toBe('processing');
});

it('asks for the payment only on an unpaid-to-paid move, and reverts the status on Cancel or Escape', function () {
    /*
     * MUTATION: delete the unpaid_statuses check in odNeedsPayment() -> a move
     * between two paid statuses would ask, and this is red.
     */
    $need = puFn('odNeedsPayment');
    expect($need)->toContain("(o.unpaid_statuses||[]).indexOf(o.status) < 0) return false")
        ->and($need)->toContain("(o.paid_statuses||[]).indexOf(to) < 0) return false")
        ->and($need)->toContain('if(!can.payment');

    $modal = puFn('odMarkPaidModal');
    expect($modal)->toContain('sel.value = o.status')
        ->and($modal)->toContain("'/admin-api/orders/'+o.id+'/mark-paid'");

    $shell = puFn('odModal');
    expect($shell)->toContain("e.key === 'Escape'")
        ->and($shell)->toContain('if(onCancel) onCancel()');

    expect(OrderPaymentPanel::UNPAID_STATUSES)->toBe(['pending', 'onhold', 'draft', 'failed'])
        ->and(OrderPaymentPanel::PAID_STATUSES)->toBe(['processing', 'shipped', 'completed']);
});

/* ============================================== 6. Capabilities */

it('maps every new or changed endpoint to its own capability', function () {
    expect(AdminCapabilities::forPath('PUT', 'admin-api/orders/{id}/address'))->toBe('orders.edit')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/mark-paid'))->toBe('orders.payment')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/orders/{id}/customer'))->toBe('orders.customer')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/order-customer-search'))->toBe('orders.customer')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders/{id}/customer-orders'))->toBe('orders.view')
        ->and(AdminCapabilities::CAPABILITIES['orders.payment'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['orders.customer'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['orders.edit'])->toBe(['owner', 'manager', 'support']);

    // Every route the file registers is mapped — none falls through to the
    // owner-only default by accident.
    $mine = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->getActionName(), 'OrderDetailController'));
    expect($mine)->toHaveCount(4);
    foreach ($mine as $r) {
        expect(AdminCapabilities::for($r))->not->toBeNull($r->uri());
    }
});

it('fails closed: support is refused the payment record and the customer change, editor is refused everything', function () {
    $order = puOrder(['status' => 'pending']);
    $date = now()->format('Y-m-d\TH:i');

    puAdmin('support');
    $this->postJson("/admin-api/orders/{$order->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date, 'status' => 'processing'])->assertForbidden();
    $this->putJson("/admin-api/orders/{$order->id}/customer", ['customer_id' => null])->assertForbidden();
    $this->getJson('/admin-api/order-customer-search?search=aa')->assertForbidden();
    // Support keeps what support is for: reading the history, fixing an address.
    $this->getJson("/admin-api/orders/{$order->id}/customer-orders")->assertOk();
    $this->putJson("/admin-api/orders/{$order->id}/address", ['type' => 'shipping', 'address' => ['city' => 'Ajman']])->assertOk();
    expect($this->getJson("/admin-api/orders/{$order->id}/detail")->json('can'))
        ->toBe(['edit' => true, 'customer' => false, 'payment' => false, 'money' => false]);

    puAdmin('editor');
    $this->getJson("/admin-api/orders/{$order->id}/customer-orders")->assertForbidden();
    $this->putJson("/admin-api/orders/{$order->id}/address", ['type' => 'shipping', 'address' => ['city' => 'Fujairah']])->assertForbidden();
    $this->postJson("/admin-api/orders/{$order->id}/mark-paid", ['payment_method' => 'cod', 'paid_at' => $date])->assertForbidden();

    expect($order->fresh()->status)->toBe('pending')
        ->and($order->fresh()->shipping_address['city'])->toBe('Ajman');
});

it('refuses all of it to a visitor who is not signed in', function () {
    $order = puOrder();

    $this->getJson("/admin-api/orders/{$order->id}/customer-orders")->assertUnauthorized();
    $this->postJson("/admin-api/orders/{$order->id}/mark-paid", [])->assertUnauthorized();
    $this->putJson("/admin-api/orders/{$order->id}/customer", [])->assertUnauthorized();
});

it('is required from routes/web.php at most once — never twice', function () {
    /*
     * The finished state is exactly one require, beside orders-admin.php,
     * which the integrator adds (CLAUDE.md). Two would register four routes
     * twice. This pins the half that can be wrong in both the lane's tree and
     * the integrated one; OrderDetailAdminRoutes is idempotent so the tests
     * above run either way.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/order-detail-admin.php';"))->toBeLessThanOrEqual(1);
});
