<?php

declare(strict_types=1);

/*
 * Store -> New Order: free gifts and Save as draft (2.60.630).
 *
 * The owner: "in new create order from backend, i can't add freebies, i need
 * a system, so according to the price range, i will have option to add
 * freebies, and the system will auto add as per the total price of order ...
 * also need a save as draft button in create new order page".
 */

use App\Models\Order;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\ManualOrders;

beforeEach(function () {
    ManualOrders::registerRoutes();
    ManualOrders::shop();
    $this->admin = ManualOrders::admin();
});

/** One range, AED 200 and above: 2 masks and 1 full size, gifts Live. */
function mogRange(string $mode = 'live'): array
{
    // Stock counted, as the shop's gift products are.
    $mask = ManualOrders::product('Tea Tree Sheet Mask', 900, ['manage_stock' => true]);
    $full = ManualOrders::product('Senka Perfect Whip', 3500, ['manage_stock' => true]);
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 20000, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true,
        'need_mask' => 0, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert([
        ['range_id' => $id, 'product_id' => $mask->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0],
        ['range_id' => $id, 'product_id' => $full->id, 'quantity' => 1, 'kind' => 'full', 'role' => 'primary', 'sort' => 1],
    ]);
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => $mode, 'testers' => []]);
    SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    app(FreeGifts::class)->compile();

    return [$mask, $full];
}

function mogPayload(array $over = []): array
{
    $customer = ManualOrders::customer(['email' => 'mog-' . uniqid() . '@example.ae']);
    $serum = ManualOrders::product('Glow Deep Serum', 12500); // AED 125 x 2 = AED 250

    return ManualOrders::payload(['customer_id' => $customer->id, 'items' => [['product_id' => $serum->id, 'quantity' => 2]]] + $over);
}

it('quotes the free gifts the order\'s total earns, and puts them on the order at AED 0, moving no stock like the rest of a manual order', function () {
    /*
     * What it looked like before: a manual order of AED 250 left with no
     * gifts at all, while the same basket on the website got three.
     * MUTATION: drop attachGifts() from createWithin() -> no gift lines;
     * drop 'gifts' from price() -> the quote shows none.
     */
    [$mask, $full] = mogRange();
    $payload = mogPayload();

    $q = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/quote', $payload)->assertOk();
    expect($q->json('gifts.range'))->toBe('AED 200+')
        ->and($q->json('gifts.mode'))->toBe('live')
        // Full size first, as the gift box lists them on the website (FreeGifts::fullFirst).
        ->and(array_column($q->json('gifts.lines'), 'quantity', 'name'))->toBe(['Senka Perfect Whip' => 1, 'Tea Tree Sheet Mask' => 2])
        ->and(array_column($q->json('gifts.lines'), 'key', 'name')['Tea Tree Sheet Mask'])->toBe($mask->id . ':0');

    $r = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders', $payload)->assertCreated();
    $order = Order::latest('id')->first();

    // Gifts are AED 0 lines beside the order's own; no total moves.
    expect($order->total)->toBe(25000)
        ->and($order->items)->toHaveCount(1)
        ->and($order->gifts->pluck('quantity', 'name')->sortKeys()->all())->toBe(['Senka Perfect Whip' => 1, 'Tea Tree Sheet Mask' => 2])
        ->and($order->gifts->sum('total'))->toBe(0)
        ->and((int) $order->free_gift_count)->toBe(3)
        ->and($order->gifts->first()->gift_range_id)->not->toBeNull()
        // A manual order moves no stock, gifts included (OrderStockReturnTest pins it).
        ->and($mask->fresh()->stock)->toBe(50)
        ->and($full->fresh()->stock)->toBe(50)
        ->and($order->notes->pluck('content')->implode(' '))->toContain('Free gifts (AED 200+) added on New Order')
        ->and(collect($r->json('order.gifts'))->pluck('name')->sort()->values()->all())->toBe(['Senka Perfect Whip', 'Tea Tree Sheet Mask']);
});

it('lets the operator switch the gifts off, or untick one', function () {
    // MUTATION: ignore free_gifts / gift_skip -> gifts land anyway.
    [$mask, $full] = mogRange();

    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders', mogPayload(['free_gifts' => false]))->assertCreated();
    expect(Order::latest('id')->first()->gifts)->toHaveCount(0);

    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders', mogPayload(['gift_skip' => [$mask->id . ':0']]))->assertCreated();
    expect(Order::latest('id')->first()->gifts->pluck('name')->all())->toBe(['Senka Perfect Whip']);

    // A key that is not "product:variation" is refused, never stored.
    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders', mogPayload(['gift_skip' => ['1;DROP']]))->assertStatus(422);
});

it('gives nothing below the first range, and says when the website\'s Mode is Off', function () {
    mogRange('off');
    $customer = ManualOrders::customer(['email' => 'mog-' . uniqid() . '@example.ae']);
    $small = ManualOrders::product('Lip Tint', 5000);

    $q = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/quote', ManualOrders::payload(['customer_id' => $customer->id, 'items' => [['product_id' => $small->id, 'quantity' => 1]]]))->assertOk();
    expect($q->json('gifts.lines'))->toBe([])->and($q->json('gifts.range'))->toBeNull();

    $q = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/quote', mogPayload())->assertOk();
    expect($q->json('gifts.mode'))->toBe('off')->and($q->json('gifts.lines'))->toHaveCount(2);
});

it('saves a draft that is not an order, opens it again, updates it, and removes it once the order is created', function () {
    /*
     * MUTATION: write the draft as an order -> the Orders count moves; drop
     * the draft_id delete in store() -> the draft outlives its order.
     */
    $orders = Order::count();
    $draft = ['customer' => ['id' => 1, 'name' => 'Layla Hassan'], 'newCustomer' => false,
        'lines' => [['product_id' => 5, 'qty' => 2, 'unit_price' => 12500, 'name' => 'Glow Deep Serum']], 'form' => ['line1' => 'Villa 3'], 'giftOff' => false, 'giftSkip' => []];

    $s = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/drafts', ['draft' => $draft])->assertCreated();
    $id = $s->json('id');
    expect($s->json('title'))->toBe('Layla Hassan')->and(Order::count())->toBe($orders);

    $list = test()->actingAs(test()->admin, 'admin')->getJson('/admin-api/manual-orders/drafts')->assertOk();
    expect($list->json('drafts.0'))->toMatchArray(['id' => $id, 'title' => 'Layla Hassan', 'items' => 2, 'value_fils' => 25000]);

    $draft['lines'][0]['qty'] = 3;
    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/drafts', ['id' => $id, 'draft' => $draft])->assertOk()->assertJsonPath('id', $id);
    expect(test()->actingAs(test()->admin, 'admin')->getJson('/admin-api/manual-orders/drafts/' . $id)->assertOk()->json('draft.lines.0.qty'))->toBe(3)
        ->and(DB::table('manual_order_drafts')->count())->toBe(1);

    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders', mogPayload(['draft_id' => $id]))->assertCreated();
    expect(DB::table('manual_order_drafts')->where('id', $id)->exists())->toBeFalse();

    $d2 = test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/drafts', ['draft' => $draft])->json('id');
    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/drafts/' . $d2 . '/delete')->assertOk();
    test()->actingAs(test()->admin, 'admin')->getJson('/admin-api/manual-orders/drafts/' . $d2)->assertNotFound();
});

it('refuses an oversized draft, and keeps drafts to roles that may take money', function () {
    // MUTATION: drop the size cap -> 201; drop the capability -> support saves one.
    $big = ['lines' => [], 'form' => ['customer_note' => str_repeat('x', 70000)]];
    test()->actingAs(test()->admin, 'admin')->postJson('/admin-api/manual-orders/drafts', ['draft' => $big])->assertStatus(422);

    $support = \App\Models\AdminUser::create(['name' => 'Sue', 'email' => 'sue-mo@example.test', 'password' => 'password-long-enough', 'role' => 'support']);
    test()->actingAs($support, 'admin')->postJson('/admin-api/manual-orders/drafts', ['draft' => ['lines' => []]])->assertForbidden();
    test()->actingAs($support, 'admin')->getJson('/admin-api/manual-orders/drafts')->assertForbidden();
});

it('draws the gifts panel, Save as draft and the drafts list on New Order', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/manual-order-screen.blade.php'));
    expect(substr_count($src, "'<div id=\"moGifts\">' + giftsHTML() + '</div>'"))->toBe(1)
        ->and(substr_count($src, 'id="moDraft"'))->toBe(1)
        ->and(substr_count($src, "'<div id=\"moDrafts\">' + draftsHTML() + '</div>'"))->toBe(1)
        ->and($src)->toContain('free_gifts: !giftOff,')->toContain('gift_skip: Object.keys(giftSkip),')->toContain('draft_id: draftId')
        ->and($src)->not->toMatch('/setInterval|getBoundingClientRect/');
});
