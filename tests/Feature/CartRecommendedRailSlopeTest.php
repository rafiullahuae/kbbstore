<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartPage;
use App\Services\CartService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE RECOMMENDED RAIL READ `brands` ONCE PER CARD, AND FETCHED EVERY CARD WITH
 * `select *`.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * App\Services\CartPage::recommended() ran
 *
 *     Product::query()->whereIn('id', $ids)->where(...)->get()
 *
 * with no `with('brand')` and no column list, and store/cart-inner.blade.php
 * reads `$recProduct->brand?->name` twice per card — once for the gradient seed
 * and once for the initials drawn in place of a missing image. So Eloquent
 * fetched the brand the moment the loop touched it: ONE
 *
 *     select * from "brands" where "id" = ? limit 1
 *
 * PER CARD. MEASURED on /cart against a basket held at one line, varying only
 * the number of products on the rail:
 *
 *     rail cards              1     2     5    10
 *     total statements       11    12    15    20
 *     reads of `brands`       3     4     7    12
 *
 * Slope exactly 1.0, and CartPage::MAX_REC lets the owner put TWENTY-FOUR cards
 * on it. After the eager load: 11 statements flat from one card to ten, with
 * `brands` read 3 times whatever the rail carries.
 *
 * `select *` was the other half, and it is the half a statement count cannot
 * see. `products.description` is a longText and `seo`, `meta_feed` and
 * `custom_tabs` are json blobs the rail never opens — all four came back for
 * every card, on the page a shopper is looking at while deciding whether to pay.
 *
 * ── WHY NOTHING CAUGHT IT, AND WHY IT IS WORTH FIXING ANYWAY ────────────────
 *
 * It is LATENT on the live shop. The rail renders only on the `squeeze` layout,
 * `layout` ships as `classic`, and `rec_ids` ships empty — so recommended()
 * returns before it queries anything and StorefrontQueryBudgetTest's /cart
 * measurement never reaches this code. It fires the moment the owner switches
 * the layout on Appearance → Cart page and picks products, which is precisely
 * when somebody is looking at the page.
 *
 * Lane Q8 found it, measured it and could not fix it — app/Services/CartPage.php
 * was not that lane's file. docs/q8-cart-eager-loads.md is where it was left.
 *
 * ── THE FOUR COLUMNS ON THE LIST THAT LOOK OPTIONAL AND ARE NOT ─────────────
 *
 * A narrowed select is not free: an accessor reading a column that was never
 * SELECTed gets null and cannot tell that from a NULL column. `type` and
 * `price` keep VariantPricing answering for a variable parent (without them the
 * rail prints AED 0), and `sale_starts_at`/`sale_ends_at` keep an expired
 * markdown expired (without them the window reads as unbounded and the sale
 * price is quoted forever). Both are pinned below, because both are silent.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Recorded in docs/q9-storefront-slope-audit.md, which also carries the slope
 * table for every other storefront page measured this round.
 */

/** The two resets every measured request in this project makes. See budgetReset(). */
function railReset(): void
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

/** A published product with a brand of its own and NO image, so the card draws initials. */
function railProduct(string $slug, string $brandName, array $extra = []): Product
{
    $brand = Brand::create(['slug' => 'rail-b-' . $slug, 'name' => $brandName]);

    return Product::create(array_merge([
        'slug' => 'rail-' . $slug,
        'name' => 'Rail ' . $slug,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'type' => 'simple',
        'brand_id' => $brand->id,
        // The two things `select *` used to drag back for every card.
        'description' => '<p>' . str_repeat('description ', 200) . '</p>',
        'seo' => ['title' => 'x'],
    ], $extra));
}

/**
 * The cart this file measures against: ONE cart, one line, made once.
 *
 * ▲ A FRESH CART PER MEASUREMENT DOES NOT WORK, and it fails in a way that
 * looks like a result. Illuminate\Routing\Route::getController() caches the
 * controller on the Route object and the Router lives for the whole test
 * process, so from the second request onward the controller holds the
 * CartService built for the FIRST one — bound to a cart that has since been
 * deleted. Every size after the first then measures an EMPTY basket: 7
 * statements, no rail, and a beautifully flat line that means nothing. The
 * basket is held at one line and only the rail varies, which is the thing under
 * measurement anyway.
 */
function railCart(): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $line = railProduct('basket-line', 'Basket Brand');

    $cart->items()->create([
        'product_id' => $line->id,
        'quantity' => 1,
        'unit_price' => 10000,
    ]);

    return $cart;
}

/** Put $n products on the rail and switch the layout that renders it on. */
function railOf(int $n): void
{
    $ids = [];

    for ($i = 0; $i < $n; $i++) {
        // A DIFFERENT BRAND PER CARD, so the per-card read cannot be batched by
        // accident — the same reason StorefrontQueryBudgetTest's basket holds
        // six different products.
        // TWO WORDS, and not sharing a first letter with the product name.
        // Gradient::initials() takes the first letter of each word and caps at
        // two, so a one-word brand yields a SINGLE letter — and asserting that
        // a whole HTML page contains the letter "B" is an assertion that cannot
        // fail. Dropping `brand_id` from the column list was green against
        // exactly that before this fixture was changed; see the mutation notes.
        $ids[] = railProduct('card-' . $n . '-' . $i, 'Xanthe Qorvis' . $n . $i)->id;
    }

    railReset();

    app(CartPage::class)->save([
        'layout' => 'squeeze',
        'rec_on' => true,
        'rec_ids' => implode(',', $ids),
    ]);
}

/** One measured request: every statement it ran, and its HTML. */
function railRequest(Cart $cart): array
{
    railReset();

    $sql = [];
    DB::listen(function ($event) use (&$sql): void { $sql[] = $event->sql; });

    $html = test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get('/cart')
        ->assertOk()
        ->getContent();

    return [
        'total' => count($sql),
        'brands' => count(array_filter($sql, fn (string $s) => str_contains($s, 'from "brands"'))),
        'sql' => $sql,
        'html' => $html,
        // The rail's cards. `<div class="cpg-card">` exactly: the inlined cart
        // stylesheet mentions `cpg-card` a further nine times, so a looser
        // match counts CSS as content and never reads zero.
        'cards' => substr_count($html, '<div class="cpg-card">'),
    ];
}

/** Warm-up discarded, then measured. Setting::map() memoises per PROCESS. */
function railMeasure(Cart $cart, int $n): array
{
    railOf($n);
    railRequest($cart);

    return railRequest($cart);
}

it('costs the same for ten recommended cards as for one', function () {
    /*
     * THE DEFECT AS A SHAPE, NOT A NUMBER.
     *
     * Before the eager load: 11 statements for one card and 20 for ten. After:
     * 11 and 11. The assertion is the EQUALITY, not the 11 — a page is allowed
     * to get dearer for a reason, and is not allowed to get dearer because the
     * owner put another product on a rail.
     */
    $cart = railCart();

    $one = railMeasure($cart, 1);
    $ten = railMeasure($cart, 10);

    // The fixture has to vary or the flatness is worthless — this is exactly
    // how StorefrontQueryBudgetTest's /cart measurement missed Lane Q8's N+1.
    expect($one['cards'])->toBe(1)
        ->and($ten['cards'])->toBe(10);

    expect($ten['total'])->toBe($one['total']);
});

it('reads the brands table the same number of times for ten cards as for one', function () {
    /*
     * The narrower assertion, so a regression names the table it is in. Each
     * card read `select * from "brands" where "id" = ? limit 1`; the page reads
     * `brands` three times whatever the rail carries, and all three are the
     * basket line's own chain.
     */
    $cart = railCart();

    $one = railMeasure($cart, 1);
    $ten = railMeasure($cart, 10);

    expect($ten['cards'])->toBe(10)
        ->and($ten['brands'])->toBe($one['brands']);
});

it('does not fetch the description or the json blobs for a rail card', function () {
    /*
     * THE HALF A STATEMENT COUNT CANNOT SEE. `select *` fetched `description`
     * (longText) plus `seo`, `meta_feed` and `custom_tabs` for every card. The
     * shape of the statement is the only place this is visible, which is the
     * argument Tests\Support\SqlShape already makes for judging the SQL a
     * request ISSUES rather than the answer it returns.
     */
    $cart = railCart();

    $ten = railMeasure($cart, 10);

    $railQuery = null;

    /*
     * ▲ THE CART PAGE ISSUES THREE `products` STATEMENTS AND TWO OF THEM ARE
     * NOT THIS ONE. The basket line's own product, and the drawer's, are both
     * fetched by `"products"."id" in (...)` and both already carry a column
     * list. Matching on `from "products"` plus an `in (` and keeping the LAST
     * hit picked the basket line's query — so this case passed with the rail
     * still on `select *`, which is the only mutation in this file that ever
     * came back green when it should not have. The rail's query is the only one
     * of the three that filters on `status` and `is_visible`; that is what
     * identifies it.
     */
    foreach ($ten['sql'] as $sql) {
        if (str_contains($sql, 'from "products"')
            && str_contains($sql, '"status" = ?')
            && str_contains($sql, '"is_visible" = ?')) {
            $railQuery = $sql;
        }
    }

    expect($railQuery)->not->toBeNull('the rail query was not issued at all');

    expect($railQuery)
        ->not->toContain('select *')
        ->not->toContain('"description"')
        ->not->toContain('"custom_tabs"')
        ->not->toContain('"meta_feed"');
});

it('still draws every card, with the brand initials and the price on it', function () {
    /*
     * ▲ WHY THIS CASE EXISTS. A cost assertion is satisfied by a page that
     * renders NOTHING — the flatness above would pass just as well if the rail
     * disappeared. Lane Q8 makes the same point about its label cases.
     *
     * The brand is not printed as words on this card: with no image, the seed
     * and the INITIALS in the placeholder come from `brand->name`. So the
     * initials are where an unloaded or wrongly-columned brand would show.
     */
    $cart = railCart();

    $r = railMeasure($cart, 3);

    expect($r['cards'])->toBe(3);

    /*
     * The placeholder each card draws in place of a missing image, read out of
     * the card rather than looked for anywhere on the page. Its text is
     * Gradient::initials($recProduct->brand?->name ?: $recProduct->t('name')),
     * so it is 'XQ' while the brand is in hand and 'RC' (from 'Rail card-3-0')
     * the moment it is not — which is what makes this case able to fail.
     */
    preg_match_all('#<div class="cpg-card">\s*<span class="im"[^>]*>([^<]*)<button#', $r['html'], $m);

    expect($m[1])->toBe(['XQ', 'XQ', 'XQ']);

    // And the price, so a card stripped to an empty box would not pass either.
    expect(substr_count($r['html'], \App\Support\Money::format(10000)))->toBeGreaterThanOrEqual(3);
});

it('prices a variable product on the rail from its variations, not at zero', function () {
    /*
     * THE COLUMN LIST IS NOT FREE, AND THIS IS THE FIRST OF TWO SILENT WAYS TO
     * GET IT WRONG.
     *
     * App\Services\VariantPricing::entry() reads `type` and `price` OFF THE
     * ATTRIBUTES — deliberately, because a NULL column and a column that was
     * never SELECTed both answer null through the model and only the first
     * means "this product has no price of its own". Drop either column from
     * CartPage::CARD_COLUMNS and range() answers null for a variable parent,
     * Product::effectivePrice() falls through to 0, and the rail prints AED 0:
     * the exact defect VariantPricing exists to remove, re-entering through a
     * narrowed select.
     */
    $cart = railCart();

    $parent = railProduct('variable', 'Variable Brand', ['type' => 'variable', 'price' => null]);

    $attr = Attribute::firstOrCreate(['slug' => 'size'], ['name' => 'Size', 'is_variation_axis' => true]);

    foreach ([9000, 14000] as $i => $price) {
        $variant = ProductVariant::create([
            'product_id' => $parent->id,
            'price' => $price,
            'stock_status' => 'instock',
            'position' => $i,
        ]);

        $value = AttributeValue::create([
            'attribute_id' => $attr->id,
            'name' => (10 * ($i + 1)) . 'ml',
            'slug' => 'rail-sz-' . $i,
        ]);

        DB::table('product_variant_attribute_value')->insert([
            'product_variant_id' => $variant->id,
            'attribute_value_id' => $value->id,
        ]);
    }

    railReset();
    app(CartPage::class)->save([
        'layout' => 'squeeze', 'rec_on' => true, 'rec_ids' => (string) $parent->id,
    ]);

    railRequest($cart);
    $r = railRequest($cart);

    expect($r['cards'])->toBe(1);

    // AED 90.00, the low end of the range — and emphatically not AED 0.00.
    expect($r['html'])->toContain(\App\Support\Money::format(9000));

    $card = substr($r['html'], strpos($r['html'], '<div class="cpg-card">'));
    $card = substr($card, 0, 600);

    expect($card)->not->toContain(\App\Support\Money::format(0));
});

it('does not advertise a markdown on the rail whose window has closed', function () {
    /*
     * THE SECOND SILENT WAY. Product::advertisedSalePrice() says it in its own
     * docblock: with `sale_starts_at` and `sale_ends_at` absent from the select,
     * Eloquent answers null for both and a window check reads two nulls as "no
     * start bound, no end bound" — a sale that is always on. That FAILS OPEN,
     * so an expired markdown keeps being charged-and-quoted on the rail.
     *
     * This product's sale ended a week ago. The card must print AED 100.00, the
     * regular price, with no struck-through figure beside it.
     */
    $cart = railCart();

    $expired = railProduct('expired-sale', 'Expired Brand', [
        'price' => 10000,
        'sale_price' => 4000,
        'sale_starts_at' => now()->subMonth(),
        'sale_ends_at' => now()->subWeek(),
    ]);

    railReset();
    app(CartPage::class)->save([
        'layout' => 'squeeze', 'rec_on' => true, 'rec_ids' => (string) $expired->id,
    ]);

    railRequest($cart);
    $r = railRequest($cart);

    expect($r['cards'])->toBe(1);

    $card = substr($r['html'], strpos($r['html'], '<div class="cpg-card">'));
    $card = substr($card, 0, 600);

    expect($card)->toContain(\App\Support\Money::format(10000))
        ->and($card)->not->toContain(\App\Support\Money::format(4000))
        // `cwas` is the struck-through compare-at. There is no sale, so there
        // is no strike.
        ->and($card)->not->toContain('cwas');
});
