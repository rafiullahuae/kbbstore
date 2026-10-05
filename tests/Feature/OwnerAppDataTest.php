<?php

declare(strict_types=1);

/*
 * The owner app's data (Lane MAC): orders, products, customers, dashboard.
 * The writes must be the admin's own; the reads must stay flat as the shop
 * grows.
 */

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    $this->owner = OA::admin();
    OA::member($this->owner);
});

function oaShop(int $orders, int $products = 2): array
{
    $pids = [];
    for ($i = 1; $i <= $products; $i++) {
        $pids[] = (int) Product::query()->create(['slug' => 'oa-p-'.$i, 'name' => ($i === 1 ? 'Centella Ampoule' : 'Rice Toner '.$i), 'sku' => 'OA-SKU-'.$i,
            'price' => 9900, 'manage_stock' => true, 'stock' => 20, 'stock_status' => 'instock'])->id;
    }
    $ids = [];
    for ($i = 1; $i <= $orders; $i++) {
        $cid = DB::table('customers')->insertGetId(['name' => 'Customer '.$i, 'email' => 'c'.$i.'@example.com', 'phone' => '+97150000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'created_at' => now(), 'updated_at' => now()]);
        $o = Order::query()->create(['order_number' => (string) (40000 + $i), 'customer_id' => $cid, 'email' => 'c'.$i.'@example.com',
            'status' => 'processing', 'total' => 10000 + $i, 'subtotal' => 10000, 'payment_method' => $i % 2 ? 'tabby' : 'cod',
            'billing_address' => ['first_name' => 'First'.$i, 'last_name' => 'Last']]);
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $pids[$i % count($pids)], 'name' => $i === 3 ? 'Centella Ampoule' : 'Rice Toner',
            'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        $ids[] = (int) $o->id;
    }

    return ['orders' => $ids, 'products' => $pids];
}

it('changes many orders in one request through the admin’s own bulk action', function () {
    // DEFECT: an app that writes `status` directly, so no stock goes back, no
    // coupon is released, no email is sent and no note says who did it.
    // MUTATION: replace the delegate in OrdersController::bulkStatus() with
    // Order::whereIn(...)->update(['status' => ...]) and the note expectation
    // (written only by OrderStatus::moveTo()) is red.
    $shop = oaShop(3);
    [$c, $csrf] = OA::enrol($this);

    $r = OA::post($this, 'orders-bulk-status', ['ids' => $shop['orders'], 'status' => 'completed'], $c, $csrf)->assertOk();
    expect($r->json('changed'))->toBe(3);

    foreach ($shop['orders'] as $id) {
        expect(Order::query()->find($id)->status)->toBe('completed');
        $note = DB::table('order_notes')->where('order_id', $id)->latest('id')->first();
        expect($note->content)->toStartWith('Status changed from processing to completed.')
            ->and($note->content)->toContain('Set from the orders list.')
            ->and($note->author)->toBe('Rafi Owner');
    }
});

it('reports a paid order the bulk action refuses to drop from revenue, and forces it on request', function () {
    $shop = oaShop(1);
    [$c, $csrf] = OA::enrol($this);

    $r = OA::post($this, 'orders-bulk-status', ['ids' => $shop['orders'], 'status' => 'cancelled'], $c, $csrf)->assertOk();
    expect($r->json('changed'))->toBe(0)->and($r->json('skipped.0.forceable'))->toBeTrue();

    OA::post($this, 'orders-bulk-status', ['ids' => $shop['orders'], 'status' => 'cancelled', 'force' => true], $c, $csrf)->assertOk()->assertJsonPath('changed', 1);
});

it('changes one order, adds a note and refuses a status the admin would refuse', function () {
    $shop = oaShop(1);
    [$c, $csrf] = OA::enrol($this);
    $id = $shop['orders'][0];

    OA::post($this, 'orders/'.$id.'/status', ['status' => 'shipped'], $c, $csrf)->assertOk()->assertJsonPath('status', 'shipped');
    expect(DB::table('order_notes')->where('order_id', $id)->latest('id')->value('content'))->toContain('Changed on the order screen.');

    OA::post($this, 'orders/'.$id.'/status', ['status' => 'refunded'], $c, $csrf)->assertStatus(422);

    OA::post($this, 'orders/'.$id.'/notes', ['content' => 'Called the customer, leaving with Aramex today.'], $c, $csrf)
        ->assertOk()->assertJsonPath('note.author', 'Rafi Owner');
    OA::post($this, 'orders/'.$id.'/notes', ['content' => ''], $c, $csrf)->assertStatus(422);
});

it('finds orders by number, email, phone, customer name and product', function (string $q, int $expect) {
    oaShop(5);
    [$c] = OA::enrol($this);

    expect(count(OA::get($this, 'orders?q='.urlencode($q), $c)->assertOk()->json('orders')))->toBe($expect);
})->with([
    'number' => ['#40003', 1],
    'email' => ['c4@example.com', 1],
    'customer name' => ['Customer 2', 1],
    'billing name' => ['First5', 1],
    'product' => ['Centella', 1],
    'a wildcard typed literally' => ['%', 0],
]);

it('filters orders by status, payment method and date, with chip counts', function () {
    oaShop(4);
    [$c] = OA::enrol($this);

    $all = OA::get($this, 'orders', $c)->assertOk();
    expect($all->json('counts.processing'))->toBe(4)
        ->and(count(OA::get($this, 'orders?payment=cod', $c)->json('orders')))->toBe(2)
        ->and(count(OA::get($this, 'orders?status=completed', $c)->json('orders')))->toBe(0)
        ->and(count(OA::get($this, 'orders?from='.now()->addDays(2)->format('Y-m-d'), $c)->json('orders')))->toBe(0);
});

it('opens one order with its lines, totals, customer, notes and neighbours', function () {
    $shop = oaShop(3);
    [$c] = OA::enrol($this);
    $mid = $shop['orders'][1];

    $o = OA::get($this, 'orders/'.$mid, $c)->assertOk()->json('order');
    expect($o['number'])->toBe('40002')
        ->and($o['prev_id'])->toBe($shop['orders'][0])
        ->and($o['next_id'])->toBe($shop['orders'][2])
        ->and($o['items'][0]['qty'])->toBe(1)
        ->and($o['payment']['kind'])->toBe('cod')
        ->and($o['customer']['orders'])->toBe(1)
        ->and($o['can'])->toBe(['status' => true, 'note' => true, 'paid' => false]);
});

it('keeps every list at the same number of queries for 3 rows and for 40', function (string $path) {
    // DEFECT: a query per row — the app would slow down as the shop grows.
    // MUTATION: load the customer inside OrdersController::row() and the
    // orders case is red.
    $count = function (int $n) use ($path) {
        DB::table('order_items')->delete();
        DB::table('order_notes')->delete();
        Order::query()->forceDelete();
        DB::table('customers')->delete();
        Product::query()->where('slug', 'like', 'oa-p-%')->forceDelete();
        oaShop($n, $n);
        [$c] = OA::enrol($this);
        OA::get($this, $path, $c)->assertOk();   // warm: per-process memos are not the per-row cost
        DB::flushQueryLog();
        DB::enableQueryLog();
        OA::get($this, $path, $c)->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
})->with(['orders', 'products', 'customers', 'dashboard']);

it('saves a quick product edit through the admin editor’s own rules', function () {
    // DEFECT: an app save that skips the editor's validation. MUTATION: write
    // the columns directly in ProductsController::update() and the negative
    // stock is stored.
    $shop = oaShop(0, 1);
    $pid = $shop['products'][0];
    [$c, $csrf] = OA::enrol($this);

    OA::post($this, 'products/'.$pid, ['stock' => -4], $c, $csrf)->assertStatus(422);
    OA::post($this, 'products/'.$pid, ['stock_status' => 'nonsense'], $c, $csrf)->assertStatus(422);
    OA::post($this, 'products/'.$pid, ['name' => 'Renamed from a phone'], $c, $csrf)->assertStatus(422);   // not an app field
    expect(Product::query()->find($pid)->stock)->toBe(20);

    $r = OA::post($this, 'products/'.$pid, ['stock' => 3, 'price_aed' => '120'], $c, $csrf)->assertOk();
    $p = Product::query()->find($pid);
    expect($p->stock)->toBe(3)->and($p->price)->toBe(12000)
        ->and($r->json('product.stock'))->toBe(3)->and($r->json('product.low'))->toBeTrue()
        ->and(Product::query()->find($pid)->name)->toBe('Centella Ampoule');
});

it('will not save over a product somebody has open in the admin editor', function () {
    $shop = oaShop(0, 1);
    $pid = $shop['products'][0];
    $other = OA::admin('manager', 'mo@example.com', 'Mo Manager');
    \App\Support\EditPresence::beat($other, 'product', (string) $pid, bin2hex(random_bytes(16)));
    expect(\App\Support\EditPresence::heldByOther('product', (string) $pid, $this->owner))->toBe('Mo Manager');

    [$c, $csrf] = OA::enrol($this);
    OA::post($this, 'products/'.$pid, ['stock' => 1], $c, $csrf)->assertStatus(409)->assertJsonPath('code', 'edit_locked');
    expect(Product::query()->find($pid)->stock)->toBe(20);
});

it('shows a customer with lifetime value and purchase history', function () {
    $shop = oaShop(2);
    Order::query()->create(['order_number' => '49999', 'customer_id' => DB::table('customers')->min('id'), 'email' => 'c1@example.com',
        'status' => 'completed', 'total' => 25000, 'payment_method' => 'cod']);
    [$c] = OA::enrol($this);
    $cid = (int) DB::table('customers')->min('id');

    $x = OA::get($this, 'customers/'.$cid, $c)->assertOk()->json('customer');
    expect($x['orders'])->toBe(2)->and($x['paid_orders'])->toBe(2)
        ->and(count($x['history']))->toBe(2)
        ->and($x['spent_display'])->toContain('350');

    expect(count(OA::get($this, 'customers?q=c2%40example', $c)->json('customers')))->toBe(1)
        ->and(count(OA::get($this, 'customers?q=%2B971500000001', $c)->json('customers')))->toBe(1);
});

it('gives a dashboard with sales, counts, low stock, latest orders and top performers', function () {
    $shop = oaShop(3);
    Product::query()->whereKey($shop['products'][0])->update(['stock' => 2]);
    [$c] = OA::enrol($this);

    $d = OA::get($this, 'dashboard', $c)->assertOk()->json();
    expect($d['paid_orders'])->toBe(3)
        ->and($d['money'])->toBeTrue()
        ->and($d['counts']['processing'])->toBe(3)
        ->and($d['stock']['low'])->toBeGreaterThanOrEqual(1)
        ->and(count($d['latest']))->toBe(3)
        ->and(count($d['chart']['labels']))->toBe(24)
        ->and($d['top'])->not->toBe([]);

    expect(count(OA::get($this, 'dashboard?range=month', $c)->json('chart.labels')))->toBe(now(\App\Support\StoreTime::zone())->day);
});
