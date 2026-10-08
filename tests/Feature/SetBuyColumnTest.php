<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\BundleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A SET'S BUY COLUMN: NO BULK STRIP, AND WHAT IS IN THE BOX IN ITS PLACE.
 * (Lane SF)
 *
 * ── THE DEFECT THIS FILE IS ABOUT, AS IT LOOKED ON THE SHOP ───────────────
 *
 * The owner, with a marked-up screenshot — the "Choose your option / 1 unit /
 * 2-pack bundle / 3-pack bundle" block struck through with a red X, and an
 * arrow drawn from the contents section far down the page UP into the space
 * it occupied:
 *
 *   "the Set product will not have bundle purchase, instead of that section,
 *    bring the What's inside there, and make it nice list, not grid! also the
 *    mobile screen will adjust that list nicely and display."
 *
 * Two halves of ONE change, and the cases below treat them as one:
 *
 *   1. A SET WAS OFFERED A QUANTITY BUNDLE. BundleService::forProduct() priced
 *      its tiers for any product carrying a price, so a curated gift box was
 *      advertised at "2-pack bundle / 3-pack bundle — Best value".
 *
 *   2. WHAT IS IN THE BOX WAS A GRID OF TILES, IN A SECTION NEAR THE FOOT OF
 *      THE PAGE. A shopper deciding whether to buy the box had to scroll past
 *      the price, the stock line, the Add to cart button, the trust badges and
 *      the tabs to find out what was in it. It is now a LIST, in the buy
 *      column, in the slot the strip vacated.
 *
 * ── AND THE RULE THAT GOVERNS BOTH ────────────────────────────────────────
 *
 * CLAUDE.md rule 1. An ORDINARY product's page does not move: it keeps its
 * strip and it has no set list, and the cases that touch a set are paired with
 * the ordinary product that must survive them.
 */
function sfBrand(string $name): Brand
{
    return Brand::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5)]);
}

function sfProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'sf-'.Str::slug($name).'-'.Str::random(6),
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
function sfSet(int $setFils, array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sf-set-'.Str::random(8),
        'name' => 'Glow Ritual Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $setFils,
        'stock_status' => 'instock',
        'image' => '/img/glow-ritual-set.jpg',
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

/** A set of N members, each priced a little differently. */
function sfSetOf(int $n, int $price = 10000): Product
{
    return sfSet($price, array_map(
        fn ($i) => [sfProduct('Member '.$i.' '.Str::random(4), 1000 + ($i * 100)), 1],
        range(1, $n)
    ));
}

/* ═════════════════════════════════ 1. the bulk strip is gone from a set ═══ */

it('offers no quantity-bundle tiers for a set', function () {
    /*
     * MUTATION NOTE. Delete the `if ($product->isSet()) { return []; }` guard
     * from BundleService::forProduct() and this is red: the set gets the same
     * three default tiers an ordinary product gets. RUN — and it was.
     */
    $set = sfSet(14000, [[sfProduct('Toner', 9000), 1], [sfProduct('Serum', 7550), 1]]);

    expect(app(BundleService::class)->forProduct($set->fresh()))->toBe(
        [],
        'A set must be offered no quantity bundles at all.'
    );
});

it('draws no bulk-quantity strip on a set page and still draws one on an ordinary product', function () {
    /*
     * THE PAIR IS THE POINT. Half of this lane's job is a visible removal and
     * the other half is CLAUDE.md rule 1 — nothing that already works may
     * change — so the removal and the thing that must survive it are asserted
     * in one case, against one run of the same template.
     *
     * MUTATION NOTE. Remove the guard from BundleService::forProduct() and the
     * SET half is red (it draws "2-pack bundle"). Move the guard up into
     * `enabled()` instead and the ORDINARY half is red (that product loses its
     * strip too). RUN, both.
     */
    $set = sfSet(14000, [[sfProduct('Panel toner', 9000), 1], [sfProduct('Panel serum', 7550), 1]]);
    $plain = sfProduct('Ordinary Rice Toner', 8900);

    $setHtml = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();
    $plainHtml = $this->get('/product/'.$plain->slug.'/')->assertOk()->getContent();

    // These are BundleService::DEFAULT_TIERS' own labels — what the strip
    // literally prints.
    foreach (['2-pack bundle', '3-pack bundle'] as $row) {
        expect(str_contains($setHtml, $row))->toBeFalse(
            "A set's product page must not offer the same set at a bulk rate ({$row})."
        );
        expect(str_contains($plainHtml, $row))->toBeTrue(
            "An ordinary product must keep the strip it has always had ({$row})."
        );
    }
});

it('leaves the pricing path alone, so no basket is repriced by this change', function () {
    /*
     * ▲ THE HALF THAT WAS DELIBERATELY NOT CHANGED, PINNED SO A LATER LANE
     *   CANNOT CHANGE IT BY ACCIDENT WHILE "FINISHING" THE ONE ABOVE. ▲
     *
     * forProduct() is the DISPLAY question — which offers does this product
     * carry. unitFor()/totalFor()/discountFor() are the PRICING path, and
     * CartService::add() reprices every basket line through them. Adding a set
     * branch there would change what a basket already holding three of a set
     * is charged, silently, on applying a package. That is what CLAUDE.md rule
     * 1 forbids, so it was left, and this lane's report says so in as many
     * words rather than leaving it to be discovered.
     *
     * MUTATION NOTE. Add `if ($product->isSet())` anywhere on the pricing path
     * — or make discountFor() answer 0 for a set — and this is red. RUN.
     */
    $bundles = app(BundleService::class);

    // BundleService::DEFAULT_TIERS: 3 units at 10% off. 10000 fils -> 9000.
    expect($bundles->unitFor(10000, 3))->toBe(9000)
        ->and($bundles->totalFor(10000, 3))->toBe(27000)
        ->and($bundles->discountFor(3))->toBe(10.0);
});

/* ═══════════════════════════════════ 2. the list is in the buy column ═══ */

it('draws the contents inside the buy form, not in a section near the foot', function () {
    /*
     * ▲ THE MOVE ITSELF, ASSERTED BY POSITION AND NOT BY PRESENCE. ▲
     *
     * "instead of that section, bring the What's inside there" — with an arrow
     * into the space the bundle strip occupied. Asserting only that the panel
     * is somewhere on the page would have been GREEN BEFORE THIS LANE, when it
     * was eight hundred pixels further down, which is the assertion this case
     * exists not to be.
     *
     * So it asserts ORDER: the list starts after the price block, inside the
     * buy form, and before the stock line — which is what "there" means.
     *
     * MUTATION NOTE. Move the @include back below the buy column — anywhere
     * after </form> — and this is red on three of the four comparisons. RUN.
     */
    $set = sfSet(14000, [[sfProduct('Slot toner', 9000), 1], [sfProduct('Slot serum', 7550), 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    $at = function (string $needle) use ($html): int {
        $pos = strpos($html, $needle);
        expect($pos)->not->toBeFalse("The page must contain {$needle}.");

        return (int) $pos;
    };

    $price = $at('id="bbPrice"');
    $form = $at('kbb-cart-form');
    $list = $at('class="ksl ');
    $stock = $at('stockline');
    $formEnd = (int) strpos($html, '</form>', $form);

    expect($list)->toBeGreaterThan($price, 'The list belongs below the price, not above it.');
    expect($list)->toBeGreaterThan($form, 'The list belongs INSIDE the buy form.');
    expect($list)->toBeLessThan($formEnd, 'The list belongs INSIDE the buy form.');
    expect($list)->toBeLessThan($stock, 'The list belongs above the stock line and Add to cart.');
});

it('includes the contents panel exactly once', function () {
    /*
     * The move was one @include DELETED and one ADDED, and the failure mode of
     * that edit is doing only the second half: the box's contents, its parts
     * total and its saving printed twice on one page.
     *
     * Counted on the RENDERED page rather than in the template source, because
     * what matters is how many times it drew.
     *
     * MUTATION NOTE. Put the old `@include('partials.set-contents-panel')`
     * back at the foot of store/product.blade.php, keeping the new one, and
     * this is red at 2. RUN.
     */
    $set = sfSet(14000, [[sfProduct('Once toner', 9000), 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    /* ▲ `class="ksl ksl-panel ksl-norule"` now, ADVANCED DELIBERATELY BY LANE
         SA2 — the panel and the hairline between rows are both settings, and
         SetAppearance::panelClass() writes what is on. What this case is about
         is the COUNT and never the exact attribute: a second @include is the
         defect it was written for. Matching the opening of the attribute keeps
         it counting the same element without pinning a class list that every
         new switch would move. */
    expect(substr_count($html, 'class="ksl '))->toBe(1, 'The contents list must be drawn once.');
    expect(substr_count($html, 'class="ksl-foot"'))->toBe(1, 'The saving must be printed once.');
});

it('is a list and not a grid', function () {
    /*
     * "make it nice list, not grid!"
     *
     * The grid was `.ksp-grid` with `repeat(auto-fill, minmax(min(100%,150px),
     * 1fr))` and a `.ksp-m` tile per member. None of it may come back, and the
     * check is on the CLASSES because a class is what a later edit would
     * reintroduce.
     *
     * MUTATION NOTE. Restore the old panel from git and this is red on
     * `ksp-grid`. RUN.
     */
    $set = sfSet(14000, [[sfProduct('Shape toner', 9000), 1], [sfProduct('Shape serum', 7550), 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'ksp-grid'))->toBeFalse('The tile grid must be gone.');
    expect(str_contains($html, 'class="ksp-m"'))->toBeFalse('The grid tile must be gone.');
    expect(substr_count($html, 'class="ksl-r"'))->toBe(2, 'One row per member.');

    // A row is three tracks at every width. The rule is in the panel's own
    // @once block, which is what the page actually ships.
    /* ▲ 40px, NOT 56. (Lane PP) The owner asked for "super squeeze, without
         pricing mentioned for each product inside the set", so the third track
         is the quantity alone and the photograph came down 56 -> 40. The three
         tracks and their inline order are what this case is about and they are
         unchanged; the number moved because he asked for it to. */
    /* ▲ ADVANCED BY LANE SA, AND THE RENDERED PAGE DID NOT MOVE.
         The panel's numbers are now `var(--ksl-x, <the literal they have always
         been>)` so that Appearance -> Set can change them, and NOTHING declares
         those properties anywhere else — a shop that has moved no slider emits
         no override block at all, so the fallbacks ARE what the page draws.
         Measured in Chromium at both widths after the change: the photograph is
         40px at 1280 and 36px at 390, the row padding 6px and 5px, the name
         13.5px and 13px — the same six numbers as before.
         The assertion is advanced rather than deleted: it is still the only
         thing that says the row is photograph / words / quantity in that inline
         order, and it still fails if somebody reorders the tracks or drops the
         phone's own size. (Lane SA) */
    /* ▲ AND ADVANCED AGAIN BY LANE SA2, for the same reason one step further
         on: the shipped photograph is 36px, not 40. It was 36 on the page and
         40 in this fallback the whole time the "hanging photos" panel carried a
         hard-coded `grid-template-columns:36px …` that overrode it — two
         numbers for one track, decided by source order. There is one now, and
         it is the schema's. The case still fails if somebody reorders the
         tracks or drops the phone's own size. */
    expect(str_contains($html, 'grid-template-columns:var(--ksl-ph,36px) minmax(0,1fr) auto'))->toBeTrue(
        'The row must be photograph / words / quantity, in that order along the inline axis.'
    );
});

it('gives a phone its own sizes rather than reflowing the desktop list', function () {
    /*
     * "also the mobile screen will adjust that list nicely and display."
     *
     * Asserted on the rules the page ships, because the media query is the only
     * place the phone's numbers exist and a later edit drops it without
     * noticing.
     *
     * MUTATION NOTE. Delete the @media (max-width:480px) block from the panel
     * and all four of these are red. RUN.
     */
    $set = sfSet(14000, [[sfProduct('Phone toner', 9000), 1]]);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, '@media (max-width:480px)'))->toBeTrue(
        'The list must have sizes of its own on a phone.'
    );
    /* ▲ ADVANCED BY LANE SA. The phone's four numbers are now ONE declaration
         block of custom properties inside the same media query instead of four
         rules — which is what lets the `.ksl-noph` / `.ksl-noq` track rules work
         at both widths from a single declaration instead of needing a phone copy
         of each. The numbers themselves are identical, measured in Chromium: a
         36px photograph with a 7px radius, 5px of row padding and a 13px name at
         390. This case is still about the phone HAVING numbers of its own, and
         it still goes red if the block is deleted. */
    /* ▲ AND ADVANCED AGAIN BY LANE SA2. The phone's numbers are 32px and 3px:
         they always were, because the panel's own @media block declared them
         and came later, while THIS block declared 36 and 5 and lost. Two media
         queries setting the same custom properties is the fault; there is one
         now. Row padding is no longer here at all — the phone's 3px and the
         laptop's 3px are the same number, and the block carries only what
         DIFFERS, so pinning it here would pin a declaration that must not
         exist. SetAppearanceTest asserts every `_m` default against it. */
    expect(str_contains($html, '--ksl-ph:32px'))->toBeTrue(
        "A phone's thumbnail must be smaller than a desktop's."
    );
    expect(str_contains($html, '--ksl-pps:16px'))->toBeTrue(
        "A phone's panel must be inset less than a desktop's."
    );
    expect(str_contains($html, '--ksl-nm:13px'))->toBeTrue(
        "A phone's name must be smaller than a desktop's."
    );
    /* ▲ THE RULE THIS REPLACED IS GONE BECAUSE THE THING IT STACKED IS GONE.
         (Lane PP) This used to assert `.ksl-end{flex-direction:row`, which made
         the quantity and the PRICE sit on one line on a phone instead of
         stacking. There is no price in the row any more, so `.ksl-end` itself
         was deleted -- a flex column around a single child. What the phone case
         is really about is that the phone has numbers of its own, and the row
         padding is the one that buys height back twelve times over. */
    /* ▲ ADVANCED BY LANE SA, same reason as the three above: the phone's
         numbers are one declaration block of custom properties now.
       ▲ AND AGAIN BY LANE SA2, where the sentence has to change rather than the
         number. The phone's row padding is 3px and so is the laptop's — the
         panel squeezed both to 3 when it shipped, and this case's premise
         ("more tightly than a desktop's") stopped being true the day the
         treatment landed while the assertion stayed green against a declaration
         the page had already overridden. What the phone really pads less is the
         PANEL: 8/9/8/16 against 12/14/11/20, which is the squeeze that buys a
         346px column its measure back. That is what is asserted now, and it is
         the sentence this case was written to say. */
    expect(str_contains($html, '--ksl-ppe:9px'))->toBeTrue(
        "A phone's panel must be padded more tightly than a desktop's."
    );
    expect(str_contains($html, 'var(--ksl-rowpad,3px)'))->toBeTrue(
        'The row padding must still be a control rather than a literal.'
    );
});

it('draws nothing at all on a product that is not a set', function () {
    /*
     * CLAUDE.md rule 1, on the page 99% of this catalogue is.
     *
     * MUTATION NOTE. Replace the panel's `@if ($kbbSetPage['members'] !== [])`
     * with `@if (true)` and every ordinary product page grows an empty list
     * with an empty footing under it. RUN.
     *
     * ▲ AND NOT the `$product->isSet()` on the line above it, which was this
     *   note's first version and STAYED GREEN under mutation. That guard is
     *   belt and braces: SetContents::fromProduct() asks isSet() itself and
     *   answers SetContents::NONE, so removing the panel's copy changes
     *   nothing at all. Worth knowing, and worth not claiming otherwise --
     *   a mutation note that names a lever which does not move is a test
     *   asserting less than its comment says it does.
     */
    $plain = sfProduct('Plain Ceramide Cream', 12900);

    $html = $this->get('/product/'.$plain->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'class="ksl"'))->toBeFalse('An ordinary product has no set list.');
    expect(str_contains($html, 'What is in this set'))->toBeFalse(
        'An ordinary product must not carry the list heading.'
    );
});

/* ════════════════════════════════════ 3. what the list has to get right ═══ */

it('names every member, with its brand and its quantity', function () {
    /*
     * ▲ AND NO LONGER "its own price". (Lane PP) ▲
     *
     *   "the set products list, i want super squeeze, without pricing
     *    mentioned for each product inside the set."
     *
     * The price assertion that stood here is now an assertion that the price is
     * ABSENT, and it lives in ProductPageSqueezeAndSeamTest scoped to the rows
     * -- scoped, because the footing below them keeps three prices on purpose
     * and an unscoped search would find those and pass whatever the rows did.
     *
     * MUTATION NOTE. Delete the name, brand or quantity interpolation from
     * partials/set-contents-row.blade.php and one of these is red. RUN.
     */
    $brand = sfBrand('Anua');
    $toner = sfProduct('Heartleaf Soothing Toner', 9000, ['brand_id' => $brand->id]);
    $serum = sfProduct('Azelaic Acid Serum', 7550, ['brand_id' => $brand->id]);

    $set = sfSet(14000, [[$toner, 2], [$serum, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Heartleaf Soothing Toner'))->toBeTrue('Must name every member.');
    expect(str_contains($html, 'Azelaic Acid Serum'))->toBeTrue('Must name every member, not only the first.');
    expect(str_contains($html, 'Anua'))->toBeTrue("Must print each member's brand.");
    expect(str_contains($html, '2&times;'))->toBeTrue('A member whose quantity is 2 must say so.');

    // 2 x 9000 + 1 x 7550 = 25550 separately; the set is 14000; saving 11550.
    expect(str_contains($html, 'You save'))->toBeTrue('A set cheaper than its parts must say what it saves.');
});

it('links a published member and never links an unpublished one', function () {
    /*
     * ▲ THE ONE A SHOPPER MEETS AS A 404. ▲
     *
     * A set's members are its own products, and one of them can be a draft, be
     * hidden, or be scheduled for next week. SetContents::memberIsLive() asks
     * the three conditions Product::scopeVisible() asks and FAILS CLOSED.
     *
     * MUTATION NOTE. Drop the `$kslLink` condition in
     * partials/set-contents-row.blade.php and always emit the <a> — red on the
     * draft's URL appearing in an href. RUN.
     */
    $live = sfProduct('Published Cleanser', 6000);
    $draft = sfProduct('Unpublished Cleanser', 6500, ['status' => 'draft']);

    $set = sfSet(9000, [[$live, 1], [$draft, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Unpublished Cleanser'))->toBeTrue(
        'An unpublished member is still IN the box and must still be named.'
    );
    expect(str_contains($html, 'href="'.$live->url().'"'))->toBeTrue(
        'A published member must be a link to its own page.'
    );
    expect(str_contains($html, 'href="'.$draft->url().'"'))->toBeFalse(
        'An unpublished member must NOT be linked — that href is a 404.'
    );
});

it('never claims a saving on an unpriced set', function () {
    /*
     * The owner's first set, half filled in, printed "Bought separately: AED
     * 806.00 / Set price: AED 0.00 / You save AED 806.00" — the arithmetic
     * right and the sentence false. SetContents::fromProduct() floors it and
     * the panel's footing is the one place that decides whether the sentence
     * is printed at all.
     *
     * MUTATION NOTE. Remove the `$setPrice <= 0 ? 0 :` from SetContents, or
     * the `saving > 0` guard from the panel, and this is red. RUN.
     */
    $set = sfSet(0, [[sfProduct('Unpriced member A', 40000), 1], [sfProduct('Unpriced member B', 40600), 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'You save'))->toBeFalse(
        'A set with no price saves nobody anything and must not say it does.'
    );
    // And it still prints the two figures it honestly has.
    expect(str_contains($html, 'Bought separately'))->toBeTrue();
    expect(str_contains($html, 'Set price'))->toBeTrue();
});

it('draws a member with no picture without a broken image', function () {
    /*
     * MUTATION NOTE. Drop the @else branch in
     * partials/set-contents-row.blade.php and a pictureless member emits
     * `<img src="">`, which browsers resolve to the current page. RUN.
     */
    $withPhoto = sfProduct('Has A Photograph', 5000);
    $without = sfProduct('Has No Photograph', 5500, ['image' => null]);

    $set = sfSet(9000, [[$withPhoto, 1], [$without, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Has No Photograph'))->toBeTrue('A pictureless member is still named.');
    expect(str_contains($html, 'src=""'))->toBeFalse('Must never emit an empty img src.');
    expect(substr_count($html, 'ksl-ph is-blank'))->toBe(1, 'The gradient fallback draws instead.');
});

it('sizes with CSS and measures nothing in script', function () {
    /*
     * CLAUDE.md rule 4: "No JavaScript that measures layout — this project
     * sizes with calc() for a reason, and two tests forbid the
     * element-measuring APIs by name."
     *
     * ▲ THE COMMENTS ARE STRIPPED FIRST, AND THAT IS NOT TIDINESS. ▲
     *
     * A source scan finds its own explanation. The first version of this case
     * was red on the panel's own docblock — "no getBoundingClientRect, no
     * offsetWidth" — the file PROMISING not to do the thing, read as the file
     * doing it. Blade comments and CSS comments both go, so what is scanned is
     * only what executes.
     *
     * MUTATION NOTE. Add `el.getBoundingClientRect()` to either partial and
     * this is red. RUN — both before and after the stripping was added.
     */
    foreach (['set-contents-panel', 'set-contents-row'] as $file) {
        $src = (string) file_get_contents(resource_path("views/partials/{$file}.blade.php"));
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);

        foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
            'ResizeObserver', 'requestAnimationFrame', '<script'] as $banned) {
            expect(str_contains($src, $banned))->toBeFalse(
                "{$file}: the set list must size with CSS. Found '{$banned}'."
            );
        }
    }
});

it('is flat in the number of members', function () {
    /*
     * CLAUDE.md rule 4 again, and the one a ceiling cannot catch: a page doing
     * one query per member passes any ceiling on a small fixture. So this
     * measures the SHAPE — three members and twelve, and the difference must
     * be ZERO.
     *
     * MUTATION NOTE. Delete the `SetEagerLoad::on([$product])` line from
     * Store\ProductController::show() and this is red by nine. RUN.
     */
    $small = sfSetOf(3);
    $large = sfSetOf(12);

    $count = function (string $url): int {
        // (Lane RP) A fresh request's set-price memo, as PHP-FPM gives every
        // request: the foot's best sellers draw the OTHER set as a card and
        // prime its price, one grouped statement on both pages — but the
        // warm-up request had already primed one of them in this process.
        // SetProductPageTest carries the same line for the same reason.
        \App\Support\SetPricing::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    // Warm whatever a first request in this process warms, so the two figures
    // compare the page rather than the boot.
    $this->get('/product/'.$small->slug.'/')->assertOk();

    $three = $count('/product/'.$small->slug.'/');
    $twelve = $count('/product/'.$large->slug.'/');

    expect($twelve)->toBe(
        $three,
        "Twelve members must cost what three cost. Three: {$three}. Twelve: {$twelve}."
    );
});

/* ══════════════════════ 4. the fold, which the new position made necessary ═══ */

it('draws a short box whole, with no disclosure at all', function () {
    /*
     * The fold exists to keep Add to cart on the screen, and a fold on a
     * three-member box is a click charged for nothing. Six is the boundary:
     * folding ONE row saves nothing, so it applies from seven up.
     *
     * MUTATION NOTE. Change `count($kbbSetRows) > $kbbSetFold + 1` to
     * `> $kbbSetFold` in the panel and the six-member case is red. RUN.
     */
    foreach ([2, 5, 6] as $n) {
        $set = sfSetOf($n);
        $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

        expect(substr_count($html, 'class="ksl-r"'))->toBe($n, "A {$n}-member box draws {$n} rows.");
        // The ELEMENT, not the class name: `.ksl-more` also appears in the
        // panel's @once stylesheet, which every set page ships whether or not
        // it folds. Looking for the bare string was this case's first version
        // and it was red on a two-member box for that reason.
        expect(str_contains($html, '<details class="ksl-more">'))->toBeFalse(
            "A {$n}-member box is short enough to draw whole — no disclosure."
        );
    }
});

it('folds a long box past the fifth row, without hiding a single member', function () {
    /*
     * ▲ FOLDED IS NOT MISSING. ▲
     *
     * Every one of the twelve is in the markup — so find-in-page reaches them,
     * a crawler reads them, and a screen reader can open the disclosure and
     * hear them. What the fold changes is what is PAINTED, and it changes it
     * with `details`, which is HTML's own control and not a line of script.
     *
     * MUTATION NOTE. Drop the <details> and render every row in the standing
     * list, and the `ksl-more` expectations are red. Render only the first five
     * and drop the rest, and the row count is. RUN, both.
     */
    $set = sfSetOf(12);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(substr_count($html, 'class="ksl-r"'))->toBe(12, 'Every member is in the markup.');
    expect(substr_count($html, '<details class="ksl-more">'))->toBe(1, 'One disclosure, not one per row.');

    // Five stand; seven are folded. The summary says how many, through
    // trans_choice, so Arabic can put the number where Arabic puts it.
    expect(str_contains($html, 'Show 7 more products'))->toBeTrue(
        'The summary must say how many rows are folded away.'
    );
    expect(str_contains($html, 'Show fewer'))->toBeTrue(
        'And carry the closing label, which CSS swaps in — there is no script to do it.'
    );

    $foldAt = (int) strpos($html, '<details class="ksl-more">');
    $standing = substr_count(substr($html, 0, $foldAt), 'class="ksl-r"');
    expect($standing)->toBe(5, 'Five rows stand above the fold.');
});

it('counts every member in the figures, folded or not', function () {
    /*
     * ▲ THE ARITHMETIC IS NOT ABOUT WHAT IS ON SCREEN. ▲
     *
     * The parts total, the set price and the saving come from
     * SetContents::fromProduct(), which knows nothing about the fold. A
     * "bought separately" that only added up the visible rows would understate
     * the box and understate its saving — the shape of mistake a fold invites.
     *
     * MUTATION NOTE. Compute the footing from $kbbSetShown instead of
     * $kbbSetPage and this is red by the seven folded members' prices. RUN.
     */
    $members = array_map(fn ($i) => [sfProduct('Fig member '.$i, 1000), 1], range(1, 12));
    $set = sfSet(9000, $members);

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    /*
     * ▲ STRIPPED OF TAGS, AND SCOPED FIRST — IN THAT ORDER. ▲
     *
     * Money::format() wraps the currency symbol in its own markup for bidi
     * isolation, so "AED 120" is never contiguous in the source and a raw
     * str_contains cannot find it.
     *
     * But strip_tags() ON THE WHOLE PAGE cannot be trusted either, and that
     * was this case's first version: PHP treats every `<` as the start of a
     * tag and drops everything up to the next `>`, so ONE inline `for (i = 0;
     * i < n; i++)` in the page's own script swallows the markup after it —
     * including this footing. It read as "the figure is missing" when the
     * figure was there, which is the most expensive kind of wrong test.
     *
     * So: cut out the two elements first, then strip those.
     */
    $chunk = function (string $class) use ($html): string {
        preg_match('/class="'.preg_quote($class, '/').'".*?<\/div>/s', $html, $m);

        return strip_tags($m[0] ?? '');
    };

    $foot = $chunk('ksl-foot');
    $label = $chunk('opt-label');

    // 12 x AED 10 = AED 120 bought separately, whatever is painted.
    expect(str_contains($foot, 'AED 120'))->toBeTrue(
        'Bought separately must add up every member, including the folded ones. Read: '.$foot
    );
    /* "12 items" and not "In this set · 12 items": the label beside it already
       says "What is in this set", and `store.set.contents` said it twice in one
       line. `store.set.count_note` is the bare count, and it counts PHYSICAL
       ITEMS rather than rows -- which is the reason to print it beside a list
       somebody could just count. */
    expect(str_contains($label, 'What is in this set'))->toBeTrue(
        'The list must be labelled. Read: '.$label
    );
    expect(str_contains($label, '12 items'))->toBeTrue(
        'And the count beside it must say twelve. Read: '.$label
    );
    expect(str_contains($label, 'In this set'))->toBeFalse(
        'The count must not repeat the label it sits beside. Read: '.$label
    );
});

/* ═══════════════════════════════════════ 5. a set with no brand at all ═══ */

it('renders a brandless set correctly everywhere the brand is printed', function () {
    /*
     * ── WHAT WAS ASKED, AND WHAT WAS ALREADY TRUE ─────────────────────────
     *
     * "the Set product type will not have any brand, so the brand selection
     *  can be optional."
     *
     * It already was, and this lane changed nothing about it: `brand_id` is
     * nullable in 0001_01_01_000000_create_kbb_schema, the product editor's
     * save validates it 'nullable', and the editor's first option has always
     * been "No brand". So this case is not a fix — it is the VERIFICATION the
     * brief asked for, done first-hand and then pinned, so the day somebody
     * makes the brand required the shop says so here rather than on the shop.
     *
     * Three places print a brand on a product page and all three are checked:
     * the brand line under the title (@if ($brand), so it is not drawn), the
     * <title> through App\Support\ProductTitle::full() (the name alone, with no
     * leading space), and the structured data (App\Support\Seo emits `brand`
     * only for a non-empty one).
     *
     * MUTATION NOTE. Drop the `@if ($brand)` guard in store/product.blade.php
     * and this is red. RUN.
     */
    $set = sfSet(14000, [[sfProduct('Brandless toner', 9000), 1]], [
        'name' => 'Quiet Ritual Box',
        'brand_id' => null,
    ]);

    expect($set->brand_id)->toBeNull('A set must be storable with no brand at all.');

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'class="bb-brand"'))->toBeFalse(
        'A product with no brand must not draw an empty brand line.'
    );

    preg_match('/<title>(.*?)<\/title>/s', $html, $kbbTitle);
    expect(str_starts_with(trim($kbbTitle[1] ?? ''), 'Quiet Ritual Box'))->toBeTrue(
        "A brandless product's title must be the name alone, with no leading space."
    );

    expect(str_contains($html, '"brand"'))->toBeFalse(
        'The structured data must not claim a brand the product does not have.'
    );
});

it('tells the owner in the Brand panel that a set needs no brand', function () {
    /*
     * The useful half of "make the brand optional": it already is, so the
     * change is the SENTENCE. The panel offered a required-looking <select>
     * with no help text, and the owner had no way to tell "optional" from "I
     * have not found where to set it yet" — which is the question he asked.
     *
     * Shown only for a set: on an ordinary product a brand is expected, and a
     * hint telling everybody it is optional would be advice this shop does not
     * want to give. Both halves are asserted, because a hint rendered
     * unconditionally is the easy mistake.
     *
     * MUTATION NOTE. Drop the `(model.type || 'simple') === 'set'` condition in
     * brandView() and the second expectation is red. Remove the hint and the
     * first is. RUN, both.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));

    $brandView = substr($src, (int) strpos($src, 'function brandView()'));
    $brandView = substr($brandView, 0, (int) strpos($brandView, 'function seoView()'));

    expect(str_contains($brandView, 'Optional for a set'))->toBeTrue(
        'The Brand panel must say that a set does not need one.'
    );
    expect(str_contains($brandView, "=== 'set'"))->toBeTrue(
        'That hint must be shown for a set and not for every product.'
    );
});
