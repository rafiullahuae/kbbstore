<?php

declare(strict_types=1);

/**
 * THE FILTERS DRAWER ON A PHONE — Lane FP.
 *
 * The owner, on his phone's category Filters drawer
 * (docs/fp-owner/filters-mobile.png): "i want the filters panel width control
 * and when clicked on empty area, the filter panel must be need to hide auto.
 * and cross icon must not scroll up with the panel, it should stick so user can
 * see all the time."
 *
 * What the shop did: the drawer was a fixed 300px (held to 90vw) with nothing
 * beside it -- a tap on the shop showing to its right went THROUGH to the page
 * (in Chromium at 390 it opened a product), Esc did nothing, the page under it
 * scrolled, and the FILTERS title and its × scrolled away with the list.
 * Measured in docs/fp-shots: headTop -518px at scrollTop 520 before, 0 after.
 *
 * The drawer is the shop's own markup in store/shop.blade.php, styled below
 * 900px by kbb-shop.css. Every assertion on the sheet strips comments first, so
 * a sentence that names a rule cannot satisfy the rule.
 */

use App\Models\AdminUser;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use Illuminate\Support\Str;
use Tests\Support\SiteLayoutAdminRoutes;

function fpdAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Drawer owner', 'email' => 'fp-'.Str::random(8).'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

/** kbb-shop.css without comments. */
function fpdShopCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-shop.css')));
}

/** The body of the FIRST `@media(max-width:900px){...}` block: the phone drawer. */
function fpdPhoneBlock(): string
{
    $css = fpdShopCss();
    $at = strpos($css, '@media(max-width:900px){');
    expect($at)->not->toBeFalse();

    $depth = 0;
    for ($i = $at; $i < strlen($css); $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}' && --$depth === 0) {
            return substr($css, $at, $i - $at + 1);
        }
    }

    return '';
}

/** The kbb-shop stylesheet the shop is actually served. */
function fpdBuiltShopCss(): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    return (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-shop.css']['file']));
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

/* ═══════════════════════════════════════════════════ a. width control ═══ */

it('offers the drawer width on Appearance → Site layout → Product grid, at the width it has today', function () {
    /*
     * Rule 3: the exact place -- the Product grid tab, under "Show the product
     * count beside Filters", the other control about this listing's Filters.
     * Rule 1: shipped at min(90vw, 300px), which is what `width:300px;
     * max-width:90vw` was, so nothing moves until he drags one.
     *
     * MUTATION, RUN: default 90 -> 100 -> red here and on the sheet's fallback
     * below (the drawer would cover a 390 phone whole on applying the package).
     */
    $this->actingAs(fpdAdmin(), 'admin');

    $grid = collect($this->getJson('/admin-api/site-layout')->assertOk()->json('tabs'))->firstWhere('key', 'grid');
    $fields = collect($grid['fields'])->keyBy('key');

    expect($grid['label'])->toBe('Product grid')
        ->and(array_slice(collect($grid['fields'])->pluck('key')->all(), -3))->toBe(['filter_max', 'filters_m', 'cols_m'])
        ->and($fields['filter_w']['label'])->toBe('Filters drawer width · phone')
        ->and($fields['filter_w']['value'])->toBe(90)
        ->and($fields['filter_max']['value'])->toBe(300);

    expect(SiteLayout::SCHEMA['filter_w'][4])->toMatchArray(['min' => 60, 'max' => 100, 'unit' => '%'])
        ->and(SiteLayout::SCHEMA['filter_max'][4])->toMatchArray(['min' => 260, 'max' => 600, 'unit' => 'px']);

    // Nothing saved: no stylesheet on the page at all, as before.
    expect(app(SiteLayout::class)->isDefault())->toBeTrue()
        ->and(app(SiteLayout::class)->css())->toBe('');
});

it('stores a clamped whole number and nothing else', function () {
    /*
     * Rule 5: a range is a clamped integer. 150% would push the drawer off the
     * screen; 5% would leave a sliver nobody can tap.
     *
     * MUTATION, RUN: 'max' => 100 -> 200 on filter_w -> red on the 150.
     */
    $this->actingAs(fpdAdmin(), 'admin');

    $this->postJson('/admin-api/site-layout', ['settings' => ['filter_w' => 150, 'filter_max' => 9999]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->all())->toMatchArray(['filter_w' => 100, 'filter_max' => 600]);

    $this->postJson('/admin-api/site-layout', ['settings' => ['filter_w' => 5, 'filter_max' => 1]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->all())->toMatchArray(['filter_w' => 60, 'filter_max' => 260]);
});

it('reaches the page as two numbers in properties whose names are constants', function () {
    /*
     * MUTATION, RUN: drop 'filter_w' from UNITLESS_VARS -> red: the slider
     * saves and the drawer never moves.
     */
    app(SettingsService::class)->set('layout_filter_w', 85);
    app(SettingsService::class)->set('layout_filter_max', 600);
    SettingsService::forgetMemo();

    $css = app(SiteLayout::class)->css();

    expect($css)->toContain('--kbb-fdrawer-w:85')
        ->and($css)->toContain('--kbb-fdrawer-max:600px');

    $html = (string) $this->get('/shop/')->assertOk()->getContent();

    expect($html)->toContain('--kbb-fdrawer-w:85');
});

it('sizes the drawer from those numbers, falling back to exactly the old 300px / 90vw', function () {
    /*
     * The fallbacks ARE the schema's defaults, so with nothing saved the drawer
     * is min(90vw, 300px) -- 300px at 390, 288px at 320 -- as it was. Measured:
     * 300px at 390 before and after; 331.5px at 85% / 600px.
     *
     * MUTATION, RUN: put back `width:300px;max-width:90vw` -> red (the slider
     * moves nothing).
     */
    $phone = fpdPhoneBlock();
    $w = SiteLayout::SCHEMA['filter_w'][2];
    $max = SiteLayout::SCHEMA['filter_max'][2];

    expect($phone)->toContain("width:calc(var(--kbb-fdrawer-w,{$w}) * 1vw);max-width:var(--kbb-fdrawer-max,{$max}px)")
        ->and($phone)->not->toContain('width:300px');

    $built = fpdBuiltShopCss();

    expect($built)->toContain('--kbb-fdrawer-w,90)')
        ->and($built)->toContain('max-width:var(--kbb-fdrawer-max,300px)');
});

/* ═════════════════════════════════════ b. a tap outside closes it ═══ */

it('draws a backdrop beside the open drawer that closes it, once, on every listing', function () {
    /*
     * The empty area to the right of the drawer was the shop itself: a tap
     * there opened whatever product was under the finger. It is a backdrop now,
     * carrying data-kbb-close, which overlay.js turns into closeAll() -- the
     * same function Esc already calls. The drawer sits ABOVE it (z 95 over 94),
     * so a tap inside the drawer never reaches it.
     *
     * Exactly once: zero is the drawer with nothing to tap; two would stack.
     *
     * MUTATION, RUN: delete the backdrop from shop.blade.php -> red.
     *
     * Lane SO: the drawer is drawn only while a Filters switch is on (both ship
     * off, as the owner asked), so this turns the phone's on first.
     */
    app(\App\Services\SettingsService::class)->set('layout_filters_m', '1');
    \App\Services\SettingsService::forgetMemo();
    $html = (string) $this->get('/shop/')->assertOk()->getContent();

    expect(substr_count($html, '<div class="fscrim" data-kbb-close></div>'))->toBe(1)
        ->and(strpos($html, '</aside>'))->toBeLessThan(strpos($html, 'class="fscrim"'));

    $phone = fpdPhoneBlock();

    expect($phone)->toContain('.fscrim{display:block;position:fixed;inset:0;z-index:94;')
        ->and($phone)->toContain('.filters-open .fscrim{opacity:1;pointer-events:auto}')
        ->and($phone)->toMatch('/\.filtercol\{[^}]*z-index:95/');

    // Laptop: no backdrop at all -- the rail is beside the grid there.
    expect(fpdShopCss())->toContain('.fscrim{display:none}');
});

it('closes on Esc and on the backdrop through the one function both reach', function () {
    /*
     * Before: Esc left the drawer open (measured, docs/fp-shots). closeAll()
     * is what overlay.js's Escape handler and its [data-kbb-close] click both
     * call, so one line serves both and no listener is added.
     *
     * MUTATION, RUN: remove the filters-open line from closeAll() -> red.
     */
    $js = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('js/kbb/overlay.js')));
    $close = substr($js, strpos($js, 'export const closeAll'), strpos($js, 'export const open') - strpos($js, 'export const closeAll'));

    expect($close)->toContain("document.body.classList.remove('filters-open');")
        ->and($js)->toContain("if (event.key === 'Escape') closeAll();")
        ->and($js)->toContain("event.target.closest('[data-kbb-close]')");

    // The × keeps its own inline handler, which works without the bundle.
    // (With the phone's Filters switch on: off, there is no drawer. Lane SO.)
    app(\App\Services\SettingsService::class)->set('layout_filters_m', '1');
    \App\Services\SettingsService::forgetMemo();
    $html = (string) $this->get('/shop/')->assertOk()->getContent();
    expect(substr_count($html, 'class="fclose" type="button" onclick="document.body.classList.remove(\'filters-open\')"'))->toBe(1);
});

it('holds the page still underneath while the drawer is open, on a phone only', function () {
    /*
     * Before: a wheel over the shop beside the drawer scrolled the page 600px.
     * After: 0. overscroll-behavior stops a fling at the end of the list from
     * chaining to the page.
     *
     * MUTATION, RUN: drop body.filters-open{overflow:hidden} -> red.
     */
    $phone = fpdPhoneBlock();

    expect($phone)->toContain('body.filters-open{overflow:hidden}')
        ->and($phone)->toMatch('/\.filtercol\{[^}]*overscroll-behavior:contain/')
        ->and(substr_count(fpdShopCss(), 'body.filters-open{overflow:hidden}'))->toBe(1);
});

/* ═════════════════════════════════════ c. the title row stays put ═══ */

it('pins the FILTERS title and its × to the top of the drawer while the list scrolls', function () {
    /*
     * Sticky inside the drawer's own scroll box (.filtercol), on a solid white
     * ground so a row scrolling under it does not show through. Phone only:
     * the laptop rail is unchanged (measured: position static at 1280).
     *
     * MUTATION, RUN: drop `position:sticky` -> red; the × scrolls off at once.
     */
    $phone = fpdPhoneBlock();

    expect($phone)->toContain('.fhead{position:sticky;top:0;z-index:1;background:#fff}')
        ->and($phone)->toMatch('/\.filtercol\{[^}]*overflow-y:auto/');

    expect(substr_count(fpdShopCss(), 'position:sticky;top:0;z-index:1;background:#fff'))->toBe(1);
    expect(fpdBuiltShopCss())->toContain('.fhead{position:sticky;top:0;z-index:1;background:#fff}');
});

it('adds no query to the listing', function () {
    /*
     * The settings are read through Setting::map(), already in memory; the
     * backdrop is markup. StorefrontQueryBudgetTest is the budget; this is the
     * flat-cost proof that the drawer's numbers do not cost a read of their own.
     */
    $this->get('/shop/')->assertOk();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->get('/shop/')->assertOk();
    $default = count(\Illuminate\Support\Facades\DB::getQueryLog());

    app(SettingsService::class)->set('layout_filter_w', 85);
    SettingsService::forgetMemo();
    $this->get('/shop/')->assertOk();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    $this->get('/shop/')->assertOk();
    $moved = count(\Illuminate\Support\Facades\DB::getQueryLog());

    expect($moved)->toBe($default);
});
