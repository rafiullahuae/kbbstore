<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Support\SetEagerLoad;
use App\Support\SetPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A HAND-TYPED SET PRICE FOLLOWS ITS MEMBERS DOWN. (Lane SP2)
 *
 * The owner, having priced a set by hand:
 *
 *   "when i set the price of product set, either total, or discounted
 *    percentage or manual set the actual and sale price. For any case, when i
 *    change the price of any product from that set, the set price will also
 *    reduce that much how much i reduced in that particular product. from the
 *    actual price and also from the sale price if any. This will help me that i
 *    will not have to change the set price also, if i change any product
 *    price."
 *
 * The two discount modes did this already — SetPricingTest covers them — because
 * they derive the whole price from the parts total. `fixed`, which is where an
 * operator types an actual price and a sale price by hand, did not: the class
 * header said in so many words that the figure "stays where it is".
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP. A box of three products worth
 * AED 200 was priced by hand at AED 180. The toner in it went on sale at
 * AED 30 off. Every shopper who bought the three separately paid AED 170; the
 * set, which is meant to be the cheaper way to buy them, went on charging
 * AED 180 — dearer than its own contents, on a page that prints "Bought
 * separately AED 170" beside it. The owner's only remedy was to remember every
 * set a repriced product appears in and retype each one.
 *
 * ── THE ANCHOR ────────────────────────────────────────────────────────────
 *
 * "Reduce it by what I reduced the product by" needs a second operand: down
 * FROM WHAT. `products.set_price_basis` is that operand — the parts total at
 * the moment the operator typed the price — and the reduction is
 * max(0, basis − parts total now), taken off the regular price AND the sale
 * price. The seven things that can happen to a member are answered one at a
 * time below, in the order the brief asks them.
 */
beforeEach(function () {
    SetPricing::forget();
});

function sfxProduct(string $name, int $fils): Product
{
    return Product::create([
        'slug' => 'sfx-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

/** @param  list<array{0: Product, 1: int}>  $members */
function sfxSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sfx-set-'.Str::random(8),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 18000,
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

/** Re-read from the database with nothing loaded and no memo. */
function sfxReread(Product $set): Product
{
    SetPricing::forget();

    return Product::find($set->id);
}

/* ════════════════════════════════════════ nothing that exists moves ═══ */

it('does not move a hand-priced set that has no anchor', function () {
    /*
     * ── CLAUDE.md's FIRST RULE, AT THE POINT WHERE IT IS EASIEST TO BREAK ──
     *
     * `products.set_price_basis` is NULL on every set that exists — the
     * migration adds the column and backfills nothing. NULL means "no anchor",
     * and no anchor must mean the typed price, unmoved, however far its members
     * have fallen since. Applying this package moves not one price on the shop.
     *
     * MUTATION NOTE. Make SetPricing::adjustment() treat a null basis as zero
     * — `$basis = self::basis($set) ?? 0` instead of returning 0 — and this is
     * red: the set prices itself at 1 fil, because an anchor of zero reads as
     * "the box used to be worth nothing and is now worth 14000". RUN.
     */
    $toner = sfxProduct('Toner', 9000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 5000), 1]], ['price' => 12000]);

    expect($set->getAttributes()['set_price_basis'])->toBeNull()
        ->and(SetPricing::adjustment($set))->toBe(0)
        ->and($set->effectivePrice())->toBe(12000);

    $toner->update(['price' => 6000]);

    expect(sfxReread($set)->effectivePrice())->toBe(
        12000,
        'A set with no anchor is the set every shop already has, and it must not move.'
    );
});

it('says nothing about a product that is not a set, whatever the column holds', function () {
    // The other half of the same rule. `set_price_basis` is on `products`, so
    // it is on all 1,400 of them, and a stray value must be inert on a row that
    // is not a set.
    $plain = sfxProduct('Ordinary Toner', 8900);
    $plain->set_price_basis = 99999;
    $plain->save();

    expect(SetPricing::adjustment($plain))->toBe(0)
        ->and(SetPricing::afterAdjustment($plain, 8900))->toBe(8900)
        ->and($plain->fresh()->effectivePrice())->toBe(8900);
});

/* ═══════════════════════════ the seven things that happen to a member ═══ */

it('takes a repriced member off the set price AND off the sale price', function () {
    /*
     * CASE 1 — A MEMBER IS REPRICED. The whole feature in one assertion.
     *
     * Box worth 20000. Typed: 18000, on sale at 16000. The toner comes down by
     * 1500 and BOTH typed figures come down by exactly 1500 — "from the actual
     * price and also from the sale price if any", in the owner's words.
     *
     * MUTATION NOTE. Delete the afterAdjustment() call from
     * Product::effectivePrice() and this is red at 16000 instead of 14500. Take
     * it out of compareAtPrice() instead and the compare-at stays at 18000, so
     * the shop advertises a saving of 3500 against a price it does not charge.
     * RUN (both).
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'sale_price' => 16000,
        'set_price_basis' => 20000,
    ]);

    expect($set->effectivePrice())->toBe(16000, 'nothing has moved yet')
        ->and($set->compareAtPrice())->toBe(18000);

    $toner->update(['price' => 10500]);
    $moved = sfxReread($set);

    expect(SetPricing::adjustment($moved))->toBe(1500)
        ->and($moved->effectivePrice())->toBe(14500, 'the sale price, less the 1500 the toner came down')
        ->and($moved->compareAtPrice())->toBe(16500, 'and the regular price, less the same 1500');
});

it('follows a member onto sale and back off it again', function () {
    /*
     * CASES 2 AND 3 — A MEMBER GOES ON SALE, AND THAT SALE ENDS.
     *
     * The parts total is sale-aware, so a member's markdown is a reduction like
     * any other and the set follows it down for exactly as long as it runs.
     * When the window closes the total climbs back to the anchor, the delta
     * returns to zero and the set is back at the typed price — WITHOUT anybody
     * saving anything, and never one fil below where it started.
     *
     * This is the case that decides the down-only rule is a clamp on today's
     * delta rather than a ratchet on history. A ratchet would have kept the
     * 3000 for ever and the owner would lose margin on a three-day sale.
     *
     * MUTATION NOTE. Cache the adjustment — take the MINIMUM parts total ever
     * seen instead of today's — and the second half of this is red at 15000.
     * RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'set_price_basis' => 20000,
    ]);

    $toner->update([
        'sale_price' => 9000,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    expect(sfxReread($set)->effectivePrice())->toBe(15000, 'on sale: 3000 off the box, 3000 off the set');

    // The sale ends. Nothing is saved; the window simply closes.
    $toner->update(['sale_ends_at' => now()->subMinute()]);

    expect(sfxReread($set)->effectivePrice())->toBe(
        18000,
        'When the reason for the reduction goes away, so does the reduction — and not one fil further.'
    );
});

it('never raises a set above the price the operator typed', function () {
    /*
     * THE UP-VERSUS-DOWN DECISION, PINNED.
     *
     * A member getting DEARER is the case the owner's sentence does not cover.
     * The answer is max(0, …): the set holds at the figure a human typed.
     *
     * A shop that raises its own prices with nobody touching it charges more
     * than anyone ever agreed to; a shop that lowers them is bounded by the
     * operator's own number and is visible as a smaller figure on the screen he
     * typed it into. The admin panel says "Prices only ever come down" in those
     * words, so this is a stated behaviour rather than a surprise.
     *
     * MUTATION NOTE. Drop the max(0, …) from SetPricing::adjustment() and this
     * is red: the set quietly charges 21000 for a box nobody repriced. RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'set_price_basis' => 20000,
    ]);

    $toner->update(['price' => 15000]);

    expect(SetPricing::adjustment(sfxReread($set)))->toBe(0)
        ->and(sfxReread($set)->effectivePrice())->toBe(18000);
});

it('holds the typed price when a member has been deleted from the catalogue', function () {
    /*
     * CASE 6 — A MEMBER IS DELETED FROM THE CATALOGUE, and it is the dangerous
     * one, because it looks EXACTLY like a price reduction: the product is gone
     * from the parts total and the total is therefore lower.
     *
     * It is not a reduction. Nobody discounted anything — the box is missing an
     * item — and marking the set down by the whole price of the missing product
     * would be a discount nobody gave, on an order that cannot be fulfilled as
     * described, with nothing anywhere saying so. So SetPricing::tally() counts
     * the membership rows whose product is gone and adjustment() declines to
     * move a set that has one. The editor prints the count and the sentence
     * "The set is holding the price you typed until you fix the box."
     *
     * MUTATION NOTE. Delete the `$tally['missing'] > 0` guard from
     * adjustment() and this is red at 10000 — the set has silently marked
     * itself down by the whole price of a product it can no longer supply. RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'set_price_basis' => 20000,
    ]);

    $toner->delete();

    $after = sfxReread($set);

    expect(SetPricing::tally($after)['missing'])->toBe(1)
        ->and(SetPricing::adjustment($after))->toBe(0)
        ->and($after->effectivePrice())->toBe(18000);

    // And the loaded path agrees, which is the path every storefront surface
    // takes: a soft-deleted member arrives through the relation as null.
    $loaded = Product::find($set->id);
    SetEagerLoad::on([$loaded]);
    SetPricing::forget();

    expect(SetPricing::tally($loaded)['missing'])->toBe(1)
        ->and($loaded->effectivePrice())->toBe(18000);
});

/* ═══════════════════════════════════════════════════ the clamp ═══ */

it('clamps a set that would otherwise become free or negative', function () {
    /*
     * A box worth 20000 anchored at 20000, priced at 500 by hand — an operator
     * error, or a legacy figure — and then every member marked down to nothing.
     * The raw arithmetic is 500 − 20000 = −19500: a negative price, which is
     * money flowing the wrong way through checkout.
     *
     * Clamped to MIN_PRICE_FILS. Not to zero: a set at zero is a free product,
     * published, buyable and checkout-able.
     *
     * MUTATION NOTE. Change afterAdjustment()'s clamp to `max(0, …)` and the
     * first expectation is red at 0 — a free set. Remove the clamp altogether
     * and it is red at −19500. RUN (both).
     */
    $toner = sfxProduct('Toner', 12000);
    $serum = sfxProduct('Serum', 8000);
    $set = sfxSet([[$toner, 1], [$serum, 1]], ['price' => 500, 'set_price_basis' => 20000]);

    $toner->update(['price' => 0]);
    $serum->update(['price' => 0]);

    expect(sfxReread($set)->effectivePrice())->toBe(SetPricing::MIN_PRICE_FILS)
        ->and(SetPricing::MIN_PRICE_FILS)->toBeGreaterThan(0, 'the floor is not free');
});

it('never raises a figure through the clamp', function () {
    /*
     * The other half of the clamp, and the half that would be a price change
     * rather than a guard. A set genuinely priced at zero must stay at zero —
     * `max(MIN_PRICE_FILS, …)` alone would LIFT it to one fil, which is a shop
     * charging for something it had decided to give away.
     *
     * MUTATION NOTE. Replace afterAdjustment()'s `max(min($amount, …), …)` with
     * `max(self::MIN_PRICE_FILS, …)` and this is red at 1. RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1]], ['price' => 0, 'set_price_basis' => 12000]);

    $toner->update(['price' => 9000]);

    expect(sfxReread($set)->effectivePrice())->toBe(0);
});

/* ════════════════════════════════════════ what the shop advertises ═══ */

it('does not advertise a followed reduction as a sale', function () {
    /*
     * A set whose members got cheaper is not ON SALE — it is simply cheaper.
     *
     * compareAtPrice() moves with effectivePrice(), so isOnSale() stays false
     * and no red badge appears. Leaving the compare-at at the typed figure
     * would have put "was AED 180" on every set the moment any member was
     * marked down, quoting a price the shop no longer charges and never
     * advertised — the advertised-price-versus-charged-price divergence this
     * model has already been repaired for twice.
     *
     * MUTATION NOTE. Return `(int) $this->price` from compareAtPrice() as it
     * was and this is red: isOnSale() answers true and discountPercent() 8. RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'set_price_basis' => 20000,
    ]);

    $toner->update(['price' => 10500]);
    $moved = sfxReread($set);

    expect($moved->isOnSale())->toBeFalse()
        ->and($moved->discountPercent())->toBe(0)
        ->and($moved->advertisedSalePrice())->toBeNull()
        ->and($moved->toApi()['price'])->toBe(16500, 'the feed quotes what the page quotes');
});

it('still advertises the markdown the operator typed', function () {
    /*
     * And the converse: a set with a REAL typed sale price is still on sale
     * after the reduction, because both figures moved by the same amount. A
     * guard that suppressed the badge along with the stale compare-at would
     * have hidden a markdown the owner deliberately set.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'sale_price' => 16000,
        'set_price_basis' => 20000,
    ]);

    $toner->update(['price' => 10500]);
    $moved = sfxReread($set);

    expect($moved->isOnSale())->toBeTrue()
        ->and($moved->effectivePrice())->toBe(14500)
        ->and($moved->compareAtPrice())->toBe(16500)
        ->and($moved->advertisedSalePrice())->toBe(14500);
});

/* ═════════════════════════════════════════ the two paths, and the cost ═══ */

it('agrees with itself whether or not the members are loaded', function () {
    /*
     * ▲ THE ONE THAT MATTERS MOST. The fast path is PHP walking loaded models,
     *   the fallback is one SQL aggregate, and a set that cost a different
     *   amount depending on whether the page happened to eager-load would be a
     *   pricing bug that is not reproducible from the data alone.
     *
     * Lane SP2 rewrote that aggregate — an INNER JOIN with two WHERE clauses
     * became a LEFT JOIN with the same conditions inside a CASE, so the rows it
     * used to drop could be counted. A quantity, a member on sale and a deleted
     * member are all in this box, which is every branch of that CASE.
     *
     * MUTATION NOTE. Drop `OR p.deleted_at IS NOT NULL` from the `$gone`
     * expression and this is red both ways: SQL prices the deleted member at 0
     * and stops calling it missing, so the SQL path marks the set down and the
     * loaded path does not. RUN.
     */
    $onSale = sfxProduct('Toner', 12000);
    $onSale->update([
        'sale_price' => 9000,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    $gone = sfxProduct('Discontinued', 3000);

    $set = sfxSet([[$onSale, 2], [sfxProduct('Serum', 8000), 1], [$gone, 1]], [
        'price' => 18000,
        'set_price_basis' => 40000,
    ]);

    $gone->delete();

    $viaSql = sfxReread($set);
    $sqlTally = SetPricing::tally($viaSql);
    $sqlPrice = $viaSql->effectivePrice();

    $loaded = Product::find($set->id);
    SetEagerLoad::on([$loaded]);
    SetPricing::forget();

    expect(SetPricing::tally($loaded))->toBe($sqlTally, 'the two paths must count the same box')
        ->and($sqlTally)->toBe(['parts' => 26000, 'rows' => 3, 'missing' => 1])
        ->and($loaded->effectivePrice())->toBe($sqlPrice)
        ->and($sqlPrice)->toBe(18000, 'paused by the missing member');
});

it('costs no query when the members are loaded, and one when they are not', function () {
    /*
     * The budget, restated for the new mode. A hand-priced set now asks what
     * its parts are worth, which the two discount modes have always done — so
     * it must ask it the same way: free when App\Support\SetEagerLoad has
     * already batched the box, and ONE aggregate for the whole set otherwise,
     * memoised for the rest of the request. Never one query per member.
     *
     * MUTATION NOTE. Remove the `relationLoaded('setItems')` fast path from
     * SetPricing::tally() and the first number is 1 instead of 0. Remove the
     * memo and the third is 2. RUN (both).
     */
    $members = [];

    for ($i = 1; $i <= 6; $i++) {
        $members[] = [sfxProduct('Member '.$i, 1000 * $i), 1];
    }

    $set = sfxSet($members, ['price' => 18000, 'set_price_basis' => 21000]);

    $loaded = Product::find($set->id);
    SetEagerLoad::on([$loaded]);
    SetPricing::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $loadedPrice = $loaded->effectivePrice();
    $loadedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $cold = sfxReread($set);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = $cold->effectivePrice();
    $afterFirst = count(DB::getQueryLog());
    $cold->effectivePrice();
    $afterSecond = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($loadedPrice)->toBe(18000, 'anchored at exactly what the box is worth, so nothing comes off')
        ->and($loadedQueries)->toBe(0)
        ->and($first)->toBe(18000)
        ->and($afterFirst)->toBe(1, 'one aggregate for the whole box, never one per member')
        ->and($afterSecond)->toBe(1, 'the second read is the per-request memo');
});

it('keeps the set\'s own product page flat, three members or twelve', function () {
    /*
     * THE BUDGET, ON THE PAGE ITSELF AND WITH AN ANCHOR IN PLACE.
     *
     * SetProductPageTest measures this for a set with no anchor. A hand-priced
     * set now asks what its parts are worth on every read, which is a new
     * question on a page that draws the price three times -- so the measurement
     * is repeated here for the mode that asks it, at three members and at
     * twelve, and the difference must be ZERO. A ceiling alone could not catch
     * it: a page doing one query per member passes any ceiling on a small box.
     *
     * It is free because App\Support\SetEagerLoad has already batched the
     * members before the price is asked for, and tally() answers from the
     * loaded relation without touching the database.
     *
     * MUTATION NOTE, AND THE FIRST ONE I WROTE WAS WRONG, WHICH IS WORTH THE
     * TWO LINES. Disabling tally()'s `relationLoaded('setItems')` fast path
     * does NOT break this: the SQL fallback is one aggregate PER SET, memoised,
     * so three members and twelve both cost the same one. Measured, not
     * assumed. What this actually guards is a per-MEMBER query inside the
     * loaded path -- change `$row->member` to `$row->member()->first()` and it
     * is red at 46 queries against 154. RUN (both).
     */
    $small = sfxSet(array_map(fn ($i) => [sfxProduct('Flat small '.$i, 1000 + $i), 1], range(1, 3)), [
        'price' => 18000, 'set_price_basis' => 20000,
    ]);
    $large = sfxSet(array_map(fn ($i) => [sfxProduct('Flat large '.$i, 1000 + $i), 1], range(1, 12)), [
        'price' => 18000, 'set_price_basis' => 20000,
    ]);

    $count = function (string $slug): int {
        SetPricing::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/product/'.$slug.'/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    // Warm whatever a first request in this process warms -- the settings map,
    // the module registry -- so the two figures compare the page and not the boot.
    test()->get('/product/'.$small->slug.'/')->assertOk();

    $three = $count($small->slug);
    $twelve = $count($large->slug);

    expect($twelve)->toBe(
        $three,
        "A set of twelve must cost what a set of three costs. Three: {$three}. Twelve: {$twelve}."
    );
});

/* ══════════════════════════════════ and a placed order does not move ═══ */

it('leaves a basket and a placed order exactly where they were agreed', function () {
    /*
     * ▲ THE WHOLE RISK OF A PRICE THAT MOVES ON ITS OWN, and the reason this
     *   is safe: it cannot move UNDER A SHOPPER. `cart_items.unit_price` is a
     *   snapshot written when the line is added, and `order_items.unit_price`
     *   is copied from it at checkout. SetPricingTest pins this for the
     *   discount modes; a hand-typed price that follows its members is a new
     *   way for the catalogue figure to move after a line exists, so it is
     *   pinned again here, for the mode that moves.
     *
     * MUTATION NOTE. Make CheckoutController write
     * `$item->product->effectivePrice()` instead of `$item->unit_price` and
     * this is red: the order charges 14500 for a basket agreed at 18000. RUN.
     */
    $toner = sfxProduct('Toner', 12000);
    $set = sfxSet([[$toner, 1], [sfxProduct('Serum', 8000), 1]], [
        'price' => 18000,
        'set_price_basis' => 20000,
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
    expect($agreed)->toBe(18000);

    // The toner comes down 1500 AFTER the set is in the basket.
    $toner->update(['price' => 10500]);
    SetPricing::forget();

    expect(Product::find($set->id)->effectivePrice())
        ->toBe(16500, 'The shop now offers the set for less, which is the feature.');

    expect((int) $cart->fresh(['items'])->items->first()->unit_price)->toBe(
        $agreed,
        'A basket holds the price it was filled at.'
    );

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

    expect((int) Order::latest('id')->first()->items->first()->unit_price)->toBe(
        $agreed,
        'The placed order must charge the price the shopper agreed to, not the price the rule works out today.'
    );
});
