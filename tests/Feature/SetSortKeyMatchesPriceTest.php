<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Models\Cart;
use App\Services\CartService;
use App\Support\EffectivePrice;
use App\Support\Money;
use App\Support\SetPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SqlShape;

/**
 * "PRICE, LOW TO HIGH" SORTS A SET BY THE NUMBER ON ITS OWN TILE. (Lane SORT)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ORDER ON THE SCREEN AND THE PRICES ON THE SCREEN ARE THE SAME FACT.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ───────────────────────────────
 *
 * Lane SG made every grid, the basket, the checkout and the API print a set's
 * DERIVED price -- N% off what its members cost today, N fils off it, or a
 * hand-typed figure that follows its members down. It said in its own merge
 * what it could not close: App\Support\EffectivePrice is SQL over
 * `products.price`, evaluated across the whole catalogue before a page is in
 * hand, so the SORT and the FACETS could not see the rule. Measured on this
 * fixture before the fix, with four products and three sets on one /shop page:
 *
 *   sort=plow, printed order        AED 80, 140, 145, 160, 162, 200, 260
 *   sort=plow, order the shop gave  AED 80, 140, 200, 260, 160, 162, 145
 *
 * -- the three sets landing at the END of a cheapest-first list, sorted by
 * figures nobody was being charged. The same three numbers put:
 *
 *   - a set printing AED 145.00 outside "AED 54 - 150" and inside
 *     "AED 150 - 300", so a shopper filtering by budget could not find it at
 *     the price it was advertised at; and
 *   - EVERY rule-priced set outside "On sale", permanently, while its tile drew
 *     a -N% badge from Product::isOnSale(). A tile that says -10% and a filter
 *     that says the product is not on sale cannot both be right.
 *
 * ── HOW IT IS FIXED ───────────────────────────────────────────────────────
 *
 * App\Support\SetPricing::chargedSql() and ::compareSql() wrap the two
 * EffectivePrice expressions in one `CASE WHEN products.type = 'set'`, so a
 * set is ordered, bucketed and filtered at its rule price and every other
 * product's expression is unchanged. See chargedSql()'s docblock for the three
 * designs weighed and why this one, and for the placeholder arithmetic.
 *
 * ── WHAT THIS FILE PROVES, IN ORDER ───────────────────────────────────────
 *
 *   1. The SQL and the PHP agree to the fil, over several hundred generated
 *      combinations of members, modes, discounts, anchors and sale windows.
 *      That is the price of spelling the money rule twice, and it is the thing
 *      that makes the duplication safe rather than merely present.
 *   2. The rendered order of /shop matches the rendered prices, both ways.
 *   3. The price band contains the set whose printed price falls in it.
 *   4. "On sale" and the -N% badge answer the same question.
 *   5. A page with NO set costs exactly what it cost before: zero extra
 *      statements, and adding sets adds none either.
 *   6. A placed order does not move when a member is repriced.
 *   7. The statements are portable -- SQLite spelling is not MySQL spelling,
 *      and this expression is nothing but spelling.
 *
 * MUTATION NOTE -- RUN, NOT ASSERTED. Make EffectivePrice::sql() return its
 * old body (the bare `COALESCE(ownSql, variantSql)`, with bindings() back to
 * four) and this file reports:
 *
 *   FAILED  the SQL sort key equals the printed price ... 384 combinations,
 *           first disagreement: mode=discount_percent parts=18000 bp=1000
 *           php=16200 sql=18000
 *   FAILED  orders sets by the price printed on their tile ... plow put
 *           'SORT Percent Set' (AED 162) after 'SORT Plain Dear' (AED 260)
 *   FAILED  puts a set in the price band its printed price falls in ...
 *           'SORT Amount Set' prints AED 160 and is absent from AED 150 - 300
 *   FAILED  agrees with the tile about whether a set is on sale ...
 *           tile draws -20% and the On sale facet does not list it
 *
 * Restore it and all seven groups are green.
 */
beforeEach(function () {
    SetPricing::forget();
});

/* ────────────────────────────────────────────────────────────── fixtures ─── */

function skProduct(string $name, ?int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'sk-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ], $overrides));
}

/** @param  list<array{0: Product, 1: int}>  $members */
function skSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sk-set-'.Str::random(8),
        'name' => 'Sort Set',
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

    SetPricing::forget();

    return $set->fresh();
}

/** Text as a browser would read it. Money::format() emits nested spans. */
function skText(string $html): string
{
    return trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

function skMoney(int $fils): string
{
    return skText(Money::format($fils));
}

function skDom(string $html): DOMXPath
{
    $doc = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($doc);
}

/**
 * Every tile on the page IN DOCUMENT ORDER, as name => printed price.
 *
 * ▲ ORDER IS THE THING UNDER TEST, so the list is returned as a LIST and never
 *   as a lookup keyed by name: an assertion that reads the prices back through
 *   a map has thrown away the only fact it was measuring.
 *
 * @return list<array{name: string, price: string}>
 */
function skTiles(string $html): array
{
    $xp = skDom($html);
    $out = [];

    $cards = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " kbb-tile ")]');

    foreach ($cards as $card) {
        $title = $xp->query('.//span[@class="kbb-card-nm"]', $card);
        $price = $xp->query('.//span[@class="kbb-card-price"]', $card);

        $out[] = [
            'name' => $title->length === 0 ? '' : skText($title->item(0)->textContent),
            'price' => $price->length === 0 ? '' : skText($price->item(0)->textContent),
        ];
    }

    return $out;
}

/** The names on the page, in document order. @return list<string> */
function skNames(string $html): array
{
    return array_values(array_map(static fn (array $t): string => $t['name'], skTiles($html)));
}

/** Count the statements one closure runs. */
function skQueries(callable $fn): int
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $fn();

    return $n;
}

/**
 * The charged price and the compare-at that the SHOP'S OWN SQL computes, for
 * every row, in one statement.
 *
 * Read through EffectivePrice::sql() and ::regularSql() rather than through a
 * hand-written copy, because those two expressions ARE the thing under test:
 * the sort, the price band and the On sale facet are the same strings with an
 * ORDER BY or a comparison round them.
 *
 * @return array<int, array{ep: int|null, rp: int|null}>
 */
function skSqlPrices(): array
{
    $charged = EffectivePrice::sql();
    $regular = EffectivePrice::regularSql();

    $rows = DB::table('products')
        ->selectRaw(
            'id, ('.$charged.') as ep, ('.$regular.') as rp',
            [...EffectivePrice::bindings(), ...array_fill(0, substr_count($regular, '?'), now()->format('Y-m-d H:i:s'))]
        )
        ->get();

    $out = [];

    foreach ($rows as $row) {
        $out[(int) $row->id] = [
            'ep' => $row->ep === null ? null : (int) $row->ep,
            'rp' => $row->rp === null ? null : (int) $row->rp,
            // ▲ THE RAW VALUE, KEPT. MySQL's `/` yields DECIMAL and PDO hands
            //   it back as a string; casting it to int here would truncate
            //   away the very thing the whole-fils case below is checking.
            'raw' => $row->ep,
        ];
    }

    return $out;
}

/* ═════════════════════════════ 1. the two spellings of the money rule ═════ */

/**
 * THE SQL SORT KEY IS THE PRINTED PRICE, TO THE FIL, OVER THE WHOLE MATRIX.
 *
 * ── WHY THIS CASE IS THE LOAD-BEARING ONE ─────────────────────────────────
 *
 * SetPricing::derived() and SetPricing::chargedSql() are the same arithmetic
 * written twice, in PHP and in SQL, and prime()'s docblock rejected exactly
 * that duplication when the batched tally was built. It is taken up here
 * deliberately, and this is what pays for it: the two are driven over every
 * combination that can change the answer and compared to the fil. A rounding
 * rule that drifts between them cannot survive one run.
 *
 * The combinations are GENERATED rather than listed, from the boundaries that
 * decide a branch: a box worth nothing, a box with a deleted member, quantities
 * above one, a member on a sale that is open / not yet open / finished, a
 * variant-priced member; basis points at 0, 1, the halfway rounding cases,
 * 9999, exactly 100% and beyond it; flat discounts at zero, under, exactly at
 * and over the parts total; anchors above, at and below today's total; and the
 * set's own sale price open and closed.
 *
 * The percentage cases are the ones with a rounding decision in them:
 * `intdiv($parts * (10000 - $bp) + 5000, 10000)` is half-up on an integer, and
 * `(n - n % 10000) / 10000` is what says so in SQL. 3333 basis points off
 * AED 100.00 is 6667 fils on both; a float chain answers 6666.
 */
it('computes the same charged price and compare-at in SQL as in PHP, over the whole rule matrix', function () {
    $open = skProduct('SK Open Sale', 10000, [
        'sale_price' => 6000,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ]);
    $future = skProduct('SK Future Sale', 10000, [
        'sale_price' => 6000,
        'sale_starts_at' => now()->addDay(),
        'sale_ends_at' => now()->addDays(2),
    ]);
    $ended = skProduct('SK Ended Sale', 10000, [
        'sale_price' => 6000,
        'sale_starts_at' => now()->subDays(3),
        'sale_ends_at' => now()->subDay(),
    ]);
    $plain = skProduct('SK Plain', 7000);
    $cheap = skProduct('SK Cheap', 3);
    $doomed = skProduct('SK Doomed', 5000);

    // Boxes, each a different shape of parts total.
    $boxes = [
        'empty' => [],
        'one' => [[$plain, 1]],
        'quantities' => [[$plain, 3], [$cheap, 2]],
        'sale windows' => [[$open, 1], [$future, 1], [$ended, 1]],
        'a member deleted' => [[$plain, 1], [$doomed, 1]],
        'penny' => [[$cheap, 1]],
    ];

    $rules = [];

    foreach ([0, 1, 1000, 1250, 3333, 5000, 6667, 9999, 10000, 12000] as $bp) {
        $rules[] = ['set_price_mode' => SetPricing::MODE_PERCENT, 'set_discount' => $bp];
    }

    foreach ([0, 1, 2500, 21000, 100000] as $fils) {
        $rules[] = ['set_price_mode' => SetPricing::MODE_AMOUNT, 'set_discount' => $fils];
    }

    // `fixed`, with and without an anchor, above / at / below today's total,
    // and with the set's own markdown open and closed underneath it.
    foreach ([null, 0, 1, 15000, 21000, 26000, 500000] as $basis) {
        foreach ([
            [],
            ['sale_price' => 12000, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()],
            ['sale_price' => 12000, 'sale_starts_at' => now()->addDay(), 'sale_ends_at' => now()->addDays(2)],
        ] as $window) {
            $rules[] = array_merge([
                'set_price_mode' => SetPricing::MODE_FIXED,
                'set_price_basis' => $basis,
            ], $window);
        }

        // And the one shape a hand-edited row can reach: a mode nothing knows.
        $rules[] = ['set_price_mode' => 'nonsense', 'set_price_basis' => $basis];
    }

    $built = [];

    foreach ($boxes as $boxName => $members) {
        foreach ($rules as $i => $rule) {
            foreach ([18000, 0, null] as $priceIndex => $price) {
                $set = skSet($members, array_merge([
                    'name' => 'SK '.$boxName.' '.$i.' '.$priceIndex,
                    'price' => $price,
                ], $rule));

                $built[$set->id] = [$boxName, $rule, $price];
            }
        }
    }

    // The member is deleted AFTER the sets are built, which is the shape
    // tally()'s `missing` count exists for: a product removed from the
    // catalogue is not a price reduction.
    $doomed->delete();

    SetPricing::forget();

    expect(count($built))->toBeGreaterThan(300,
        'the matrix collapsed to a handful of cases and is not covering the branches');

    $sql = skSqlPrices();

    $mismatches = [];

    foreach ($built as $id => [$boxName, $rule, $price]) {
        SetPricing::forget();

        $set = Product::find($id);

        $php = $set->effectivePrice();
        $phpCompare = $set->compareAtPrice();

        $label = sprintf(
            'box=%s mode=%s discount=%s basis=%s price=%s',
            $boxName,
            (string) ($rule['set_price_mode'] ?? 'null'),
            var_export($rule['set_discount'] ?? null, true),
            var_export($rule['set_price_basis'] ?? null, true),
            var_export($price, true),
        );

        /*
         * A NULL `price` column is the one row where SQL and PHP are allowed to
         * differ, and they differed before sets existed: effectivePrice() falls
         * through to VariantPricing and answers the int 0, while the SQL
         * COALESCE answers NULL because MIN() over no variations is NULL. That
         * is EffectivePrice::sql()'s own documented case ("un-priced data is
         * not a free product"), so it is excluded here rather than papered
         * over -- but only for the modes that read the column at all.
         */
        $readsColumn = ! in_array($rule['set_price_mode'] ?? null, [SetPricing::MODE_PERCENT, SetPricing::MODE_AMOUNT], true);

        if ($price === null && $readsColumn) {
            continue;
        }

        if (($sql[$id]['ep'] ?? null) !== $php) {
            $mismatches[] = 'charged: '.$label.' php='.$php.' sql='.var_export($sql[$id]['ep'] ?? null, true);
        }

        if (($sql[$id]['rp'] ?? null) !== $phpCompare) {
            $mismatches[] = 'compare-at: '.$label.' php='.var_export($phpCompare, true)
                .' sql='.var_export($sql[$id]['rp'] ?? null, true);
        }
    }

    expect($mismatches)->toBe([], count($mismatches).' of '.count($built)
        .' combinations disagree between SetPricing::derived() and SetPricing::chargedSql()');
});

/**
 * AND THE HALF IS CARRIED UP, IN SQL, ON THE CASES A FLOAT CHAIN GETS WRONG.
 *
 * `(int) ($parts * (1 - $pct / 100))` is the shape derived() replaced: 30% off
 * is 0.69999999999999995559 in binary and lands a fil light. These four cases
 * are the ones where that difference is visible, asserted against literal
 * numbers so the expectation does not compute itself out of the disagreement.
 */
it('rounds the percentage half up in SQL, on the fils a float chain loses', function () {
    $cases = [
        // [parts, basis points, expected]
        [10000, 3333, 6667],
        [10000, 3000, 7000],
        [333, 5000, 167],
        [1, 5000, 1],
        [999, 3333, 666],
    ];

    foreach ($cases as [$parts, $bp, $expected]) {
        $member = skProduct('SK Round '.$parts.'-'.$bp, $parts);
        $set = skSet([[$member, 1]], [
            'name' => 'SK Round Set '.$parts.'-'.$bp,
            'set_price_mode' => SetPricing::MODE_PERCENT,
            'set_discount' => $bp,
            'price' => 1,
        ]);

        SetPricing::forget();
        expect(Product::find($set->id)->effectivePrice())->toBe($expected,
            "PHP got the rounding wrong for {$parts} at {$bp}bp");

        $sql = skSqlPrices();
        expect($sql[$set->id]['ep'])->toBe($expected,
            "SQL got the rounding wrong for {$parts} at {$bp}bp");
    }
});

/**
 * THE SORT KEY IS A WHOLE NUMBER OF FILS, ON BOTH ENGINES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS IS THE CASE THAT IS GREEN ON SQLITE AND RED ON THE SERVER.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `(n - n % 10000) / 10000` is written the long way for one reason: SQLite's
 * `/` between two integers IS integer division, and MySQL's is not -- it
 * yields an exact DECIMAL with four decimal places. So the short spelling
 * `n / 10000` answers 6667 here and 6667.5000 there, and PHP's `(int)` cast on
 * the way back out of PDO truncates the difference away. A test that reads the
 * value as an int therefore passes on both engines while the SERVER is sorting
 * and bucketing on a fractional key.
 *
 * Where that shows up is a band edge, which is the second case below: a set
 * priced at exactly AED 150.00 belongs in "AED 54 - 150", and a key of
 * 15000.5000 is not <= 15000.
 *
 * MUTATION NOTE -- RUN ON BOTH ENGINES. Replace the divisor in
 * SetPricing::chargedSql() with the bare `('.$numerator.' / 10000)`:
 *
 *     default config          16 passed              (SQLite divides integers)
 *     -c phpunit-mysql.xml      1 failed, 15 passed
 *
 * with "the SQL answered '15000.5000' fils". The band assertion below it is in
 * the same case and never gets to run, which is deliberate: the fractional key
 * is the CAUSE and the missing band is the symptom, and a case that names the
 * cause is the one worth reading at three in the morning. Before this case
 * existed the same mutation was green on BOTH engines -- every other assertion
 * in this file reads the value back through PHP's `(int)` cast, which truncates
 * the defect away -- and that is exactly the asymmetry Tests\Support\SqlShape
 * was written about.
 */
it('answers a whole number of fils, so a band edge cannot fall between two buckets', function () {
    // 50% off a box worth AED 300.00 is AED 150.00 exactly -- the top of the
    // "AED 54 - 150" band, and the one number a fractional key excludes.
    $member = skProduct('SORT Edge Member', 30000);

    $edge = skSet([[$member, 1]], [
        'name' => 'SORT Edge Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 5000,
        'price' => 15000,
    ]);

    Product::query()->whereNotIn('id', [$member->id, $edge->id])->update(['status' => 'draft']);

    SetPricing::forget();
    expect(Product::find($edge->id)->effectivePrice())->toBe(15000);

    $raw = skSqlPrices()[$edge->id]['raw'];

    // "15000", "15000.0000" and 15000 are all acceptable; "15000.5000" is not.
    $normalised = str_contains((string) $raw, '.')
        ? rtrim(rtrim((string) $raw, '0'), '.')
        : (string) $raw;

    expect($normalised)->toBe('15000',
        'the SQL answered '.var_export($raw, true).' fils; a sort key with a fraction in it '
            .'is a sort key that is not the printed price');

    SetPricing::forget();
    $band = skNames($this->get('/shop/?price=54-150')->assertSuccessful()->getContent());

    expect(in_array('SORT Edge Set', $band, true))->toBeTrue(
        'SORT Edge Set prints '.skMoney(15000).' and the band "AED 54 - 150" does not list it: '
            .implode(', ', $band));
});

/* ════════════════════════════════════ 2. the order on the rendered page ═══ */

/**
 * A catalogue that interleaves plain products with all three set modes, so a
 * sort that ignores the rule CANNOT accidentally produce the right order.
 *
 * Printed prices, cheapest first:
 *   AED  80.00  SORT Plain Cheap
 *   AED 140.00  SORT Fixed Set     (typed 160.00, anchored at 200.00, box 180.00)
 *   AED 145.00  SORT Plain Mid
 *   AED 160.00  SORT Amount Set    (box 180.00 less 20.00)
 *   AED 162.00  SORT Percent Set   (box 180.00 less 10%)
 *   AED 200.00  SORT Plain High
 *   AED 260.00  SORT Plain Dear
 *
 * The three sets all carry `products.price` = 18000, so the OLD key put them
 * together between 145.00 and 200.00 in an arbitrary order -- and the anchored
 * one, whose typed price is 16000, at 160.00. Every set's stale key is at or
 * above its printed price, which is the bound Lane SG stated.
 *
 * @return array{sets: array<string, Product>, expected: list<string>}
 */
function skCatalogue(): array
{
    $toner = skProduct('Member Toner', 10000);
    $serum = skProduct('Member Serum', 8000);

    $plain = [
        skProduct('SORT Plain Cheap', 8000),
        skProduct('SORT Plain Mid', 14500),
        skProduct('SORT Plain High', 20000),
        skProduct('SORT Plain Dear', 26000),
    ];

    $percent = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Percent Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    $amount = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Amount Set',
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 2000,
    ]);

    $fixed = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Fixed Set',
        'price' => 16000,
        'set_price_mode' => SetPricing::MODE_FIXED,
        'set_price_basis' => 20000,
    ]);

    /*
     * ▲ THE DEMO CATALOGUE IS PUT OUT OF THE WAY, not worked around. This
     *   suite seeds a shop full of real products and /shop pages at 24, so a
     *   fixture appended to it lands partly on page two -- and an ORDER read
     *   off one page of two is not the order. Everything that is not this
     *   fixture is drafted, which /shop excludes, so the seven tiles below are
     *   the whole grid and their positions are the whole answer.
     */
    Product::query()
        ->whereNotIn('id', array_merge(
            [$toner->id, $serum->id, $percent->id, $amount->id, $fixed->id],
            array_map(static fn (Product $p): int => (int) $p->id, $plain),
        ))
        ->update(['status' => 'draft']);

    SetPricing::forget();

    return [
        'sets' => ['percent' => $percent, 'amount' => $amount, 'fixed' => $fixed],
        'plain' => $plain,
        'expected' => [
            'SORT Plain Cheap',
            'SORT Fixed Set',
            'SORT Plain Mid',
            'SORT Amount Set',
            'SORT Percent Set',
            'SORT Plain High',
            'SORT Plain Dear',
        ],
    ];
}

/** Only the tiles this lane's fixture put on the page, in document order. */
function skOurs(string $html): array
{
    return array_values(array_filter(skTiles($html), static fn (array $t): bool => str_starts_with($t['name'], 'SORT ')));
}

it('orders sets by the price printed on their tile, in both directions', function () {
    $catalogue = skCatalogue();

    foreach (['plow' => false, 'phigh' => true] as $orderby => $descending) {
        SetPricing::forget();

        $html = $this->get('/shop/?orderby='.$orderby)->assertSuccessful()->getContent();

        $tiles = skOurs($html);

        expect(count($tiles))->toBe(7, "the {$orderby} page did not draw all seven tiles");

        $expected = $catalogue['expected'];

        if ($descending) {
            $expected = array_reverse($expected);
        }

        expect(array_map(static fn (array $t): string => $t['name'], $tiles))->toBe(
            $expected,
            "?orderby={$orderby} ordered the grid as:\n  "
                .implode("\n  ", array_map(static fn (array $t): string => $t['name'].'  '.$t['price'], $tiles))
        );

        /*
         * ▲ AND THE PROOF IS READ OFF THE PAGE, NOT OFF THE MODEL. The prices
         *   below are the strings the shopper sees, in the order the shopper
         *   sees them; comparing them as fils is what makes "the order matches
         *   the numbers" an assertion rather than a claim.
         */
        $printed = array_map(
            static fn (array $t): int => (int) round(100 * (float) preg_replace('~[^0-9.]~', '', $t['price'])),
            $tiles
        );

        $sorted = $printed;
        $descending ? rsort($sorted) : sort($sorted);

        expect($printed)->toBe($sorted,
            "?orderby={$orderby} printed prices out of order: ".implode(', ', $printed));
    }
});

/* ═══════════════════════════════════════════════ 3. the price-range facet ═══ */

it('puts a set in the price band its printed price falls in, and leaves it out of the others', function () {
    $catalogue = skCatalogue();

    // AED 54 - 150 holds the AED 80 plain, the AED 145 plain and the AED 140
    // anchored set. It does NOT hold the two discount sets at 160 and 162 --
    // whose stale key, 18000, would have put them here had the band been read
    // off the column instead.
    SetPricing::forget();
    $band = skNames($this->get('/shop/?price=54-150')->assertSuccessful()->getContent());
    $ours = array_values(array_filter($band, static fn (string $n): bool => str_starts_with($n, 'SORT ')));

    expect(in_array('SORT Fixed Set', $ours, true))->toBeTrue(
        'the anchored set prints AED 140 and is absent from "AED 54 - 150": '.implode(', ', $ours));
    expect(in_array('SORT Amount Set', $ours, true))->toBeFalse('a set printing AED 160 is in "AED 54 - 150"');
    expect(in_array('SORT Percent Set', $ours, true))->toBeFalse('a set printing AED 162 is in "AED 54 - 150"');

    // AED 150 - 300 holds the two discount sets and the two dearer plains, and
    // NOT the anchored set, which prints AED 140.
    SetPricing::forget();
    $upper = skNames($this->get('/shop/?price=150-300')->assertSuccessful()->getContent());
    $ours = array_values(array_filter($upper, static fn (string $n): bool => str_starts_with($n, 'SORT ')));

    expect(in_array('SORT Amount Set', $ours, true))->toBeTrue(
        'a set printing AED 160 is absent from "AED 150 - 300": '.implode(', ', $ours));
    expect(in_array('SORT Percent Set', $ours, true))->toBeTrue(
        'a set printing AED 162 is absent from "AED 150 - 300": '.implode(', ', $ours));
    expect(in_array('SORT Fixed Set', $ours, true))->toBeFalse('a set printing AED 140 is in "AED 150 - 300"');

    /*
     * ▲ AND THE BAND AGREES WITH THE TILE, WHICHEVER BAND IT IS. Every set on
     *   the fixture is asked for by name against every bucket, and the answer
     *   has to be exactly "the band its printed price falls in". A facet that
     *   merely stops hiding one product is not fixed -- it is fixed when no
     *   band can disagree with a tile.
     */
    foreach ($catalogue['sets'] as $set) {
        SetPricing::forget();
        $printed = Product::find($set->id)->effectivePrice();

        foreach (\App\Support\Facets::BUCKETS as $key => [$label, $min, $max]) {
            SetPricing::forget();

            $inBand = ($min === null || $printed >= $min * 100) && ($max === null || $printed <= $max * 100);

            $names = skNames($this->get('/shop/?price='.$key)->assertSuccessful()->getContent());

            expect(in_array($set->name, $names, true))->toBe($inBand,
                $set->name.' prints '.skMoney($printed).' and the band "'.$label.'" '
                    .($inBand ? 'does not list it' : 'lists it anyway'));
        }
    }
});

/* ═══════════════════════════════════════════════════════ 4. the On sale facet ═══ */

/**
 * "ON SALE" AND THE -N% BADGE ANSWER THE SAME QUESTION. THE POLICY, DECIDED.
 *
 * ── WHAT WAS WRONG ────────────────────────────────────────────────────────
 *
 * whereOnSale() is documented as Product::isOnSale() in SQL and mirrors it
 * "term for term". It stopped mirroring it when sets arrived. isOnSale() is
 * `effectivePrice() < compareAtPrice()`, and for a rule-priced set that is the
 * DERIVED price against `products.price` -- the snapshot
 * Admin\ProductEditorApiController wrote when the set was last saved. The SQL
 * asked `charged < regular` with BOTH sides reading that one column, so the
 * answer was "equal, therefore not on sale", always, for every rule-priced set
 * in the shop -- while the tile drew a -N% badge off the PHP.
 *
 * ── THE DECISION ──────────────────────────────────────────────────────────
 *
 * The facet follows the badge, not the other way round. Three readings were
 * possible and the other two were refused:
 *
 *   TAKE THE BADGE OFF THE TILE. Rejected: it changes a surface that works
 *   today, and it makes the shop silent about a reduction it is really
 *   offering. A member's markdown reaching the set IS the feature the owner
 *   asked for; a shop that applies it and says nothing has hidden its own
 *   discount.
 *
 *   CALL A SET "ON SALE" FOR BEING CHEAPER THAN ITS MEMBERS BOUGHT SEPARATELY.
 *   Rejected: that is a different claim -- it is what SetContents prints as
 *   `saving` on the set's own page -- and it is true of very nearly every set
 *   ever built. A facet that contains everything filters nothing.
 *
 *   MIRROR isOnSale(). Taken. A rule-priced set is on sale exactly while its
 *   derived price is below the figure the shop last published for it, which is
 *   the same two numbers the badge compares and the same two the strikethrough
 *   prints.
 */
it('agrees with the tile about whether a rule-priced set is on sale', function () {
    $toner = skProduct('SORT Sale Toner', 10000);
    $serum = skProduct('SORT Sale Serum', 8000);

    $set = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Sale Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
        // What the editor writes on save: the derived figure of the day.
        'price' => 16200,
    ]);

    // See skCatalogue(): the seeded demo shop pages at 24 and would push this
    // fixture onto page two of a facet that is supposed to contain it.
    Product::query()->whereNotIn('id', [$toner->id, $serum->id, $set->id])->update(['status' => 'draft']);

    // Nothing has moved since the save, so nothing is on sale and no badge.
    SetPricing::forget();
    expect(Product::find($set->id)->isOnSale())->toBeFalse();
    $names = skNames($this->get('/shop/?sale=1')->assertSuccessful()->getContent());
    expect(in_array('SORT Sale Set', $names, true))->toBeFalse(
        'a set nobody has marked down is in the On sale facet');

    // The toner is marked down by AED 20. The box is now AED 160, the set
    // AED 144, and the tile says -11% against the AED 162 it last published.
    $toner->update(['price' => 8000]);
    SetPricing::forget();

    $live = Product::find($set->id);
    expect($live->effectivePrice())->toBe(14400);
    expect($live->compareAtPrice())->toBe(16200);
    expect($live->isOnSale())->toBeTrue();
    expect($live->discountPercent())->toBe(11);

    SetPricing::forget();
    $html = $this->get('/shop/?sale=1')->assertSuccessful()->getContent();

    expect(in_array('SORT Sale Set', skNames($html), true))->toBeTrue(
        'the tile draws a -'.$live->discountPercent().'% badge and the On sale facet does not list it');

    // And the badge really is on the grid, so the two surfaces are being
    // compared rather than one of them being assumed.
    SetPricing::forget();
    $grid = $this->get('/shop/')->assertSuccessful()->getContent();
    expect(str_contains($grid, '-11%'))->toBeTrue('the -11% badge is not on the shop grid at all');

    /*
     * ▲ AND A HAND-PRICED SET THAT HAS FOLLOWED ITS MEMBERS DOWN IS NOT ON
     *   SALE, which is the other half of the decision and the one that could
     *   have gone wrong in the opposite direction. compareAtPrice() comes down
     *   with the charged price for an anchored set -- Product::compareAtPrice()
     *   says so in as many words -- so both ends move together and the set is
     *   simply cheaper, not marked down. compareSql() had to do the same or the
     *   facet would have filled up with every anchored set in the shop.
     */
    $anchored = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Anchored Set',
        'price' => 16000,
        'set_price_mode' => SetPricing::MODE_FIXED,
        'set_price_basis' => 20000,
    ]);

    Product::query()->whereNotIn('id', [$toner->id, $serum->id, $set->id, $anchored->id])
        ->update(['status' => 'draft']);

    SetPricing::forget();
    $live = Product::find($anchored->id);
    expect($live->effectivePrice())->toBe(12000);
    expect($live->compareAtPrice())->toBe(12000);
    expect($live->isOnSale())->toBeFalse();

    SetPricing::forget();
    $names = skNames($this->get('/shop/?sale=1')->assertSuccessful()->getContent());
    expect(in_array('SORT Anchored Set', $names, true))->toBeFalse(
        'an anchored set that merely got cheaper was reported as being on sale');
});

/* ═══════════════════════════════════ 5. and it costs nothing to not use ═══ */

/**
 * A PAGE WITH NO SET COSTS EXACTLY WHAT IT COST BEFORE, AND A PAGE WITH SETS
 * COSTS THE SAME AGAIN.
 *
 * This is the property Lane SG's prime() bought and the one a correlated
 * subquery could have spent. It is not spent: the whole set expression sits
 * behind `CASE WHEN products.type = 'set'`, a CASE evaluates only the branch it
 * takes, and nothing about it is a separate STATEMENT. So the three counts
 * below are one number.
 *
 * ▲ THE SORTED PAGE IS COMPARED WITH THE UNSORTED ONE, which is what makes
 *   this a before/after without a second checkout of the repository: the
 *   default /shop does not go through EffectivePrice at all, so if the sort
 *   expression cost a statement the two would differ by one.
 */
it('adds no statement to a price sort, a price band or an On sale filter, with or without a set', function () {
    foreach (range(1, 12) as $n) {
        skProduct('SORT Budget Plain '.$n, 5000 + $n * 1000);
    }

    $warm = ['/shop/', '/shop/?orderby=plow', '/shop/?orderby=phigh', '/shop/?price=54-150', '/shop/?sale=1'];

    foreach ($warm as $url) {
        SetPricing::forget();
        $this->get($url)->assertSuccessful();
    }

    $measure = function (string $url): int {
        SetPricing::forget();

        return skQueries(fn () => $this->get($url)->assertSuccessful());
    };

    $plain = [];

    foreach ($warm as $url) {
        $plain[$url] = $measure($url);
    }

    foreach (array_slice($warm, 1) as $url) {
        expect($plain[$url])->toBe($plain['/shop/'],
            $url.' cost '.$plain[$url].' statements on a catalogue with no set in it, '
                .'against '.$plain['/shop/'].' for the unsorted page');
    }

    // Now put sets on the page: three of them, then twelve, and the count must
    // not move with either the feature or the number of sets. The one extra
    // statement is SetPricing::prime()'s batched tally, which Lane SG measured
    // and which is flat.
    $toner = skProduct('SORT Budget Toner', 10000);

    foreach (range(1, 3) as $n) {
        skSet([[$toner, 2]], [
            'name' => 'SORT Budget Set '.$n,
            'set_price_mode' => SetPricing::MODE_PERCENT,
            'set_discount' => 1000 * $n,
        ]);
    }

    foreach ($warm as $url) {
        SetPricing::forget();
        $this->get($url)->assertSuccessful();
    }

    $three = [];

    foreach ($warm as $url) {
        $three[$url] = $measure($url);
    }

    foreach (range(4, 12) as $n) {
        skSet([[$toner, 2]], [
            'name' => 'SORT Budget Set '.$n,
            'set_price_mode' => SetPricing::MODE_AMOUNT,
            'set_discount' => 500 * $n,
        ]);
    }

    foreach ($warm as $url) {
        SetPricing::forget();
        $this->get($url)->assertSuccessful();
    }

    foreach ($warm as $url) {
        expect($measure($url))->toBe($three[$url],
            $url.' is not flat in the number of sets on the page');

        expect($three[$url] - $plain[$url])->toBeLessThanOrEqual(1,
            $url.' cost '.($three[$url] - $plain[$url]).' extra statements once sets were on the page; '
                .'the batched tally is one and there is nothing else to pay for');
    }
});

/* ═════════════════════════════════════════ 6. a placed order does not move ═══ */

/**
 * A PLACED ORDER DOES NOT MOVE WHEN A MEMBER IS REPRICED -- PROVED AGAIN NOW
 * THAT THE SORT AND THE FACETS READ THE DERIVED FIGURE TOO.
 *
 * SetPricingTest and SetPriceOnGridsTest both pin this, and it is re-proved
 * here for the same reason Lane SG re-proved it: a derived price that is now
 * read from MORE places is a price with more chances to move under somebody who
 * has already paid. The snapshot columns are the reason it cannot --
 * `cart_items.unit_price` is written when the line is added and
 * `order_items.unit_price` is copied from it at checkout.
 *
 * MUTATION NOTE -- RUN. Have CartPage read `$product->effectivePrice()` in
 * place of the stored `unit_price` and the basket assertion below drops from
 * 16200 to 14400 while the order still says 16200.
 */
it('leaves a placed order where it was when a member is repriced under it', function () {
    $toner = skProduct('SORT Paid Toner', 10000);
    $serum = skProduct('SORT Paid Serum', 8000);

    $set = skSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SORT Paid Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
        'price' => 16200,
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

    Product::query()->whereNotIn('id', [$toner->id, $serum->id, $set->id])->update(['status' => 'draft']);

    app(CartService::class)->add($cart, Product::find($set->id), 1);

    $agreed = (int) $cart->fresh(['items'])->items->first()->unit_price;
    expect($agreed)->toBe(16200);

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

    $order = Order::latest('id')->first();
    expect((int) $order->items->first()->unit_price)->toBe($agreed);

    $toner->update(['price' => 6000]);
    SetPricing::forget();

    expect((int) Order::find($order->id)->items->first()->unit_price)->toBe($agreed,
        'a placed order moved when a member of one of its sets was repriced');

    // And the SORT has followed the member down, which is the feature: the set
    // is now the cheapest thing in the shop and opens the cheapest-first list.
    SetPricing::forget();
    $tiles = skOurs($this->get('/shop/?orderby=plow')->assertSuccessful()->getContent());

    /*
     * AED 60 toner, AED 80 serum, AED 126 set -- the set has followed the
     * member down by the full AED 40 (10% off a box that lost AED 40) and the
     * cheapest-first list says so. Before this lane it sat at AED 162, its
     * `products.price` snapshot, BEHIND both of them by the stale figure and
     * ahead of nothing.
     */
    expect(array_map(static fn (array $t): string => $t['name'], $tiles))->toBe([
        'SORT Paid Toner', 'SORT Paid Serum', 'SORT Paid Set',
    ], 'the repriced set is not where its printed price puts it');

    expect($tiles[2]['price'])->toBe(skMoney(12600));
});

/* ═══════════════════════════════════════════════════ 7. and it is portable ═══ */

/**
 * THE STATEMENTS ARE PORTABLE, WHICH FOR THIS CHANGE IS THE WHOLE RISK.
 *
 * The suite runs SQLite and the shop runs MySQL, and this lane's contribution
 * is nothing but SQL text: a derived table inside a correlated scalar subquery,
 * an integer division written without `DIV`, a GROUP BY that has to survive
 * ONLY_FULL_GROUP_BY, and sixteen placeholders that have to match their
 * bindings exactly. Tests\Support\SqlShape judges the statement rather than the
 * answer, so this case is meaningful on a laptop with no MySQL -- and
 * `-c phpunit-mysql.xml` runs the same file against a real server.
 */
it('issues portable SQL on the price sort, the price band and the On sale facet', function (string $url) {
    skCatalogue();
    SetPricing::forget();

    $captured = SqlShape::capture(function () use ($url) {
        $this->get($url)->assertSuccessful();
    });

    expect($captured)->not->toBeEmpty("no SQL was issued for {$url}");
    expect(SqlShape::violations($captured))->toBe([], "portability violations on {$url}");
})->with([
    '/shop/?orderby=plow',
    '/shop/?orderby=phigh',
    '/shop/?price=54-150',
    '/shop/?price=300p',
    '/shop/?sale=1',
    '/shop/?orderby=plow&price=150-300&sale=1',
    '/shop/?s=SORT&orderby=plow',
]);

/**
 * THE PLACEHOLDER COUNT IS NEVER WRITTEN DOWN.
 *
 * EffectivePrice::bindings() used to return a literal four and three call sites
 * used it. sql() now carries ten, regularSql() six and whereOnSale() the sum;
 * a branch added to the set expression tomorrow changes all three. Counting is
 * the one thing a caller can get wrong here, and getting it wrong is a PDO
 * error on a live page rather than a wrong number.
 *
 * MUTATION NOTE -- RUN. Make fill() return `array_fill(0, 4, $now)` and this
 * case reports 10 bindings against 4, and eleven other cases in this file fail
 * with "SQLSTATE[HY000]: General error: 25 column index out of range".
 */
it('binds exactly as many timestamps as the expression has placeholders', function () {
    foreach (['products', ''] as $table) {
        $sql = EffectivePrice::sql($table === '' ? '' : $table);

        expect(count(EffectivePrice::bindings($table === '' ? '' : $table)))->toBe(
            substr_count($sql, '?'),
            'bindings() and sql() disagree for table "'.$table.'"'
        );
    }

    // And through the three doors a caller actually uses.
    skCatalogue();

    foreach ([
        'order' => fn ($q) => EffectivePrice::orderBy($q, 'asc'),
        'range' => fn ($q) => EffectivePrice::whereRange($q, 5400, 15000),
        'sale' => fn ($q) => EffectivePrice::whereOnSale($q),
    ] as $door => $apply) {
        $captured = SqlShape::capture(function () use ($apply) {
            $apply(DB::table('products'))->get();
        });

        expect(SqlShape::violations($captured))->toBe([], "the {$door} door bound the wrong number of values");
    }
});
