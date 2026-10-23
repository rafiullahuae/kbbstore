<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use Tests\Support\OwnerAppRoutes as OA;

/*
 * THE FREE GIFTS ON AN ORDER, IN THE OWNER APP.
 *
 * What it looked like (owner, 23 October): "in owner app, the free gifts are
 * not showing along with the order. i want the opened free gifts box by
 * default there. so i can see in one go." Order::items() leaves the gift lines
 * out on purpose, and the owner app's order screen read only `items`, so an
 * order that shipped with four gifts showed none of them.
 *
 * Mutation notes: drop the `gifts` key from OwnerApp\OrdersController::show()
 * and the first case is red; load the gifts for every order and the second is.
 */
beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
});

function oagOrder(int $gifts): Order
{
    static $n = 0;
    $n++;
    $order = Order::create([
        'order_number' => 'OAG-' . $n, 'email' => 'oag-' . $n . '@example.test', 'status' => 'processing',
        'total' => 20500, 'subtotal' => 20500, 'currency' => 'AED', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'free_gift_count' => $gifts,
    ]);
    OrderItem::create(['order_id' => $order->id, 'name' => 'Vanilla Deodorant and Body Mist Duo', 'quantity' => 1, 'unit_price' => 20500, 'subtotal' => 20500, 'total' => 20500]);

    if ($gifts > 0) {
        OrderItem::create(['order_id' => $order->id, 'name' => 'EGF NAD Firming Gel Mask', 'brand' => 'medicube', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
        OrderItem::create(['order_id' => $order->id, 'name' => 'Honey & Milk Lip Oil', 'brand' => "A'PIEU", 'quantity' => $gifts - 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
    }

    return $order;
}

it('sends the order\'s free gifts beside its products, with brand, quantity and name', function () {
    $order = oagOrder(3);
    [$c] = OA::enrol($this);

    $o = OA::get($this, 'orders/' . $order->id, $c)->assertOk()->json('order');

    expect(array_column($o['items'], 'name'))->toBe(['Vanilla Deodorant and Body Mist Duo'])
        ->and($o['gifts'])->toHaveCount(2)
        ->and($o['gifts'][0])->toMatchArray(['name' => 'EGF NAD Firming Gel Mask', 'brand' => 'medicube', 'qty' => 1])
        ->and($o['gifts'][1])->toMatchArray(['name' => 'Honey & Milk Lip Oil', 'brand' => "A'PIEU", 'qty' => 2]);
});

it('sends an empty list, and asks the database nothing more, for an order without gifts', function () {
    $order = oagOrder(0);
    [$c] = OA::enrol($this);

    \Illuminate\Support\Facades\DB::enableQueryLog();
    $o = OA::get($this, 'orders/' . $order->id, $c)->assertOk()->json('order');
    $giftQueries = array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'is_gift" = 1') || str_contains($q['query'], 'is_gift` = 1') || str_contains($q['query'], '"is_gift" = ?') && in_array(true, $q['bindings'], true));

    expect($o['gifts'])->toBe([])->and($giftQueries)->toBe([]);
});

it('draws the gifts in an always-open box under Products in the owner app', function () {
    $js = (string) file_get_contents(resource_path('js/owner-app/orders.js'));

    $products = strpos($js, '<h4 class="sh">Products');
    $gifts = strpos($js, 'class="card gifts"');

    expect($js)->toContain('<section class="card gifts"><h4 class="sh">Free gifts')
        ->and($gifts)->toBeGreaterThan($products)
        ->and($gifts)->toBeLessThan(strpos($js, '<h4 class="sh">Payment', $products))
        ->and($js)->not->toContain('<details class="card gifts"');
});
