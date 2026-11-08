<?php

declare(strict_types=1);

/**
 * Growth & Marketing -> Free gifts -> Ranges & gifts -> Templates (Lane GT,
 * the owner 8 Nov 2026): "save as template the whole gifts ranges in one
 * single template, and from the list i should be able to select and live
 * instantly ... with save button."
 *
 * Each case says what the defect would look like on the live shop and carries
 * a mutation note. Routes are mounted the way the integrator is told to
 * (Tests\Support\FreeGiftTemplatesRoutes), and stay correct once web.php has
 * them. Helpers are prefixed gtpl (one Pest process loads every file).
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\CartTracking\GiftClicks;
use App\Services\FreeGifts;
use App\Services\FreeGiftTemplates;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FreeGiftTemplatesRoutes;

beforeEach(function () {
    FreeGiftTemplatesRoutes::wire(app());
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

function gtplAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GT ' . $role, 'email' => 'gt-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function gtplProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'type' => 'simple', 'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

/** A range; $gifts = [[Product, qty, kind, role, ?variantId], ...]. */
function gtplRange(int $min, ?int $max, array $gifts, array $o = []): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(array_merge([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100,
        'label_en' => 'Gifts ' . $min, 'label_ar' => 'هدايا ' . $min, 'sort' => 0, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ], $o));
    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert([
            'range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'kind' => $g[2] ?? null,
            'role' => $g[3] ?? 'primary', 'variant_id' => $g[4] ?? null, 'sort' => $i,
        ]);
    }
    app(FreeGifts::class)->compile();

    return $id;
}

/** Everything a range and its gifts are, ids and timestamps left out. */
function gtplState(): array
{
    return app(FreeGiftTemplates::class)->snapshot()[0];
}

function gtplCompiled(): array
{
    SettingsService::forgetMemo();
    Setting::flushMap();

    $c = app(SettingsService::class)->get(FreeGifts::COMPILED);
    $c = is_array($c) ? $c : [];
    // ids are new after an apply by design; everything else must match.
    $c['ranges'] = array_map(static fn ($r) => array_diff_key($r, ['id' => 1]), $c['ranges'] ?? []);

    return $c;
}

function gtplApi(string $method, string $path, array $body = [], ?AdminUser $as = null)
{
    return test()->actingAs($as ?? gtplAdmin(), 'admin')->json($method, '/admin-api/free-gifts/templates' . $path, $body);
}

function gtplSave(string $name, ?AdminUser $as = null): int
{
    $r = gtplApi('POST', '', ['name' => $name], $as)->assertCreated();

    return (int) $r->json('id');
}

/** The owner's kind of set: three ranges, dates, both labels, criteria, a backup and a variation. */
function gtplRich(): array
{
    $mask = gtplProduct('Sheet Mask', ['gift_only' => true]);
    $mini = gtplProduct('Mini Toner', ['gift_only' => true]);
    $full = gtplProduct('Full Cream');
    $tint = gtplProduct('Lip Tint', ['type' => 'variable']);
    $pink = ProductVariant::create(['product_id' => $tint->id, 'sku' => 'GT-PINK-' . Str::random(4), 'price' => 3000, 'manage_stock' => false, 'stock_status' => 'instock', 'position' => 0]);

    gtplRange(0, 299, [[$mask, 2, 'mask', 'primary']]);
    gtplRange(300, 499, [[$mask, 3, 'mask', 'primary'], [$mini, 1, 'mini', 'backup'], [$tint, 1, null, 'primary', $pink->id]],
        ['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-12-31 00:00:00', 'need_mask' => 3, 'need_mini' => 0, 'need_full' => 1, 'sort' => 2, 'label_en' => 'Mid & more', 'label_ar' => 'متوسط']);
    gtplRange(500, null, [[$full, 1, 'full', 'primary'], [$mini, 2, 'mini', 'backup']], ['enabled' => false, 'sort' => 1]);

    return compact('mask', 'mini', 'full', 'tint', 'pink');
}

/*
|------------------------------------------------------------------------------
| SAVE AND MAKE LIVE
|------------------------------------------------------------------------------
*/

it('round-trips every range and gift exactly: dates, labels, criteria, backups, variations, order', function () {
    /*
     * The defect: a template that comes back "nearly" right -- a backup
     * turned main, a date or the Arabic label dropped, a variation lost --
     * so the shop gives the wrong gifts the moment he makes it live.
     * MUTATION: drop 'role' (or 'starts_at', or 'variant_id') from the insert
     * in FreeGiftTemplates::apply(). RED.
     */
    gtplRich();
    $before = gtplState();
    $compiled = gtplCompiled();
    expect($before)->toHaveCount(3);

    $id = gtplSave('Eid set');

    // He changes everything: one plain range instead.
    DB::table('free_gift_ranges')->delete();
    gtplRange(100, 199, [[gtplProduct('Other'), 1]]);
    expect(gtplState())->not->toBe($before);

    $r = gtplApi('POST', '/' . $id . '/apply')->assertOk();

    expect(gtplState())->toBe($before)
        ->and(gtplCompiled())->toBe($compiled)
        ->and($r->json('skipped'))->toBe(0)
        ->and($r->json('ranges'))->toBe(3)
        // the screen redraws from this one answer
        ->and($r->json('data.ranges'))->toHaveCount(3)
        ->and($r->json('data.revision'))->toBeString();
});

it('keeps the ranges it replaces as a "Before ..." template, and only the last five of those', function () {
    /*
     * The defect: one click wipes the ranges he had with no way back, or the
     * automatic copies pile up for ever.
     * MUTATION: remove the "1. The ranges being replaced" block from apply(),
     * or the prune after it. RED.
     */
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $a = gtplSave('Set A');
    DB::table('free_gift_ranges')->delete();
    gtplRange(400, null, [[$p, 4]]);
    $b = gtplSave('Set B');

    $r = gtplApi('POST', '/' . $a . '/apply')->assertOk();
    $auto = DB::table('free_gift_templates')->where('auto', true)->get();
    expect($auto)->toHaveCount(1)
        ->and($auto[0]->name)->toStartWith('Before Set A · ')
        ->and($r->json('before'))->toBe($auto[0]->name);

    // The automatic copy is the B ranges: making it live goes back.
    gtplApi('POST', '/' . $auto[0]->id . '/apply')->assertOk();
    expect(gtplState()[0]['min_fils'])->toBe(40000);

    for ($i = 0; $i < 7; $i++) {
        gtplApi('POST', '/' . ($i % 2 ? $a : $b) . '/apply')->assertOk();
    }
    expect(DB::table('free_gift_templates')->where('auto', true)->count())->toBe(FreeGiftTemplates::AUTO_KEEP)
        ->and(DB::table('free_gift_templates')->whereIn('id', [$a, $b])->count())->toBe(2)
        ->and(DB::table('free_gift_templates')->pluck('name')->unique()->count())->toBe(DB::table('free_gift_templates')->count());
});

it('is all or nothing: a failure half way leaves the live ranges, the compiled copy and the list as they were', function () {
    /*
     * The defect: the old ranges deleted, the new ones half written, and the
     * shop giving a partial set (or none) after an error.
     * MUTATION: take the DB::transaction() off apply(). RED.
     */
    gtplRich();
    $id = gtplSave('Eid set');
    DB::table('free_gift_ranges')->delete();
    $p = gtplProduct('Today');
    gtplRange(100, 199, [[$p, 1]]);
    $live = gtplState();
    $compiled = gtplCompiled();
    $templates = DB::table('free_gift_templates')->count();

    DB::listen(function ($q) {
        if (str_contains($q->sql, 'insert into "free_gift_range_items"') || str_contains($q->sql, 'insert into `free_gift_range_items`')) {
            throw new RuntimeException('disk full');
        }
    });

    expect(fn () => app(FreeGiftTemplates::class)->apply($id))->toThrow(RuntimeException::class);

    expect(gtplState())->toBe($live)
        ->and(gtplCompiled())->toBe($compiled)
        ->and(DB::table('free_gift_templates')->count())->toBe($templates);
});

it('leaves out a gift whose product was deleted, says how many, and gives the rest', function () {
    /*
     * The defect: a template naming a product that has since been deleted
     * either refuses to apply at all or writes a gift the shop cannot give.
     * MUTATION: drop the `$type !== null` test in apply(). RED.
     */
    $keep = gtplProduct('Keeps');
    $gone = gtplProduct('Gone');
    gtplRange(0, 299, [[$keep, 1], [$gone, 2]]);
    $id = gtplSave('With a gone one');
    $gone->delete(); // soft delete, as the admin does

    $r = gtplApi('POST', '/' . $id . '/apply')->assertOk();

    expect($r->json('skipped'))->toBe(1)
        ->and(array_column(gtplState()[0]['items'], 'product_id'))->toBe([$keep->id]);

    // Gone for good is the same answer.
    DB::table('products')->where('id', $gone->id)->delete();
    expect(gtplApi('POST', '/' . $id . '/apply')->assertOk()->json('skipped'))->toBe(1);
});

it('breaks no placed order, no open cart and no gift-offer claim', function () {
    /*
     * What holds a range id, found before building this:
     *   - order_items.gift_range_id / gift_range_label: a snapshot, no foreign
     *     key, never read back -- a placed order must not change at all;
     *   - a cart holds no range: its box is worked out from the compiled
     *     ranges on every draw, and checkout does the same;
     *   - gift offers have their own gift_offer_ranges / _claims tables;
     *   - the cart trail's "r12" click is turned into a label at display time.
     * The defect: an apply that rewrites an order's gift lines, leaves an open
     * cart giving the old set (or failing), or touches a claim; or a reused
     * range id that gives an old "r12" click the new range's label.
     * MUTATION: reuse the old range ids in apply() (update in place). The
     * GiftClicks assertion goes RED.
     */
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    app(SettingsService::class)->set(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    $oldGift = gtplProduct('Old Mask', ['gift_only' => true]);
    $newGift = gtplProduct('New Toner', ['gift_only' => true]);
    $serum = gtplProduct('Serum', ['price' => 15000]);
    $oldRange = gtplRange(100, 299, [[$newGift, 1]]);
    $tpl = gtplSave('New set');
    DB::table('free_gift_ranges')->delete();
    $oldRange = gtplRange(100, 299, [[$oldGift, 2]]);

    $cart = function () use ($serum): Cart {
        $c = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
        $c->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 15000]);

        return $c->fresh();
    };
    $place = fn (Cart $c) => test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $c->token)
        ->post('/checkout/place', ['billing_email' => 'b@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'A', 'billing_last_name' => 'K',
            'billing_address_1' => '1 Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod']);

    $place($cart())->assertRedirect();
    $order = Order::latest('id')->first();
    $orderGifts = DB::table('order_items')->where('order_id', $order->id)->where('is_gift', true)->get()->map(fn ($r) => (array) $r)->all();
    expect($orderGifts)->toHaveCount(1)->and((int) $orderGifts[0]['gift_range_id'])->toBe($oldRange);

    $open = $cart(); // a shopper mid-basket
    $claimRange = DB::table('gift_offer_ranges')->insertGetId(['min_fils' => 0, 'max_fils' => null, 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('gift_offer_claims')->insert(['token_hash' => str_repeat('a', 64), 'place' => 'popup', 'range_id' => $claimRange, 'product_id' => $oldGift->id,
        'cart_id' => $open->id, 'status' => 'claimed', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $claims = DB::table('gift_offer_claims')->get()->map(fn ($r) => (array) $r)->all();
    expect(GiftClicks::rangeLabel('r' . $oldRange))->not->toBeNull();

    gtplApi('POST', '/' . $tpl . '/apply')->assertOk();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetInstance(FreeGifts::class);

    // The placed order is byte-for-byte what it was.
    expect(DB::table('order_items')->where('order_id', $order->id)->where('is_gift', true)->get()->map(fn ($r) => (array) $r)->all())->toBe($orderGifts)
        ->and(DB::table('gift_offer_claims')->get()->map(fn ($r) => (array) $r)->all())->toBe($claims)
        // The old id is a range that no longer exists, never the new one's label.
        ->and(GiftClicks::rangeLabel('r' . $oldRange))->toBeNull()
        ->and((int) DB::table('free_gift_ranges')->value('id'))->toBeGreaterThan($oldRange);

    // The open cart now gets the new set and checks out.
    $place($open)->assertRedirect();
    $next = Order::latest('id')->first();
    expect($next->id)->not->toBe($order->id)
        ->and($next->gifts->pluck('product_id')->all())->toBe([$newGift->id]);
});

it('applying nothing changes nothing: with no templates the compiled ranges are byte-identical', function () {
    /*
     * The defect: the package moving the shop's gifts by merely arriving.
     * MUTATION: have the migration seed a template and apply it. RED.
     */
    gtplRich();
    $before = app(SettingsService::class)->get(FreeGifts::COMPILED);
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    expect(DB::table('free_gift_templates')->count())->toBe(0)
        ->and(app(SettingsService::class)->get(FreeGifts::COMPILED))->toBe($before);
});

it('refuses a damaged or oversized template with nothing written', function () {
    /*
     * The defect: a payload edited by hand (or truncated) applied half way.
     * MUTATION: return [] instead of throwing in normalise(). RED.
     */
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $live = gtplState();
    $bad = DB::table('free_gift_templates')->insertGetId(['name' => 'Broken', 'payload' => '{"v":1,"ranges":[{"min_fils":-5,"items":[]}]}', 'auto' => false, 'created_at' => now(), 'updated_at' => now()]);
    $big = DB::table('free_gift_templates')->insertGetId(['name' => 'Too many', 'auto' => false, 'created_at' => now(), 'updated_at' => now(),
        'payload' => json_encode(['v' => 1, 'ranges' => array_fill(0, 21, ['min_fils' => 0, 'max_fils' => null, 'label_en' => '', 'label_ar' => '', 'items' => []])])]);

    gtplApi('POST', '/' . $bad . '/apply')->assertStatus(422);
    gtplApi('POST', '/' . $big . '/apply')->assertStatus(422)->assertJsonPath('error', 'A template holds at most 20 ranges.');
    gtplApi('POST', '/999999/apply')->assertNotFound();

    expect(gtplState())->toBe($live)->and(DB::table('free_gift_templates')->where('auto', true)->count())->toBe(0);
    // Listed all the same, so it can be deleted.
    expect(collect(gtplApi('GET', '')->json('templates'))->pluck('name')->all())->toContain('Broken');
});

/*
|------------------------------------------------------------------------------
| NAMES, THE LIST, LIVE STATE
|------------------------------------------------------------------------------
*/

it('wants a name, at most 60 characters, unique whatever the case; rename and save-over follow the same rule', function () {
    /*
     * The defect: two templates both called "Eid", so the one he picks is
     * not the one he meant; or an empty name nobody can click.
     * MUTATION: drop the `$taken` check in FreeGiftTemplatesController::name(). RED.
     */
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $owner = gtplAdmin();

    gtplApi('POST', '', ['name' => '   '], $owner)->assertStatus(422);
    gtplApi('POST', '', ['name' => str_repeat('x', 61)], $owner)->assertStatus(422);
    $eid = gtplSave(str_repeat('y', 59) . 'z', $owner);
    $id = gtplSave('Eid', $owner);
    gtplApi('POST', '', ['name' => 'EID'], $owner)->assertStatus(422)->assertJsonValidationErrors('name');
    gtplApi('POST', '/' . $id . '/rename', ['name' => str_repeat('y', 59) . 'z'], $owner)->assertStatus(422);
    gtplApi('POST', '/' . $id . '/rename', ['name' => '  Eid   2026 '], $owner)->assertOk();
    expect(DB::table('free_gift_templates')->where('id', $id)->value('name'))->toBe('Eid 2026');
    gtplApi('POST', '/' . $id . '/rename', ['name' => 'Eid 2026'], $owner)->assertOk(); // its own name is not "taken"

    // Save over: the row keeps its name and takes today's ranges.
    gtplRange(500, null, [[$p, 5]]);
    gtplApi('PUT', '/' . $id, [], $owner)->assertOk();
    $row = collect(gtplApi('GET', '', [], $owner)->json('templates'))->firstWhere('id', $id);
    expect($row['name'])->toBe('Eid 2026')->and($row['ranges'])->toBe(2)
        ->and($row['summary'])->toBe('AED 0–299 · 500+ · 2 gifts');

    // Nothing to save is refused, not stored empty.
    DB::table('free_gift_ranges')->delete();
    gtplApi('POST', '', ['name' => 'Empty'], $owner)->assertStatus(422);
    expect(DB::table('free_gift_templates')->where('name', 'Empty')->exists())->toBeFalse();
});

it('marks the live template, and the live_rev stops matching once a range is edited', function () {
    /*
     * The defect: the card saying "Live: Eid" after he has changed a range,
     * so he thinks the shop gives what the template holds.
     * MUTATION: set live_rev before the inserts in apply(). RED.
     */
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $owner = gtplAdmin();
    $id = gtplSave('Eid', $owner);
    $other = gtplSave('Other', $owner);
    gtplApi('POST', '/' . $id . '/apply', [], $owner)->assertOk();

    $rev = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts')->json('revision');
    $rows = collect(gtplApi('GET', '', [], $owner)->json('templates'));
    expect($rows->firstWhere('id', $id)['live_rev'])->toBe($rev)
        ->and($rows->whereNotNull('live_rev'))->toHaveCount(1)
        ->and($rows->firstWhere('id', $other)['live_rev'])->toBeNull();

    // An edit through the ordinary range save moves the revision on.
    $range = (int) DB::table('free_gift_ranges')->value('id');
    test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $range, [
        'min' => 0, 'max' => 350, 'label_en' => 'x', 'label_ar' => 'x', 'enabled' => true, 'gifts' => [['product_id' => $p->id, 'quantity' => 1]],
    ])->assertOk();
    $now = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts')->json('revision');
    expect($now)->not->toBe($rev)
        ->and(collect(gtplApi('GET', '', [], $owner)->json('templates'))->firstWhere('id', $id)['live_rev'])->toBe($rev);
});

it('reads with freegifts.view, writes with freegifts.manage, and refuses everyone else', function () {
    /*
     * The defect: a support login (or a guest) swapping every live range.
     * MUTATION: move the routes out of free-gifts/ (off the mapped prefix). RED.
     */
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $id = DB::table('free_gift_templates')->insertGetId(['name' => 'Eid', 'auto' => false, 'created_at' => now(), 'updated_at' => now(),
        'payload' => json_encode(['v' => 1, 'ranges' => app(FreeGiftTemplates::class)->snapshot()[0]])]);
    $live = gtplState();

    test()->getJson('/admin-api/free-gifts/templates')->assertUnauthorized();
    test()->postJson('/admin-api/free-gifts/templates/' . $id . '/apply')->assertUnauthorized();

    $support = gtplAdmin('support');
    foreach ([['GET', ''], ['POST', ''], ['PUT', '/' . $id], ['POST', '/' . $id . '/rename'], ['POST', '/' . $id . '/apply'], ['DELETE', '/' . $id]] as [$m, $path]) {
        gtplApi($m, $path, ['name' => 'Hijack'], $support)->assertForbidden();
    }

    foreach (['', '/5', '/5/rename', '/5/apply'] as $path) {
        expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/free-gifts/templates' . $path))->toBe('freegifts.manage');
    }
    expect(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/free-gifts/templates'))->toBe('freegifts.view')
        ->and(\App\Support\AdminCapabilities::forPath('PUT', 'admin-api/free-gifts/templates/5'))->toBe('freegifts.manage')
        ->and(\App\Support\AdminCapabilities::forPath('DELETE', 'admin-api/free-gifts/templates/5'))->toBe('freegifts.manage')
        ->and(gtplState())->toBe($live)
        ->and(DB::table('free_gift_templates')->pluck('name')->all())->toBe(['Eid']);

    gtplApi('GET', '', [], gtplAdmin('manager'))->assertOk();
});

it('lists 3 or 30 templates in the same number of queries', function () {
    /*
     * The defect: one query per template (its products, its ranges), so the
     * card slows down every time he saves another set.
     * MUTATION: look each template's products up inside list()'s map(). RED.
     */
    $owner = gtplAdmin();
    $p = gtplProduct('Mask');
    gtplRange(0, 299, [[$p, 1]]);
    $count = function () use ($owner): int {
        gtplApi('GET', '', [], $owner)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        gtplApi('GET', '', [], $owner)->assertOk();
        $n = count(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/\b(free_gift_templates|free_gift_ranges|free_gift_range_items|products)\b/', $q['query']) === 1));
        DB::disableQueryLog();

        return $n;
    };
    for ($i = 1; $i <= 3; $i++) {
        gtplSave('T' . $i, $owner);
    }
    $three = $count();
    for ($i = 4; $i <= 30; $i++) {
        gtplSave('T' . $i, $owner);
    }

    expect(DB::table('free_gift_templates')->count())->toBe(30)
        ->and($count())->toBe($three)
        ->and($three)->toBe(1);
});

it('draws the card with no timer and no polling, and asks through the shared box before every change', function () {
    /*
     * The defect: a Make live one stray tap away, a confirm of its own beside
     * the admin's shared one, or a refresh loop that repaints over another
     * screen.
     * MUTATION: drop 'data-kbb-sure' from the Make live button. RED.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    $start = strpos($screen, '/* ------------------------------------------------------ templates (Lane GT)');
    $end = strpos($screen, '/* --------------------------------------------------------------- display */', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $js = substr($screen, (int) $start, $end - $start);

    expect($js)->not->toMatch('/setInterval|setTimeout|requestAnimationFrame|window\.confirm|getBoundingClientRect|offsetHeight/')
        ->and(substr_count($js, "api('GET', '/templates')"))->toBe(1)
        ->and(substr_count($js, "'data-kbb-sure': "))->toBe(4) // Make live, Save over, Save name, Delete
        ->and($js)->toContain("'data-fk': 'tpl-apply-'")
        ->and($js)->toContain('Make "')
        ->and($js)->toContain('Save the live ranges over "')
        ->and($js)->toContain('Delete the template "')
        ->and($js)->toContain('function paintTpl() { if (ours()')
        // mounted exactly once, on the Ranges & gifts tab
        // (just above Gift-only products, whose own mount line is unchanged)
        ->and(substr_count($screen, 'p.insertBefore(tplPanel(), p.lastChild)'))->toBe(1)
        ->and(substr_count($screen, 'function tplPanel('))->toBe(1);

    // No label the reset guard would ask about a second time.
    preg_match_all("/text: '([^']+)'/", $js, $m);
    foreach ($m[1] as $label) {
        expect(preg_match('/^\s*(reset|restore|revert|back to defaults?)\b/i', $label))->toBe(0);
    }
});
