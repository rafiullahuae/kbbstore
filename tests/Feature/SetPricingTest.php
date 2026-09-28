<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Support\SetContents;
use App\Support\SetEagerLoad;
use App\Support\SetPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A set priced as a RULE rather than as a number. (Lane SP)
 *
 * The owner, having built his first set:
 *
 *   "also if i reduce the price of any product, it will also take effect on
 *    this set price, and will reduce the same reduced price from the total
 *    here."
 *
 * That sentence is why `products.price` is not the answer for a set: five
 * different screens change a product's price and a synchronisation job would
 * have to hear from all of them. So the set stores the rule and the price is
 * derived on every read — and the whole risk of a derived price is that it
 * could move UNDER A SHOPPER. The last two cases in this file are about that
 * and they are the ones worth reading.
 */
beforeEach(function () {
    SetPricing::forget();
});

function spxProduct(string $name, int $fils): Product
{
    return Product::create([
        'slug' => 'spx-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

/** @param list<array{0: Product, 1: int}> $members */
function spxSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'spx-set-'.Str::random(8),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 0,
        'stock_status' => 'instock',
    ], $overrides));

    $position = 0;

    foreach ($members as [$product, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $product->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    SetPricing::forget((int) $set->id);

    return $set->fresh();
}

/* ══════════════════════════════════════════════ nothing that exists moves ═══ */

it('prices a set with no rule exactly as it did before the rule existed', function () {
    /*
     * ── CLAUDE.md's FIRST RULE, AT THE POINT WHERE IT IS EASIEST TO BREAK ──
     *
     * `products.set_price_mode` is NULL on every set that exists — the
     * migration adds the column and backfills nothing — and NULL must read as
     * `fixed`, which is the absolute figure in `products.price`. If it did not,
     * every set the owner has already built would reprice itself the moment the
     * package was applied.
     *
     * MUTATION NOTE. Change SetPricing::mode()'s fall-through from MODE_FIXED
     * to MODE_AMOUNT and this is red: the set prices itself at its parts total
     * (14000) instead of at the 12000 somebody typed. RUN.
     */
    $set = spxSet([[spxProduct('Toner', 9000), 1], [spxProduct('Serum', 5000), 1]], ['price' => 12000]);

    expect($set->getAttributes()['set_price_mode'])->toBeNull()
        ->and(SetPricing::mode($set))->toBe(SetPricing::MODE_FIXED)
        ->and(SetPricing::derived($set))->toBeNull()
        ->and($set->effectivePrice())->toBe(12000);
});

it('says nothing at all about a product that is not a set', function () {
    // The other half of the same rule: this class must be invisible to the
    // 1,400 ordinary products in this catalogue.
    $plain = spxProduct('Ordinary Toner', 8900);

    expect(SetPricing::derived($plain))->toBeNull()
        ->and(SetPricing::partsTotal($plain))->toBe(0)
        ->and($plain->effectivePrice())->toBe(8900);
});

/* ═══════════════════════════════════════════════════════ the three rules ═══ */

it('takes a percentage off the parts total in integer fils, rounded once', function () {
    /*
     * 3 × 6667 = 20001 fils bought separately (AED 66.67 each). 10% off is
     * 2000.1 fils, so the set is 18000.9 — and money is integer fils, so it is
     * 18001 with the half rounded up:
     *
     *     intdiv(20001 × 9000 + 5000, 10000) = 18001
     *
     * MUTATION NOTE. Rewrite the percent branch of SetPricing::derived() as the
     * float shape `(int) ($parts * (1 - $bp / self::FULL_BP))` and this is RED
     * by one fil: 20001 × 0.9 is 18000.9 and the cast TRUNCATES to 18000. RUN
     * — it was run, and it fails on exactly this figure.
     *
     * Two faults live in that one line and the mutation shows the bigger one.
     * The cast truncating is the visible half; the other is that `1 - $bp/10000`
     * is a binary fraction — 0.7 is 0.69999999999999995559 — so a chain like it
     * can also land a fil light on a figure that looks like it should be exact.
     * 30% off AED 255.50 happens to come out right either way, which is why the
     * figure above was chosen instead: a mutation note that does not actually go
     * red is worse than none.
     */
    $set = spxSet([[spxProduct('Toner', 6667), 3]], [
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    expect(SetPricing::partsTotal($set))->toBe(20001)
        ->and(SetPricing::derived($set))->toBe(18001)
        ->and(SetPricing::derived($set))->toBeInt()
        ->and($set->effectivePrice())->toBe(18001);

    /*
     * A whole percentage of a whole figure, so the two spellings agree here and
     * the rounding is not what is being read: 12.5% of 20001 is 2500.125, and
     * 17500.875 rounds to 17501.
     */
    $set->set_discount = 1250;
    $set->save();
    SetPricing::forget((int) $set->id);

    expect($set->fresh()->effectivePrice())->toBe(17501);

    // And the two ends of the clamp: 0% is the parts total, 100% is free.
    $set->set_discount = 0;
    $set->save();
    SetPricing::forget((int) $set->id);
    expect($set->fresh()->effectivePrice())->toBe(20001);

    $set->set_discount = SetPricing::FULL_BP;
    $set->save();
    SetPricing::forget((int) $set->id);
    expect($set->fresh()->effectivePrice())->toBe(0);
});

it('takes a fixed amount off the parts total and never goes below zero', function () {
    /*
     * MUTATION NOTE. Drop the `max(0, ...)` from the amount branch and the
     * second half is red: a discount larger than the box is worth prices the
     * set at minus AED 45, which is a line item that pays the customer. RUN.
     */
    $set = spxSet([[spxProduct('Toner', 9000), 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 2500,
    ]);

    expect($set->effectivePrice())->toBe(11500);

    $set->set_discount = 18500;
    $set->save();
    SetPricing::forget((int) $set->id);

    expect($set->fresh()->effectivePrice())->toBe(0);
});

it('prices the set at exactly its parts total when the discount is nothing', function () {
    /*
     * The owner's "use this price" button, which is NOT a button that copies a
     * figure into a box: it is this rule with the discount set to zero, so the
     * set goes on following its members afterwards. A copied number would be
     * right on the day and wrong the first time a member was repriced — which
     * is the thing he asked it not to do.
     */
    $set = spxSet([[spxProduct('Toner', 9000), 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 0,
    ]);

    expect($set->effectivePrice())->toBe(14000)
        ->and(SetContents::fromProduct($set)['saving'])->toBe(0);
});

/* ═════════════════════════════ the sentence the whole shape exists for ═══ */

it('drops the set price by exactly what a member dropped by', function () {
    /*
     * ── THE OWNER'S OWN SENTENCE, MADE INTO AN ASSERTION ──────────────────
     *
     *   "if i reduce the price of any product, it will also take effect on this
     *    set price, and will reduce the same reduced price from the total here."
     *
     * AED 20 off the toner is AED 20 off the set, exactly, with nothing having
     * been told about it. There is no synchronisation job here to run and
     * therefore none to drift.
     *
     * MUTATION NOTE. Make Product::effectivePrice() skip the SetPricing branch
     * (return $this->ownPrice() first) and this is red: the set stays at the
     * price cached in `products.price` while its contents get cheaper. RUN.
     */
    $toner = spxProduct('Toner', 9000);
    $set = spxSet([[$toner, 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 2500,
    ]);

    $before = $set->effectivePrice();

    expect($before)->toBe(11500);

    $toner->price = 7000;
    $toner->save();
    SetPricing::forget();

    $after = Product::find($set->id)->effectivePrice();

    expect($before - $after)->toBe(2000, 'A 20.00 drop on a member is a 20.00 drop on the set.')
        ->and($after)->toBe(9500);
});

/* ══════════════════════════ and it never moves under a shopper ═══ */

it('charges a basket and an order what was agreed, not what the set costs now', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * ▲ THE WHOLE RISK OF A DERIVED PRICE, PINNED. ▲
     * ═══════════════════════════════════════════════════════════════════════
     *
     * A price that works itself out on every read could work itself out
     * differently between the moment a shopper put the set in their basket and
     * the moment they paid. It does not, and that is a property of this
     * application rather than of App\Support\SetPricing — both were read
     * first-hand before the feature was built:
     *
     *   `cart_items.unit_price` is written by CartService::add() and rewritten
     *   only by updateQuantity(). The column's own comment in
     *   0001_01_01_000000_create_kbb_schema.php:270 reads "Snapshot so a price
     *   change mid-session cannot silently alter the cart."
     *
     *   `order_items.unit_price` is copied FROM that snapshot in
     *   Store\CheckoutController — `'unit_price' => $item->unit_price` — beside
     *   the name, brand and sku snapshots on the same row.
     *
     * So: a set goes in a basket at AED 115.00, a member is then cut by AED 20
     * underneath it, and the basket and the order both still say AED 115.00
     * while the shop's own page now offers the set at AED 95.00.
     *
     * MUTATION NOTE. Change CartService::add() to store the product's live
     * effectivePrice() at read time instead of the snapshot — or change
     * CheckoutController's `'unit_price' => $item->unit_price` to
     * `$item->product->effectivePrice()` — and this is red. RUN (the second
     * one; it is the one that would reach a customer's card).
     */
    $toner = spxProduct('Toner', 9000);
    $set = spxSet([[$toner, 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 2500,
    ]);

    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $cart = Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    app(CartService::class)->add($cart, Product::find($set->id), 1);

    $agreed = (int) $cart->fresh(['items'])->items->first()->unit_price;

    expect($agreed)->toBe(11500, 'The basket line is written at the price on the page that day.');

    // The shop cuts the toner AFTER the set is in the basket.
    $toner->price = 7000;
    $toner->save();
    SetPricing::forget();

    expect(Product::find($set->id)->effectivePrice())
        ->toBe(9500, 'The shop now offers the set for less, which is the feature.');

    expect((int) $cart->fresh(['items'])->items->first()->unit_price)
        ->toBe($agreed, 'A basket already holding the set must not reprice itself underneath the shopper.');

    $this->withCredentials()
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

    $line = Order::latest('id')->first()->items->first();

    expect((int) $line->unit_price)->toBe(
        $agreed,
        'The placed order must charge the price the shopper agreed to, not the price the rule works out today.'
    );
});

/* ══════════════════════════════════════════════════════ the query shape ═══ */

it('costs no query at all when the members are already loaded', function () {
    /*
     * Every surface that draws a set has been through App\Support\SetEagerLoad,
     * which batches the members for the whole page. The derived price must then
     * be free, or a derived price would be an N+1 wearing a different hat.
     *
     * MUTATION NOTE. Delete the `relationLoaded('setItems')` fast path from
     * SetPricing::partsTotal() and this is red by one query. RUN.
     */
    $set = spxSet([[spxProduct('Toner', 9000), 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    $loaded = Product::find($set->id);
    SetEagerLoad::on([$loaded]);
    SetPricing::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $price = $loaded->effectivePrice();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($price)->toBe(12600)
        ->and($queries)->toBe(0, 'A loaded set must price itself without asking the database anything.');
});

it('costs one statement, not one per member, when nothing is loaded', function () {
    /*
     * And the fallback is a SINGLE AGGREGATE over the pivot, joined to
     * `products` and `product_variants`, rather than a loop. Six members, one
     * statement — and the second read costs nothing because of the per-request
     * memo.
     *
     * MUTATION NOTE. Replace the aggregate with `$set->setItems()->get()` and a
     * PHP loop calling effectivePrice() per row and this reads 7 instead of 1.
     * RUN.
     */
    $members = [];

    for ($i = 1; $i <= 6; $i++) {
        $members[] = [spxProduct('Member '.$i, 1000 * $i), 1];
    }

    $set = spxSet($members, [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 1000,
    ]);

    $fresh = Product::find($set->id);
    SetPricing::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = $fresh->effectivePrice();
    $afterFirst = count(DB::getQueryLog());
    $second = $fresh->effectivePrice();
    $afterSecond = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 1000 + 2000 + 3000 + 4000 + 5000 + 6000 = 21000, less 1000.
    expect($first)->toBe(20000)
        ->and($second)->toBe(20000)
        ->and($afterFirst)->toBe(1, 'One aggregate statement for the whole box, never one per member.')
        ->and($afterSecond)->toBe(1, 'The second read is the per-request memo and costs nothing.');
});

it('and the two paths agree with each other', function () {
    /*
     * ▲ THE ONE THAT MATTERS MOST AND IS EASIEST TO FORGET. The fast path is
     *   PHP walking loaded models; the fallback is SQL. They are two
     *   implementations of the same sentence, and if they disagreed a set would
     *   quietly cost a different amount depending on whether the page that drew
     *   it happened to eager-load — which is the worst kind of pricing bug,
     *   because it is not reproducible from the data alone.
     *
     * A SALE PRICE ON A MEMBER is the case that separates them: the SQL CASE
     * has to honour the sale window exactly as Product::ownPrice() does.
     *
     * MUTATION NOTE. Drop the `p.sale_price IS NOT NULL` branch from the SQL
     * and this is red — the loaded path says 11000 and SQL says 14000. RUN.
     */
    $onSale = spxProduct('Toner', 9000);
    $onSale->sale_price = 6000;
    $onSale->sale_starts_at = now()->subDay();
    $onSale->sale_ends_at = now()->addDay();
    $onSale->save();

    $set = spxSet([[$onSale, 1], [spxProduct('Serum', 5000), 1]], [
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 0,
    ]);

    SetPricing::forget();
    $viaSql = Product::find($set->id)->effectivePrice();

    $loaded = Product::find($set->id);
    SetEagerLoad::on([$loaded]);
    SetPricing::forget();
    $viaModels = $loaded->effectivePrice();

    expect($viaSql)->toBe(11000)
        ->and($viaModels)->toBe($viaSql, 'The SQL fallback and the loaded path must agree, sale window and all.');
});
