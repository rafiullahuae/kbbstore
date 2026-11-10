<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\BuyTogetherSettings;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * EVERY OPEN BASKET LINE CARRIES TODAY'S PRICE. (Lane RP, order #56680)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT ON THE SHOP. A shopper put the Anua PDRN Capsule Mist Mini in the
 * bag at AED 85. The owner raised it to AED 89 that morning. The shopper
 * checked out that night and paid AED 85: `cart_items.unit_price` was a
 * snapshot taken by CartService::add() that nothing ever refreshed, and
 * CheckoutController::place() wrote the order from it.
 *
 * The owner: "sure proceed, but make sure no breakage etc in anything
 * existing." So every line is brought to unitPriceFor() -- what adding it now
 * would charge -- whenever the basket is drawn (panel, cart page, checkout and
 * their JSON answers) and AGAIN inside place(); the shopper is told on the
 * line; and Place order refuses to charge a total its page did not show.
 */

function rprfProduct(string $name, int $fils, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => 'rp-' . Str::slug($name) . '-' . Str::lower(Str::random(6)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $fils, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $extra));
}

function rprfCart(): Cart
{
    return Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
}

/** One request as the shopper holding this basket, from a clean service. */
function rprfAs(Cart $cart)
{
    app(CartService::class)->forget();

    return test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);
}

function rprfShip(): void
{
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
}

function rprfPlaceBody(): array
{
    return [
        'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
        'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
        'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
        'billing_country' => 'AE', 'payment_method' => 'cod',
    ];
}

function rprfReprice(Product $p, int $fils): void
{
    $p->forceFill(['price' => $fils])->save();
}

function rprfUnit(Cart $cart): int
{
    return (int) DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('id')->value('unit_price');
}

/** The queries one GET costs. */
function rprfQueries(Cart $cart, string $path): int
{
    $req = rprfAs($cart);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $req->get($path)->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

/* ═══════════════════════════════ the money ═══════════════════════════════ */

it('charges the raised price: a line added at AED 85 and repriced to AED 89 totals and places at 89', function () {
    /*
     * Order #56680 exactly. MUTATION: remove the refreshPrices() call from
     * CheckoutController::loadCart() and the order is written at 8500 (red).
     */
    rprfShip();
    $mist = rprfProduct('Anua PDRN Capsule Mist Mini', 8500);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);
    expect(rprfUnit($cart))->toBe(8500);

    rprfReprice($mist, 8900);

    // The checkout the shopper opens that night shows 89 and says so.
    rprfAs($cart)->get('/checkout')->assertOk()
        ->assertSee('Price updated from AED 85 to AED 89')
        // The summary opens folded, so the band above the form names it too.
        ->assertSee('<div class="woocommerce-info" role="status">Anua PDRN Capsule Mist Mini: Price updated from AED 85 to AED 89</div>', false)
        ->assertSee('role="status"', false);

    expect(rprfUnit($cart))->toBe(8900);
    expect(app(CartService::class)->totals(Cart::find($cart->id), 'AE')['subtotal'])->toBe(8900);

    rprfAs($cart)->post('/checkout/place', rprfPlaceBody())->assertRedirect();

    $order = Order::latest('id')->firstOrFail();
    expect((int) $order->items->first()->unit_price)->toBe(8900)
        ->and((int) $order->subtotal)->toBe(8900)
        ->and((int) $order->total)->toBe(8900 + 2000);
});

it('lowers the price the other way: a cut from AED 89 to AED 79 reaches the basket and the order', function () {
    /* MUTATION: return early when $now < $was in refreshPrices() -- red. */
    rprfShip();
    $mist = rprfProduct('Mist', 8900);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);

    rprfReprice($mist, 7900);

    rprfAs($cart)->get('/cart')->assertOk()->assertSee('Price updated from AED 89 to AED 79');
    expect(rprfUnit($cart))->toBe(7900);

    rprfAs($cart)->get('/checkout')->assertOk()->assertDontSee('Price updated from');
    rprfAs($cart)->post('/checkout/place', rprfPlaceBody())->assertRedirect();

    expect((int) Order::latest('id')->firstOrFail()->total)->toBe(7900 + 2000);
});

it('follows a sale, a scheduled sale ending and a quantity tier, exactly as adding it now would', function () {
    /* MUTATION: price with effectivePrice() instead of unitPriceFor() -- the tier case is red. */
    $p = rprfProduct('Toner', 10000);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $p, 1);

    $p->forceFill(['sale_price' => 8000, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()])->save();
    rprfAs($cart)->get('/cart')->assertOk();
    expect(rprfUnit($cart))->toBe(8000);

    $p->forceFill(['sale_ends_at' => now()->subMinute()])->save();
    rprfAs($cart)->get('/cart')->assertOk();
    expect(rprfUnit($cart))->toBe(10000);

    $line = Cart::find($cart->id)->items()->first();
    DB::table('cart_items')->where('id', $line->id)->update(['quantity' => 3]);
    rprfAs($cart)->get('/cart')->assertOk();
    expect(rprfUnit($cart))->toBe(app(CartService::class)->unitPriceFor(Product::find($p->id), null, 3));
});

/* ═════════════════════════ place order, never stale ═════════════════════ */

it('never charges the stale amount when the price moves between the checkout page and Place order', function () {
    /*
     * The page was drawn at AED 85; the owner then raised it; the shopper
     * presses Place order. Nothing is written, the answer says why in the
     * shape the overlay and the card form already read (a sentence and a link
     * back to the checkout), the checkout then draws 89 with the note, and the
     * next press places at 89 -- the total every gateway is sent.
     *
     * MUTATION: delete the pricesMovedSinceDrawn() refusal in place() and the
     * first press writes an order at 8900 the page never showed -- red.
     */
    rprfShip();
    $mist = rprfProduct('Mist', 8500);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);

    rprfAs($cart)->get('/checkout')->assertOk()->assertDontSee('Price updated from');

    rprfReprice($mist, 8900);

    $answer = rprfAs($cart)->postJson('/checkout/place', rprfPlaceBody())->assertStatus(422);
    expect($answer->json('ok'))->toBeFalse()
        ->and($answer->json('code'))->toBe('bag_gone')
        ->and($answer->json('reason'))->toBe('prices_changed')
        ->and($answer->json('error'))->toContain('nothing has been charged')
        ->and($answer->json('url'))->toEndWith('/checkout/');
    expect(Order::count())->toBe(0);
    expect(rprfUnit($cart))->toBe(8900);

    rprfAs($cart)->get('/checkout')->assertOk()->assertSee('Price updated from AED 85 to AED 89');

    $placed = rprfAs($cart)->postJson('/checkout/place', rprfPlaceBody());
    $placed->assertOk();
    expect(Order::count())->toBe(1)
        ->and((int) Order::first()->total)->toBe(8900 + 2000);
});

it('refuses too when the price moved in the panel on another page that the checkout never showed', function () {
    /*
     * Another tab: the panel on a product page refreshed the basket and wrote
     * 89, so place() itself finds nothing to move -- and would charge 89 under
     * a checkout drawn at 85. The prices the checkout drew, kept in the
     * session, are what catch it. MUTATION: refuse only when place()'s own
     * refresh moved something and the order is written -- red.
     */
    rprfShip();
    $mist = rprfProduct('Mist', 8500);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);
    rprfAs($cart)->get('/checkout')->assertOk();

    rprfReprice($mist, 8900);
    rprfAs($cart)->get('/')->assertOk()->assertSee('Price updated from AED 85 to AED 89');
    expect(rprfUnit($cart))->toBe(8900);

    rprfAs($cart)->post('/checkout/place', rprfPlaceBody())->assertRedirect();
    expect(Order::count())->toBe(0);
});

it('places a basket no checkout has drawn straight at today\'s price', function () {
    /*
     * Nothing on a page to contradict, so nothing to refuse: the order is
     * written at the price adding it now would charge, never the snapshot.
     * MUTATION: write the order from the line before refreshPrices() -- red.
     */
    rprfShip();
    $mist = rprfProduct('Mist', 8500);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);
    rprfReprice($mist, 8900);

    rprfAs($cart)->post('/checkout/place', rprfPlaceBody())->assertRedirect();

    expect((int) Order::latest('id')->firstOrFail()->items->first()->unit_price)->toBe(8900);
});

/* ═══════════════════════════ what it leaves alone ════════════════════════ */

it('keeps free gifts at AED 0 on the order whatever the gift product costs today', function () {
    /* MUTATION: price the gift lines through unitPriceFor() in place() -- red. */
    rprfShip();
    $gift = rprfProduct('Gift Mini', 3000);
    DB::table('free_gift_ranges')->delete();
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => 100, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا',
        'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $gift->id, 'quantity' => 1, 'sort' => 0]);
    app(FreeGifts::class)->compile();
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    $serum = rprfProduct('Serum', 15000);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $serum, 1);
    rprfReprice($serum, 16000);
    rprfReprice($gift, 3500);

    rprfAs($cart)->get('/checkout')->assertOk();
    rprfAs($cart)->post('/checkout/place', rprfPlaceBody())->assertRedirect();

    $order = Order::latest('id')->firstOrFail();
    expect($order->gifts)->toHaveCount(1)
        ->and((int) $order->gifts->first()->unit_price)->toBe(0)
        ->and((int) $order->gifts->first()->total)->toBe(0)
        ->and((int) $order->total)->toBe(16000 + 2000);
});

it('leaves an out-of-stock or unpublished line, and a zero answer, exactly as the cart had it', function () {
    /* MUTATION: drop the instock test in refreshPrices() -- the first case is red. */
    $gone = rprfProduct('Sold Out', 5000);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $gone, 1);

    $gone->forceFill(['price' => 6000, 'stock_status' => 'outofstock'])->save();
    app(CartService::class)->refreshPrices(Cart::find($cart->id));
    expect(rprfUnit($cart))->toBe(5000);

    $gone->forceFill(['stock_status' => 'instock', 'status' => 'draft'])->save();
    app(CartService::class)->forget();
    app(CartService::class)->refreshPrices(Cart::with('items.product', 'items.variant')->find($cart->id));
    expect(rprfUnit($cart))->toBe(5000);

    $gone->forceFill(['status' => 'publish', 'price' => 0])->save();
    app(CartService::class)->refreshPrices(Cart::with('items.product', 'items.variant')->find($cart->id));
    expect(rprfUnit($cart))->toBe(5000);
});

it('prices a buy-together bundle off the refreshed line exactly as it prices a fresh one', function () {
    /*
     * The bundle is a percentage of unit_price computed in totals(), so a
     * repriced line takes its bundle share at the new price -- the same
     * answer a basket added today gets. MUTATION: clear bt_group in the
     * refresh write and the percent row disappears -- red.
     */
    app(BuyTogetherSettings::class)->save(['on' => true, 'discount_on' => true, 'count' => 4, 'tier_3' => 5, 'tier_4' => 10, 'tier_5' => 15]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $a = rprfProduct('A', 5000);
    $b = rprfProduct('B', 6000);
    $c = rprfProduct('C', 7000);

    $old = rprfCart();
    $ids = [];
    foreach ([$a, $b, $c] as $p) {
        $ids[] = app(CartService::class)->add($old, $p, 1)->id;
    }
    app(CartService::class)->group($old, $ids);

    rprfReprice($a, 5500);
    app(CartService::class)->forget();
    $oldCart = Cart::find($old->id);
    app(CartService::class)->refreshPrices($oldCart);

    $fresh = rprfCart();
    $fids = [];
    foreach ([Product::find($a->id), $b, $c] as $p) {
        $fids[] = app(CartService::class)->add($fresh, $p, 1)->id;
    }
    app(CartService::class)->group($fresh, $fids);

    $t1 = app(CartService::class)->totals(Cart::find($old->id), 'AE');
    $t2 = app(CartService::class)->totals(Cart::find($fresh->id), 'AE');

    expect($t1['subtotal'])->toBe(5500 + 6000 + 7000)
        ->and($t1['bundle_discount'])->toBeGreaterThan(0)
        ->and($t1['bundle_discount'])->toBe($t2['bundle_discount'])
        ->and($t1['total'])->toBe($t2['total']);
});

/* ═════════════════════════════ the cost ══════════════════════════════════ */

it('costs no query when nothing moved and one statement when any number of lines moved', function () {
    /*
     * Lines already loaded by every reader: the comparison is in memory. A
     * variant line included, because ProductVariant::effectivePrice() reads its
     * parent and that is the per-line query this must not grow.
     *
     * MUTATION: drop the setRelation('product', ...) line in refreshPrices()
     * and the variant costs a query per read -- the "nothing moved" count is
     * red; write the moved lines one save() each and the 3-moved case is red.
     */
    rprfShip();
    $products = [rprfProduct('One', 5000), rprfProduct('Two', 6000), rprfProduct('Three', 7000)];
    $parent = rprfProduct('Shade Parent', 9000, ['type' => 'variable']);
    $shade = ProductVariant::create(['product_id' => $parent->id, 'sku' => 'RP-SHADE-1', 'price' => 9000, 'stock_status' => 'instock']);

    $cart = rprfCart();
    foreach ($products as $p) {
        app(CartService::class)->add($cart, $p, 1);
    }
    app(CartService::class)->add($cart, Product::find($parent->id), 1, $shade);

    foreach (['/cart', '/checkout'] as $path) {
        rprfQueries($cart, $path);                       // warm: nothing to move
        $still = rprfQueries($cart, $path);

        rprfReprice($products[0], (int) Product::find($products[0]->id)->price + 100);
        $oneMoved = rprfQueries($cart, $path);

        foreach ($products as $p) {
            rprfReprice($p, (int) Product::find($p->id)->price + 100);
        }
        $shade->forceFill(['price' => (int) $shade->fresh()->price + 100])->save();
        $allMoved = rprfQueries($cart, $path);

        $again = rprfQueries($cart, $path);

        expect($oneMoved)->toBe($still + 1, $path . ': one UPDATE for one moved line')
            ->and($allMoved)->toBe($still + 1, $path . ': still one UPDATE for four moved lines')
            ->and($again)->toBe($still, $path . ': nothing moved, nothing added');
    }
});

/* ═════════════════════════════ the words ═════════════════════════════════ */

it('prints nothing at all when no price moved, on the panel, the cart page and the checkout', function () {
    /*
     * The page a shopper gets on every day the owner does not touch a price.
     * MUTATION: print the note's wrapper unconditionally -- red.
     */
    rprfShip();
    $cart = rprfCart();
    app(CartService::class)->add($cart, rprfProduct('Mist', 8500), 1);

    foreach (['/', '/cart', '/checkout'] as $path) {
        rprfAs($cart)->get($path)->assertOk()->assertDontSee('Price updated');
    }
    expect(app(CartService::class)->priceNoteHtml(Cart::find($cart->id)->items->first(), 'cvar'))->toBe('');
});

it('says it once on each surface, in the line\'s own small text, and then stops', function () {
    /* MUTATION: never call takePriceNotes() and the third /cart still shows it -- red. */
    $mist = rprfProduct('Mist', 8500);
    $cart = rprfCart();
    app(CartService::class)->add($cart, $mist, 1);
    rprfReprice($mist, 8900);

    rprfAs($cart)->get('/')->assertOk()
        ->assertSee('<div class="kc-var" role="status">Price updated from AED 85 to AED 89</div>', false);
    rprfAs($cart)->get('/cart')->assertOk()
        ->assertSee('<div class="cvar" role="status">Price updated from AED 85 to AED 89</div>', false);
    rprfAs($cart)->get('/cart')->assertOk()->assertDontSee('Price updated from');
});

it('ships the Arabic beside the English, and the migration carries the class\'s own words', function () {
    /* MUTATION: change one Arabic sentence in ArabicWording and not the migration -- red. */
    $m = require database_path('migrations/2028_01_19_100000_arabic_cart_price_updated.php');
    $rows = (new ReflectionClassConstant($m, 'ROWS'))->getValue();

    foreach (['price_updated', 'msg_prices_changed', 'price_review_link'] as $k) {
        expect($rows['store.wording.' . $k])->toBe([
            \App\Support\ArabicWording::ENGLISH['wording.' . $k],
            \App\Support\ArabicWording::ARABIC['wording.' . $k],
        ]);
    }

    expect(__('store.wording.price_updated', ['from' => 'AED 85', 'to' => 'AED 89']))->toBe('Price updated from AED 85 to AED 89');
});
