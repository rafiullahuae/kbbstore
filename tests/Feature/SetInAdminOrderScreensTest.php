<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use Illuminate\Support\Str;

/**
 * BOTH admin screens that list an order's items say what is in a Set.
 *
 * ── THE DEFECT, AND WHY IT SURVIVED ONE ROUND OF FIXING ─────────────────────
 *
 * Lane SE wired the member list into eleven order documents — the confirmation,
 * the status mail, the invoice, the refund note, the plain-text twins, the
 * printed invoice, the packing slip, the delivery note, the basket reminder and
 * the manual-order receipt — and named ONE remaining gap for the integrator:
 * the order-detail screen's item cell, in a file a lane may not edit.
 *
 * There were TWO. `resources/views/admin/app.blade.php` builds an order's item
 * rows in two places with two different payload shapes:
 *
 *     odItemsCard()      it.quantity / it.unit_price_aed / it.total_aed
 *     the quick-view modal   it.qty / it.unit_aed / it.line_aed
 *
 * and the second is fed by a different controller (`AdminController::order()`,
 * not `AdminOrderController::show()`) which was not sending `set_contents` at
 * all. A search for the first cell's markup does not find the second, which is
 * exactly how it was missed. Somebody opening an order from the list — the
 * quicker of the two paths, and the one support uses — saw "Glow Set ×1" and no
 * way to learn what was in it.
 *
 * ── WHAT THIS PINS ─────────────────────────────────────────────────────────
 *
 * The payload on both endpoints, and ONE renderer shared by both cells. Two
 * copies of the markup would drift, and the copy that drifts is the one nobody
 * photographs.
 */
beforeEach(function () {
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function setScreenProduct(string $name, int $fils): Product
{
    return Product::create([
        'slug' => 'scr-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

/**
 * ITS OWN FIXTURE, not SetInDocumentsTest's setDocOrder().
 *
 * A function declared in a test file only exists once Pest has loaded that
 * file, so borrowing one makes this file pass in a full run and fail when run
 * alone -- which is how a test that is actually broken looks green in CI and
 * red on somebody's laptop. SetInManualOrderTest builds its own for the same
 * reason; that is this repository's convention.
 *
 * Placed through the REAL checkout, so `order_items.set_contents` is written by
 * the code that writes it in production rather than by this file.
 *
 * @return array{order: Order, set: Product, plain: Product}
 */
function setAdminScreensOrder(): array
{
    $toner = setScreenProduct('Heartleaf Toner', 9000);
    $serum = setScreenProduct('Azelaic Serum', 5000);

    $set = Product::create([
        'slug' => 'screen-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $serum->id, 'quantity' => 2, 'position' => 1]);

    // Deliberately nothing like a member's name, so "the plain line grew a
    // member list" cannot pass by accident.
    $plain = setScreenProduct('Rice Cleanser', 7000);

    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
    $cart->items()->create(['product_id' => $set->id, 'quantity' => 1, 'unit_price' => 12000]);
    $cart->items()->create(['product_id' => $plain->id, 'quantity' => 1, 'unit_price' => 7000]);

    test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ])->assertRedirect();

    return ['order' => Order::latest('id')->first()->fresh(['items']), 'set' => $set, 'plain' => $plain];
}

it('sends the box contents on both order endpoints', function () {
    ['order' => $order, 'set' => $set, 'plain' => $plain] = setAdminScreensOrder();

    $admin = AdminUser::create([
        'name' => 'Screen Owner',
        'email' => 'set-admin-screens@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    /*
     * MUTATION NOTE. Remove `set_contents` from AdminController::order()'s item
     * map and the quick-view half is red; remove it from
     * AdminOrderController::show() and the detail half is. RUN, both.
     */
    $quick = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders/'.$order->id)
        ->assertOk()
        ->json('items');

    $detail = test()->actingAs($admin, 'admin')
        ->getJson('/admin-api/orders/'.$order->id.'/detail')
        ->assertOk()
        ->json();

    $detailItems = $detail['items'] ?? $detail['order']['items'] ?? [];

    expect($quick)->not->toBe([], 'the quick-view endpoint returned no items at all');
    expect($detailItems)->not->toBe([], 'the detail endpoint returned no items at all');

    foreach (['quick-view' => $quick, 'detail' => $detailItems] as $which => $items) {
        $setLine = null;
        $plainLine = null;

        foreach ($items as $line) {
            if (($line['name'] ?? '') === $set->name) {
                $setLine = $line;
            }
            if (($line['name'] ?? '') === $plain->name) {
                $plainLine = $line;
            }
        }

        expect($setLine)->not->toBeNull("the {$which} payload has no line for the set");
        expect($plainLine)->not->toBeNull("the {$which} payload has no line for the ordinary product");

        $lines = $setLine['set_contents'] ?? null;

        expect(is_array($lines))->toBeTrue(
            "the {$which} payload's set line carries no set_contents key, so the screen has nothing "
            .'to draw and a support agent cannot see what is in the box.'
        );
        expect(count($lines))->toBe(
            2,
            "the {$which} payload's set line lists ".count($lines).' members; the set holds 2.'
        );

        $joined = implode(' | ', $lines);

        expect(str_contains($joined, 'Heartleaf Toner'))->toBeTrue("{$which}: [{$joined}]");
        expect(str_contains($joined, 'Azelaic Serum'))->toBeTrue("{$which}: [{$joined}]");
        expect(str_contains($joined, 'Rice Cleanser'))->toBeFalse(
            "{$which}: the ordinary product appears inside the SET's member list — the two lines "
            .'have been crossed.'
        );

        /*
         * The other half, and the one a careless fix breaks: an ordinary line
         * must carry an EMPTY list, so the cell draws nothing rather than an
         * empty bordered box under every product in every order.
         */
        expect($plainLine['set_contents'] ?? [])->toBe(
            [],
            "{$which}: an ordinary product's line carries a member list."
        );
    }
});

it('draws the box from one renderer, used by both cells', function () {
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // Comments stripped: this file's own reasoning names the function.
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    /*
     * MUTATION NOTE. Paste the renderer's body into the second cell instead of
     * calling it and the definition count stays 1 while the call count drops to
     * 1 — red, which is the point: two copies of this markup is how one of them
     * ends up with different escaping from the other. RUN.
     */
    expect(substr_count($src, 'function odSetContents(it){'))->toBe(
        1,
        'resources/views/admin/app.blade.php must define odSetContents EXACTLY ONCE.'
    );
    expect(substr_count($src, 'odSetContents(it)'))->toBe(
        3,
        'odSetContents must be CALLED from both item cells — odItemsCard and the quick-view modal — '
        .'which with its own definition is three occurrences of the token. One call means a screen '
        .'still shows a set as an anonymous line; none means the renderer is dead code.'
    );

    /*
     * And it escapes. `set_contents` is a list of names that came from the
     * catalogue, which came from the WordPress import, and it is printed into
     * innerHTML.
     */
    $body = substr($src, (int) strpos($src, 'function odSetContents(it){'), 700);

    expect(str_contains($body, 'sesc(l)'))->toBeTrue(
        'odSetContents must escape every member name — it prints imported text into innerHTML.'
    );
});
