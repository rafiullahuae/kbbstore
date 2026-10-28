<?php

declare(strict_types=1);

/*
 * Free gifts in the owner app (Lane OAG). The owner, 28 October: "to add and
 * manage gifts products in each range etc. through owner app, the same
 * functionality which i have on desktop admin" -- and "put the refresh icon in
 * owner app for free gifts sync ... so there will be no override things".
 *
 * ONE RULE: every app endpoint delegates to Admin\FreeGiftsController, so the
 * stored rows from either screen are the same; and both screens send the
 * revision they loaded, refused 409 with nothing written when stale.
 */

use App\Http\Controllers\Admin\FreeGiftsController;
use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
    $this->owner = OA::admin();
    OA::member($this->owner);
});

function oagProduct(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::random(6), 'name' => $name, 'status' => 'publish',
        'is_visible' => true, 'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 40,
    ], $o));
}

/** A product with options and its variations, none of them another product's. */
function oagTint(string $name = 'Lip Tint'): array
{
    $p = oagProduct($name, ['type' => 'variable']);
    $out = [];
    foreach (['Red', 'Pink'] as $i => $c) {
        $out[$c] = ProductVariant::create(['product_id' => $p->id, 'sku' => 'T-'.$c.'-'.Str::random(4), 'price' => 3000,
            'manage_stock' => true, 'stock' => 9, 'stock_status' => 'instock', 'position' => $i]);
    }

    return [$p, $out];
}

function oagFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    request()->attributes->replace([]);
}

/** Stored state with ids and timestamps left out: what "identical" means for two saves. */
function oagStored(): array
{
    $strip = fn ($rows, array $drop) => $rows->map(fn ($r) => array_diff_key((array) $r, array_flip($drop)))->values()->all();
    oagFresh();

    return [
        $strip(DB::table('free_gift_ranges')->orderBy('min_fils')->get(), ['id', 'created_at', 'updated_at']),
        $strip(DB::table('free_gift_range_items')->orderBy('sort')->get(), ['id', 'range_id', 'created_at', 'updated_at']),
        DB::table('products')->orderBy('id')->pluck('gift_kind', 'slug')->all(),
        array_map(fn ($r) => array_diff_key($r, ['id' => 1]), app(FreeGifts::class)->ranges()),
    ];
}

function oagRange(array $gifts, array $o = []): array
{
    return array_merge(['min' => 200, 'max' => 399, 'label_en' => 'Free mask', 'label_ar' => 'قناع مجاني', 'enabled' => true,
        'starts_on' => null, 'ends_on' => null, 'gifts' => $gifts, 'needs' => null], $o);
}

it('fails closed: no session is 401, a role without the capability is 403 and writes nothing', function () {
    // DEFECT: a phone screen that skips the console's capability lets any
    // enrolled member rewrite the shop's gifts. MUTATION: drop refuse() in
    // GiftsController::save and support's POST is 200 with a range written.
    $p = oagProduct('Snail Mask');
    expect($this->flushHeaders()->getJson(OA::base().'/api/gifts')->status())->toBeIn([401, 403]);
    expect($this->flushHeaders()->postJson(OA::base().'/api/gifts/ranges', oagRange([]))->status())->toBeIn([401, 403]);

    foreach (['support' => 'sue@example.com', 'editor' => 'ed@example.com'] as $role => $email) {
        OA::member(OA::admin($role, $email, ucfirst($role)));
        [$c, $k] = OA::enrol($this, $email);
        expect(OA::get($this, 'gifts', $c)->assertForbidden()->json('capability'))->toBe('freegifts.view');
        OA::get($this, 'gifts/products?q=sn', $c)->assertForbidden();
        expect(OA::post($this, 'gifts/ranges', oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask']]), $c, $k)->assertForbidden()->json('capability'))->toBe('freegifts.manage');
        OA::post($this, 'gifts/mode', ['mode' => 'live'], $c, $k)->assertForbidden();
        expect(OA::get($this, 'state', $c)->json('me.can.gifts'))->toBeFalse()
            ->and(OA::get($this, 'state', $c)->json('me.can.gifts_edit'))->toBeFalse();
    }
    expect(DB::table('free_gift_ranges')->count())->toBe(0)
        ->and(app(FreeGifts::class)->freshConfig()['mode'])->toBe('off');

    // And without the app's CSRF value, even the owner is refused.
    [$c] = OA::enrol($this);
    expect(OA::post($this, 'gifts/ranges', oagRange([]), $c, null)->status())->toBeIn([401, 403, 419]);
    expect(DB::table('free_gift_ranges')->count())->toBe(0);
});

it('reads the ranges for the owner, allowlisted: no Testing IPs, texts or my_ip reach the phone', function () {
    // DEFECT: passing the console's payload straight through puts the Testing
    // IP list and the server's view of the owner's IP on the phone.
    // MUTATION: return $d unmapped in GiftsController::answer() -- red.
    app(FreeGifts::class)->saveConfig(array_merge(app(FreeGifts::class)->freshConfig(), ['mode' => 'testing', 'testers' => [['ip' => '203.0.113.77', 'label' => 'Shop']]]));
    [$c] = OA::enrol($this);
    $d = OA::get($this, 'gifts', $c)->assertOk()->json();

    expect(array_keys($d))->toBe(['ok', 'mode', 'testers', 'revision', 'low_stock', 'max_qty', 'max_need', 'can_manage', 'gaps', 'checklist', 'ranges'])
        ->and($d['mode'])->toBe('testing')->and($d['testers'])->toBe(1)->and($d['can_manage'])->toBeTrue()
        ->and(json_encode($d))->not->toContain('203.0.113.77')->not->toContain('my_ip')->not->toContain('"texts"');
    expect(OA::get($this, 'state', $c)->json('me.can'))->toMatchArray(['gifts' => true, 'gifts_edit' => true]);
});

it('changes the mode with the console\'s own switch, and refuses a mode that is not one of its options', function () {
    // MUTATION: route gifts/mode anywhere but FreeGiftsController::switch -- the config does not move.
    [$c, $k] = OA::enrol($this);
    expect(OA::post($this, 'gifts/mode', ['mode' => 'live', 'pin' => '482613'], $c, $k)->assertOk()->json('mode'))->toBe('live');
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('live');

    OA::post($this, 'gifts/mode', ['mode' => 'everyone', 'pin' => '482613'], $c, $k)->assertStatus(422);
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('live');
});

/*
 * 2.60.615, the owner: "OFF TESTING LIVE, lock these options in owner app ...
 * the owner app must ask yes / no, and upon changing, it will require the app
 * password once to apply the change. this security is only for the free gifts
 * functionality OFF, TESTING and LIVE status".
 * DEFECT this stops: anyone holding an unlocked phone could switch every
 * shopper's free gifts on or off with two taps.
 * MUTATION: delete the confirmPin() block in GiftsController::mode -- the
 * first two cases go red (the mode moves with no PIN and with a wrong one).
 */
it('locks Off / Testing / Live behind the app PIN, checked on the server; ranges need no PIN', function () {
    [$c, $k] = OA::enrol($this);

    // No PIN: refused, nothing written.
    expect(OA::post($this, 'gifts/mode', ['mode' => 'live'], $c, $k)->status())->toBeIn([403, 422]);
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('off');

    // A wrong PIN: refused as the lock screen refuses it (bad_pin, attempts left), nothing written.
    $r = OA::post($this, 'gifts/mode', ['mode' => 'live', 'pin' => '111111'], $c, $k)->assertStatus(422);
    expect($r->json('code'))->toBe('bad_pin')->and($r->json('pin_refused'))->toBeTrue()->and($r->json('left'))->toBeInt();
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('off');

    // The right PIN: applied, and the wrong-PIN counter is cleared.
    OA::post($this, 'gifts/mode', ['mode' => 'testing', 'pin' => '482613'], $c, $k)->assertOk();
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('testing')
        ->and((int) DB::table('owner_app_members')->where('admin_user_id', $this->owner->id)->value('failed_count'))->toBe(0);

    // Only the mode is locked: a range still saves with no PIN.
    $p = oagProduct('Snail Mask');
    OA::post($this, 'gifts/ranges', oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask']]), $c, $k)->assertOk();
    expect(DB::table('free_gift_ranges')->count())->toBe(1);
});

it('shares the lock screen\'s lockout: five wrong PINs on the mode switch lock the member', function () {
    // MUTATION: give confirmPin() its own counter instead of checkPin() -- the
    // sixth try is not 423 and a guesser gets unlimited tries from this box.
    [$c, $k] = OA::enrol($this);
    for ($i = 0; $i < 5; $i++) {
        OA::post($this, 'gifts/mode', ['mode' => 'live', 'pin' => '11111'.$i], $c, $k);
    }
    expect(OA::post($this, 'gifts/mode', ['mode' => 'live', 'pin' => '482613'], $c, $k)->status())->toBeIn([401, 403, 423]);
    expect(app(FreeGifts::class)->freshConfig()['mode'])->toBe('off');
});

it('draws the three mode buttons locked and asks Yes / No, then the PIN, on the phone', function () {
    $js = file_get_contents(resource_path('js/owner-app/gifts.js'));
    expect($js)->toContain("data-gmode=\"' + m[0] + '\"' + (can() ? '' : ' disabled') + '>' + ic('lock', 's')")
        ->and($js)->toContain("pinSheet(to, row); // sheet() closes this Yes / No sheet first")
        ->and($js)->toContain("api('POST', 'gifts/mode', { mode: to, pin })")
        ->and(substr_count($js, "api('POST', 'gifts/mode'"))->toBe(1);
});

it('adds a range with a simple product, and refuses a bad range with 422 and nothing written', function () {
    // The server rule allows overlapping ranges (the owner, 22 October: "the
    // server should not restrict the ranges") -- so the app does too: one rule.
    $p = oagProduct('Snail Mask');
    [$c, $k] = OA::enrol($this);

    $d = OA::post($this, 'gifts/ranges', oagRange([['product_id' => $p->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary']]), $c, $k)->assertOk()->json();
    expect($d['ranges'])->toHaveCount(1)
        ->and($d['ranges'][0]['gifts'][0])->toMatchArray(['product_id' => $p->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary'])
        ->and($d['ranges'][0]['gifts'][0]['product']['name'])->toBe('Snail Mask')
        ->and($d['ranges'][0]['gives']['lines'][0])->toMatchArray(['name' => 'Snail Mask', 'quantity' => 2]);

    // Overlapping 300-499 is accepted, exactly as on the desk.
    OA::post($this, 'gifts/ranges', oagRange([], ['min' => 300, 'max' => 499, 'revision' => $d['revision']]), $c, $k)->assertOk();

    $before = oagStored();
    foreach ([
        oagRange([], ['min' => -5]),                                                     // negative amount
        oagRange([], ['label_en' => '']),                                                // no wording
        oagRange([], ['starts_on' => '2027-05-02', 'ends_on' => '2027-05-01']),          // ends before it starts
        oagRange([['product_id' => 999999, 'quantity' => 1]]),                           // no such product
        oagRange([['product_id' => $p->id, 'quantity' => 51]]),                          // over the 50 cap
        oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'lipstick']]),     // not one of the kinds
    ] as $bad) {
        expect(OA::post($this, 'gifts/ranges', $bad, $c, $k)->assertStatus(422)->json('ok'))->toBeFalse();
    }
    expect(oagStored())->toBe($before);
});

it('adds a product with options only as one of ITS OWN variations: none or a foreign one is 422, nothing written', function () {
    // DEFECT this guards (FGV, now on the phone too): a variable product with
    // no variation is skipped by the shop; another product's variation is
    // given as the wrong item. MUTATION: drop the `$variantOwner` check in
    // FreeGiftsController::saveRange -- the foreign variant saves. RED.
    [$tint, $v] = oagTint();
    [, $other] = oagTint('Cushion');
    $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => 'Gift', 'label_ar' => 'هدية', 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
    [$c, $k] = OA::enrol($this);
    $before = oagStored();

    $none = OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $tint->id, 'quantity' => 1, 'kind' => 'mini']], ['min' => 100, 'max' => null]), $c, $k)->assertStatus(422)->json();
    expect($none['message'])->toContain('choose which variation');
    OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $tint->id, 'variant_id' => $other['Pink']->id, 'quantity' => 1, 'kind' => 'mini']], ['min' => 100, 'max' => null]), $c, $k)->assertStatus(422);
    expect(oagStored())->toBe($before);

    // The picker lists its variations ("Choose variation"), and its own Pink saves.
    $found = collect(OA::get($this, 'gifts/products?q=Lip', $c)->assertOk()->json('products'))->firstWhere('id', $tint->id);
    expect(collect($found['variants'])->pluck('variant_id')->all())->toBe([$v['Red']->id, $v['Pink']->id]);

    $d = OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1, 'kind' => 'mini']], ['min' => 100, 'max' => null]), $c, $k)->assertOk()->json();
    expect($d['ranges'][0]['gifts'][0]['variant_id'])->toBe($v['Pink']->id)
        ->and($d['ranges'][0]['gifts'][0]['product']['variant_id'])->toBe($v['Pink']->id);
});

it('stores exactly what the desk stores for the same range: rows, kinds remembered, compiled copy', function () {
    // DEFECT this guards: a second copy of the rule drifting -- the phone
    // saving a range the desk would save differently. MUTATION: drop the
    // criteriaWording() fill in GiftsController::save (the desk sends the
    // counts' sentence as the wording) -- the label differs. RED.
    $mask = oagProduct('Snail Mask');
    $mini = oagProduct('Mini Toner');
    [$tint, $v] = oagTint();
    $gifts = [
        ['product_id' => $mask->id, 'quantity' => 2, 'kind' => 'mask', 'role' => 'primary'],
        ['product_id' => $tint->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1, 'kind' => 'mini', 'role' => 'primary'],
        ['product_id' => $mini->id, 'quantity' => 1, 'kind' => 'mini', 'role' => 'backup'],
    ];
    $needs = ['mask' => 2, 'mini' => 1, 'full' => 0];

    // The desk: its rangeBody() sends the counts' wording as the label.
    $w = FreeGifts::criteriaWording($needs);
    $admin = AdminUser::query()->where('email', 'owner@example.com')->first();
    $this->actingAs($admin, 'admin')->postJson('/admin-api/free-gifts/ranges', oagRange($gifts, ['needs' => $needs, 'label_en' => $w['en'], 'label_ar' => $w['ar'], 'starts_on' => '2027-01-01']))->assertOk();
    $desk = oagStored();

    DB::table('free_gift_ranges')->delete();
    DB::table('products')->update(['gift_kind' => null]);
    app(FreeGifts::class)->compile();

    // The phone: the same edit, whatever it typed in the (hidden) wording boxes.
    [$c, $k] = OA::enrol($this);
    OA::post($this, 'gifts/ranges', oagRange($gifts, ['needs' => $needs, 'label_en' => 'x', 'label_ar' => 'y', 'starts_on' => '2027-01-01']), $c, $k)->assertOk();

    expect(oagStored())->toBe($desk)
        ->and($desk[0][0]['label_en'])->toBe('2 Masks + 1 Mini Product');
});

it('refuses a stale save from the phone with 409 and writes nothing; a fresh one passes and moves the revision', function () {
    // The owner, 28 October: "i almost edit from desktop and owner app both".
    // DEFECT: the last save wins and silently undoes the other device's work.
    // MUTATION: make FreeGiftsController::guard() return at once -- the stale
    // save is 200 and the desk's gift is gone. RED.
    $p = oagProduct('Snail Mask');
    $q = oagProduct('Rice Toner');
    [$c, $k] = OA::enrol($this);
    $d = OA::post($this, 'gifts/ranges', oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask']]), $c, $k)->assertOk()->json();
    $id = $d['ranges'][0]['id'];
    $loaded = OA::get($this, 'gifts', $c)->json('revision');
    expect($loaded)->toBe($d['revision']);

    // Meanwhile the desk adds the toner (sending the revision it loaded).
    $admin = AdminUser::query()->where('email', 'owner@example.com')->first();
    $desk = $this->actingAs($admin, 'admin')->getJson('/admin-api/free-gifts')->assertOk()->json('revision');
    expect($desk)->toBe($loaded);
    $this->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, oagRange([
        ['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask'], ['product_id' => $q->id, 'quantity' => 1, 'kind' => 'mini'],
    ], ['revision' => $desk]))->assertOk();
    $after = oagStored();

    // The phone saves from what it loaded: refused, nothing written.
    $r = OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 3, 'kind' => 'mask']], ['revision' => $loaded]), $c, $k)->assertStatus(409)->json();
    expect($r['code'])->toBe('stale')->and($r['message'])->toBe(FreeGiftsController::STALE);
    OA::post($this, 'gifts/ranges/'.$id.'/delete', ['revision' => $loaded], $c, $k)->assertStatus(409);
    expect(oagStored())->toBe($after);

    // Refresh returns the latest; a save from it passes and moves the revision.
    $fresh = OA::get($this, 'gifts', $c)->assertOk()->json();
    expect($fresh['revision'])->not->toBe($loaded)->and($fresh['ranges'][0]['gifts'])->toHaveCount(2);
    $saved = OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 3, 'kind' => 'mask'], ['product_id' => $q->id, 'quantity' => 1, 'kind' => 'mini']], ['revision' => $fresh['revision']]), $c, $k)->assertOk()->json();
    expect($saved['revision'])->not->toBe($fresh['revision'])
        ->and($saved['ranges'][0]['gifts'][0]['quantity'])->toBe(3);
});

it('refuses a stale save from the desk too, the same rule, and keeps a save with no revision working', function () {
    // MUTATION: call guard() only from the app's controller -- the desk's stale PUT is 200. RED.
    $p = oagProduct('Snail Mask');
    $admin = AdminUser::query()->where('email', 'owner@example.com')->first();
    $id = $this->actingAs($admin, 'admin')->postJson('/admin-api/free-gifts/ranges', oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask']]))->assertOk()->json('ranges.0.id');
    $desk = $this->actingAs($admin, 'admin')->getJson('/admin-api/free-gifts')->json('revision');

    [$c, $k] = OA::enrol($this);
    OA::post($this, 'gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 4, 'kind' => 'mask']], ['revision' => OA::get($this, 'gifts', $c)->json('revision')]), $c, $k)->assertOk();
    $after = oagStored();

    $r = $this->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 1, 'kind' => 'mask']], ['revision' => $desk]))->assertStatus(409);
    expect($r->json('error'))->toBe(FreeGiftsController::STALE);
    $this->actingAs($admin, 'admin')->deleteJson('/admin-api/free-gifts/ranges/'.$id, ['revision' => $desk])->assertStatus(409);
    $this->actingAs($admin, 'admin')->postJson('/admin-api/free-gifts/ranges/order', ['ids' => [$id], 'revision' => $desk])->assertStatus(409);
    expect(oagStored())->toBe($after);

    // A console page from before this package sends no revision: as before.
    $this->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 2, 'kind' => 'mask']]))->assertOk();
    // And a save that changes nothing keeps the revision (no false conflict).
    $rev = $this->actingAs($admin, 'admin')->getJson('/admin-api/free-gifts')->json('revision');
    $same = $this->actingAs($admin, 'admin')->putJson('/admin-api/free-gifts/ranges/'.$id, oagRange([['product_id' => $p->id, 'quantity' => 2, 'kind' => 'mask']], ['revision' => $rev]))->assertOk()->json('revision');
    expect($same)->toBe($rev);
});

it('costs the same number of queries for a range of 3 gifts as for 15, and products+variants+stock in two', function () {
    // DEFECT: a query per gift -- the phone screen slows as ranges grow.
    // MUTATION: load each gift's product inside GiftsController::gives() -- red.
    $count = function (int $n): int {
        DB::table('free_gift_ranges')->delete();
        $id = DB::table('free_gift_ranges')->insertGetId(['min_fils' => 10000, 'max_fils' => null, 'label_en' => 'Gift', 'label_ar' => 'هدية', 'enabled' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < $n; $i++) {
            [$t, $v] = oagTint('Tint '.$n.'-'.$i);
            DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $t->id, 'variant_id' => $v['Pink']->id, 'quantity' => 1, 'kind' => 'mini', 'role' => 'primary', 'sort' => $i * 2]);
            DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => oagProduct('Mask '.$n.'-'.$i)->id, 'quantity' => 1, 'kind' => 'mask', 'role' => 'primary', 'sort' => $i * 2 + 1]);
        }
        app(FreeGifts::class)->compile();
        oagFresh();
        [$c] = OA::enrol($this);
        OA::get($this, 'gifts', $c)->assertOk();
        oagFresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $d = OA::get($this, 'gifts', $c)->assertOk()->json();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($d['ranges'][0]['gifts'])->toHaveCount($n * 2);

        return $q;
    };

    expect($count(15))->toBe($count(3));
});

it('is reachable from the app: More lists it, the router draws it, the refresh icon re-reads it, nothing polls it', function () {
    $js = fn (string $f) => (string) file_get_contents(resource_path('js/owner-app/'.$f));
    $web = (string) file_get_contents(base_path('routes/owner-app.php'));

    expect(substr_count($js('store.js'), 'href="#/gifts"'))->toBe(1)
        ->and(substr_count($js('owner-app.js'), "import { renderGifts, giftsClick, fetchGifts } from './gifts.js';"))->toBe(1)
        ->and(substr_count($js('owner-app.js'), "else if (n === 'gifts') await renderGifts(view, route.id);"))->toBe(1)
        // The header's refresh icon (sync) re-reads the gifts while the screen is open, and only then.
        ->and(substr_count($js('owner-app.js'), "if (route.name === 'gifts' && can.gifts) jobs.push(fetchGifts(quiet, manual));"))->toBe(1)
        ->and(substr_count($web, "GiftsController::class, 'show']"))->toBe(1)
        ->and(substr_count((string) file_get_contents(resource_path('views/owner-app/shell.blade.php')), 'id="i-gift"'))->toBe(1);

    // No timer, no poll, no layout measuring; search is debounced.
    $g = $js('gifts.js');
    expect($g)->not->toContain('setInterval')->not->toContain('setTimeout')->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth')
        ->toContain('debounce((q) => fetchRows(q), 300)')
        ->toContain("t.length < 2");
});

it('offers every control of the desk\'s Ranges & gifts on the phone', function () {
    // The owner: "the same functionality which i have on desktop admin". Each
    // pair is [what the desk's screen says, what the phone's says]; a control
    // dropped from either side is red. Settings outside Ranges & gifts
    // (texts, look, homepage section, testers, countries, basis) stay on the desk.
    $desk = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    $app = (string) file_get_contents(resource_path('js/owner-app/gifts.js'));
    $pairs = [
        ["'Add range'", '+ Add range'],
        ["['off', 'Off', 'Nobody sees free gifts.'], ['testing', 'Testing'", "['off', 'Off', 'Nobody sees free gifts.'], ['testing', 'Testing'"],
        ["'and above'", 'And above'],
        ["sw(x.enabled, x.enabled ? 'On' : 'Off'", 'data-gon'],
        ["'Criteria'", '>Criteria <'],
        ["txt('label_en', 'Wording (English)')", 'data-gf="label_en"'],
        ["txt('label_ar', 'Wording (Arabic)', true)", 'data-gf="label_ar"'],
        ["date('starts_on', 'Starts (optional)')", 'Starts (optional)'],
        ["date('ends_on', 'Ends (optional)')", 'Ends (optional)'],
        ["'Add ' + k[4]", "'Add ' + k[4]"],
        ["'Add backup'", 'Add backup'],
        ["'Used only if a main gift runs out · most stock first'", 'Used only if a main gift runs out · most stock first'],
        ["'As needed'", 'As needed'],
        ["[['primary', 'Main'], ['backup', 'Backup']]", "[['primary', 'Main'], ['backup', 'Backup']]"],
        ["'aria-label': 'Quantity of ' + name", 'data-gq='],
        ["'aria-label': 'Remove ' + name", 'Remove from this range'],
        ["text: 'Undo'", '>Undo<'],
        ["'Duplicate'", '>Duplicate<'],
        ["'Delete…'", 'Delete…'],
        ["'Discard'", '>Discard<'],
        ["'Save range'", "'Save range'"],
        ["'New picks go to'", 'New picks go to'],
        ["[['all', 'All'], ['mask', 'Masks'], ['mini', 'Minis'], ['full', 'Full size'], ['none', 'Untagged']]", "[['all', 'All'], ['mask', 'Masks'], ['mini', 'Minis'], ['full', 'Full size'], ['none', 'Untagged']]"],
        ["[['all', 'All'], ['gift', 'Gift-only'], ['instock', 'In stock']]", "[['all', 'All'], ['gift', 'Gift-only'], ['instock', 'In stock']"],
        ["'★ Products used in gifts'", '★ Products used in gifts'],
        ["'Choose variation — each one you pick is its own gift'", 'Choose variation — each one you pick is its own gift'],
        ["'Which box?'", 'Which box?'],
        ["'Ask me'", "'Ask me'"],
        ["'Type at least 2 letters to search.'", 'Type at least 2 letters to search.'],
        ["'Search by name or SKU'", 'Search by name or SKU'],
        ["'Shares amounts with '", "'Shares amounts with '"],
        ["'Gap: '", "'Gap: '"],
        ["text: 'Stock not tracked · never runs out'", "'Stock not tracked'"],
        ["'Low · ' + p.stock + ' left'", "'Low · ' + p.stock + ' left'"],
        ["'Has options · gift a variation'", "'Has options · gift a variation'"],
    ];
    foreach ($pairs as [$d, $a]) {
        expect(str_contains($desk, $d))->toBeTrue('desk: '.$d)
            ->and(str_contains($app, $a))->toBeTrue('app: '.$a);
    }
});
