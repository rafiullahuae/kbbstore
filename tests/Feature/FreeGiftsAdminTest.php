<?php

declare(strict_types=1);

/**
 * Growth & Marketing -> Free gifts, the redesigned screen (Lane FG2).
 *
 * The engine and the shop are FreeGiftsTest's. This file pins what the
 * redesign added on the server -- Overview's numbers, the create form's photo
 * and price, the gap and overlap words, the checklist's "open this range" --
 * and the screen's own promises: one debounced search, ours() before every
 * late repaint, no window.confirm, no timer left running. Each case says what
 * the defect would look like to the owner and carries a mutation note.
 */

use App\Http\Controllers\Admin\FreeGiftsController;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Services\FreeGifts;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

function fg2Admin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'FG2 ' . $role, 'email' => 'fg2-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function fg2Product(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

function fg2Range(int $min, ?int $max, array $gifts = [], bool $enabled = true): int
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'label_en' => 'Gifts', 'label_ar' => 'هدايا',
        'sort' => 0, 'enabled' => $enabled, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($gifts as $i => [$product, $qty]) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $product->id, 'quantity' => $qty, 'sort' => $i]);
    }

    return $id;
}

/** An order with gift lines [[Product, qty], ...], $daysAgo old. */
function fg2Order(array $gifts, string $status = 'processing', int $daysAgo = 1): Order
{
    $order = Order::create([
        'order_number' => 'FG2-' . Str::random(8), 'email' => 'buyer@example.com', 'status' => $status, 'currency' => 'AED',
        'subtotal' => 30000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'fee_total' => 0, 'total' => 30000,
        'payment_method' => 'cod', 'free_gift_count' => array_sum(array_map(fn ($g) => $g[1], $gifts)),
    ]);
    $order->items()->create(['name' => 'Snail Mucin Essence', 'quantity' => 1, 'unit_price' => 30000, 'subtotal' => 30000, 'total' => 30000]);
    foreach ($gifts as [$product, $qty]) {
        $order->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'is_gift' => true]);
    }
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDays($daysAgo)]);

    return $order;
}

/*
|------------------------------------------------------------------------------
| OVERVIEW'S NUMBERS -- GET /admin-api/free-gifts/stats
|------------------------------------------------------------------------------
*/

it('counts the last 30 days of real orders with gifts, the units, the most-given gift and the stock alerts', function () {
    /*
     * The defect: the Overview says the feature is working when it is not --
     * a failed payment or a two-month-old order counted as a gift given, or a
     * gift at 20 units not flagged although the screen says "20 or fewer".
     * MUTATION: drop ->whereIn('status', $real) in FreeGiftsController::stats(),
     * or change '<=' LOW_STOCK to '<'. RED.
     */
    $mask = fg2Product('Tea Tree Sheet Mask', ['manage_stock' => true, 'stock' => 400]);
    $toner = fg2Product('Toner Mini', ['manage_stock' => true, 'stock' => 20]);
    $balm = fg2Product('Glowy Balm', ['manage_stock' => true, 'stock' => 21]);
    $gone = fg2Product('Glow Set', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock']);
    fg2Range(100, 299, [[$mask, 2], [$toner, 1], [$balm, 1], [$gone, 1]]);

    fg2Order([[$mask, 2], [$toner, 1]]);
    fg2Order([[$mask, 3]], 'completed');
    fg2Order([[$toner, 9]], 'failed');           // never paid: not a gift given
    fg2Order([[$toner, 9]], 'processing', 45);   // outside the 30 days

    $r = test()->actingAs(fg2Admin(), 'admin')->getJson('/admin-api/free-gifts/stats')->assertOk();

    expect($r->json('orders'))->toBe(2)
        ->and($r->json('units'))->toBe(6)
        ->and($r->json('top'))->toBe(['product_id' => $mask->id, 'name' => 'Tea Tree Sheet Mask', 'units' => 5])
        ->and($r->json('low_stock'))->toBe(20)
        ->and($r->json('days'))->toBe(30)
        ->and(collect($r->json('stock_alerts'))->pluck('level', 'name')->all())->toBe(['Glow Set' => 'out', 'Toner Mini' => 'low']);
    // Allowlisted: nothing but these keys leaves the endpoint.
    expect(array_keys($r->json('stock_alerts.0')))->toBe(['id', 'name', 'stock', 'level']);
});

it('answers the stats in the same number of queries for 3 orders and for 40', function () {
    /*
     * The defect: Overview loading one query per order (or per gift line), so
     * the screen slows down exactly as the feature succeeds.
     * MUTATION: compute 'units' with Order::all()->each(fn ($o) => $o->gifts...). RED.
     */
    $mask = fg2Product('Mask');
    $mini = fg2Product('Mini', ['manage_stock' => true, 'stock' => 3]);
    fg2Range(100, null, [[$mask, 2], [$mini, 1]]);
    $owner = fg2Admin();

    $count = function () use ($owner) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/stats')->assertOk();
        $n = count(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/free_gift|order_items|\borders\b|\bproducts\b/', $q['query'])));
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 3) as $i) {
        fg2Order([[$mask, 2], [$mini, 1]]);
    }
    $three = $count();

    foreach (range(1, 37) as $i) {
        fg2Order([[$mask, 2], [$mini, 1]]);
    }
    $forty = $count();

    expect($three)->toBe($forty)->and($forty)->toBeLessThanOrEqual(4);
});

it('keeps the new GETs on freegifts.view and fails closed for a role without it', function () {
    /*
     * The defect: a support login reading order numbers off the Overview, or
     * a GET added under the prefix landing on a capability nobody holds.
     * MUTATION: map ['GET', 'admin-api/free-gifts/**'] to anything but freegifts.view. RED.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/free-gifts/stats'))->toBe('freegifts.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/free-gifts/brands'))->toBe('freegifts.view');

    test()->getJson('/admin-api/free-gifts/stats')->assertStatus(401);
    test()->actingAs(fg2Admin('support'), 'admin')->getJson('/admin-api/free-gifts/stats')->assertForbidden();
    test()->actingAs(fg2Admin('manager'), 'admin')->getJson('/admin-api/free-gifts/brands')->assertOk()->assertJsonStructure(['brands']);
});

/*
|------------------------------------------------------------------------------
| "NEW GIFT-ONLY PRODUCT"
|------------------------------------------------------------------------------
*/

it('creates a gift-only product with its photo, price and stock, and validates every field', function () {
    /*
     * The defect: a javascript: or data: address saved as the photo becomes an
     * <img src> on the admin and the shop; a negative stock or a missing name
     * makes a product the picker cannot show.
     * MUTATION: drop the 'regex' rule on image in createProduct(). RED.
     */
    $owner = fg2Admin();
    $brand = DB::table('brands')->insertGetId(['name' => 'Isntree', 'slug' => 'isntree-' . Str::random(4), 'created_at' => now(), 'updated_at' => now()]);
    $post = fn (array $b) => test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/products', $b);

    $post(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
    $post(['name' => 'Mask', 'image' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('image');
    $post(['name' => 'Mask', 'image' => '//evil.example/x.png'])->assertStatus(422)->assertJsonValidationErrors('image');
    $post(['name' => 'Mask', 'image' => 'data:image/png;base64,AAAA'])->assertStatus(422)->assertJsonValidationErrors('image');
    $post(['name' => 'Mask', 'price' => -1])->assertStatus(422)->assertJsonValidationErrors('price');
    $post(['name' => 'Mask', 'stock' => -1])->assertStatus(422)->assertJsonValidationErrors('stock');
    $post(['name' => 'Mask', 'brand_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('brand_id');
    expect(Product::query()->where('name', 'Mask')->exists())->toBeFalse();

    $r = $post(['name' => 'Mugwort Clay Mask Mini', 'brand_id' => $brand, 'stock' => 80, 'price' => 12.5, 'image' => 'https://shop.example/uploads/products/mugwort.webp'])->assertOk();
    $p = Product::query()->where('name', 'Mugwort Clay Mask Mini')->firstOrFail();

    expect($p->gift_only)->toBeTrue()
        ->and((bool) $p->is_visible)->toBeFalse()
        ->and((int) $p->price)->toBe(1250)
        ->and((int) $p->stock)->toBe(80)
        ->and((bool) $p->manage_stock)->toBeTrue()
        ->and($p->image)->toBe('https://shop.example/uploads/products/mugwort.webp')
        ->and((int) $p->brand_id)->toBe($brand)
        ->and($r->json('created.brand'))->toBe('Isntree')
        // The row the screen draws is the allowlist, never the model.
        ->and(array_keys($r->json('created')))->toBe(['id', 'name', 'brand', 'image', 'thumb', 'type', 'status', 'in_stock', 'stock', 'gift_only', 'price']);
});

it('refuses product create to a role without freegifts.manage', function () {
    /*
     * MUTATION: map ['*', 'admin-api/free-gifts'] (POST) to freegifts.view. RED.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/free-gifts/products'))->toBe('freegifts.manage');
    test()->postJson('/admin-api/free-gifts/products', ['name' => 'Sneaky Gift'])->assertStatus(401);
    test()->actingAs(fg2Admin('support'), 'admin')->postJson('/admin-api/free-gifts/products', ['name' => 'Sneaky Gift'])->assertForbidden();
    expect(Product::query()->where('name', 'Sneaky Gift')->exists())->toBeFalse();
});

it('narrows the picker to gift-only or in-stock products, and treats any other filter as all', function () {
    /*
     * The defect: "In stock" offering a sold-out product the shop would skip,
     * or a made-up filter value reaching the query.
     * MUTATION: drop the 'instock' ->when() in products(). RED.
     */
    $owner = fg2Admin();
    fg2Product('Zeta Sold Out', ['manage_stock' => true, 'stock' => 0]);
    fg2Product('Zeta Gift', ['gift_only' => true]);
    fg2Product('Zeta For Sale');
    $names = fn (string $f) => collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/products?q=zeta&filter=' . $f)->assertOk()->json('products'))->pluck('name')->sort()->values()->all();

    expect($names('gift'))->toBe(['Zeta Gift'])
        ->and($names('instock'))->toBe(['Zeta For Sale', 'Zeta Gift'])
        ->and($names('nonsense'))->toBe(['Zeta For Sale', 'Zeta Gift', 'Zeta Sold Out']);
});

/*
|------------------------------------------------------------------------------
| OVERLAP AND GAP WORDS, THE CHECKLIST'S LINKS
|------------------------------------------------------------------------------
*/

it('names the range a new one overlaps, in the words the screen shows as you type', function () {
    /*
     * The defect: the server's refusal and the inline message disagreeing, so
     * the owner reads one thing while typing and another on Save.
     * MUTATION: change 'Overlaps ' back to 'This range overlaps '. RED.
     */
    $owner = fg2Admin();
    fg2Range(300, 499);
    $r = test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/ranges', [
        'min' => 450, 'max' => 600, 'label_en' => 'Masks', 'label_ar' => 'أقنعة', 'enabled' => true, 'gifts' => [],
    ])->assertStatus(422);

    expect($r->json('errors.min.0'))->toStartWith('Overlaps AED 300 – 499');

    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect($screen)->toContain("'Overlaps ' + amount(b.min, b.max)")
        ->and($screen)->toContain("'Gap: ' + amount(g.from, g.to) + ' gives nothing.'");
});

it('reports the amounts no enabled range covers, between two that do', function () {
    /*
     * The defect: a basket of AED 300.50 gets nothing although the owner
     * believes his ranges are continuous (299 then 301).
     * MUTATION: drop the `$min > $reach + 1` check in FreeGiftsController::gaps(). RED.
     */
    $row = fn (int $min, ?int $max, bool $on = true) => (object) ['min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'enabled' => $on];

    expect(FreeGiftsController::gaps([$row(100, 299), $row(301, 499)]))->toBe([['from' => 300, 'to' => 300]])
        ->and(FreeGiftsController::gaps([$row(100, 299), $row(300, 499), $row(500, null)]))->toBe([])
        // A switched-off range covers nothing, so it opens a gap.
        ->and(FreeGiftsController::gaps([$row(100, 299), $row(300, 499, false), $row(500, null)]))->toBe([['from' => 300, 'to' => 499]])
        // Below the first range is the shop's choice, not a gap; nothing after "and above".
        ->and(FreeGiftsController::gaps([$row(2000, null), $row(100, 299)]))->toBe([['from' => 300, 'to' => 1999]]);

    fg2Range(100, 299);
    fg2Range(301, 499);
    test()->actingAs(fg2Admin(), 'admin')->getJson('/admin-api/free-gifts')->assertOk()
        ->assertJsonPath('gaps', [['from' => 300, 'to' => 300]])
        ->assertJsonPath('low_stock', 20);
});

it('names the range each checklist item is about, so Overview can open it', function () {
    /*
     * The defect: "Things to check" says what is wrong but not where, and
     * the owner hunts through five ranges for the one with no gifts.
     * MUTATION: drop 'range' => (int) $r->id from the no-gifts item. RED.
     */
    $empty = fg2Range(100, 299);
    $gone = fg2Product('Glow Set', ['manage_stock' => true, 'stock' => 0, 'stock_status' => 'outofstock']);
    $short = fg2Range(300, 499, [[$gone, 1]]);

    $items = collect(app(FreeGifts::class)->checklist());
    expect($items->firstWhere('level', 'alert')['range'])->toBe($empty)
        ->and($items->firstWhere('level', 'warn')['range'])->toBe($short)
        ->and($items->firstWhere('level', 'warn')['text'])->toContain('out of stock — skipped until restocked');

    DB::table('free_gift_ranges')->delete();
    expect(app(FreeGifts::class)->checklist()[0])->toBe(['level' => 'alert', 'text' => 'No range is on yet, so no basket gets a gift. Add a spend range and switch it on.', 'range' => null]);
});

/*
|------------------------------------------------------------------------------
| THE SCREEN
|------------------------------------------------------------------------------
*/

it('renders the screen in the console and keeps its promises: debounced search, ours(), no confirm(), no polling', function () {
    /*
     * The defects this pins, each one seen elsewhere in this console: a
     * request per keystroke; a late answer painting over another screen
     * (2.60.488, Marketing Emails); window.confirm, which the console cannot
     * show; a timer that never stops; the owner's wording reaching toast()'s
     * innerHTML unescaped.
     * MUTATION: change 300 to 0 in typed(), drop `if (!c || !ours()) return;`
     * from render(), or call window.toast(m) without esc(). RED.
     */
    $html = test()->actingAs(fg2Admin(), 'admin')->get('/admin')->assertOk()->getContent();
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));

    expect(substr_count($html, "var SCREEN = 'freegifts';"))->toBe(1);

    expect($screen)->toContain("p.timer = setTimeout(function () { fetchPicker(q.trim()); }, 300);")
        ->and($screen)->toContain("if (q.trim().length < 2) {")
        ->and($screen)->toContain('if (!c || !ours()) return;')
        ->and($screen)->toContain('if (!ours() || !resultsNode || !resultsNode.isConnected) return;')
        ->and($screen)->toContain('function say(m, kind) { try { window.toast(esc(m), kind); } catch (e) {} }')
        ->and($screen)->toContain("clearTimeout(p.timer);")
        ->and($screen)->not->toContain('window.confirm')
        ->and($screen)->not->toContain('setInterval')
        ->and($screen)->not->toContain('getBoundingClientRect')
        ->and($screen)->not->toContain('offsetHeight');

    // Each part's own Save (no global bar), the inline delete confirm, the picker's three filters.
    expect($screen)->toContain("cfgSaveRow('display')")
        ->and($screen)->toContain("cfgSaveRow('home')")
        ->and($screen)->not->toContain('fg-bar')
        ->and($screen)->toContain("text: 'Delete range'")
        ->and($screen)->toContain("[['all', 'All'], ['gift', 'Gift-only'], ['instock', 'In stock']]");
});

/*
|------------------------------------------------------------------------------
| LANE FG3 -- range tabs, instant search, never blocked
|------------------------------------------------------------------------------
*/

/** The Free gifts script exactly as the console renders it. */
function fg3Rendered(): string
{
    $html = test()->actingAs(fg2Admin(), 'admin')->get('/admin')->assertOk()->getContent();
    $at = strpos($html, "var SCREEN = 'freegifts';");
    expect($at)->not->toBeFalse();

    return substr($html, $at, strpos($html, '</script>', $at) - $at);
}

it('searches as the owner types, through one debounced path, with no Search button and nothing on Enter', function () {
    /*
     * The defect: the owner's screenshot of the old screen has a "Search"
     * button, and an Enter that searched at once beside the debounce made two
     * paths, so an earlier slower answer could land after a newer one and
     * show the wrong products.
     * MUTATION: add `if (e.key === 'Enter') fetchPicker(q.value.trim())`, a
     * `text: 'Search'` button, or drop `ctl.abort()` from stopSearch(). RED.
     */
    $js = fg3Rendered();

    expect($js)->not->toContain("text: 'Search'")
        ->and($js)->not->toMatch('/>\s*Search\s*</')
        ->and($js)->not->toContain("type: 'submit'")
        ->and($js)->not->toContain('<form')
        // ONE debounced call site for a typed question, at 300 ms, 2+ characters.
        ->and(substr_count($js, 'fetchPicker(q.'))->toBe(1)
        ->and($js)->toContain("p.timer = setTimeout(function () { fetchPicker(q.trim()); }, 300);")
        ->and($js)->toContain('if (q.trim().length < 2) {')
        // Enter does nothing but stop the browser.
        ->and($js)->toContain("q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });")
        // Stale answers: aborted, and dropped if they arrive anyway.
        ->and($js)->toContain('new AbortController()')
        ->and($js)->toContain('p.ctl.abort()')
        ->and($js)->toContain("if (st.picker !== p || mine !== p.seq || (e && e.name === 'AbortError')) return;")
        ->and($js)->toContain("p.loading ? 'Searching…'");

    // Every product search on this screen goes through the picker: the products
    // endpoint is asked from exactly one place.
    expect(substr_count($js, "'/products?q='"))->toBe(1);
});

it('shows one range at a time behind range tabs, each with its own Save, and never disables Testing or Live', function () {
    /*
     * The owner, 22 Oct: a tab per range, only the selected editor showing,
     * a Save per range that saves only that range, unsaved edits kept per
     * range with a dot on the tab, the tab remembered, and Testing / Live
     * always selectable.
     * MUTATION: put `|| blocked` back into the Mode button's disabled, or
     * have saveRange() loop over dirtyRanges(). RED.
     */
    $js = fg3Rendered();

    expect($js)->toContain("class: 'fg-rtabs', role: 'tablist', 'aria-label': 'Ranges'")
        ->and($js)->toContain("'Add range'")
        ->and($js)->toContain("class: 'fg-rt-dirty', role: 'img', 'aria-label': 'Unsaved changes'")
        ->and($js)->toContain("class: 'fg-rt-warn', role: 'img'")
        ->and($js)->toContain("text: st.busy && dirty ? 'Saving…' : 'Save range'")
        ->and($js)->toContain("var d = await api(k === 'new' ? 'POST' : 'PUT', k === 'new' ? '/ranges' : '/ranges/' + k, rangeBody(x));")
        ->and($js)->toContain("say('Saved ' + name + '.');")
        ->and($js)->not->toContain('saveAll')
        ->and($js)->not->toContain('dirtyRanges().forEach')
        // remembered, and a blocked store cannot break the screen
        ->and($js)->toContain('function remember(id) { try { window.localStorage.setItem(LS_RANGE, String(id)); } catch (e) {} }')
        ->and($js)->toContain('function recalled() { try { return window.localStorage.getItem(LS_RANGE); } catch (e) { return null; } }')
        // Overview opens a range's tab
        ->and($js)->toContain("text: i.range ? 'Open range' : 'Add a range'")
        ->and($js)->toContain("st.tab = 'ranges'; selectRange(id); render();")
        // never blocked
        ->and($js)->toContain('disabled: !can() || st.busy, onclick: function () {')
        ->and($js)->not->toContain("'Needed'")
        ->and($js)->not->toContain('Before switching on')
        ->and($js)->toContain("card('Things to check'")
        ->and($js)->toContain("' — skipped until restocked'");

    // A late answer still cannot paint over another screen, and nothing polls.
    expect($js)->toContain('if (!c || !ours()) return;')
        ->and($js)->not->toContain('setInterval');
});
