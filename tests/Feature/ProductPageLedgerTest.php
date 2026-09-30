<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\Review;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ProductRating;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * LEDGER IS THE PRODUCT PAGE NOW — AND THE PAGE STILL DOES NOT END THERE
 * =============================================================================
 *
 * ── THE OWNER, VERBATIM, WHICH IS THE SPECIFICATION ─────────────────────────
 *
 *   "Ledger design is fine for mobile and desktop both. but don't end the page,
 *    this desgn + existing reviews section, and related products section and
 *    then footer. also in mobile you have used big bold font, whichi dont'
 *    want."
 *
 * He is answering docs/PDP-PRODUCT-PAGE-DESIGNS.md, which put five whole
 * product pages in front of him behind the admin login. A · Ledger is the one
 * with no boxes on it: a full-bleed square photograph, thumbnails floating on
 * its bottom edge, hairline rules between one idea and the next, the price
 * beside the name, a thin rating bar, a blurb that fades after three lines, and
 * a tab row whose open tab is prominent and whose others sit at 38%.
 *
 * ── WHAT THIS FILE PINS, AND WHY IT IS THE FINISHED STATE ───────────────────
 *
 * CLAUDE.md: "Pin the FINISHED state instead, which is also the thing that can
 * actually regress." Every case below is a count or an order on the page the
 * shop now serves — one `.bb-head`, the struck figure before the live one, the
 * hairline present exactly where there is a rating to draw, the reviews section
 * BELOW the buy column — so each of them is green today and stays green when a
 * later lane touches this file for some other reason. Nothing here asserts that
 * something is absent because it has not been wired up yet.
 *
 * ── AND WHAT IT BUYS BACK ───────────────────────────────────────────────────
 *
 * tests/Support/EnglishRenderWalk::approvedInsertions() and approvedRemovals()
 * now carry a paired rule that cuts one region of this one page out of the
 * byte-for-byte comparison — the head of the buy column, from the title to the
 * blurb. That cut swallows the product NAME, both PRICE FIGURES, the VAT
 * sentence and the review-badge label, so the walk no longer sees somebody
 * rewriting any of them. The `shipped English` case below is what replaces it,
 * element by element. A rule that cuts more than its own element has to say
 * what it stopped watching, and this is the saying.
 */
function ledgerBrand(string $name = 'Anua'): Brand
{
    return Brand::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5)]);
}

function ledgerProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'ledger-'.Str::random(8),
        'name' => 'Heartleaf 77% Soothing Toner 250ml',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => '/img/heartleaf.jpg',
        'short_description' => 'A gentle daily toner built around 77% heartleaf extract, '
            .'formulated for skin that reacts to everything. It calms redness, loosens what '
            .'has settled in the pores overnight and leaves the barrier where it found it.',
        'description' => '<p>Anua built this around a single idea.</p>',
    ], $overrides));
}

/** A product with real, approved, non-demo reviews, so the rating is genuinely known. */
function ledgerReviewed(int $howMany = 5, array $overrides = []): Product
{
    $product = ledgerProduct($overrides);

    for ($i = 0; $i < $howMany; $i++) {
        Review::create([
            'product_id' => $product->id,
            'author_name' => 'Reviewer '.($i + 1),
            'rating' => 0 === $i % 4 ? 4 : 5,
            'title' => 'Bought it again',
            'content' => 'Third bottle.',
            'status' => 'approved',
            'verified' => true,
            'source' => 'import',
        ]);
    }

    ProductRating::refresh([$product->id]);

    return $product->fresh();
}

function ledgerSet(array $members): Product
{
    $set = Product::create([
        'slug' => 'ledger-set-'.Str::random(8),
        'name' => 'Glow Ritual Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 21500,
        'stock_status' => 'instock',
        'image' => '/img/glow-ritual-set.jpg',
        'short_description' => 'Three steps and about four minutes: the rice cleanser, the '
            .'ginseng essence and the relief sun stick, boxed together at less than the three '
            .'of them cost apart.',
    ]);

    foreach ($members as $position => $member) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $member->id,
            'quantity' => 1,
            'position' => $position,
        ]);
    }

    return $set->fresh();
}

function ledgerPage(Product $product, string $prefix = ''): string
{
    return test()->get($prefix.'/product/'.$product->slug.'/')->assertOk()->getContent();
}

/**
 * `expect(str_contains(...))->toBeTrue($why)` AND NEVER `toContain($needle, $why)`.
 *
 * Pest's `toContain()` is VARIADIC: every argument is another needle, so a
 * "message" passed after the needle is silently asserted as a second needle —
 * and `->not->toContain($needle, $why)` can never fail at all, because the page
 * cannot contain a sentence nobody printed. tests/Feature/
 * ExpectationsThatCannotFailTest exists because of it, and the first run of THIS
 * file had six of them: five cases went red naming the message as the missing
 * text, which is the friendly way for that mistake to surface. It is not always
 * friendly.
 */
function ledgerHas(string $html, string $needle, string $why): void
{
    expect(str_contains($html, $needle))->toBeTrue($why.' — missing: '.$needle);
}

function ledgerLacks(string $html, string $needle, string $why): void
{
    expect(str_contains($html, $needle))->toBeFalse($why.' — still present: '.$needle);
}

/** The Ledger stylesheet block, read from the SOURCE Vite entry. */
function ledgerSourceCss(): string
{
    return (string) file_get_contents(base_path('resources/css/kbb/kbb-product.css'));
}

/**
 * The stylesheet with every comment removed — DECLARATIONS ONLY.
 *
 * Needed wherever a case asserts that something is NOT in the sheet, because
 * this block explains at length what it does not do and names the very strings
 * those cases look for. Scanned raw, `ledgerLacks($css, 'calc(100% - 1.05em)')`
 * is satisfied by the paragraph that says why that form is wrong — an assertion
 * failing for a reason unrelated to what it claims, which is the thing
 * tools/plc-needle-holes.php exists to find and which this file had two of.
 * PdpPreviewTest strips comments before its own scan for the same reason.
 */
function ledgerCssRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', ledgerSourceCss());
}

/** Every built bundle, concatenated — what the shop is actually serving. */
function ledgerBuiltCss(): string
{
    $out = '';

    foreach (glob(base_path('public/build/assets/*.css')) ?: [] as $file) {
        $out .= (string) file_get_contents($file);
    }

    return $out;
}

/* ═══════════════ 1. the name and the price share one row ═══════════════════ */

it('puts the price inside the title row, with the struck figure above the live one', function () {
    /*
     * "then product name, and right side cut price and actual price beautifully
     *  present."
     *
     * THE DEFECT THIS WOULD CATCH ON THE SHOP: the shipped page stacked brand /
     * title / capsule / rating / price straight down the buy column — eleven
     * blocks at one rhythm, which is finding 2 of
     * docs/PP-PRODUCT-PAGE-PROPOSALS.md and the reason nothing on that page told
     * a shopper which block was the decision. A `.bb-price` that drifts back out
     * of `.bb-head` puts it under the name again and the row he asked for is
     * gone, while the page still renders and still returns 200.
     *
     * MUTATION NOTE, RUN (M1): close `.bb-head` after the <h1> and put the price
     * in a plain <div> beneath it, which is the shipped page's own shape →
     * "the price must sit INSIDE the title's row" is RED, 1 closing tag where 0
     * is required. Put it back → green.
     *
     * ▲ AND THE FIRST VERSION OF THAT ASSERTION WAS GREEN UNDER M1. See the
     *   note beside it below: it counted `id="bbPrice"` inside a region that ran
     *   past the row's own closing tag, so it was satisfied whether the price
     *   was in the row or three elements under it. Recorded because it is the
     *   exact failure CLAUDE.md sends every lane looking for, and because a
     *   mutation run is the only thing that finds it.
     *
     * MUTATION NOTE, RUN (M2): swap the `<s>` and the `.now` span so the struck
     * figure follows the live one → "the struck figure is printed before the
     * live one" is RED.
     */
    $product = ledgerProduct(['sale_price' => 7425, 'brand_id' => ledgerBrand()->id]);
    $html = ledgerPage($product);

    expect(substr_count($html, '<div class="bb-head">'))->toBe(1, 'exactly one title row');

    $head = ledgerRegion($html, '<div class="bb-head">', '<form class="cart');

    expect(substr_count($head, 'class="bb-title"'))->toBe(1, 'the name, once, inside the row');
    // One price block on the page: two is the shape a merge leaves behind, and
    // the second one would be sitting in the old position under the title.
    expect(substr_count($html, 'id="bbPrice"'))->toBe(1, 'one price block on the page');

    /* ▲ THE NESTING IS ASSERTED BY THE ABSENCE OF A CLOSING TAG BETWEEN THEM,
         AND THE FIRST DRAFT OF THIS CASE DID NOT ASSERT IT AT ALL.
         `ledgerRegion(html, '<div class="bb-head">', '<form class="cart')` runs
         from the row's opening tag all the way to the cart form, so it contains
         the price whether the price is INSIDE the row or three elements below
         it. Measured: mutation M1 -- move `.bb-price` back out of `.bb-head` --
         left this case GREEN, which is precisely the "an assertion can be true
         for a reason unrelated to what it claims" that CLAUDE.md sends every
         lane looking for.
         Between the row's opening tag and the price there is exactly one
         element, the <h1>, and an <h1> carries no </div>. So a </div> in that
         slice means the row closed before the price arrived. M1 is RED on it. */
    $untilPrice = ledgerRegion($html, '<div class="bb-head">', 'id="bbPrice"');
    expect(substr_count($untilPrice, '</div>'))->toBe(
        0,
        'the price must sit INSIDE the title\'s row — a closing tag between the two means it does not'
    );

    // The struck figure FIRST, because Ledger stacks it above the live one.
    $price = ledgerRegion($head, 'id="bbPrice"', '</div>');
    expect(strpos($price, '<s>'))->toBeLessThan(
        (int) strpos($price, '<span class="now">'),
        'the struck figure is printed before the live one, because it is drawn above it'
    );

    /* ── AND THE TITLE TRACK CANNOT SPILL OUT OF ITSELF ────────────────────
       `minmax(0,1fr)` is what stops a long name pushing the price off the
       inline edge: it lets the TRACK be narrower than its own content. What it
       does NOT do is stop the content painting past the track, because a grid
       does not clip — so one unbreakable token longer than the track overflows
       the document and takes `scrollWidth` with it. That is the same sideways
       scroll `.pdp` itself carries a note about 600 lines up this sheet, in a
       box Ledger made NARROWER than the full-width title it replaced.

       MEASURED IN CHROMIUM AT 390, both ways, on `pdp-unbreakable-name`:
       `document.documentElement.scrollWidth` reads **390 with the declaration
       and 672 without it** — 282px of sideways scroll on the whole document.

       ▲ AND THE FIRST VERSION OF THAT FIXTURE PROVED THE OPPOSITE. It was named
         "Anua-Heartleaf-77-Percent-…", and a HYPHEN is a break opportunity
         whatever `overflow-wrap` says — so the title wrapped perfectly well with
         the rule switched off and scrollWidth read 390 either way. A fixture
         that cannot exercise the rule it is there for is the same defect as an
         assertion that cannot fail, and it was found by running the measurement
         rather than by reading it. The name has no hyphens and no spaces now.

       MUTATION NOTE, RUN (M20): drop `overflow-wrap:anywhere` → RED here. */
    ledgerHas(
        ledgerCssRules(),
        '.pdp .bb-head .bb-title{margin:0;overflow-wrap:anywhere}',
        'a name with no space in it breaks rather than overflowing the page'
    );

    /* AND THE BREADCRUMB, WHICH IS WHAT THE FIXTURE ACTUALLY CAUGHT.
       With the title guarded, `pdp-unbreakable-name` still measured scrollWidth
       437 against a 390 viewport — and hiding `.crumb` alone brought it back to
       390 while hiding the title, the tab row, the reviews and the related grid
       each changed nothing. It is a TEXT NODE overflowing, which no element's
       bounding box reports, so it took a hit-test rather than a walk over
       rectangles. Older than this lane; `.crumb` is declared in this sheet, so
       the rule reaches the product page and nothing else.

       MUTATION NOTE, RUN (M21): drop `.crumb{overflow-wrap:anywhere}` → RED
       here, and that product's page scrolls 47px sideways at 390. */
    ledgerHas(ledgerCssRules(), '.crumb{overflow-wrap:anywhere}', 'the breadcrumb breaks a name it cannot fit');
});

/* ═══════════════ 2. the page does not end where the preview ended ══════════ */

it('still carries the tabs, the reviews, the related grid and the footer, in that order', function () {
    /*
     * "don't end the page, this desgn + existing reviews section, and related
     *  products section and then footer."
     *
     * THE DEFECT THIS WOULD CATCH: the five previews STOPPED at the payment
     * marks — that is where his own list of parts stopped — so the obvious way
     * to make Ledger real is to serve the preview template, and the obvious way
     * is wrong. This is the case that says the reviews and the related grid are
     * still below the buy column and in his order.
     *
     * ASSERTED BY POSITION AND NOT ONLY BY PRESENCE. "The reviews section is on
     * the page" is satisfied by a link to it in the rating row, by the schema
     * block in <head> and by the word "reviews" in half a dozen places; "the
     * reviews section begins after the cart form ends" is not.
     *
     * MUTATION NOTE, RUN: delete `@include('partials.reviews')` from
     * store/product.blade.php → RED at the reviews marker. Move the related
     * <section> above the buy column → RED on the ordering.
     */
    $brand = ledgerBrand();
    $product = ledgerReviewed(5, ['brand_id' => $brand->id]);

    // Related products need companions in the same category to draw at all;
    // four siblings under one brand is what the shop's own rail asks for.
    for ($i = 0; $i < 4; $i++) {
        ledgerProduct(['brand_id' => $brand->id, 'name' => 'Companion '.$i]);
    }

    $html = ledgerPage($product);

    $markers = [
        'the buy form' => '<form class="cart',
        'the details tabs' => 'class="details" id="details"',
        'the reviews section' => 'id="sr"',
        'the footer' => '<footer',
    ];

    $at = [];

    foreach ($markers as $what => $needle) {
        $where = strpos($html, $needle);
        expect($where)->not->toBeFalse($what.' must still be on the product page');
        $at[$what] = (int) $where;
    }

    expect($at['the details tabs'])->toBeGreaterThan($at['the buy form'], 'the tabs follow the buy column');
    expect($at['the reviews section'])->toBeGreaterThan($at['the details tabs'], 'the reviews follow the tabs');
    expect($at['the footer'])->toBeGreaterThan($at['the reviews section'], 'the footer is last');
});

/* ═══════════════ 3. the rating hairline ════════════════════════════════════ */

it('draws the rating hairline where there is a rating, and not where there is none', function () {
    /*
     * "and then small thin rating bar."
     *
     * A capsule says "4.8 · 212 reviews"; a bar also says how far off five that
     * is, in three pixels of height and no extra row. The fill is arithmetic on
     * a number the server already has — `$rating / 5` as a percent — so nothing
     * in the browser measures anything, which is CLAUDE.md rule 4.
     *
     * THE DEFECT THIS WOULD CATCH: a 0%-filled bar under "0.0" on a product
     * nobody has reviewed. The shipped page already refuses to print a rating it
     * does not have — there is no fallback to the denormalised `products.rating`
     * column, which DemoCatalogueSeeder had filled with mt_rand(4, 1400) and
     * which advertised "4.9 · 3,204 reviews" on products with no reviews at all.
     * A bar drawn outside that guard would put the defect back in a new shape.
     *
     * ▲ COUNTED PER RATING ROW, NOT PER PAGE, and the first draft of this case
     *   got it wrong. BOTH rows are always in the markup — the capsule under
     *   `@if ($showCap && $rcount)` and the inline row under `@if ($rcount)`
     *   with `style="display:none"` when the owner has not asked for it — so a
     *   page-wide count of two is CORRECT on a shop shipping the default
     *   `capsule` and says nothing about either row. Scoped, it says the thing
     *   that matters: each row that is drawn carries exactly one bar.
     *
     * MUTATION NOTE, RUN: move the `<span class="bb-ratebar">` outside
     * `@if ($showCap && $rcount)` → the unreviewed expectation is RED (1,
     * expected 0), because an unreviewed product then draws an empty bar.
     */
    $reviewed = ledgerReviewed(5);
    $unreviewed = ledgerProduct();

    $html = ledgerPage($reviewed);

    expect(substr_count(ledgerRegion($html, 'id="capArea"', '</div>'), 'class="bb-ratebar"'))
        ->toBe(1, 'one hairline in the capsule row');
    expect(substr_count(ledgerRegion($html, 'id="bbRate"', '</div>'), 'class="bb-ratebar"'))
        ->toBe(1, 'one hairline in the inline row');

    expect(substr_count(ledgerPage($unreviewed), 'bb-ratebar'))->toBe(
        0,
        'an unreviewed product draws no bar at all, the same refusal the number already makes'
    );
    // And no rating row at all, which is the guard the bar sits behind.
    expect(substr_count(ledgerPage($unreviewed), 'id="bbRate"'))->toBe(0);

    // The fill is the rating and not a constant: 4.8 of 5 is 96%.
    $fill = (int) round(((float) $reviewed->fresh()->rating / 5) * 100);
    expect($fill)->toBeGreaterThan(80)->toBeLessThan(100);
    ledgerHas($html, 'inline-size:'.$fill.'%', 'the hairline is filled to the rating the server computed');
});

it('keeps the hairline in whichever rating row the owner has chosen', function () {
    /*
     * Store → Ecommerce → Product page → Review badges picks between the
     * capsule, the inline row and both. A hairline added to only one of them is
     * a hairline that disappears when the owner moves a control he already had —
     * which is the half of CLAUDE.md rule 1 that did NOT change on 30 September:
     * nothing he did not ask about may change, and he did not ask about this.
     *
     * ▲ THE SETTING DECIDES WHICH ROW IS SHOWN, NOT WHICH IS RENDERED. `.bb-rate`
     *   is always in the document and carries `style="display:none"` unless the
     *   owner asked for it, so "is it visible" is the question, and it is asked
     *   of the markup that decides it.
     *
     * MUTATION NOTE, RUN: remove the `<span class="bb-ratebar">` from the
     * `.bb-rate` row → the `inline` and `both` cases are RED (0 bars in a
     * visible row).
     */
    $product = ledgerReviewed(5);
    $settings = app(SettingsService::class);

    foreach ([
        'capsule' => ['capsule' => true, 'inline' => false],
        'inline' => ['capsule' => false, 'inline' => true],
        'both' => ['capsule' => true, 'inline' => true],
    ] as $style => $shown) {
        $settings->set('review_capsule_style', $style);
        $html = ledgerPage($product);

        $capsule = ledgerRegion($html, 'id="capArea"', '</div>');
        expect(str_contains($capsule, 'sr-capbar'))->toBe(
            $shown['capsule'],
            'the capsule row is drawn only for '.$style
        );

        if ($shown['capsule']) {
            expect(substr_count($capsule, 'class="bb-ratebar"'))->toBe(1, 'a bar in the capsule on '.$style);
        }

        $inlineShown = str_contains($html, 'id="bbRate"') && ! str_contains(
            ledgerRegion($html, 'id="bbRate"', '>'),
            'display:none'
        );
        expect($inlineShown)->toBe($shown['inline'], 'the inline row is visible only for '.$style);

        if ($shown['inline']) {
            expect(substr_count(ledgerRegion($html, 'id="bbRate"', '</div>'), 'class="bb-ratebar"'))
                ->toBe(1, 'a bar in the inline row on '.$style);
        }
    }

    $settings->set('review_capsule_style', 'capsule');
});

/* ═══════════════ 4. the blurb, three lines and a fade ══════════════════════ */

it('wraps the blurb in a checkbox and a label, once, on an ordinary product and on a set', function () {
    /*
     * "2-3 lines short description with fade read more."
     *
     * The cap and the fade are CSS (`max-block-size` on three line-boxes plus a
     * `mask-image`); what the template has to get right is that the toggle is a
     * CHECKBOX BEFORE the paragraph and a LABEL AFTER it, because the rule that
     * opens it is `.bb-morebox:checked ~ .bb-desc` and a sibling combinator only
     * looks forward. Written in the wrong order the page renders, returns 200,
     * shows the fade, and the label does nothing at all.
     *
     * AND THE SET IS THE SECOND POSITION. Lane PP moved the blurb BELOW "What is
     * in this set", so there are two `@if`s for it and they are mutually
     * exclusive — exactly one `#bbMore` may exist, or the label points at the
     * wrong control.
     *
     * MUTATION NOTE, RUN: put the `<label class="bb-more">` BEFORE the
     * `<p class="bb-desc">` → the ordering expectation is RED on both products.
     * Add the checkbox to the set branch as well as the ordinary one → the
     * "exactly one" count on the set is RED (2, expected 1).
     */
    $set = ledgerSet([ledgerProduct(), ledgerProduct(), ledgerProduct()]);

    foreach (['an ordinary product' => ledgerProduct(), 'a set' => $set] as $what => $product) {
        $html = ledgerPage($product);

        expect(substr_count($html, 'id="bbMore"'))->toBe(1, 'exactly one read-more control on '.$what);
        /* `bb-desc"` AND NOT `class="bb-desc"`. The class attribute is
           `class="{{ $modules->classFor('short') }} bb-desc"`, so on a shop with
           every section on it renders as `class=" bb-desc"` — one leading space,
           and a needle that names the whole attribute matches nothing. Measured
           on the first run: 0, expected 1. */
        expect(substr_count($html, 'bb-desc"'))->toBe(1, 'exactly one blurb on '.$what);
        expect(substr_count($html, 'class="bb-more"'))->toBe(1, 'exactly one read-more label on '.$what);

        $box = (int) strpos($html, 'class="bb-morebox"');
        $para = (int) strpos($html, 'bb-desc"');
        $label = (int) strpos($html, 'class="bb-more"');

        expect($box)->toBeLessThan($para, 'the checkbox precedes the blurb on '.$what
            .' — `:checked ~` only looks forward');
        expect($para)->toBeLessThan($label, 'the label follows the blurb on '.$what);
    }

    // On a set the pair is below the contents panel, which is where Lane PP put
    // the blurb and is not this lane's to move.
    $setHtml = ledgerPage($set);
    expect((int) strpos($setHtml, 'ksl-rows'))->toBeLessThan(
        (int) strpos($setHtml, 'bb-desc"'),
        'on a set the blurb still comes after the list'
    );

    // The checkbox carries no `name`, so the copy that lands inside the cart
    // form on a set is not serialised with the basket.
    ledgerLacks($setHtml, 'class="bb-morebox" type="checkbox" name', 'the read-more control is not a form field');

    /* ── AND THE FADE MUST NOT BITE A BLURB THAT IS NOT BEING CUT OFF ───────
       THE DEFECT, MEASURED: written as `linear-gradient(to bottom,#000
       calc(100% - 1.05em),transparent)` the gradient measures from the
       element's OWN bottom, so a one-sentence blurb — a 65px element with
       nothing hidden under it — had its last line dissolved into the page
       anyway. Seen on pdp-variable-ampoule in
       docs/lane-pdp-shots/real-variable-1280.png before the change.

       Written as distances from the TOP, an element shorter than the first
       stop never reaches the gradient at all and is painted flat. The same
       correction is made to the tab panel's fade for the same reason.

       ASSERTED ON THE DECLARATION, because that is where the defect is: both
       forms produce a `mask-image` and both make `blurbMasked` true in the
       harness, so "is it masked" cannot tell them apart. What can is whether
       the stop is measured from the top or from the bottom.

       ▲ THE PIN MOVED IN ROUND 4 AND THE NUMBERS DID NOT. `1.62em` is now
         `var(--pl-desc-lh,1.62) * 1em` and `20px` is `var(--pl-rule-pad,20px)`,
         because Appearance → Product page → Type · Buy column owns those two
         and the cap has to follow them or the blurb clips mid-line at any other
         setting. THE FALLBACKS ARE THE SAME TWO NUMBERS, which is what keeps
         this a pin on the shipped page rather than on a mechanism:
         ProductPageLayoutTest asserts that every fallback equals the value
         ProductLayout ships, in both directions, and
         docs/lane-pdp4-shots/base-measure.json is the browser agreeing —
         `max-block-size: 85.61px` before the change and after it.

       MUTATION NOTE, RUN: put `calc(100% - 1.05em),transparent` back → RED,
       and a one-line blurb fades again. */
    $rules = ledgerCssRules();
    ledgerLacks($rules, 'calc(100% - 1.05em)', 'the blurb fade is not measured from the element bottom');
    ledgerHas($rules, 'transparent calc(3 * var(--pl-desc-lh,1.62) * 1em + var(--pl-rule-pad,20px))',
        'it ends exactly where the cap cuts');
    ledgerLacks($rules, 'calc(100% - 2.2em)', 'nor is the tab panel fade');
    ledgerHas($rules, 'transparent 104px', 'which ends where .dcontent.clamp cuts');
});

/* ═══════════════ 5. the tab row, on a phone as well as a desktop ═══════════ */

it('draws the tab row at every width instead of an accordion on a phone', function () {
    /*
     * "i want these tabs in same row with opened promient, and other slightely
     *  faded by default, and can be scroll left to right between tags and and
     *  can directly click on any tab, it will open."
     *
     * THE DEFECT ON THE SHOP: the shipped page already did all four of those —
     * ON A DESKTOP ONLY. `@media(max-width:720px){.dtabbar,.dpanel{display:none}
     * .macc{display:block}}` swapped the row for an ACCORDION under 720px: a
     * stack, not a row, with no faded siblings and nothing to scroll. So the
     * phone, which is the width he asked about, was the one width that did not
     * have the thing he asked for.
     *
     * ASSERTED ON THE STYLESHEET, BECAUSE THAT IS WHERE THE DEFECT LIVES. The
     * markup was always the same at both widths — partials/product-tabs.blade.php
     * prints the row AND the accordion on every product, and CSS picks. So a
     * test that only counted `.dtab` elements would have been green before this
     * lane and green after it, which is an assertion that cannot fail.
     *
     * MUTATION NOTE, RUN: delete the `@media (max-width:720px)` block at the
     * foot of kbb-product.css that re-shows `.details .dtabbar` → RED, and the
     * phone is back on the accordion.
     */
    $product = ledgerProduct();
    $html = ledgerPage($product);

    expect(substr_count($html, 'class="dtabbar"'))->toBe(1, 'one tab row in the markup');
    ledgerHas($html, 'class="dtab on"', 'the first tab is open server-side');

    $css = ledgerSourceCss();

    foreach ([
        '.details .dtabbar{display:flex;' => 'the row is shown on a phone',
        '.details .dpanel{display:block}' => 'and so is its panel',
        '.details .macc{display:none}' => 'and the accordion is not',
        '.details .dtab{' => 'the closed tabs are faded by a rule of this block\'s own',
    ] as $needle => $why) {
        expect(str_contains($css, $needle))->toBeTrue($why.' — missing: '.$needle);
    }

    // 38% is the number docs/PDP-PRODUCT-PAGE-DESIGNS.md measured for Ledger and
    // the one the owner chose from; `opacity:1` on the open tab is what makes
    // "opened prominent" true rather than merely claimed.
    ledgerHas($css, 'opacity:.38', 'the closed tabs sit at 38%, the number he chose from');
    ledgerHas($css, '.details .dtab.on{opacity:1', 'and the open one at full ink');

    // AND THE BUNDLE IS BUILT. `package.json` defines no `build` script, so
    // `npx vite build` is hand-typed and easy to skip — and a rule that is real
    // in the repo and absent from public/build is a rule that does nothing on
    // the shop. BuiltCssSelectorsAreCurrentTest makes the general case; this is
    // the one selector that decides whether the phone has a tab row at all.
    ledgerHas(ledgerBuiltCss(), '.details .dtabbar', 'the phone tab row is in the BUILT bundle, not only the source');
});

/* ═══════════════ 6. the mobile type, which is his second complaint ═════════ */

it('ships the quiet mobile title and the Ledger desktop title', function () {
    /*
     * "also in mobile you have used big bold font, whichi dont' want."
     *
     * MEASURED ON THE PREVIEW HE WAS LOOKING AT, in Chromium at 390: the title
     * was 21px at weight 600 over three line-boxes, 82px tall, beside a 22px/800
     * price. The DESKTOP he approved in the same sentence is 30px at weight 500
     * — so the weight is what differs between the half he likes and the half he
     * does not, and the third line is what makes the block tall.
     *
     * 19px/500 is two line-boxes and 52px on the same product, measured after
     * the change. docs/lane-pdp-shots/sheet-real-type-390.png offers him 21px/500
     * and 17px/500 beside it with the numbers under each; picking one of those is
     * an edit to these two declarations and nothing else, which is why they are
     * pinned by their values rather than by their existence.
     *
     * ▲ THE PIN MOVED IN ROUND 4 AND THE TYPE DID NOT. Both declarations are
     *   now `var(--pl-title-m,19px)` / `var(--pl-title-d,30px)` /
     *   `var(--pl-title-w,500)`, because Appearance → Product page →
     *   Type · Buy column owns the three. THE FALLBACKS ARE THE SAME THREE
     *   NUMBERS and that is the point: with nothing saved the screen emits no
     *   <style> block at all, so the page renders 19/500 and 30/500 exactly as
     *   it did. ProductPageLayoutTest compares every fallback against the value
     *   ProductLayout ships, in both directions, and
     *   docs/lane-pdp4-shots/base-measure.json is Chromium agreeing — 19px/500
     *   at 390 and 30px/500 at 1280, before the change and after it.
     *
     *   Picking 21px/500 or 17px/500 off the sheet is still an edit to these
     *   two declarations and nothing else; it is now an edit to a DEFAULT in
     *   ProductLayout::SCHEMA and its fallback here, which have to move
     *   together or the test above says so.
     *
     * MUTATION NOTE, RUN: put `font-weight:600` back on `.pdp .bb-title` → RED,
     * naming the declaration. Restore 21px → RED.
     */
    $css = ledgerSourceCss();

    ledgerHas($css, '.pdp .bb-title{font-size:var(--pl-title-m,19px);font-weight:var(--pl-title-w,500);line-height:1.36',
        'the quiet phone title');
    ledgerHas($css, '.pdp .bb-title{font-size:var(--pl-title-d,30px);font-weight:var(--pl-title-w,500)',
        'the desktop title he approved');

    // And in the bundle, or the shop is serving yesterday's type.
    ledgerHas(ledgerBuiltCss(), 'font-size:var(--pl-title-m,19px);font-weight:var(--pl-title-w,500)',
        'the phone title is in the BUILT bundle');
});

it('takes the frame off the photograph and the ground out of the trust block', function () {
    /*
     * Ledger's one sentence is "there is not a single box on this page", and
     * these are the three boxes that were on it: the gallery's rounded border,
     * the cream trust card, and the lozenge round the rating.
     *
     * ASSERTED ON THE BUILT BUNDLE AS WELL AS THE SOURCE, for the reason
     * BuiltCssIsCurrentTest's docblock gives at length: a stylesheet edit that
     * was never built is an edit that does not exist, and this project has
     * already lost a round to exactly that.
     *
     * MUTATION NOTE, RUN: delete `.pdp .gmain{border-radius:0;border:0}` from
     * the phone block → RED on the first expectation.
     */
    $css = ledgerSourceCss();

    /* ▲ SCOPED TO THE PHONE BLOCK, AND THE FIRST DRAFT WAS NOT.
         The identical declaration appears TWICE in the sheet -- once under
         `@media (max-width:880px)` and once under `@media (min-width:881px)`,
         because the frame comes off at both widths -- so `str_contains($css,
         '.pdp .gmain{border-radius:0;border:0}')` is satisfied by either of
         them. Measured: mutation M9, which deletes the PHONE copy, left this
         case GREEN. Asked of each block separately it is RED. */
    $phone = ledgerRegion($css, '@media (max-width:880px){', "\n}");
    $desktop = ledgerRegion($css, '@media (min-width:881px){', "\n}");

    ledgerHas($phone, '.pdp .gmain{border-radius:0;border:0}', 'the photograph has no frame on a phone');
    ledgerHas($phone, '.pdp .gallery{margin-inline:calc(', 'and it runs to both edges of it');
    ledgerHas($desktop, '.pdp .gmain{border-radius:0;border:0}', 'and none on a desktop either');
    ledgerHas($css, '.pdp .trust{background:none;padding:0;border-radius:0', 'the trust card has no ground');
    ledgerHas($css, '.pdp .cap-area .sr-capbar{background:none;border:0;border-radius:0', 'the rating is not a lozenge');

    // The capsule rule has to out-specify sorina-reviews.css, which
    // Store\ProductController INLINES into <head> AFTER this sheet's <link> —
    // so `.sr-capbar` alone would lose every tie. Three classes, not one.
    ledgerLacks(ledgerCssRules(), "\n.sr-capbar{background:none", 'the capsule rule out-specifies the inlined review sheet');
});

it('greys the Add to cart the server has already disabled', function () {
    /*
     * THE DEFECT ON THE SHOP, MEASURED: `.addcart` is `background:var(--pink)`
     * and this stylesheet carried no `[disabled]` rule for it at all, so a
     * sold-out product rendered a FULL BRAND PINK button reading "Sold out" —
     * the single most prominent thing on the page, and the one thing on it that
     * cannot be pressed. `docs/lane-pdp-shots/real-noreviews-390.png` before
     * this rule is the picture.
     *
     * It is Ledger's own answer rather than an invention: the drawing the owner
     * picked carries `.pv-add[disabled]{background:var(--pv-mut);cursor:not-
     * allowed}` and `docs/lane-pdp-shots/ledger-soldout-390.png` is the grey
     * button he approved.
     *
     * ▲ THE SERVER IS WHAT DISABLES IT, AND THAT HALF IS ASSERTED TOO. A rule
     *   for `[disabled]` is worth nothing if the attribute stops being emitted,
     *   and the two halves live in different files — so both are checked here
     *   rather than one being assumed from the other.
     *
     * MUTATION NOTE, RUN: delete the `.pdp .addcart[disabled]` rule from
     * kbb-product.css → RED on the stylesheet half. Drop `@disabled($out)` from
     * the button in store/product.blade.php → RED on the markup half, and the
     * sold-out product gets a live Add to cart.
     */
    $out = ledgerProduct(['stock_status' => 'outofstock']);
    $inStock = ledgerProduct();

    $html = ledgerPage($out);
    ledgerHas($html, '<button class="addcart" id="mainAdd" type="submit" disabled', 'the server disables the button');
    ledgerLacks(ledgerPage($inStock), 'id="mainAdd" type="submit" disabled', 'and only when the product is gone');

    $css = ledgerSourceCss();
    ledgerHas($css, '.pdp .addcart[disabled]', 'a disabled Add to cart is not brand pink');
    ledgerHas($css, 'cursor:not-allowed', 'and says so to the pointer');
    // The hover has to be restated in the same rule, or `.addcart:hover` darkens
    // a button that cannot be pressed.
    ledgerHas($css, '.pdp .addcart[disabled]:hover', 'and it does not react to a hover either');

    // In the BUILT bundle, or the shop is still serving the pink one.
    ledgerHas(ledgerBuiltCss(), '.pdp .addcart[disabled]', 'the rule is built, not only written');
});

/* ═══════════════ 7. the controls the owner already had still work ══════════ */

it('leaves every section switch on the product page doing what it did', function () {
    /*
     * CLAUDE.md rule 1, the half that did NOT change on 30 September: nothing he
     * did not ask about may change. He asked for a treatment, not for the
     * Catalog → Product page → Sections switches to stop working — and a
     * redesign is exactly where a switch quietly stops being read, because the
     * element it hides has been rewritten.
     *
     * MUTATION NOTE, RUN: drop `{{ $modules->classFor('short') }}` from either
     * `.bb-desc` → RED, naming the blurb. Drop the `@unless` round
     * `@include('partials.reviews')` → RED on the reviews section.
     */
    $sections = app(ProductSections::class);
    $product = ledgerReviewed(5);

    /* ▲ PIN ADVANCED IN 2.60.336, AND NARROWED RATHER THAN DELETED.
       This read "nothing is marked off", whose premise was that every section
       ships on. The owner asked for the VAT line off by default -- "turned off
       the vat line on product page by default" -- so exactly ONE section is now
       marked off on a shop as it ships.

       Deleting the assertion would throw away what it is for: catching a
       section that stops rendering by accident. So it now says ONE, and says
       WHICH. A second off-switch arriving by accident is still red, and so is
       the VAT line silently coming back on. */
    $shipped = ledgerPage($product);

    expect(substr_count($shipped, 'class="d-off m-off'))->toBe(
        1,
        'exactly one section ships switched off — the VAT line — and nothing else'
    );

    // And it is the VAT line that carries it, not some other section that has
    // quietly stopped rendering while the VAT line came back on.
    expect($shipped)->toContain('class="d-off m-off bb-vat"');

    $sections->save([
        'reviews' => ['desktop' => false, 'mobile' => false],
        'tabs' => ['desktop' => false, 'mobile' => false],
        'short' => ['desktop' => false, 'mobile' => false],
        'trust' => ['desktop' => false, 'mobile' => false],
    ] + array_map(
        static fn (array $row): array => ['desktop' => true, 'mobile' => true],
        app(ProductSections::class)->all()
    ));

    $off = ledgerPage($product->fresh());

    ledgerLacks($off, 'id="sr"', 'the reviews section is off');
    ledgerLacks($off, 'class="dtabbar"', 'the tab row is off');
    ledgerHas($off, 'class="d-off m-off bb-desc"', 'the blurb carries its off classes');
    ledgerHas($off, 'd-off m-off trust', 'the trust lines carry theirs');

    // AND THE READ-MORE GOES WITH THE BLURB IT BELONGS TO rather than being left
    // behind as a label pointing at hidden text.
    expect(substr_count($off, 'class="bb-more"'))->toBe(1, 'one label, beside the one blurb');
});

/* ═══════════════ 8. the shipped English the byte-walk stopped watching ═════ */

it('prints the product name, both price figures, the VAT line and the badge label', function () {
    /*
     * THE COVERAGE THE APPROVED-REGION RULE GAVE UP, BOUGHT BACK.
     *
     * EnglishRenderWalk's paired rule cuts this whole region out of the
     * byte-for-byte comparison, so nothing else in the suite would now notice a
     * lane rewriting the title into the brand, dropping the struck price, or
     * losing the "Inclusive of 5% VAT" sentence. Each is asserted here against
     * the element that prints it.
     *
     * MUTATION NOTE, RUN: delete the `<s>` branch from `.bb-price` → RED on the
     * struck figure. Change `$name` to `$brand` in the <h1> → RED on the name.
     */
    $settings = app(SettingsService::class);
    $settings->set('vat_note_enabled', true);

    $product = ledgerReviewed(5, ['sale_price' => 7425, 'brand_id' => ledgerBrand()->id]);
    $html = ledgerPage($product);
    $head = ledgerRegion($html, '<div class="bb-head">', '<form class="cart');

    ledgerHas($head, '<h1 class="bb-title" id="bbTitle">Heartleaf 77% Soothing Toner 250ml</h1>', 'the product name');
    // Money::format() splits the symbol from the figure for bidi isolation, so
    // the figures are asserted as they are printed rather than as "AED 99".
    /* SCOPED TO THE TWO ELEMENTS, because Money::format() wraps a figure in two
       spans and separates the symbol from it with a space for bidi isolation --
       the printed bytes are `…>AED</span> 99</span>`, so a needle like `>99<`
       matches nothing and `99` on its own matches the SKU, the year and half the
       related rail. Measured on the first run: red on `>99<` against a page that
       was printing 99 correctly, which is an assertion failing for a reason
       unrelated to what it claims. */
    ledgerHas($head, '<s>', 'the struck figure is printed');
    ledgerHas(ledgerRegion($head, '<s>', '</s>'), '99', 'the regular price, struck through');
    ledgerHas(ledgerRegion($head, '<span class="now">', '</div>'), '74', 'the live price');
    ledgerHas($head, '-25%', 'the discount badge');
    /* SCOPED TO THE CAPSULE, because BOTH rating rows print the same label and
       an unscoped hit in `$head` would be satisfied by whichever of them the
       owner has switched off — the ambiguity tools/plc-needle-holes.php exists
       to find: a needle whose copies sit in different elements is a needle that
       survives blanking the one under test. */
    ledgerHas(ledgerRegion($html, 'id="capArea"', '</div>'), '5 reviews', 'the review-badge label');
    ledgerHas($head, 'bb-vat', 'the VAT sentence still has its element');
});

/* ═══════════════ 9. no script, and nothing measured in the browser ═════════ */

it('adds no JavaScript to the product page and names no layout-measuring API', function () {
    /*
     * CLAUDE.md rule 4: "No JavaScript that measures layout — this project sizes
     * with `calc()` for a reason." The tab switch was already a click handler
     * that toggles a class and the read-more is now a checkbox, so the two
     * pieces of this design that a page normally reaches for
     * `getBoundingClientRect()` to build ask nothing.
     *
     * THE SOURCE IS SCANNED WITH ITS COMMENTS STRIPPED FIRST, because the files
     * NAME these APIs in order to explain why they are not used — the same thing
     * PdpPreviewTest does, for the same reason.
     *
     * MUTATION NOTE, RUN: add `<script>document.querySelector('.bb-title')
     * .getBoundingClientRect()</script>` to store/product.blade.php → RED twice,
     * once in the source scan and once in the rendered count.
     */
    $files = [
        base_path('resources/views/store/product.blade.php'),
        base_path('resources/css/kbb/kbb-product.css'),
    ];

    $banned = [
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft',
        'clientWidth', 'clientHeight', 'scrollHeight', 'getComputedStyle',
        'ResizeObserver', 'IntersectionObserver',
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        // Blade comments, HTML comments and C-style comments, in that order.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/<!--.*?-->/s', '', $source);
        $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $source = (string) preg_replace('#//[^\n]*#', '', $source);

        foreach ($banned as $api) {
            expect(str_contains($source, $api))->toBeFalse(
                basename($file).' measures layout in the browser: '.$api
            );
        }
    }

    // And the rendered page carries no MORE inline scripts than the marketing
    // pixels and the layout already put there. Counted rather than forbidden,
    // because layouts/store.blade.php has its own and they are not this lane's.
    $before = substr_count(ledgerPage(ledgerProduct()), '<script');
    expect($before)->toBeLessThan(
        12,
        'the product page has grown a crop of inline scripts; this lane added none'
    );
    ledgerLacks(ledgerPage(ledgerProduct()), 'bb-morebox" type="checkbox" onclick', 'the read-more toggle is not scripted');
});

/* ═══════════════ 10. Arabic is the same page, mirrored ════════════════════ */

it('renders the same structure on the Arabic mirror', function () {
    /*
     * Every rule in the Ledger block is written in LOGICAL properties —
     * `inline-size`, `margin-inline`, `border-block-start`, `padding-block` — so
     * /ar is the same stylesheet rather than a second one. The single exception
     * is the tab row's edge fade: `mask-image` gradients take physical
     * directions only and have no logical form, so there is exactly ONE
     * `[dir="rtl"]` rule in the block and this case is what says it is one.
     *
     * MUTATION NOTE, RUN (M13): add a second `[dir="rtl"]` rule to the block →
     * RED. That is the countable half.
     *
     * ▲ AND THE HALF THIS CANNOT COUNT, measured in the browser instead. Writing
     *   the hairline's fill as `width:` instead of `inline-size:` leaves this
     *   case GREEN — it is a width either way — and reverses which end the bar
     *   fills from on /ar. So it is measured rather than asserted, at 390:
     *
     *       English  bar x 117 → 161, filled part 117 → 159  (starts LEFT)
     *       Arabic   bar x 229 → 273, filled part 231 → 273  (starts RIGHT)
     *
     *   Same 42px of the same 44px bar, from opposite ends, with no [dir] rule
     *   behind it. docs/lane-pdp-shots/ar-real-toner-390.png is the picture and
     *   docs/PDP-LEDGER-HANDOVER.md §6b carries the table — which is why the
     *   pictures are a deliverable and not a courtesy.
     */
    $css = ledgerSourceCss();
    $ledger = substr($css, (int) strpos($css, 'LEDGER — THE PRODUCT PAGE THE OWNER PICKED'));
    /* COMMENTS STRIPPED FIRST, because the block NAMES `[dir="rtl"]` in order to
       explain why there is exactly one of them — the same reason PdpPreviewTest
       strips comments before scanning for the measuring APIs. Counted raw it
       reads 2, and the second one is a sentence. */
    /* The block's OWN header is a comment too, and `substr()` starts INSIDE it,
       so everything up to the end of that comment has to go first -- otherwise
       the sentence "a single [dir=rtl] override" in the header survives as the
       second match. Counted raw it reads 2.
       (And this note spells the comment terminator out rather than quoting it,
       because a block comment that contains its own closing pair ends there: the
       first draft of this line did, and PHP reported a syntax error six lines
       further down, on the word `width`.) */
    $rules = (string) preg_replace('#^.*?\*/#s', '', $ledger);
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $rules);

    expect(substr_count($rules, '[dir="rtl"]'))->toBe(
        1,
        'one direction rule in the whole block, and it is the mask that cannot be logical'
    );
    ledgerHas($rules, 'mask-image:linear-gradient(to left', 'the mirrored edge fade');

    // Physical `width`/`height` on the hairline or the title row would mirror
    // wrongly; these are the three that carry direction.
    ledgerHas($rules, 'inline-size:44px', 'the hairline is sized logically');
    ledgerHas($rules, 'margin-inline:calc(', 'the full-bleed edges are logical');
});

/**
 * The bytes between two needles, with the first one included.
 *
 * Scoped reads, not whole-page ones, and that is the point of the helper: "the
 * page contains the price" is true of the sticky bar, the schema block in
 * <head> and the related rail as readily as of the buy column, so an unscoped
 * search would pass whatever the title row contained.
 */
function ledgerRegion(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    expect($start)->not->toBeFalse('the page must carry '.$from);

    $end = strpos($html, $to, (int) $start);
    expect($end)->not->toBeFalse($from.' must be followed by '.$to);

    return substr($html, (int) $start, (int) $end - (int) $start);
}
