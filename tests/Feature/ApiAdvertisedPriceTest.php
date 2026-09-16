<?php

declare(strict_types=1);

/**
 * WHAT /api/* ADVERTISES IS WHAT /api/* CHARGES.
 *
 * THE DEFECT THIS PINS.
 *
 * Product::toApi() published the raw columns:
 *
 *     'price'      => $this->price,
 *     'sale_price' => $this->sale_price,
 *
 * `sale_price` is a stored markdown with a schedule beside it —
 * `sale_starts_at` and `sale_ends_at` — and toApi() never looked at either.
 * So the public product feed advertised a sale price for a sale that ended
 * last month, and for one that does not open until next week, on a catalogue
 * where the owner is expected to schedule markdowns in advance and leave them
 * in place afterwards.
 *
 * Api\CheckoutController, one directory along, already got this right: "
 * effectivePrice(), not sale_price ?? price. The raw column ignores
 * sale_starts_at and sale_ends_at, so an expired sale kept selling at the sale
 * price and a future one sold early." So the two halves of the same public,
 * unauthenticated surface disagreed about the price of the same product: the
 * read endpoint quoted AED 99 and the write endpoint charged AED 150.
 *
 * That is the one class of pricing bug a shopper can see happen to them. It is
 * also the same defect ProductVariant::effectivePrice() was repaired for (see
 * VariantSaleWindowTest) and the one Support\EffectivePrice exists to keep the
 * shop's sort and filter from re-introducing.
 *
 * AND THE TRAP IN FIXING IT — the last test in this file.
 *
 * Api\ProductController::INDEX_COLUMNS is a deliberate allowlist: the index is
 * unthrottled, so it hydrates named columns rather than whole rows. It listed
 * `sale_price` and NOT `sale_starts_at` / `sale_ends_at`. A window check
 * written straight onto those attributes therefore reads null for both on the
 * index — "no start bound, no end bound" — and concludes the sale is live.
 * It FAILS OPEN, on the exact endpoint the defect was reported against, and it
 * does so silently: the expired sale goes on being advertised and every test
 * written against the single-product endpoint (which selects everything) still
 * passes.
 *
 * CouponService::withRules() documents this same trap for coupon rules. The
 * fix here is both halves: the column list is widened so the window is
 * KNOWN, and the projection refuses to advertise a sale whose window it was
 * not given — absent columns mean "no sale", never "sale with no bounds".
 *
 * THE PUBLIC SHAPE DOES NOT CHANGE. `sale_starts_at` and `sale_ends_at` are
 * selected, never published; toApi() returns the same eleven keys it always
 * has (ApiProductIndexCostTest pins them). `sale_price` keeps its meaning for
 * every consumer that reads it — "the reduced price, or null if there is not
 * one" — it has simply stopped lying about when.
 */

use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

/** Prices are integer fils, like every money column in this schema. */
function advertisedProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'window-serum-' . uniqid(),
        'name' => 'Window Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 15000,        // AED 150.00
        'stock_status' => 'instock',
    ], $overrides));
}

/** The row this endpoint returns for one slug, from the INDEX. */
function indexRow(string $slug): ?array
{
    $body = test()->getJson('/api/products')->assertOk()->json();

    return collect($body)->firstWhere('slug', $slug);
}

it('advertises a sale that is running right now', function () {
    // The control. Without it the three tests below all pass on a projection
    // that simply never advertises a sale at all.
    $p = advertisedProduct([
        'sale_price' => 9900,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    expect(indexRow($p->slug)['sale_price'])->toBe(9900);
    expect($this->getJson("/api/products/{$p->slug}")->json('sale_price'))->toBe(9900);
});

it('does not advertise a sale that has already ended', function () {
    $p = advertisedProduct([
        'sale_price' => 9900,
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);

    expect($p->effectivePrice())->toBe(15000);

    expect(indexRow($p->slug)['sale_price'])->toBeNull();
    expect($this->getJson("/api/products/{$p->slug}")->json('sale_price'))->toBeNull();
});

it('does not advertise a sale that has not started yet', function () {
    $p = advertisedProduct([
        'sale_price' => 9900,
        'sale_starts_at' => now()->addWeek(),
        'sale_ends_at' => now()->addMonth(),
    ]);

    expect($p->effectivePrice())->toBe(15000);

    expect(indexRow($p->slug)['sale_price'])->toBeNull();
    expect($this->getJson("/api/products/{$p->slug}")->json('sale_price'))->toBeNull();
});

it('advertises an unscheduled sale, which is every sale the owner never dated', function () {
    // Both bounds null is not an expired window — it is a markdown with no
    // schedule, which is what most of this catalogue carries.
    $p = advertisedProduct(['sale_price' => 9900]);

    expect(indexRow($p->slug)['sale_price'])->toBe(9900);
});

it('quotes on the index exactly what the checkout endpoint charges', function () {
    /*
     * The defect stated as the shopper meets it: read the feed, order what it
     * quoted, compare the bill. Every branch of the window, one basket.
     */
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id,
        'type' => 'flat_rate',
        'title' => 'Standard delivery',
        'cost' => 2000,
        'enabled' => true,
        'position' => 0,
    ]);

    $expired = advertisedProduct([
        'sale_price' => 9900,
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);
    $future = advertisedProduct([
        'sale_price' => 8800,
        'sale_starts_at' => now()->addWeek(),
    ]);
    $live = advertisedProduct([
        'sale_price' => 7700,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);

    $quoted = [];
    foreach ([$expired, $future, $live] as $p) {
        $row = indexRow($p->slug);
        $quoted[$p->slug] = $row['sale_price'] ?? $row['price'];
    }

    $this->postJson('/api/checkout/session', [
        'items' => [
            ['slug' => $expired->slug, 'qty' => 1],
            ['slug' => $future->slug, 'qty' => 1],
            ['slug' => $live->slug, 'qty' => 1],
        ],
        'customer' => [
            'name' => 'Amina R.',
            'email' => 'amina@example.com',
            'country' => 'AE',
        ],
        'method' => 'cod',
    ])->assertSuccessful();

    $order = Order::latest('id')->firstOrFail();

    foreach ($order->items as $line) {
        $slug = Product::findOrFail($line->product_id)->slug;

        expect((int) $line->unit_price)->toBe(
            $quoted[$slug],
            "advertised {$quoted[$slug]} fils but charged {$line->unit_price} for {$slug}"
        );
    }
});

it('refuses to advertise a sale whose window it was not given', function () {
    /*
     * THE FAIL-OPEN CASE, asserted directly rather than through a route, so
     * that narrowing INDEX_COLUMNS again cannot make it pass by accident.
     *
     * This is a product hydrated WITHOUT the two date columns — precisely what
     * Api\ProductController::index used to build — whose sale expired a month
     * ago. A window check that reads the missing attributes gets null from
     * both, reads that as "unbounded", and advertises the dead sale.
     *
     * The absence of the columns is not evidence that the sale is live. The
     * projection must say "no sale" rather than guess.
     */
    $full = advertisedProduct([
        'sale_price' => 9900,
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subDay(),
    ]);

    $partial = Product::query()
        ->whereKey($full->id)
        ->select(['id', 'slug', 'name', 'price', 'sale_price', 'image', 'images',
            'rating', 'review_count', 'stock_status', 'short_description'])
        ->firstOrFail();

    // The premise: the window really is absent from this instance.
    expect(array_key_exists('sale_starts_at', $partial->getAttributes()))->toBeFalse();
    expect(array_key_exists('sale_ends_at', $partial->getAttributes()))->toBeFalse();
    expect((int) $partial->sale_price)->toBe(9900);

    expect($partial->toApi()['sale_price'])->toBeNull(
        'a projection that cannot see the sale window advertised the sale anyway'
    );
});
