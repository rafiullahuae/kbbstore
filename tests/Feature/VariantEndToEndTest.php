<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Translation\TranslationStore;
use App\Support\VariantText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;
use Tests\Support\VpaCatalog;

/*
 * Lane VPA: "update everywhere" -- the chosen option from the basket to the
 * order, its documents, the admin and the owner app.
 */

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
});

function vpaVariant(string $sku): ProductVariant
{
    return ProductVariant::query()->where('sku', $sku)->firstOrFail();
}

function vpaCart(array $lines): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $variant, $price]) {
        $cart->items()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant?->id,
            'quantity' => 1, 'unit_price' => $price,
        ]);
    }

    return $cart;
}

function vpaPlace(Cart $cart, string $prefix = ''): ?Order
{
    test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post($prefix.'/checkout/place', [
            'billing_email' => 'layla@example.com', 'billing_phone' => '+971501234567',
            'billing_first_name' => 'Layla', 'billing_last_name' => 'Al Mansoori',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ]);

    return Order::latest('id')->first();
}

function vpaAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'VPA '.$role, 'email' => 'vpa-'.$role.'-'.uniqid().'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

/* ── 1. the basket ─────────────────────────────────────────────────────── */

it('names the chosen shade on the cart page, the mini cart and the checkout', function () {
    /*
     * THE DEFECT: the cart page printed "#21 Light Beige" with nothing saying
     * it was a shade, and the mini cart and the checkout summary printed
     * nothing at all -- a shopper with two shades of one cushion in the bag
     * could not tell the lines apart. MUTATION: make VariantText::forVariant()
     * return '' and all three are red.
     */
    $c = VpaCatalog::seed();
    $cart = vpaCart([[$c['missha'], vpaVariant('MS-BB-2'), 12500]]);
    $cookie = fn () => $this->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token);

    expect($cookie()->get('/cart')->assertOk()->getContent())->toContain('<div class="cvar">Shade: #21 Light Beige</div>')
        ->and($cookie()->get('/api/cart/drawer')->getContent())->toContain('kc-var')
        ->and($cookie()->get('/checkout')->getContent())->toContain('<div class="co-var">Shade: #21 Light Beige</div>');
});

/* ── 2. the server decides ─────────────────────────────────────────────── */

it('prices a line from the server, and refuses a sold-out, foreign, missing or absent option', function () {
    /*
     * THE DEFECT THIS PINS: /api/cart/add is public. A price, a sold-out
     * shade, another product's shade or no shade at all must never get in.
     * MUTATION: drop the stock check in CartController::add() and the
     * sold-out line is 200.
     */
    $c = VpaCatalog::seed();
    $post = fn (array $body) => $this->postJson('/api/cart/add', $body);

    $post(['product_id' => $c['tirtir']->id, 'variant_id' => vpaVariant('TT-MF-17CPorcelain')->id])->assertStatus(422);
    $post(['product_id' => $c['tirtir']->id, 'variant_id' => vpaVariant('MS-BB-1')->id])->assertStatus(404);
    $post(['product_id' => $c['tirtir']->id, 'variant_id' => 999999])->assertStatus(404);
    $post(['product_id' => $c['tirtir']->id])->assertStatus(422);

    $ok = $post(['product_id' => $c['tirtir']->id, 'variant_id' => vpaVariant('TT-MF-23NSand')->id, 'price' => 1]);
    $ok->assertOk();

    // The response is HTML fragments and totals -- no model, no SKU, no wc_id.
    expect(array_keys($ok->json()))->not->toContain('items')
        ->and(json_encode($ok->json()))->not->toContain('wc_id')
        ->and(DB::table('cart_items')->latest('id')->value('unit_price'))->toBe(12900);
});

/* ── 3. the order and its documents ────────────────────────────────────── */

it('snapshots the shade keyed by its axis, and every document prints it', function () {
    /*
     * THE DEFECT: order_items.variant_attributes was ["#13 Bright Beige"],
     * so the confirmation, the invoice and the review request said a name
     * with nothing saying it was a shade. MUTATION: write
     * attributeValues->pluck('name') back in CheckoutController and the
     * first expectation is red.
     */
    $c = VpaCatalog::seed();
    $order = vpaPlace(vpaCart([[$c['missha'], vpaVariant('MS-BB-1'), 13900]]));
    $item = $order->items()->first();

    expect($item->variant_attributes)->toBe(['Shade' => '#13 Bright Beige'])
        ->and($item->sku)->toBe('MS-BB-1')
        ->and($item->product_variant_id)->toBe(vpaVariant('MS-BB-1')->id);

    $mail = (new \App\Mail\OrderConfirmation($order->fresh()))->render();
    $invoice = app(\App\Services\Invoices\InvoiceDocument::class)->present($order->fresh());

    expect($mail)->toContain('Shade: #13 Bright Beige')
        ->and($invoice['items'][0]['variant'])->toBe('Shade: #13 Bright Beige')
        ->and($invoice['items'][0]['variantForCustomer'])->toBe('Shade: #13 Bright Beige')
        ->and((new \App\Mail\NewOrderAlert($order->fresh()))->render())->toContain('Shade: #13 Bright Beige');
})->skip(fn () => ! class_exists(\App\Mail\OrderConfirmation::class), 'no confirmation mailable');

it('prints an older order\'s plain list exactly as before', function () {
    /*
     * THE DEFECT THIS GUARDS: every order placed before this package (and
     * every WooCommerce import) holds a plain list. It must read as it did.
     * MUTATION: drop the is_string($key) test in VariantText::line() and an
     * old list prints "0: #13 Bright Beige".
     */
    expect(VariantText::line(['#13 Bright Beige', '50ml'], ', '))->toBe('#13 Bright Beige, 50ml')
        ->and(VariantText::line(['Shade' => '#13 Bright Beige', 'Size' => '50 ml'], ' · '))->toBe('Shade: #13 Bright Beige · Size: 50 ml')
        ->and(VariantText::line(null))->toBe('')
        ->and(VariantText::line('["#21"]'))->toBe('#21');
});

it('takes the stock off the chosen shade and gives it back on cancel', function () {
    /*
     * THE DEFECT THIS GUARDS: stock is per option. Selling #21 must not
     * touch #13, and a cancellation returns #21's unit to #21.
     * MUTATION: claim against the parent in CartService::claimLine() and the
     * shade's count does not move.
     */
    $c = VpaCatalog::seed();
    $v = vpaVariant('MS-BB-2');
    $other = vpaVariant('MS-BB-1');

    $order = vpaPlace(vpaCart([[$c['missha'], $v, 12500]]));

    expect((int) $v->fresh()->stock)->toBe(11)
        ->and((int) $other->fresh()->stock)->toBe(12);

    app(\App\Services\Orders\OrderTransitionStock::class)->applied((int) $order->id, (string) $order->status, 'cancelled');

    expect((int) $v->fresh()->stock)->toBe(12);
});

it('writes the Arabic order\'s shade in Arabic, and its emails print it', function () {
    /*
     * THE DEFECT: an /ar order's confirmation printed the Arabic product name
     * over "Shade: #13 Bright Beige" in English. OrderLocale snapshots the
     * option in the order's language beside name_localised.
     * MUTATION: remove self::localiseVariant() from OrderLocale::listen()
     * and variant_localised stays NULL.
     */
    ArabicShop::on();
    $c = VpaCatalog::seed();
    $value = vpaVariant('MS-BB-1')->attributeValues()->first();
    TranslationStore::put('ar', 'attribute_values', (int) $value->id, 'name', '#13 بيج فاتح', \App\Models\Translation::STATUS_PUBLISHED);
    TranslationStore::flush();

    $order = vpaPlace(vpaCart([[$c['missha'], vpaVariant('MS-BB-1'), 13900]]), '/ar');
    $item = $order->items()->first();

    expect($order->locale)->toBe('ar')
        ->and($item->variant_attributes)->toBe(['Shade' => '#13 Bright Beige'])
        ->and($item->variant_localised)->toBe(['الدرجة' => '#13 بيج فاتح'])
        ->and(VariantText::forOrderItem($item, true))->toBe("الدرجة: \u{2068}#13 بيج فاتح\u{2069}")
        ->and(VariantText::forOrderItem($item, false))->toBe('Shade: #13 Bright Beige');
});

/* ── 4. the admin ──────────────────────────────────────────────────────── */

it('shows the shade on the admin order screen and finds the order by its SKU', function () {
    /*
     * THE DEFECT: Store -> Orders -> (an order) listed "MISSHA BB Cream" and
     * not which shade went in the parcel. MUTATION: drop 'variant' from
     * AdminOrderController::show() and the first line is red.
     */
    $c = VpaCatalog::seed();
    $order = vpaPlace(vpaCart([[$c['tirtir'], vpaVariant('TT-MF-21NIvory'), 14500]]));
    $admin = vpaAdmin();

    $detail = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders/'.$order->id.'/detail')->assertOk()->json();

    expect($detail['items'][0]['variant'])->toBe('Shade: 21N Ivory');

    $list = $this->actingAs($admin, 'admin')->getJson('/admin-api/orders-list?search=TT-MF-21N')->assertOk()->json();
    expect(collect($list['orders'] ?? $list['rows'] ?? [])->pluck('id')->all())->toContain($order->id);
});

it('asks for the option when a variable product is added to an order, and prices it from the option', function () {
    /*
     * THE DEFECT: adding a variable product on the order screen used
     * `sale_price ?: price` of the PARENT -- NULL -- so the line went in at
     * AED 0 with no shade. MUTATION: remove the requiresVariant() branch in
     * AdminOrderController::addItem() and the first call is 200 at zero.
     */
    $c = VpaCatalog::seed();
    $order = vpaPlace(vpaCart([[$c['missha'], vpaVariant('MS-BB-1'), 13900]]));
    $admin = vpaAdmin();

    $ask = $this->actingAs($admin, 'admin')->postJson('/admin-api/orders/'.$order->id.'/items', ['product_id' => $c['tirtir']->id, 'quantity' => 1]);
    $ask->assertStatus(422)->assertJson(['needs_variant' => true]);
    expect(collect($ask->json('options'))->firstWhere('label', 'Shade: 17C Porcelain')['in_stock'])->toBeFalse();

    $this->actingAs($admin, 'admin')->postJson('/admin-api/orders/'.$order->id.'/items', [
        'product_id' => $c['tirtir']->id, 'variant_id' => vpaVariant('MS-BB-2')->id, 'quantity' => 1,
    ])->assertStatus(422);

    $this->actingAs($admin, 'admin')->postJson('/admin-api/orders/'.$order->id.'/items', [
        'product_id' => $c['tirtir']->id, 'variant_id' => vpaVariant('TT-MF-23NSand')->id, 'quantity' => 1,
    ])->assertOk();

    $line = $order->items()->latest('id')->first();
    expect($line->unit_price)->toBe(12900)
        ->and($line->sku)->toBe('TT-MF-23NSand')
        ->and($line->variant_attributes)->toBe(['Shade' => '23N Sand']);
});

it('offers each option, named, to the manual order picker', function () {
    /*
     * THE DEFECT: Store -> New Order offered a variable product as one row,
     * and ManualOrderBuilder then refused the whole order for want of an
     * option. MUTATION: drop 'label' from the variants map in
     * AdminOrderController::products() and this is red.
     */
    $c = VpaCatalog::seed();
    $res = $this->actingAs(vpaAdmin(), 'admin')->getJson('/admin-api/manual-orders/products?q=TIRTIR')->assertOk()->json('products');
    $tt = collect($res)->firstWhere('id', $c['tirtir']->id);

    expect(collect($tt['variants'])->pluck('label')->all())->toContain('Shade: 21N Ivory')
        ->and(collect($tt['variants'])->firstWhere('label', 'Shade: 23N Sand')['price_fils'])->toBe(12900);
});

/* ── 5. the owner app and the alerts ───────────────────────────────────── */

it('names the shade in the owner app\'s low-stock alert', function () {
    /*
     * THE DEFECT: "Low stock: TIRTIR Mask Fit Red Cushion" -- which of nine?
     * MUTATION: drop the $option suffix in OwnerAppEvents::lowStockAfter().
     */
    $c = VpaCatalog::seed();
    \App\Models\Setting::query()->updateOrCreate(['key' => 'owner_app_low_stock'], ['value' => '20', 'autoload' => true]);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    vpaPlace(vpaCart([[$c['tirtir'], vpaVariant('TT-MF-21NIvory'), 14500]]));

    $titles = DB::table('owner_app_events')->whereIn('type', ['stock.low', 'stock.out'])->pluck('title')->implode(' | ');
    expect($titles)->toContain('Shade: 21N Ivory');
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('owner_app_events'), 'owner app not migrated');

/* ── 6. Arabic and SEO ─────────────────────────────────────────────────── */

it('draws the picker in Arabic on /ar, with the axis named in Arabic', function () {
    /*
     * THE DEFECT THIS GUARDS: "Shade:" in English above Arabic swatches.
     * MUTATION: drop the AXIS_AR map in VariantOptions and the Arabic page
     * prints "Shade:".
     */
    ArabicShop::on();
    VpaCatalog::seed();

    $html = $this->get('/ar/product/vpa-missha/')->assertOk()->getContent();

    expect($html)->toContain('الدرجة: <b dir="auto">#13 Bright Beige</b>')
        ->and($html)->toContain(e(__('store.product.stock_in', [], 'ar')));
});

it('adds no address of its own: no ?shade= link, the canonical is the product', function () {
    /*
     * THE DEFECT THIS GUARDS: a picker that writes ?shade= into links makes
     * nine indexable copies of one product. The choice travels in
     * sessionStorage instead. MUTATION: append '?v=' to the card's links.
     */
    VpaCatalog::seed();

    $page = $this->get('/product/vpa-tirtir/')->getContent();
    $grid = $this->get('/collections/vpa-makeup/')->getContent();

    expect($page.$grid)->not->toMatch('#href="[^"]*/product/[^"]*[?&](shade|v|variant|variation_id)=#')
        ->and($page)->toMatch('#<link rel="canonical" href="[^"]*/product/vpa-tirtir/"#');
});

/* ── 7. the cached card ────────────────────────────────────────────────── */

it('draws a new card the moment a shade sells out', function () {
    /*
     * THE DEFECT THIS GUARDS: CardFragments caches a card's HTML; a sold-out
     * shade must not keep a live dot. MUTATION: drop the swatch signature
     * from CardFragments::signature() and the second render is the first.
     */
    $c = VpaCatalog::seed();
    $first = \App\Support\CardFragments::many([$c['missha']->fresh()])[$c['missha']->id];
    vpaVariant('MS-BB-3')->update(['stock_status' => 'outofstock']);
    $second = \App\Support\CardFragments::many([$c['missha']->fresh()])[$c['missha']->id];

    expect($first)->not->toContain('class="vpc-sw out"')
        ->and($second)->toContain('class="vpc-sw out"');
});
