<?php

declare(strict_types=1);

/*
 * GIFT BOX POPUPS (Lane GBX). The owner, 28 October, having played with the
 * previews:
 *
 *   "okay, i need a switch also with TESTING phase, and my our IPs, please
 *    prepare, i didn't upload the last patch, keep turn off by default this
 *    feature."
 *
 * Marketing -> Free gifts -> Overview -> Gift box popups: Off / Testing / Live
 * (Testing = the free gifts Testing IP list, the one list), the photo popup
 * A-D, the Gifts Criteria popup 1-3 with its own switch, the glow none/G1-G4.
 * What would go wrong on the shop without each case is in its comment.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const GBX_TESTER = '203.0.113.7';
const GBX_STRANGER = '198.51.100.9';

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function gbxGift(string $name, string $kind, int $reg = 3500, ?int $sale = 2900): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => $reg, 'sale_price' => $sale, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 50, 'gift_kind' => $kind,
    ]);
}

/** Two bands; the second holds $n gifts (full size first in the photos). */
function gbxBands(int $n = 3): void
{
    $low = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29999, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true,
        'need_mask' => 2, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $low, 'product_id' => gbxGift('Low Mask', 'mask', 900, 700)->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0]);

    $high = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 30000, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 1, 'enabled' => true,
        'need_mask' => $n - 1, 'need_mini' => 0, 'need_full' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $high, 'product_id' => gbxGift('Senka Whip', 'full')->id, 'quantity' => 1, 'kind' => 'full', 'role' => 'primary', 'sort' => 0]);
    for ($i = 1; $i < $n; $i++) {
        DB::table('free_gift_range_items')->insert(['range_id' => $high, 'product_id' => gbxGift('Mask ' . $i, 'mask', 900, null)->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'primary', 'sort' => $i]);
    }
}

/** Save the free gifts row: gifts Live, one tester, and (unless null) the popups' settings. */
function gbxSave(?array $extras): void
{
    $row = ['mode' => 'live', 'testers' => [['ip' => GBX_TESTER, 'label' => 'Owner']]] + ($extras === null ? [] : ['extras' => $extras]);
    app(SettingsService::class)->set(FreeGifts::KEY, $row);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(FreeGifts::class)->compile();
}

/** The box as the cart panel draws it, for a basket of AED 400 seen from $ip. */
function gbxBox(string $ip = GBX_STRANGER, string $lang = 'en'): string
{
    app()->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]));
    app()->setLocale($lang);
    SettingsService::forgetMemo();
    Setting::flushMap();
    $fg = app(FreeGifts::class);
    $fg->freshConfig();

    return view('partials.free-gifts.box', $fg->box(['subtotal' => 40000, 'item_count' => 1], 'AE'))->render();
}

/** Queries box() makes, from a fresh request. */
function gbxQueries(string $ip = GBX_STRANGER): int
{
    app()->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]));
    SettingsService::forgetMemo();
    Setting::flushMap();
    $fg = app(FreeGifts::class);
    $fg->freshConfig();
    $fg->config();
    app(SettingsService::class)->get(FreeGifts::KEY);
    $q = 0;
    DB::listen(function () use (&$q) { $q++; });
    $fg->box(['subtotal' => 40000, 'item_count' => 1], 'AE');

    return $q;
}

it('ships Off, and Off draws the box byte for byte as before -- with or without the setting saved', function () {
    // What it would look like: a shop that never asked for popups gets new
    // buttons, glows or a heavier box the moment the package is applied.
    // MUTATION: default EXTRAS['mode'] to 'live' -- red.
    gbxBands();
    gbxSave(null);
    $before = gbxBox(GBX_TESTER);
    expect(FreeGifts::EXTRAS['mode'])->toBe('off')
        ->and(app(FreeGifts::class)->freshConfig()['extras'])->toBe(FreeGifts::EXTRAS)
        ->and($before)->not->toContain('gbx');

    gbxSave(['mode' => 'off', 'pop' => 'B', 'crit_on' => true, 'crit' => '3', 'glow' => 'G2']);
    expect(gbxBox(GBX_TESTER))->toBe($before);
});

it('in Testing shows the popups only to an address on the free gifts Testing list', function () {
    // The owner tries designs live without any shopper seeing them.
    // MUTATION: return $x for testing without testerMatch() -- red (the stranger sees them).
    gbxBands();
    gbxSave(['mode' => 'testing', 'pop' => 'A']);

    $mine = gbxBox(GBX_TESTER);
    // What it looked like with the box marked "gbx-pA", the popup's own class:
    // the popup's 100vw phone width landed on the box, 390px in a 300px panel.
    expect($mine)->not->toMatch('/<fieldset class="[^"]*gbx-p[A-D]/')->toContain(' gbx gbx-dA')->toContain('class="gbx-cr"')->toContain('popover')
        ->and(gbxBox(GBX_STRANGER))->not->toContain('gbx');
});

it('in Live shows them to every shopper', function () {
    gbxBands();
    gbxSave(['mode' => 'live', 'pop' => 'A']);

    expect(gbxBox(GBX_STRANGER))->toContain(' gbx gbx-dA')->toContain('class="gbx-cr"');
});

it('draws one popup per photo, in the photos\' order, with brand, cut price, price and FREE', function () {
    // MUTATION: drop the 'reg'/'now' keys from extrasBox() -- red (no prices).
    gbxBands(3);
    gbxSave(['mode' => 'live', 'pop' => 'B']);
    $html = gbxBox();

    expect(substr_count($html, 'class="gbx-pp gbx-pB" popover'))->toBe(3)
        // Each photo a real button that opens its popup. What it looked like
        // when Blade swallowed the tile (an @endphp right before {!!): the
        // template's own source -- "@__raw_block_1__{!! '" -- printed in the box.
        ->and(substr_count($html, '<button type="button" class="fgb-t" aria-label="'))->toBe(3)
        ->and($html)->toContain('<button type="button" class="fgb-t" aria-label="Senka Whip" aria-haspopup="dialog" onclick="var p=this.closest(&#039;.fgb&#039;).querySelectorAll(&#039;.gbx-pp&#039;)[0];')
        ->and($html)->not->toContain('{!!')->not->toContain('__raw_block')->not->toContain('$gbx')
        // Full size first: the Senka Whip's popup is the first.
        ->and(strpos($html, 'popover role="dialog" aria-label="Senka Whip"'))->toBeLessThan(strpos($html, 'popover role="dialog" aria-label="Mask 1"'))
        ->and($html)->toContain('<s>AED 35</s> <b>AED 29</b>')->toContain('<span class="gbx-badge">FREE</span>');
});

it('draws C as cards inside the box, and the criteria in each design, in both languages', function () {
    gbxBands(3);
    gbxSave(['mode' => 'live', 'pop' => 'C', 'crit' => '2']);
    $html = gbxBox();
    expect(substr_count($html, '<div class="gbx-k" hidden'))->toBe(3)
        ->and(substr_count($html, 'aria-expanded="false" onclick="var b=this.closest('))->toBe(3)
        ->and($html)->not->toContain('{!!')->not->toContain('__raw_block')
        ->and($html)->not->toContain('class="gbx-pp')
        ->and($html)->toContain('class="gbx-cp gbx-c2" popover')
        // Every band, the basket's own marked.
        ->and($html)->toContain('2 Masks')->toContain('1 Full Size Product + 2 Masks')->toContain('<em>Your basket</em>');

    gbxSave(['mode' => 'live', 'crit' => '3']);
    $ar = gbxBox(GBX_STRANGER, 'ar');
    expect($ar)->toContain('شروط الهدايا')->toContain('type="radio"')->toContain('منتج بالحجم الكامل + قناعان');
    app()->setLocale('en');

    gbxSave(['mode' => 'live', 'crit_on' => false]);
    expect(gbxBox())->not->toContain('class="gbx-cr')->not->toContain('class="gbx-cp');
});

it('adds the chosen glow as a fixed class, CSS only', function () {
    gbxBands();
    gbxSave(['mode' => 'live', 'glow' => 'G3']);
    $html = gbxBox();
    expect($html)->toContain(' gbx-g3"')->toContain('.gbx-g3 .fgb-t{box-shadow:');

    gbxSave(['mode' => 'live', 'glow' => 'none']);
    expect(gbxBox())->not->toMatch('/ gbx-g\d/');
});

it('reads a damaged setting as the default, never as a class', function () {
    gbxBands();
    gbxSave(['mode' => 'live', 'pop' => '"><script>', 'glow' => 'G9', 'crit' => 7]);
    $x = app(FreeGifts::class)->freshConfig()['extras'];
    expect([$x['pop'], $x['glow'], $x['crit']])->toBe(['off', 'none', '1'])
        ->and(gbxBox())->not->toContain('<script>');
});

it('costs nothing Off, at most one query On, and the same with 3 gifts as with 15', function () {
    // The speed rule: the popups' whole cost is one statement for every
    // band's gifts, flat however many. MUTATION: skip the pool in products()
    // -- red (3 against 2).
    gbxBands(3);
    gbxSave(null);
    $base = gbxQueries();
    gbxSave(['mode' => 'off']);
    expect(gbxQueries())->toBe($base);

    gbxSave(['mode' => 'live', 'pop' => 'A']);
    $on3 = gbxQueries();
    // Measured: 2 queries Off and 2 On -- the one statement for every band's
    // gifts replaces the box's own (the brief allows +1; it costs 0).
    expect($on3)->toBe($base);

    DB::table('free_gift_range_items')->delete();
    DB::table('free_gift_ranges')->delete();
    gbxBands(15);
    gbxSave(['mode' => 'live', 'pop' => 'A']);
    expect(gbxQueries())->toBe($on3);
});

it('saves from the admin, refuses a value that is not an option, and fails closed', function () {
    // MUTATION: drop Rule::in on pop -- red (the 422).
    gbxSave(null);
    $owner = AdminUser::create(['name' => 'GBX', 'email' => 'gbx-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['pop' => 'Z'])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['glow' => '<b>'])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'on'])->assertStatus(422);
    // (2.60.624) Range boxes: one of its own options only. MUTATION: drop Rule::in on tabs -- red.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['tabs' => 'T3'])->assertStatus(422);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'testing', 'pop' => 'D', 'glow' => 'G1', 'crit' => '2', 'crit_on' => false])->assertOk();
    expect($r->json('config.extras'))->toBe(['mode' => 'testing', 'pop' => 'D', 'crit_on' => false, 'crit' => '2', 'glow' => 'G1', 'panel_gap' => 12, 'link_m' => 10, 'link_d' => 10, 'tabs' => 'T5'])
        // The one Testing list, untouched (it already had the owner's IP).
        ->and($r->json('config.testers'))->toBe([['ip' => GBX_TESTER, 'label' => 'Owner']])
        // The free gifts Mode is its own switch.
        ->and($r->json('config.mode'))->toBe('live');

    $support = AdminUser::create(['name' => 'GBX S', 'email' => 'gbx-s-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'support']);
    test()->actingAs($support, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'live'])->assertForbidden();
    expect(test()->postJson('/admin-api/free-gifts/extras', ['mode' => 'live'])->status())->toBeIn([401, 403]);
    expect(app(FreeGifts::class)->freshConfig()['extras']['mode'])->toBe('testing');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['tabs' => 'T1'])->assertOk();
    expect(app(FreeGifts::class)->freshConfig()['extras']['tabs'])->toBe('T1');
});

it('puts the card on the Overview, saving through its own endpoint', function () {
    $screen = file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect(substr_count($screen, "wrap.appendChild(extrasCard(d));"))->toBe(1)
        ->and($screen)->toContain("api('POST', '/extras', body)")
        ->and(substr_count(file_get_contents(base_path('routes/free-gifts-admin.php')), "Route::post('/extras', [FreeGiftsController::class, 'extras']);"))->toBe(1);
});

it('opens with no layout measuring and nothing fetched: popovers and inline handlers only', function () {
    gbxBands(3);
    foreach (['A', 'C'] as $p) {
        gbxSave(['mode' => 'live', 'pop' => $p]);
        $html = gbxBox();
        expect($html)->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth')->not->toContain('fetch(')->not->toContain('<script');
    }
});

/*
|------------------------------------------------------------------------------
| 2.60.620 -- the owner on 2.60.619: "I want the Gifts Criteria button to simple
| text same like View all, but keep the gift icon along with it. also turn off
| the click on the products images. and prominent the cross icon and little big
| size on the gifts criteria popup, and when the popup changes the size as per
| the range selection or view. put a super slight animation, just a bit. also i
| want little spacing above the gift box in the cart panel. plz allow this
| control".
|------------------------------------------------------------------------------
*/

it('ships the photo popup Off: the photos are plain pictures again, the criteria link stays', function () {
    // What it looked like: every photo a button opening a popup he no longer
    // wants. MUTATION: put EXTRAS['pop'] back to 'A' -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live']);
    $html = gbxBox();
    expect(FreeGifts::EXTRAS['pop'])->toBe('off')
        ->and($html)->toContain(' gbx gbx-doff"')
        // ▲ 2.60.623: the photos open the LIST again (next test), never a popup.
        ->and(substr_count($html, '<span class="fgb-t">'))->toBe(3) // the 3 in the list
        ->and($html)->not->toContain('aria-haspopup="dialog" onclick="var p=this.closest(&#039;.fgb&#039;).querySelectorAll')->not->toContain('class="gbx-pp')->not->toContain('class="gbx-k"')
        ->and($html)->toContain('class="gbx-cr"');
});

it('opens the gift list when a photo in the box is tapped, exactly as View all does', function () {
    // ▲ 2.60.623, the owner: "the products thumbnails inside the gift box
    // should give list upon click on them like before." What it looked like:
    // tapping a photo did nothing; only "View all" opened the list. Each photo
    // is now a button with View all's own handler (keyboard focus moves to
    // Hide; a tap leaves no ring). MUTATION: render the off-branch photos as
    // plain <span class="fgb-t"> again -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live']);
    $html = gbxBox();
    preg_match('#<div class="fgb-th"[^>]*>(.*?)<span class="gbx-ft">#s', $html, $m);
    expect($m)->not->toBeEmpty()
        ->and(substr_count($m[1], '<button type="button" class="fgb-t" aria-label="'))->toBe(3)
        ->and(substr_count($m[1], 'aria-expanded="false" onclick="var f=this.closest(\'.fgb\');f.querySelector(\'.fgb-th\').hidden=true;f.querySelector(\'.fgb-ls\').hidden=false;if(!event.detail)'))->toBe(3)
        ->and($html)->toContain('.gbx .fgb-th button.fgb-t{padding:0;')
        ->toContain('.gbx .fgb-th button.fgb-t:focus:not(:focus-visible){outline:0}');
});

it('draws "Gifts Criteria" as a text link exactly like View all, with its gift icon', function () {
    // What it looked like: a green pill beside a plain "View all".
    // MUTATION: restore the pill's border/background/radius -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live']);
    $html = gbxBox();
    preg_match('/\.gbx \.gbx-cr\{([^}]*)\}/', $html, $cr);
    preg_match('/\.fgb \.fgb-tg\{([^}]*)\}/', $html, $tg);
    foreach (['border:0', 'background:none', 'padding:0', 'color:#2e8f62', 'font:inherit', 'font-size:var(--fg-lk,11.5px)', 'font-weight:700', 'min-height:32px', 'cursor:pointer'] as $same) {
        expect($tg[1])->toContain($same)->and($cr[1])->toContain($same);
    }
    expect($cr[1])->not->toContain('border-radius')->not->toContain('#f3faf6')
        ->and($html)->toMatch('#<button type="button" class="gbx-cr"[^>]*><svg viewBox="0 0 24 24" width="13" height="13"#');
});

it('gives the criteria popup a big, clearly visible close, mirrored in Arabic by inset-inline-end', function () {
    // MUTATION: drop the .gbx-cp .gbx-x rule -- red (a 36px ghost on white).
    gbxBands(3);
    foreach (['1', '2', '3'] as $d) {
        gbxSave(['mode' => 'live', 'crit' => $d]);
        // ▲ 2.60.621: in em with the popup's fluid size, never under 36px.
        expect(gbxBox())->toContain('.gbx-cp .gbx-x{top:.75em;inset-inline-end:.75em;width:2.7em;height:2.7em;min-width:36px;min-height:36px;background:#221A20;color:#fff')
            ->toContain('.gbx-cp .gbx-x span{font-size:1.35em}')
            ->toContain('.gbx-cp .gbx-x:focus-visible{outline:3px solid #C6395F');
    }
});

it('keeps the tabs\' popup one fixed size: two rows of gifts that scroll sideways, a slight cross-fade only', function () {
    // ▲ 2.60.621, the owner: "I want popup height fixed ... if there's more
    // than 6 products, the products grid should display two rows ... with
    // scroll left right ... keep the fixed height which is on the 500 range".
    // What it looked like: the sheet growing on AED 2,000+ (558px against
    // 302px at AED 100 at 390 wide). Measured after: 413px on every tab at
    // 390, 340 at 320, 441 at 430 and 1280 (docs/gbx-feature-shots/v4).
    // MUTATION: put the grid back on grid-template-columns:repeat(4,...) -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->toContain('.gbx-grid{display:grid;grid-template-rows:repeat(2,7.1em);grid-template-columns:repeat(var(--gc,3),calc((100% - 2.3 * .7em) / 3.3));')
        ->toContain('overflow-x:auto;overflow-y:hidden;')
        ->toContain('.gbx-tp .gbx-wd{margin:.1em .1em .55em;font-size:.95em;font-weight:700;line-height:1.35;height:2.7em;overflow:hidden;')
        ->toContain('.gbx-tp>div{animation:gbx-fade .18s ease-out}')
        ->toContain('@media (prefers-reduced-motion:reduce){.gbx-tp>div{animation:none}}')
        ->toContain('data-i="0"><div><p class="gbx-wd">')
        ->not->toContain('grid-template-rows:0fr')
        // (2.60.626) One scrollIntoView per Gifts Criteria link, on open, for the basket range only.
        ->not->toContain('getBoundingClientRect')->and(substr_count($html, 'scrollIntoView'))->toBe(substr_count($html, 'class="gbx-cr"'));
});

it('fills the first row of gifts before the second, on phone and computer', function () {
    // ▲ 2.60.622, the owner: "The first row must be filled. then second" and
    // "on desktop also it should fill first the first row". What it looked
    // like: the grid flowed by COLUMN, so a range with 5 gifts showed three
    // across the top and two underneath on a phone, and on a computer the
    // 4th column sat empty beside them. Now each range's grid carries its own
    // column count -- half its gifts, rounded up, never under the 3 a phone
    // shows (--gc) or the 4 a computer shows (--gd) -- and fills row by row.
    // Measured after (Chromium, 5 ranges of 2/3/4/6/7): 4 gifts read 3+1 at
    // 390 and 4+0 at 1280; 7 read 4+3 and scroll sideways at 390.
    // MUTATION: put grid-auto-flow:column back, or drop the style="--gc..." -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->not->toContain('grid-auto-flow:column')
        ->toContain('@media (min-width:601px){.gbx-grid{grid-template-columns:repeat(var(--gd,4),calc((100% - 3.3 * .7em) / 4.3))}}');
    preg_match_all('#<div class="gbx-grid(?: gbx-s3)?(?: gbx-s4)?" style="--gc:(\d+);--gd:(\d+)">(.*?)</div></div></div>#s', $html, $m, PREG_SET_ORDER);
    expect($m)->not->toBeEmpty();
    foreach ($m as [$all, $gc, $gd, $cells]) {
        $n = substr_count($cells, 'class="gbx-gc"');
        expect((int) $gc)->toBe(max(3, (int) ceil($n / 2)))->and((int) $gd)->toBe(max(4, (int) ceil($n / 2)));
    }
});

it('opens the criteria popup still, and shows 3.2 gifts across when a range scrolls', function () {
    // ▲ 2.60.623, the owner: "remove the slide animation while opening ... jxt
    // reverse that popup opening slide animation. AND if any range has scroll
    // ... then it should show 3.2 grid." What it looked like: the sheet rose
    // 6px as it faded in, and a scrolling range showed 3.3 gifts (4.3 on a
    // computer). Scrolls = over 6 gifts on a phone, over 8 on a computer.
    // MUTATION: put .gbx-cp:popover-open{animation:gbx-in ...} back, or drop
    // the gbx-s3 class -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->not->toContain('gbx-in')->not->toContain('translateY(6px)')
        ->toContain('.gbx-tp>div{animation:gbx-fade .18s ease-out}')
        ->toContain('@media (max-width:600px){.gbx-grid.gbx-s3{grid-template-columns:repeat(var(--gc,3),calc((100% - 3 * .7em) / 3.2))}}')
        ->toContain('@media (min-width:601px){.gbx-grid.gbx-s4{grid-template-columns:repeat(var(--gd,4),calc((100% - 3 * .7em) / 3.2))}}');
    preg_match_all('#<div class="gbx-grid( gbx-s3)?( gbx-s4)?" style="--gc:\d+;--gd:\d+">(.*?)</div></div></div>#s', $html, $m, PREG_SET_ORDER);
    expect($m)->not->toBeEmpty();
    foreach ($m as $g) {
        $n = substr_count($g[3], 'class="gbx-gc"');
        expect($g[1] !== '')->toBe($n > 6)->and($g[2] !== '')->toBe($n > 8);
    }
});

it('draws the range boxes as the owner picked (B2 + T5) by default, and T1 brings the green fill back', function () {
    // ▲ 2.60.624, the owner, picking from the preview: "B2 + T5 ... finalize
    // this design." What it looked like before: "Your basket" sat inside the
    // green-filled box as a second line. Now: a white box with the gift count
    // under the amount, a green outline and a tick on the chosen one, and
    // "Your basket" a pink pill sitting on the box's top line. Default T5 as
    // he asked; Gift box popups -> Range boxes -> T1 is the way back.
    // MUTATION: put EXTRAS['tabs'] back to 'T1' -- red; drop the gbx-t5 class -- red.
    expect(FreeGifts::EXTRAS['tabs'])->toBe('T5')
        ->and(FreeGifts::extrasOf(['tabs' => 'T9'])['tabs'])->toBe('T5')
        ->and(FreeGifts::extrasOf(['tabs' => 'T1'])['tabs'])->toBe('T1');
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->toContain('<div class="gbx-3 gbx-t5">')
        ->toContain('.gbx-t5 .gbx-tabs i{position:absolute;top:0;left:50%;transform:translate(-50%,-55%);')
        ->toContain('background:#C6395F;color:#fff;padding:.3em .7em;border-radius:999px')
        ->toContain('.gbx-t5 .gbx-tabs input:checked+span{background:#fff;color:#221A20;border:2px solid #2e8f62;')
        ->toContain('.gbx-t5 .gbx-tabs input:checked~.gbx-tk{display:block;')
        ->toContain('<b class="gbx-tk" aria-hidden="true"></b></label>');
    // Every box carries its band's own count under the amount.
    expect(substr_count($html, '</small></span><b class="gbx-tk"'))->toBe(substr_count($html, '<input type="radio"'));

    gbxSave(['mode' => 'live', 'crit' => '3', 'tabs' => 'T1']);
    $t1 = gbxBox();
    expect($t1)->toContain('<div class="gbx-3">')->not->toContain('.gbx-t5 ')
        ->toContain('.gbx-tabs input:checked+span{background:#2e8f62;border-color:#2e8f62;color:#fff}')
        ->toContain('.gbx-tabs small,.gbx-tk{display:none}');
});

it('opens the range row with the "Your basket" range in the middle, and the row still scrolls freely', function () {
    // ▲ 2.60.626, the owner on 2.60.625's sticky box: "i neeed it iin the
    // middle. and then user can scroll. but when open the popup. it should be
    // in the middle, but if user fall in first range, then on the left is
    // fine". What 2.60.624 looked like: the basket's range past the row's
    // right edge, its pill cut to "Your b"; 2.60.625 pinned it to the edge.
    // Now opening the popup asks the browser once to bring the basket's
    // range to the centre of its own row (scrollIntoView, inline:'center',
    // block:'nearest') -- nothing is read or measured, nothing repeats, and a
    // first or second range simply stays at the start. Measured (Chromium,
    // 320-1280, EN/AR, basket in each of the 5 ranges): middle ranges 0px off
    // centre; the popup, its body and the page never moved; the row scrolls
    // end to end afterwards.
    // MUTATION: drop the scrollIntoView from the Gifts Criteria onclick -- red;
    // put position:sticky back on .gbx-yb -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->toContain("p.showPopover();p.querySelector('.gbx-x').focus();var y=p.querySelector('.gbx-yb');if(y)y.scrollIntoView({block:'nearest',inline:'center'})")
        ->not->toContain('gbx-yb{position:sticky')->not->toContain('scrollLeft')->not->toContain('getBoundingClientRect');
    // Exactly the basket's own range carries the hook, and it is the one with the pill.
    expect(substr_count($html, '<label class="gbx-yb">'))->toBeLessThanOrEqual(1);
    if (str_contains($html, '<label class="gbx-yb">')) {
        expect($html)->toMatch('#<label class="gbx-yb"><input type="radio"[^>]*><span>[^<]*<i>#');
    }
});

it('leaves no box around Gifts Criteria after a tap or a click, and keeps the ring for the keyboard', function () {
    // ▲ 2.60.622, the owner: "the gifts criteria button is leaving an outline
    // box upon click. it should not be." What it looked like: closing the
    // popup handed focus back to the link by script, which on a phone drew
    // the green focus ring round it until the next tap elsewhere. Now focus is
    // handed back only when the popup was opened from the keyboard
    // (event.detail is 0), and a pointer focus never draws the outline.
    // Measured after (Chromium, 320-1280, EN/AR): outline none after a click
    // and close; solid after Enter and Escape.
    // MUTATION: put p.gbxFrom=this back, or drop :focus:not(:focus-visible) -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->toContain('p.gbxFrom=event.detail?null:this;')
        ->not->toContain('p.gbxFrom=this;')
        ->toContain('.gbx .gbx-cr:focus:not(:focus-visible){outline:0}')
        ->toContain('white-space:nowrap;-webkit-tap-highlight-color:transparent}')
        ->toContain('.gbx .gbx-cr:focus-visible{outline:2px solid #2e8f62');
});

it('never shifts the tabs\' popup sideways: each radio stays inside its own tab, and the popup clips', function () {
    // The owner's two phone screenshots, 2.60.620: tapping "AED 1,000 - 1,599"
    // slid the whole sheet left, the X in the middle, a white strip on the
    // right. Cause: the radio was position:absolute against the POPUP, so it
    // sat far right inside the scrolled tab strip; tapping a tab focused it
    // and the browser scrolled the popup itself (overflow:hidden still
    // scrolls) to show it -- measured: the popup's scrollLeft 114px (-64 in
    // Arabic), 0 after the fix on every tab at 320-1280.
    // MUTATION: drop position:relative from the label -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '3']);
    $html = gbxBox();
    expect($html)->toContain('.gbx-tabs label{position:relative;flex:none;cursor:pointer}.gbx-tabs input{position:absolute;inset:0;width:100%;height:100%;opacity:0;margin:0;pointer-events:none}')
        ->toMatch('/\.gbx-cp\{[^}]*overflow:hidden;overflow:clip\}/');
});

it('scales the whole criteria popup with the screen, fonts included', function () {
    // "percentage wise it should reduce overall including content font size
    // etc if low res phone": 11.5px at 320, 14px at 390, 15px from 417.
    // MUTATION: a fixed font-size on .gbx-cp -- red.
    gbxBands(3);
    gbxSave(['mode' => 'live', 'crit' => '1']);
    expect(gbxBox())->toContain('.gbx-cp{font-size:15px;font-size:clamp(11px,3.6vw,15px);width:min(37em,calc(100vw - 48px));')
        ->toContain('.gbx-c1,.gbx-c2{height:min(31em,88vh)}');
});

it('puts 12px above the box in the cart panel by default, his to change 0-40, popups on or off', function () {
    // What it looked like: the box tight under the last bag line.
    // MUTATION: EXTRAS['panel_gap'] back to 0 -- red.
    gbxBands(3);
    gbxSave(null);
    expect(gbxBox())->toContain('#cart{--fg-cg:12px}#cart fieldset.fgb{margin-top:calc(18px + var(--fg-cg))}');

    gbxSave(['panel_gap' => 30]);
    expect(gbxBox())->toContain('#cart{--fg-cg:30px}');
    gbxSave(['panel_gap' => 0]);
    expect(gbxBox())->not->toContain('--fg-cg');

    expect(FreeGifts::extrasOf(['panel_gap' => 99])['panel_gap'])->toBe(40)
        ->and(FreeGifts::extrasOf(['panel_gap' => '"><b>'])['panel_gap'])->toBe(12)
        ->and(FreeGifts::extrasOf(['panel_gap' => -5])['panel_gap'])->toBe(0);

    // An order's own box (thank-you, My account) is not in the panel: nothing.
    gbxSave(null);
    $order = view('partials.free-gifts.box', ['kbbFgRows' => [['name' => 'G', 'brand' => '', 'image' => '', 'quantity' => 1, 'value' => 0]], 'kbbFgOpen' => true])->render();
    expect($order)->not->toContain('--fg-cg');

    $owner = AdminUser::create(['name' => 'GBX2', 'email' => 'gbx2-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['panel_gap' => 41])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['panel_gap' => 'abc'])->assertStatus(422);
    expect(test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['panel_gap' => 20, 'pop' => 'off'])->assertOk()->json('config.extras.panel_gap'))->toBe(20);
});

it('turns the photo click off on a shop that already saved the popups, and touches nothing else', function () {
    // His row has pop "A" from 2.60.619. MUTATION: skip the write -- red.
    gbxSave(['mode' => 'testing', 'pop' => 'B', 'glow' => 'G2', 'crit' => '3']);
    (require database_path('migrations/2027_12_31_130000_gift_box_photos_off.php'))->up();
    SettingsService::forgetMemo();
    Setting::flushMap();
    $x = app(FreeGifts::class)->freshConfig();
    expect($x['extras'])->toBe(['mode' => 'testing', 'pop' => 'off', 'crit_on' => true, 'crit' => '3', 'glow' => 'G2', 'panel_gap' => 12, 'link_m' => 10, 'link_d' => 10, 'tabs' => 'T5'])
        ->and($x['mode'])->toBe('live')->and($x['testers'])->toBe([['ip' => GBX_TESTER, 'label' => 'Owner']]);

    // No popups saved yet: the row is left exactly as it was.
    gbxSave(null);
    $before = DB::table('settings')->where('key', FreeGifts::KEY)->value('value');
    (require database_path('migrations/2027_12_31_130000_gift_box_photos_off.php'))->up();
    expect(DB::table('settings')->where('key', FreeGifts::KEY)->value('value'))->toBe($before);
});

/*
 * 2.60.621 -- the owner: "I want to reduce the font size of View all and Gifts
 * Criteria text. plz give controls." Overview -> Gift box popups -> Link text
 * size, phone and computer, 10-18px, 10 by default (was 11.5).
 */
it('draws View all, Hide and Gifts Criteria at 10px by default, phone and computer, popups on or off', function () {
    // What it looked like: 11.5px links he found too big.
    // MUTATION: EXTRAS['link_m'] / ['link_d'] back to 12 -- red; or the rule
    // back to font-size:11.5px -- red.
    gbxBands(3);
    gbxSave(null);
    $off = gbxBox();
    expect([FreeGifts::EXTRAS['link_m'], FreeGifts::EXTRAS['link_d']])->toBe([10, 10])
        ->and($off)->toContain('.fgb{--fg-lk:10px}@media (min-width:761px){.fgb{--fg-lk:10px}}.fgb .fgb-tg{border:0;background:none;padding:0;color:#2e8f62;font:inherit;font-size:var(--fg-lk,11.5px);')
        ->and($off)->not->toContain('font-size:11.5px;font-weight:700');

    gbxSave(['mode' => 'live', 'link_m' => 12, 'link_d' => 15]);
    $on = gbxBox();
    expect($on)->toContain('.fgb{--fg-lk:12px}@media (min-width:761px){.fgb{--fg-lk:15px}}')
        // The criteria link reads the same size, its icon scaling with it.
        ->toContain('.gbx .gbx-cr{display:inline-flex;align-items:center;gap:4px;border:0;background:none;padding:0;color:#2e8f62;font:inherit;font-size:var(--fg-lk,11.5px);')
        ->toContain('.gbx .gbx-cr svg{width:1.13em;height:1.13em;flex:none}');

    // An order's own box (thank-you, My account) carries the size too.
    $order = view('partials.free-gifts.box', ['kbbFgRows' => [['name' => 'G', 'brand' => '', 'image' => '', 'quantity' => 1, 'value' => 0]], 'kbbFgOpen' => true])->render();
    expect($order)->toContain('.fgb{--fg-lk:12px}');
});

it('keeps the link size a whole number from 10 to 18, refused otherwise', function () {
    expect(FreeGifts::extrasOf(['link_m' => 4, 'link_d' => 99]))->toMatchArray(['link_m' => 10, 'link_d' => 18])
        ->and(FreeGifts::extrasOf(['link_m' => '"}body{x', 'link_d' => null]))->toMatchArray(['link_m' => 10, 'link_d' => 10]);

    gbxSave(null);
    $owner = AdminUser::create(['name' => 'GBX3', 'email' => 'gbx3-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['link_m' => 9])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['link_d' => 19])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['link_d' => '12px'])->assertStatus(422);
    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['link_m' => 11, 'link_d' => 13])->assertOk();
    expect([$r->json('config.extras.link_m'), $r->json('config.extras.link_d')])->toBe([11, 13]);

    $screen = file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect(substr_count($screen, "linkField(x, ro, 'link_m', 'Link text size — phone (px)'), linkField(x, ro, 'link_d', 'Link text size — computer (px)')"))->toBe(1);
});
