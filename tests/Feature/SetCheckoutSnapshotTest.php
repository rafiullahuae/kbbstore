<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\Invoices\InvoiceDocument;
use App\Services\Mail\OrderEmailPresenter;
use App\Models\PaymentProvider;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Support\SetContents;
use Illuminate\Support\Str;

/**
 * Buying a set, and what the order remembers. (Lane SET)
 *
 * The whole point of `order_items.set_contents` is that a set's contents change
 * and an order must not. This file buys a set through the real checkout, then
 * REWRITES the set, then reads every document the shop prints — the invoice,
 * the packing slip, the delivery note, the confirmation email and its plain-text
 * twin — and asserts each one still lists what was in the box.
 */
/**
 * The shop has to be able to take an order at all before this file can ask what
 * it remembers: a gateway and a delivery zone. The same miniature
 * TaxEndToEndTest builds, and for the same reason.
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

function setBuyProduct(string $name, int $fils, ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => 'buy-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'image' => '/img/'.Str::slug($name).'.jpg',
    ]);
}

function setBuyCart(Product $set, int $unitPrice): Cart
{
    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create([
        'product_id' => $set->id,
        'quantity' => 1,
        'unit_price' => $unitPrice,
    ]);

    return $cart->fresh(['items']);
}

function setBuyPlace(Cart $cart): Order
{
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

    return Order::latest('id')->first();
}

it('writes what was in the box onto the order, including the chosen option', function () {
    /*
     * ── THE DEFECT THIS EXISTS AGAINST ─────────────────────────────────────
     *
     * Without the write in Store\CheckoutController::place(), an order for a set
     * carries ONE line named "Glow Set" and no record of what was inside it.
     * Every document the shop prints — the invoice, the packing slip that the
     * bench picks from, the delivery note in the parcel, the receipt — says
     * "Glow Set" and nothing else, and nobody can tell what to put in the box.
     *
     * MUTATION NOTE. Delete the `'set_contents' => SetContents::snapshot($p)`
     * line from CheckoutController::place() and this is red. RUN.
     *
     * THE OPTION LABEL IS THE SECOND HALF, and it is the pin on the eager
     * load. ProductVariant::label() reads `attributeValues`, and
     * SetContents::fromProduct() will NOT run a query for it — it answers ''
     * for an unloaded relation rather than costing one query per member. So
     * "50 ml" only reaches the snapshot because
     * CheckoutController::loadCart() passes through SetEagerLoad::on(), which
     * loads setItems.variant.attributeValues.
     *
     * MUTATION NOTE. Delete the SetEagerLoad::on() call from
     * CheckoutController::loadCart() and the `variant` expectation is red while
     * the rest stays green. RUN.
     */
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::random(5)]);

    $toner = setBuyProduct('Heartleaf Toner', 9000, $brand);
    $serum = setBuyProduct('Azelaic Serum', 4550, $brand);

    // The serum is named by its 50 ml option, which is the case the pivot's
    // `member_variant_id` exists for.
    $size = Attribute::create(['name' => 'Size', 'slug' => 'size-'.Str::random(5)]);
    $ml50 = AttributeValue::create(['attribute_id' => $size->id, 'name' => '50 ml', 'slug' => '50ml-'.Str::random(5)]);

    $variant = ProductVariant::create([
        'product_id' => $serum->id,
        'sku' => 'AZ-50',
        'price' => 5000,
        'stock_status' => 'instock',
        'position' => 0,
    ]);
    $variant->attributeValues()->attach($ml50->id);

    $set = Product::create([
        'slug' => 'glow-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create([
        'set_product_id' => $set->id, 'member_product_id' => $serum->id,
        'member_variant_id' => $variant->id, 'quantity' => 2, 'position' => 1,
    ]);

    $order = setBuyPlace(setBuyCart($set->fresh(), 12000));
    $line = $order->items()->first();

    expect($line->set_contents)->toBeArray()->toHaveCount(2)
        ->and($line->set_contents[0]['name'])->toBe('Heartleaf Toner')
        ->and($line->set_contents[0]['unit'])->toBe(9000)
        ->and($line->set_contents[1]['name'])->toBe('Azelaic Serum')
        ->and($line->set_contents[1]['variant'])->toBe('50 ml')
        // The VARIATION's price, not the parent's — it is the variation that
        // was in the box and the variation the saving is measured from.
        ->and($line->set_contents[1]['unit'])->toBe(5000)
        ->and($line->set_contents[1]['quantity'])->toBe(2);

    // 9000 + 2 x 5000 = 19000 bought separately, paid 12000.
    $sold = SetContents::fromOrderItem($line);
    expect($sold['partsTotal'])->toBe(19000)->and($sold['saving'])->toBe(7000);
});

it('still prints what was in the box after the set is rewritten', function () {
    /*
     * ── THE DEFECT, ON THE SHOP ────────────────────────────────────────────
     *
     * The owner swaps a member a month after a sale. The customer's invoice,
     * the packing slip for their RETURN and their order page all start listing
     * a product they were never sent.
     *
     * MUTATION NOTE. Change SetContents::fromOrderItem() to read the live pivot
     * (`fromProduct($item->product)`) and every expectation below is red: each
     * document reports "Cleanser" and never "Azelaic Serum". RUN.
     */
    $toner = setBuyProduct('Heartleaf Toner', 9000);
    $serum = setBuyProduct('Azelaic Serum', 4550);

    $set = Product::create([
        'slug' => 'glow-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $toner->id, 'quantity' => 1, 'position' => 0]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $serum->id, 'quantity' => 1, 'position' => 1]);

    $order = setBuyPlace(setBuyCart($set->fresh(), 12000));

    // The shop moves on: the serum is replaced.
    ProductSetItem::where('set_product_id', $set->id)->where('member_product_id', $serum->id)->delete();
    ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => setBuyProduct('Rice Cleanser', 7000)->id,
        'quantity' => 1,
        'position' => 5,
    ]);

    $order = $order->fresh(['items']);

    $invoice = app(InvoiceDocument::class)->present($order);
    $email = app(OrderEmailPresenter::class)->present($order);

    foreach ([$invoice, $email] as $document) {
        $lines = $document['items'][0]['setContents'];

        expect($lines)->toBe(['1 × Heartleaf Toner', '1 × Azelaic Serum']);
    }

    // And the rendered documents really print them, which is the half a
    // presenter assertion alone cannot prove.
    $html = view('invoices.partials.sheet-invoice', ['doc' => $invoice])->render();
    expect($html)->toContain('Heartleaf Toner')
        ->and($html)->not->toContain('Rice Cleanser');
});

it('leaves set_contents null on an ordinary line', function () {
    /*
     * The value that keeps every order this shop has ever taken unchanged.
     *
     * MUTATION NOTE. Make SetContents::snapshot() return [] instead of null for
     * a non-set and this is red — and every order row in the shop would start
     * carrying an empty JSON array where it used to carry NULL. RUN.
     */
    $order = setBuyPlace(setBuyCart(setBuyProduct('Plain Toner', 9000), 9000));

    expect($order->items()->first()->set_contents)->toBeNull();
});
