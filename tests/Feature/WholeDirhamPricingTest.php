<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Services\BundleService;
use App\Services\CouponService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\TaxRule;
use App\Support\WholeDirhams;
use Illuminate\Support\Str;
use Tests\Support\CatalogProductsAdminRoutes;
use Tests\Support\CouponsAdminRoutes;
use Tests\Support\ProductEditorRoutes;

/**
 * "no decimals. if any decimals comes. adjust to the price." — the owner,
 * asked how to fix a basket that printed 90 − 1 = 90.
 *
 * This file pins the policy those nine words describe, in the two halves it
 * actually has:
 *
 *   TYPED money is REFUSED when it carries fils. The operator sees what he set.
 *   DERIVED money is ADJUSTED to a whole dirham, in a stated direction.
 *
 * and the third half, which is the one worth having a test for: the figures
 * that CANNOT be made whole, pinned as they are so that nobody later rounds
 * them into a ledger that no longer adds up. See the exclusive-VAT section at
 * the bottom.
 */
beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
});

function wdAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Whole Dirham Owner',
        'email' => 'wd-' . uniqid() . '@kbb.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

function wdEditorPayload(array $over = []): array
{
    $brand = Brand::create(['name' => 'WD Brand ' . uniqid(), 'slug' => 'wd-brand-' . uniqid()]);
    $cat = Category::create(['name' => 'WD Cat ' . uniqid(), 'slug' => 'wd-cat-' . uniqid()]);

    return array_merge([
        'name' => 'WD Product ' . uniqid(),
        'slug' => 'wd-product-' . uniqid(),
        'brand_id' => $brand->id,
        'category_ids' => [$cat->id],
        'primary_category_id' => $cat->id,
        'price_aed' => '99',
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
    ], $over);
}

/* ================================================================ THE UNIT */

it('knows a whole dirham from one carrying fils', function () {
    expect(WholeDirhams::isWhole(9900))->toBeTrue()
        ->and(WholeDirhams::isWhole(9980))->toBeFalse()
        ->and(WholeDirhams::nearest(9980))->toBe(10000)
        ->and(WholeDirhams::nearest(9940))->toBe(9900)
        // Half away from zero, the same rule every other money path here uses.
        ->and(WholeDirhams::nearest(9950))->toBe(10000)
        ->and(WholeDirhams::nearest(-9950))->toBe(-10000)
        ->and(WholeDirhams::toward(13930))->toBe(13900)
        ->and(WholeDirhams::away(1990))->toBe(2000)
        ->and(WholeDirhams::away(2000))->toBe(2000);
});

/* ========================================================= TYPED: REFUSALS */

it('refuses a product price carrying fils', function () {
    ProductEditorRoutes::wire(app());
    wdAdmin();

    test()->postJson('/admin-api/product-editor-create', wdEditorPayload(['price_aed' => '99.80']))
        ->assertStatus(422);

    expect(Product::where('price', 9980)->exists())->toBeFalse();
});

it('refuses a sale price carrying fils', function () {
    ProductEditorRoutes::wire(app());
    wdAdmin();

    test()->postJson('/admin-api/product-editor-create', wdEditorPayload([
        'price_aed' => '199',
        'sale_aed' => '149.50',
    ]))->assertStatus(422);
});

it('accepts a whole product price', function () {
    ProductEditorRoutes::wire(app());
    wdAdmin();

    test()->postJson('/admin-api/product-editor-create', wdEditorPayload(['price_aed' => '199']))
        ->assertStatus(201);

    expect(Product::where('price', 19900)->exists())->toBeTrue();
});

/*
 * ROUND-TRIP SAFETY. An existing 9,980-fil price is a price the owner set
 * before this policy existed. Editing the product's NAME must not move it.
 * Money that changes because somebody edited a description is the worst
 * outcome available here — worse than a price with fils in it.
 */
it('leaves an existing price with fils alone when another field is saved', function () {
    ProductEditorRoutes::wire(app());
    wdAdmin();

    $product = Product::create([
        'name' => 'Legacy Priced',
        'slug' => 'legacy-priced-' . uniqid(),
        'price' => 9980,
        'status' => 'publish',
        'is_visible' => true,
    ]);

    test()->postJson('/admin-api/product-editor-save/' . $product->id, [
        'name' => 'Legacy Priced, renamed',
        'price_aed' => Money::decimalString(9980),
    ])->assertStatus(200);

    expect((int) $product->fresh()->price)->toBe(9980);
});

it('still refuses a NEW fils price on a product that already carries one', function () {
    ProductEditorRoutes::wire(app());
    wdAdmin();

    $product = Product::create([
        'name' => 'Legacy Priced Two',
        'slug' => 'legacy-priced-two-' . uniqid(),
        'price' => 9980,
        'status' => 'publish',
        'is_visible' => true,
    ]);

    test()->postJson('/admin-api/product-editor-save/' . $product->id, ['price_aed' => '89.70'])
        ->assertStatus(422);

    expect((int) $product->fresh()->price)->toBe(9980);
});

it('refuses a fils price typed into the catalogue list', function () {
    CatalogProductsAdminRoutes::wire(app());
    wdAdmin();

    $product = Product::create([
        'name' => 'Inline Edited',
        'slug' => 'inline-edited-' . uniqid(),
        'price' => 19900,
        'status' => 'publish',
        'is_visible' => true,
    ]);

    test()->postJson('/admin-api/catalog-products-save/' . $product->id, ['price' => '149.50'])
        ->assertStatus(422);

    expect((int) $product->fresh()->price)->toBe(19900);
});

it('refuses a fixed-amount coupon carrying fils', function () {
    CouponsAdminRoutes::wire(app());
    wdAdmin();

    test()->postJson('/admin-api/coupons/manage', [
        'code' => 'FILSY', 'type' => 'fixed_cart', 'amount' => '12.50',
    ])->assertStatus(422);

    expect(Coupon::where('code', 'FILSY')->exists())->toBeFalse();
});

/*
 * A PERCENTAGE IS NOT MONEY. 10.5% is a rate, it is stored in hundredths of a
 * percent, and refusing its decimals would be applying a money rule to a
 * number that is not money. The same box, the same column, two meanings —
 * which is exactly why this has its own test.
 */
it('still accepts a percentage coupon with decimals', function () {
    CouponsAdminRoutes::wire(app());
    wdAdmin();

    test()->postJson('/admin-api/coupons/manage', [
        'code' => 'TENPTFIVE', 'type' => 'percent', 'amount' => '10.5',
    ])->assertStatus(201);

    expect((int) Coupon::where('code', 'TENPTFIVE')->first()->amount)->toBe(1050);
});

it('refuses a store money setting that is not a whole dirham', function () {
    wdAdmin();

    foreach (['cod_fee', 'gift_fee', 'free_ship', 'delivery_flat'] as $key) {
        test()->putJson('/admin-api/settings', ['settings' => [$key => '1250']])
            ->assertStatus(422);

        expect(Setting::where('key', $key)->value('value'))->not->toBe('1250');
    }
});

it('accepts a store money setting that is a whole dirham', function () {
    wdAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['cod_fee' => '1200']])->assertStatus(200);

    expect((string) Setting::where('key', 'cod_fee')->value('value'))->toBe('1200');
});

it('refuses a merchant shipping cost carrying fils', function () {
    wdAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['merchant_ship_cost' => '12.50']])
        ->assertStatus(422);
});

it('refuses a COD fee carrying fils on the ecommerce screen', function () {
    wdAdmin();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['cod_fee' => '750']])
        ->assertStatus(422);
});

it('refuses a delivery rate and a free-delivery threshold carrying fils', function () {
    wdAdmin();

    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    $flat = ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard',
        'enabled' => true, 'cost' => 2000, 'position' => 0,
    ]);
    $free = ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'free_shipping', 'title' => 'Free',
        'enabled' => true, 'cost' => 0, 'min_amount' => 20000, 'position' => 1,
    ]);

    test()->postJson('/admin-api/shipping', ['methods' => [
        ['id' => $flat->id, 'title' => 'Standard', 'cost' => 1250, 'enabled' => true],
    ]])->assertStatus(422);

    test()->postJson('/admin-api/shipping', ['methods' => [
        ['id' => $free->id, 'title' => 'Free', 'cost' => 0, 'min_amount' => 19950, 'enabled' => true],
    ]])->assertStatus(422);

    expect((int) $flat->fresh()->cost)->toBe(2000)
        ->and((int) $free->fresh()->min_amount)->toBe(20000);
});

it('refuses a payment rule bound carrying fils', function () {
    wdAdmin();

    test()->postJson('/admin-api/pay-ship-rules', ['settings' => ['cod_min' => 9950]])
        ->assertStatus(422);

    // And accepts the same bound on the dirham grid, so the refusal above is
    // about the fils and not about the endpoint.
    test()->postJson('/admin-api/pay-ship-rules', ['settings' => ['cod_min' => 10000]])
        ->assertStatus(200);
});

/*
 * MANUAL ORDERS. An order is where every other figure in this shop ends up, so
 * a line priced at AED 99.80 puts a fil into a subtotal, a total, an invoice, a
 * receipt and a card capture at once.
 */
it('refuses a manual order line price carrying fils', function () {
    wdAdmin();

    $order = Order::create([
        'order_number' => 'WD-' . uniqid(), 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 19900, 'total' => 19900, 'email' => 'buyer@kbb.test',
    ]);

    $item = $order->items()->create([
        'name' => 'A Product', 'quantity' => 1,
        'unit_price' => 19900, 'subtotal' => 19900, 'total' => 19900,
    ]);

    test()->putJson('/admin-api/orders/' . $order->id . '/items/' . $item->id, [
        'unit_price_aed' => '99.80',
    ])->assertStatus(422);

    expect((int) $item->fresh()->unit_price)->toBe(19900);
});

it('accepts a whole manual order line price', function () {
    wdAdmin();

    $order = Order::create([
        'order_number' => 'WD-' . uniqid(), 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 19900, 'total' => 19900, 'email' => 'buyer2@kbb.test',
    ]);

    $item = $order->items()->create([
        'name' => 'A Product', 'quantity' => 1,
        'unit_price' => 19900, 'subtotal' => 19900, 'total' => 19900,
    ]);

    test()->putJson('/admin-api/orders/' . $order->id . '/items/' . $item->id, [
        'unit_price_aed' => '99',
    ])->assertStatus(200);

    expect((int) $item->fresh()->unit_price)->toBe(9900);
});

it('refuses a per-country delivery charge and threshold carrying fils', function () {
    wdAdmin();

    $base = [
        'on' => true, 'detect' => false, 'show_all' => false,
        'rows' => [['code' => 'SA', 'enabled' => true, 'charge' => 1250, 'free_from' => null, 'eta' => '3-5 days']],
    ];

    test()->postJson('/admin-api/extended-delivery', $base)->assertStatus(422);

    $base['rows'][0]['charge'] = 1200;
    $base['rows'][0]['free_from'] = 19950;

    test()->postJson('/admin-api/extended-delivery', $base)->assertStatus(422);

    expect(\App\Models\DeliveryCountry::query()->where('code', 'SA')->exists())->toBeFalse();

    $base['rows'][0]['free_from'] = 20000;

    test()->postJson('/admin-api/extended-delivery', $base)->assertStatus(200);

    expect((int) \App\Models\DeliveryCountry::query()->where('code', 'SA')->value('charge'))->toBe(1200);
});

/* ====================================================== DERIVED: ADJUSTED */

/*
 * 10% off AED 199 is AED 19.90 exactly, and there is nobody to refuse: the
 * shopper typed a code, not an amount. So it is ADJUSTED — UP, to AED 20,
 * which is the direction that favours the shopper. See the lane report for
 * why the shop pays the at-most-99-fil difference rather than the customer.
 */
it('rounds a percentage discount up to a whole dirham', function () {
    $coupon = Coupon::create(['code' => 'TEN', 'type' => 'percent', 'amount' => 1000]);

    $product = Product::create([
        'name' => 'One Nine Nine', 'slug' => 'one-nine-nine-' . uniqid(),
        'price' => 19900, 'status' => 'publish', 'is_visible' => true,
    ]);

    $cart = \App\Models\Cart::create(['token' => Str::uuid()->toString()]);
    $cart->items()->create([
        'product_id' => $product->id, 'quantity' => 1,
        'unit_price' => 19900,
    ]);
    $cart->load('items');

    $discount = app(CouponService::class)->discountFor($coupon, $cart);

    expect($discount)->toBe(2000)
        ->and(WholeDirhams::isWhole($discount))->toBeTrue()
        // And the ledger sums, in whole dirhams, with nothing left over.
        ->and(19900 - $discount)->toBe(17900);
});

/*
 * A percentage bundle tier on an odd price. 30% off AED 199 is AED 139.30.
 * Rounded TOWARD ZERO on the unit, so the shopper pays less rather than more
 * and `unit x qty` stays exactly the line total the cart stores.
 */
it('rounds a bundle unit price down to a whole dirham', function () {
    app(SettingsService::class)->set('bundles_enabled', '1');
    app(SettingsService::class)->set('bundle_tiers', json_encode([
        ['qty' => 1, 'discount' => 0, 'label' => '1'],
        ['qty' => 2, 'discount' => 30, 'label' => '2-pack'],
    ]));
    SettingsService::forgetMemo();

    $bundles = app(BundleService::class);

    expect($bundles->unitFor(19900, 2))->toBe(13900)
        ->and($bundles->totalFor(19900, 2))->toBe(27800)
        ->and(WholeDirhams::isWhole($bundles->totalFor(19900, 2)))->toBeTrue();
});

/* ============================================ WHAT CANNOT BE MADE WHOLE */

/*
 * INCLUSIVE VAT IS A NOTE, NOT A ROW. "Of which AED 4.76 is VAT" carries fils
 * and is CORRECT carrying them: it is a statement about a whole-dirham total,
 * not a line in the sum. The total is untouched, so the ledger still adds up,
 * and rounding this figure would make it a false statement about a real tax.
 *
 * Pinned rather than fixed. If a later lane "tidies" this to a whole dirham,
 * this test says why not.
 */
it('leaves inclusive VAT carrying fils, and outside the total', function () {
    $rule = new TaxRule(5.0, TaxRule::INCLUSIVE);

    expect($rule->taxOn(10000))->toBe(476)
        ->and(WholeDirhams::isWhole($rule->taxOn(10000)))->toBeFalse()
        // The total does not move, which is what keeps the column adding up.
        ->and($rule->grossOf(10000))->toBe(10000);
});

/*
 * EXCLUSIVE VAT GENUINELY BREAKS THE POLICY, and this test exists to say so
 * out loud rather than to hide it. 5% on top of AED 199 is AED 9.95, and the
 * customer is charged AED 208.95 — a total carrying fils, from a basket in
 * which every typed figure was whole.
 *
 * NOT ROUNDED HERE. The figure is a statutory percentage of a base; rounding
 * it would change the tax charged and recorded, which is not the shop's to
 * round. The lane report puts the choice in front of the owner instead.
 *
 * This is reachable only with tax_mode = 'live' AND a country on an exclusive
 * basis — neither of which is the shipped configuration.
 */
it('records that exclusive VAT still produces a total carrying fils', function () {
    $rule = new TaxRule(5.0, TaxRule::EXCLUSIVE);

    expect($rule->taxOn(19900))->toBe(995)
        ->and($rule->grossOf(19900))->toBe(20895)
        ->and(WholeDirhams::isWhole($rule->grossOf(19900)))->toBeFalse();
});

/* ====================================== PART 4: THE DISPLAY FOLLOWS SUIT */

it('picks the narrowest width that states every figure exactly', function () {
    // Whole dirhams throughout -> the store's own display width, which is 0.
    expect(Money::receiptDecimals(20000, 2000, 22000))->toBe(0)
        // One figure carrying fils widens the WHOLE set, not just that figure.
        ->and(Money::receiptDecimals(9040, 60, 8980))->toBe(2)
        ->and(Money::receiptDecimals(22000, 2000, 1))->toBe(2)
        ->and(Money::receiptDecimals())->toBe(0);
});

/*
 * THE ORDER THE COORDINATOR NAMED. Lane EZ pinned the receipts to
 * Money::minorExponent(), which was right while fils were ordinary. Under the
 * whole-dirham policy it prints "AED 220.00" at an owner who has just said "no
 * decimals" — on a figure where the decimals state nothing the whole form does
 * not.
 */
it('prints a whole-dirham receipt in whole dirhams, on screen and in the email', function () {
    $customer = \App\Models\Customer::create([
        'name' => 'Ada Shopper', 'email' => 'whole-' . uniqid() . '@kbb.test', 'password' => 'password123',
    ]);

    $order = Order::create([
        'order_number' => 'WD-DISPLAY-' . uniqid(),
        'customer_id' => $customer->id, 'email' => $customer->email,
        'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 20000, 'discount_total' => 0, 'shipping_total' => 2000,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 22000,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
        ],
    ]);

    $order->items()->create([
        'name' => 'Rice Toner', 'quantity' => 2,
        'unit_price' => 10000, 'subtotal' => 20000, 'total' => 20000,
    ]);

    $order = $order->fresh();
    $presented = app(\App\Services\Mail\OrderEmailPresenter::class)->present($order);

    // The emailed copy.
    expect($presented['totalPlain'])->toContain('220')
        ->and($presented['totalPlain'])->not->toContain('220.00');

    // The customer's own copy, read out of the rendered page.
    $html = test()
        ->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customer->id])
        ->get('/my-account/orders/' . $order->id)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('220')
        ->and(str_contains($html, '220.00'))->toBeFalse();
});

/*
 * AND THE SAFETY NET STAYS UP. An order from before the policy still carries
 * fils, and its receipt still widens — as a whole column, so it still adds up.
 * This is the case Lane EZ found and it must not regress.
 */
it('still prints a legacy receipt at full precision, so the column adds up', function () {
    $customer = \App\Models\Customer::create([
        'name' => 'Ada Legacy', 'email' => 'legacy-' . uniqid() . '@kbb.test', 'password' => 'password123',
    ]);

    $order = Order::create([
        'order_number' => 'WD-LEGACY-' . uniqid(),
        'customer_id' => $customer->id, 'email' => $customer->email,
        'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 9040, 'discount_total' => 60, 'coupon_code' => 'TINY',
        'shipping_total' => 0, 'fee_total' => 0, 'gift_fee' => 0,
        'tax_total' => 0, 'total' => 8980,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        'shipping_address' => [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '12 Marina Walk',
            'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE',
        ],
    ]);

    $order->items()->create([
        'name' => 'Rice Toner', 'quantity' => 2,
        'unit_price' => 4520, 'subtotal' => 9040, 'total' => 9040,
    ]);

    $order = $order->fresh();
    $presented = app(\App\Services\Mail\OrderEmailPresenter::class)->present($order);

    expect($presented['totalPlain'])->toContain('89.80');

    $html = test()
        ->withSession(['login_customer_' . sha1(\Illuminate\Auth\SessionGuard::class) => $customer->id])
        ->get('/my-account/orders/' . $order->id)
        ->assertOk()
        ->getContent();

    // 90.40 − 0.60 = 89.80, all three printed, so the column a customer reads
    // down actually sums.
    expect($html)->toContain('90.40')
        ->and($html)->toContain('0.60')
        ->and($html)->toContain('89.80');
});

/*
 * THE BASKET THIS LANE STARTED FROM. The owner was shown
 *
 *     Subtotal AED 90 / − AED 1 / Total AED 90
 *
 * on a basket of AED 90.40 with a 60-fil discount, and answered "no decimals.
 * if any decimals comes. adjust to the price."
 *
 * Under the policy his prices are whole, so the ordinary basket prints whole
 * dirhams and the column sums exactly — which is what the first test asserts.
 * The second is the safety net for a basket that still holds a price from
 * before the policy: it widens as a WHOLE column rather than rounding rows
 * into a sum that does not work.
 */
it('prints a whole-dirham basket in whole dirhams, and the column adds up', function () {
    $product = Product::create([
        'name' => 'Whole Priced', 'slug' => 'whole-priced-' . uniqid(),
        'price' => 19900, 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock',
    ]);

    $carts = app(\App\Services\CartService::class);
    $cart = $carts->create();
    $carts->add($cart, $product, 1);

    $totals = $carts->totals($cart->fresh(['items.product', 'items.variant', 'coupon']), 'AE');

    expect($totals['decimals'])->toBe(0)
        ->and(strip_tags(Money::format($totals['subtotal'], $totals['decimals'])))->toContain('199')
        ->and(strip_tags(Money::format($totals['subtotal'], $totals['decimals'])))->not->toContain('.');
});

it('widens the whole basket column when a price from before the policy is in it', function () {
    // 9,040 fils — the owner's own AED 90.40, which the audit command reports
    // and which no screen can create any more.
    $product = Product::create([
        'name' => 'Legacy Priced Cart', 'slug' => 'legacy-priced-cart-' . uniqid(),
        'price' => 9040, 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock',
    ]);

    $carts = app(\App\Services\CartService::class);
    $cart = $carts->create();
    $carts->add($cart, $product, 1);

    $totals = $carts->totals($cart->fresh(['items.product', 'items.variant', 'coupon']), 'AE');

    // The whole column widens, not just the row that needs it — which is what
    // makes "Subtotal 90.40 / Total 90.40" read as arithmetic rather than as
    // two figures at two precisions.
    expect($totals['decimals'])->toBe(2)
        ->and(strip_tags(Money::format($totals['subtotal'], $totals['decimals'])))->toContain('90.40')
        ->and(strip_tags(Money::format($totals['total'], $totals['decimals'])))->toContain('90.40');
});
