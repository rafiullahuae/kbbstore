<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\SetContents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A set's OWN product page names what is in the box. (Lane SP)
 *
 * ── THE DEFECT THIS FILE IS ABOUT, AS IT LOOKED ON THE SHOP ────────────────
 *
 * Lane SET's report, §8: the set publishes with its own description, gallery
 * and price, and what is in the box is not repeated on its product page. A
 * shopper who landed on /product/glow-starter-set/ from Google saw a name, a
 * photograph and AED 199 — and nothing at all to say it was three products.
 * The fanned stack and the popup existed, and existed only in the basket, which
 * is a surface reached AFTER the decision this page exists to support.
 *
 * Every case below would be red without
 * resources/views/partials/set-contents-panel.blade.php and its one @include
 * in resources/views/store/product.blade.php.
 */
function spBrand(string $name): Brand
{
    return Brand::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5)]);
}

function spProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'sp-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'image' => '/img/'.Str::slug($name).'.jpg',
    ], $overrides));
}

/** @param list<array{0: Product, 1: int}> $members */
function spSet(int $setFils, array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sp-set-'.Str::random(8),
        'name' => 'Glow Starter Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $setFils,
        'stock_status' => 'instock',
        'image' => '/img/glow-starter-set.jpg',
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

    return $set->fresh();
}

/* ═══════════════════════════════════════════ what the page actually says ═══ */

it('names every member, with its brand, its quantity and its own price', function () {
    /*
     * MUTATION NOTE. Remove the @include from resources/views/store/product.-
     * blade.php — which is the state of this repository before this lane — and
     * every expectation below is red: the page renders, and says nothing about
     * what is in the box. RUN.
     */
    $brand = spBrand('Anua');

    $toner = spProduct('Heartleaf Soothing Toner', 9000, ['brand_id' => $brand->id]);
    $serum = spProduct('Azelaic Acid Serum', 7550, ['brand_id' => $brand->id]);

    $set = spSet(14000, [[$toner, 2], [$serum, 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Heartleaf Soothing Toner'))->toBeTrue(
        'The set page must name each member.'
    );
    expect(str_contains($html, 'Azelaic Acid Serum'))->toBeTrue(
        'The set page must name every member, not only the first.'
    );
    expect(str_contains($html, 'Anua'))->toBeTrue(
        "The set page must print each member's brand."
    );

    /*
     * THE QUANTITY, which is the difference between "a toner is in this box"
     * and "two toners are in this box" — and the number the parts total is
     * built from.
     */
    expect(str_contains($html, '2&times;'))->toBeTrue(
        'A member whose quantity is 2 must say so on the set page.'
    );

    /*
     * EVERY FIGURE IS SetContents', NOT THE PAGE'S OWN ARITHMETIC. 2 x 9000 +
     * 1 x 7550 = 25550 bought separately; the set is 14000; the saving is
     * 11550. All integer fils.
     */
    $contents = SetContents::fromProduct($set->fresh());

    expect($contents['partsTotal'])->toBe(25550)
        ->and($contents['setPrice'])->toBe(14000)
        ->and($contents['saving'])->toBe(11550)
        ->and($contents['saving'])->toBeInt();

    expect(str_contains($html, \App\Support\Money::plain(11550)))->toBeTrue(
        'The saving SetContents computed must be the saving the page prints.'
    );
});

it('links a published member to its own page and does not link one that is not published', function () {
    /*
     * ── THE HALF THAT IS EASY TO GET WRONG ────────────────────────────────
     *
     * On this page a member is a product the shopper may want to open, so the
     * name is a link. A link to a DRAFT or HIDDEN member is a 404 served to
     * somebody who arrived from Google — the worst place in the shop to put a
     * dead link — so SetContents::memberIsLive() asks the same three conditions
     * Product::scopeVisible() asks, off the model already in hand.
     *
     * MUTATION NOTE. Make SetContents::memberIsLive() `return true;` and the
     * second half of this is red: the draft member gets an <a href> to a URL
     * that 404s. RUN — and the 404 half below is the proof the link really is
     * dead, rather than an assertion that the shop is careful.
     */
    $live = spProduct('Rice Milky Toner', 6900);
    $draft = spProduct('Unreleased Night Cream', 12000, ['status' => 'draft']);

    $set = spSet(15000, [[$live, 1], [$draft, 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'href="'.$live->url().'"'))->toBeTrue(
        'A published member must be a link to its own product page.'
    );
    expect(str_contains($html, 'href="'.$draft->url().'"'))->toBeFalse(
        'A member that is not published must be named without a link — its page 404s.'
    );
    expect(str_contains($html, 'Unreleased Night Cream'))->toBeTrue(
        'A member that is not published is still IN the box and must still be named.'
    );

    // The dead link, proved dead rather than assumed.
    $this->get($draft->url())->assertNotFound();
});

it('draws nothing at all on a product that is not a set', function () {
    /*
     * CLAUDE.md's first rule, at the level of this one page: an ordinary
     * product page must be what it was. StorefrontEnglishUnchangedTest compares
     * the whole storefront byte for byte and is the real instrument; this is the
     * same statement made where a reader of this file will see it.
     *
     * MUTATION NOTE. Change the partial's `@if ($kbbSetPage['members'] !== [])`
     * to `@if (true)` and this is red — every product page in the shop grows an
     * empty "What is in this set" section with an empty grid and a footing that
     * says the box costs nothing. RUN.
     *
     * ▲ The partial's OTHER guard — the `$product->isSet() ?` in its @php
     *   block — is deliberately belt-and-braces and mutating THAT alone is
     *   green, because SetContents::fromProduct() tests isSet() itself and
     *   answers NONE. Said out loud rather than left as a mutation note that
     *   does not hold: a note nobody ran is worse than no note.
     */
    $plain = spProduct('Ceramide Moisturiser', 12900);

    $html = $this->get('/product/'.$plain->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'ksp-grid'))->toBeFalse(
        'A product that is not a set must draw no set panel.'
    );
    expect(str_contains($html, 'What is in this set'))->toBeFalse(
        'A product that is not a set must not carry the panel heading.'
    );
});

/* ═══════════════════════════════════════════════════════ the query budget ═══ */

it('costs the same number of queries for a set of twelve as for a set of three', function () {
    /*
     * ── MEASURED, NOT ASSERTED ────────────────────────────────────────────
     *
     * CLAUDE.md: StorefrontQueryBudgetTest is a budget, and a set's product
     * page must not cost one query per member. A ceiling alone cannot catch an
     * N+1 — a page doing one query per product passes any ceiling on a small
     * fixture — so this measures the SHAPE: the same page, three members and
     * twelve, and the difference must be ZERO.
     *
     * App\Support\SetEagerLoad is what makes that true: three batched queries
     * for the membership rows, the member products and the chosen variants,
     * whatever the box holds.
     *
     * MUTATION NOTE. Delete the `SetEagerLoad::on([$product])` line from
     * Store\ProductController::show() and this is red — the members are then
     * lazy-loaded one at a time from inside the Blade and twelve members cost
     * nine queries more than three. RUN.
     */
    $small = spSet(10000, array_map(
        fn ($i) => [spProduct('Small member '.$i, 1000 + $i), 1],
        range(1, 3)
    ));

    $large = spSet(10000, array_map(
        fn ($i) => [spProduct('Large member '.$i, 1000 + $i), 1],
        range(1, 12)
    ));

    $count = function (string $url): int {
        /*
         * (Lane RP) A fresh request's set-price memo, as PHP-FPM gives every
         * request. The page's foot now shows best sellers ("Continue
         * shopping"), so each set page draws the OTHER set as a card and
         * primes its price — one grouped statement, the same on both pages.
         * Without this the warm-up request below had already primed the large
         * set inside this process, so only the second measurement paid it and
         * the two read 12 and 13 for a reason that is not the member count.
         */
        \App\Support\SetPricing::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    // Warm whatever a first request in this process warms (the settings map,
    // the module registry), so the two figures below are comparing the page
    // rather than the boot.
    $this->get('/product/'.$small->slug.'/')->assertOk();

    $three = $count('/product/'.$small->slug.'/');
    $twelve = $count('/product/'.$large->slug.'/');

    expect($twelve)->toBe(
        $three,
        'A set of twelve must cost the same number of queries as a set of three. '
        ."Three members: {$three}. Twelve members: {$twelve}."
    );
});

it('costs a shop with no sets in it not one extra query', function () {
    /*
     * The other half of the same rule, and the one CLAUDE.md's "nothing that
     * already works may change" is about: SetEagerLoad::on() LOOKS FIRST and
     * returns without touching the database when it is handed something that is
     * not a set — which is every product in this catalogue. So the product-page
     * budget in StorefrontQueryBudgetTest does not move by one query for a
     * feature this shop is not using.
     *
     * MUTATION NOTE. Change SetEagerLoad::on()'s early return to load the
     * relations unconditionally and this is red by three. RUN.
     */
    $plain = spProduct('Ordinary Toner', 8900);

    $this->get('/product/'.$plain->slug.'/')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/product/'.$plain->slug.'/')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $setQueries = array_values(array_filter(
        $queries,
        fn ($q) => str_contains((string) $q['query'], 'product_set_items')
    ));

    expect($setQueries)->toBe(
        [],
        'A product that is not a set must not query product_set_items at all.'
    );
});
