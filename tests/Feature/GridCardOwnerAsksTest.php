<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ProductStyles;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The owner's four asks of 2 October 2026 about the product grid card.  (Lane PR)
 *
 *   1. "Turn off by default on the product grid card, new and discount tag."
 *   2. "Remove pagination from the categories and brands; it should load more
 *       products via scroll with grey loading stuff ... keep this on by
 *       default."
 *   3. "Remove grid hover from products in mobile ... turn off by default on
 *       mobile devices only."
 *   4. "I need the full control of grid card spacing like between image,
 *       title, pricing row, add to cart, and also control of font bold etc."
 *
 * 1–3 MOVE THE SHOP, because he asked for each (CLAUDE.md rule 1, 30 Sept);
 * every one of them still has its control. 4 adds controls whose defaults are
 * today's measured values and which emit nothing until moved.
 *
 * Every test names the defect it would catch and the mutation that was RUN to
 * see it go red. Screenshots and numbers: docs/lane-pr-shots/.
 */

/** A category and a brand with $n products, every one NEW and on sale. */
function prSeed(int $n = 30): array
{
    $brand = Brand::firstOrCreate(['slug' => 'pr-brand'], ['name' => 'PR Brand']);
    $cat = Category::firstOrCreate(['slug' => 'pr-sets'], ['name' => 'PR Sets']);

    for ($i = 1; $i <= $n; $i++) {
        $p = Product::create([
            'slug' => 'pr-p'.$i,
            'name' => sprintf('PR Product %03d', $i),
            'status' => 'publish', 'is_visible' => true, 'type' => 'simple',
            'brand_id' => $brand->id,
            'price' => 10000, 'sale_price' => 7000,     // -30%
            'stock_status' => 'instock',
            'rating' => 4.0, 'review_count' => 2,
            // created_at is "now": every one of these is inside the 30-day NEW window.
        ]);
        $p->categories()->syncWithoutDetaching([$cat->id]);
    }

    return [$cat, $brand];
}

function prFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetScopedInstances();
}

function prStyles(array $values): void
{
    app(ProductStyles::class)->save($values);
    prFresh();
}

function prGet(string $uri): string
{
    prFresh();

    return (string) test()->get($uri)->assertOk()->getContent();
}

function prCatPath(Category $cat): string
{
    return '/'.ltrim((string) parse_url($cat->url(), PHP_URL_PATH), '/');
}

/* ═══════════════════ 1 · the NEW and -N% pills ═══════════════════ */

it('draws no NEW and no -N% pill on any listing by default', function () {
    /*
     * THE DEFECT, MEASURED BEFORE: on the shop's own preview the BEFORE shots
     * counted 6 NEW and 8 -N% pills on /shop/ at 390 and 1280, and the switches
     * that were meant to hide them (`pc-nonew`, `pc-nodisc`) are rules in
     * kbb-grid-skins.css, which /shop/, a category and a brand page never load.
     *
     * MUTATION, RUN: put `'show_new' => ['bool', 'New badge', true, …]` back
     * in ProductStyles::SCHEMA and this is red on `kbb-badge-new` — on /shop/,
     * the page the CSS switch could never reach. Same for `show_discount` and
     * `kbb-badge-sale`. Dropping the `$kbbShows['show_new']` gate in
     * components/product-card.blade.php is red the same way.
     */
    [$cat, $brand] = prSeed(5);

    foreach (['/shop/', prCatPath($cat), '/brands/pr-brand/'] as $uri) {
        $html = prGet($uri);

        expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBeGreaterThan(0, "{$uri} drew no cards")
            ->and($html)->not->toContain('kbb-badge-new', "{$uri} still draws the NEW pill")
            ->and($html)->not->toContain('kbb-badge-sale', "{$uri} still draws the -N% pill")
            // The markdown itself is still shown, by the struck-through price.
            ->and($html)->toContain('kbb-card-reg');
    }
});

it('brings both pills back on every listing when the switches are turned on', function () {
    /*
     * The control has to still WORK, on the pages the old CSS switch never
     * reached. MUTATION, RUN: gate `$kbbEnd` on `! $kbbShows['show_discount']`
     * (inverted) and this is red on `kbb-badge-sale">-30%`.
     */
    [$cat] = prSeed(3);
    prStyles(['show_new' => true, 'show_discount' => true]);

    foreach (['/shop/', prCatPath($cat), '/brands/pr-brand/'] as $uri) {
        $html = prGet($uri);

        expect($html)->toContain('<span class="kbb-badge kbb-badge-new">')
            ->and($html)->toContain('<span class="kbb-badge kbb-badge-sale">-30%</span>');
    }
});

it('no longer puts pc-nonew or pc-nodisc on <body>, which would hide the rank pills too', function () {
    /*
     * `#1`, `#2` on the bestsellers rail wear `.kbb-badge-new` as well, and
     * `.pc-nonew .kbb-badge-new{display:none}` is a live rule on the homepage.
     * With the default now OFF, emitting the class would have blanked every
     * rank pill on the home page — a thing the owner did not ask about.
     *
     * MUTATION, RUN: restore `$c['show_new'] ? '' : 'pc-nonew'` in
     * ProductStyles::bodyClass() and this is red.
     */
    $class = app(ProductStyles::class)->bodyClass();

    expect($class)->not->toContain('pc-nonew')
        ->and($class)->not->toContain('pc-nodisc')
        ->and($class)->toBe('pc-nobrand pc-nocat');   // exactly what it was before this lane
});

/* ═══════════════ 2 · more products on scroll, category AND brand ═══════════════ */

it('ships "Load more on scroll" as the default, because he asked for it', function () {
    /*
     * MUTATION, RUN: `load_mode` default back to 'arrows' and this is red on
     * the mode, on the page size (24 instead of one batch of 12) and on
     * data-load="scroll".
     */
    expect(SiteLayout::SCHEMA['load_mode'][2])->toBe('scroll');

    [$cat] = prSeed(30);
    $html = prGet(prCatPath($cat));

    expect(app(SiteLayout::class)->loadMode())->toBe('scroll')
        ->and(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(12)
        ->and($html)->toContain('data-load="scroll"')
        ->and($html)->toContain('data-batch="12"')
        // No JavaScript: the next page is still an ordinary link.
        ->and($html)->toMatch('#<a class="page-numbers next" rel="next" href="[^"]*\?paged=2"#');
});

it('wires a brand page to the loader: one batch, a pager naming its grid, and the next batch', function () {
    /*
     * THE DEFECT, MEASURED BEFORE: /brands/{slug}/ drew a fixed twelve with no
     * pager at all, so a brand with thirty products showed twelve and the rest
     * were reachable only through "Shop all". docs/lane-pr-shots/before:
     * brands-cosrx-390 — 12 tiles, pager null.
     *
     * MUTATION, RUN: delete the @include('partials.listing-pager', …) from
     * store/brands.blade.php and this is red on the pager; put back
     * `->limit(self::PREVIEW_LIMIT)` in BrandController::show() and the batch
     * request answers a whole HTML page instead of JSON.
     */
    prSeed(30);

    $html = prGet('/brands/pr-brand/');

    expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(12)
        ->and(substr_count($html, 'id="brandGrid"'))->toBe(1)
        ->and($html)->toContain('<nav class="kbb-pager" aria-label="Pages" data-load="scroll" data-grid="#brandGrid" data-batch="12">')
        ->and($html)->toContain('rel="next" href="/brands/pr-brand/?paged=2"');

    prFresh();
    $batch = test()->get('/brands/pr-brand/?paged=2&kbbbatch=1')->assertOk();

    // The same five allowlisted keys /shop/ answers with — nothing else leaves.
    expect(array_keys($batch->json()))->toEqualCanonicalizing(['html', 'page', 'last', 'next', 'url'])
        ->and($batch->json('page'))->toBe(2)
        ->and($batch->json('next'))->toBe('/brands/pr-brand/?paged=3')
        ->and($batch->json('url'))->toBe('/brands/pr-brand/?paged=2')
        ->and(substr_count($batch->json('html'), 'class="kbb-card kbb-tile"'))->toBe(12)
        ->and($batch->json('html'))->not->toContain('<html');

    prFresh();
    $last = test()->get('/brands/pr-brand/?paged=3&kbbbatch=1')->assertOk();
    expect(substr_count($last->json('html'), 'class="kbb-card kbb-tile"'))->toBe(6)
        ->and($last->json('next'))->toBeNull();
});

it('walks every product of a brand exactly once, page by page', function () {
    /*
     * The look-ahead row (limit perPage + 1) is the whole mechanism, so an
     * off-by-one there either drops a product at every seam or draws one
     * twice. MUTATION, RUN: `->limit($perPage)` instead of `+ 1` and this is
     * red — no page ever has a next one, so page 1 stops at twelve of thirty.
     */
    prSeed(30);
    $seen = [];

    for ($page = 1; $page <= 3; $page++) {
        prFresh();
        $json = test()->get('/brands/pr-brand/?paged='.$page.'&kbbbatch=1')->assertOk()->json();
        preg_match_all('#href="(/product/pr-p\d+/)"#', $json['html'], $m);
        $seen = array_merge($seen, array_values(array_unique($m[1])));
    }

    expect($seen)->toHaveCount(30)
        ->and(array_unique($seen))->toHaveCount(30);
});

it('404s a brand page past the end and canonicalises page 2 to itself', function () {
    /*
     * The same rule /shop/?paged=4000 already follows: an unbounded supply of
     * self-canonical duplicates of page one is crawl budget taken off product
     * pages. MUTATION, RUN: drop the abort(404) and ?paged=9 answers 200.
     */
    prSeed(30);

    prFresh();
    test()->get('/brands/pr-brand/?paged=9')->assertNotFound();

    $two = prGet('/brands/pr-brand/?paged=2');
    expect($two)->toMatch('#<link rel="canonical" href="[^"]*/brands/pr-brand/\?paged=2">#');

    $one = prGet('/brands/pr-brand/');
    expect($one)->toMatch('#<link rel="canonical" href="[^"]*/brands/pr-brand/">#');
});

it('keeps a brand that fits on one page exactly as it was: no pager at all', function () {
    /*
     * Twelve or fewer products is the one case the old page got right, and it
     * must not grow an empty pager. MUTATION, RUN: hard-code `$lastPage = 2`
     * in BrandController::show() and this is red.
     */
    prSeed(5);

    $html = prGet('/brands/pr-brand/');

    expect($html)->not->toContain('kbb-pager')
        ->and(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(5);
});

it('still honours Arrows and Load all on a brand page', function () {
    /*
     * The setting is one setting for every listing. MUTATION, RUN: replace
     * `perPage(self::PREVIEW_LIMIT)` with the literal 12 in
     * BrandController::show() and "Load all" is red at 12 of 30.
     */
    prSeed(30);

    app(SiteLayout::class)->save(['load_mode' => 'arrows']);
    $arrows = prGet('/brands/pr-brand/');
    expect($arrows)->toContain('data-load="arrows"')
        ->and($arrows)->not->toContain('data-batch=')
        ->and(substr_count($arrows, 'class="kbb-card kbb-tile"'))->toBe(12);

    app(SiteLayout::class)->save(['load_mode' => 'all']);
    $all = prGet('/brands/pr-brand/');
    expect(substr_count($all, 'class="kbb-card kbb-tile"'))->toBe(30)
        ->and($all)->not->toContain('kbb-pager');
});

it('adds no query to a brand page for the pager', function () {
    /*
     * A COUNT(*) is what a pager usually costs, and StorefrontQueryBudgetTest
     * is a budget. One extra ROW stands in for it. MUTATION, RUN: replace the
     * look-ahead with `$total = (clone $query)->count()` and this is red by one.
     */
    prSeed(30);
    prFresh();
    test()->get('/brands/pr-brand/')->assertOk();   // warm every cache the page fills once

    prFresh();
    DB::enableQueryLog();
    test()->get('/brands/pr-brand/')->assertOk();
    $paged = count(DB::getQueryLog());
    DB::flushQueryLog();

    app(SiteLayout::class)->save(['load_mode' => 'all']);
    prFresh();
    test()->get('/brands/pr-brand/')->assertOk();
    prFresh();
    DB::flushQueryLog();
    test()->get('/brands/pr-brand/')->assertOk();
    $whole = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Page one of a paged brand costs what the whole brand on one page costs.
    expect($paged)->toBe($whole);
});

/* ═══════════════════ 3 · hover on a phone ═══════════════════ */

it('neutralises every card hover rule on a phone unless the owner opts back in', function () {
    /*
     * THE DEFECT, MEASURED BEFORE (tools/pr-shots.cjs, 390 with touch): a tap
     * left box-shadow `0 14px 34px rgba(42,34,40,.12)` and the photograph at
     * `scale(1.06)`. AFTER: nothing moves on any of the 32 skins
     * (tools/pr-hover-skins.sh), and at 1280 every skin still has its hover.
     *
     * This pins the block and its gate. MUTATION, RUN: change the query to
     * `(max-width:700px)` alone and this is red — a tablet in a browser at
     * 1024 is a touch screen with no pointer, and it would keep the sticky
     * hover. Delete the `body:not(.pc-phonehover)` prefix from any rule and
     * the "every rule is gated" check is red.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $at = strpos($rules, '@media (hover:none),(max-width:700px){');

    expect($at)->not->toBeFalse('the phone-hover block is gone from kbb.css');

    // The block's body, to its matching brace.
    $depth = 0;
    $body = '';
    for ($i = strpos($rules, '{', (int) $at); $i < strlen($rules); $i++) {
        $ch = $rules[$i];
        $depth += $ch === '{' ? 1 : ($ch === '}' ? -1 : 0);
        $body .= $ch;
        if ($depth === 0) {
            break;
        }
    }

    preg_match_all('#([^{}]+)\{[^{}]*\}#', substr($body, 1, -1), $m);
    $selectors = array_map('trim', explode(',', implode(',', $m[1])));

    expect(count($selectors))->toBeGreaterThan(20);

    foreach ($selectors as $sel) {
        expect($sel)->toStartWith('body:not(.pc-phonehover) ', "{$sel} is not gated by the owner's switch");
    }

    /*
     * AND NO HOVER RULE IS LEFT OUT. Every `.kbb-card:hover` rule in both
     * copies of the skins must be named here, or — for the three that are
     * functions rather than effects — be on the list of deliberate exceptions.
     * A lane that adds a hover effect to a skin goes red here and is asked to
     * add its resting value to the phone block.
     */
    $exceptions = [
        '.kbb-pgrid[data-skin="actions"] .kbb-card:hover .kbb-card-cart',   // its only way to the button
        '.kbb-pgrid[data-skin="reveal"] .kbb-card:hover .cb',               // already (hover:hover)
        '.kbb-pgrid[data-skin="overlay"] .kbb-card:hover .kbb-card-cart',   // already (hover:hover)
        '.kbb-pgrid[data-skin="showcase-compact"] .kbb-card:hover',         // its hover is `none`
    ];
    $gated = implode("\n", $selectors);

    foreach (['css/kbb/kbb.css', 'css/kbb/kbb-grid-skins.css'] as $sheet) {
        $src = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path($sheet)));
        preg_match_all('#([^{}]*\.kbb-card:hover[^{}]*)\{#', $src, $hm);

        foreach ($hm[1] as $group) {
            foreach (array_map('trim', explode(',', $group)) as $sel) {
                if ($sel === '' || str_starts_with($sel, 'body:not(.pc-phonehover)') || str_starts_with($sel, '.skinprev')
                    || in_array($sel, $exceptions, true) || ! str_contains($sel, '.kbb-pgrid')) {
                    continue;
                }

                // The generic transform rule covers a card's own lift on any skin.
                $covered = str_contains($gated, 'body:not(.pc-phonehover) '.$sel)
                    || str_contains($gated, 'body:not(.pc-phonehover) '.preg_replace('#\[data-skin[^\]]*\]#', '', $sel));

                expect($covered)->toBeTrue("{$sheet}: `{$sel}` has no resting value in the phone-hover block");
            }
        }
    }
});

it('puts pc-phonehover on <body> only when "Card hover effects on phones" is on', function () {
    /*
     * MUTATION, RUN: emit 'pc-phonehover' unconditionally from bodyClass() and
     * the default half is red — the block would step aside on every shop.
     */
    expect(ProductStyles::SCHEMA['hover_phone'][2])->toBeFalse();

    prSeed(2);
    expect(prGet('/shop/'))->not->toContain('pc-phonehover');

    prStyles(['hover_phone' => true]);
    expect(prGet('/shop/'))->toMatch('#<body class="[^"]*\bpc-phonehover\b#');
});

/* ═══════════════════ 4 · spacing and type ═══════════════════ */

it('emits nothing at all while every Spacing & type control is at its default', function () {
    /*
     * The shop must be byte-identical when this ships: no <style> element on
     * any page, and the other card designs keep their own spacing.
     * MUTATION, RUN: drop the `$moved(...)` guard on card_pad and this is red
     * on cardCss() !== '' and on the element in the page.
     */
    expect(app(ProductStyles::class)->cardCss())->toBe('');

    [$cat] = prSeed(2);

    foreach (['/shop/', prCatPath($cat), '/brands/pr-brand/', '/'] as $uri) {
        expect(prGet($uri))->not->toContain('kbb-card-type');
    }
});

it('pins every default to the value the stylesheet renders today', function () {
    /*
     * The defaults are the MEASURED values (docs/lane-pr-shots/before/
     * measurements.json), and this reads each one back out of the sheet that
     * produces it, so the control and the card cannot drift apart. MUTATION,
     * RUN: `--sc-pad:18px` in kbb.css's showcase block and this is red on
     * card_pad_d.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $sc = substr($css, (int) strpos($css, '.kbb-pgrid[data-skin^="showcase"]{'));
    $prop = function (string $name) use ($sc): string {
        preg_match('#'.preg_quote($name, '#').':([^;}]+)#', $sc, $m);

        return trim($m[1] ?? '');
    };
    $d = fn (string $k) => ProductStyles::SCHEMA[$k][2];

    expect($prop('--sc-pad'))->toBe($d('card_pad_d').'px')
        ->and($d('card_pad_m'))->toBe($d('card_pad_d'))
        ->and($d('card_gap_img_d'))->toBe($d('card_pad_d'))          // the text column's top padding IS --sc-pad
        ->and($prop('--sc-gap'))->toBe($d('card_gap_price_d').'px')  // .cp padding-top
        ->and($prop('--sc-gap'))->toBe($d('card_gap_cart_d').'px')   // .kbb-card-cart margin-top
        ->and($prop('--sc-name'))->toBe($d('card_fs_title_d'))
        ->and($prop('--sc-brand-fs'))->toBe($d('card_fs_brand_d'))
        ->and($prop('--sc-btn-fs'))->toBe($d('card_fs_btn_d'))
        ->and($d('card_fs_price_d'))->toBe('15px')                  // calc(var(--sc-name) + 1px)
        ->and($css)->toContain('.kbb-pgrid[data-skin^="showcase"]{--sc-btn-fs:'.$d('card_fs_btn_m').'}')
        // Inside kbb.css's long (max-width:900px) block — the phone price.
        ->and($css)->toContain('.kbb-card-price{font-size:'.$d('card_fs_price_m').'}');

    expect($d('card_fw_title'))->toBe('600')
        ->and($d('card_fw_brand'))->toBe('600')
        ->and($d('card_fw_price'))->toBe('700')
        ->and($d('card_fw_sale'))->toBe('700')
        ->and($d('card_fw_btn'))->toBe('700');
});

it('emits exactly the moved values, each in its own device query', function () {
    /*
     * MUTATION, RUN: swap PHONE and DESKTOP in cardCss()'s loop and the phone
     * padding lands in the desktop query — red. Remove the sale-price hold and
     * "Price weight" repaints the sale price — red on the last line.
     */
    prStyles([
        'card_pad_m' => 10,
        'card_gap_cart_d' => 20,
        'card_fs_title_d' => '17px',
        'card_fw_price' => '500',
    ]);

    $css = app(ProductStyles::class)->cardCss();

    expect($css)->toBe(
        '@media (max-width:700px){.kbb-pgrid.kbb-pgrid[data-skin]{--sc-pad:10px}'
        .'.kbb-pgrid[data-skin] .kbb-tile .cb{padding-inline:10px;padding-bottom:10px}}'
        .'@media (min-width:701px){.kbb-pgrid[data-skin] .kbb-tile .kbb-card-cart{margin-top:20px}'
        .'.kbb-pgrid.kbb-pgrid[data-skin]{--sc-name-slot:calc(17px * var(--sc-name-lh,1.32) * var(--sc-name-lines,2))}'
        .'.kbb-pgrid[data-skin] .kbb-tile .kbb-card-nm{font-size:17px}}'
        .'.kbb-pgrid[data-skin] .kbb-tile .kbb-card-price{font-weight:500}'
        .'.kbb-pgrid[data-skin] .kbb-tile .kbb-card-reg+.kbb-card-price{font-weight:700}'
    );

    prSeed(2);
    expect(prGet('/shop/'))->toContain('<style id="kbb-card-type">'.$css.'</style>');
});

it('reaches every Spacing & type control through to a selector, and none of them twice', function () {
    /*
     * A control that is drawn and emits nothing is the "built, never wired"
     * shape this repo keeps finding. Each key is moved alone and must produce
     * CSS. MUTATION, RUN: delete the card_fs_brand block from cardCss() and
     * this is red naming card_fs_brand_m and card_fs_brand_d.
     */
    $off = [
        'card_pad_m' => 4, 'card_pad_d' => 30, 'card_gap_img_m' => 2, 'card_gap_img_d' => 30,
        'card_gap_price_m' => 0, 'card_gap_price_d' => 30, 'card_gap_cart_m' => 0, 'card_gap_cart_d' => 30,
        'card_fs_title_m' => '12px', 'card_fs_title_d' => '18px', 'card_fs_price_m' => '16px', 'card_fs_price_d' => '20px',
        'card_fs_btn_m' => '13px', 'card_fs_btn_d' => '14px', 'card_fs_brand_m' => '9px', 'card_fs_brand_d' => '12px',
        'card_fw_title' => '400', 'card_fw_price' => '400', 'card_fw_sale' => '500', 'card_fw_btn' => '500', 'card_fw_brand' => '400',
    ];

    expect(array_keys($off))->toEqualCanonicalizing(ProductStyles::TABS['spacing'][2]);

    $dead = [];

    foreach ($off as $key => $value) {
        prStyles([$key => $value]);
        $css = app(ProductStyles::class)->cardCss();

        $needle = is_int($value) ? $value.'px' : (str_ends_with($value, 'px') ? $value : ':'.$value);

        if ($css === '' || ! str_contains($css, $needle)) {
            $dead[] = $key;
        }

        prStyles([$key => ProductStyles::SCHEMA[$key][2]]);
        expect(app(ProductStyles::class)->cardCss())->toBe('', "{$key} back at its default still emits CSS");
    }

    expect($dead)->toBe([]);
});

it('cannot be made to print anything but its own constants (rule 5)', function () {
    /*
     * A select stores one of its own options or the default; a range is a
     * clamped integer. So a POST that tries to break out of the stylesheet
     * stores the default and emits nothing. MUTATION, RUN: return `(string)
     * $c[$k]` from the $size closure without the FONT_SIZES check, and store a
     * raw row directly — the payload reaches the page and this is red.
     */
    $owner = AdminUser::create([
        'name' => 'PR Owner', 'email' => 'pr-owner@example.test',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/product-styles', ['settings' => [
        'card_fs_title_m' => '14px}</style><script>alert(1)</script>',
        'card_fw_btn' => '900',
        'card_pad_d' => '9999',
        'card_gap_img_m' => '-50',
    ]])->assertOk();

    prFresh();
    $c = app(ProductStyles::class)->all();

    expect($c['card_fs_title_m'])->toBe('14px')         // refused → default
        ->and($c['card_fw_btn'])->toBe('700')            // refused → default
        ->and($c['card_pad_d'])->toBe(32)                // clamped to its own max
        ->and($c['card_gap_img_m'])->toBe(0);            // clamped to its own min

    // And a row written behind the cast's back is still not printed.
    Setting::query()->updateOrCreate(['key' => 'card_fs_btn_d'], ['value' => '12px;}body{display:none', 'autoload' => true]);
    prFresh();

    $css = app(ProductStyles::class)->cardCss();
    expect($css)->not->toContain('display:none')
        ->and($css)->not->toContain('<')
        ->and($css)->toContain('padding-inline:32px');
});

it('offers the new controls on Appearance → Product styles, in their own tab', function () {
    /*
     * The screen draws whatever the schema says, so this pins what the owner
     * sees: a "Spacing & type" tab with all twenty-one, the hover switch on
     * Layout, the two badge switches off on Card content.
     */
    $owner = AdminUser::create([
        'name' => 'PR Owner 2', 'email' => 'pr-owner2@example.test',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);

    $tabs = collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/product-styles')->assertOk()->json('tabs'))->keyBy('key');

    expect($tabs['spacing']['label'])->toBe('Spacing & type')
        ->and($tabs['spacing']['fields'])->toHaveCount(21)
        ->and(collect($tabs['layout']['fields'])->firstWhere('key', 'hover_phone')['value'])->toBeFalse()
        ->and(collect($tabs['content']['fields'])->firstWhere('key', 'show_new')['value'])->toBeFalse()
        ->and(collect($tabs['content']['fields'])->firstWhere('key', 'show_discount')['value'])->toBeFalse();

    // Sizes keep their order in the browser: string keys, unit included.
    $sizes = array_keys(collect($tabs['spacing']['fields'])->firstWhere('key', 'card_fs_price_m')['options']);
    expect($sizes[0])->toBe('8px')->and($sizes[1])->toBe('8.5px');
});

/* ═══════════════════ the migration ═══════════════════ */

it('clears a stored value for each of the three defaults that moved, and nothing else', function () {
    /*
     * A shop that has ever pressed Save on either screen has a row, and a row
     * is what stops a new default being seen. MUTATION, RUN: drop
     * 'layout_load_mode' from DEFAULT_MOVED and this is red on that row.
     */
    foreach (['show_new' => '1', 'show_discount' => '1', 'layout_load_mode' => 'arrows', 'show_rating' => '0', 'card_radius' => '20'] as $k => $v) {
        Setting::query()->updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
    }

    $migration = require database_path('migrations/2027_07_12_000300_clear_caches_lane_pr_grid_cards.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    $left = Setting::query()->pluck('value', 'key')->all();

    expect($left)->not->toHaveKey('show_new')
        ->and($left)->not->toHaveKey('show_discount')
        ->and($left)->not->toHaveKey('layout_load_mode')
        ->and($left['show_rating'])->toBe('0')          // the owner's own choice survives
        ->and($left['card_radius'])->toBe('20');
});
