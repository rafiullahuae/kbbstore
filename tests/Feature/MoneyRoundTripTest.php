<?php

declare(strict_types=1);

/**
 * Every screen that puts a price in a box must be able to save what it put there.
 *
 * ── THE INCOMPATIBILITY ─────────────────────────────────────────────────────
 *
 * Money::amount() groups thousands: 1,500,000 fils renders as "15,000.00".
 * MajorUnits::shape() — the anchored, digits-only regex the admin validates
 * every incoming amount with — refuses a comma on purpose, because a comma
 * means different things in different locales and guessing is how a price
 * becomes a thousand times itself.
 *
 * Put those two together and a screen that fills its price input from amount()
 * loads AED 15,000 correctly, shows it correctly, and then refuses to save a
 * value it produced itself, with a message telling the operator to enter a
 * plain number. Nothing is wrong with either function; the fault is only ever
 * in wiring one to the other.
 *
 * The first test below states that incompatibility directly, so it is on the
 * record rather than inferred from four screens failing.
 *
 * ── WHAT THIS FILE IS FOR ───────────────────────────────────────────────────
 *
 * Each remaining test takes one screen, loads a price at or above 1,000 — the
 * smallest amount that can carry a separator — and asserts that what the screen
 * hands the browser would pass that screen's own validator, then saves it back
 * and asserts the fils are unchanged.
 *
 * Every one of them passes today: the screens that round-trip prices each carry
 * a local workaround (ProductEditorApiController::editorAmount() strips the
 * grouping; CatalogProductsApiController::majorString() never adds it;
 * AdminOrderController goes through Money::toAed(), which does not group). This
 * file is not fixing them. It is pinning the property, because the workarounds
 * are private to four different classes and nothing until now stated the rule
 * they exist to keep — so the next screen to be written, or the next one to
 * switch to amount() because it is the obvious-looking helper, would
 * reintroduce it silently.
 *
 * Amounts are asserted as exact fils throughout. Nothing here goes through a
 * float, because `(int) (1.15 * 100)` is 114.
 */

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\MajorUnits;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Tests\Support\CatalogProductsAdminRoutes;

/** Would this screen's own validator accept this string? */
function mrtPassesShape(string $value): bool
{
    return ! Validator::make(['v' => $value], ['v' => MajorUnits::shape()])->fails();
}

function mrtAdmin(): AdminUser
{
    static $n = 0;
    $n++;

    return AdminUser::create([
        'name' => 'MRT Owner',
        'email' => 'mrt-owner-'.$n.'@kbeautybliss.test',
        'password' => 'password',
        'role' => 'admin',
    ]);
}

function mrtProduct(int $fils): Product
{
    static $n = 0;
    $n++;

    return Product::create([
        'name' => 'MRT Product '.$n,
        'slug' => 'mrt-product-'.strtr((string) $n, '0123456789', 'abcdefghij'),
        'sku' => 'MRT-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

/* ---------------------------------------------------------------- the rule */

it('shows why a formatted amount cannot be fed back into the validator', function () {
    // The formatter groups...
    expect(Money::amount(1500000, 2))->toBe('15,000.00');

    // ...and the validator refuses exactly what it produced.
    expect(mrtPassesShape('15,000.00'))->toBeFalse();

    // Below a thousand there is no separator, which is why this hid for so
    // long: every price anyone tested with round-tripped perfectly.
    expect(Money::amount(9950, 2))->toBe('99.50');
    expect(mrtPassesShape('99.50'))->toBeTrue();

    // The house conversion that is safe to put in an input, for contrast.
    expect(Money::decimalString(1500000))->toBe('15000.00');
    expect(mrtPassesShape('15000.00'))->toBeTrue();
});

it('refuses more precision than a fil can hold, at any magnitude', function () {
    expect(mrtPassesShape('0.145'))->toBeFalse();
    expect(mrtPassesShape('15000.145'))->toBeFalse();

    // And the parse is exact where it is allowed.
    expect(MajorUnits::fils('99.50'))->toBe(9950);
    expect(MajorUnits::fils('1.15'))->toBe(115);
    expect(MajorUnits::fils('15000.00'))->toBe(1500000);
});

/* ------------------------------------------------- Catalog -> Products */

it('round-trips a four-figure price through the Catalog Products panel', function () {
    CatalogProductsAdminRoutes::wire(app());

    test()->actingAs(mrtAdmin(), 'admin');

    $product = mrtProduct(1500000);          // AED 15,000.00

    // The panel wraps its payload in `product`.
    $detail = test()->getJson('/admin-api/catalog-products-detail/'.$product->id)
        ->assertOk()->json('product');

    // What the screen puts in the box.
    expect($detail['price_input'])->toBe('15000.00');

    // The screen's own validator would take it back.
    expect(mrtPassesShape($detail['price_input']))->toBeTrue(
        'Catalog → Products handed the browser "'.$detail['price_input']
        .'", which its own money rule refuses'
    );

    // And saving it back changes nothing.
    test()->postJson('/admin-api/catalog-products-save/'.$product->id, [
        'price' => $detail['price_input'],
    ])->assertOk();

    expect((int) $product->fresh()->price)->toBe(1500000);
});

/* ------------------------------------- the legacy /admin-api/products pair */

it('round-trips a four-figure price through the legacy product endpoint', function () {
    test()->actingAs(mrtAdmin(), 'admin');

    $product = mrtProduct(1500000);

    $loaded = test()->getJson('/admin-api/products/'.$product->id)->assertOk()->json();

    /*
     * This endpoint answers price_aed as exact major units through
     * Money::toMajor(), which returns a FLOAT and so cannot carry a separator.
     * That is why this screen was never part of the comma problem — but the
     * float is worth stating, because a float is how 1.15 becomes 114 and the
     * next person to reach for it should know it is only safe here because the
     * value goes straight back out as a string.
     */
    $asTyped = (string) $loaded['price_aed'];

    expect(mrtPassesShape($asTyped))->toBeTrue(
        'the legacy endpoint handed the browser "'.$asTyped.'", which MONEY_RULE refuses'
    );

    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => $asTyped])
        ->assertOk();

    expect((int) $product->fresh()->price)->toBe(1500000);
});

/**
 * The truncation this lane removed, stated as the endpoint sees it.
 *
 * The admin's legacy editor sent `parseInt(rp.value, 10)`, so a product loaded
 * at AED 99.50 was saved back as the integer 99 and the row lost 50 fils —
 * silently, and again every time the product was opened. The endpoint itself
 * was never wrong: handed the digits, it stores them exactly. What is pinned
 * here is that the two forms are NOT the same, so a client that truncates
 * cannot be mistaken for one that does not.
 */
it('stores exact fils from a decimal string, and fewer from a truncated one', function () {
    test()->actingAs(mrtAdmin(), 'admin');

    $product = mrtProduct(1000);

    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => '99.50'])->assertOk();
    expect((int) $product->fresh()->price)->toBe(9950);

    // What the old client actually sent for the same keystrokes.
    test()->putJson('/admin-api/products/'.$product->id, ['price_aed' => '99'])->assertOk();
    expect((int) $product->fresh()->price)->toBe(9900);
});

/* ------------------------------------------------ Orders -> line item price */

it('round-trips a four-figure line price through the order editor', function () {
    test()->actingAs(mrtAdmin(), 'admin');

    $customer = Customer::create([
        'name' => 'MRT Shopper', 'first_name' => 'MRT', 'last_name' => 'Shopper',
        'email' => 'mrt-shopper@example.ae', 'phone' => '+971500000009',
    ]);

    $order = Order::create([
        'order_number' => '90001',
        'customer_id' => $customer->id,
        'email' => 'mrt-shopper@example.ae',
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 1500000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 1500000,
        'payment_method' => 'cod',
    ]);

    $product = mrtProduct(1500000);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'name' => $product->name,
        'quantity' => 1,
        'unit_price' => 1500000,
        'total' => 1500000,
    ]);

    $detail = test()->getJson('/admin-api/orders/'.$order->id.'/detail')->assertOk()->json();

    $unit = (string) $detail['items'][0]['unit_price_aed'];

    /*
     * Fils::parse() — this screen's own parser, not MajorUnits — is what
     * validates it, and it is deliberately more forgiving: it ACCEPTS
     * "1,299.99". So this screen could survive a grouped value even if it were
     * handed one. Asserted anyway, because the value it is handed today is
     * ungrouped and a future change to that is exactly what this file is for.
     */
    expect($unit)->not->toContain(',');

    expect(App\Support\Fils::parse($unit))->toBe(1500000);
});
