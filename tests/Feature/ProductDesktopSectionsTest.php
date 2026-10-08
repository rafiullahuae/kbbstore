<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ProductDesktopSections;
use App\Services\ProductMobileSections;
use App\Services\ProductSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Appearance → Product page → Desktop sections — Lane RF.
 *
 *   "also give option to change position in desktop also fo the sections.
 *    make all those changes carefully without disturbing other things. and
 *    super fast and optimized."
 *
 * The laptop order of the four full-width blocks under the two columns —
 * Buy these together, Product details, Reviews, You may also like — as CSS
 * `order` inside `@media (min-width:881px)`, printed onto `.pdp-page` only
 * once the order differs from the default.
 *
 * WHAT A DEFECT LOOKS LIKE ON THE SHOP is in each case's own comment.
 * MUTATION NOTES marked RUN were made and reverted on this branch, each one
 * turning the named case red.
 */
function pdsAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Desktop Sections '.$role,
        'email' => 'pds-'.$role.'-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => $role,
    ]);
}

function pdsProduct(): Product
{
    return Product::create([
        'slug' => 'pds-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf 77% Soothing Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'sale_price' => 7400,
        'stock_status' => 'instock',
        'short_description' => '<p>A gentle daily toner.</p>',
    ]);
}

function pdsPage(Product $product): string
{
    return (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
}

function pdsFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function pdsWrapper(string $html): string
{
    expect(preg_match('#<div class="wrap pdp-page[^"]*" style="[^"]*">#', $html, $m))->toBe(1, 'the product page wrapper is missing');

    return $m[0];
}

function pdsPost(array $body)
{
    return test()->postJson('/admin-api/product-page', $body);
}

/** Every `@media (<query>){…}` block in the product stylesheet, joined. */
function pdsMedia(string $query): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $out = '';
    $offset = 0;
    $open = '@media '.$query.'{';

    while (($at = strpos($css, $open, $offset)) !== false) {
        $i = $at + strlen($open);
        $depth = 1;

        while ($depth > 0 && $i < strlen($css)) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            $i++;
        }

        $out .= substr($css, $at + strlen($open), $i - $at - strlen($open) - 1)."\n";
        $offset = $i;
    }

    return $out;
}

/* ═══════════════ 1. the stored order is validated ════════════════════════ */

it('refuses an unknown or repeated key, appends a missing one, and saves nothing on a refusal', function () {
    /*
     * THE DEFECT: a list that drops a block loses it from the laptop page for
     * good (no `order` value: it falls to 999, under the carousel); a key twice
     * draws a row twice in the admin; an unknown key is a typo reporting
     * "Saved".
     *
     * MUTATION NOTE, RUN: remove the "listed twice" check in validate() → RED
     * on the second POST (200, not 422).
     * MUTATION NOTE, RUN: drop the append loop from clean() → RED on toHaveCount(4).
     */
    test()->actingAs(pdsAdmin(), 'admin');

    pdsPost(['dsections' => ['order' => ['reviews', 'nope']]])->assertStatus(422)->assertJson(['error' => 'Unknown desktop section: nope.']);
    pdsPost(['dsections' => ['order' => ['reviews', 'details', 'reviews']]])->assertStatus(422)->assertJson(['error' => 'Desktop section listed twice: reviews.']);
    pdsPost(['dsections' => ['order' => ['reviews' => 1]]])->assertStatus(422);
    pdsPost(['dsections' => ['order' => [['x']]]])->assertStatus(422);
    pdsPost(['dsections' => ['order' => 'reviews']])->assertStatus(422);
    pdsPost(['dsections' => ['wrong' => []]])->assertStatus(422);
    // A refused half refuses the whole POST: the Mobile sections half beside it is not written either.
    pdsPost(['dsections' => ['order' => ['nope']], 'msections' => ['options' => ['gap' => 30]]])->assertStatus(422);
    pdsFlush();

    expect(DB::table('settings')->where('key', ProductDesktopSections::ORDER_KEY)->exists())->toBeFalse();
    expect(DB::table('settings')->where('key', ProductMobileSections::PREFIX.'gap')->exists())->toBeFalse();

    pdsPost(['dsections' => ['order' => ['related', 'reviews']]])->assertOk()->assertJsonPath('dsections.list.0.key', 'related');
    pdsFlush();

    $order = app(ProductDesktopSections::class)->order();
    expect($order)->toBe(['related', 'reviews', 'buytogether', 'details']);
    expect($order)->toHaveCount(4);
});

it('re-validates a stored row on the way out, so a hand-written one cannot break the page', function () {
    app(SettingsService::class)->set(ProductDesktopSections::ORDER_KEY, ['reviews', 'reviews', 'bogus', 7, ['x'], 'related']);
    pdsFlush();
    expect(app(ProductDesktopSections::class)->order())->toBe(['reviews', 'related', 'buytogether', 'details']);

    app(SettingsService::class)->set(ProductDesktopSections::ORDER_KEY, 'not a list');
    pdsFlush();
    expect(app(ProductDesktopSections::class)->order())->toBe(ProductDesktopSections::defaultOrder());
});

/* ═══════════════ 2. the default order is byte-identical ══════════════════ */

it('prints nothing onto the page in the default order, saved or not', function () {
    /*
     * "without disturbing other things": applying the package must move
     * nothing until he drags. The wrapper is pinned whole, exactly as Lane QA
     * pinned it, and is the same whether the order was never saved or was
     * saved back to the default. StorefrontEnglishUnchangedTest holds the
     * whole page to the same rule.
     *
     * MUTATION NOTE, RUN: make wrapperClass() skip its isDefault() return →
     * RED (` pds-on pds-ar-related` appears).
     */
    $expected = '<div class="wrap pdp-page pm-off-trust pm-off-buytogether pm-rate-m-beside pd-rate-d-beside" style="'
        .'--pm-gap:18px;--pm-o-gallery:1;--pm-o-title:2;--pm-o-short:3;--pm-o-price:4;--pm-o-paylater:5;--pm-o-bundles:6;'
        .'--pm-o-ready:7;--pm-o-cart:8;--pm-o-delivery:9;--pm-o-auth:10;--pm-o-trust:11;--pm-o-paychips:12;'
        .'--pm-o-buytogether:13;--pm-o-details:14;--pm-o-reviews:15;--pm-o-related:16;--pm-dm-short:-8px;--pm-dm-cart:-6px">';

    $product = pdsProduct();
    $before = pdsPage($product);
    expect(pdsWrapper($before))->toBe($expected);
    expect(preg_match('/[" ;]-*pds-/', $before))->toBe(0);

    test()->actingAs(pdsAdmin(), 'admin');
    pdsPost(['dsections' => ['order' => ['reviews', 'details']]])->assertOk();
    pdsPost(['dsections' => ['order' => ProductDesktopSections::defaultOrder()]])->assertOk();
    pdsFlush();

    $after = pdsPage($product);
    expect(pdsWrapper($after))->toBe($expected);
    expect(preg_match('/[" ;]-*pds-/', $after))->toBe(0);
    expect(ProductDesktopSections::defaultOrder())->toBe(['buytogether', 'details', 'reviews', 'related']);
});

it('keeps the template\'s blocks in today\'s DOM order, which is the default order', function () {
    /*
     * The default order IS the order the template draws them in — that is
     * what lets the default print nothing. A template edit that moved a block
     * would make the admin list lie about the page.
     *
     * MUTATION NOTE, RUN: swap 'details' and 'reviews' in SECTIONS → RED.
     */
    $tpl = (string) file_get_contents(resource_path('views/store/product.blade.php'));
    $at = [
        'buytogether' => strpos($tpl, "@include('partials.fbt')"),
        'details' => strpos($tpl, '<section class="sec pm-sec pm-details">'),
        'reviews' => strpos($tpl, "@include('partials.reviews')"),
        // (Lane RP) the foot's three blocks, "You may also like" first.
        'related' => strpos($tpl, "@include('partials.product.recs')"),
    ];
    foreach ($at as $k => $pos) {
        expect($pos)->not->toBeFalse("{$k} is not in the template");
    }
    asort($at);
    expect(array_keys($at))->toBe(ProductDesktopSections::defaultOrder());
});

/* ═══════════════ 3. a custom order reaches the CSS, on desktop only ══════ */

it('writes a custom order onto the wrapper as integers, and marks the block drawn under Reviews', function () {
    /*
     * THE DEFECT: an order saved and never drawn, or drawn with Reviews' 56px
     * bottom margin ADDED to Buy these together's 34px (90px: flex items do
     * not collapse margins) instead of the 56px the page draws today.
     *
     * MUTATION NOTE, RUN: drop the `' pds-ar-'.$after` from wrapperClass() →
     * RED on the class.
     */
    test()->actingAs(pdsAdmin(), 'admin');
    pdsPost(['dsections' => ['order' => ['details', 'reviews', 'buytogether', 'related']]])->assertOk();
    pdsFlush();

    $wrapper = pdsWrapper(pdsPage(pdsProduct()));
    // Buy these together has no matches on this bare product, so the block
    // really drawn under Reviews is the carousel — if it has products — or nothing.
    expect($wrapper)->toContain(' pd-rate-d-beside pds-on')
        ->toContain(';--pm-dm-cart:-6px;--pds-o-details:1;--pds-o-reviews:2;--pds-o-buytogether:3;--pds-o-related:4">');

    $svc = app(ProductDesktopSections::class);
    $all = ['buytogether' => true, 'details' => true, 'reviews' => true, 'related' => true];
    expect($svc->wrapperClass($all))->toBe(' pds-on pds-ar-buytogether');
    expect($svc->wrapperClass(['buytogether' => false] + $all))->toBe(' pds-on pds-ar-related');
    expect($svc->wrapperClass(['buytogether' => false, 'related' => false] + $all))->toBe(' pds-on');
    expect($svc->wrapperClass(['reviews' => false] + $all))->toBe(' pds-on');
    expect($svc->wrapperStyle())->toMatch('/^(;--pds-o-(buytogether|details|reviews|related):[1-4]){4}$/');
});

it('works out which blocks a product draws on a laptop from the switches and lists already in hand', function () {
    $modules = app(ProductSections::class);
    $none = ['products' => collect()];
    $some = ['products' => collect([new Product])];

    expect(ProductDesktopSections::drawn($modules, $some, $some))->toBe(['buytogether' => true, 'details' => true, 'reviews' => true, 'related' => true]);
    expect(ProductDesktopSections::drawn($modules, $none, null))->toBe(['buytogether' => false, 'details' => true, 'reviews' => true, 'related' => false]);

    $modules->save(['fbt' => ['desktop' => false, 'mobile' => true], 'related' => ['desktop' => false, 'mobile' => true], 'reviews' => ['desktop' => false, 'mobile' => false]]);
    pdsFlush();
    expect(ProductDesktopSections::drawn(app(ProductSections::class), $some, $some))->toBe(['buytogether' => false, 'details' => true, 'reviews' => false, 'related' => false]);
});

it('orders the four blocks only inside the laptop media query, and every rule waits for pds-on', function () {
    /*
     * THE DEFECT: a rule outside `min-width:881px` would reorder the PHONE,
     * whose order is Mobile sections'; a rule not gated on `.pds-on` would
     * turn the laptop page into a flex column for everyone, default order
     * included.
     *
     * MUTATION NOTE, RUN: move `.pdp-page.pds-on > .sr{order:…}` outside the
     * media query → RED on the outside check.
     * MUTATION NOTE, RUN: write `.pdp-page{display:flex;…}` instead of
     * `.pdp-page.pds-on{…}` → RED on the gate.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $laptop = pdsMedia('(min-width:881px)');

    foreach (['buytogether' => '.kbb-fbt', 'details' => '.pm-details', 'reviews' => '.sr', 'related' => '.ymal'] as $key => $sel) {
        expect($laptop)->toContain('.pdp-page.pds-on > '.$sel.'{order:var(--pds-o-'.$key.',');
    }
    expect($laptop)->toContain('.pdp-page.pds-on{display:flex;flex-direction:column}');
    expect($laptop)->toContain('.pdp-page.pds-on > .pdp{order:-1}');

    // Every selector that mentions this feature is gated on .pds-on.
    preg_match_all('/([^{}]*pds-[^{}]*)\{/', (string) preg_replace('#/\*.*?\*/#s', '', $laptop), $m);
    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $selectors) {
        foreach (explode(',', $selectors) as $sel) {
            expect(trim($sel))->toStartWith('.pdp-page.pds-on');
        }
    }

    // Outside the laptop blocks, nothing reads the order.
    // (comments out first: the block's own header names the property)
    $outside = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    while (($at = strpos($outside, '@media (min-width:881px){')) !== false) {
        $i = $at + strlen('@media (min-width:881px){');
        for ($depth = 1; $depth > 0 && $i < strlen($outside); $i++) {
            $depth += $outside[$i] === '{' ? 1 : ($outside[$i] === '}' ? -1 : 0);
        }
        $outside = substr($outside, 0, $at).substr($outside, $i);
    }
    expect($outside)->not->toContain('--pds-o-');
    expect(preg_match('/\.pds-(on|ar-)[^\n]*\{/', $outside))->toBe(0);
});

/* ═══════════════ 4. the phone is unaffected ══════════════════════════════ */

it('leaves the phone page\'s order, switches and stylesheet exactly as Mobile sections draws them', function () {
    /*
     * THE DEFECT: a laptop reorder that leaks onto the phone. The phone's
     * order is the --pm-o-* properties and the 880px block, and a desktop save
     * must not touch either; the only bytes it adds are the `pds-` class and
     * properties, which no phone rule reads.
     *
     * MUTATION NOTE, RUN: have save() also write ProductMobileSections'
     * layout row → RED on the mobile layout.
     */
    $product = pdsProduct();
    $phoneBefore = pdsMedia('(max-width:880px)');
    $layoutBefore = app(ProductMobileSections::class)->layout();
    $wrapperBefore = pdsWrapper(pdsPage($product));

    test()->actingAs(pdsAdmin(), 'admin');
    pdsPost(['dsections' => ['order' => ['related', 'reviews', 'details', 'buytogether']]])->assertOk();
    pdsFlush();

    expect(app(ProductMobileSections::class)->layout())->toBe($layoutBefore);
    expect(pdsMedia('(max-width:880px)'))->toBe($phoneBefore)->not->toContain('pds-');

    $wrapperAfter = pdsWrapper(pdsPage($product));
    $strip = static fn (string $w): string => preg_replace(['/ pds-[a-z-]+/', '/;--pds-o-[a-z]+:\d+/'], '', $w);
    expect($strip($wrapperAfter))->toBe($wrapperBefore);
    expect($wrapperAfter)->not->toBe($wrapperBefore);
});

/* ═══════════════ 5. the capability fails closed ══════════════════════════ */

it('refuses the order to a role without the Product page capability, and to a guest', function () {
    /*
     * The same capability as every Product page half: content.manage on
     * admin-api/product-page (AdminCapabilities). Support staff can read
     * orders, not reorder the shop.
     *
     * MUTATION NOTE, RUN: map admin-api/product-page to admin.access in
     * AdminCapabilities → RED (200 for support).
     */
    pdsPost(['dsections' => ['order' => ['reviews']]])->assertStatus(401);

    test()->actingAs(pdsAdmin('support'), 'admin');
    pdsPost(['dsections' => ['order' => ['reviews']]])->assertForbidden();
    test()->getJson('/admin-api/product-page')->assertForbidden();
    pdsFlush();

    expect(DB::table('settings')->where('key', ProductDesktopSections::ORDER_KEY)->exists())->toBeFalse();
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/product-page'))->toBe('content.manage');
});

/* ═══════════════ 6. no query added ═══════════════════════════════════════ */

it('adds no query to the product page, in the default order or a custom one', function () {
    /*
     * CLAUDE.md rule 4: the order is one settings row, read from the snapshot
     * SettingsService already took; which blocks are drawn comes from what
     * the controller already built.
     *
     * MUTATION NOTE, RUN: read the row with Setting::query()->where(...)
     * in order() → RED (one more query).
     */
    $product = pdsProduct();
    pdsPage($product); // warm

    DB::flushQueryLog();
    DB::enableQueryLog();
    pdsPage($product);
    $base = count(DB::getQueryLog());

    app(SettingsService::class)->set(ProductDesktopSections::ORDER_KEY, ['reviews', 'related', 'details', 'buytogether']);
    pdsFlush();
    pdsPage($product); // warm the snapshot again

    DB::flushQueryLog();
    $html = pdsPage($product);
    expect(count(DB::getQueryLog()))->toBe($base, 'a desktop order must not add a query to the product page');
    expect(pdsWrapper($html))->toContain(' pds-on');
    DB::disableQueryLog();

    foreach (['views/store/product.blade.php', 'css/kbb/kbb-product.css'] as $f) {
        $src = (string) file_get_contents(resource_path($f));
        foreach (['getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'ResizeObserver', 'getComputedStyle'] as $api) {
            expect($src)->not->toContain($api);
        }
    }
});

/* ═══════════════ 7. the admin tab ════════════════════════════════════════ */

it('mounts the Desktop sections tab exactly once, beside Mobile sections, served by the one endpoint', function () {
    /*
     * Pinned as the FINISHED state (CLAUDE.md): the screen is included exactly
     * once across the console's views — from the Mobile sections partial,
     * which app.blade.php itself includes exactly once — so 0 ("built, never
     * wired up") and 2 (a second wrap of paintProductPage, two buttons) are
     * both red.
     *
     * MUTATION NOTE, RUN: delete the @include from the Mobile sections partial
     * → RED (0). Paste it twice → RED (2).
     */
    $views = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
            $views .= file_get_contents($f->getPathname());
        }
    }
    expect(substr_count($views, "@include('admin.partials.product-desktop-sections-screen')"))->toBe(1);

    $mobile = (string) file_get_contents(resource_path('views/admin/partials/product-mobile-sections-screen.blade.php'));
    expect(substr_count($mobile, "@include('admin.partials.product-desktop-sections-screen')"))->toBe(1);
    expect(strpos($mobile, "@include('admin.partials.product-desktop-sections-screen')"))->toBeGreaterThan((int) strrpos($mobile, '@endverbatim'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.product-mobile-sections-screen')"))->toBe(1);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-desktop-sections-screen.blade.php'));
    // Its button lands right after Mobile sections', in the wrapping strip.
    expect($screen)->toContain("strip.querySelector('[data-pmstab]')")
        ->toContain(">Desktop sections<span class=\"ecn\">'")
        ->toContain("addEventListener('dragstart'")
        ->toContain("addEventListener('pointerdown'")
        ->toContain('data-pds-up=')
        ->toContain('data-pds-down=')
        ->toContain('Reset to the default order')
        ->toContain("querySelectorAll('[data-ppframe]')");
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'getComputedStyle'] as $api) {
        expect($screen)->not->toContain($api);
    }

    test()->actingAs(pdsAdmin(), 'admin');
    $body = test()->getJson('/admin-api/product-page')->assertOk()->json();
    expect(array_column($body['dsections']['list'], 'key'))->toBe(['buytogether', 'details', 'reviews', 'related']);
    expect($body['dsections']['defaults'])->toBe(['buytogether', 'details', 'reviews', 'related']);
    expect($body['dsections']['list'][0]['label'])->toBe('Buy these together');
    expect($body['dsections']['list'][0]['desktop'])->toBeTrue();
});
