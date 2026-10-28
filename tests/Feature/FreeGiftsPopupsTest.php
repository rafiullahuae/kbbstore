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
    gbxSave(['mode' => 'testing']);

    $mine = gbxBox(GBX_TESTER);
    // What it looked like with the box marked "gbx-pA", the popup's own class:
    // the popup's 100vw phone width landed on the box, 390px in a 300px panel.
    expect($mine)->not->toMatch('/<fieldset class="[^"]*gbx-p[A-D]/')->toContain(' gbx gbx-dA')->toContain('class="gbx-cr"')->toContain('popover')
        ->and(gbxBox(GBX_STRANGER))->not->toContain('gbx');
});

it('in Live shows them to every shopper', function () {
    gbxBands();
    gbxSave(['mode' => 'live']);

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
    expect([$x['pop'], $x['glow'], $x['crit']])->toBe(['A', 'none', '1'])
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

    gbxSave(['mode' => 'live']);
    $on3 = gbxQueries();
    // Measured: 2 queries Off and 2 On -- the one statement for every band's
    // gifts replaces the box's own (the brief allows +1; it costs 0).
    expect($on3)->toBe($base);

    DB::table('free_gift_range_items')->delete();
    DB::table('free_gift_ranges')->delete();
    gbxBands(15);
    gbxSave(['mode' => 'live']);
    expect(gbxQueries())->toBe($on3);
});

it('saves from the admin, refuses a value that is not an option, and fails closed', function () {
    // MUTATION: drop Rule::in on pop -- red (the 422).
    gbxSave(null);
    $owner = AdminUser::create(['name' => 'GBX', 'email' => 'gbx-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['pop' => 'Z'])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['glow' => '<b>'])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'on'])->assertStatus(422);

    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'testing', 'pop' => 'D', 'glow' => 'G1', 'crit' => '2', 'crit_on' => false])->assertOk();
    expect($r->json('config.extras'))->toBe(['mode' => 'testing', 'pop' => 'D', 'crit_on' => false, 'crit' => '2', 'glow' => 'G1'])
        // The one Testing list, untouched (it already had the owner's IP).
        ->and($r->json('config.testers'))->toBe([['ip' => GBX_TESTER, 'label' => 'Owner']])
        // The free gifts Mode is its own switch.
        ->and($r->json('config.mode'))->toBe('live');

    $support = AdminUser::create(['name' => 'GBX S', 'email' => 'gbx-s-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'support']);
    test()->actingAs($support, 'admin')->postJson('/admin-api/free-gifts/extras', ['mode' => 'live'])->assertForbidden();
    expect(test()->postJson('/admin-api/free-gifts/extras', ['mode' => 'live'])->status())->toBeIn([401, 403]);
    expect(app(FreeGifts::class)->freshConfig()['extras']['mode'])->toBe('testing');
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
