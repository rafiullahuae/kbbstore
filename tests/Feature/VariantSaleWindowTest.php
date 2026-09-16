<?php

/**
 * A variant's sale price obeys the sale WINDOW, exactly as a simple product's
 * does.
 *
 * THE DEFECT THIS PINS.
 *
 * ProductVariant::effectivePrice() was:
 *
 *     return (int) ($this->sale_price ?? $this->price ?? $this->product?->effectivePrice() ?? 0);
 *
 * — `sale_price` returned unconditionally, with no reference to any schedule.
 *
 * `product_variants` has `price` and `sale_price` and NO date columns (see the
 * table in 0001_01_01_000000_create_kbb_schema.php). The only sale schedule
 * that exists for a variable product is the one on its parent `products` row,
 * and this method never looked at it. So on every variable product:
 *
 *   - a sale that ENDED kept selling at the sale price, forever, while the
 *     product page, the shop filters and the sale badge had all reverted to
 *     full price;
 *   - a sale scheduled to START next week sold at the discount today.
 *
 * That is the identical defect the rest of the codebase has already been
 * repaired for twice, in writing:
 *
 *   - Api\CheckoutController: "effectivePrice(), not sale_price ?? price. The
 *     raw column ignores sale_starts_at and sale_ends_at, so an expired sale
 *     kept selling at the sale price and a future one sold early. Money,
 *     quietly, in both directions."
 *   - Support\EffectivePrice, which mirrors Product::effectivePrice()
 *     "condition for condition" so the shop's sort and filter cannot disagree
 *     with the card.
 *
 * ProductVariant was the one place left answering a third way — and it is the
 * one that sets `cart_items.unit_price`, which becomes `order_items.unit_price`
 * and `orders.subtotal`. Unlike a sort order, this one is charged.
 */

use App\Models\Product;
use App\Models\ProductVariant;

/** A variable product whose parent carries the sale schedule. */
function variantOnSale(?string $startsAt, ?string $endsAt): ProductVariant
{
    $product = Product::create([
        'slug' => 'variable-' . uniqid(),
        'name' => 'Variable Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 20000,          // AED 200
        'sale_price' => 10000,     // AED 100
        'sale_starts_at' => $startsAt,
        'sale_ends_at' => $endsAt,
        'stock_status' => 'instock',
    ]);

    return ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'VAR-50ML',
        'price' => 20000,
        'sale_price' => 10000,
        'stock_status' => 'instock',
        'position' => 0,
    ]);
}

it('charges the full price for a variant whose sale has ended', function () {
    $variant = variantOnSale(now()->subMonth()->toDateTimeString(), now()->subDay()->toDateTimeString());

    // The parent product has already reverted — that is the price the card,
    // the shop filter and the sale badge all show.
    expect($variant->product->effectivePrice())->toBe(20000);

    expect($variant->effectivePrice())
        ->toBe(20000, 'a variant kept selling at the sale price after the sale ended');
});

it('charges the full price for a variant whose sale has not started', function () {
    $variant = variantOnSale(now()->addWeek()->toDateTimeString(), now()->addMonth()->toDateTimeString());

    expect($variant->product->effectivePrice())->toBe(20000);

    expect($variant->effectivePrice())
        ->toBe(20000, 'a variant sold at next week\'s sale price today');
});

it('charges the sale price for a variant whose sale is running', function () {
    $variant = variantOnSale(now()->subDay()->toDateTimeString(), now()->addWeek()->toDateTimeString());

    expect($variant->product->effectivePrice())->toBe(10000)
        ->and($variant->effectivePrice())->toBe(10000);
});

it('charges the sale price for a variant whose sale has no window at all', function () {
    // No dates means an open-ended sale — the same reading Product::
    // effectivePrice() gives a null window. Nothing about the ordinary,
    // unscheduled markdown may change.
    $variant = variantOnSale(null, null);

    expect($variant->product->effectivePrice())->toBe(10000)
        ->and($variant->effectivePrice())->toBe(10000);
});

it('falls back to the variant price when it carries no sale of its own', function () {
    $product = Product::create([
        'slug' => 'variable-nosale-' . uniqid(),
        'name' => 'Plain Variable',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 20000,
        'stock_status' => 'instock',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'price' => 17500,
        'stock_status' => 'instock',
        'position' => 0,
    ]);

    expect($variant->effectivePrice())->toBe(17500);
});

it('falls back to the parent product when the variant is priced by neither column', function () {
    $product = Product::create([
        'slug' => 'variable-inherit-' . uniqid(),
        'name' => 'Inheriting Variable',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 20000,
        'sale_price' => 12000,
        'stock_status' => 'instock',
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'stock_status' => 'instock',
        'position' => 0,
    ]);

    expect($variant->effectivePrice())->toBe(12000);
});

it('puts the post-sale price on the cart line, not the expired one', function () {
    // The money path end to end: CartService::add() snapshots
    // ProductVariant::effectivePrice() onto cart_items.unit_price, which
    // becomes order_items.unit_price and orders.subtotal.
    $variant = variantOnSale(now()->subMonth()->toDateTimeString(), now()->subDay()->toDateTimeString());
    $product = $variant->product;

    $cart = app(\App\Services\CartService::class)->create();
    $item = app(\App\Services\CartService::class)->add($cart, $product, 2, $variant);

    expect($item->unit_price)->toBe(20000, 'the expired sale price was snapshotted onto the cart line')
        ->and($item->lineTotal())->toBe(40000);
});
