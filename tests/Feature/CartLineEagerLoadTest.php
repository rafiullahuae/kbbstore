<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE CART READ ONE TABLE ONCE PER LINE, ON THE SCREEN WHERE SOMEBODY DECIDES
 * TO PAY.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * store/cart-inner.blade.php draws the chosen option under each line:
 *
 *     $attrs = $item->variant?->label();          // "50ml", "50ml / Rose"
 *
 * ProductVariant::label() reads `attributeValues`, a belongsToMany over
 * `product_variant_attribute_value`. Store\CartController::loadCart() named
 * `items`, `items.product`, `items.product.brand`, `items.variant` and
 * `coupon` — and not that one. So Eloquent fetched it the moment the loop
 * touched it: ONE JOIN PER VARIANT LINE, with a singular
 *
 *     where "product_variant_attribute_value"."product_variant_id" = ?
 *
 * rather than the `in (...)` an eager load produces. MEASURED on /cart before
 * the fix, one variable product per line:
 *
 *     basket lines            1     2     3     5     8
 *     total queries          10    11    12    14    17
 *     `attribute_values`      1     2     3     5     8
 *
 * and after the eager load, 10 flat from one line to eight with a single read
 * of `attribute_values`. Measured through elRequest() below, which resets the
 * same two things every measured request here resets.
 *
 * Exactly +1 per line, forever, on a page a shopper reloads after every
 * quantity change — and cart.js re-renders this same partial through
 * CartController::fragments(), which loads through the same method, so every
 * plus and minus paid it again.
 *
 * ── WHY NOTHING CAUGHT IT ───────────────────────────────────────────────────
 *
 * StorefrontQueryBudgetTest measures /cart with a six-line basket and a ceiling
 * of 10. Every one of those six lines is a SIMPLE product: its fixture is
 * `Product::query()->visible()->limit(6)` with no `product_variant_id` on the
 * line at all, so `$item->variant?->label()` short-circuits on the null and the
 * relation is never touched. A budget is also the wrong shape for this: it caps
 * a total without saying how the total grows, so a per-line cost hides inside
 * any budget that is generous enough for a realistic basket. That is why every
 * assertion below compares EIGHT LINES AGAINST ONE instead of naming a number.
 *
 * ── THE FIX, AND WHY IT IS SPELLED THAT WAY ─────────────────────────────────
 *
 * Store\CheckoutController::loadCart() has carried
 * `items.variant.attributeValues:id,attribute_id,name` all along, because
 * CartService::lineLabel() REFUSES to read the relation unless it is loaded and
 * the order snapshot would otherwise have lost the shopper's size. So the
 * checkout was already flat and the cart was not. The cart's list now carries
 * the same entry, character for character, so the two screens cannot drift into
 * loading different columns of the same relation.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * 1. Delete `'items.variant.attributeValues:id,attribute_id,name'` from
 *    Store\CartController::loadCart() — the fix itself. RUN: 2 failed —
 *    'costs the same for eight variant lines as for one' (16 against 9) and
 *    'reads the option labels in one query however many lines there are'
 *    (8 against 1).
 * 2. Change the fix to `'items.variant.attributeValues:id,name'`, dropping
 *    `attribute_id` from the column list. RUN: 0 failed, and that is recorded
 *    rather than dropped: label() plucks `name` and nothing here reads the
 *    attribute a value belongs to, so the shorter list is equally correct and
 *    equally flat. It is kept at three columns because it is the checkout's
 *    list and the point of this change is that the two screens load the same
 *    thing — a difference nobody can measure today is exactly the sort that
 *    becomes a bug when one of them starts grouping by axis.
 * 3. Delete `'items.variant.attributeValues:id,attribute_id,name'` from
 *    Store\CheckoutController::loadCart(). RUN: 1 failed — 'the checkout, which
 *    is the reference, does not grow either'. ▲ The two cart cases stay GREEN
 *    under it, which is the point of measuring the checkout here at all: the
 *    reference this fix was copied from has nothing pinning it in this file's
 *    absence, and CartService::lineLabel()'s relationLoaded() guard means
 *    removing it loses the size from the ORDER SNAPSHOT silently rather than
 *    noisily.
 * 4. Make ProductVariant::label() `return '';`. RUN: 2 failed — 'prints the
 *    option under every line it renders' and 'prints a multi-axis option in the
 *    order the relation returns it'. ▲ Both COST cases stay green, and that is
 *    the whole reason those two content cases are in this file: a cost
 *    assertion is satisfied by a page that renders nothing at all, so without
 *    them the fix could "pass" by making the label disappear.
 * 5. Remove the `orderBy('id')` from the `items` closure in
 *    Store\CartController::loadCart(). RUN: 0 failed. ▲ Recorded because it
 *    looked like it should matter: 'prints the option under every line' asserts
 *    the SET of labels, sorted, not the order they appear in, and SQLite hands
 *    back insertion order without being asked. Line order is pinned by
 *    CartVariantWasPriceTest's ordered expectations on the price cells, not
 *    here, and this file deliberately does not restate it.
 * 6. Swap the eager load onto the wrong relation —
 *    `'items.product.attributeValues'` (Product has one too, and it is a real
 *    relation, so this is a plausible typo rather than an invented one). RUN: 2
 *    failed, the same two as mutation 1: the variant's relation is still
 *    unloaded, so the per-line join comes straight back.
 *
 * ▲ ONE THING THIS FILE DELIBERATELY DOES NOT DO. ProductVariant::isOnSale()
 * refuses to fetch an unloaded parent rather than lazily reading it, and the
 * obvious symmetry would be for label() to refuse an unloaded
 * `attributeValues` the same way. It does not, and should not: isOnSale()
 * failing closed answers "no sale I can vouch for", which is a safe sentence,
 * while label() failing closed DROPS THE SIZE OFF A BASKET LINE — a shopper
 * looking at two lines of the same cushion with nothing to tell them apart.
 * The cost is pinned here, where it can be measured, rather than bought with a
 * silent content regression on whatever calls it next. CartService::lineLabel()
 * takes the guarded route because it writes an order snapshot, where "no label"
 * is recoverable and a wrong label is not.
 */

/** Pest's own container reset plus the process-level memo. See budgetReset(). */
function elReset(): void
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

function elAttribute(): Attribute
{
    return Attribute::firstOrCreate(['slug' => 'size'], ['name' => 'Size', 'is_variation_axis' => true]);
}

/**
 * A variable product with one variation, marked down, carrying the attribute
 * values named. One product per line so the lines cannot be batched by
 * accident — the same reason StorefrontQueryBudgetTest's basket holds six
 * different products.
 */
function elLine(Cart $cart, string $slug, array $values): ProductVariant
{
    $product = Product::create([
        'slug' => $slug,
        'name' => 'CUSHION ' . strtoupper($slug),
        'status' => 'publish',
        'is_visible' => true,
        'price' => null,
        'stock_status' => 'instock',
        'type' => 'variable',
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addWeek(),
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'price' => 19000,
        'sale_price' => 14000,
        'stock_status' => 'instock',
    ]);

    foreach ($values as $name) {
        $value = AttributeValue::create([
            'attribute_id' => elAttribute()->id,
            'name' => $name,
            'slug' => Str::slug($name . '-' . $variant->id),
        ]);

        DB::table('product_variant_attribute_value')->insert([
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $value->id,
        ]);
    }

    $cart->items()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 14000,
    ]);

    return $variant;
}

function elCart(): Cart
{
    // The squeeze layout, so the measurement covers the page the owner is
    // moving towards as well as the classic one this ships on. Neither layout
    // changes the line loop, which is where the cost is.
    app(CartPage::class)->save(['layout' => 'squeeze']);

    return Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
}

/**
 * One measured request: every statement it ran, plus its HTML.
 *
 * ▲ TWO RESETS AND A WARM-UP, and all three are already paid for in this repo.
 * Setting::map() memoises in a process-level static as well as the cache, so
 * the FIRST request of a process is dearer than every later one and a
 * cold-against-warm comparison measures the warm-up rather than the page.
 * `scoped()` bindings — CartService and SettingsService among them — live in a
 * container a test process never rebuilds, so without forgetScopedInstances()
 * the second request answers out of the first one's memo. Every caller below
 * discards one request before it measures anything.
 */
function elRequest(Cart $cart, string $path): array
{
    elReset();

    $sql = [];
    DB::listen(function ($event) use (&$sql): void { $sql[] = $event->sql; });

    $html = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)
        ->assertOk()
        ->getContent();

    return [
        'total' => count($sql),
        'labels' => count(array_filter($sql, fn (string $s) => str_contains($s, 'from "attribute_values"'))),
        'html' => $html,
    ];
}

/** The text of every `.cvar` chip — the option printed under each line. */
function elPrintedOptions(string $html): array
{
    preg_match_all('#<div class="cvar">(.*?)</div>#s', $html, $m);

    return array_map(
        fn (string $cell) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES, 'UTF-8'))),
        $m[1]
    );
}

it('costs the same for eight variant lines as for one', function () {
    /*
     * THE DEFECT, AS A SHAPE RATHER THAN A NUMBER.
     *
     * Before the eager load: 10 queries for one line and 17 for eight, a slope
     * of exactly one. After: 10 and 10. The assertion is the equality, not the 10
     * — a page is allowed to get dearer for a reason, and is not allowed to get
     * dearer because somebody put more in their basket.
     */
    $cart = elCart();
    elLine($cart, 'el-one-0', ['50ml']);

    elRequest($cart->fresh(), '/cart');                 // warm-up, discarded
    $one = elRequest($cart->fresh(), '/cart');

    for ($i = 1; $i < 8; $i++) {
        elLine($cart, 'el-one-' . $i, ['50ml']);
    }

    $eight = elRequest($cart->fresh(), '/cart');

    expect($eight['total'])->toBe(
        $one['total'],
        "an eight-line basket cost {$eight['total']} queries where a one-line basket costs {$one['total']} — "
        .'the cart page is doing work once per line'
    );
});

it('reads the option labels in one query however many lines there are', function () {
    // The same measurement narrowed to the one table, so a failure says WHICH
    // relation went lazy instead of only that something did. The total above
    // would also move if an unrelated page element started growing; this one
    // cannot.
    $cart = elCart();
    elLine($cart, 'el-lbl-0', ['50ml']);

    elRequest($cart->fresh(), '/cart');
    $one = elRequest($cart->fresh(), '/cart');

    for ($i = 1; $i < 8; $i++) {
        elLine($cart, 'el-lbl-' . $i, ['50ml']);
    }

    $eight = elRequest($cart->fresh(), '/cart');

    expect($one['labels'])->toBe(1);
    expect($eight['labels'])->toBe(
        1,
        "eight variant lines read `attribute_values` {$eight['labels']} times — one per line is the N+1"
    );
});

it('prints the option under every line it renders', function () {
    /*
     * THE COST CASES ABOVE ARE SATISFIED BY A PAGE THAT RENDERS NOTHING.
     *
     * A cart that printed no options at all would be flat, cheap and wrong, so
     * the content is asserted beside the cost: eight lines, eight chips, the
     * eight sizes that were put in the basket. Sorted, because line ORDER is
     * CartVariantWasPriceTest's assertion and this file does not restate it.
     */
    $cart = elCart();
    $sizes = ['30ml', '40ml', '50ml', '60ml', '70ml', '80ml', '90ml', '100ml'];

    foreach ($sizes as $i => $size) {
        elLine($cart, 'el-print-' . $i, [$size]);
    }

    $printed = elPrintedOptions(elRequest($cart->fresh(), '/cart')['html']);

    sort($printed);
    $expected = $sizes;
    sort($expected);

    expect($printed)->toBe($expected);
});

it('prints a multi-axis option in the order the relation returns it', function () {
    /*
     * NOTHING THAT ALREADY WORKS MAY CHANGE, and the one thing an eager load
     * CAN change is the order rows come back in: lazily it is one
     * `where product_variant_id = ?` per variant, eagerly it is a single
     * `in (...)` that Eloquent then buckets. label() implodes with ' / ', so a
     * reordering would rename "50ml / Rose" to "Rose / 50ml" on a live basket.
     *
     * Asserted against the model's own answer rather than against a literal, so
     * this says "the page prints what label() says" — which is the actual
     * contract — for a variation with two values on it.
     */
    $cart = elCart();
    $variant = elLine($cart, 'el-axes', ['50ml', 'Rose']);

    expect(elPrintedOptions(elRequest($cart->fresh(), '/cart')['html']))
        ->toBe([ProductVariant::query()->findOrFail($variant->id)->label()]);
});

it('the checkout, which is the reference, does not grow either', function () {
    /*
     * THE LIST THE CART'S WAS COPIED FROM, PINNED SO IT CANNOT QUIETLY GO.
     *
     * Store\CheckoutController::loadCart() has eager-loaded attributeValues
     * since the relation existed, and nothing asserted it. Removing it there
     * does NOT produce a visible defect on the checkout — CartService::
     * lineLabel() checks relationLoaded() and simply omits the size — so the
     * failure mode is an order line that reads "Water Glow Cushion" where it
     * should read "Water Glow Cushion (50ml)", on the snapshot the shop later
     * packs from. Cheap to measure here while the fixture is in hand.
     */
    $cart = elCart();
    elLine($cart, 'el-co-0', ['50ml']);

    elRequest($cart->fresh(), '/checkout');
    $one = elRequest($cart->fresh(), '/checkout');

    for ($i = 1; $i < 8; $i++) {
        elLine($cart, 'el-co-' . $i, ['50ml']);
    }

    $eight = elRequest($cart->fresh(), '/checkout');

    expect($eight['labels'])->toBe(1)
        ->and($eight['total'])->toBe(
            $one['total'],
            "eight checkout lines cost {$eight['total']} queries where one costs {$one['total']}"
        );
});
