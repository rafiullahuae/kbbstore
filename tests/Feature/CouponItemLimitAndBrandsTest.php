<?php

declare(strict_types=1);

/**
 * The three coupon fields Store → Coupons drew greyed out, now enforced.
 *
 * WHAT WAS WRONG. The coupon editor's own docblock listed three WooCommerce
 * fields it refused to offer as live controls, each for the same reason: the
 * shop's pricing code did not enforce them, and "a restriction the owner sets
 * and the shop quietly ignores is worse than a missing one". Those three are
 * "Limit usage to X items" (no column at all), Brands / Exclude brands (no
 * column, and eligibleItems() never looked at products.brand_id) and "Allow
 * free shipping" (column populated by the import, read by nothing).
 *
 * WHAT THESE TESTS PIN. Each field is checked twice over. Once against
 * CouponService directly, for the arithmetic; and once against a coupon
 * created through the real admin endpoint, which is the assertion that proves
 * the editor and the till agree — an editor that stores the owner's number and
 * a service that prices a different one are individually perfect and together
 * a money bug, which is the defect CouponEditorTest's section 8 exists about.
 *
 * WHICH UNITS A QUANTITY CAP DISCOUNTS. "Limit usage to 2 items" against a
 * basket holding four eligible units has to choose two of them, and the choice
 * changes the payout. This shop discounts the CHEAPEST eligible units first,
 * ties broken by cart line id. Two reasons, both written out beside
 * cappedLines() in CouponService: it is the merchant-protective reading of a
 * cap (a cap exists to bound exposure, so when it binds the shop pays the
 * smaller of the available figures), and it is independent of the order the
 * shopper happened to click things into the basket, so the cart page and the
 * checkout re-price to the same number. Cart order would make a discount move
 * when a shopper removed a line and added it back.
 *
 * Pest trap avoided throughout: toContain() reads further arguments as more
 * NEEDLES and toHaveKey() reads a second argument as an EXPECTED VALUE.
 * Neither takes a failure message, so where a message is wanted the assertion
 * is str_contains(...) with toBeTrue('...').
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CouponsAdminRoutes;

beforeEach(function () {
    CouponsAdminRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    PaymentProvider::query()->delete();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    PaymentProvider::create([
        'id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0,
    ]);
    app(\App\Services\SettingsService::class)->set('cod_fee', 0);

    // A flat AED 20 rate and NO free-shipping method, so the order-value
    // threshold can never be the thing that zeroed the delivery line. Anything
    // these tests see at zero was zeroed by the coupon.
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);
});

/* ------------------------------------------------------------------ tools */

function climProduct(string $name, int $priceFils, ?Brand $brand = null): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(8),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
    ]);
}

function climBrand(string $name): Brand
{
    return Brand::create([
        'slug' => Str::slug($name) . '-' . Str::random(8),
        'name' => $name,
    ]);
}

/** A cart carrying one line per [product, quantity], in the order given. */
function climCart(?Coupon $coupon, array $lines): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon?->id,
        'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->effectivePrice(),
        ]);
    }

    return $cart->load('items.product', 'coupon');
}

/**
 * The coupon as the STOREFRONT hands it to discountFor(): four columns.
 *
 * CartController::loadCart(), Store\CheckoutController::loadCart() and
 * CartDrawerComposer all eager-load `coupon:id,code,type,amount`. Every rule
 * column is absent on that instance and reads null, which is why
 * CouponService::RULE_COLUMNS and withRules() exist. A test that hands in a
 * complete row cannot see a rule that fails open.
 */
function climPartial(Coupon $coupon): Coupon
{
    return Coupon::query()->select(['id', 'code', 'type', 'amount'])->findOrFail($coupon->id);
}

/** CouponService::RULE_COLUMNS, which is private and deliberately stays so. */
function climRuleColumns(): array
{
    return (new ReflectionClass(CouponService::class))->getConstant('RULE_COLUMNS');
}

/**
 * A coupon instance carrying every rule column the service names EXCEPT one.
 *
 * WHY THIS SHAPE, AND WHY climPartial() IS NOT ENOUGH ON ITS OWN. withRules()
 * is all-or-nothing: it re-reads the row if ANY name in RULE_COLUMNS is absent
 * from the instance. The storefront's `coupon:id,code,type,amount` is missing
 * all of them, so it triggers the re-read no matter which names are on the
 * list — which means a test built on that instance passes just as happily with
 * a column MISSING from RULE_COLUMNS as with it present, and pins nothing
 * about the list.
 *
 * Selecting every listed column but one closes that gap. With the name on the
 * list, the instance is incomplete and withRules() re-reads. Drop the name and
 * the instance is suddenly "complete", the re-read is skipped, the column reads
 * null and the rule fails open — which is the whole failure mode, reproduced
 * exactly. The select list is built FROM the constant, so it cannot drift away
 * from what the service actually checks.
 */
function climAllRuleColumnsExcept(Coupon $coupon, string $omit): Coupon
{
    $columns = array_values(array_diff(climRuleColumns(), [$omit]));

    return Coupon::query()
        ->select(array_merge(['id', 'code', 'type', 'amount'], $columns))
        ->findOrFail($coupon->id);
}

function climPayload(array $overrides = []): array
{
    return $overrides + [
        'code' => 'CAPPED',
        'type' => 'percent',
        'amount' => '20',
        'description' => null,
        'starts_at' => null,
        'expires_at' => null,
        'minimum_amount' => null,
        'maximum_amount' => null,
        'exclude_sale_items' => false,
        'free_shipping' => false,
        'usage_limit' => null,
        'usage_limit_per_user' => null,
        'limit_usage_to_x_items' => null,
        'product_ids' => [],
        'excluded_product_ids' => [],
        'category_ids' => [],
        'excluded_category_ids' => [],
        'brand_ids' => [],
        'excluded_brand_ids' => [],
        'allowed_emails' => [],
    ];
}

function climPost(array $overrides = [])
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/coupons/manage', climPayload($overrides));
}

function climPlaceOrder(Cart $cart)
{
    return test()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com',
            'billing_first_name' => 'Aisha',
            'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk',
            'billing_city' => 'Dubai',
            'billing_state' => 'Dubai',
            'billing_country' => 'AE',
            'payment_method' => 'cod',
        ]);
}

/* =====================================================================
 | 0. THE MIGRATION IS SAFE ON THE LIVE DATA
 |
 | Every coupon on the shop arrived in the WooCommerce import and none of
 | them carries any of the three new fields. After the migration they must
 | price to exactly the figure they priced to before it existed.
 ==================================================================== */

it('adds the three columns without a default that changes anything', function () {
    expect(Schema::hasColumn('coupons', 'limit_usage_to_x_items'))->toBeTrue();
    expect(Schema::hasColumn('coupons', 'brand_ids'))->toBeTrue();
    expect(Schema::hasColumn('coupons', 'excluded_brand_ids'))->toBeTrue();

    // Written the way the WooCommerce importer writes a row: it names none of
    // the new columns, so each has to land on its own "no restriction" value.
    $coupon = Coupon::create([
        'code' => 'IMPORTED35',
        'type' => 'percent',
        'amount' => 3500,
        'wc_id' => 4021,
    ]);

    $fresh = $coupon->fresh();

    expect($fresh->limit_usage_to_x_items)->toBeNull();
    expect($fresh->brand_ids)->toBeNull();
    expect($fresh->excluded_brand_ids)->toBeNull();
});

it('prices a coupon that predates the new fields exactly as it always did', function () {
    // The row the importer left: no item cap, no brands, three eligible units
    // across two lines and two brands it has never heard of.
    $anua = climBrand('Anua');
    $toner = climProduct('Heartleaf Toner', 6000, $anua);
    $serum = climProduct('Peach Serum', 10000);

    $coupon = Coupon::create([
        'code' => 'LEGACY35',
        'type' => 'percent',
        'amount' => 3500,
        'wc_id' => 5150,
    ]);

    $cart = climCart($coupon, [[$toner, 2], [$serum, 1]]);

    // 35% of the WHOLE eligible basket: 6000*2 + 10000 = 22,000 fils, exactly
    // 7,700. If the cap read anything other than "no limit" from a NULL, or a
    // NULL brand list read as "only these brands", this drops.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(7700);

    // And through the storefront's four-column instance, which is the one that
    // reaches the till.
    expect(app(CouponService::class)->discountFor(climPartial($coupon), $cart))->toBe(7700);
});

it('leaves delivery charged for a coupon that predates free-shipping enforcement', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'LEGACYNOSHIP',
        'type' => 'percent',
        'amount' => 1000,
        'wc_id' => 5151,
    ]);

    $cart = climCart($coupon, [[$product, 1]]);

    $totals = app(CartService::class)->totals($cart, 'AE', null, 2000);

    expect($totals['shipping'])->toBe(2000);
    expect($totals['total'])->toBe(9000 - 900 + 2000);
});

/* =====================================================================
 | 1. LIMIT USAGE TO X ITEMS
 ==================================================================== */

it('caps a percentage coupon at the cheapest N eligible units', function () {
    $serum = climProduct('Expensive Serum', 10000);   // AED 100
    $toner = climProduct('Everyday Toner', 6000);     // AED 60

    $coupon = Coupon::create([
        'code' => 'TWOITEMS',
        'type' => 'percent',
        'amount' => 2000,                            // 20%
        'limit_usage_to_x_items' => 2,
    ]);

    // The expensive line FIRST, so cart order and cheapest-first disagree.
    $cart = climCart($coupon, [[$serum, 1], [$toner, 3]]);

    /*
     * Four eligible units; two may be discounted. Cheapest first takes two
     * toners: 6000 * 2 = 12,000 eligible, 20% of which is 2,400 fils.
     *
     * The two figures this is NOT:
     *   3,200 — the serum plus one toner, which is both "most expensive first"
     *           and "cart order" on this basket.
     *   5,600 — no cap at all, 20% of the whole AED 280 basket.
     */
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(2400);
});

it('caps a fixed_product coupon per unit, not per line', function () {
    $balm = climProduct('Lip Balm', 300);            // AED 3 — cheaper than the discount
    $toner = climProduct('Toner', 6000);

    $coupon = Coupon::create([
        'code' => 'THREEUNITS',
        'type' => 'fixed_product',
        'amount' => 500,                             // AED 5.00 off each unit
        'limit_usage_to_x_items' => 3,
    ]);

    $cart = climCart($coupon, [[$balm, 2], [$toner, 3]]);

    /*
     * Three units allowed, cheapest first: both balms, then ONE toner. The
     * per-unit discount is still floored at the unit's own price, so
     * min(500,300)*2 + min(500,6000)*1 = 600 + 500 = 1,100.
     *
     * Uncapped this is 2,100. Capped a unit wrong in either direction it is
     * 1,600 (four units) or 600 (two).
     */
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(1100);
});

it('floors a fixed_cart coupon at the capped eligible subtotal', function () {
    $product = climProduct('Sheet Mask', 1000);      // AED 10 each

    $coupon = Coupon::create([
        'code' => 'ONEITEM30',
        'type' => 'fixed_cart',
        'amount' => 3000,                            // AED 30 off the basket
        'limit_usage_to_x_items' => 1,
    ]);

    $cart = climCart($coupon, [[$product, 5]]);

    // One unit may be discounted, so the eligible subtotal the AED 30 is
    // floored against is 1,000 fils. Uncapped it would pay the full 3,000.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(1000);
});

it('changes nothing when the cap is larger than the basket', function () {
    $product = climProduct('Ampoule', 4000);

    $coupon = Coupon::create([
        'code' => 'ROOMY',
        'type' => 'percent',
        'amount' => 1000,
        'limit_usage_to_x_items' => 99,
    ]);

    $cart = climCart($coupon, [[$product, 2]]);

    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(800);
});

it('does not let a partial coupon instance escape the item cap', function () {
    $product = climProduct('Essence', 5000);

    $coupon = Coupon::create([
        'code' => 'CAPPARTIAL',
        'type' => 'percent',
        'amount' => 1000,
        'limit_usage_to_x_items' => 1,
    ]);

    $cart = climCart($coupon, [[$product, 4]]);

    // The storefront's `coupon:id,code,type,amount` instance has no
    // limit_usage_to_x_items on it at all, so an unguarded read gets null and
    // the cap vanishes: 10% of 20,000 = 2,000 instead of 10% of 5,000 = 500.
    expect(app(CouponService::class)->discountFor(climPartial($coupon), $cart))->toBe(500);

    // Sensitive to the constant itself: missing only limit_usage_to_x_items,
    // so the re-read happens if and only if that name is on the list.
    expect(app(CouponService::class)->discountFor(climAllRuleColumnsExcept($coupon, 'limit_usage_to_x_items'), $cart))
        ->toBe(500);
});

it('prices an item cap set through the admin editor exactly as the shop does', function () {
    $serum = climProduct('Editor Serum', 10000);
    $toner = climProduct('Editor Toner', 6000);

    $id = climPost([
        'code' => 'EDITORCAP',
        'type' => 'percent',
        'amount' => '20',
        'limit_usage_to_x_items' => 2,
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);

    expect($coupon->limit_usage_to_x_items)->toBe(2);

    $cart = climCart($coupon, [[$serum, 1], [$toner, 3]]);

    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(2400);
});

/* =====================================================================
 | 2. PRODUCT BRANDS / EXCLUDE BRANDS
 ==================================================================== */

it('discounts only the named brands', function () {
    $anua = climBrand('Anua');
    $roundLab = climBrand('Round Lab');

    $anuaToner = climProduct('Anua Toner', 5000, $anua);
    $rlCream = climProduct('Round Lab Cream', 8000, $roundLab);
    $noBrand = climProduct('Unbranded Puffs', 3000);

    $coupon = Coupon::create([
        'code' => 'ANUAONLY',
        'type' => 'percent',
        'amount' => 1000,
        'brand_ids' => [$anua->id],
    ]);

    $cart = climCart($coupon, [[$anuaToner, 1], [$rlCream, 1], [$noBrand, 1]]);

    // 10% of the AED 50 Anua line alone. 10% of the whole AED 160 basket is
    // 1,600 — the figure a brand rule that failed open would pay.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(500);
});

it('never discounts an excluded brand', function () {
    $anua = climBrand('Anua');
    $roundLab = climBrand('Round Lab');

    $anuaToner = climProduct('Anua Toner', 5000, $anua);
    $rlCream = climProduct('Round Lab Cream', 8000, $roundLab);
    $noBrand = climProduct('Unbranded Puffs', 3000);

    $coupon = Coupon::create([
        'code' => 'NOTANUA',
        'type' => 'percent',
        'amount' => 1000,
        'excluded_brand_ids' => [$anua->id],
    ]);

    $cart = climCart($coupon, [[$anuaToner, 1], [$rlCream, 1], [$noBrand, 1]]);

    // Everything except the Anua line: 8000 + 3000 = 11,000 -> 1,100 fils.
    // A product with no brand at all is not "in" the excluded brand, so it
    // stays eligible.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(1100);
});

it('does not let a partial coupon instance escape the brand rules', function () {
    $anua = climBrand('Anua');
    $anuaToner = climProduct('Anua Toner', 5000, $anua);
    $other = climProduct('Other Cream', 11000);

    $coupon = Coupon::create([
        'code' => 'BRANDPARTIAL',
        'type' => 'percent',
        'amount' => 1000,
        'brand_ids' => [$anua->id],
    ]);

    $cart = climCart($coupon, [[$anuaToner, 1], [$other, 1]]);

    /*
     * THE FAIL-OPEN THIS FILE EXISTS FOR. brand_ids is absent from
     * `coupon:id,code,type,amount`, so on that instance it reads null and the
     * `if ($coupon->brand_ids && ...)` clause is skipped entirely — the code
     * discounts the whole basket and nothing anywhere reports it. withRules()
     * defeats that only if brand_ids is named in RULE_COLUMNS. Drop it from
     * that list and this assertion reads 1,600 instead of 500.
     */
    expect(app(CouponService::class)->discountFor(climPartial($coupon), $cart))->toBe(500);

    /*
     * And the assertion that actually pins RULE_COLUMNS. The instance above is
     * missing every rule column, so withRules() re-reads whatever the list
     * says; this one is missing ONLY brand_ids, so the re-read happens if and
     * only if brand_ids is named. Drop that one string from the constant and
     * this reads 1,600 — the whole basket discounted by a code fenced to one
     * brand, with no error anywhere.
     */
    expect(app(CouponService::class)->discountFor(climAllRuleColumnsExcept($coupon, 'brand_ids'), $cart))
        ->toBe(500);
});

it('names every column the pricing path reads in RULE_COLUMNS', function () {
    /*
     * The list is the load-bearing part of withRules(), and the cost of a name
     * missing from it is not an error — it is a rule that silently does not
     * apply and a discount larger than the owner authorised. Each of these is
     * read off the coupon by discountFor(), eligibleItems() or
     * grantsFreeShipping(), none of them is in the `coupon:id,code,type,amount`
     * the storefront eager-loads, and every one of them fails OPEN when absent.
     */
    $columns = climRuleColumns();

    foreach ([
        'exclude_sale_items',
        'product_ids',
        'excluded_product_ids',
        'category_ids',
        'excluded_category_ids',
        'brand_ids',
        'excluded_brand_ids',
        'limit_usage_to_x_items',
        'free_shipping',
    ] as $column) {
        expect(in_array($column, $columns, true))
            ->toBeTrue($column . ' is read by the pricing path but is not in CouponService::RULE_COLUMNS, '
                . 'so it reads null on the coupon instance the storefront hands in and the rule fails open.');
    }
});

it('refuses a brand-restricted code against a basket holding none of that brand', function () {
    $anua = climBrand('Anua');
    $other = climProduct('Other Cream', 11000);

    $coupon = Coupon::create([
        'code' => 'ANUAMISS',
        'type' => 'percent',
        'amount' => 1000,
        'brand_ids' => [$anua->id],
    ]);

    $cart = climCart($coupon, [[$other, 1]]);

    $check = app(CouponService::class)->validate('ANUAMISS', $cart);

    expect($check['ok'])->toBeFalse();
    expect(str_contains((string) $check['error'], 'does not apply to anything in your basket'))
        ->toBeTrue('A brand-restricted code must be refused, not silently applied to nothing.');
});

it('prices brand rules set through the admin editor exactly as the shop does', function () {
    $anua = climBrand('Anua');
    $roundLab = climBrand('Round Lab');

    $anuaToner = climProduct('Anua Toner', 5000, $anua);
    $rlCream = climProduct('Round Lab Cream', 8000, $roundLab);

    $id = climPost([
        'code' => 'EDITORBRAND',
        'type' => 'percent',
        'amount' => '10',
        'brand_ids' => [$anua->id],
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);

    expect($coupon->brand_ids)->toBe([$anua->id]);

    $cart = climCart($coupon, [[$anuaToner, 1], [$rlCream, 1]]);

    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(500);
});

it('stores an empty brand selection as null, the way the other id lists are stored', function () {
    $id = climPost(['code' => 'NOBRANDRULE', 'brand_ids' => [], 'excluded_brand_ids' => []])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);

    // [] and null both mean "no restriction" to eligibleItems(), but null is
    // what the import wrote and what every existing row holds. Two spellings
    // of the same thing in one column is how a later `!== null` check ends up
    // meaning the opposite of what it reads.
    expect($coupon->getRawOriginal('brand_ids'))->toBeNull();
    expect($coupon->getRawOriginal('excluded_brand_ids'))->toBeNull();
});

it('reads brands back to the editor when a saved coupon is opened', function () {
    $anua = climBrand('Anua');

    $id = climPost(['code' => 'READBACK', 'brand_ids' => [$anua->id]])
        ->assertCreated()->json('id');

    $payload = test()->actingAs(test()->admin, 'admin')
        ->getJson('/admin-api/coupons/manage/' . $id)
        ->assertOk()
        ->json('coupon');

    expect($payload['brands'][0]['id'])->toBe($anua->id);
    expect($payload['brands'][0]['label'])->toBe('Anua');
    expect($payload['limit_usage_to_x_items'])->toBeNull();
});

it('looks brands up for the picker', function () {
    $anua = climBrand('Anua');

    $items = test()->actingAs(test()->admin, 'admin')
        ->getJson('/admin-api/coupons/manage/lookup?kind=brand&q=anu')
        ->assertOk()
        ->json('items');

    expect(collect($items)->pluck('id')->all())->toContain($anua->id);
});

/* =====================================================================
 | 3. FREE SHIPPING FROM A COUPON
 ==================================================================== */

it('zeroes the delivery line when the applied coupon grants free shipping', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'FREEDELIVERY',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => true,
    ]);

    $cart = climCart($coupon, [[$product, 1]]);

    $totals = app(CartService::class)->totals($cart, 'AE', null, 2000);

    expect($totals['shipping'])->toBe(0);
    expect($totals['total'])->toBe(9000 - 900);
});

it('still charges delivery when the coupon does not grant free shipping', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'PAIDDELIVERY',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => false,
    ]);

    $cart = climCart($coupon, [[$product, 1]]);

    $totals = app(CartService::class)->totals($cart, 'AE', null, 2000);

    expect($totals['shipping'])->toBe(2000);
    expect($totals['total'])->toBe(9000 - 900 + 2000);
});

it('does not let a partial coupon instance hide the free-shipping flag', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'FREEPARTIAL',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => true,
    ]);

    $cart = climCart($coupon, [[$product, 1]]);

    // The relation the storefront eager-loads, put back on the cart in place
    // of the complete row, exactly as CartDrawerComposer leaves it.
    $cart->setRelation('coupon', climPartial($coupon));

    expect(app(CartService::class)->totals($cart, 'AE', null, 2000)['shipping'])->toBe(0);

    // Sensitive to the constant: missing only free_shipping, so the re-read
    // happens if and only if that name is on the list. Without it the flag
    // reads null and the shopper is charged AED 20 the code promised to cover.
    $cart->setRelation('coupon', climAllRuleColumnsExcept($coupon, 'free_shipping'));

    expect(app(CartService::class)->totals($cart, 'AE', null, 2000)['shipping'])->toBe(0);
});

it('writes a zero shipping_total to the order when the coupon grants free shipping', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'FREEATCHECKOUT',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => true,
    ]);

    climPlaceOrder(climCart($coupon, [[$product, 1]]));

    $order = Order::latest('id')->first();

    /*
     * shipping_total came off `$rate['cost']` rather than off the totals the
     * same request had just computed, so a zeroed delivery line would have
     * left the order saying AED 20 of shipping against a total that did not
     * include it — the two halves of one order disagreeing by the whole rate.
     */
    expect($order->shipping_total)->toBe(0);
    expect($order->discount_total)->toBe(900);
    expect($order->total)->toBe(9000 - 900);
});

it('writes the real shipping_total to the order when the coupon does not grant it', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $coupon = Coupon::create([
        'code' => 'PAIDATCHECKOUT',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => false,
    ]);

    climPlaceOrder(climCart($coupon, [[$product, 1]]));

    $order = Order::latest('id')->first();

    expect($order->shipping_total)->toBe(2000);
    expect($order->total)->toBe(9000 - 900 + 2000);
});

it('charges delivery on a basket carrying no coupon at all', function () {
    $product = climProduct('Cleansing Balm', 9000);

    $totals = app(CartService::class)->totals(climCart(null, [[$product, 1]]), 'AE', null, 2000);

    expect($totals['shipping'])->toBe(2000);
});

it('grants free shipping from a coupon created through the admin editor', function () {
    $product = climProduct('Editor Balm', 9000);

    $id = climPost([
        'code' => 'EDITORFREESHIP',
        'type' => 'percent',
        'amount' => '10',
        'free_shipping' => true,
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);

    expect($coupon->free_shipping)->toBeTrue();

    $cart = climCart($coupon, [[$product, 1]]);

    expect(app(CartService::class)->totals($cart, 'AE', null, 2000)['shipping'])->toBe(0);
});

/* =====================================================================
 | 4. THE THREE TOGETHER
 ==================================================================== */

it('applies a brand rule, an item cap and free shipping on one basket', function () {
    $anua = climBrand('Anua');

    $anuaToner = climProduct('Anua Toner', 6000, $anua);
    $anuaSerum = climProduct('Anua Serum', 11000, $anua);
    $other = climProduct('Other Cream', 8000);

    $id = climPost([
        'code' => 'BIGONE',
        'type' => 'percent',
        'amount' => '25',
        'brand_ids' => [$anua->id],
        'limit_usage_to_x_items' => 2,
        'free_shipping' => true,
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $cart = climCart($coupon, [[$anuaSerum, 1], [$anuaToner, 2], [$other, 1]]);

    /*
     * The other cream is not Anua, so it is out. That leaves three Anua units;
     * two may be discounted, cheapest first, so both toners: 12,000 fils.
     * 25% of 12,000 is 3,000. Delivery is free on top.
     */
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(3000);

    $totals = app(CartService::class)->totals($cart, 'AE', null, 2000);

    expect($totals['subtotal'])->toBe(11000 + 12000 + 8000);
    expect($totals['discount'])->toBe(3000);
    expect($totals['shipping'])->toBe(0);
    expect($totals['total'])->toBe(31000 - 3000);
});
