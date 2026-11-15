<?php

declare(strict_types=1);

/*
 * GIFTS CRITERIA AS SIMPLE ROWS (Lane GCR). The owner, 15 November:
 *
 *   "we have the gifts criteria button inside the box, give us option to open
 *    as same popup, but with same section which we have on homepage, only the
 *    rows having gifts icons etc, without any arrow button or further clicks.
 *    just simple display rows, same as on our homepage. give us the switch to
 *    show only rows or complete tabs as we have currently."
 *
 * and then: "by default, keep the rows which we have on homepage without any
 * arrow or further clicks." Marketing -> Free gifts -> Overview -> Gift box
 * popups -> "Gifts Criteria popup shows": Simple rows (default) | Complete tabs.
 * What would go wrong on the shop without each case is in its comment.
 */

use App\Models\Product;
use App\Models\Setting;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
});

function gcrGift(string $name, string $kind): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 3500, 'sale_price' => 2900, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 50, 'gift_kind' => $kind,
    ]);
}

/** Two bands; the second holds $n gifts. */
function gcrBands(int $n = 3): void
{
    $low = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => 29999, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 0, 'enabled' => true,
        'need_mask' => 2, 'need_mini' => 0, 'need_full' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $low, 'product_id' => gcrGift('Low Mask', 'mask')->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary', 'sort' => 0]);
    $high = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 30000, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 1, 'enabled' => true,
        'need_mask' => $n - 1, 'need_mini' => 0, 'need_full' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('free_gift_range_items')->insert(['range_id' => $high, 'product_id' => gcrGift('Senka Whip', 'full')->id, 'quantity' => 1, 'kind' => 'full', 'role' => 'primary', 'sort' => 0]);
    for ($i = 1; $i < $n; $i++) {
        DB::table('free_gift_range_items')->insert(['range_id' => $high, 'product_id' => gcrGift('Mask ' . $i, 'mask')->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'primary', 'sort' => $i]);
    }
}

function gcrSave(array $extras): void
{
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live', 'testers' => [], 'extras' => $extras]);
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(FreeGifts::class)->compile();
}

/** The box as the cart panel draws it for an AED 400 basket, and the queries it cost (box() + render). */
function gcrBox(string $lang = 'en', ?int &$queries = null): string
{
    app()->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.9']));
    app()->setLocale($lang);
    SettingsService::forgetMemo();
    Setting::flushMap();
    $fg = app(FreeGifts::class);
    $fg->freshConfig();
    $fg->config();
    app(SettingsService::class)->get(FreeGifts::KEY);
    $q = 0;
    DB::listen(function () use (&$q) { $q++; });
    $html = view('partials.free-gifts.box', $fg->box(['subtotal' => 40000, 'item_count' => 1], 'AE'))->render();
    $queries = $q;

    return $html;
}

/** The Gifts Criteria popover's own markup. */
function gcrPopover(string $html): string
{
    $at = strpos($html, '<div class="gbx-cp');
    expect($at)->not->toBeFalse();

    return substr($html, $at);
}

it('ships at Simple rows, as the owner asked, and keeps Complete tabs as the other choice', function () {
    // Defect: without the setting the popup only ever showed the tabs (two
    // levels of clicks: a tab, then sideways arrows) -- the owner asked for
    // the homepage's rows by default. MUTATION: EXTRAS crit_show 'tabs' -- red.
    expect(FreeGifts::EXTRAS['crit_show'])->toBe('rows')
        ->and(FreeGifts::EXTRA_CRIT_SHOWS)->toBe(['rows', 'tabs'])
        ->and(FreeGifts::extrasOf([])['crit_show'])->toBe('rows')
        ->and(FreeGifts::extrasOf(['crit_show' => 'tabs'])['crit_show'])->toBe('tabs');
});

it('draws the homepage rows inside the same popover: amount, words, FREE, gift photos -- no tabs, no arrows, nothing to click', function () {
    // Defect: the popover in rows mode would still carry the radio tabs, the
    // swipe panes or a chevron, i.e. "further clicks". MUTATION: drop the
    // rows branch in extras.blade.php -- red (no gbx-rw, gbx-tabs present).
    gcrBands(8);
    gcrSave(['mode' => 'live', 'crit' => '3']);
    $html = gcrBox();
    $pop = gcrPopover($html);

    expect($pop)->toContain('class="gbx-cp gbx-cR" popover role="dialog" aria-label="Gifts Criteria"')
        ->toContain('<div class="gbx-rw">')
        ->toContain('<span class="gbx-ra">AED 100 – 299</span>')
        ->toContain('<span class="gbx-ra">AED 300+</span>')
        ->toContain('<span class="gbx-rf">')
        ->toContain('class="gbx-x"')
        ->not->toContain('gbx-tabs')->not->toContain('type="radio"')->not->toContain('gbx-tp')
        ->not->toContain('gbx-1')->not->toContain('gbx-2')
        ->and(substr_count($pop, '<div class="gbx-r">'))->toBe(2)
        // The two bands' photos: 1 (low) + 6 of 8 (high), "+2"/"+3" past them, the quantity badge on the low mask.
        ->and(substr_count($pop, '<span class="gbx-rt">'))->toBe(7)
        ->and($pop)->toContain('<span class="gbx-rm d">+2</span>')->toContain('<span class="gbx-rm m">+3</span>')->toContain('<b>×2</b>');
    // The only buttons in the popover are its close: no arrows, no tab to press.
    expect(substr_count($pop, '<button'))->toBe(1)->and($pop)->not->toContain('onclick="var p')->not->toContain('<input');
    // Only the rows' CSS is printed, not the tabs design's.
    expect($html)->toContain('.gbx-rw{background:#fff;border:1.5px solid #2e8f62')->not->toContain('.gbx-tabs{');
    // The button that opens it is the same one, in the same place.
    expect($html)->toContain('<button type="button" class="gbx-cr" aria-haspopup="dialog"');
});

it('draws the rows in Arabic with the amounts and FREE in Arabic', function () {
    // Defect: an /ar shopper reading English amounts in an RTL popup.
    gcrBands(3);
    gcrSave(['mode' => 'live']);
    $pop = gcrPopover(gcrBox('ar'));
    expect($pop)->toContain('aria-label="شروط الهدايا"')->toContain('<span class="gbx-ra">300+ د.إ</span>')->not->toContain('AED');
});

it('keeps Complete tabs byte for byte as before: the chosen design, its CSS, no rows', function () {
    // Defect: switching back to tabs leaving rows markup or rows CSS behind, or
    // losing the design. MUTATION: print the rows CSS unconditionally -- red.
    gcrBands(3);
    foreach (['1' => '<ol class="gbx-1">', '2' => '<ol class="gbx-2">', '3' => 'class="gbx-tabs"'] as $d => $mark) {
        gcrSave(['mode' => 'live', 'crit' => $d, 'crit_show' => 'tabs']);
        $html = gcrBox();
        expect($html)->toContain('class="gbx-cp gbx-c' . $d . '"')->toContain($mark)
            ->not->toContain('gbx-cR')->not->toContain('gbx-rw')->not->toContain('.gbx-r{');
    }
});

it('reads a junk value as the default, and the admin refuses it', function () {
    // Defect: a stored "<script>" or "grid" reaching the class list or switching
    // the popup off. A select stores one of its own options or the default.
    expect(FreeGifts::extrasOf(['crit_show' => '"><script>'])['crit_show'])->toBe('rows')
        ->and(FreeGifts::extrasOf(['crit_show' => 7])['crit_show'])->toBe('rows')
        ->and(FreeGifts::extrasOf(['crit_show' => ['tabs']])['crit_show'])->toBe('rows');

    gcrBands(3);
    gcrSave(['mode' => 'live', 'crit' => '3', 'crit_show' => 'grid']);
    expect(gcrBox())->toContain('gbx-cR')->not->toContain('grid"');

    $owner = \App\Models\AdminUser::create(['name' => 'GCR', 'email' => 'gcr-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['crit_show' => 'grid'])->assertStatus(422);
    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/extras', ['crit_show' => 'tabs'])->assertOk();
    expect($r->json('config.extras.crit_show'))->toBe('tabs');
});

it('costs the same queries in rows as in tabs, and the same with 3 gifts as with 15', function () {
    // Defect: the rows reading the homepage section (FreeGifts::home()) again,
    // a second pass over the ranges per box. They come from the bands the box
    // already loaded. MUTATION: call app(FreeGifts::class)->home() in
    // extras-rows -- red.
    gcrBands(3);
    gcrSave(['mode' => 'live', 'crit' => '3', 'crit_show' => 'tabs']);
    gcrBox('en', $tabs);
    gcrSave(['mode' => 'live', 'crit' => '3', 'crit_show' => 'rows']);
    gcrBox('en', $rows3);
    expect($rows3)->toBe($tabs);

    DB::table('free_gift_range_items')->delete();
    DB::table('free_gift_ranges')->delete();
    gcrBands(15);
    gcrSave(['mode' => 'live', 'crit' => '3', 'crit_show' => 'rows']);
    gcrBox('en', $rows15);
    expect($rows15)->toBe($rows3);
});

it('carries the control in the admin card, saving through the existing endpoint', function () {
    // Defect: a setting with no switch the owner can find. Path: Marketing ->
    // Free gifts -> Overview -> Gift box popups -> "Gifts Criteria popup shows".
    $screen = file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain("pick('crit_show', X_CRIT_SHOWS, 'Gifts Criteria popup shows'")
        ->toContain("[['rows', 'Simple rows (as on the homepage)'], ['tabs', 'Complete tabs (the design below)']]");
});
