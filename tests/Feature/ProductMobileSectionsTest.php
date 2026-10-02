<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Services\ProductMobileSections;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\ProductRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Appearance → Product page → Mobile sections — Lane QA.
 *
 * The owner, 2 October:
 *
 *   "i want things need to work as sections. image + gallery, Title, short
 *    description, then price row (cut price + actual price + discount capsule)
 *    + on right side rating (4.9 and bar, remove count) but also give option
 *    display reviews in seperate row incase we don't like on right side of the
 *    pricing, then bundle section, then ready to ship row, then quantity + add
 *    to cart section, then delivery yellow box, then authenticity row, then Buy
 *    together box (keep turned off by default), remove the 100% authentic 3
 *    rows completely, remove the THE DETAILS sub heading completely. then
 *    Product Details section along with tabs, and then reviews section, and
 *    then you may also like section. [...] and give functionality to drag an
 *    drop the positioning changing / sorting, and ON/OFF anything. THIS message
 *    changes is only for MOBILE. for desktop everything is fine. also i want
 *    one more section. Tabby and Tamara. [...] no learn more buttons. [...]
 *    also FOR DEKSTOP only two changes. the share icon will also desktop
 *    beside the title on right side. and the pricing row will come downside
 *    and on right side of the pricing row rating without count"
 *
 *   "also make sure that we will have control for spacing between the sections
 *    to adjust. by default keep same space between, i mean good enough."
 *
 * WHAT A DEFECT LOOKS LIKE ON THE SHOP, for each group below, is in the case's
 * own comment. MUTATION NOTES marked RUN were made and reverted on this branch.
 */

function pmsAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Sections Owner',
        'email' => 'pms-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function pmsProduct(array $overrides = [], int $reviews = 0): Product
{
    $product = Product::create(array_merge([
        'slug' => 'pms-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf 77% Soothing Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'sale_price' => 7400,
        'stock_status' => 'instock',
        'short_description' => '<p>A gentle daily toner for skin that reacts to everything.</p>',
    ], $overrides));

    for ($i = 0; $i < $reviews; $i++) {
        Review::create([
            'product_id' => $product->id, 'author_name' => 'Reviewer '.$i, 'rating' => 5,
            'title' => 'Again', 'content' => 'Third bottle.', 'status' => 'approved', 'verified' => true, 'source' => 'import',
        ]);
    }

    if ($reviews > 0) {
        ProductRating::refresh([$product->id]);
    }

    return $product->fresh();
}

function pmsPage(Product $product, string $prefix = ''): string
{
    return (string) test()->get($prefix.'/product/'.$product->slug.'/')->assertOk()->getContent();
}

function pmsFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

/** The `.pdp-page` wrapper's opening tag. */
function pmsWrapper(string $html): string
{
    expect(preg_match('#<div class="wrap pdp-page[^"]*" style="[^"]*">#', $html, $m))->toBe(1, 'the product page wrapper is missing');

    return $m[0];
}

function pmsCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
}

/** Every `@media (max-width:880px){…}` block in a stylesheet, joined. */
function pmsPhoneBlocks(string $css): string
{
    $out = '';
    $offset = 0;

    while (preg_match('/@media \(max-width:880px\)\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $i = $start;

        while ($depth > 0 && $i < strlen($css)) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            $i++;
        }

        $out .= substr($css, $start, $i - $start - 1)."\n";
        $offset = $i;
    }

    return $out;
}

/** The stylesheet with every phone block taken out. */
function pmsOutsidePhone(string $css): string
{
    $offset = 0;

    while (preg_match('/@media \(max-width:880px\)\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $at = $m[0][1];
        $depth = 1;
        $i = $at + strlen($m[0][0]);

        while ($depth > 0 && $i < strlen($css)) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            $i++;
        }

        $css = substr($css, 0, $at).substr($css, $i);
        $offset = $at;
    }

    return $css;
}

function pmsPost(array $body)
{
    return test()->postJson('/admin-api/product-page', $body);
}

/* ═══════════════ 1. his order, as the page ships ═══════════════════════════ */

it('ships his order, his switches and one even gap, written onto the page as integers', function () {
    /*
     * THE DEFECT: a phone page in any other order than the one he dictated, or
     * a default he did not choose. The wrapper carries the whole state, so it
     * is pinned whole.
     *
     * MUTATION NOTE, RUN: swap 'paylater' and 'price' in SECTIONS → RED here
     * (the order values move). Ship `buytogether` ON → RED on pm-off-buytogether.
     */
    $html = pmsPage(pmsProduct());
    $wrapper = pmsWrapper($html);

    expect($wrapper)->toBe('<div class="wrap pdp-page pm-off-trust pm-off-buytogether pm-rate-m-beside pd-rate-d-beside" style="'
        .'--pm-gap:18px;--pm-o-gallery:1;--pm-o-title:2;--pm-o-short:3;--pm-o-price:4;--pm-o-paylater:5;--pm-o-bundles:6;'
        .'--pm-o-ready:7;--pm-o-cart:8;--pm-o-delivery:9;--pm-o-auth:10;--pm-o-trust:11;--pm-o-paychips:12;'
        .'--pm-o-buytogether:13;--pm-o-details:14;--pm-o-reviews:15;--pm-o-related:16;--pm-dm-short:-8px;--pm-dm-cart:-6px">');

    expect(array_keys(ProductMobileSections::SECTIONS))->toBe([
        'gallery', 'title', 'short', 'price', 'paylater', 'bundles', 'ready', 'cart',
        'delivery', 'auth', 'trust', 'paychips', 'buytogether', 'details', 'reviews', 'related',
    ]);
});

it('orders and switches every section only inside the phone breakpoint, and has a rule for each one', function () {
    /*
     * THE DEFECT: a section with no `order` rule falls to wherever the flex
     * container puts it (the catch-all sends it to the end), and one with no
     * off rule cannot be switched off — a switch that does nothing. And a rule
     * outside the 880px block would reorder or hide the LAPTOP page, which he
     * said is fine as it is.
     *
     * MUTATION NOTE, RUN: delete `.pm-off-paylater .pm-paylater,` from the
     * off list → RED naming paylater.
     */
    $css = pmsCss();
    $phone = pmsPhoneBlocks($css);

    foreach (array_keys(ProductMobileSections::SECTIONS) as $key) {
        expect($phone)->toContain('order:var(--pm-o-'.$key.',');
        expect($phone)->toContain('margin-block'.($key === 'buytogether' || in_array($key, ['details', 'reviews', 'related', 'delivery', 'auth'], true) ? '' : '-start').':var(--pm-dm-'.$key.',0px)');
        expect((bool) preg_match('/\.pm-off-'.$key.'[ >][^{]*[,{]/', $phone))->toBeTrue("no off rule for {$key}");
    }

    // Outside the phone blocks, no order and no off switch.
    $outside = pmsOutsidePhone($css);
    expect($outside)->not->toContain('var(--pm-o-');
    expect($outside)->not->toContain('.pm-off-');

    // The column itself: one flex column, the even gap, the boxes in between stepping aside.
    expect($phone)->toContain('.pdp-page{display:flex;flex-direction:column;row-gap:var(--pm-gap,18px)}');
    expect($phone)->toContain('.pdp-page > .pdp,.pdp-page > .pdp > .buybox,.pdp-page .buybox > .kbb-cart-form{display:contents}');
});

/* ═══════════════ 2. ON / OFF ═════════════════════════════════════════════ */

it('hides a section on the phone when he switches it off, and shows the old trust rows again when he switches them on', function () {
    /*
     * THE DEFECT: an ON/OFF switch that saves and moves nothing.
     *
     * MUTATION NOTE, RUN: make wrapperClass() skip `pm-off-` → RED on the
     * first expectation.
     */
    test()->actingAs(pmsAdmin(), 'admin');
    $product = pmsProduct();

    pmsPost(['msections' => ['list' => ['on' => ['delivery' => false, 'trust' => true]]]])->assertOk();
    pmsFlush();

    $wrapper = pmsWrapper(pmsPage($product));
    expect($wrapper)->toContain(' pm-off-delivery ');
    expect($wrapper)->not->toContain('pm-off-trust');
});

it('keeps the three trust rows on the laptop while they are off on the phone', function () {
    /*
     * "remove the 100% authentic 3 rows completely" — on the phone. "for
     * desktop everything is fine", so the markup is still there and only the
     * phone block hides it.
     */
    $html = pmsPage(pmsProduct());

    expect($html)->toContain('trust pm-sec pm-trust">');
    expect(pmsWrapper($html))->toContain(' pm-off-trust ');
    expect(pmsPhoneBlocks(pmsCss()))->toContain('.pm-off-trust .pm-trust');
});

it('ships Buy together off on the phone, as asked', function () {
    expect(app(ProductMobileSections::class)->layout()['on']['buytogether'])->toBeFalse();
    expect(app(ProductMobileSections::class)->layout()['on']['trust'])->toBeFalse();
    expect(pmsWrapper(pmsPage(pmsProduct())))->toContain(' pm-off-buytogether ');
});

it('hides THE DETAILS on the phone only, and brings it back with its switch', function () {
    /*
     * "remove the THE DETAILS sub heading completely" — a mobile message. The
     * eyebrow stays in the markup for the laptop; the phone block hides it.
     *
     * MUTATION NOTE, RUN: ship `details_head` true → RED on pm-dhead.
     */
    $product = pmsProduct();
    $html = pmsPage($product);

    expect($html)->toContain('<section class="sec pm-sec pm-details">'."\n".'    <div class="eyebrow">');
    expect(pmsWrapper($html))->not->toContain('pm-dhead');
    expect(pmsPhoneBlocks(pmsCss()))->toContain('.pdp-page:not(.pm-dhead) > .pm-details > .eyebrow{display:none}');

    app(ProductMobileSections::class)->saveOptions(['details_head' => true]);
    pmsFlush();
    expect(pmsWrapper(pmsPage($product)))->toContain(' pm-dhead');
});

/* ═══════════════ 3. the order list is validated ══════════════════════════ */

it('refuses an unknown section and a section listed twice, and appends one that is missing', function () {
    /*
     * THE DEFECT: an order list that drops a section loses it from the phone
     * page for good; a list with a key twice draws a section twice in the
     * admin and gives it two positions; an unknown key is a typo reporting
     * success.
     *
     * MUTATION NOTE, RUN: remove the "listed twice" check in validate() → RED
     * on the second POST (200 instead of 422).
     */
    test()->actingAs(pmsAdmin(), 'admin');

    pmsPost(['msections' => ['list' => ['order' => ['gallery', 'nope']]]])->assertStatus(422);
    pmsPost(['msections' => ['list' => ['order' => ['gallery', 'title', 'gallery']]]])->assertStatus(422);
    pmsPost(['msections' => ['list' => ['on' => ['nope' => true]]]])->assertStatus(422);
    pmsPost(['msections' => ['list' => ['space' => ['nope' => 3]]]])->assertStatus(422);
    pmsPost(['msections' => ['options' => ['nope' => 3]]])->assertStatus(422);
    pmsPost(['msections' => ['wrong' => []]])->assertStatus(422);
    pmsFlush();

    // Nothing was written by any of the refused posts.
    expect(DB::table('settings')->where('key', ProductMobileSections::LAYOUT_KEY)->exists())->toBeFalse();

    pmsPost(['msections' => ['list' => ['order' => ['related', 'gallery']]]])->assertOk();
    pmsFlush();

    $order = app(ProductMobileSections::class)->layout()['order'];
    expect($order)->toHaveCount(count(ProductMobileSections::SECTIONS));
    expect(array_slice($order, 0, 3))->toBe(['related', 'gallery', 'title']);
    expect(array_unique($order))->toBe($order);
});

it('re-validates a stored row on the way out, so a hand-written one cannot break the page', function () {
    app(SettingsService::class)->set(ProductMobileSections::LAYOUT_KEY, [
        'order' => ['title', 'title', 'bogus', 'gallery'], 'on' => ['gallery' => '0'], 'space' => ['price' => '999', 'title' => 'x'],
    ]);
    pmsFlush();

    $layout = app(ProductMobileSections::class)->layout();
    expect(array_slice($layout['order'], 0, 3))->toBe(['title', 'gallery', 'short']);
    expect($layout['order'])->toHaveCount(16);
    expect($layout['on']['gallery'])->toBeFalse();
    expect($layout['space']['price'])->toBe(48);
    expect($layout['space']['title'])->toBeNull();
});

/* ═══════════════ 4. spacing: one even gap, overrides, the clamp ══════════ */

it('spaces every section by one even gap, with two named exceptions, and neutralises their own margins on the phone', function () {
    /*
     * "by default keep same space between". THE DEFECT: each block's own
     * margin and hairline seam adding to the gap, so every pair of sections
     * sits a different distance apart (the measured page before this: 20px,
     * 22px, 12px, 16px, 50px between neighbours).
     *
     * The two exceptions are VISIBLE values on the screen, not leftover
     * margins: the blurb sits 10px under the name, Add to cart 12px under the
     * stock line.
     */
    expect(ProductMobileSections::SCHEMA['gap'][2])->toBe(18);
    expect(ProductMobileSections::SPACE_DEFAULTS)->toBe(['short' => 10, 'cart' => 12]);

    $phone = pmsPhoneBlocks(pmsCss());
    expect($phone)->toContain('.pdp-page .pm-sec > :first-child{margin-block-start:0}');
    expect($phone)->toContain('.pdp-page .pm-sec > :last-child{margin-block-end:0}');
    expect($phone)->toContain('border-block-start:0;margin-block-start:0;padding-block-start:0');
});

it('turns a per-section space into the difference from the even gap, and ignores it on the first section', function () {
    /*
     * MUTATION NOTE, RUN: drop `$key !== $first` from wrapperStyle() → RED on
     * the gallery's override appearing.
     */
    test()->actingAs(pmsAdmin(), 'admin');

    pmsPost(['msections' => [
        'list' => ['space' => ['price' => 30, 'gallery' => 40, 'short' => null, 'cart' => '']],
        'options' => ['gap' => 20],
    ]])->assertOk();
    pmsFlush();

    $style = app(ProductMobileSections::class)->wrapperStyle();
    expect($style)->toStartWith('--pm-gap:20px;');
    expect($style)->toContain('--pm-dm-price:10px');
    expect($style)->not->toContain('--pm-dm-gallery');
    expect($style)->not->toContain('--pm-dm-short');
    expect($style)->not->toContain('--pm-dm-cart');
});

it('clamps every spacing value to 0–48 and refuses anything that is not a number', function () {
    /*
     * Rule 5: nothing from a setting reaches CSS but a validated integer.
     *
     * MUTATION NOTE, RUN: return `(int) $raw` from space() without the clamp →
     * RED on 999 becoming 999.
     */
    expect(ProductMobileSections::space(999))->toBe(48);
    expect(ProductMobileSections::space(-5))->toBe(0);
    expect(ProductMobileSections::space('12.6'))->toBe(13);
    expect(ProductMobileSections::space('abc'))->toBeNull();
    expect(ProductMobileSections::space('1px;}body{x'))->toBeNull();
    expect(ProductMobileSections::space(''))->toBeNull();

    app(ProductMobileSections::class)->saveOptions(['gap' => 999]);
    pmsFlush();
    expect(app(ProductMobileSections::class)->gap())->toBe(48);
    expect(app(ProductMobileSections::class)->wrapperStyle())->toMatch('/^(--pm-[a-z]+(-[a-z]+)?:-?\d+(px)?;?)+$/');
});

/* ═══════════════ 5. the price row and the rating ═════════════════════════ */

it('draws the price row under the title, struck price first, rating beside it with no count', function () {
    /*
     * THE DEFECT: "AED 74 · 5 reviews" — the count he asked to remove — or the
     * price still sitting in the title's row on a laptop.
     *
     * MUTATION NOTE, RUN: ship `rate_count` true → RED on the count.
     */
    $html = pmsPage(pmsProduct([], 5));

    expect(preg_match('#<div class="bb-head">(.*?)</div>#s', $html, $head))->toBe(1);
    expect($head[1])->not->toContain('bbPrice');
    expect($head[1])->toContain('class="pdp-share-btn"');

    expect(preg_match('#<div class="bb-pricerow" id="bbPriceRow">(.*?)</div>\s*@?#s', $html))->toBe(1);
    $row = substr($html, (int) strpos($html, 'id="bbPriceRow"'), 4000);
    expect(strpos($row, '<s>'))->toBeLessThan((int) strpos($row, 'class="now"'));
    expect(strpos($row, 'class="now"'))->toBeLessThan((int) strpos($row, 'class="off"'));
    expect($row)->toContain('id="capArea"');
    expect($row)->toContain('sr-cap-avg');
    expect($row)->toContain('bb-ratebar');
    expect($html)->not->toContain('<span class="sr-cap-count">');

    app(SettingsService::class)->set('pdpms_rate_count', true);
    pmsFlush();
    expect(pmsPage(pmsProduct([], 3)))->toContain('<span class="sr-cap-count">');
});

it('puts the rating in its own row, per device, from a select that stores only its own options', function () {
    /*
     * "give option display reviews in seperate row". Phone and laptop are
     * separate controls; each select stores one of its own options or the
     * default (rule 5).
     */
    test()->actingAs(pmsAdmin(), 'admin');

    pmsPost(['msections' => ['options' => ['rate_m' => 'row', 'rate_d' => 'row']]])->assertOk();
    pmsFlush();
    $wrapper = pmsWrapper(pmsPage(pmsProduct([], 3)));
    expect($wrapper)->toContain(' pm-rate-m-row ');
    expect($wrapper)->toContain(' pd-rate-d-row');

    pmsPost(['msections' => ['options' => ['rate_m' => 'hidden', 'rate_d' => 'hidden']]])->assertOk();
    pmsFlush();
    $m = app(ProductMobileSections::class);
    expect($m->choice('rate_m'))->toBe('hidden');
    expect($m->choice('rate_d'))->toBe('beside', 'the laptop select has no "hidden" option, so it falls back to its default');

    pmsPost(['msections' => ['options' => ['rate_m' => '"><script>']]])->assertOk();
    pmsFlush();
    expect(app(ProductMobileSections::class)->choice('rate_m'))->toBe('beside');

    $css = pmsCss();
    expect(pmsPhoneBlocks($css))->toContain('.pm-rate-m-row .bb-pricerow{flex-direction:column;align-items:flex-start}');
    expect($css)->toContain('.pd-rate-d-row .bb-pricerow{flex-direction:column;align-items:flex-start}');
});

/* ═══════════════ 6. Tabby & Tamara ═══════════════════════════════════════ */

it('draws the two pay-later cards, escaped, with no link and no Learn more', function () {
    /*
     * "two columns side by side [...] no learn more buttons." THE DEFECT: a
     * card that links away, or a setting printed raw.
     *
     * MUTATION NOTE, RUN: print the text with {!! !!} → RED on the escaping.
     */
    $html = pmsPage(pmsProduct());

    expect(preg_match('#<div class="pdp-paylater pm-sec pm-paylater">(.*?)</div><!--/pm-->#s', $html, $m))->toBe(1);
    $cards = $m[1];
    expect(substr_count($cards, 'class="pdp-pl-card'))->toBe(2);
    expect($cards)->toContain('Split your purchase into monthly payments');
    expect($cards)->toContain('Installments up to 6 months, no late fees!');
    expect($cards)->toContain('aria-label="tabby"');
    expect($cards)->toContain('aria-label="tamara"');
    expect($cards)->not->toContain('<a ');
    expect(strtolower($cards))->not->toContain('learn more');

    // Tags are stripped on the way in (markup => strip) AND the words are
    // escaped on the way out; the ampersand and the quotes prove the second.
    app(ProductMobileSections::class)->saveOptions(['tabby_text' => '<script>alert(1)</script>Pay "4" & save']);
    pmsFlush();
    $html = pmsPage(pmsProduct());
    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('<span class="pdp-pl-text">alert(1)Pay &quot;4&quot; &amp; save</span>');
});

it('shows a card only while both its own switch and the shop\'s mark for that method are on', function () {
    app(ProductMobileSections::class)->saveOptions(['tamara_on' => false]);
    pmsFlush();
    expect(app(ProductMobileSections::class)->payLater())->toBe(['tabby']);

    app(\App\Services\CartPage::class)->save(['pay_tabby' => false]);
    pmsFlush();
    expect(app(ProductMobileSections::class)->payLater())->toBe([]);
    expect(pmsPage(pmsProduct()))->not->toContain('pdp-paylater');
});

it('keeps the pay-later section off the laptop', function () {
    expect(pmsCss())->toContain('@media (min-width:881px){.pdp .pm-paylater{display:none}}');
});

/* ═══════════════ 7. the share button contract with Lane QB ═══════════════ */

it('renders the share button in the title row and includes the share sheet exactly once', function () {
    /*
     * THE CONTRACT: the button is this lane's, the sheet is QB's. Pinned as
     * the FINISHED state — one include each — so it is green before QB's file
     * lands and stays green after.
     */
    $tpl = (string) file_get_contents(resource_path('views/store/product.blade.php'));
    expect(substr_count($tpl, "@include('partials.product.share-button')"))->toBe(1);
    expect(substr_count($tpl, "@includeIf('partials.product.share-sheet')"))->toBe(1);

    $html = pmsPage(pmsProduct());
    expect(substr_count($html, 'class="pdp-share-btn"'))->toBe(1);
    expect($html)->toContain('<button type="button" class="pdp-share-btn" data-share-open aria-haspopup="dialog" aria-controls="pdpShareSheet" aria-label="Share">');

    expect(__('store.product.share_open'))->toBe('Share');
    expect(\App\Services\Translation\ArabicInterfaceDrafts::all())->toHaveKey('store.product.share_open');
});

/* ═══════════════ 8. one source of truth per section per device ═══════════ */

it('answers the Sections tab\'s phone switch from Mobile sections for a module that IS a section', function () {
    /*
     * THE DEFECT: two switches for one thing on one device, disagreeing.
     *
     * MUTATION NOTE, RUN: drop the `$owned ??` in ProductSections::all() → RED
     * on trust's mobile reading true.
     */
    $all = app(ProductSections::class)->all();
    expect($all['trust']['mobile'])->toBeFalse();
    expect($all['trust']['desktop'])->toBeTrue();
    expect($all['trust']['mobile_owner'])->toBe('Mobile sections');
    expect($all['capsule']['mobile_owner'])->toBeNull();
    // Hidden on the phone by `pm-off-trust` at 880px, never by m-off at 900px.
    expect(app(ProductSections::class)->classFor('trust'))->toBe('');

    // A phone switch he had saved on the Sections tab before this screen
    // existed is inherited — except for the two he named.
    app(SettingsService::class)->set('product_sections', ['short' => ['desktop' => true, 'mobile' => false], 'fbt' => ['desktop' => true, 'mobile' => true]]);
    pmsFlush();
    $layout = app(ProductMobileSections::class)->layout();
    expect($layout['on']['short'])->toBeFalse();
    expect($layout['on']['buytogether'])->toBeFalse();
});

/* ═══════════════ 9. the admin tab ════════════════════════════════════════ */

it('wires the Mobile sections tab into the console exactly once and serves it from the one endpoint', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.product-mobile-sections-screen')"))->toBe(1);

    test()->actingAs(pmsAdmin(), 'admin');
    $body = test()->getJson('/admin-api/product-page')->assertOk()->json();

    expect($body['msections']['list'])->toHaveCount(16);
    expect(array_column($body['msections']['list'], 'key'))->toBe(array_keys(ProductMobileSections::SECTIONS));
    expect($body['msections']['options'][0]['label'])->toBe('Mobile sections');
    expect($body['msections']['space'])->toBe(['min' => 0, 'max' => 48]);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-mobile-sections-screen.blade.php'));
    // Drag and drop for a mouse, pointer events for a finger, buttons for a keyboard.
    expect($screen)->toContain("addEventListener('dragstart'")
        ->toContain("addEventListener('pointerdown'")
        ->toContain('data-pms-up=')
        ->toContain('Reset to the default order');
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'getComputedStyle'] as $api) {
        expect($screen)->not->toContain($api);
    }
});

/* ═══════════════ 10. nothing measured, nothing queried ════════════════════ */

it('adds no JavaScript and no query to the storefront', function () {
    /*
     * CLAUDE.md rule 4. Ordering is CSS; nothing on the storefront asks the
     * browser how big anything is.
     */
    foreach (['views/store/product.blade.php', 'views/partials/product/paylater.blade.php', 'views/partials/product/share-button.blade.php'] as $f) {
        $src = (string) file_get_contents(resource_path($f));
        foreach (['getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'ResizeObserver', 'getComputedStyle'] as $api) {
            expect($src)->not->toContain($api);
        }
    }
    expect((string) file_get_contents(resource_path('views/partials/product/paylater.blade.php')))->not->toContain('<script');

    $product = pmsProduct([], 2);
    pmsPage($product); // warm

    DB::flushQueryLog();
    DB::enableQueryLog();
    pmsPage($product);
    $base = count(DB::getQueryLog());

    app(SettingsService::class)->set(ProductMobileSections::LAYOUT_KEY, ProductMobileSections::defaultLayout());
    app(ProductMobileSections::class)->saveOptions(['rate_m' => 'row']);
    pmsFlush();
    pmsPage($product); // warm the snapshot again

    DB::flushQueryLog();
    pmsPage($product);
    expect(count(DB::getQueryLog()))->toBe($base, 'saving Mobile sections must not add a query to the product page');
    DB::disableQueryLog();
});
