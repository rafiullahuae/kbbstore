<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use Illuminate\Support\Str;

/**
 * The four things the owner asked for on the product page. (Lane PP)
 *
 * ── HIS WORDS, WHICH ARE THE SPECIFICATION ──────────────────────────────────
 *
 *   1. "the set products list, i want super squeeze, without pricing mentioned
 *       for each product inside the set."
 *   2. "and then a short description should come after the list."
 *   3. "give little bit space after short description, i mean between short
 *       description and bundles section."
 *   4. "can u also propose the product / set page more improved ... give me
 *       some options previews to choose from for now."
 *
 * ── WHAT THE PAGE ACTUALLY DID, MEASURED IN CHROMIUM BEFORE THE CHANGE ──────
 *
 * Every case below is written against a number read off the shipped page, not
 * against an intention:
 *
 *   - A set's row was 78px at 1280 and 74px at 390, of which the photograph was
 *     56 and 48. Three members was a 308px list; twelve was 497px, and Add to
 *     cart sat at y=876 at 1280 and y=1304 at 390.
 *   - The distance from the bottom of the short description to the top of
 *     "Choose your option" was ZERO PIXELS at 390 and at 1280 -- which is
 *     exactly the second screenshot he sent. `.bb-desc` in kbb-product.css
 *     declares margin-bottom:20px and had never once applied, because
 *     `.pdp .bb-desc{margin:12px 0 0}` in kbb.css is one class more specific
 *     and is a SHORTHAND, so it resets the bottom margin to nothing.
 *   - On a set the blurb sat ABOVE "What is in this set".
 *
 * ── AND WHAT MUST NOT MOVE ──────────────────────────────────────────────────
 *
 * CLAUDE.md rule 1. The ordinary product is 99% of this catalogue and its blurb
 * is where it was; the set's footing keeps all three of its figures, because
 * "Bought separately / Set price / You save" is the SET's economics and not a
 * per-member price; and a page with no `layout` in its query string matches
 * none of the three proposals.
 */
function sqzBrand(string $name): Brand
{
    return Brand::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5)]);
}

function sqzProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pp-'.Str::slug($name).'-'.Str::random(6),
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
function sqzSet(int $setFils, array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'pp-set-'.Str::random(8),
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

function sqzGet(string $slug, string $query = ''): string
{
    return test()->get('/product/'.$slug.'/'.$query)->assertOk()->getContent();
}

/**
 * The rows of the contents list ONLY -- from the heading to the footing.
 *
 * Scoped, and that is the whole reason this helper exists: the footing keeps
 * three prices on purpose, and an unscoped search for "AED" in the page would
 * find them, the headline price, the bundle rows and the related rail, and
 * would be true whatever the rows contained.
 */
function sqzListRows(string $html): string
{
    $start = strpos($html, 'class="ksl-rows"');
    expect($start)->not->toBeFalse('The page must carry the contents list.');

    $end = strpos($html, 'class="ksl-foot"', (int) $start);
    expect($end)->not->toBeFalse('The list must be followed by its footing.');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/* ═════════════════════════════ 1. the squeeze, and the price that left ═════ */

it('prints no price at all against any member of a set', function () {
    /*
     * "without pricing mentioned for each product inside the set."
     *
     * ▲ ASSERTED ON THE ROWS, NOT ON THE PAGE. The set's own three figures are
     *   below this chunk and are supposed to be there.
     *
     * MUTATION NOTE. Put this back into partials/set-contents-row.blade.php,
     * which is the line this lane removed:
     *
     *     <span class="ksl-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
     *
     * -- and both expectations below are red. RUN, both ways.
     */
    $brand = sqzBrand('Anua');
    $toner = sqzProduct('Heartleaf Soothing Toner', 9000, ['brand_id' => $brand->id]);
    $serum = sqzProduct('Azelaic Acid Serum', 7550, ['brand_id' => $brand->id]);

    $rows = sqzListRows(sqzGet(sqzSet(14000, [[$toner, 2], [$serum, 1]])->slug));

    expect(str_contains($rows, 'ksl-pr'))->toBeFalse(
        'The per-member price span must be gone from the markup, not hidden by CSS.'
    );
    // Money::format() splits the symbol from the figure for bidi isolation, so
    // the class name is the honest needle and the currency symbol is the
    // belt-and-braces one.
    expect(str_contains($rows, 'Price-currencySymbol'))->toBeFalse(
        'No money of any kind may be printed against a member of the set.'
    );
});

it('keeps the quantity against every member', function () {
    /*
     * The other half of the sentence: the price goes, the quantity stays. A
     * box holding two of one thing and one of another is a different box.
     *
     * MUTATION NOTE. Delete the `.ksl-q` span from the row partial and this is
     * red on both counts. RUN.
     */
    $rows = sqzListRows(sqzGet(sqzSet(14000, [
        [sqzProduct('Qty toner', 9000), 2],
        [sqzProduct('Qty serum', 7550), 1],
    ])->slug));

    expect(substr_count($rows, 'class="ksl-q"'))->toBe(2, 'One quantity per member.');
    expect(str_contains($rows, '2&times;'))->toBeTrue('A member whose quantity is 2 must say so.');
});

it('keeps the set\'s own three figures under the list', function () {
    /*
     * ▲ THE LINE THAT IS NOT A PER-MEMBER PRICE. ▲
     *
     * "Bought separately AED 325 / Set price AED 269 / You save AED 56" is the
     * reason the box reads as a bargain, and the easy way to get item 1 wrong
     * is to take it out with the member prices.
     *
     * MUTATION NOTE. Delete the `.ksl-foot` div from
     * partials/set-contents-panel.blade.php and all three are red. RUN.
     */
    $html = sqzGet(sqzSet(14000, [[sqzProduct('Foot toner', 9000), 2], [sqzProduct('Foot serum', 7550), 1]])->slug);

    expect(str_contains($html, 'Bought separately'))->toBeTrue();
    expect(str_contains($html, 'Set price'))->toBeTrue();
    expect(str_contains($html, 'You save'))->toBeTrue();
});

it('ships the squeezed sizes, on the desktop and on the phone', function () {
    /*
     * The row is 52px at 1280 and 46px at 390 now, against 78 and 74, and the
     * sizes below are where those numbers come from. Asserted on the rules the
     * page ships because the media query is the only place the phone's numbers
     * exist and a later edit drops it without noticing.
     *
     * MUTATION NOTE. Put 56px/11px back in the row rule and the first two are
     * red; delete the @media (max-width:480px) block and the last two are. RUN.
     */
    $html = sqzGet(sqzSet(14000, [[sqzProduct('Size toner', 9000), 1]])->slug);

    expect(str_contains($html, 'grid-template-columns:40px minmax(0,1fr) auto'))->toBeTrue(
        'The desktop row is a 40px thumbnail, down from 56.'
    );
    expect(str_contains($html, 'padding:6px 0'))->toBeTrue(
        'The desktop row is padded 6px, down from 11.'
    );
    expect(str_contains($html, 'grid-template-columns:36px minmax(0,1fr) auto'))->toBeTrue(
        'The phone row is a 36px thumbnail, down from 48.'
    );
    expect(str_contains($html, '.ksl-ph{width:36px;height:36px'))->toBeTrue(
        "A phone's thumbnail must be smaller than a desktop's."
    );
});

/* ═════════════════════ 2. where the short description sits ═════════════════ */

it('prints a set\'s short description AFTER the contents list', function () {
    /*
     * "and then a short description should come after the list."
     *
     * MUTATION NOTE. Drop `&& ! $kbbShortBelow` from the @if above the buy form
     * in store/product.blade.php and the "exactly once" expectation is red
     * (the blurb prints twice); drop the second @if instead, below the panel
     * include, and the ORDER expectation is red. RUN, both ways.
     */
    $set = sqzSet(14000, [
        [sqzProduct('Order toner', 9000), 1],
        [sqzProduct('Order serum', 7550), 1],
    ], ['short_description' => 'Three steps, one box.']);

    $html = sqzGet($set->slug);

    $at = function (string $needle) use ($html): int {
        $pos = strpos($html, $needle);
        expect($pos)->not->toBeFalse("The page must contain {$needle}.");

        return (int) $pos;
    };

    expect(substr_count($html, 'bb-desc'))->toBe(1, 'The blurb prints once, in one place.');
    expect($at('bb-desc'))->toBeGreaterThan($at('class="ksl-foot"'), 'The blurb belongs after the list.');
    expect($at('bb-desc'))->toBeLessThan($at('stockline'), 'And still above the stock line and the button.');
});

it('leaves an ordinary product\'s short description exactly where it was', function () {
    /*
     * ▲ CLAUDE.md RULE 1, ON THE 99% OF THIS CATALOGUE THAT IS NOT A SET. ▲
     *
     * "after the list" names no position on a page with no list. The only
     * candidates would be after the bundle strip or after Add to cart, and both
     * put the blurb below the buying decision on every product in the shop.
     *
     * MUTATION NOTE. Make $kbbShortBelow in store/product.blade.php true for
     * any product with a short description -- drop the SetContents clause --
     * and the ORDER expectation is red: the blurb lands after the bundle rows.
     * RUN.
     */
    $plain = sqzProduct('Ceramide Daily Moisturiser', 12900, [
        'short_description' => 'Demo product for layout testing.',
    ]);

    $html = sqzGet($plain->slug);

    $desc = strpos($html, 'bb-desc');
    $form = strpos($html, 'kbb-cart-form');

    expect($desc)->not->toBeFalse('An ordinary product still prints its blurb.');
    expect(substr_count($html, 'bb-desc'))->toBe(1, 'Once, as before.');
    expect((int) $desc)->toBeLessThan((int) $form, 'Above the buy form, exactly as it shipped.');
    expect(str_contains($html, 'class="ksl"'))->toBeFalse('And there is no list on this page to be after.');
});

it('does not move the blurb on a set that has no members to list', function () {
    /*
     * A `type='set'` row whose membership is empty draws no panel at all, so
     * "after the list" would mean "after a block that is not there". The guard
     * asks SetContents the same question the panel asks itself.
     *
     * MUTATION NOTE. Reduce $kbbShortBelow to `(bool) $product->short_description
     * && $product->isSet()` and this is red: the blurb drops past the option
     * slot on a set with nothing in it. RUN.
     */
    $empty = sqzSet(14000, [], ['short_description' => 'A box with nothing in it yet.']);

    $html = sqzGet($empty->slug);

    expect(str_contains($html, 'class="ksl"'))->toBeFalse('No members, no list.');
    expect((int) strpos($html, 'bb-desc'))->toBeLessThan(
        (int) strpos($html, 'kbb-cart-form'),
        'With no list to follow, the blurb stays where every other page puts it.'
    );
});

/* ═══════════════════ 3. the space under the short description ═════════════ */

it('gives the short description a bottom margin that actually applies', function () {
    /*
     * ▲ THE ONE THE OWNER PHOTOGRAPHED. ▲
     *
     * Measured in Chromium on the shipped page: the gap from the bottom of the
     * blurb to the top of "Choose your option" was 0px at 390 AND at 1280. The
     * `.bb-desc{margin-bottom:20px}` that has been in kbb-product.css all along
     * was being reset by `.pdp .bb-desc{margin:12px 0 0}` in kbb.css -- one
     * class more specific, and a SHORTHAND, so the bottom went to zero. It is
     * 18px now, at both widths, on an ordinary product and on a set.
     *
     * Both halves, because this repo's signature failure is a stylesheet fix
     * that is real in the source and stale in the bundle: `npx vite build` is
     * manual here and CI does not run it.
     *
     * MUTATION NOTE. Delete the `.pdp .bb-desc{margin-block:12px 18px}` rule
     * from resources/css/kbb/kbb-product.css, run `npx vite build`, and both
     * halves are red -- and the rendered gap goes back to 0px. RUN.
     */
    $halves = phoneBothHalves('resources/css/kbb/kbb-product.css');

    foreach ($halves as $where => $css) {
        expect(phoneHas($css, '.pdp .bb-desc{margin-block:12px 18px}'))->toBeTrue(
            "The seam under the short description is unstyled in {$where}: without a rule at THIS "
            .'specificity, .pdp .bb-desc{margin:12px 0 0} in kbb.css wins and the gap is 0px.'
        );
    }
});

/* ══════════════════════════ 4. the three proposed layouts ═════════════════ */

it('renders the shipped layout when nobody has asked for a proposal', function () {
    /*
     * ▲ THE PROPOSALS MUST NOT MOVE THE SHOP. ▲
     *
     * StorefrontEnglishUnchangedTest renders without a query string, so this is
     * the assertion that says WHY that stays green: with no `layout` parameter
     * the element is `class="pdp"`, character for character.
     *
     * MUTATION NOTE. Give the map in store/product.blade.php a default other
     * than '' -- `?? ' pp-lay pp-lay-focus'` -- and this is red. RUN.
     */
    $html = sqzGet(sqzProduct('Default layout product', 9900)->slug);

    expect(str_contains($html, '<div class="pdp">'))->toBeTrue(
        'An ordinary request renders the shipped page, unmarked.'
    );
    expect(str_contains($html, 'pp-lay'))->toBeFalse('And carries no proposal class at all.');
});

it('marks the page for each of the three proposals, and for nothing else', function () {
    /*
     * CLAUDE.md rule 5, on a page anybody can reach with a URL bar: the query
     * string is a KEY into a map written in the template, never a value that is
     * printed. A parameter that is not one of the three finds no key and gets
     * the empty string, so there is no character a visitor can put into that
     * attribute.
     *
     * MUTATION NOTE. Replace the map lookup with
     * `' pp-lay pp-lay-'.request()->query('layout')` and the last two
     * expectations are red -- the injected string lands inside class="". RUN.
     */
    $slug = sqzProduct('Preview layout product', 9900)->slug;

    foreach (['focus', 'editorial', 'compact'] as $name) {
        expect(str_contains(sqzGet($slug, '?layout='.$name), 'class="pdp pp-lay pp-lay-'.$name.'"'))
            ->toBeTrue("?layout={$name} must mark the page for that proposal.");
    }

    expect(str_contains(sqzGet($slug, '?layout=nonsense'), 'pp-lay'))->toBeFalse(
        'A layout nobody proposed renders the shipped page.'
    );
    $injected = sqzGet($slug, '?layout='.urlencode('" onmouseover=alert(1) x="'));
    expect(str_contains($injected, 'pp-lay'))->toBeFalse('And so does an injection attempt.');
    expect(str_contains($injected, 'onmouseover'))->toBeFalse(
        'Nothing from the query string reaches the markup.'
    );
});

it('ships all three proposals in the built stylesheet', function () {
    /*
     * The three are CSS and nothing else -- there is no markup behind them, so
     * a bundle built before they were written is a preview URL that renders the
     * shipped page and looks like the proposal was never made.
     *
     * MUTATION NOTE. Edit resources/css/kbb/kbb-product.css without running
     * `npx vite build` and the "(built bundle)" half is red while the source
     * half passes -- which is the exact failure this shape exists to catch. RUN.
     */
    $halves = phoneBothHalves('resources/css/kbb/kbb-product.css');

    foreach ($halves as $where => $css) {
        foreach (['focus', 'editorial', 'compact'] as $name) {
            expect(str_contains($css, '.pp-lay-'.$name))->toBeTrue(
                "Proposal {$name} is missing from {$where}."
            );
        }
        // The one thing all three share, and the one finding they all answer:
        // a 1:1 frame around a 4:5 photograph is 21% white.
        expect(phoneHas($css, '.pp-lay .gmain{aspect-ratio:4/5}'))->toBeTrue(
            "The frame that matches the photograph is missing from {$where}."
        );
    }
});
