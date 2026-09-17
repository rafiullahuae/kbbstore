<?php

/**
 * Two prices printed from one pair of fils, and whether they can be believed.
 *
 * ── THE DEFECT
 *
 * Money::displayDecimals() is 0 on this store: the live WooCommerce site prints
 * whole dirhams, and Money::format() therefore rounds. That is a presentation
 * choice, and a fine one for a lone price. It stops being fine the moment a
 * template prints TWO figures off the same underlying integers and asks the
 * shopper to compare them.
 *
 * A product marked down from AED 100.00 to AED 99.80 rendered, on /shop:
 *
 *     <del>AED 100</del> <ins>AED 100</ins>
 *
 * — a struck-through price identical to the one beside it. Reproduced against a
 * running preview before the change, on /shop, on /product/{slug}/, in the
 * quick-view modal, on the brand landing grid and on the cart line.
 *
 * The same collision hides a REAL markdown rather than inventing a false one:
 * a sachet at AED 1.00 reduced to AED 0.50 also printed "AED 1" twice, beneath
 * a red "-50% OFF" badge that was telling the truth while the price block
 * silently contradicted it.
 *
 * ── WHY PRECISION AND NOT SUPPRESSION
 *
 * The alternative answers were to drop the strike-through when the rounded
 * figures match, or to refuse to present a sub-1% markdown as a sale anywhere.
 * Both hide real money: at AED 1000 a 0.4% markdown is AED 4 off, which rounds
 * to a 0% badge but is plainly worth showing, and at AED 1.00 a 50% markdown
 * rounds into a collision while being the largest discount in the shop.
 *
 * So the rounded display is kept wherever it can tell the truth and widened to
 * the currency's own precision only for the pair that would otherwise collide.
 * It is the rule OrderEmailPresenter already applies to a whole receipt ("a
 * receipt may not round" — see its header); this narrows the same principle to
 * the one place on the storefront where rounding states something untrue.
 *
 * ── AND IT AGREES WITH THE BADGE
 *
 * ProductLabels::for() falls through to the next rule when the rounded discount
 * is below 1%, because below 1% the badge cannot state a true number. That is a
 * refusal to quote a PERCENTAGE, not a claim that no sale exists — so a price
 * block that shows the real saving does not contradict it. The invariant these
 * tests pin is the one that was broken: a sale badge is never drawn over two
 * equal figures, and a strike-through is never drawn over two equal figures.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;

/** A visible, buyable product on /shop with a page of its own. */
function pdtProduct(array $attributes = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pdt-roundlab'], ['name' => 'Round Lab']);
    $category = Category::firstOrCreate(
        ['slug' => 'pdt-serums'],
        ['name' => 'Serums', 'path' => 'pdt-serums']
    );

    $product = Product::create(array_merge([
        'slug' => 'pdt-' . uniqid(),
        'name' => 'Dokdo Toner',
        'sku' => 'PDT-' . strtoupper(uniqid()),
        'brand_id' => $brand->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'featured' => false,
        'created_at' => now()->subYears(2),
        'updated_at' => now()->subYears(2),
    ], $attributes));

    $product->categories()->syncWithoutDetaching([$category->id]);

    return $product;
}

/**
 * Every money figure on the page, in order, as the plain strings a shopper
 * reads — "AED 100", "AED 99.80".
 *
 * Money::format() wraps the symbol in its own span for the bidi isolation, so
 * the markup between the two has to come out before the string can be compared
 * with anything. Asserting on the bytes and not on a model is the whole point:
 * the defect was entirely in the rendering.
 *
 * @return list<string>
 */
function pdtAmounts(string $html): array
{
    // The symbol lives in a span of its own INSIDE the amount span — that is
    // the bidi isolation Money::symbolHtml() exists for — so the inner element
    // is unwrapped first. Without that the amount span's first closing tag is
    // the symbol's, and a naive match returns "AED" rather than "AED 100".
    $html = preg_replace(
        '#<span class="woocommerce-Price-currencySymbol"[^>]*>(.*?)</span>#s',
        '$1',
        $html
    );

    preg_match_all(
        '#<span class="woocommerce-Price-amount amount"[^>]*>(.*?)</span>#s',
        $html,
        $matches
    );

    return array_map(
        static fn (string $inner): string => trim(preg_replace('/\s+/', ' ', strip_tags($inner))),
        $matches[1]
    );
}

/**
 * The two figures inside one element, e.g. the card's `.cprice`.
 *
 * @return list<string>
 */
function pdtAmountsIn(string $html, string $needle, int $length = 600): array
{
    $at = strpos($html, $needle);

    return $at === false ? [] : pdtAmounts(substr($html, $at, $length));
}

/** The card for one product on /shop, as a string. */
function pdtCard(Product $product): string
{
    $html = test()->get('/shop')->assertOk()->getContent();

    foreach (explode('<div class="pc">', $html) as $card) {
        if (str_contains($card, $product->slug)) {
            return $card;
        }
    }

    return '';
}

/* ────────────────────────── the helper, on its own ───────────────────────── */

it('keeps the store display width whenever the rounded figures already differ', function () {
    // AED 100.00 down to AED 90.00. "AED 100" and "AED 90" are two different
    // strings already, so nothing widens and every honest sale in the catalogue
    // renders byte-for-byte as it did before this existed.
    expect(Money::decimalsToDistinguish(10000, 9000))->toBe(0);
    expect(Money::displayDecimals())->toBe(0);
});

it('widens to the currency precision exactly when the rounded figures collide', function () {
    // The headline case: 0.2% off, both sides round to "AED 100".
    expect(Money::decimalsToDistinguish(10000, 9980))->toBe(2);

    // Half a dirham. The badge rounds this UP to "-1% OFF", so the badge is
    // drawn and the price block has to show a saving beside it.
    expect(Money::decimalsToDistinguish(10000, 9950))->toBe(2);

    // A real half-price markdown that whole dirhams hid: both round to "AED 1".
    expect(Money::decimalsToDistinguish(100, 50))->toBe(2);

    // Equal amounts are not a comparison and must not widen: a caller handing
    // the same figure twice is not asking to be told them apart.
    expect(Money::decimalsToDistinguish(10000, 10000))->toBe(0);
});

it('never widens past what the stored integers can express', function () {
    // minorExponent() is the finest distinction fils can make, so the widened
    // width is always enough AND never more than the currency has.
    expect(Money::decimalsToDistinguish(10000, 9999))->toBe(Money::minorExponent());

    // And the widened pair really is two different strings, which is the whole
    // contract. One fils apart is the worst case there is.
    $dp = Money::decimalsToDistinguish(10000, 9999);
    expect(Money::amount(10000, $dp))->not->toBe(Money::amount(9999, $dp));
});

/* ─────────────────────────── the rendered pages ──────────────────────────── */

it('never prints a struck-through price equal to the price beside it on the shop grid', function () {
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980]);

    $figures = pdtAmountsIn(pdtCard($product), 'class="cprice"');

    expect($figures)->toHaveCount(2);
    expect($figures[0])->not->toBe($figures[1]);
    expect($figures)->toBe(['AED 100.00', 'AED 99.80']);
});

it('leaves an honest sale on the grid exactly as it was', function () {
    // The guard on the fix: nothing outside the colliding pair may move. This
    // is the shape every sale in the catalogue renders in, and it is still
    // whole dirhams.
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9000]);

    expect(pdtAmountsIn(pdtCard($product), 'class="cprice"'))->toBe(['AED 100', 'AED 90']);
});

it('shows a real half-price markdown that whole dirhams was hiding', function () {
    // AED 1.00 -> AED 0.50 carries a truthful "-50% OFF" badge and printed
    // "AED 1" on both sides of it.
    $product = pdtProduct(['price' => 100, 'sale_price' => 50]);

    $card = pdtCard($product);

    expect($card)->toContain('-50% OFF');
    expect(pdtAmountsIn($card, 'class="cprice"'))->toBe(['AED 1.00', 'AED 0.50']);
});

it('never prints the same figure twice on the product page', function () {
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    $figures = pdtAmountsIn($html, 'id="bbPrice"', 400);

    expect($figures)->toHaveCount(2);
    expect($figures[0])->not->toBe($figures[1]);
    // .now first, the struck original second — the order the template writes.
    expect($figures)->toBe(['AED 99.80', 'AED 100.00']);
});

it('never prints the same figure twice in the quick-view modal', function () {
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980]);

    // The endpoint answers JSON with the rendered partial under `html`, so the
    // markup has to come back out of it before it can be read.
    $html = (string) test()->get('/quick-view/' . $product->id)->assertOk()->json('html');

    $figures = pdtAmountsIn($html, 'class="qv-price"', 600);

    expect($figures)->toHaveCount(2);
    expect($figures[0])->not->toBe($figures[1]);

    // And the modal printed an unguarded discountPercent() beside them, so the
    // exact badge the labels lane removed from the card was still live here.
    expect($html)->not->toContain('-0%');
});

it('never prints the same figure twice on a cart line', function () {
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980]);

    test()->post('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    $figures = pdtAmountsIn(test()->get('/cart')->assertOk()->getContent(), 'class="cpr"', 400);

    expect($figures)->toHaveCount(2);
    expect($figures[0])->not->toBe($figures[1]);
});

/* ───────────────── the theme badge and the module badge agree ────────────── */

it('falls through to the bestseller badge when the markdown cannot state a percentage', function () {
    // Module OFF, so the theme's own badge block in product-card.blade.php is
    // what runs. It took the sale branch on ANY isOnSale(), emitted the empty
    // string for a markdown rounding to nothing, and stopped — so this featured
    // product carried no badge at all. ProductLabels::for() falls through to
    // the next rule in exactly this case; the two now agree.
    app(\App\Services\SettingsService::class)->setModule('product_labels', false);

    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980, 'featured' => true]);

    $card = pdtCard($product);

    expect($card)->toContain('Bestseller');
    expect($card)->not->toContain('% OFF');
});

it('still gives a real sale its badge and not the bestseller one', function () {
    // The floor is a floor, not an amputation: a featured product with a real
    // markdown keeps the sale badge, which is the plugin's precedence.
    app(\App\Services\SettingsService::class)->setModule('product_labels', false);

    $product = pdtProduct(['price' => 10000, 'sale_price' => 9000, 'featured' => true]);

    $card = pdtCard($product);

    expect($card)->toContain('-10% OFF');
    expect($card)->not->toContain('Bestseller');
});

/* ─────────────────── the same rounding, measured against zero ────────────── */

it('never says a shopper is AED 0 away from free delivery', function () {
    // A zone whose free-delivery floor sits 30 fils above this basket. Rounded
    // to whole dirhams the remainder is "AED 0" — which is exactly what the
    // UNLOCKED state looks like, and the unlocked branch did not run: the bar
    // was not full, the caption said zero, and delivery was still being charged.
    // Reproduced on a running preview on both the cart page and the panel.
    $zone = \Illuminate\Support\Facades\DB::table('shipping_zones')->insertGetId([
        'name' => 'UAE', 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('shipping_zone_locations')->insert([
        'shipping_zone_id' => $zone, 'type' => 'country', 'code' => 'AE',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('shipping_methods')->insert([
        'shipping_zone_id' => $zone, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'enabled' => 1, 'cost' => 0, 'min_amount' => 9030, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $product = pdtProduct(['price' => 9000]);

    test()->post('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    $html = test()->get('/cart')->assertOk()->getContent();

    $figures = pdtAmountsIn($html, 'class="ship"', 400);

    expect($figures)->not->toBeEmpty();
    expect($figures[0])->not->toBe('AED 0');
    expect($figures[0])->toBe('AED 0.30');
});

it('fills the cart panel bar from the same number the cart page uses', function () {
    // Two copies of one rule: the panel divided for itself while the cart page
    // read CartService::totals()['free_shipping_percent'], whose own comment
    // says the two must not diverge. One basket filled the panel's bar to
    // 99.667774086379% and the page's to 100%.
    $zone = \Illuminate\Support\Facades\DB::table('shipping_zones')->insertGetId([
        'name' => 'UAE', 'position' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('shipping_zone_locations')->insert([
        'shipping_zone_id' => $zone, 'type' => 'country', 'code' => 'AE',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('shipping_methods')->insert([
        'shipping_zone_id' => $zone, 'type' => 'free_shipping', 'title' => 'Free delivery',
        'enabled' => 1, 'cost' => 0, 'min_amount' => 30000, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $product = pdtProduct(['price' => 10000]);

    $added = test()->post('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    preg_match('#class="kc-fill" style="width:([^%]*)%#', (string) $added->json('drawer'), $panel);
    preg_match('#class="fill" style="width:([^%]*)%#', test()->get('/cart')->assertOk()->getContent(), $page);

    expect($panel[1] ?? null)->not->toBeNull();
    expect($page[1] ?? null)->not->toBeNull();
    expect($panel[1])->toBe($page[1]);
});

/*
|------------------------------------------------------------------------------
| THE ROW THAT HAS NO PAIR OF ITS OWN — found by the integrator, at merge
|------------------------------------------------------------------------------
|
| decimalsToDistinguish() answers a question about TWO figures: quote this pair
| wide enough that they are different numbers. Every surface above hands it a
| pair, so every surface above is covered.
|
| The product page's bundle rows are the exception, and it took a screenshot to
| see it. The "1 unit" row carries ONE figure, because a single unit has no
| bundle saving, so there is no pair and the row stayed at the store's
| whole-dirham display. That row IS the headline price. On a product marked down
| from AED 100.00 to AED 99.80 the block at the top of the page correctly read
| AED 99.80 while the row directly beneath it read AED 100 — for the same unit,
| in the same eyeful, one of them wrong.
|
| The rule the template now follows is the one the sticky bar below it already
| followed, applied upward: two renderings of one number on one document must
| not be quoted at two widths. The row takes the wider of its own requirement
| and the price block's, so a bundle whose own pair needs more precision than
| the headline still gets it.
*/

it('quotes the single-unit row at the same width as the price block above it', function () {
    $product = pdtProduct(['price' => 10000, 'sale_price' => 9980]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    $headline = pdtAmountsIn($html, 'id="bbPrice"', 400);
    $rows = pdtAmountsIn($html, 'id="variants"', 1600);

    expect($headline[0])->toBe('AED 99.80');

    // The first figure in the first row is the single unit, and it is the same
    // money as the headline. Before this, it read "AED 100".
    expect($rows[0] ?? null)
        ->toBe('AED 99.80', 'the 1-unit row disagrees with the price block directly above it');
});

it('leaves every bundle row on a whole-dirham product exactly as it was', function () {
    /*
     * The regression guard, and it passes on BOTH sides of the change — which
     * is the point of it. Widening is supposed to be reserved for the pair that
     * would otherwise misprint; an ordinary product priced in whole dirhams must
     * not acquire ".00" on any row.
     */
    $product = pdtProduct(['price' => 5900, 'sale_price' => null]);

    $html = test()->get('/product/' . $product->slug)->assertOk()->getContent();

    foreach (pdtAmountsIn($html, 'id="variants"', 1600) as $figure) {
        expect($figure)->not->toContain('.', "a whole-dirham product grew decimals: {$figure}");
    }
});
