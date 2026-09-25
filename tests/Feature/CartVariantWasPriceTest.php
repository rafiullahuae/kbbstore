<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartPage;
use App\Services\CartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE CART WENT SILENT ABOUT A SAVING THE SHOPPER WAS ACTUALLY GETTING.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * store/cart-inner.blade.php draws a struck "was" beside each line, and beside
 * Order value it draws the sum of them. Both asked the PRODUCT what the line
 * was marked down from:
 *
 *     $was = ($p && $p->isOnSale()) ? (int) $p->compareAtPrice() * $qty : 0;
 *
 * On a variable product Product::compareAtPrice() is MIN(regular) across the
 * variations — the from-price the tile printed the day before the markdown
 * started — and that is exactly right everywhere the product is collapsed to
 * one number. A basket line is the one place it is not: the line knows WHICH
 * option is in it.
 *
 * So on a parent whose options are AED 120 and AED 190, marked down to AED 90
 * and AED 140, a shopper who chose the DEARER option saw:
 *
 *     AED 140                      <- and nothing struck beside it
 *
 * because the compare-at on offer was AED 120, which is BELOW the AED 140 being
 * charged. The previous round clamped that with max() rather than print it, and
 * was right to: "AED 120" struck beside "AED 140" is a worse screen than no
 * strike at all. The clamp was correct and silent, and a shopper saving AED 50
 * was told nothing about it — on the screen where somebody decides to pay, and
 * beside a line where the shopper who picked the CHEAPER option does see a
 * strike.
 *
 * The honest figure there is the VARIATION's own regular price:
 * ProductVariant::compareAtPrice(). store/cart-inner.blade.php now derives both
 * the line's figure and the order-value sum from one closure, $kbbLineWas(), so
 * a row and the total beside it cannot state two different savings.
 *
 * ── THE TWO RULES, PINNED BELOW ─────────────────────────────────────────────
 *
 *   - a struck figure is NEVER below what is being charged, and
 *   - it is NEVER zero.
 *
 * Both are max($compareAt * $qty, $line), and both have already been shipped
 * wrong once each: `(int) null` put a zero in the order-value sum
 * (VariableProductSaleVisibleTest), and the parent's from-price put a figure
 * below the line on a dearer option (this file).
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * 1. Point $kbbLineWas()'s variant arm back at the parent
 *    (`$kbbWasProduct->isOnSale() ? $kbbWasProduct->compareAtPrice() : 0` for
 *    every line). RUN: 3 failed — 'strikes the option's own regular price',
 *    'sums the lines it struck', and the order-value case in
 *    VariableProductSaleVisibleTest, which reads AED 340 against AED 390.
 *    ▲ The quantity case stays GREEN under it, and that is worth saying: its
 *    parent has ONE option, so MIN(regular) across the variations and that
 *    option's own regular price are the same number. A product with one
 *    variation cannot tell these two rules apart, which is why every other
 *    case here gives the parent two.
 * 2. Drop the max() from $kbbLineWas(). RUN: 1 failed — 'never counts a line
 *    into the order value at less than it cost', AED 390 against AED 400.
 *    ▲ AND THE LINE CASES STAY GREEN, which is the measurement that told this
 *    file what max() is actually for. A row prints its struck figure only when
 *    `$was > $line`, so on the LINE a compare-at below the line draws nothing
 *    with or without the clamp. The sum has no such guard, so that is the only
 *    place it shows — and the case above exists because the first draft of this
 *    file asserted max() through the lines and would have stayed green with it
 *    deleted.
 * 3. ▲ AND ONE THAT DOES NOT GO RED, recorded because a claim nobody can check
 *    is what rule 6 is against. Make ProductVariant::compareAtPrice() return
 *    `(int) $this->price` instead of the null test: RUN: 0 failed. That is
 *    correct rather than a gap — a compare-at of 0 fails isOnSale()'s
 *    `sale_price >= $compare` test on its way past, so the strikethrough this
 *    file is about cannot print AED 0 by either spelling, and 'never strikes
 *    AED 0 on an option priced by its markdown alone' passes both ways. The
 *    null is kept because compareAtPrice() is a public answer in its own right
 *    and "no compare-at" is not "a compare-at of nothing" — the same
 *    distinction Product::ownPrice() was extracted from effectivePrice() to
 *    hold, and the case above is what stops the ZERO reaching a screen however
 *    it is spelt.
 * 4. Delete the `relationLoaded('product')` guard in ProductVariant::isOnSale()
 *    and read `$this->product` instead. RUN: 1 failed — 'never fetches a
 *    variation's parent', on the first of its two expectations: the lazily
 *    fetched parent answers the window and an unloaded variation starts
 *    reporting a live sale, which is the query AND the fail-open direction in
 *    one line.
 *    ▲ THE PAGE-LEVEL COST CASE STAYS GREEN UNDER IT, and that is why the
 *    model-level case exists. cart-inner.blade.php hands the parent over with
 *    setRelation() before it asks, so through the cart the guard and its
 *    absence are indistinguishable — the first draft of this file measured
 *    only the page and would have shipped the lazy read.
 * 5. Delete the window check from ProductVariant::isOnSale() (return true once
 *    the compare-at and the markdown are there). RUN: 1 failed — 'says nothing
 *    about a markdown whose window has shut', which strikes AED 190 beside the
 *    AED 140 the shopper is paying and advertises a sale the shop says ended
 *    yesterday. That case had to hold a line snapshotted AT the markdown to
 *    show it: a line already charged the regular price renders identically
 *    with the check and without it.
 *
 * ▲ ONE MUTATION THAT DOES NOT GO RED, recorded rather than left out. Changing
 * the `(int) $this->sale_price >= $compare` test in ProductVariant::isOnSale()
 * to `>` leaves every case here green: a sale_price EQUAL to the regular price
 * is not a markdown, but max() would take the line for it anyway, so the two
 * spellings cannot differ in what this file renders. It is kept as `>=` because
 * isOnSale() is a public answer about a variation and "on sale at the same
 * price" is false wherever it is asked from, not only from here — the same
 * argument Product::isOnSale() makes with its own null test.
 */

/** A variable parent with an open markdown window and no price of its own. */
function cwParent(string $slug, array $extra = []): Product
{
    return Product::create(array_merge([
        'slug' => $slug,
        'name' => 'CUSHION ' . strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addWeek(),
    ], $extra));
}

function cwVariant(Product $parent, ?int $price, ?int $salePrice = null): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $parent->id,
        'price' => $price,
        'sale_price' => $salePrice,
        'stock_status' => 'instock',
    ]);
}

/** An empty basket on the squeeze layout, which is the one with Order value. */
function cwCart(): Cart
{
    app(CartPage::class)->save(['layout' => 'squeeze']);

    return Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
}

/** The rendered /cart page for this basket. */
function cwPage(Cart $cart): string
{
    return test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();
}

/** The plain text of every line's price cell, in basket order. */
function cwLines(string $html): array
{
    preg_match_all('#<div class="cpr">(.*?)</div>#s', $html, $m);

    return array_map(
        fn (string $cell) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8'))),
        $m[1]
    );
}

/** The Order value row: the struck before-price and the subtotal, adjacent. */
function cwOrderValue(string $html): string
{
    preg_match('#<span class="cpg-was">(.*?)</div>#s', $html, $m);

    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES, 'UTF-8')));
}

it('strikes the option\'s own regular price when the shopper bought a dearer one', function () {
    /*
     * THE DEFECT ITSELF. Options at AED 120 and AED 190, marked to AED 90 and
     * AED 140. The basket holds the AED 190 option at AED 140.
     *
     * Before: "AED 140", alone — the parent's compare-at is AED 120, which is
     * below the line, so the clamp took the line and the row said nothing.
     * After: "AED 140 AED 190", a saving of AED 50 stated where it is being
     * given.
     */
    $parent = cwParent('cw-dearer');
    cwVariant($parent, 12000, 9000);
    $dearer = cwVariant($parent, 19000, 14000);

    $cart = cwCart();
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $dearer->id,
        'quantity' => 1,
        'unit_price' => 14000,
    ]);

    expect(cwLines(cwPage($cart)))->toBe(['AED 140AED 190']);
});

it('still strikes the same figure for the option that is also the from-price', function () {
    // NOTHING THAT ALREADY WORKS MAY CHANGE. The cheaper option's own regular
    // price IS the parent's compare-at, so this line renders the bytes it
    // rendered before the change — measured here rather than assumed.
    $parent = cwParent('cw-cheaper');
    $cheap = cwVariant($parent, 12000, 9000);
    cwVariant($parent, 19000, 14000);

    $cart = cwCart();
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $cheap->id,
        'quantity' => 1,
        'unit_price' => 9000,
    ]);

    expect(cwLines(cwPage($cart)))->toBe(['AED 90AED 120']);
});

it('multiplies the option\'s regular price by the quantity, like the line beside it', function () {
    // The struck figure is a LINE total, not a unit price: the cell beside it
    // is AED 140 x 3, so a unit compare-at printed there would read as a
    // saving three times smaller than the one being given.
    $parent = cwParent('cw-qty');
    $dearer = cwVariant($parent, 19000, 14000);

    $cart = cwCart();
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $dearer->id,
        'quantity' => 3,
        'unit_price' => 14000,
    ]);

    expect(cwLines(cwPage($cart)))->toBe(['AED 420AED 570']);
});

it('never strikes a figure below what is being charged', function () {
    /*
     * RULE ONE, AND IT IS THE ONE THE PREVIOUS ROUND'S max() WAS PROTECTING.
     *
     * A line whose unit_price was snapshotted ABOVE the variation's current
     * regular price — the option was repriced downward after it went in the
     * basket — has a compare-at below the line. There is no honest strike to
     * draw, so none is drawn.
     */
    $parent = cwParent('cw-below');
    $variant = cwVariant($parent, 19000, 14000);

    $cart = cwCart();
    // Paid AED 200 for a line whose regular price is now AED 190.
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    $lines = cwLines(cwPage($cart));

    expect($lines)->toBe(['AED 200']);
    expect($lines[0])->not->toContain('AED 190');
});

it('never strikes AED 0 on an option priced by its markdown alone', function () {
    /*
     * RULE TWO. `product_variants.price` is nullable, and a variation carrying
     * only a `sale_price` has no compare-at that can be vouched for. `(int)
     * null` would advertise a markdown FROM NOTHING — the same AED 0 that
     * reached three struck-price sites the last time this area moved.
     */
    $parent = cwParent('cw-noregular');
    $variant = cwVariant($parent, null, 9000);

    $cart = cwCart();
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 9000,
    ]);

    $html = cwPage($cart);

    expect(cwLines($html))->toBe(['AED 90']);
    expect($html)->not->toContain('AED 0');
});

it('says nothing about a markdown whose window has shut', function () {
    // `product_variants` carries no dates: the window is the PARENT's, and it
    // is the only one there is. A variation whose sale_price is outside it is
    // not marked down, and ProductVariant::effectivePrice() charges the regular
    // price for it — so a strike here would contradict the money.
    $parent = cwParent('cw-shut', [
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    $variant = cwVariant($parent, 19000, 14000);

    $cart = cwCart();
    // The basket a shopper left open across the end of the sale: the line was
    // snapshotted at the markdown, and the window has since shut. This is the
    // shape that tells the window check from no check at all — a line already
    // charged the regular price renders the same either way.
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 14000,
    ]);

    $lines = cwLines(cwPage($cart));

    expect($lines)->toBe(['AED 140']);
    expect($lines[0])->not->toContain('AED 190');
});

it('never fetches a variation\'s parent to answer whether it is on sale', function () {
    /*
     * THE N+1 THAT CANNOT HAPPEN, ASSERTED AT THE MODEL.
     *
     * The sale window lives on `products` and nowhere else, so
     * ProductVariant::isOnSale() has to consult the parent — and
     * `$this->product` would LAZILY FETCH it. Store\CartController::loadCart()
     * eager-loads `items.variant` with a named column list and does NOT load
     * `variant.product`, so a basket of ten variable lines would have run ten
     * extra statements on the screen where somebody decides to pay.
     *
     * So an unloaded parent is refused rather than fetched: no window that can
     * be seen is no sale that can be vouched for, which is the direction
     * Product::advertisedSalePrice() takes for the same question. The caller
     * that HAS the row hands it over, and the answer changes without a query.
     *
     * The cost case further down measures the page; this one measures the
     * mechanism, because a page whose caller does the right thing cannot tell
     * the guard from its absence.
     */
    $parent = cwParent('cw-noload');
    $variant = cwVariant($parent, 19000, 14000);

    $fresh = ProductVariant::query()->findOrFail($variant->id);

    $queries = 0;
    DB::listen(function () use (&$queries): void { $queries++; });

    expect($fresh->isOnSale())->toBeFalse('an unloaded parent was treated as an open window');
    expect($queries)->toBe(0, "asking an unloaded variation ran {$queries} queries; on a basket that is one per line");

    // And with the row the cart already holds in hand, it answers properly.
    $fresh->setRelation('product', $parent);

    expect($fresh->isOnSale())->toBeTrue()
        ->and($fresh->compareAtPrice())->toBe(19000);
});

it('leaves a simple product\'s line exactly as it was', function () {
    // The other arm of the closure, unchanged: a line with no variation asks
    // the product, as it always did.
    $simple = Product::create([
        'slug' => 'cw-simple', 'name' => 'TONER', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'sale_price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $cart = cwCart();
    $cart->items()->create(['product_id' => $simple->id, 'quantity' => 2, 'unit_price' => 5000]);

    expect(cwLines(cwPage($cart)))->toBe(['AED 100AED 400']);
});

it('sums the lines it struck, and the two cannot disagree', function () {
    /*
     * ONE DEFINITION FOR THE ROW AND THE TOTAL. The order-value "before" is the
     * sum of exactly the figures struck above it, so a shopper adding up the
     * lines gets the number beside Order value.
     *
     * Basket: the AED 190 option at AED 140, plus a simple product marked
     * AED 200 down to AED 50. Subtotal AED 190; the lines strike AED 190 and
     * AED 200, so the total strikes AED 390.
     */
    $parent = cwParent('cw-sum-parent');
    cwVariant($parent, 12000, 9000);
    $dearer = cwVariant($parent, 19000, 14000);

    $simple = Product::create([
        'slug' => 'cw-sum-simple', 'name' => 'TONER', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'sale_price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $cart = cwCart();
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $dearer->id,
        'quantity' => 1,
        'unit_price' => 14000,
    ]);
    $cart->items()->create(['product_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]);

    $html = cwPage($cart);

    $struck = array_map(
        fn (string $line) => (int) preg_replace('/^.*AED (\d+)$/', '$1', $line),
        cwLines($html)
    );

    expect(array_sum($struck))->toBe(390);
    expect(cwOrderValue($html))->toBe('AED 390AED 190');
});

it('never counts a line into the order value at less than it cost', function () {
    /*
     * max() IN THE SUM, WHICH IS THE ONLY PLACE IT SHOWS.
     *
     * The LINE is already safe without it: the row prints its struck figure
     * only when `$was > $line`, so a compare-at below the line simply draws
     * nothing. The TOTAL has no such guard — it adds every line up — so a
     * compare-at below a line would make the struck order value LOWER than the
     * sum of what the lines cost: a saving smaller than the one being given,
     * which is the same understatement a zero produced, one round earlier.
     *
     * The basket: a simple product marked AED 200 down to AED 50, beside a
     * variation whose regular price is AED 190 but whose line was snapshotted
     * at AED 200 (it was repriced downward after it went in the basket).
     * Subtotal AED 250. The honest before-price is AED 200 + AED 200 = AED 400;
     * without max() the second line contributes AED 190 and it reads AED 390 —
     * a before-price below what that line actually cost.
     */
    $parent = cwParent('cw-max-parent');
    $variant = cwVariant($parent, 19000, 14000);

    $simple = Product::create([
        'slug' => 'cw-max-simple', 'name' => 'TONER', 'status' => 'publish', 'is_visible' => true,
        'price' => 20000, 'sale_price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $cart = cwCart();
    $cart->items()->create(['product_id' => $simple->id, 'quantity' => 1, 'unit_price' => 5000]);
    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 20000,
    ]);

    expect(cwOrderValue(cwPage($cart)))->toBe('AED 400AED 250');
});

it('costs no query per basket line', function () {
    /*
     * RULE 4, MEASURED. ProductVariant::isOnSale() needs the parent's sale
     * window, and reading `$this->product` would LAZILY FETCH IT — Store\
     * CartController::loadCart() eager-loads `items.variant` with a named
     * column list and does not load `variant.product`, so five variable lines
     * would have been five extra statements on the screen where somebody
     * decides to pay.
     *
     * It refuses to fetch instead: an unloaded parent means no window it can
     * see, and the cart hands over the row it already holds with
     * setRelation(). So the number of reads of `products` is the same for one
     * variable line as for five.
     *
     * ▲ READS OF `products`, NOT THE TOTAL, and that is not the measurement
     * being dodged — it is the only one that can answer this question, because
     * the /cart page ALREADY runs one statement per variant line and it is not
     * this one. `$item->variant?->label()` reads the `attributeValues` relation
     * and loadCart() does not eager-load it, so a five-option basket runs five
     * `attribute_values` joins. That N+1 predates this change, is in a file this
     * lane does not own (Store\CartController::loadCart's eager-load list), and
     * is reported rather than quietly absorbed into a total here. Counting
     * every statement would fail against it forever and would never notice the
     * one extra read this change could have added.
     *
     * ▲ ONE WARM-UP RENDER FIRST. Setting::map() memoises in a process-level
     * static as well as the cache (CLAUDE.md names this trap), so the first
     * render of a process is dearer than every later one and a cold-against-
     * warm comparison measures the warm-up. forgetScopedInstances() before each
     * measured render, for the reason ApiProductTypeNotPublishedTest gives: a
     * test does not reboot the container between requests, so without it the
     * second render answers from the first one's memo.
     *
     * MEASURED: 2 reads of `products` for one variable line and 2 for five.
     */
    $parent = cwParent('cw-cost');
    $variants = [
        cwVariant($parent, 12000, 9000),
        cwVariant($parent, 19000, 14000),
        cwVariant($parent, 22000, 16000),
        cwVariant($parent, 25000, 18000),
        cwVariant($parent, 28000, 20000),
    ];

    $cart = cwCart();

    $productReads = function (Cart $cart): int {
        app()->forgetScopedInstances();

        $n = 0;
        DB::listen(function ($event) use (&$n): void {
            if (str_contains($event->sql, 'from "products"')) {
                $n++;
            }
        });
        cwPage($cart);

        return $n;
    };

    $cart->items()->create([
        'product_id' => $parent->id,
        'product_variant_id' => $variants[0]->id,
        'quantity' => 1,
        'unit_price' => 9000,
    ]);

    $productReads($cart->fresh());          // warm-up, discarded
    $one = $productReads($cart->fresh());

    foreach (array_slice($variants, 1) as $variant) {
        $cart->items()->create([
            'product_id' => $parent->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => (int) $variant->sale_price,
        ]);
    }

    $five = $productReads($cart->fresh());

    expect($five)->toBe(
        $one,
        "five variable basket lines read `products` {$five} times where one reads it {$one} — "
        .'the struck price is asking each variation for its parent'
    );
});
