<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherPricing;
use App\Services\BuyTogetherSettings;
use App\Services\ProductDesktopSections;
use App\Services\ProductLayout;
use App\Services\ProductSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Appearance → Product page → Desktop sections — Lane RI.
 *
 *   "ONLY IN DESKTOP: allow me option to bring the buy together section to
 *    the right collumn, by drag n drop."
 *
 * "Buy these together" may be dragged from the full-width list into the Buy
 * column list and back. Placed there, the ONE `.kbb-fbt` element is drawn at
 * the end of `.buybox` and RG's laptop flex column puts it at his position;
 * on a phone `.buybox` is display:contents, so Mobile sections still places it.
 *
 * WHAT A DEFECT LOOKS LIKE ON THE SHOP is in each case's own comment. MUTATION
 * NOTES marked RUN were made and reverted on this branch, each one turning the
 * named case red. The pictures and the pixel comparison at 390 are
 * docs/ri-shots/ (tools/ri-shots.cjs).
 */
function riAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Lane RI '.$role,
        'email' => 'ri-'.$role.'-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => $role,
    ]);
}

function riFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
    app()->forgetInstance(BuyTogetherSettings::class);
    app()->forgetInstance(BuyTogetherPricing::class);
}

function riCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function riProduct(string $name, string $cat, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'total_sales' => $sales,
        'short_description' => '<p>A gentle daily product.</p>',
    ], $extra));
    $p->categories()->sync([riCat($cat)->id]);

    return $p;
}

/** A shop where Buy these together draws four cards on the sunscreen's page. */
function riShop(): Product
{
    $sun = riProduct('Relief Sun', 'Sunscreens', 10000, ['price' => 6900]);
    riProduct('Moist Best', 'Moisturisers', 900000, ['price' => 15300, 'sale_price' => 10800]);
    riProduct('Toner Best', 'Toners', 700000, ['price' => 8000]);
    riProduct('Oil Best', 'Cleansing Oils', 500000, ['price' => 9500]);
    riProduct('Mask Best', 'Masks', 300000, ['price' => 6000]);

    $s = app(SettingsService::class);
    foreach (['bt_on' => 1, 'bt_count' => 4] as $k => $v) {
        $s->set($k, $v);
    }
    riFlush();

    return $sun;
}

/** The page as a shopper sees it: no admin signed in, the clock held still. */
function riPage(Product $p): string
{
    Cache::flush();
    riFlush();
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-03 08:00:00'));
    auth('admin')->logout();
    app('auth')->forgetGuards();

    return (string) test()->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

function riPost(array $body)
{
    $r = test()->actingAs(riAdmin(), 'admin')->postJson('/admin-api/product-page', $body);
    riFlush();

    return $r;
}

/** The buy order with Buy these together straight after `$after`. */
function riBuyWith(string $after): array
{
    $out = [];

    foreach (ProductDesktopSections::defaultBuyOrder() as $k) {
        $out[] = $k;

        if ($k === $after) {
            $out[] = 'buytogether';
        }
    }

    return $out;
}

/** Every `@media <query>{…}` block of the product stylesheet, comments removed, joined. */
function riMedia(string $query): string
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-product.css')));
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

/** The product page's `.buybox`, from its opening tag to the end of the payment chips. */
function riBuybox(string $html): string
{
    $start = strpos($html, '<div class="buybox">');
    $chips = strpos($html, 'pm-paychips');
    expect($start)->not->toBeFalse()->and($chips)->not->toBeFalse();
    $end = strpos($html, "\n  </div>\n", $chips);

    return substr($html, $start, $end - $start);
}

/* ═══════════════ 1. ONE PLACE ONLY, AND ONLY THIS BLOCK MAY CROSS ═══════════ */

it('keeps Buy these together in exactly one list and refuses unknown keys, saving nothing on a refusal', function () {
    /*
     * THE DEFECT: listed in both, the template would have to choose — or draw
     * it twice, two `#btTitle`s and two fbt.js bindings on one page; and a
     * full-width block other than this one (Reviews, say) dragged into the buy
     * column would be a key the template cannot draw there at all.
     *
     * MUTATION NOTE, RUN: delete the `$inBuy && $sentUnder` refusal in
     * validatePlacement() → RED (the both-lists POST answers 200).
     * MUTATION NOTE, RUN: drop `&& $key !== self::MOVABLE` from validateBuy()
     * → RED (the placing POST answers 422 "Unknown buy column block").
     */
    $svc = fn () => app(ProductDesktopSections::class);

    // Both lists: refused, nothing written.
    riPost(['dsections' => ['order' => ProductDesktopSections::defaultOrder(), 'buy' => riBuyWith('auth')]])
        ->assertStatus(422)->assertJsonPath('ok', false);
    expect(DB::table('settings')->whereIn('key', ['pdpds_order', 'pdpds_buy_order'])->count())->toBe(0);

    // Unknown, another full-width block, or a repeat in the buy list: refused.
    foreach ([[...riBuyWith('auth'), 'nope'], [...ProductDesktopSections::defaultBuyOrder(), 'reviews'], [...riBuyWith('auth'), 'buytogether']] as $bad) {
        riPost(['dsections' => ['buy' => $bad, 'order' => ['details', 'reviews', 'related']]])->assertStatus(422);
    }
    expect(DB::table('settings')->whereIn('key', ['pdpds_order', 'pdpds_buy_order'])->count())->toBe(0);

    // Into the buy column: saved, and the full-width list no longer holds it.
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    expect($svc()->buyOrder())->toBe(riBuyWith('auth'))
        ->and($svc()->order())->toBe(['details', 'reviews', 'related'])
        ->and($svc()->buyTogetherRight())->toBeTrue();

    // A full-width list that names it while the stored buy list holds it: refused.
    riPost(['dsections' => ['order' => ProductDesktopSections::defaultOrder()]])->assertStatus(422);
    expect($svc()->buyTogetherRight())->toBeTrue();

    // A buy list alone may move it, either way; the other list follows.
    riPost(['dsections' => ['buy' => ProductDesktopSections::defaultBuyOrder()]])->assertOk();
    expect($svc()->buyTogetherRight())->toBeFalse()
        ->and($svc()->order())->toContain('buytogether');
    riPost(['dsections' => ['buy' => riBuyWith('price')]])->assertOk();
    expect($svc()->order())->toBe(['details', 'reviews', 'related']);

    // In neither list: home, under the columns (RF's "a missing key is appended").
    riPost(['dsections' => ['buy' => ProductDesktopSections::defaultBuyOrder(), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    expect($svc()->buyTogetherRight())->toBeFalse()
        ->and($svc()->order())->toBe(['details', 'reviews', 'related', 'buytogether']);

    // The payload labels it wherever it sits.
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    $buy = collect($svc()->payload()['buy'])->keyBy('key');
    expect($buy['buytogether']['label'])->toBe('Buy these together')->and($buy['buytogether']['module'])->toBe('fbt');
});

it('reads a stored row that lists it in both as the buy column, never twice', function () {
    /*
     * THE DEFECT: the live box has a shell; a row edited there to name it in
     * both lists must not print it in both orders.
     *
     * MUTATION NOTE, RUN: make order() return `$order` unfiltered → RED.
     */
    $s = app(SettingsService::class);
    $s->set('pdpds_order', ProductDesktopSections::defaultOrder());
    $s->set('pdpds_buy_order', riBuyWith('auth'));
    riFlush();

    $svc = app(ProductDesktopSections::class);
    expect($svc->order())->toBe(['details', 'reviews', 'related'])
        ->and($svc->isDefault())->toBeTrue()
        ->and($svc->buyOrder())->toBe(riBuyWith('auth'))
        ->and($svc->wrapperStyle())->toContain(';--pdsb-o-buytogether:10;')
        ->and($svc->wrapperStyle())->not->toContain('--pds-o-');
});

/* ═══════════════ 2. NOTHING MOVES UNTIL HE DRAGS IT ═══════════════════════ */

it('draws the page byte for byte as before while it sits under the columns, and again after a round trip', function () {
    /*
     * THE DEFECT: applying the package moves the block, or a blank line, a
     * class or a custom property appears on every product page.
     *
     * The seam pinned below is what the integrator branch printed between the
     * payment chips and the block (measured before this change).
     *
     * MUTATION NOTE, RUN: indent the `@unless ($kbbDsecBtRight)` directive by
     * two spaces in store/product.blade.php → RED (two extra spaces before
     * the block), and so is StorefrontEnglishUnchangedTest.
     */
    $sun = riShop();
    $before = riPage($sun);

    expect(substr_count($before, 'class="kbb-fbt bt'))->toBe(1)
        ->and(riBuybox($before))->not->toContain('kbb-fbt')
        ->and($before)->toMatch('#<span>COD</span></div>\n    </div>\n  </div>\n\n  <section class="kbb-fbt bt #')
        ->and($before)->not->toContain('pdsb-')
        ->and($before)->not->toContain('--pl-bt-gap-d');

    // Over to the buy column and home again: the very same bytes.
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    expect(riPage($sun))->not->toBe($before);
    riPost(['dsections' => ['buy' => ProductDesktopSections::defaultBuyOrder(), 'order' => ProductDesktopSections::defaultOrder()]])->assertOk();
    expect(riPage($sun))->toBe($before);
});

it('ships the right-column gap at 20px and prints no layout CSS for it until it is moved', function () {
    /*
     * THE DEFECT: a new slider that reached the shop's <head> on day one, or a
     * default different from the fallback the stylesheet carries.
     */
    expect(ProductLayout::SCHEMA['bt_gap_d'][2])->toBe(20)
        ->and(ProductLayout::PROPS['bt_gap_d'])->toBe('--pl-bt-gap-d')
        ->and(app(ProductLayout::class)->storefrontCss())->toBe('')
        ->and(riMedia('(min-width:881px)'))->toContain('.pdp-page.pdsb-on .buybox > .kbb-fbt{order:var(--pdsb-o-buytogether,12);margin-block-start:var(--pl-bt-gap-d,20px)}')
        ->and(ProductLayout::TABS['sp_buy'][2])->toContain('bt_gap_d');
});

/* ═══════════════ 3. PLACED RIGHT: IN THE COLUMN, ONCE ═════════════════════ */

it('draws the one block inside the buy column, outside the cart form, at his position', function () {
    /*
     * THE DEFECT: two `.kbb-fbt`s (two #btTitle, fbt.js binding the first
     * only), or the block inside the cart form — whose checkboxes would then
     * submit with Add to cart.
     *
     * MUTATION NOTE, RUN: remove the `@unless ($kbbDsecBtRight)` wrapper
     * around the old include → RED (two blocks).
     * MUTATION NOTE, RUN: move the new include above `</form>` → RED.
     */
    $sun = riShop();
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    $html = riPage($sun);

    expect(substr_count($html, 'class="kbb-fbt bt'))->toBe(1)
        ->and(substr_count($html, 'id="btTitle"'))->toBe(1)
        ->and(substr_count($html, 'data-bt="'))->toBe(1);

    $box = riBuybox($html);
    expect($box)->toContain('class="kbb-fbt bt')
        ->and(strpos($box, 'class="kbb-fbt bt'))->toBeGreaterThan((int) strpos($box, '</form>'))
        ->and(strpos($box, 'class="kbb-fbt bt'))->toBeGreaterThan((int) strpos($box, 'pm-paychips'));

    expect($html)->toMatch('#<div class="wrap pdp-page [^"]* pdsb-on pdsb-f-title" style="[^"]*;--pdsb-o-auth:9;--pdsb-o-buytogether:10;--pdsb-o-trust:11;--pdsb-o-paychips:12">#')
        ->and($html)->not->toContain('pds-on');

    // Under the price: the same element, its order 3.
    riPost(['dsections' => ['buy' => riBuyWith('price')]])->assertOk();
    expect(riPage($sun))->toContain(';--pdsb-o-price:2;--pdsb-o-buytogether:3;--pdsb-o-short:4;');
});

it('gives the block a compact stacked layout in the column from 881px, measuring nothing', function () {
    /*
     * THE DEFECT: the laptop panel's cards-beside-the-button row squeezed into
     * a 450–780px column; or, at 881–1023px, the phone's full-bleed
     * `width:100vw` drawn inside the column — a page that scrolls sideways.
     *
     * MUTATION NOTE, RUN: delete `margin-inline:0;width:auto;max-width:none`
     * from the column rule → RED here; in Chromium at 1000px the block was
     * then 1000px wide from x=255 and the page scrolled sideways
     * (scrollWidth 1255).
     */
    $laptop = riMedia('(min-width:881px)');

    expect($laptop)->toContain('.pdp .buybox > .kbb-fbt.bt{margin-inline:0;width:auto;max-width:none;min-inline-size:0;border-radius:14px;padding:14px 0 16px}')
        ->and($laptop)->toContain('.pdp .buybox > .kbb-fbt .bt-row{display:block}')
        ->and($laptop)->toContain('.pdp .buybox > .kbb-fbt .bt-card{flex:0 0 calc((100% - var(--bt-gap) * (var(--bt-per,4) - 1)) / var(--bt-per,4))}')
        ->and($laptop)->toContain('.pdp .buybox > .kbb-fbt .bt-foot{display:flex;flex-direction:column;gap:10px;max-width:none;padding:12px 14px 0}')
        ->and($laptop)->toContain('.pdp .buybox > .kbb-fbt.bt-more.is-peek .bt-card:first-child{animation:btpeek');

    // Every new rule needs the block to be a child of `.buybox`, which it is
    // only once he has moved it; and none of them measures anything.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
    $mine = substr($css, (int) strrpos(substr($css, 0, (int) strpos($css, 'BUY THESE TOGETHER IN THE RIGHT COLUMN.')), '/*'));
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $mine);
    preg_match_all('#([^{}]+)\{[^{}]*\}#', (string) preg_replace('#@media[^{]+\{#', '', $rules), $m);
    foreach ($m[1] as $selector) {
        foreach (explode(',', $selector) as $one) {
            expect(trim($one))->toContain('.buybox > .kbb-fbt');
        }
    }
    expect($mine)->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth');
});

/* ═══════════════ 4. THE PHONE IS TODAY'S PHONE ════════════════════════════ */

it('keeps the phone placement in both placements: same order, same margins, same full-bleed width, same switch', function () {
    /*
     * THE DEFECT: on a phone `.buybox` is display:contents, so the block is an
     * item of `.pdp-page` wherever it is in the DOM — but `.pdp-page .buybox >
     * *{max-width:100%}` has the same specificity as `.kbb-fbt.bt` and comes
     * later, so without the override the full-bleed panel is capped at the
     * column (346px of 390, a 22px white edge each side); and without the
     * order rule it falls to `order:999`, under the reviews.
     *
     * MUTATION NOTE, RUN: drop `;max-width:100vw` → RED here; in Chromium at
     * 390px the placed block was then 346px wide instead of 390 and the page
     * 15px shorter than the default one.
     * MUTATION NOTE, RUN: drop the `.pm-off-buytogether .buybox > .kbb-fbt`
     * rule → RED.
     */
    $phone = riMedia('(max-width:880px)');
    expect($phone)->toContain('.pdp-page > .kbb-fbt{order:var(--pm-o-buytogether,13);margin-block:var(--pm-dm-buytogether,0px) 0}')
        ->and($phone)->toContain('.pdp-page .buybox > .kbb-fbt{order:var(--pm-o-buytogether,13);margin-block:var(--pm-dm-buytogether,0px) 0;max-width:100vw}')
        ->and($phone)->toContain('.pm-off-buytogether .buybox > .kbb-fbt{display:none}')
        ->and($phone)->toContain('.pdp-page > .pdp,.pdp-page > .pdp > .buybox,.pdp-page .buybox > .kbb-cart-form{display:contents}')
        ->and($phone)->not->toContain('pdsb-')->not->toContain('--pl-bt-gap-d');

    // The phone half of the wrapper (Mobile sections' classes and properties)
    // is the same in both placements, and the block is the element straight
    // after the payment chips either way — the phone's DOM sequence too.
    $sun = riShop();
    $default = riPage($sun);
    riPost(['dsections' => ['buy' => riBuyWith('price'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    $placed = riPage($sun);

    $pm = function (string $html): array {
        preg_match('#<div class="wrap pdp-page ([^"]*)" style="([^"]*)">#', $html, $w);

        return [
            array_values(array_filter(explode(' ', $w[1]), fn ($c) => str_starts_with($c, 'pm-'))),
            array_values(array_filter(explode(';', $w[2]), fn ($p) => str_starts_with($p, '--pm-'))),
        ];
    };
    expect($pm($placed))->toBe($pm($default));

    $after = fn (string $html) => preg_replace('#\s+#', ' ', substr($html, (int) strpos($html, '<span>COD</span></div>'), 120));
    expect($after($default))->toContain('</div> </div> </div> <section class="kbb-fbt')
        ->and($after($placed))->toContain('</div> <section class="kbb-fbt');
});

/* ═══════════════ 5. THE SWITCHES STILL DECIDE ═════════════════════════════ */

it('honours the laptop switch, the Sections switch and the total row master switch in the column', function () {
    /*
     * THE DEFECT: switched off for laptops on Desktop sections (or on the
     * Sections tab) and still drawn in the column — the bundles bug Lane RG
     * fixed, back again at a new address; or RH's "Show the total and
     * buy-together discount" ignored once the block has moved.
     *
     * MUTATION NOTE, RUN: delete `.pdp-page.pd-off-buytogether .buybox >
     * .kbb-fbt{display:none}` → RED. (On the page the module's own `d-off`
     * also hides it — two locks, the shape RG gave every section.)
     */
    expect(riMedia('(min-width:881px)'))->toContain('.pdp-page.pd-off-buytogether .buybox > .kbb-fbt{display:none}')
        ->and(riMedia('(min-width:881px)'))->toContain('.pdp-page .d-off{display:none !important}');

    $sun = riShop();
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();

    // Desktop sections' laptop switch: the wrapper says so, the section carries d-off.
    riPost(['dsections' => ['laptop' => ['buytogether' => false]]])->assertOk();
    $off = riPage($sun);
    expect($off)->toMatch('#<div class="wrap pdp-page [^"]*pd-off-buytogether#')
        ->and(riBuybox($off))->toMatch('#<section class="kbb-fbt bt[^"]*d-off#')
        // Switched off on laptops, it is not the first block for the gap rule either.
        ->and(app(ProductDesktopSections::class)->firstBuy(['buytogether' => true]))->toBe('title');
    riPost(['dsections' => ['laptop' => ['buytogether' => true]]])->assertOk();

    // Hidden everywhere on the Sections tab: not drawn at all, in either place.
    $all = app(ProductSections::class)->all();
    riPost(['sections' => collect($all)->map(fn ($v, $k) => ['key' => $k, 'desktop' => $k === 'fbt' ? false : $v['desktop'], 'mobile' => $k === 'fbt' ? false : $v['mobile']])->values()->all()])->assertOk();
    if (app(ProductSections::class)->hidden('fbt')) {
        expect(riPage($sun))->not->toContain('class="kbb-fbt');
    }
    riPost(['sections' => collect($all)->map(fn ($v, $k) => ['key' => $k, 'desktop' => $v['desktop'], 'mobile' => $v['mobile']])->values()->all()])->assertOk();

    // RH's master switch: off (shipped) → no total row; on → the row, in the column.
    expect(riBuybox(riPage($sun)))->toContain('class="kbb-fbt bt')->not->toContain('bt-sumrow');
    riPost(['together' => ['options' => ['discount_on' => true]]])->assertOk();
    expect(riBuybox(riPage($sun)))->toContain('<div class="bt-sumrow">');
});

it('names the block for the first-block gap rule only when this product draws it', function () {
    /*
     * THE DEFECT: dragged to the very top of the column on a product with no
     * matches, the block is not drawn — so the title must lose its gap, not
     * keep a 20px hole above it.
     */
    $svc = app(ProductDesktopSections::class);
    app(SettingsService::class)->set('pdpds_buy_order', ['buytogether', ...ProductDesktopSections::defaultBuyOrder()]);
    riFlush();
    $svc = app(ProductDesktopSections::class);

    expect($svc->firstBuy(['buytogether' => true]))->toBe('buytogether')
        ->and($svc->firstBuy(['buytogether' => false]))->toBe('title')
        ->and(riMedia('(min-width:881px)'))->toContain('.pdp-page.pdsb-f-buytogether .buybox > .kbb-fbt{margin-block-start:0}');
});

/* ═══════════════ 6. NO NEW QUERY ══════════════════════════════════════════ */

it('renders the placed page with exactly the queries of the default page', function () {
    /*
     * THE DEFECT: a lookup per request to decide where the block goes.
     *
     * MUTATION NOTE, RUN: read `pdpds_buy_order` with a fresh
     * DB::table('settings') query in buyTogetherRight() → RED (+1).
     */
    $sun = riShop();
    $count = function () use ($sun): int {
        riPage($sun); // warm
        Cache::flush();
        riFlush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/product/'.$sun->slug.'/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $default = $count();
    riPost(['dsections' => ['buy' => riBuyWith('auth'), 'order' => ['details', 'reviews', 'related']]])->assertOk();
    expect($count())->toBe($default);
});

/* ═══════════════ 7. THE ADMIN SCREEN ══════════════════════════════════════ */

it('lets only Buy these together cross between the lists, with a button and the arrow keys too', function () {
    /*
     * THE DEFECT: a screen that could drag any full-width block into the buy
     * column (a key the server refuses — "Could not save"), or one that loses
     * the block on Reset (both lists' defaults leave it out of the buy list).
     *
     * Driven for real in Chromium by tools/ri-shots.cjs (step "admin"): the
     * drag, ↓ three times through both lists, the button, Save — recorded in
     * docs/ri-shots/MEASUREMENTS.json → admin.
     *
     * MUTATION NOTE, RUN: drop `if (key !== MOVABLE ...) return;` in place()
     * → RED.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-desktop-sections-screen.blade.php'));

    expect($screen)->toContain("var MOVABLE = 'buytogether';")
        ->and($screen)->toContain("if (key !== MOVABLE || !LISTS[name]) return;")
        ->and($screen)->toContain("if (key !== MOVABLE || !into) return;")
        ->and($screen)->toContain("if (DRAG !== MOVABLE || !listOf(k)) return;")
        ->and($screen)->toContain('data-pds-move=')
        ->and($screen)->toContain('LISTS = shipped();')
        // The preview moves the ONE node, never clones it.
        ->and($screen)->toContain('box.appendChild(bt)')
        ->and($screen)->toContain('pdp.after(bt)')
        ->and($screen)->not->toContain('cloneNode')
        ->and($screen)->not->toContain('getBoundingClientRect');
});

/** The real screen partial in a page with the console's globals stubbed. */
function riScreenPage(array $payload): string
{
    $frame = '<!doctype html><html><body><div class="wrap pdp-page"><div class="crumb">c</div><div class="pdp"><div class="gallery">g</div>'
        .'<div class="buybox"><div class="pm-sec pm-title"><h1>t</h1></div><div class="pm-sec pm-price"><p>p</p></div>'
        .'<form class="cart kbb-cart-form"><div class="pts-stack"><p>a</p></div></form><div class="pm-sec pm-paychips"><span>x</span></div></div></div>'
        .'<section class="kbb-fbt bt"><h2 id="btTitle">Buy these together</h2></section><section class="sec pm-sec pm-details"><h2>d</h2></section>'
        .'<section class="sr">r</section></div></body></html>';
    $boot = '<script>'
        .'window.PP={dsections:'.json_encode($payload).',sections:[{key:"fbt",desktop:true,mobile:true}]};window.PPTAB="sections";'
        .'function escHtml(s){return String(s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;"}[c];});}'
        .'var escAttr=escHtml;function ppBase(){return "/admin-api/product-page";}function uToken(){return "t";}'
        .'function renderProductPage(){window.paintProductPage(true);}'
        .'window.paintProductPage=function(){if(!document.querySelector("#ppStrip .ectabs")){document.getElementById("ppStrip").innerHTML=\'<div class="ectabs"><button data-pmstab>Mobile sections</button></div>\';}};'
        .'window.fetch=function(u,o){window.__posted=JSON.parse(o.body);return Promise.resolve({status:200,json:function(){return Promise.resolve({ok:true,saved:3,dsections:PP.dsections});}});};'
        .'</script>';

    return '<!doctype html><html><head><meta charset="utf-8">'.$boot.'</head><body>'
        .'<div id="ppShell"><div id="ppStrip"></div><p id="ppLede"></p><div id="ppActs"></div><span id="ppDirty"></span><button id="ppSave">Save changes</button>'
        .'<div id="ppCol"></div><iframe data-ppframe="desktop" style="width:900px;height:300px" srcdoc="'.e($frame).'"></iframe></div>'
        .view('admin.partials.product-desktop-sections-screen')->render()
        .'</body></html>';
}

it('drags Buy these together between the two lists in a real browser, and nothing else crosses', function () {
    /*
     * THE DEFECT: the owner drags the block into the Buy column and it does
     * not go — or another block goes with it, or the preview draws it twice,
     * or Save posts it in both lists (a 422), or Reset loses it.
     *
     * Runs the REAL partial in Chromium (tests/browser/lane-ri-desktop-drag
     * .cjs): HTML5 drag with the mouse, the ↑ / ↓ buttons and keys, the
     * "Move to right column" button, Save and Reset. No server — about two
     * seconds — so it runs whenever node, playwright and Chromium are present.
     *
     * MUTATION NOTE, RUN: put back RG's same-list-only guard in dropOn()
     * (`if (!name || listOf(target) !== name) return;`) → RED (afterDrop still
     * has it under the columns).
     * MUTATION NOTE, RUN: drop `+ (into === 'under' ? 1 : 0)` → RED (dragged
     * back down onto Reviews it lands above Reviews, not below).
     * MUTATION NOTE, RUN: remove the node move from paintFrames() → RED (the
     * preview frame keeps the block under the columns).
     */
    $chrome = (string) env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if (! is_file($chrome) || $node === '' || ! is_dir(base_path('node_modules/playwright'))) {
        test()->markTestSkipped('browser half skipped: needs node, node_modules/playwright and Chromium at '.$chrome);
    }

    $file = storage_path('framework/testing/ri-screen-'.getmypid().'.html');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, riScreenPage(app(ProductDesktopSections::class)->payload()));

    $cmd = 'RI_PAGE='.escapeshellarg($file).' RI_CHROME='.escapeshellarg($chrome).' RI_PW='.escapeshellarg(base_path('node_modules/playwright'))
        .' node '.escapeshellarg(base_path('tests/browser/lane-ri-desktop-drag.cjs')).' 2>/dev/null';
    $raw = (string) shell_exec($cmd);
    @unlink($file);
    $r = json_decode($raw, true);

    expect($r)->toBeArray()
        ->and($r['error'] ?? null)->toBeNull()
        ->and($r['ok'])->toBeTrue()
        ->and($r['errors'])->toBe([]);

    $buy = ProductDesktopSections::defaultBuyOrder();
    $under = ProductDesktopSections::defaultOrder();
    $home = ['buy' => $buy, 'under' => $under];
    expect($r['start'])->toBe($home)
        ->and($r['frameStart'])->toMatchArray(['count' => 1, 'parent' => 'pdp-page']);

    // Reviews cannot cross: no mark, no move.
    expect($r['reviewsMarks']['over'])->toBe([])->and($r['afterReviewsDrag'])->toBe($home);

    // Up onto Trust lines: the line on Trust's top edge, and it lands above Trust.
    expect($r['btMarks'])->toBe(['over' => ['trust'], 'after' => []])
        ->and($r['afterDrop'])->toBe(['buy' => riBuyWith('auth'), 'under' => ['details', 'reviews', 'related']])
        ->and($r['frameAfterDrop']['count'])->toBe(1)
        ->and($r['frameAfterDrop']['parent'])->toBe('buybox')
        ->and($r['frameAfterDrop']['cls'])->toContain('pdsb-on')
        ->and($r['frameAfterDrop']['vars'])->toContain('--pdsb-o-buytogether: 10');

    // Back down onto Reviews: the line on Reviews' bottom edge, and below it.
    expect($r['backMarks'])->toBe(['over' => ['reviews'], 'after' => ['reviews']])
        ->and($r['afterDropBack'])->toBe(['buy' => $buy, 'under' => ['details', 'reviews', 'buytogether', 'related']])
        ->and($r['frameAfterBack']['count'])->toBe(1)
        ->and($r['frameAfterBack']['parent'])->toBe('pdp-page')
        ->and($r['frameAfterBack']['cls'])->not->toContain('pdsb-on');

    // ↑ ↑ to the top of the full-width list; ↑ again: last in the Buy column.
    expect($r['afterUp2'])->toBe($home)
        ->and($r['afterUp3'])->toBe(['buy' => [...$buy, 'buytogether'], 'under' => ['details', 'reviews', 'related']]);
    // ↓ on the handle from the bottom of the Buy column: first under the columns.
    expect($r['afterKeyDown'])->toBe($home);
    // The button: under Authenticity, and it now offers the way back.
    expect($r['afterButton'])->toBe(['buy' => riBuyWith('auth'), 'under' => ['details', 'reviews', 'related']])
        ->and(trim((string) $r['buttonLabel']))->toBe('Move below the columns')
        ->and($r['frameAfterButton']['parent'])->toBe('buybox');

    // Save posts it in ONE list — the body the server's validatePlacement() accepts.
    expect($r['posted']['dsections']['order'])->toBe(['details', 'reviews', 'related'])
        ->and($r['posted']['dsections']['buy'])->toBe(riBuyWith('auth'));
    riPost(['dsections' => ['order' => $r['posted']['dsections']['order'], 'buy' => $r['posted']['dsections']['buy']]])->assertOk();

    // Reset: home, under the columns, and the preview with it.
    expect($r['afterReset'])->toBe($home)
        ->and($r['frameAfterReset']['parent'])->toBe('pdp-page');
});
