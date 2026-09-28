<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a set publishes on the unauthenticated feed. (Lane SET)
 *
 * ▲ /api/* IS UNAUTHENTICATED. Every endpoint there is public, and a set's
 *   member is a PRODUCT — a `products` row carries `wc_id`, `sku` and
 *   `total_sales`, every one of which has leaked from this surface before.
 *   tests/Feature/ApiSecurityTest.php is the standing pin for the product feed;
 *   this file is the same rule for the one key a set adds.
 *
 * THE ABSENT KEYS ARE ASSERTED BY NAME rather than counted. A count passes the
 * day somebody swaps one key for another, and the point is not "five keys" —
 * it is that a supplier code and a sales figure are not on the wire.
 */
function apiSetFixture(int $setFils = 12000): Product
{
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'anua-'.Str::random(6)]);

    $member = Product::create([
        'slug' => 'toner-'.Str::random(6),
        'name' => 'Heartleaf Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'sku' => 'ANUA-HT-150',
        'wc_id' => random_int(50000, 59999) * 100 + random_int(0, 99),
        'total_sales' => 4321,
        'brand_id' => $brand->id,
        'stock_status' => 'instock',
    ]);

    $set = Product::create([
        'slug' => 'glow-set-'.Str::random(6),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $setFils,
        'sku' => 'SET-GLOW-'.Str::upper(Str::random(4)),
        'wc_id' => random_int(90000, 99999) * 100 + random_int(0, 99),
        'total_sales' => 77,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => $member->id,
        'quantity' => 2,
        'position' => 0,
    ]);

    return $set->fresh();
}

it('publishes a set through an allowlist and never the member model', function () {
    /*
     * MUTATION NOTE. Change SetContents::toApi() to return the member arrays
     * unchanged — `'members' => $contents['members']` — and this is red: `sku`
     * reaches the feed. Change Product::toApi() to publish
     * `$this->setItems->toArray()` and every one of the forbidden names below
     * appears. RUN.
     */
    $set = apiSetFixture();

    $json = $this->getJson('/api/products/'.$set->slug)->assertOk()->json();

    expect($json)->toHaveKey('set')
        ->and($json['set']['item_count'])->toBe(2)
        ->and($json['set']['parts_total'])->toBe(18000)
        ->and($json['set']['saving'])->toBe(6000)
        ->and($json['set']['members'])->toHaveCount(1);

    $member = $json['set']['members'][0];

    expect(array_keys($member))->toBe(['name', 'brand', 'variant', 'quantity', 'price'])
        ->and($member['name'])->toBe('Heartleaf Toner')
        ->and($member['brand'])->toBe('Anua')
        ->and($member['price'])->toBe(9000);

    // By name, not by count.
    $raw = json_encode($json);

    foreach (['wc_id', 'total_sales', 'sku', 'ANUA-HT-150', '4321'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});

it('adds nothing at all to an ordinary product', function () {
    /*
     * The key that keeps this feed unchanged for every consumer that has been
     * reading it for months: it is not present, and it is not null.
     *
     * MUTATION NOTE. Move the `set` assignment in Product::toApi() out of its
     * `if ($this->isSet())` — publishing `null` for everything else — and this
     * is red. RUN.
     */
    apiSetFixture();

    $plain = Product::create([
        'slug' => 'plain-'.Str::random(6),
        'name' => 'Plain Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
    ]);

    expect($this->getJson('/api/products/'.$plain->slug)->assertOk()->json())
        ->not->toHaveKey('set');
});

it('costs no query on a feed with no sets in it', function () {
    /*
     * ── WHY THIS IS MEASURED AND NOT ASSERTED ──────────────────────────────
     *
     * The obvious way to get the members onto this feed is three more relations
     * on INDEX_COLUMNS' eager set. Laravel runs a relation's query whether or
     * not any parent needs it, so that would cost THREE QUERIES ON EVERY PAGE
     * of a shop that has never created a set — a budget raised to pay for a
     * feature nobody is using. App\Support\SetEagerLoad looks first.
     *
     * MUTATION NOTE. Change SetEagerLoad::on() to load unconditionally (drop
     * the `isEmpty()` return) and the first two counts differ. RUN.
     */
    Product::create([
        'slug' => 'plain-'.Str::random(6), 'name' => 'Plain Toner', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock',
    ]);

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->getJson('/api/products')->assertOk();

        return $n;
    };

    // Warm-up: Setting::map() memoises in a process-level static and the test
    // cache store is the array driver, so the first request through a page pays
    // for all of them. Measuring cold-then-warm would report a fall that has
    // nothing to do with the data. Same reasoning as StorefrontQueryBudgetTest.
    $this->getJson('/api/products');

    $withoutSets = $count();

    apiSetFixture();

    $withOneSet = $count();

    // Two sets of two members must not cost more than one set of one: the
    // members are batched for the whole page.
    apiSetFixture(15000);
    apiSetFixture(15000);

    $withThreeSets = $count();

    expect($withoutSets)->toBeLessThan($withOneSet, 'a page with a set should load its members')
        ->and($withThreeSets)->toBe($withOneSet, 'three sets must cost the same as one — the members are batched');
});
