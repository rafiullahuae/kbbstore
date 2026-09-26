<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\HeaderSettings;
use App\Services\ModuleSchema;
use App\Services\SiteLayout;
use App\Services\SettingsService;

/**
 * ONE SITE WIDTH, and the narrow measures kept narrow.                Lane W1
 *
 * ── THE DEFECTS ON THE SHOP, all of them measured before a line was changed ──
 *
 * 1. THERE WAS NO SITE WIDTH. A census of every `max-width:<n>px` across
 *    resources/views/{store,components,partials,layouts} and resources/css/kbb
 *    found 217 of them, of which 142 are MEDIA-QUERY BREAKPOINTS and 75 are
 *    element widths (29 distinct values). Fourteen of those 75 are page
 *    containers and they disagreed SIX ways: 1400 twice for `.wrap` in kbb.css
 *    with a DEAD 1200 for the same selector two thousand lines above it, 1352 on
 *    the home page's section card, 1240 on /shop, 1180 on a product page and on
 *    the brand pages, 1160 on the Journal and an article, 1080 on the review
 *    wall. No setting, no token, nothing in common.
 *
 * 2. SIX PRODUCT-GRID COLUMN SYSTEMS, ten media queries, three stylesheets, and
 *    they disagreed with each other AT THE SAME SCREEN WIDTH. Measured in real
 *    Chromium on a seeded shop: at a 1180px viewport the homepage rails showed
 *    THREE columns and /shop showed FOUR; at 834px the rails showed two and
 *    /shop showed three. Two of the six were in kbb.css itself — a full second
 *    copy of kbb-grid-skins.css lives in that sheet, with its own
 *    repeat(4,1fr) + 1100→3 + 900→2 ladder at the very END of the file, which is
 *    why it won on every page and why the first version of this lane's change
 *    was correct and invisible.
 *
 * 3. TWO OF THE THREE COLUMN SETTINGS ON Appearance → Product styles MOVED
 *    NOTHING. `--kbb-cols-t` ("Columns · tablet") and `--kbb-gap` are written
 *    only by ProductStyles::cssVariables(), which is called from exactly one
 *    place in this repo — resources/views/admin/app.blade.php — so the tablet
 *    slider, Gap between cards, Card roundness and Image shape have never
 *    reached the storefront. Not fixed here (that class is not this lane's
 *    file); recorded in docs/W1-SITE-WIDTH.md §"found, not fixed".
 *
 * ── WHAT THIS FILE PINS, AND WHY EACH ASSERTION IS THE ONE THAT CATCHES IT ──
 *
 * The rendered column count needs a layout engine, so it is pinned by
 * tests/browser/lane-w1-width-sweep.mjs and reported in docs/W1-SITE-WIDTH.md.
 * What Pest can pin — and what actually regresses — is the STRUCTURE: that
 * there is one declaration of the count, that every page container references
 * the token rather than a number, that the narrow measures were NOT swept into
 * it, and that the arithmetic the stylesheet performs agrees with the arithmetic
 * the admin preview performs. Each case below says which of those it is.
 */
function w1Css(string $name): string
{
    $path = base_path('resources/css/kbb/'.$name);

    expect(is_file($path))->toBeTrue("resources/css/kbb/{$name} is missing");

    return (string) file_get_contents($path);
}

/** A stylesheet with its comments removed, so a rule cannot be matched in prose. */
function w1CssRules(string $name): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', w1Css($name));
}

function w1View(string $path): string
{
    $file = base_path('resources/views/'.$path);

    expect(is_file($file))->toBeTrue("resources/views/{$path} is missing");

    return (string) file_get_contents($file);
}

/**
 * A view with its Blade and CSS comments removed, so a rule cannot be matched in
 * prose.
 *
 * ▲ THIS EXISTS BECAUSE A MUTATION RUN CAUGHT IT. The measures case below
 * asserted `toContain('article{max-width:720px')` against the raw file, and
 * store/post.blade.php's inline stylesheet now carries a COMMENT that quotes
 * `article{max-width:720px}` in order to explain why an article is not on the
 * site width. So the assertion matched the explanation: changing the real rule to
 * `var(--site-max)` left the test GREEN, which is the one outcome a mutation run
 * exists to find. PhoneShopperLayoutTest's header records the same trap from the
 * other direction — a bare class-name search matching an inline stylesheet — and
 * this is that trap with the lane's own comment as the decoy.
 */
function w1ViewRules(string $path): string
{
    return (string) preg_replace(
        ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s'],
        '',
        w1View($path)
    );
}

/* ══════════════════════════════════════════ one width, in one place ═══ */

it('declares the site width exactly once, as a token, at the value the owner asked for', function () {
    $root = w1CssRules('kbb.css');

    /*
     * DEFECT 1. `--site-max` is the only place the number lives, and it is
     * 1680px because the owner asked for it in as many words: "site width max i
     * need 1680 px". This is the ONE deliberate default change in the lane.
     *
     * MUTATION: change 1680 to 1400 here and this is red, and so is
     * SiteLayoutDefaultsMatchCssTest's agreement case — which is the point of
     * having both: the number cannot move in one place only.
     */
    expect(substr_count($root, '--site-max:'))->toBe(1);
    expect($root)->toContain('--site-max:1680px');
});

it('points every page container at the token instead of its own number', function () {
    /*
     * DEFECT 1, the half that can regress. Each of these carried a different
     * literal. A sheet that grows a fresh `max-width:1240px` on `.wrap` puts the
     * shop back to six widths, and the owner's slider stops reaching that page.
     *
     * MUTATION: put `max-width:1240px` back on kbb-shop.css's `.wrap` and the
     * first expectation below is red.
     */
    $containers = [
        ['kbb.css', '.wrap{max-width:var(--site-max)'],
        ['kbb.css', '.kbb-home .wrap{max-width:var(--site-max)'],
        ['kbb.css', 'max-width:var(--site-max);width:calc(100% - 24px)'],
        ['kbb-shop.css', '.wrap{max-width:var(--site-max)'],
        ['kbb-product.css', '.wrap{max-width:var(--site-max)'],
    ];

    foreach ($containers as [$sheet, $needle]) {
        expect(w1CssRules($sheet))->toContain($needle);
    }

    // The three page containers that live in a view rather than a sheet.
    expect(w1ViewRules('store/brands.blade.php'))->toContain('.brw{max-width:var(--site-max)');
    expect(w1ViewRules('store/blog.blade.php'))->toContain('.wrap{max-width:var(--site-max,1680px)');
    expect(w1ViewRules('store/post.blade.php'))->toContain('.wrap{max-width:var(--site-max,1680px)');
});

it('leaves the dead duplicate .wrap rule gone rather than leaving two', function () {
    /*
     * DEFECT 1's sharpest edge: kbb.css declared `.wrap{max-width:1200px}` at
     * line 14 and `.wrap{max-width:1400px}` two thousand lines below it. Same
     * selector, same specificity, later wins — so anyone who read either one
     * read a number the shop does not use, and the 1200 had presumably been
     * wrong for as long as both existed.
     *
     * MUTATION: add a second `.wrap{max-width:...}` anywhere in kbb.css and this
     * is red.
     */
    $rules = w1CssRules('kbb.css');

    /*
     * The UNSCOPED `.wrap`, which is the one that had two declarations.
     * `.kbb-home .wrap` and `header .wrap` are different selectors with
     * different jobs — the second is the header's own --hd-max — so the pattern
     * requires the selector to START at `.wrap`.
     */
    expect(preg_match_all('/(?:^|[}\n;])\s*\.wrap\{max-width:/m', $rules))->toBe(1);
});

/* ══════════════════════════════ the narrow measures stay narrow ═══ */

it('keeps the reading and form widths off the site width', function () {
    /*
     * THE POINT OF THE LANE, stated as the thing that must NOT have happened.
     * A 1680px paragraph is unreadable, and the owner asking for a wider SITE is
     * not asking for wider PROSE. Sixty-one of the 75 element widths are
     * measures; these are the ones a careless sweep would have caught.
     *
     * MUTATION: change `article{max-width:720px}` to `var(--site-max)` in
     * store/post.blade.php and this is red — which is exactly the regression
     * this case exists to make impossible to ship quietly.
     */
    expect(w1ViewRules('store/post.blade.php'))->toContain('article{max-width:720px');
    expect(w1ViewRules('store/skin-quiz.blade.php'))->toContain('.shell{position:relative;z-index:1;max-width:720px');
    expect(w1ViewRules('store/review-wall.blade.php'))->toContain('.page{max-width:1080px');

    // And the `ch` measures, which are measures by construction.
    expect(w1CssRules('kbb-banner.css'))->toContain('max-width:44ch');
});

it('leaves the cart, the checkout and the slim footer on their own width settings', function () {
    /*
     * Rule 1, as the thing that would have been worst to get wrong: all three
     * are pages asking for money, all three already have a Content width slider
     * on their own screen, and folding them in would have widened the checkout
     * to 1680px on every shop that applies the package. Nobody asked for that.
     *
     * MUTATION: point `.kbb-checkout .co-grid` at --site-max and this is red.
     */
    expect(w1CssRules('kbb-checkout.css'))->toContain('max-width:var(--cop-d-max,1040px)');
    expect(w1ViewRules('store/cart-squeeze.blade.php'))->toContain('max-width:var(--cpg-d-max,1200px)');
    expect(w1ViewRules('partials/slim-footer.blade.php'))->toContain('max-width:var(--sf-max)');

    // Their own keys, not this screen's.
    expect(array_keys(SiteLayout::SCHEMA))->not->toContain('cop_d_max');
    expect(array_keys(SiteLayout::SCHEMA))->not->toContain('cpg_d_max');
});

/* ═════════════════════════════════ one column count, derived ═══ */

it('declares the auto-fill track exactly once and on the grid rather than on :root', function () {
    /*
     * DEFECT 2, and a bug this lane wrote and then measured its way out of.
     *
     * The first version declared `--kbb-track: minmax(… var(--kbb-tile) …)` on
     * `:root`. A var() inside a custom property is substituted AT THE ELEMENT
     * WHERE THE PROPERTY IS DECLARED, and the resolved token stream is what
     * inherits — so :root's 260px was baked in and kbb-shop.css's
     * `#grid{--kbb-tile:220px}` could not reach it. Measured consequence: the
     * shop listing rendered THREE columns in a 1002px row where the arithmetic
     * said four, with --kbb-tile computing to 220px on that very element.
     *
     * MUTATION: move the minmax() back into a `--kbb-track:` declaration on
     * :root and this is red on both counts.
     */
    $rules = w1CssRules('kbb.css');

    expect($rules)->not->toContain('--kbb-track:');
    expect(substr_count($rules, 'grid-template-columns:repeat(auto-fill,minmax(min('))->toBe(1);
    expect($rules)->toContain('.kbb-pgrid,.rel,#grid{');

    /*
     * ▲ AND ALL THREE TERMS BY THEIR TEXT, which a mutation run is the reason
     * for. Counting the declaration was not enough: deleting the FLOOR term
     * outright left every case in this lane green, because the shape still
     * matched and the PHP copy in SiteLayoutColumnArithmeticTest tests its own
     * arithmetic rather than the sheet's. On the shop that deletion is one
     * full-width card per row on a 320px phone, where two have always been
     * shown.
     *
     * The bare `100%` is pinned too, and it is NOT redundant even though the
     * floor term is always smaller: it stops being smaller the moment the owner
     * sets "Never fewer than" to 1, and then `100%` is the only thing keeping a
     * container narrower than the tile from overflowing. A mutation run found
     * this one as well.
     *
     * MUTATION: delete any one of the four lines below from kbb.css and this is
     * red, naming which.
     */
    expect($rules)->toContain('minmax(min(
        100%,');
    expect($rules)->toContain('calc((100% - (var(--kbb-cols-floor) - 1) * var(--kbb-gap)) / var(--kbb-cols-floor))');
    expect($rules)->toContain('max(
            var(--kbb-tile),');
    expect($rules)->toContain('calc((100% - (var(--kbb-cols-cap) - 1) * var(--kbb-gap)) / var(--kbb-cols-cap))');
});

it('leaves no viewport breakpoint setting a product column count anywhere', function () {
    /*
     * DEFECT 2, as the thing that regresses: a new ladder. Ten media queries
     * across three sheets set a count before this, and the two in kbb.css were
     * found only by measuring the RENDERED count at seventeen widths — reading
     * the sheet that had been edited would not have found them.
     *
     * The search is over the product-grid selectors only. Other grids on the
     * shop (brand tiles, review cards, the UGC strip, the footer columns) still
     * use fixed counts, and that is fine: the owner asked about the product
     * grid, and a four-up row of trust badges is not a listing.
     *
     * MUTATION: add `@media(max-width:1180px){.kbb-pgrid{grid-template-columns:
     * repeat(3,1fr)}}` to any of the three sheets and this is red.
     */
    foreach (['kbb.css', 'kbb-grid-skins.css', 'kbb-shop.css', 'kbb-product.css'] as $sheet) {
        $rules = w1CssRules($sheet);

        expect($rules)->not->toMatch(
            '/(\.kbb-pgrid|\.rel|#grid|\.grid\[data-cols\])(?![ >\w.\[])\{[^}]*grid-template-columns:repeat\(\d/',
            "{$sheet} sets a fixed product column count"
        );
    }
});

it('pins an exact count with a rule, never with a custom property', function () {
    /*
     * A BUG THIS LANE SHIPPED INTO ITS OWN FIRST DRAFT, recorded because it
     * looks harmless. The pin was `--kbb-count:4` plus
     * `--kbb-track:minmax(0,1fr)`, and the phone breakpoint undid it with
     * `--kbb-track:initial`. On a CUSTOM property `initial` is the
     * guaranteed-invalid value, not "revert to the inherited declaration" — so
     * `repeat(auto-fill, var(--kbb-track))` substituted nothing, became invalid
     * at computed-value time, and the whole declaration fell back to
     * `grid-template-columns:none`: every product card in one full-width column,
     * on every phone.
     *
     * So a pin is a real rule inside a real media query, and nothing has to be
     * undone below it.
     *
     * MUTATION: change SiteLayout::css()'s pin to emit `--kbb-count:` and the
     * second expectation is red.
     */
    $shop = w1CssRules('kbb-shop.css');

    /*
     * The pin and its media query TOGETHER, as one string. Asserting the query
     * on its own proved nothing: kbb-shop.css already carries a second
     * `@media(min-width:901px)` block further down, so widening THIS one to
     * `min-width:1px` left the case green — and on the shop that means a
     * shopper's desktop choice of four columns applying to a 390px phone, 88px a
     * card. A mutation run found it.
     */
    expect($shop)->toContain('@media(min-width:901px){
  #grid[data-cols="2"]{grid-template-columns:repeat(2,minmax(0,1fr))}
  #grid[data-cols="3"]{grid-template-columns:repeat(3,minmax(0,1fr))}
  #grid[data-cols="4"]{grid-template-columns:repeat(4,minmax(0,1fr))}
}');
    expect($shop)->not->toContain('--kbb-count');

    app(SettingsService::class)->set('layout_pin', '6');

    $css = app(SiteLayout::class)->css();

    expect($css)->toContain('@media(min-width:901px){.kbb-pgrid,.rel{grid-template-columns:repeat(6,minmax(0,1fr))}}');
    expect($css)->not->toContain('--kbb-count');
});

it('divides the row by the same gap the browser lays out with, on every grid', function () {
    /*
     * A DEFECT WORTH TWO PIXELS OF HORIZONTAL PAGE SCROLL ON THE NARROWEST
     * PHONE, and the reason every surface sets --kbb-gap rather than `gap`.
     *
     * The track's floor term is `(100% - (floor - 1) * var(--kbb-gap)) / floor`.
     * Declare 18px as a plain `gap` on `#grid` and the guarantee is computed
     * with 16 while the browser lays out with 18: at 320px that is two 138px
     * tracks plus an 18px gap in a 292px row — 2px over, from a disagreement
     * between two numbers that do not look related.
     *
     * MUTATION: change `#grid{--kbb-gap:18px}` to `#grid{gap:18px}` and this is
     * red.
     */
    expect(w1CssRules('kbb-shop.css'))->toContain('#grid{--kbb-gap:18px');
    expect(w1CssRules('kbb-product.css'))->toContain('.rel{--kbb-gap:18px}');
    expect(w1CssRules('kbb.css'))->toContain('gap:var(--kbb-gap);');

    // And no surface re-declares a plain product-grid gap beside it.
    foreach (['kbb-grid-skins.css', 'kbb-shop.css', 'kbb-product.css'] as $sheet) {
        expect(w1CssRules($sheet))->not->toMatch(
            '/(\.kbb-pgrid|\.rel|#grid)(?![ >\w.\[])\{[^}]*(?<!-)gap:\d/',
            "{$sheet} declares a plain gap on a product grid"
        );
    }
});

it('fixes the product page overflow that made every phone scroll sideways', function () {
    /*
     * A REAL DEFECT ON THE SHOP, pre-existing and made 2px worse by this lane.
     *
     * At 320px every product page scrolled sideways:
     * `document.documentElement.scrollWidth` measured 328 against a clientWidth
     * of 320 on the pre-change tree. `.pdp` is one column below 880px, and a
     * `1fr` track is `minmax(auto, 1fr)` whose `auto` minimum cannot go below
     * its content's min-content width — 308px here — so the grid refused to be
     * narrower than 308 in a 280px box and dragged the page with it. The side
     * gutter moving from this sheet's 20px to the shared 22px narrowed the box
     * and took the overrun to 10px, which is what made it this lane's to fix.
     *
     * `minmax(0,1fr)` measured 320 against 320 afterwards, at 320, 360 and 390.
     *
     * MUTATION: put `1fr` back and lane-w1-width-sweep.mjs reports +10 at 320 on
     * /product/*; this case is red immediately.
     */
    expect(w1CssRules('kbb-product.css'))
        ->toContain('@media(max-width:880px){.pdp{grid-template-columns:minmax(0,1fr);gap:26px}}');
});

it('leaves /shop automatic until the shopper picks a column count', function () {
    /*
     * THE DEFECT ON THE SHOP, and the half of the owner's sentence this serves.
     *
     * `Facets::columns()` answers '4' whether or not `?cols` is in the URL, and
     * `data-cols` is a PIN (kbb-shop.css turns it into
     * `grid-template-columns:repeat(N,minmax(0,1fr))` above 900px). So the
     * listing was pinned at four columns for every visitor at every screen size
     * — measured: four at 1280, four at 1680, four at 2560 — while the owner had
     * asked for "on 1680px the grid products will show 1 column extra". The
     * default and the only setting were indistinguishable, and the default won.
     *
     * The attribute is emitted only when the shopper has chosen, and the button
     * highlight follows the same fact, because a highlighted "4" above a
     * five-column grid is the control lying about the page.
     *
     * A MUTATION RUN IS WHY THIS CASE EXISTS. Putting `data-cols="{{ $cols }}"`
     * back unconditionally left every structural case in this lane green; only
     * StorefrontEnglishUnchangedTest caught it, and a behaviour that is pinned
     * only by a byte comparison is a behaviour nobody reading this file can see.
     *
     * MUTATION: drop the `@if ($colsChosen)` guard and the first two
     * expectations are red.
     */
    foreach (range(1, 3) as $n) {
        Product::create([
            'slug' => 'w1-tile-'.$n.'-'.uniqid(),
            'name' => 'W1 tile '.$n,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 4500,
            'stock_status' => 'instock',
        ]);
    }

    $auto = $this->get('/shop/')->assertOk()->getContent();

    expect($auto)->toContain('<div class="grid" id="grid">');
    expect($auto)->not->toContain('data-cols');

    // And no button claims to be the current choice.
    expect($auto)->not->toContain('data-c="4" class="on"');

    $picked = $this->get('/shop/?cols=3')->assertOk()->getContent();

    expect($picked)->toContain('id="grid" data-cols="3"');
    expect($picked)->toContain('data-c="3" class="on"');

    // A value outside the allowlist is not a choice, so the grid stays automatic.
    $bogus = $this->get('/shop/?cols=99')->assertOk()->getContent();

    expect($bogus)->not->toContain('data-cols');
});

it('bounds a column count a caller asks for, however absurd', function () {
    /*
     * RULE 5, on the one number in this system that comes from OUTSIDE the
     * settings screen. `[kbb_products columns="3"]` is somebody writing 3 into a
     * page, so the component honours it — as a pin, which means it reaches
     * `grid-template-columns:repeat(N,…)` in a <style> element. An unbounded N is
     * a shortcode that can emit `repeat(999999,minmax(0,1fr))`, which is a page
     * that stops laying out.
     *
     * A MUTATION RUN IS WHY THIS CASE EXISTS: removing the clamp left every other
     * case in this lane green.
     *
     * MUTATION: change `max(1, min(8, (int) $columns))` to `(int) $columns` in
     * components/product-grid.blade.php and this is red.
     */
    $product = Product::create([
        'slug' => 'w1-pin-'.uniqid(),
        'name' => 'W1 pin',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 4500,
        'stock_status' => 'instock',
    ]);

    $products = Product::query()->with(['brand:id,name,slug', 'categories:id,name'])
        ->whereKey($product->id)->get();

    $render = function (array $props) use ($products) {
        return view('components.product-grid', array_merge([
            'products' => $products,
            'skin' => null, 'columns' => null, 'columnsMobile' => null,
            'heading' => null, 'subheading' => null, 'moreUrl' => null, 'moreLabel' => null,
        ], $props))->render();
    };

    foreach ([['999999', 8], ['0', null], ['-4', null], ['3', 3]] as [$asked, $expected]) {
        $html = $render(['columns' => $asked]);

        if ($expected === null) {
            // Nothing was asked for that the component can honour, so no pin.
            expect($html)->not->toContain('grid-template-columns:repeat(');

            continue;
        }

        expect($html)->toContain('grid-template-columns:repeat('.$expected.',minmax(0,1fr))');
    }

    /*
     * THE PHONE COUNT IS BOUNDED AT TWO, separately, and a mutation run is why
     * it is asserted separately: removing its clamp left every other case green.
     * Two is the most a 390px screen holds comfortably — the note that has been
     * in kbb-grid-skins.css since the ladder was written, and the reason
     * --kbb-cols-floor exists.
     */
    foreach ([['9', 2], ['1', 1], ['0', null], ['-2', null]] as [$asked, $expected]) {
        $html = $render(['columnsMobile' => $asked]);

        if ($expected === null) {
            expect($html)->not->toContain('grid-template-columns:repeat(');

            continue;
        }

        expect($html)->toContain('grid-template-columns:repeat('.$expected.',minmax(0,1fr))');
    }

    /*
     * AND A CALLER'S DESKTOP COUNT IS SPLIT AT 900px, which is the same split
     * every other pin in this system takes. Without it, `[kbb_products
     * columns="5"]` puts five cards across a 390px phone — 62px each, narrower
     * than the price line. A mutation run found this one too: widening the query
     * to `min-width:1px` left every other case green.
     */
    $desktopPin = $render(['columns' => '5']);

    expect($desktopPin)->toContain('@media(min-width:901px){');

    $phonePin = $render(['columnsMobile' => '1']);

    expect($phonePin)->toContain('@media(max-width:900px){');

    /*
     * The two are scoped by an id, so one grid on a page can be pinned without
     * pinning the rest — a shortcode is not the only grid on the page it sits on.
     */
    expect($desktopPin)->toMatch('/<style>@media\(min-width:901px\)\{#kbbg[0-9a-f]{8}\{/');
});

/* ══════════════════════════════ the schema, and rule 1 ═══ */

it('ships every setting at the value the page already had, except the ones the owner asked for', function () {
    /*
     * RULE 1, as an assertion rather than a promise. Applying the package must
     * move nothing until a slider moves — and the exceptions are the settings
     * the owner asked for in as many words, which are called out in the commit
     * and in the migration rather than buried.
     *
     * The numbers on the right are the MEASURED pre-change values: 22px is
     * kbb.css's generic `.wrap` padding, 16px is the gap `.kbb-pgrid` computed
     * at every one of 320/390/600/900/1280/1680, and 2 is what a phone showed.
     *
     * ▲ `header_follows` IS NOW THE SECOND EXCEPTION AND SHIPS ON.       Lane H1
     *
     * It shipped OFF here, on the reasoning that the header keeping its own
     * 1280px --hd-max was the value the page already had. That was right about
     * rule 1 and wrong about the shop: the owner's next sentence was "the header
     * need to be matched the width", which is this switch in his own words, so
     * it now ships at the value he asked for. The same paragraph of his message
     * is why `max` is 1680.
     *
     * (And while it was off, nobody found that it did not work. The declaration
     * it wrote into `:root` was beaten by the one HeaderSettings writes into the
     * `<header>` style attribute, so turning it on moved nothing at any width —
     * see the case further down this file and DesktopHeaderWidthTest.)
     *
     * MUTATION: change `gutter` to 24 and this is red.
     */
    $fields = ModuleSchema::normalised('site_layout_test_defaults', SiteLayout::SCHEMA, SiteLayout::POLICY, SiteLayout::overrides());

    $expected = [
        'max' => 1680,             // <- the one deliberate change
        'gutter' => 22,
        'gutter_wide' => 22,
        'header_follows' => true,  // <- the second deliberate change (Lane H1)
        'tile' => 260,
        'tile_shop' => 220,
        'cols_floor' => 2,
        'cols_cap' => 8,
        'gap' => 16,
        'pin' => 'auto',
    ];

    expect(array_keys($fields))->toEqual(array_keys($expected));

    foreach ($expected as $key => $value) {
        expect($fields[$key]['default'])->toBe($value, "default for {$key}");
    }
});

it('sends no stylesheet at all while nothing has been moved', function () {
    /*
     * RULE 1's other half, and the reason cssVariables() returns '' rather than
     * a block of defaults. A style block restating them would be right in pixels
     * and wrong in bytes: a new <style> element on forty storefront pages at
     * once, which is what StorefrontEnglishUnchangedTest pins, for a change that
     * renders identically.
     *
     * MUTATION: delete the isDefault() early return in cssVariables() and this
     * is red, and so is StorefrontEnglishUnchangedTest on every page.
     */
    $layout = app(SiteLayout::class);

    expect($layout->isDefault())->toBeTrue();
    expect($layout->cssVariables())->toBe('');
    expect($layout->css())->toBe('');

    // One moved slider, and only then does anything appear.
    app(SettingsService::class)->set('layout_max', '1440');
    SettingsService::forgetMemo();

    $layout = app(SiteLayout::class);

    expect($layout->isDefault())->toBeFalse();
    expect($layout->css())->toStartWith(':root{');
    expect($layout->css())->toContain('--site-max:1440px');
});

it('reads back every value it stored, under this screen\'s own policy and prefix', function () {
    /*
     * A BUG THE FIRST DRAFT OF SiteLayout HAD, and the quietest kind there is.
     * all() was ModuleSchema::read(), which takes no $policy and no $overrides —
     * it calls normalise($schema) bare — so it looked up `max` in the settings
     * table instead of `layout_max` and cast every field under DEFAULT_POLICY
     * instead of this screen's. It read back nine SHIPPED DEFAULTS on a shop
     * that had saved nine values: the screen would have shown 1680 for as long
     * as anybody looked, whatever had been saved.
     *
     * MUTATION: put `ModuleSchema::read($this->settings, 'site_layout',
     * self::SCHEMA)` back in all() and this is red on the first field.
     */
    $layout = app(SiteLayout::class);

    $written = [
        'max' => 2000, 'gutter' => 30, 'gutter_wide' => 48, 'header_follows' => true,
        'tile' => 300, 'tile_shop' => 240, 'cols_floor' => 1, 'cols_cap' => 6,
        'gap' => 24, 'pin' => '5',
    ];

    $result = $layout->save($written);

    expect($result['rejected'])->toBe([]);
    expect($result['written'])->toHaveCount(count($written));

    SettingsService::forgetMemo();

    $read = app(SiteLayout::class)->all();

    foreach ($written as $key => $value) {
        expect($read[$key])->toBe($value, "round trip for {$key}");
    }

    // And the prefix really is where they went.
    expect(app(SettingsService::class)->get('layout_max'))->not->toBeNull();
    expect(app(SettingsService::class)->get('max'))->toBeNull();
});

it('clamps a number outside its slider and refuses a select that is not one of its options', function () {
    /*
     * RULE 5 on a screen made of numbers. A slider cannot emit an out-of-range
     * value, so a POST that does is a mistake or an attack; `clamp` pulls it to
     * the bound. A `select` is refused outright and REPORTED, because "Site
     * width: 1680" reading back after a failed save is the screen lying about
     * the shop.
     *
     * MUTATION: change POLICY's `invalid` to 'default' and the pin case below
     * is red — the bad value would be silently swapped for 'auto' instead of
     * refused.
     */
    $layout = app(SiteLayout::class);

    $layout->save(['max' => 99999, 'gutter' => -40, 'cols_cap' => 400]);
    SettingsService::forgetMemo();

    $read = app(SiteLayout::class)->all();

    expect($read['max'])->toBe(2400);     // the slider's own max
    expect($read['gutter'])->toBe(8);     // the slider's own min
    expect($read['cols_cap'])->toBe(8);

    $refused = app(SiteLayout::class)->save(['pin' => 'javascript:alert(1)']);

    expect(array_keys($refused['rejected']))->toBe(['pin']);
    expect($refused['written'])->toBe([]);
});

it('makes the header FOLLOW the site width rather than copying its number', function () {
    /*
     * THE ONE WIDTH ON THIS SCREEN THAT ANOTHER SCREEN ALREADY OWNS.
     * Appearance → Header's `max_width` writes --hd-max, and its slider stops at
     * 1600px — below the 1680 asked for here — so a shop that wants one width
     * everywhere cannot say so there. This switch says "use the page width".
     *
     * IT MUST EMIT THE TOKEN, NOT THE NUMBER. Writing `--hd-max:1680px` looks
     * identical the day it is saved and freezes: the owner moves Site width to
     * 1800 and the header silently stays at 1680, which is the shop disagreeing
     * with its own setting and no error anywhere. A mutation run found that the
     * literal passed every other case in this lane. That half is unchanged and
     * is still the first assertion below.
     *
     * ▲ WHAT THIS CASE USED TO ASK, AND WHY IT WAS GREEN ON A DEAD SWITCH.
     *                                                                   Lane H1
     * It read SiteLayout::css() and found `--hd-max:var(--site-max)` in the
     * `:root` block, which is exactly what that method emitted — and the
     * declaration never reached anything. HeaderSettings::cssVariables() writes
     * the same property into the `style` attribute of the `<header>` ELEMENT, on
     * every request, saved or not; an inline declaration beats a `:root` one
     * outright, and `header .wrap` is a child of `<header>`, so it inherited the
     * inline value. Measured in Chromium on /shop/ with the switch saved on:
     * header .wrap 1280 at a 1280px viewport, 1280 at 1680, 1280 at 1920, while
     * the page container went to 1680.
     *
     * A unit test that reads the emitter can only ever ask whether the emitter
     * emitted. So this case now asks the question the shop asks: what does the
     * element that carries the property actually say. DesktopHeaderWidthTest
     * asks it again of a rendered page, which is the other half.
     *
     * MUTATION 1: change the emission to `'--hd-max:1680px'` and this is red.
     * MUTATION 2: emit it from SiteLayout::cssVariables() again instead of from
     * HeaderSettings, which is what it did before, and this is red — where the
     * old version of this case was green. RUN AND CONFIRMED.
     */
    $settings = app(SettingsService::class);

    // On by default now, and a default shop still sends no stylesheet at all —
    // so the follow cannot be riding on one.
    expect(app(SiteLayout::class)->css())->toBe('');

    $header = app(HeaderSettings::class)->cssVariables();

    expect($header)->toContain('--hd-max:var(--site-max)');
    expect($header)->not->toMatch('/--hd-max:\s*\d/');

    // And off again gives the header back its own number, from its own screen.
    $settings->set('layout_header_follows', '0');
    SettingsService::forgetMemo();

    expect(app(HeaderSettings::class)->cssVariables())->toContain('--hd-max:1280px');

    // The property has one writer. SiteLayout emitting it too is the dead
    // declaration this lane removed, and it is dead in either direction.
    $settings->set('layout_max', '1600');
    SettingsService::forgetMemo();

    // str_contains(), not ->not->toContain($needle, $message): toContain takes
    // a LIST of needles, so a message passed there becomes a second needle and
    // the expectation can no longer fail. ExpectationsThatCannotFailTest catches
    // exactly that, and caught this line.
    expect(str_contains(app(SiteLayout::class)->css(), '--hd-max'))
        ->toBeFalse('SiteLayout is writing --hd-max again, where the header\'s own inline style beats it');

    // And the header's own container really is the property being read.
    expect(w1CssRules('kbb.css'))->toContain('header .wrap{max-width:var(--hd-max)}');
});

it('never lets a stored value reach a property name, a selector or a unit', function () {
    /*
     * RULE 5, pointed at the stylesheet rather than at the value. Everything
     * this class prints unescaped is a literal in it; the only thing a save can
     * influence is an integer. The strongest available check is that a hostile
     * value cannot appear in the output at all.
     *
     * MUTATION: interpolate a setting into the property name in PX_VARS and this
     * is red.
     */
    $layout = app(SiteLayout::class);

    $layout->save([
        'max' => '1680;--x:url(javascript:alert(1))',
        'gap' => '</style><script>alert(1)</script>',
        'pin' => '4;}*{display:none}',
    ]);
    SettingsService::forgetMemo();

    $css = app(SiteLayout::class)->css();

    expect($css)->not->toContain('script');
    expect($css)->not->toContain('javascript');
    expect($css)->not->toContain('url(');
    expect($css)->not->toContain('display:none');
});
