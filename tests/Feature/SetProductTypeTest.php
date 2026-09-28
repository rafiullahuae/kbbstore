<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\SetContents;

/**
 * The Set: the shape, and the arithmetic. (Lane SET)
 *
 * A Set IS a row in `products` with `type = 'set'`, plus rows in
 * `product_set_items`. This file pins the three things that make that safe:
 *
 *   1. the type really does not fall out of anything — a set is visible,
 *      buyable, and not treated as a variable parent;
 *   2. every figure on the path is an INTEGER number of fils;
 *   3. a SOLD set reads its snapshot and never the live pivot.
 */
function setFixtureProduct(string $name, int $fils, ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => 'set-fx-'.\Illuminate\Support\Str::slug($name).'-'.uniqid(),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'image' => '/img/'.\Illuminate\Support\Str::slug($name).'.jpg',
    ]);
}

function setFixture(int $setFils, array $members): Product
{
    $set = Product::create([
        'slug' => 'set-'.uniqid(),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $setFils,
        'stock_status' => 'instock',
    ]);

    $position = 0;

    foreach ($members as [$product, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $product->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    return $set->fresh();
}

it('is an ordinary visible, directly buyable product with type set', function () {
    /*
     * THE ASSUMPTION THE WHOLE SHAPE RESTS ON, checked rather than asserted in
     * a comment. Only three places in this application branch on
     * `products.type` and none of them excludes an unknown value:
     *
     *   Product::requiresVariant()  `=== 'variable'`  -> false for a set
     *   VariantPricing              `!== 'variable'`  -> skipped
     *   Product::scopeVisible()     no type clause    -> a set publishes
     *
     * MUTATION NOTE. Change requiresVariant() to `!== 'simple'` — the exclusive
     * test the brief warned about — and the second and third expectations here
     * go red: a set would demand an option it does not have and could never be
     * added to a basket. RUN.
     */
    $set = setFixture(20000, [[setFixtureProduct('Toner', 9000), 1]]);

    expect($set->isSet())->toBeTrue()
        ->and($set->requiresVariant())->toBeFalse()
        ->and($set->isDirectlyBuyable())->toBeTrue()
        ->and(Product::visible()->whereKey($set->id)->exists())->toBeTrue()
        // A set's price is its own column, exactly like a simple product's.
        ->and($set->effectivePrice())->toBe(20000);
});

it('fails closed when the type column was not selected', function () {
    /*
     * Half this application hydrates explicit column lists because the
     * endpoints are public. A model loaded without `type` answers null for it,
     * and guessing "yes" there would draw a set row around an ordinary product
     * on whichever public endpoint forgot the column.
     *
     * MUTATION NOTE. Change isSet() to `$this->type === 'set'` — the accessor
     * rather than the attribute array — and this is still green, because the
     * accessor also answers null. Change it to `!== 'simple'` and it goes RED
     * immediately. The value of the guard is the narrowed-SELECT case below.
     * RUN.
     */
    $set = setFixture(20000, [[setFixtureProduct('Toner', 9000), 1]]);

    $narrow = Product::query()->select(['id', 'slug', 'name', 'price'])->find($set->id);

    expect($narrow->isSet())->toBeFalse();
});

it('computes the parts total, the item count and the saving in integer fils', function () {
    /*
     * 2 x 9000 + 1 x 4550 = 22550 bought separately; the set is 18000; the
     * saving is 4550. Every one of those is an int and the test asserts it —
     * `toBe` in Pest is strict, so a float 4550.0 fails here.
     *
     * MUTATION NOTE. Change SetContents::fromProduct() to build `$unit` with
     * `(float)` instead of `(int)` and the three toBeInt() expectations go red.
     * RUN.
     */
    $set = setFixture(18000, [
        [setFixtureProduct('Toner', 9000), 2],
        [setFixtureProduct('Serum', 4550), 1],
    ]);

    $contents = SetContents::fromProduct($set);

    expect($contents['count'])->toBe(3)
        ->and($contents['partsTotal'])->toBe(22550)
        ->and($contents['setPrice'])->toBe(18000)
        ->and($contents['saving'])->toBe(4550)
        ->and($contents['partsTotal'])->toBeInt()
        ->and($contents['saving'])->toBeInt()
        ->and($contents['members'][0]['unit'])->toBeInt();
});

it('never reports a negative saving when a set costs more than its parts', function () {
    /*
     * A set priced above its members is a pricing mistake to correct, not a
     * negative saving to print with a minus sign — and "You save AED -30" on
     * the shop is worse than saying nothing. The row only draws the saving when
     * it is positive, and this is the floor under that.
     *
     * MUTATION NOTE. Replace `max(0, $partsTotal - $setPrice)` with the bare
     * subtraction in BOTH fromProduct() and fromOrderItem() and this is red.
     * RUN.
     */
    $set = setFixture(30000, [[setFixtureProduct('Toner', 9000), 1]]);

    expect(SetContents::fromProduct($set)['saving'])->toBe(0);
});

it('reads a sold set from its snapshot and not from the pivot', function () {
    /*
     * ── THE DEFECT THIS EXISTS AGAINST, ON THE SHOP ────────────────────────
     *
     * A customer buys the Glow Set (toner + serum). A month later the owner
     * swaps the serum for a cleanser. The customer opens their order, or the
     * shop reprints the invoice, and it lists a cleanser they were never sent —
     * and the packing slip for a RETURN lists the wrong bottle.
     *
     * That is what happens if any of the order surfaces reaches back through
     * `product_set_items`. They read `order_items.set_contents`, which was
     * written on the day and is what this asserts.
     *
     * MUTATION NOTE. Change SetContents::fromOrderItem() to
     * `fromProduct($item->product)` and this is red on the last two
     * expectations: the order would report the cleanser and one member. RUN.
     */
    $toner = setFixtureProduct('Toner', 9000);
    $serum = setFixtureProduct('Serum', 4550);
    $set = setFixture(12000, [[$toner, 1], [$serum, 1]]);

    $order = Order::create([
        'order_number' => 'SET-'.uniqid(),
        'email' => 'buyer@example.test',
        'status' => 'processing',
        'subtotal' => 12000,
        'total' => 12000,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $set->id,
        'name' => $set->name,
        'set_contents' => SetContents::snapshot($set),
        'quantity' => 1,
        'unit_price' => 12000,
        'subtotal' => 12000,
        'total' => 12000,
    ]);

    // The set changes AFTER the sale: the serum is swapped for a cleanser.
    ProductSetItem::where('set_product_id', $set->id)
        ->where('member_product_id', $serum->id)
        ->delete();

    ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => setFixtureProduct('Cleanser', 7000)->id,
        'quantity' => 1,
        'position' => 9,
    ]);

    $sold = SetContents::fromOrderItem($item->fresh());
    $names = array_column($sold['members'], 'name');

    expect($names)->toBe(['Toner', 'Serum'])
        ->and($sold['count'])->toBe(2)
        // The saving is measured against what the customer actually paid for
        // one set, not against today's catalogue price.
        ->and($sold['saving'])->toBe(1550);
});

it('keeps the members in the order the owner arranged them', function () {
    /*
     * `position` is the order the operator dragged them into on Catalog → Sets,
     * and it is the order every one of the surfaces draws them in — the fan of
     * circles and the popup list both.
     *
     * MUTATION NOTE. Drop `->orderBy('position')` from Product::setItems() and
     * this is red: the rows come back in id order, which is the order they were
     * inserted rather than the order they were arranged. RUN.
     */
    $a = setFixtureProduct('Aaa', 1000);
    $b = setFixtureProduct('Bbb', 1000);
    $c = setFixtureProduct('Ccc', 1000);

    $set = setFixture(2000, [[$a, 1], [$b, 1], [$c, 1]]);

    ProductSetItem::where('set_product_id', $set->id)
        ->where('member_product_id', $c->id)->update(['position' => -1]);

    expect(array_column(SetContents::fromProduct($set->fresh())['members'], 'name'))
        ->toBe(['Ccc', 'Aaa', 'Bbb']);
});

it('answers the empty shape for a product that is not a set', function () {
    /*
     * Every surface guards on `$contents['members']`, so this is the value that
     * keeps a shop with no sets byte-identical: there is no key to be missing
     * and no null to be dereferenced.
     */
    expect(SetContents::fromProduct(setFixtureProduct('Toner', 9000)))->toBe(SetContents::NONE)
        ->and(SetContents::fromProduct(null))->toBe(SetContents::NONE)
        ->and(SetContents::snapshot(setFixtureProduct('Serum', 1000)))->toBeNull()
        ->and(SetContents::fromOrderItem(null))->toBe(SetContents::NONE);
});
