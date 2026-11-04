<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Orders -> (an order): change the payment method, apply a coupon
| (2.60.641)
|------------------------------------------------------------------------------
| The owner, 4 November: "I can not change the order payment method manually,
| for example pending or failed order to Cash on Delivery, Cash on Delivery to
| Paid orders etc. ... if i select COD, the cod amount will be added auto, and
| also i should have option to apply coupons inside the existing orders."
| Before this there was no control for either: the method could only be set by
| "Mark as paid" on a status move, and an order's coupon never changed after
| checkout.
*/

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Services\CouponService;
use App\Services\SettingsService;
use Illuminate\Support\Str;

beforeEach(function () {
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    PaymentProvider::create(['id' => 'tabby', 'title' => 'Tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1]);
    PaymentProvider::create(['id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 2]);
    app(SettingsService::class)->set('cod_fee', '1000');
});

function opeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'OPE ' . $role, 'email' => 'ope-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

/** A 200.00 order (two 100.00 lines) + 20.00 shipping, unpaid, on $method. */
function opeOrder(string $status = 'failed', string $method = 'tabby', array $o = []): Order
{
    $order = Order::create(array_merge([
        'order_number' => 'OPE-' . Str::random(6), 'email' => 'buyer@example.test', 'phone' => '+971501234567', 'status' => $status,
        'subtotal' => 20000, 'shipping_total' => 2000, 'total' => 22000, 'fee_total' => 0, 'gift_fee' => 0, 'discount_total' => 0,
        'currency' => 'AED', 'payment_method' => $method, 'payment_method_title' => $method === 'cod' ? 'Cash on delivery' : 'Tabby',
        'shipping_address' => ['first_name' => 'Aisha', 'country' => 'AE', 'city' => 'Dubai'],
    ], $o));

    foreach (['Glow Serum', 'Snail Essence'] as $name) {
        $p = Product::create(['slug' => Str::slug($name) . '-' . Str::random(5), 'name' => $name, 'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 20]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'name' => $name, 'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000]);
    }

    return $order->fresh();
}

function opeAs(?AdminUser $a = null)
{
    return test()->actingAs($a ?? opeAdmin(), 'admin');
}

/* ------------------------------------------------------------- the method */

it('turns a failed Tabby order into Cash on delivery, adds the COD fee, moves it to Processing and writes it down', function () {
    /*
     * The defect: no way to do this at all ("I can not change the order
     * payment method manually ... failed order to Cash on Delivery").
     * MUTATION: drop `$newCod` from $newFee in OrderPaymentEdit::changeMethod()
     * -- the fee is not added and total stays 220.00. RED.
     */
    $order = opeOrder('failed', 'tabby');

    opeAs()->postJson("/admin-api/orders/{$order->id}/payment-method", ['payment_method' => 'cod', 'status' => 'processing', 'notify' => false])
        ->assertOk()->assertJson(['ok' => true]);

    $order->refresh();
    expect($order->payment_method)->toBe('cod')->and($order->payment_method_title)->toBe('Cash on delivery')
        ->and((int) $order->fee_total)->toBe(1000)->and((int) $order->total)->toBe(23000)
        ->and($order->status)->toBe('processing')
        ->and($order->paid_at)->toBeNull()
        ->and($order->notes()->pluck('content')->implode(' | '))->toContain('Tabby → Cash on delivery')->toContain('COD fee AED 10.00 added')->toContain('total AED 220.00 → AED 230.00');
});

it('lets the owner leave the COD fee off, and takes the COD fee away again when the order leaves COD, keeping the gift-wrap fee', function () {
    /*
     * MUTATION: in codPart() return fee_total instead of fee_total - gift_fee
     * -- leaving COD strips the gift wrap too. RED.
     */
    $order = opeOrder('pending', 'cod', ['fee_total' => 1000 + 1500, 'gift_fee' => 1500, 'total' => 22000 + 2500]);

    opeAs()->postJson("/admin-api/orders/{$order->id}/payment-method", ['payment_method' => 'stripe'])->assertOk();
    $order->refresh();
    expect((int) $order->fee_total)->toBe(1500)->and((int) $order->total)->toBe(23500)->and($order->payment_method)->toBe('stripe');

    opeAs()->postJson("/admin-api/orders/{$order->id}/payment-method", ['payment_method' => 'cod', 'cod_fee' => false])->assertOk();
    $order->refresh();
    expect((int) $order->fee_total)->toBe(1500)->and($order->payment_method)->toBe('cod');
});

it('records a COD order as paid by card in one step, through Mark as paid\'s own write', function () {
    /*
     * "Cash on Delivery to Paid orders". MUTATION: skip ManualPayment::record()
     * when `paid` -- paid_at stays empty. RED.
     */
    $order = opeOrder('processing', 'cod', ['fee_total' => 1000, 'total' => 23000]);

    opeAs()->postJson("/admin-api/orders/{$order->id}/payment-method", [
        'payment_method' => 'stripe', 'paid' => true, 'reference' => 'pi_123', 'paid_at' => now()->subHour()->format('Y-m-d\TH:i'),
    ])->assertOk();

    $order->refresh();
    expect($order->payment_method)->toBe('stripe')->and($order->paid_at)->not->toBeNull()
        ->and($order->transaction_id)->toBe('pi_123')->and((int) $order->fee_total)->toBe(0)->and((int) $order->total)->toBe(22000)
        ->and($order->status)->toBe('processing');
});

it('refuses a paid, completed, cancelled or trashed order, and a method the shop does not have, writing nothing', function () {
    /*
     * The defect this guards: a card payment rewritten as cash, or a live
     * Tabby authorisation stranded. MUTATION: drop the paid_at / captured_at
     * check in changeBlock(). RED.
     */
    $paid = opeOrder('processing', 'stripe', ['paid_at' => now()]);
    $authorised = opeOrder('processing', 'tabby', ['paid_at' => now()]);
    $done = opeOrder('completed', 'cod');
    $gone = opeOrder('cancelled', 'cod');

    foreach ([$paid, $authorised, $done, $gone] as $o) {
        opeAs()->postJson("/admin-api/orders/{$o->id}/payment-method", ['payment_method' => 'cod'])->assertStatus(422);
        expect($o->fresh()->payment_method)->toBe($o->payment_method)->and((int) $o->fresh()->total)->toBe((int) $o->total);
    }

    $open = opeOrder('pending', 'tabby');
    opeAs()->postJson("/admin-api/orders/{$open->id}/payment-method", ['payment_method' => 'bitcoin'])->assertStatus(422);
    opeAs()->postJson("/admin-api/orders/{$open->id}/payment-method", ['payment_method' => 'cod', 'status' => 'completed'])->assertStatus(422);
    expect($open->fresh()->payment_method)->toBe('tabby');
});

/* ------------------------------------------------------------- coupons */

it('applies a coupon to an existing unpaid order at its own prices, records the use once, and swaps or removes it cleanly', function () {
    /*
     * MUTATION: drop detachRedemptions() from applyCoupon() -- the first
     * code's use is never given back and FIRST's usage_count stays 1. RED.
     * MUTATION: price the cart at today's product price -- the 30% code takes
     * 90.00 off (the price was raised to 150.00), not 60.00. RED.
     */
    $order = opeOrder('processing', 'cod', ['fee_total' => 1000, 'total' => 23000]);
    Product::query()->update(['price' => 15000]);
    $first = Coupon::create(['code' => 'FIRST', 'type' => 'fixed_cart', 'amount' => 2000]);
    $second = Coupon::create(['code' => 'THIRTY', 'type' => 'percent', 'amount' => 3000]);

    opeAs()->postJson("/admin-api/orders/{$order->id}/coupon", ['code' => 'first'])->assertOk();
    $order->refresh();
    expect($order->coupon_code)->toBe('FIRST')->and((int) $order->discount_total)->toBe(2000)->and((int) $order->total)->toBe(21000)
        ->and((int) $first->fresh()->usage_count)->toBe(1)
        ->and(CouponRedemption::where('order_id', $order->id)->whereNull('released_at')->count())->toBe(1);

    opeAs()->postJson("/admin-api/orders/{$order->id}/coupon", ['code' => 'THIRTY'])->assertOk();
    $order->refresh();
    expect($order->coupon_code)->toBe('THIRTY')->and((int) $order->discount_total)->toBe(6000)->and((int) $order->total)->toBe(17000)
        ->and((int) $first->fresh()->usage_count)->toBe(0)->and((int) $second->fresh()->usage_count)->toBe(1)
        ->and(CouponRedemption::where('order_id', $order->id)->pluck('coupon_id')->all())->toBe([$second->id]);

    // A later cancel and revive takes back THIRTY only, never FIRST.
    app(CouponService::class)->releaseRedemptions($order->id);
    app(CouponService::class)->reclaimRedemptions($order->id);
    expect((int) $first->fresh()->usage_count)->toBe(0)->and((int) $second->fresh()->usage_count)->toBe(1);

    opeAs()->deleteJson("/admin-api/orders/{$order->id}/coupon")->assertOk();
    $order->refresh();
    expect($order->coupon_code)->toBeNull()->and((int) $order->discount_total)->toBe(0)->and((int) $order->total)->toBe(23000)
        ->and((int) $second->fresh()->usage_count)->toBe(0)
        ->and($order->notes()->pluck('content')->implode(' | '))->toContain('Coupon FIRST applied')->toContain('in place of FIRST')->toContain('Coupon THIRTY removed');
});

it('keeps every rule of the code, and refuses a coupon on a paid or shipped order', function () {
    /*
     * MUTATION: skip CouponService::validate() in price() -- the expired code
     * goes on. RED.
     */
    $order = opeOrder('pending', 'cod');
    Coupon::create(['code' => 'OLD', 'type' => 'fixed_cart', 'amount' => 2000, 'expires_at' => now()->subDay()]);
    Coupon::create(['code' => 'BIGSPEND', 'type' => 'fixed_cart', 'amount' => 2000, 'minimum_amount' => 50000]);
    Coupon::create(['code' => 'GONE', 'type' => 'fixed_cart', 'amount' => 2000, 'usage_limit' => 1, 'usage_count' => 1]);

    foreach (['OLD' => 'expired', 'BIGSPEND' => 'minimum', 'GONE' => 'fully redeemed', 'NOPE' => 'not valid'] as $code => $words) {
        $res = opeAs()->postJson("/admin-api/orders/{$order->id}/coupon", ['code' => $code])->assertStatus(422);
        expect(strtolower((string) $res->json('message')))->toContain($words);
    }
    expect($order->fresh()->coupon_code)->toBeNull()->and((int) $order->fresh()->total)->toBe(22000);

    Coupon::create(['code' => 'OK20', 'type' => 'fixed_cart', 'amount' => 2000]);
    foreach ([opeOrder('processing', 'stripe', ['paid_at' => now()]), opeOrder('shipped', 'cod')] as $o) {
        opeAs()->postJson("/admin-api/orders/{$o->id}/coupon", ['code' => 'OK20'])->assertStatus(422);
        expect($o->fresh()->coupon_code)->toBeNull();
    }
});

/* ------------------------------------------------------- who, and the screen */

it('gives each control its own capability and fails closed for a support login', function () {
    $order = opeOrder('pending', 'tabby');
    Coupon::create(['code' => 'OK20', 'type' => 'fixed_cart', 'amount' => 2000]);
    $support = opeAdmin('support');

    opeAs($support)->postJson("/admin-api/orders/{$order->id}/payment-method", ['payment_method' => 'cod'])->assertForbidden();
    opeAs($support)->postJson("/admin-api/orders/{$order->id}/coupon", ['code' => 'OK20'])->assertForbidden();
    opeAs($support)->deleteJson("/admin-api/orders/{$order->id}/coupon")->assertForbidden();
    expect(test()->postJson("/admin-api/orders/{$order->id}/payment-method", ['payment_method' => 'cod'])->status())->toBeIn([401, 403]);

    expect($order->fresh()->payment_method)->toBe('tabby')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/payment-method'))->toBe('orders.payment')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/coupon'))->toBe('orders.money')
        ->and(\App\Support\AdminCapabilities::forPath('DELETE', 'admin-api/orders/{id}/coupon'))->toBe('orders.money');
});

it('tells the order screen what may change, with the COD fee, and draws both controls once', function () {
    $order = opeOrder('failed', 'tabby');
    $pe = opeAs()->getJson("/admin-api/orders/{$order->id}/detail")->assertOk()->json('payment_edit');

    expect($pe['change']['allowed'])->toBeTrue()->and($pe['coupon']['allowed'])->toBeTrue()
        ->and((float) $pe['cod_fee_aed'])->toBe(10.0)->and($pe['unpaid_status'])->toBeTrue();

    $paid = opeOrder('processing', 'stripe', ['paid_at' => now()]);
    $pe = opeAs()->getJson("/admin-api/orders/{$paid->id}/detail")->assertOk()->json('payment_edit');
    expect($pe['change']['allowed'])->toBeFalse()->and($pe['coupon']['allowed'])->toBeFalse();

    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($blade, 'function odPayMethodModal('))->toBe(1)
        ->and(substr_count($blade, 'function odCouponModal('))->toBe(1)
        ->and(substr_count($blade, "id=\"odPayMethodGo\""))->toBe(1)
        ->and(substr_count($blade, "require __DIR__.'/order-payment-edit.php';"))->toBe(0)
        ->and(substr_count((string) file_get_contents(base_path('routes/web.php')), "require __DIR__.'/order-payment-edit.php';"))->toBe(1);
});
