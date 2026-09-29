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
use App\Support\Money;
use App\Support\SetPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A SET COSTS THE SAME NUMBER ON A GRID AS IT DOES ON ITS OWN PAGE. (Lane SG)
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ───────────────────────────────
 *
 * A Set may be priced by a RULE rather than by a number — N% off what its
 * members cost, N fils off it, or a hand-typed figure that follows its members
 * down from an anchor. App\Support\SetPricing derives the live figure on every
 * read, and Product::effectivePrice() asks it. That is what the set's own
 * product page, the cart, the checkout, the API and the admin all print.
 *
 * NINE NARROW `CARD_COLUMNS` LISTS DID NOT SELECT THE THREE COLUMNS THE RULE IS
 * WRITTEN IN. SetPricing::mode() reads `set_price_mode` off getAttributes() and
 * falls back to `fixed` for anything it does not recognise — an absent column
 * included — and basis() reads `set_price_basis` and falls back to "no anchor".
 * Neither fallback fails. Both are silently right for a legacy row and silently
 * WRONG for a query that simply did not ask, so on /shop, on every category
 * archive, on a collection, a brand page, the homepage rails, the wishlist, the
 * search dropdown, the cart rail and `[kbb_products]`, a set printed the number
 * in `products.price`:
 *
 *   discount_percent / discount_amount — the derived figure AS AT THE LAST TIME
 *   anybody saved the set. ProductEditorApiController writes it into
 *   `products.price` on save and nothing writes it again, so it is a snapshot
 *   that goes stale the moment a member's price moves in EITHER direction.
 *
 *   fixed with an anchor — the typed price, before the reduction. Always the
 *   dearer of the two.
 *
 * So the owner saw AED 180 on the shop and AED 170 on the set's own page, and a
 * shopper saw the same. The cart charged the lower one, which is why this was
 * never a money leak in the common direction — see the one case below where it
 * IS one.
 *
 * ── HOW IT IS FIXED ───────────────────────────────────────────────────────
 *
 * The three columns are on every list, through SetPricing::COLUMNS so there is
 * one list and not nine; and SetPricing::prime() fills the parts total for
 * every set on a page in ONE grouped statement, or in none at all when the page
 * holds no set. See prime()'s docblock for the two designs that were rejected.
 */
beforeEach(function () {
    SetPricing::forget();
});

function sgProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'sg-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ], $overrides));
}

/** @param  list<array{0: Product, 1: int}>  $members */
function sgSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sg-set-'.Str::random(8),
        'name' => 'Grid Set',
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

/**
 * Text as a browser would read it: tags gone, entities decoded, runs of space
 * collapsed. App\Support\Money::format() emits nested <span>s carrying the
 * currency symbol, so a price can only be compared as TEXT.
 */
function sgText(string $html): string
{
    return trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

/** What Money::format() puts on the screen, as text. */
function sgMoney(int $fils): string
{
    return sgText(Money::format($fils));
}

function sgDom(string $html): DOMXPath
{
    $doc = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($doc);
}

/**
 * The price printed on ONE product's tile, out of a page full of tiles.
 *
 * Scoped to the card rather than searched for across the whole document: a
 * grid of a dozen products carries a dozen prices, and an assertion that the
 * right number appears SOMEWHERE on the page is an assertion that passes when
 * the number belongs to a different tile.
 */
function sgTilePrice(string $html, Product $product): ?string
{
    $xp = sgDom($html);

    $cards = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " kbb-tile ")]');

    foreach ($cards as $card) {
        $links = $xp->query('.//a[@href = '.sgXpathLiteral($product->url()).']', $card);

        if ($links->length === 0) {
            continue;
        }

        $price = $xp->query('.//span[@class="kbb-card-price"]', $card);

        return $price->length === 0 ? null : sgText($price->item(0)->textContent);
    }

    return null;
}

/** The headline price on a product's own page. */
function sgPagePrice(string $html): ?string
{
    $nodes = sgDom($html)->query('//span[@class="now"]');

    return $nodes->length === 0 ? null : sgText($nodes->item(0)->textContent);
}

/** A URL as an XPath string literal — slugs here carry no quotes, but say so. */
function sgXpathLiteral(string $value): string
{
    return str_contains($value, "'") ? 'concat("'.$value.'")' : "'".$value."'";
}

/**
 * Count the statements one closure runs.
 *
 * ▲ IT DOES NOT CLEAR THE MEMO. Three of the cases below measure a SECOND call
 *   and expect zero, which is the whole point of the memo; clearing it here
 *   would make those cases measure a cold read and pass for the wrong reason.
 *   A case that wants a cold read says SetPricing::forget() itself.
 */
function sgQueries(callable $fn): int
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $fn();

    return $n;
}

/** A basket of this shop's own shape, with a token a checkout can be posted for. */
function sgCart(): Cart
{
    return Cart::create([
        'token' => Str::random(32),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);
}

/* ════════════════════════════ the tile and the page agree, in all three ═══ */

/**
 * THE DEFECT, ONE CASE PER MODE, MEASURED ON THE REAL /shop PAGE.
 *
 * MUTATION NOTE — RUN, NOT ASSERTED. Delete `...\App\Support\SetPricing::COLUMNS`
 * from Store\ShopController::CARD_COLUMNS and this case is red on the first set
 * it reaches:
 *
 *   SG Percent Set shows 'AED 180' on the shop grid and 'AED 162' on its own
 *   page. A set must cost one number.
 *
 * 'AED 180' is `products.price` — 18000 fils, the seeded column — and it is the
 * same wrong figure for all three modes, which is the shape the header
 * describes. Five of this file's eight cases go red on that one deletion.
 */
it('prints the same price on a shop tile as on the set own page, in every pricing mode', function () {
    $toner = sgProduct('SG Toner', 10000);
    $serum = sgProduct('SG Serum', 8000);

    // 10% off a parts total of AED 180 -> AED 162.
    $percent = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Percent Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    // AED 20 off the same total -> AED 160.
    $amount = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Amount Set',
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 2000,
    ]);

    /*
     * Hand-typed at AED 180 when the box was worth AED 200, so the anchor is
     * 20000 and today's box is worth 18000: the set comes down by the same
     * 2000 fils its members did, to AED 160.
     */
    $fixed = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Fixed Set',
        'set_price_mode' => SetPricing::MODE_FIXED,
        'set_price_basis' => 20000,
    ]);

    $grid = $this->get('/shop/');
    $grid->assertSuccessful();
    $html = $grid->getContent();

    foreach ([$percent, $amount, $fixed] as $set) {
        SetPricing::forget();
        $page = $this->get($set->url());
        $page->assertSuccessful();

        $tile = sgTilePrice($html, $set);
        $own = sgPagePrice($page->getContent());

        expect($tile)->not->toBeNull($set->name.' has no tile on /shop/');
        expect(is_string($tile) && $tile === $own)->toBeTrue(
            $set->name.' shows '.var_export($tile, true).' on the shop grid and '
            .var_export($own, true).' on its own page. A set must cost one number.'
        );
    }

    // And the number is the derived one, not the column. Spelled out so the
    // case cannot pass by both surfaces being wrong in the same way.
    SetPricing::forget();
    expect(sgTilePrice($html, $percent))->toBe(sgMoney(16200));
    expect(sgTilePrice($html, $amount))->toBe(sgMoney(16000));
    expect(sgTilePrice($html, $fixed))->toBe(sgMoney(16000));
});

/**
 * THE ONE DIRECTION IN WHICH THE OLD BEHAVIOUR UNDERCHARGED ON THE TILE.
 *
 * `products.price` for a rule-priced set is the derived figure as at the last
 * save. A member whose SALE ENDS makes the parts total climb back up, so the
 * derived price climbs with it and the stale column is left BELOW what the cart
 * will charge — a tile advertising less than the shop takes. It is the same one
 * defect as the cases above, but it is the one that costs money rather than
 * trust, so it gets its own name.
 *
 * MUTATION NOTE — RUN. Remove the set columns from ShopController::CARD_COLUMNS
 * and the tile reads 'AED 140' beside a cart line of 'AED 180'.
 */
it('does not advertise a set below what the basket charges when a member comes off sale', function () {
    $toner = sgProduct('SG Sale Toner', 10000, [
        'sale_price' => 6000,
        'sale_ends_at' => now()->addDay(),
    ]);
    $serum = sgProduct('SG Sale Serum', 8000);

    // Saved while the toner was marked down: parts 14000, no discount, so
    // ProductEditorApiController's `$product->price = derived()` wrote 14000.
    $set = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Snapshot Set',
        'price' => 14000,
        'set_price_mode' => SetPricing::MODE_AMOUNT,
        'set_discount' => 0,
    ]);

    // The sale ends. Nobody touches the set.
    $toner->update(['sale_ends_at' => now()->subHour()]);
    SetPricing::forget();

    $html = $this->get('/shop/')->assertSuccessful()->getContent();

    expect(sgTilePrice($html, $set))->toBe(
        sgMoney(18000),
        'the tile is quoting the stale `products.price` snapshot, which is below what the set now costs'
    );

    // And that is what the basket charges, so the two agree.
    SetPricing::forget();
    $cart = sgCart();
    app(CartService::class)->add($cart, Product::find($set->id), 1);

    expect((int) $cart->fresh(['items'])->items->first()->unit_price)->toBe(18000);
});

/* ═══════════════════════════════════════ every list carries the columns ═══ */

/**
 * THE NINE LISTS, PINNED BY NAME.
 *
 * The case above proves /shop. This proves the other eight without rendering
 * eight pages, and it is the assertion that catches a TENTH list being added
 * without them — which is how this defect arrived in the first place, one
 * copied constant at a time.
 *
 * MUTATION NOTE — RUN. Remove the spread from Services\CartPage::CARD_COLUMNS
 * and this reports `App\Services\CartPage::CARD_COLUMNS is missing
 * set_price_mode, set_discount, set_price_basis`. Remove it from
 * Store\CartController::LINE_COLUMNS and it names that one instead.
 */
it('selects the three set-pricing columns on every product card column list', function () {
    $lists = [
        \App\Http\Controllers\Store\ShopController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\CollectionController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\BrandController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\HomeController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\SearchController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\ProductController::class => 'CARD_COLUMNS',
        \App\Http\Controllers\Store\WishlistController::class => 'CARD_COLUMNS',
        \App\Services\CartPage::class => 'CARD_COLUMNS',
        \App\Support\Shortcodes::class => 'CARD_COLUMNS',
        \App\Services\Ugc\Tile::class => 'PRODUCT_COLUMNS',

        /*
         * ▲ AND THE THREE LISTS THAT DECIDE WHAT A SHOPPER IS CHARGED.
         *
         * These are not tiles. CartController::add() hydrates its list and
         * hands the model straight to CartService::add(), which snapshots
         * `$product->effectivePrice()` into `cart_items.unit_price` — so a
         * missing column here is not a wrong label, it is a wrong charge, and
         * it is the case this file's second cart assertion measures.
         */
        \App\Http\Controllers\Store\CartController::class => 'LINE_COLUMNS',
        \App\Http\Controllers\Store\CheckoutController::class => 'LINE_COLUMNS',
        \App\View\Composers\CartDrawerComposer::class => 'LINE_COLUMNS',

        // The public feed's LIST endpoint. show() reads whole rows and was
        // always right; index() reads this and was not, so one endpoint
        // published a price the other contradicted.
        \App\Http\Controllers\Api\ProductController::class => 'INDEX_COLUMNS',
    ];

    $missing = [];

    foreach ($lists as $class => $constant) {
        $columns = (new ReflectionClass($class))->getConstant($constant);

        // Ugc\Tile qualifies every entry with its table, because its list is
        // used across a join. Compare on the bare column name either way.
        $bare = array_map(
            static fn (string $c): string => str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c,
            $columns
        );

        $absent = array_values(array_diff(SetPricing::COLUMNS, $bare));

        if ($absent !== []) {
            $missing[] = $class.'::'.$constant.' is missing '.implode(', ', $absent);
        }

        // `type` too: Product::isSet() reads it and fails CLOSED without it, so
        // a list carrying the three rule columns and not `type` reads the rule
        // for nothing.
        if (! in_array('type', $bare, true)) {
            $missing[] = $class.'::'.$constant.' is missing type, so Product::isSet() answers false for every row';
        }
    }

    expect($missing)->toBe([], "Card column lists that cannot price a set:\n  ".implode("\n  ", $missing));
});

/* ═══════════════════════════════════════════════════ and it is not an N+1 ═══ */

/**
 * A PAGE WITH NO SET IN IT COSTS EXACTLY WHAT IT COST BEFORE.
 *
 * CLAUDE.md's first rule, at the point where this change could most easily
 * break it: SetPricing::prime() must LOOK FIRST. The three columns are three
 * more bytes on a SELECT that was already running; the statement count may not
 * move at all for a shop that has never created a set, which is this one.
 *
 * MUTATION NOTE — RUN. Delete prime()'s `if ($ids === []) { return; }` early
 * return and the unit case below goes red twice, 1 against 0: the grouped
 * aggregate runs against an empty id list, on a page with nothing to ask about.
 * (This case stays green under that mutation — it compares /shop/ with itself —
 * which is exactly why the unit case exists beside it.)
 */
it('runs no extra query on a grid that holds no set', function () {
    foreach (range(1, 6) as $i) {
        sgProduct('SG Plain '.$i, 1000 * $i);
    }

    // Warm the process-level setting and cache memos first; see
    // StorefrontQueryBudgetTest's header for why a cold first request is not a
    // measurement.
    $this->get('/shop/')->assertSuccessful();

    SetPricing::forget();
    $baseline = sgQueries(fn () => $this->get('/shop/')->assertSuccessful());

    SetPricing::forget();
    expect(sgQueries(fn () => $this->get('/shop/')->assertSuccessful()))->toBe(
        $baseline,
        'the shop grid is not stable between two identical requests'
    );

    expect($baseline)->toBeLessThan(
        20,
        'a /shop with six plain products should be single figures; a number this size means prime() is asking per row'
    );
});

/**
 * AND A GRID FULL OF SETS COSTS THE SAME AS A GRID WITH ONE.
 *
 * This is the flatness assertion, and it is the one the budget test's own
 * header argues for: a budget alone cannot catch an N+1, because per-set work
 * passes any ceiling on a small enough fixture. One set and six sets must cost
 * the same number of statements.
 *
 * MUTATION NOTE — RUN. Delete the `\App\Support\SetPricing::prime($products)`
 * call from Store\ShopController::index() and this reports
 *
 *   /shop/ ran 4 queries with one set on it and 9 with six.
 *
 * Five extra statements for five extra sets, one lazy tally() each, which is
 * precisely the N+1 the call is there to remove.
 */
it('costs one statement for a grid of sets, not one per set', function () {
    $toner = sgProduct('SG Flat Toner', 10000);
    $serum = sgProduct('SG Flat Serum', 8000);

    $mk = fn (int $i) => sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Flat Set '.$i,
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    $mk(1);

    $this->get('/shop/')->assertSuccessful();
    SetPricing::forget();
    $one = sgQueries(fn () => $this->get('/shop/')->assertSuccessful());

    foreach (range(2, 6) as $i) {
        $mk($i);
    }

    $this->get('/shop/')->assertSuccessful();
    SetPricing::forget();
    $six = sgQueries(fn () => $this->get('/shop/')->assertSuccessful());

    expect($six)->toBe(
        $one,
        "/shop/ ran {$one} queries with one set on it and {$six} with six. "
        .'A difference is one statement per set, which is the N+1 SetPricing::prime() exists to remove.'
    );

    // And the price is right on all six, so flatness was not bought by not
    // answering the question.
    $html = $this->get('/shop/')->getContent();

    foreach (Product::query()->where('type', 'set')->get() as $set) {
        expect(sgTilePrice($html, $set))->toBe(sgMoney(16200), $set->name.' is priced wrong');
    }
});

/**
 * prime() ITSELF, AT THE UNIT LEVEL.
 *
 * The page tests above would catch a regression, but only as "/shop gained six
 * queries", which is a long way from the cause. This says the cause — and it
 * pins the three refusals that make the call free: nothing to do, already
 * memoised, and members already loaded by App\Support\SetEagerLoad.
 *
 * MUTATION NOTE — RUN. Drop the `array_key_exists($id, self::$memo)` skip from
 * prime() and the "already memoised" measurement below reads 1 instead of 0,
 * here and in the empty-box case that follows.
 */
it('fills every set on a page in one statement and refuses to run when there is nothing to ask', function () {
    $toner = sgProduct('SG Unit Toner', 10000);
    $serum = sgProduct('SG Unit Serum', 8000);

    $sets = collect(range(1, 4))->map(fn (int $i) => sgSet([[$toner, 1], [$serum, 2]], [
        'name' => 'SG Unit Set '.$i,
    ]))->all();

    $plain = [Product::find($toner->id), Product::find($serum->id)];

    // Nothing to ask about.
    SetPricing::forget();
    expect(sgQueries(fn () => SetPricing::prime($plain)))->toBe(0);
    expect(sgQueries(fn () => SetPricing::prime([])))->toBe(0);
    expect(sgQueries(fn () => SetPricing::prime([null, 'not a model'])))->toBe(0);

    // Four sets, ONE statement — and the answer is right for each.
    SetPricing::forget();
    $fresh = Product::query()->whereIn('id', collect($sets)->pluck('id'))->get();

    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    SetPricing::prime($fresh);

    expect($n)->toBe(1, 'prime() ran '.$n.' statements for four sets');

    foreach ($fresh as $set) {
        expect(SetPricing::partsTotal($set))->toBe(10000 + 2 * 8000);
    }

    expect($n)->toBe(1, 'reading the four totals ran a further '.($n - 1).' statements');

    // Already memoised: a second call is free.
    expect(sgQueries(function () use ($fresh) {
        SetPricing::prime($fresh);
    }))->toBe(0);
});

/**
 * A SET WITH AN EMPTY BOX IS MEMOISED TOO.
 *
 * A GROUP BY returns no row for a set with no membership rows, so without
 * seeding the misses before the results are folded in, such a set would miss
 * the memo on every read and re-run the aggregate once per set per request —
 * the N+1 this method exists to remove, arriving through the back door on the
 * one row that has the least to say.
 *
 * MUTATION NOTE — RUN. Delete prime()'s `foreach ($ids as $id)` seeding loop
 * and the second count below reads 1 instead of 0.
 */
it('memoises a set whose box is empty, which the grouped statement answers nothing for', function () {
    $empty = sgSet([], ['name' => 'SG Empty Set']);

    SetPricing::forget();
    $row = Product::find($empty->id);

    SetPricing::forget();
    expect(sgQueries(fn () => SetPricing::prime([$row])))->toBe(1);
    expect(SetPricing::tally($row))->toBe(['parts' => 0, 'rows' => 0, 'missing' => 0]);
    expect(sgQueries(function () use ($row) {
        SetPricing::prime([$row]);
    }))->toBe(0);
});


/**
 * THE BASKET CHARGES WHAT THE TILE ADVERTISED, THROUGH THE REAL DOOR.
 *
 * ── THE DEFECT, AND IT WAS AN OVERCHARGE ──────────────────────────────────
 *
 * Store\CartController::add() hydrates LINE_COLUMNS and hands the model to
 * CartService::add(), which writes `$product->effectivePrice()` into
 * `cart_items.unit_price`. That list did not carry the set columns either, so
 * the SNAPSHOT was taken at `products.price` — for a rule-priced set, the
 * figure the editor last wrote. A member marked down afterwards moves the
 * advertised price DOWN and leaves the column where it was, so the shop
 * advertised the lower figure and the basket took the higher one.
 *
 * Measured on the preview fixture before the fix: page AED 166.50 / basket
 * AED 180.00, page AED 160.00 / basket AED 175.00, page AED 145.00 / basket
 * AED 160.00.
 *
 * ▲ THROUGH POST /api/cart/add, NOT THROUGH CartService DIRECTLY. Every other set
 *   test in this suite hands CartService a model it loaded itself with
 *   `Product::find()` — a whole row, every column present — which is exactly
 *   the hydration the defect did not have. The bug lived in the door, so the
 *   test has to come through the door.
 *
 * MUTATION NOTE — RUN. Remove `...\App\Support\SetPricing::COLUMNS` from
 * Store\CartController::LINE_COLUMNS and this reports
 * `Failed asserting that 18000 is identical to 16650` — the basket charging
 * AED 13.50 more than the page offered.
 */
it('charges the advertised price when a set is added through POST /api/cart/add', function () {
    $toner = sgProduct('SG Door Toner', 12000);
    $serum = sgProduct('SG Door Serum', 8000);

    $set = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Door Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
        // What Admin\ProductEditorApiController writes at save time: the
        // derived price of a 20000-fil box, 10% off.
        'price' => 18000,
    ]);

    // The toner is marked down AFTERWARDS. Nothing is saved on the set.
    $toner->update(['price' => 10500]);
    SetPricing::forget();

    $advertised = Product::find($set->id)->effectivePrice();
    expect($advertised)->toBe(16650, 'the fixture is not exercising a stale snapshot');

    $this->post('/api/cart/add', ['product_id' => $set->id, 'quantity' => 1])
        ->assertSuccessful();

    $line = \App\Models\Cart::query()->latest('id')->first()->items->first();

    expect((int) $line->unit_price)->toBe(
        $advertised,
        'the basket snapshot was taken from `products.price` rather than from the rule'
    );
});

/* ════════════════════════════════════════════ and a placed order is fixed ═══ */

/**
 * A PLACED ORDER DOES NOT MOVE WHEN A MEMBER IS REPRICED.
 *
 * SetPricingTest pins this for the derived price generally; it is re-proved
 * here because this lane is the first to put the derived figure on the SHOP,
 * and a price that now moves in more places is a price with more chances to
 * move under somebody who has already paid.
 *
 * MUTATION NOTE — RUN. Change CartService::add() to store
 * `$product->effectivePrice()` at read time rather than snapshotting it — i.e.
 * make CartPage read the live figure — and the second expectation below drops
 * to 14400 while the order says 16200.
 */
it('leaves a basket line and a placed order where they were when a member is repriced', function () {
    $toner = sgProduct('SG Order Toner', 10000);
    $serum = sgProduct('SG Order Serum', 8000);

    $set = sgSet([[$toner, 1], [$serum, 1]], [
        'name' => 'SG Order Set',
        'set_price_mode' => SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    $cart = sgCart();
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

    // The toner is marked down by AED 20 AFTER the order was placed.
    $toner->update(['price' => 8000]);
    SetPricing::forget();

    expect((int) Order::find($order->id)->items->first()->unit_price)->toBe(
        $agreed,
        'a placed order moved when a member of one of its sets was repriced'
    );

    // And the shop now advertises the lower figure, which is the feature.
    SetPricing::forget();
    $html = $this->get('/shop/')->assertSuccessful()->getContent();
    expect(sgTilePrice($html, Product::find($set->id)))->toBe(sgMoney(14400));
});
