<?php

declare(strict_types=1);

/**
 * Growth & Marketing -> Free gifts -> Ranges & gifts: gift types and criteria
 * (Lane FG4, the owner 22 Oct: "tag each product Face Mask, Mini Product and
 * Full Size Product ... only for us as internal understanding ... the system
 * should count as per the set criteria ... notify us immediately").
 *
 * What this file pins: the type and criteria are saved, validated and read
 * back; a product remembers its type for the next add; the migration's
 * backfill and the owner's five criteria; the server's "short / over" items
 * and the screen's own copy of the same walk (run in node against PHP); that
 * nothing is ever blocked by unmet criteria; the wording generator; and --
 * the owner's hard requirement -- that the shop, the orders and /api never
 * see any of it. Each case says what the defect would look like and carries
 * a mutation note.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

function fg4Admin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'FG4 ' . $role, 'email' => 'fg4-' . $role . '-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => $role]);
}

function fg4Product(string $name, array $o = []): Product
{
    return Product::create(array_merge([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish', 'is_visible' => true,
        'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false,
    ], $o));
}

/** [[Product, qty, kind?], ...]; $o sets columns such as need_mask. */
function fg4Range(int $min, ?int $max, array $gifts = [], array $o = []): int
{
    $id = DB::table('free_gift_ranges')->insertGetId(array_merge([
        'min_fils' => $min * 100, 'max_fils' => $max === null ? null : $max * 100, 'label_en' => 'Gifts', 'label_ar' => 'هدايا',
        'sort' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ], $o));
    foreach ($gifts as $i => $g) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $g[0]->id, 'quantity' => $g[1], 'kind' => $g[2] ?? null, 'sort' => $i]);
    }

    return $id;
}

function fg4Body(array $gifts, ?array $needs, array $o = []): array
{
    return array_merge(['min' => 500, 'max' => 999, 'label_en' => '3 Masks + 2 Mini Products + 1 Full Size Product', 'label_ar' => 'هدايا', 'enabled' => true,
        'gifts' => $gifts, 'needs' => $needs], $o);
}

function fg4Fresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();
}

/*
|------------------------------------------------------------------------------
| SAVED, VALIDATED, READ BACK
|------------------------------------------------------------------------------
*/

it('saves each gift\'s type and the range\'s criteria, and reads them back through the allowlisted payload', function () {
    /*
     * The defect: the owner sorts his gifts into boxes and sets "3 / 2 / 1",
     * saves, and the screen comes back with everything untagged.
     * MUTATION: drop 'kind' => $kinds[$sort] from saveRange()'s insert, or
     * `+ ($needs ?? [])` from its row. RED.
     */
    $mask = fg4Product('Tea Tree Sheet Mask');
    $mini = fg4Product('Black Rice Toner Mini');
    $full = fg4Product('Senka Perfect Whip');
    $id = fg4Range(500, 999);

    $r = test()->actingAs(fg4Admin(), 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body([
        ['product_id' => $mask->id, 'quantity' => 3, 'kind' => 'mask'],
        ['product_id' => $mini->id, 'quantity' => 2, 'kind' => 'mini'],
        ['product_id' => $full->id, 'quantity' => 1, 'kind' => 'full'],
    ], ['mask' => 3, 'mini' => 2, 'full' => 1]))->assertOk();

    $range = collect($r->json('ranges'))->firstWhere('id', $id);
    expect($range['needs'])->toBe(['mask' => 3, 'mini' => 2, 'full' => 1])
        ->and(collect($range['gifts'])->pluck('kind', 'product_id')->all())->toBe([$mask->id => 'mask', $mini->id => 'mini', $full->id => 'full'])
        ->and(array_keys($range['gifts'][0]))->toBe(['product_id', 'quantity', 'kind', 'role', 'product'])
        ->and($range['gifts'][0]['product']['gift_kind'])->toBe('mask');

    expect((array) DB::table('free_gift_ranges')->where('id', $id)->first(['need_mask', 'need_mini', 'need_full']))->toEqual(['need_mask' => 3, 'need_mini' => 2, 'need_full' => 1]);

    // Criteria back to 0/0/0, or sent as null, read as "none set".
    $r = test()->actingAs(fg4Admin(), 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body([], null))->assertOk();
    expect(collect($r->json('ranges'))->firstWhere('id', $id)['needs'])->toBeNull();

    // A save that does not send `needs` at all (an older screen) leaves them as they were.
    DB::table('free_gift_ranges')->where('id', $id)->update(['need_mask' => 2, 'need_mini' => 0, 'need_full' => 0]);
    $body = fg4Body([], null);
    unset($body['needs']);
    test()->actingAs(fg4Admin(), 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, $body)->assertOk();
    expect((int) DB::table('free_gift_ranges')->where('id', $id)->value('need_mask'))->toBe(2);
});

it('refuses a type that is not one of its own options and a criterion outside 0-50', function (array $gift, ?array $needs, string $field) {
    /*
     * The defect: a forged request stores "kind":"<script>" or a criterion of
     * -3 / 9999, and the screen draws a box nobody can empty.
     * MUTATION: drop Rule::in(FreeGifts::KINDS) or 'max:' . MAX_NEED. RED.
     */
    $p = fg4Product('Sheet Mask');
    $id = fg4Range(500, 999);

    test()->actingAs(fg4Admin(), 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body([['product_id' => $p->id, 'quantity' => 1] + $gift], $needs))
        ->assertStatus(422)->assertJsonValidationErrors([$field]);
    expect(DB::table('free_gift_range_items')->where('range_id', $id)->count())->toBe(0);
})->with([
    'unknown type' => [['kind' => 'sample'], null, 'gifts.0.kind'],
    'markup as type' => [['kind' => '<b>mask</b>'], null, 'gifts.0.kind'],
    'criterion over 50' => [['kind' => 'mask'], ['mask' => 51, 'mini' => 0, 'full' => 0], 'needs.mask'],
    'negative criterion' => [['kind' => 'mask'], ['mask' => 1, 'mini' => -1, 'full' => 0], 'needs.mini'],
    'text criterion' => [['kind' => 'mask'], ['mask' => 1, 'mini' => 0, 'full' => 'two'], 'needs.full'],
    'missing criterion' => [['kind' => 'mask'], ['mask' => 1, 'mini' => 0], 'needs.full'],
]);

it('keeps the screen on freegifts.view / freegifts.manage, failing closed', function () {
    /*
     * MUTATION: route the range save outside the guarded group. RED.
     */
    $id = fg4Range(500, 999);
    $viewer = fg4Admin('viewer');
    $role = \App\Support\AdminRoles::can($viewer, 'freegifts.manage');
    expect($role)->toBeFalse();
    test()->actingAs($viewer, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body([], ['mask' => 3, 'mini' => 0, 'full' => 0]))->assertForbidden();
    expect(DB::table('free_gift_ranges')->where('id', $id)->value('need_mask'))->toBeNull();
});

/*
|------------------------------------------------------------------------------
| REMEMBERED PER PRODUCT
|------------------------------------------------------------------------------
*/

it('remembers a product\'s type and applies it the next time the product is added', function () {
    /*
     * The defect: the owner tags "Mugwort Clay Mask Mini" as a mini in one
     * range, adds it to the next range and has to tag it again.
     * MUTATION: drop the DB::table('products')->update(['gift_kind' => ...])
     * in saveRange(), or the remembered fallback for a gift sent without
     * `kind`. RED.
     */
    $clay = fg4Product('Mugwort Calming Clay Mask Mini');
    $a = fg4Range(300, 499);
    $b = fg4Range(500, 999);
    $c = fg4Range(1000, 1999);
    $owner = fg4Admin();

    test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $a, fg4Body([['product_id' => $clay->id, 'quantity' => 1, 'kind' => 'mini']], null, ['min' => 300, 'max' => 499]))->assertOk();
    expect(DB::table('products')->where('id', $clay->id)->value('gift_kind'))->toBe('mini');

    // The picker shows it.
    $found = test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/products?q=Mugwort')->assertOk()->json('products.0');
    expect($found['gift_kind'])->toBe('mini');

    // Sent with no `kind` key: the remembered type.
    $r = test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $b, fg4Body([['product_id' => $clay->id, 'quantity' => 2]], null))->assertOk();
    expect(collect($r->json('ranges'))->firstWhere('id', $b)['gifts'][0]['kind'])->toBe('mini');

    // Sent with kind null on purpose: the owner's "Untagged" stays untagged, and the memory is kept.
    $r = test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $c, fg4Body([['product_id' => $clay->id, 'quantity' => 1, 'kind' => null]], null, ['min' => 1000, 'max' => 1999]))->assertOk();
    expect(collect($r->json('ranges'))->firstWhere('id', $c)['gifts'][0]['kind'])->toBeNull()
        ->and(DB::table('products')->where('id', $clay->id)->value('gift_kind'))->toBe('mini');

    // Re-tagged: the memory follows the owner's latest choice.
    test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $c, fg4Body([['product_id' => $clay->id, 'quantity' => 1, 'kind' => 'mask']], null, ['min' => 1000, 'max' => 1999]))->assertOk();
    expect(DB::table('products')->where('id', $clay->id)->value('gift_kind'))->toBe('mask');
});

it('filters the picker by remembered type, untagged included, and reads anything else as all', function () {
    /*
     * MUTATION: drop the whereNull('gift_kind') branch, or accept any `kind`. RED.
     */
    fg4Product('Zqxw Sheet Mask', ['gift_kind' => 'mask']);
    fg4Product('Zqxw Toner Mini', ['gift_kind' => 'mini']);
    fg4Product('Zqxw Foam');
    $owner = fg4Admin();
    $names = fn (string $kind) => collect(test()->actingAs($owner, 'admin')->getJson('/admin-api/free-gifts/products?q=Zqxw&kind=' . urlencode($kind))->assertOk()->json('products'))->pluck('name')->sort()->values()->all();

    expect($names('mask'))->toBe(['Zqxw Sheet Mask'])
        ->and($names('mini'))->toBe(['Zqxw Toner Mini'])
        ->and($names('full'))->toBe([])
        ->and($names('none'))->toBe(['Zqxw Foam'])
        ->and($names('all'))->toHaveCount(3)
        ->and($names("mask' OR 1=1 --"))->toHaveCount(3);
});

it('saves a range in the same number of queries for 3 gifts as for 30', function () {
    /*
     * "Super light": remembering a type is one statement per TYPE, never one per gift.
     * MUTATION: update products inside the per-gift loop. RED.
     */
    $owner = fg4Admin();
    $count = function (int $n) use ($owner) {
        $id = fg4Range(100 * $n, 100 * $n + 50);
        $gifts = [];
        foreach (range(1, $n) as $i) {
            $gifts[] = ['product_id' => fg4Product('Gift ' . $n . '-' . $i)->id, 'quantity' => 1, 'kind' => ['mask', 'mini', 'full'][$i % 3]];
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body($gifts, ['mask' => 1, 'mini' => 1, 'full' => 1], ['min' => 100 * $n, 'max' => 100 * $n + 50]))->assertOk();
        $q = collect(DB::getQueryLog())->filter(fn ($l) => ! str_starts_with(strtolower(\Tests\Support\SqlShape::portable($l['query'])), 'insert into "free_gift_range_items"'))->pluck('query')->all();
        DB::disableQueryLog();
        $q = count($q);

        return $q;
    };

    $count(2);  // the first request of a test reads the settings map; measure warm
    expect($count(30))->toBe($count(3));
});

/*
|------------------------------------------------------------------------------
| THE MIGRATION
|------------------------------------------------------------------------------
*/

it('backfills gift types by name, mini before mask, and seeds the owner\'s five criteria where unset', function () {
    /*
     * The defect: the owner opens the screen to fifty untagged gifts; or the
     * "Clay Mask Mini" his own seeded range counts as a mini lands in Masks;
     * or a second run overwrites what he has tagged since.
     * MUTATION: test 'mask' before 'mini' in guess(), drop ->whereNull('kind'),
     * or drop the whereNull('need_mask') guard on the criteria seed. RED.
     */
    $migration = require database_path('migrations/2027_11_24_100000_free_gift_kinds.php');

    $sheet = fg4Product('Tea Tree SHEET Mask');
    $clay = fg4Product('Mugwort Calming Clay Mask Mini');
    $pouch = fg4Product('Cleansing Foam Travel Pouch');
    $foam = fg4Product('Senka Perfect Whip');
    $mask = fg4Product('Hydrating Mask Pack');
    $set = fg4Product('Glow Serum', ['gift_kind' => 'full']);   // remembered: wins over the name

    $r100 = fg4Range(100, 299, [[$sheet, 2]]);
    $r300 = fg4Range(300, 499, [[$clay, 1], [$pouch, 1]]);
    $r500 = fg4Range(500, 999, [[$foam, 1], [$mask, 1], [$set, 1]]);
    $r1000 = fg4Range(1000, 1999, [[$foam, 1, 'mask']], ['need_mask' => 9, 'need_mini' => 9, 'need_full' => 9]);
    $r2000 = fg4Range(2000, null);
    $other = fg4Range(250, 600);

    $migration->up();
    $migration->up();  // idempotent

    $kinds = DB::table('free_gift_range_items')->get()->mapWithKeys(fn ($i) => [$i->range_id . ':' . $i->product_id => $i->kind])->all();
    expect($kinds)->toBe([
        $r100 . ':' . $sheet->id => 'mask',
        $r300 . ':' . $clay->id => 'mini',
        $r300 . ':' . $pouch->id => 'mini',
        $r500 . ':' . $foam->id => null,
        $r500 . ':' . $mask->id => 'mask',
        $r500 . ':' . $set->id => 'full',
        $r1000 . ':' . $foam->id => 'mask',   // tagged already: untouched
    ]);

    // The product's memory, for the next add -- never for a product with no guess.
    expect(DB::table('products')->whereIn('id', [$sheet->id, $clay->id, $foam->id, $set->id])->pluck('gift_kind', 'id')->all())
        ->toBe([$sheet->id => 'mask', $clay->id => 'mini', $foam->id => null, $set->id => 'full']);

    $needs = fn (int $id) => array_values((array) DB::table('free_gift_ranges')->where('id', $id)->first(['need_mask', 'need_mini', 'need_full']));
    expect(array_map('intval', $needs($r100)))->toBe([2, 0, 0])
        ->and(array_map('intval', $needs($r300)))->toBe([3, 1, 0])
        ->and(array_map('intval', $needs($r500)))->toBe([3, 2, 1])
        ->and(array_map('intval', $needs($r1000)))->toBe([9, 9, 9])  // set by the owner: kept
        ->and(array_map('intval', $needs($r2000)))->toBe([12, 5, 3])
        ->and($needs($other))->toBe([null, null, null]);              // not one of the owner's five
});

it('adds nullable columns, so every existing row and the shop keep working, and rolls back cleanly', function () {
    /*
     * The defect: a NOT NULL column without a default fails the migration on
     * MySQL for a table with rows, or an insert that does not name it (the
     * seed migration, the shop's own code) starts failing.
     * MUTATION: drop ->nullable() from any of the five columns. RED.
     */
    $migration = require database_path('migrations/2027_11_24_100000_free_gift_kinds.php');
    $p = fg4Product('Plain');
    $id = fg4Range(100, 299, [[$p, 1]]);
    // An insert that names none of the new columns -- as 2027_11_22_100100_free_gifts_seed does.
    DB::table('free_gift_ranges')->insert(['min_fils' => 50000, 'max_fils' => null, 'label_en' => 'x', 'label_ar' => 'x', 'sort' => 1, 'enabled' => true]);

    $migration->down();
    expect(Illuminate\Support\Facades\Schema::hasColumn('free_gift_range_items', 'kind'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Schema::hasColumn('products', 'gift_kind'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Schema::hasColumn('free_gift_ranges', 'need_mask'))->toBeFalse()
        ->and(DB::table('free_gift_range_items')->where('range_id', $id)->count())->toBe(1);
    $migration->down();  // twice: guarded

    $migration->up();
    foreach ([['free_gift_range_items', 'kind'], ['products', 'gift_kind'], ['free_gift_ranges', 'need_mask'], ['free_gift_ranges', 'need_mini'], ['free_gift_ranges', 'need_full']] as [$table, $col]) {
        $info = collect(DB::select('PRAGMA table_info(' . $table . ')'))->firstWhere('name', $col);
        expect($info)->not->toBeNull()->and((int) $info->notnull)->toBe(0);
    }
});

/*
|------------------------------------------------------------------------------
| SHORT / OVER, ON THE SERVER AND ON THE SCREEN
|------------------------------------------------------------------------------
*/

it('counts gift units per type against the criteria, and lists each difference on Overview with its range', function () {
    /*
     * The defect: "2x Sheet Mask" counted as one mask, or Overview silent
     * about a range one mini short.
     * MUTATION: count `1` per gift in tally() instead of its quantity, or
     * swap the '<' and '>' branches in criteriaGaps(). RED.
     */
    expect(FreeGifts::tally([['kind' => 'mask', 'quantity' => 2], ['kind' => 'mask', 'quantity' => 1], ['kind' => 'mini', 'quantity' => 1], ['kind' => 'junk', 'quantity' => 2], ['kind' => null, 'quantity' => 1]]))
        ->toBe(['mask' => 3, 'mini' => 1, 'full' => 0, 'none' => 3]);

    expect(array_column(FreeGifts::criteriaGaps(['mask' => 3, 'mini' => 2, 'full' => 1], ['mask' => 4, 'mini' => 1, 'full' => 1, 'none' => 0]), 'text'))->toBe([
        'Face masks: 4 chosen, criteria says 3.',
        'Mini products: 1 of 2 chosen — add 1 more.',
    ])->and(FreeGifts::criteriaGaps(['mask' => 3, 'mini' => 2, 'full' => 1], ['mask' => 3, 'mini' => 2, 'full' => 1, 'none' => 0]))->toBe([])
        ->and(FreeGifts::criteriaGaps(null, ['mask' => 9, 'mini' => 0, 'full' => 0, 'none' => 4]))->toBe([])
        ->and(FreeGifts::needsOf(['need_mask' => 0, 'need_mini' => 0, 'need_full' => 0]))->toBeNull()
        ->and(FreeGifts::needsOf(['need_mask' => null, 'need_mini' => 2, 'need_full' => null]))->toBe(['mask' => 0, 'mini' => 2, 'full' => 0]);

    $mask = fg4Product('Sheet Mask');
    $mini = fg4Product('Toner Mini');
    $foam = fg4Product('Foam');
    $short = fg4Range(500, 999, [[$mask, 2, 'mask'], [$mini, 1, 'mini'], [$foam, 1]], ['need_mask' => 3, 'need_mini' => 2, 'need_full' => 1]);
    $fine = fg4Range(100, 299, [[$mask, 2, 'mask']], ['need_mask' => 2, 'need_mini' => 0, 'need_full' => 0]);

    $items = collect(app(FreeGifts::class)->checklist());
    expect($items->where('range', $short)->pluck('text')->values()->all())->toBe([
        'AED 500 – 999: Face masks: 2 of 3 chosen — add 1 more.',
        'AED 500 – 999: Mini products: 1 of 2 chosen — add 1 more.',
        'AED 500 – 999: Full size products: 0 of 1 chosen — add 1 more.',
        'AED 500 – 999: 1 gift is untagged, so not counted — set its type.',
    ])->and($items->where('range', $short)->pluck('level')->unique()->all())->toBe(['warn'])
        ->and($items->where('range', $fine)->all())->toBe([]);

    // Overview reads it through the payload.
    $payload = test()->actingAs(fg4Admin(), 'admin')->getJson('/admin-api/free-gifts')->assertOk();
    expect(collect($payload->json('checklist'))->where('range', $short)->count())->toBe(4);
});

it('never blocks Save, Testing or Live for unmet criteria', function () {
    /*
     * The owner, explicitly: a warning only. "Unless we change the whole
     * range criteria" means the steppers are the fix, never a refusal.
     * MUTATION: throw a ValidationException in saveRange() when
     * criteriaGaps() is not empty, or refuse switch() on a 'warn'. RED.
     */
    $mask = fg4Product('Sheet Mask');
    $id = fg4Range(100, 299);
    $owner = fg4Admin();

    test()->actingAs($owner, 'admin')->putJson('/admin-api/free-gifts/ranges/' . $id, fg4Body([['product_id' => $mask->id, 'quantity' => 1, 'kind' => 'mask']], ['mask' => 12, 'mini' => 5, 'full' => 3], ['min' => 100, 'max' => 299]))->assertOk();
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/ranges', fg4Body([['product_id' => $mask->id, 'quantity' => 50, 'kind' => 'mask']], ['mask' => 1, 'mini' => 0, 'full' => 0], ['min' => 300, 'max' => 499]))->assertOk();

    expect(collect(app(FreeGifts::class)->checklist())->where('level', 'warn')->count())->toBeGreaterThan(0);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'testing'])->assertOk()->assertJsonPath('config.mode', 'testing');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/free-gifts/switch', ['mode' => 'live'])->assertOk()->assertJsonPath('config.mode', 'live');
});

it('says the same things on the screen as on the server: counts, short/over, wording -- run in node', function () {
    /*
     * The screen counts and words as the owner edits, without asking the
     * server; if its copy drifts, the box says "2 of 3" while Overview says
     * something else, or "Use for wording" writes a different Arabic from the
     * one this file pins.
     * MUTATION: change any word in WORDS, or `n <= 10` in wordingFor(), or
     * `got < need` in criteriaGaps(), in free-gifts-screen.blade.php. RED.
     */
    $html = test()->actingAs(fg4Admin(), 'admin')->get('/admin')->assertOk()->getContent();
    $from = strpos($html, '/* ------------------------------------------- gift types and criteria (FG4)');
    $to = strpos($html, '  function kindPill(', (int) $from);
    expect($from)->not->toBeFalse()->and($to)->not->toBeFalse();
    $block = substr($html, (int) $from, (int) $to - (int) $from);

    $cases = [];
    foreach ([[0, 0, 0], [1, 1, 1], [2, 2, 2], [3, 2, 1], [2, 0, 0], [3, 1, 0], [5, 2, 2], [12, 5, 3], [11, 0, 10], [0, 1, 0], [50, 50, 50]] as [$a, $b, $c]) {
        $cases[] = ['needs' => ['mask' => $a, 'mini' => $b, 'full' => $c]];
    }
    $gifts = [[['kind' => 'mask', 'quantity' => 2], ['kind' => 'mini', 'quantity' => 1], ['kind' => null, 'quantity' => 3]], [['kind' => 'full', 'quantity' => 4]], []];

    $script = $block . "\nvar C = " . json_encode($cases) . ', G = ' . json_encode($gifts) . ";\n"
        . "console.log(JSON.stringify({ w: C.map(function (c) { return wordingFor(c.needs); }), "
        . "g: G.map(function (g) { var t = tally(g); return { t: t, gaps: C.map(function (c) { return criteriaGaps(needsOf(c), t).map(function (x) { return x.text; }); }) }; }) }));";

    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    $file = $dir . '/fg4-parity-' . getmypid() . '.js';
    file_put_contents($file, $script);
    $process = new Process(['node', $file]);
    $process->run();
    @unlink($file);

    if (! $process->isSuccessful() && str_contains($process->getErrorOutput() . $process->getOutput(), 'not found')) {
        test()->markTestSkipped('node is not installed here: the screen copy could not be run.');
    }
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    $js = json_decode(trim($process->getOutput()), true);

    foreach ($cases as $i => $c) {
        expect($js['w'][$i])->toBe(FreeGifts::criteriaWording($c['needs']));
    }
    foreach ($gifts as $j => $g) {
        $t = FreeGifts::tally($g);
        expect($js['g'][$j]['t'])->toBe($t);
        foreach ($cases as $i => $c) {
            $needs = FreeGifts::needsOf(['need_mask' => $c['needs']['mask'], 'need_mini' => $c['needs']['mini'], 'need_full' => $c['needs']['full']]);
            expect($js['g'][$j]['gaps'][$i])->toBe(array_column(FreeGifts::criteriaGaps($needs, $t), 'text'));
        }
    }
});

it('renders the boxes, the steppers, the notice and the typed picker, and keeps the screen\'s promises', function () {
    /*
     * The defect: the boxes and notice exist in a branch nobody can reach --
     * not rendered, or rendered but painting outside ours(), or a toast with
     * the owner's product name unescaped.
     * MUTATION: drop giftBoxes() from rangeBodyEl(), the "Add mini products"
     * box button, or the `&kind=` on the one picker request. RED.
     */
    $html = test()->actingAs(fg4Admin(), 'admin')->get('/admin')->assertOk()->getContent();
    $at = strpos($html, "var SCREEN = 'freegifts';");
    $js = substr($html, $at, strpos($html, '</script>', $at) - $at);

    expect($js)->toContain("var giftList = giftBoxes(id, key, x, ro);")
        ->and($js)->toContain("var crit = criteriaEl(id, x, ro, words, changed);")
        ->and($js)->toContain("['mask', 'Face masks', 'Masks'")
        ->and($js)->toContain("['mini', 'Mini products', 'Minis'")
        ->and($js)->toContain("['full', 'Full size products', 'Full size'")
        ->and($js)->toContain("rows.push(['none', 'Untagged'")
        ->and($js)->toContain("onclick: function () { openPicker(id, kk, 'primary'); }")
        ->and($js)->toContain("onclick: function () { openPicker(id, kk, 'backup'); }")
        ->and($js)->toContain("var kind = kindOf(pr.gift_kind) || p.box;")
        ->and($js)->toContain("'Which box?'")
        ->and($js)->toContain("[['all', 'All'], ['mask', 'Masks'], ['mini', 'Minis'], ['full', 'Full size'], ['none', 'Untagged']]")
        ->and($js)->toContain("'&kind=' + encodeURIComponent(p.kind)")
        ->and($js)->toContain("'Not as the criteria say — a warning only: Save, Testing and Live still work.'")
        ->and($js)->toContain("cnt.className = 'fg-cnt ' + (have === need ? 'ok' : have < need ? 'short' : 'over');")
        ->and($js)->toContain("['Use for wording']")
        ->and($js)->toContain("'aria-label': label + ' needed'")
        // The tab's warning mark and its tooltip carry the criteria.
        ->and($js)->toContain("var crit = criteriaGaps(needsOf(x), tally(x.gifts)).map(function (c) { return c.text; }).join(' ');")
        // The save sends the type and the criteria.
        ->and($js)->toContain("kind: kindOf(g.kind), role: g.role === 'backup' ? 'backup' : 'primary' }; }), needs: needsOf(x) ?");

    // One search path, the old promises intact.
    expect(substr_count($js, "'/products?q='"))->toBe(1)
        ->and($js)->toContain('if (!c || !ours()) return;')
        ->and($js)->toContain('function say(m, kind) { try { window.toast(esc(m), kind); } catch (e) {} }')
        ->and($js)->not->toContain('window.confirm')
        ->and($js)->not->toContain('setInterval')
        ->and($js)->not->toContain('getBoundingClientRect');

    // Neither the box's live repaint nor the picker writes anything the owner typed as HTML.
    expect(preg_match_all("/html: (?!ICONS\\.|k\\[5\\])/", $js))->toBe(0);
});

/*
|------------------------------------------------------------------------------
| THE WORDING
|------------------------------------------------------------------------------
*/

it('words the criteria in English and Arabic, singular and plural, zeros left out, as the owner\'s own ranges read', function () {
    /*
     * MUTATION: drop the `$n === 0` skip, or the 1/2/3-10/11+ Arabic forms. RED.
     */
    $w = fn (int $a, int $b, int $c) => FreeGifts::criteriaWording(['mask' => $a, 'mini' => $b, 'full' => $c]);

    // The owner's seeded wording, full size first (Lane GBX, 28 October: "full
    // size products, mini products and then mask"). MUTATION: put WORDS back
    // in mask, mini, full order. RED.
    expect($w(2, 0, 0))->toBe(['en' => '2 Masks', 'ar' => 'قناعان'])
        ->and($w(3, 1, 0))->toBe(['en' => '1 Mini Product + 3 Masks', 'ar' => 'منتج صغير واحد + 3 أقنعة'])
        ->and($w(3, 2, 1))->toBe(['en' => '1 Full Size Product + 2 Mini Products + 3 Masks', 'ar' => 'منتج بالحجم الكامل + منتجان صغيران + 3 أقنعة'])
        ->and($w(5, 2, 2)['ar'])->toBe('منتجان بالحجم الكامل + منتجان صغيران + 5 أقنعة')
        ->and($w(12, 5, 3)['ar'])->toBe('3 منتجات بالحجم الكامل + 5 منتجات صغيرة + 12 قناعاً');

    expect($w(1, 0, 0))->toBe(['en' => '1 Mask', 'ar' => 'قناع واحد'])
        ->and($w(0, 1, 1))->toBe(['en' => '1 Full Size Product + 1 Mini Product', 'ar' => 'منتج بالحجم الكامل + منتج صغير واحد'])
        ->and($w(0, 0, 2))->toBe(['en' => '2 Full Size Products', 'ar' => 'منتجان بالحجم الكامل'])
        ->and($w(0, 11, 0))->toBe(['en' => '11 Mini Products', 'ar' => '11 منتجاً صغيراً'])
        ->and($w(0, 0, 0))->toBe(['en' => '', 'ar' => '']);

    // It never writes on its own: the screen fills the wording only from the button.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/free-gifts-screen.blade.php'));
    expect(substr_count($screen, 'x.label_en = w.en'))->toBe(1);
});

/*
|------------------------------------------------------------------------------
| THE SHOP NEVER SEES ANY OF IT
|------------------------------------------------------------------------------
*/

it('draws the shop, the checkout and the order byte-identically with or without types and criteria', function () {
    /*
     * The owner, twice: admin-only, internal understanding. The defect would
     * be a "Mask" label, a count or a reordered gift in the cart panel, the
     * cart page, the checkout, or on the order.
     * MUTATION: add 'kind' to FreeGifts::compile()'s gifts, or print
     * $range['needs'] in partials/free-gifts/box. RED.
     */
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery', 'cost' => 2000, 'enabled' => true, 'position' => 0]);
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $mask = fg4Product('Sheet Mask', ['gift_only' => true]);
    $mini = fg4Product('Toner Mini', ['gift_only' => true]);
    $foam = fg4Product('Senka Foam', ['gift_only' => true]);
    $id = fg4Range(100, 299, [[$mask, 2], [$mini, 1], [$foam, 1]], ['label_en' => '2 Masks']);
    app(SettingsService::class)->set(FreeGifts::KEY, ['mode' => 'live', 'on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);
    $serum = fg4Product('Serum', ['price' => 15000]);

    $newCart = function () use ($serum) {
        $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
        $cart->items()->create(['product_id' => $serum->id, 'quantity' => 1, 'unit_price' => 15000]);

        return $cart;
    };
    $browsing = $newCart();  // the SAME basket is drawn both times, so line ids match
    $shop = function () use ($newCart, $browsing) {
        app(FreeGifts::class)->compile();
        fg4Fresh();
        $as = function ($cart) {
            app(CartService::class)->forget();

            return test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)->withUnencryptedCookie(CartService::COOKIE, $cart->token);
        };
        $strip = fn (string $h) => preg_replace(['/(name="_token"[^>]*value=|"csrf-token" content=|csrf["\']?\s*[:=]\s*)["\'][^"\']*["\']/i', '/nonce="[^"]*"/'], ['$1""', 'nonce=""'], $h);
        $out = [
            'compiled' => json_encode(app(SettingsService::class)->get(FreeGifts::COMPILED)),
            'drawer' => $strip((string) $as($browsing)->getJson('/api/cart/drawer')->json('html')),
            'cart' => $strip($as($browsing)->get('/cart/')->getContent()),
            'checkout' => $strip($as($browsing)->get('/checkout/')->getContent()),
        ];
        $as($newCart())->post('/checkout/place', ['billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000', 'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai', 'billing_country' => 'AE', 'payment_method' => 'cod'])->assertRedirect();
        $order = Order::latest('id')->first();
        $out['order'] = json_encode([(int) $order->free_gift_count, $order->gifts->map(fn ($i) => [$i->product_id, $i->name, $i->quantity, $i->total, $i->gift_range_label])->all()]);
        $out['rows'] = json_encode(FreeGifts::orderRows($order));

        return $out;
    };

    $plain = $shop();
    expect($plain['drawer'])->toContain('Sheet Mask');

    DB::table('free_gift_range_items')->where('product_id', $mask->id)->update(['kind' => 'mask']);
    DB::table('free_gift_range_items')->where('product_id', $mini->id)->update(['kind' => 'mini']);
    DB::table('free_gift_range_items')->where('product_id', $foam->id)->update(['kind' => 'full']);
    DB::table('products')->whereIn('id', [$mask->id, $mini->id, $foam->id])->update(['gift_kind' => 'mask']);
    DB::table('free_gift_ranges')->where('id', $id)->update(['need_mask' => 3, 'need_mini' => 2, 'need_full' => 1]);

    $tagged = $shop();
    /*
     * ▲ 23 October 2026, the owner's main/backup ask: the counts now DECIDE
     * what is given and the sentence is written from what is given, so the
     * box's words move on purpose ("2 Masks" -> "2 Masks + 1 Mini Product +
     * 1 Full Size Product") and the compiled copy carries the types. What must
     * still not move is what was given -- the order's gift lines -- and the
     * internal words must still never reach a shopper (below).
     */
    /*
     * ▲ 28 October 2026 (Lane GBX), the owner: "full size products, mini
     * products and then mask". The same gifts in the same quantities are
     * given; only their ORDER follows the types now (full size first).
     */
    $sorted = static function (string $json): string {
        $a = json_decode($json, true);
        $list = is_array($a[1] ?? null) && array_is_list($a) ? $a[1] : $a;
        usort($list, static fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));

        return json_encode($list);
    };
    foreach (['order', 'rows'] as $k) {
        expect($sorted($tagged[$k]))->toBe($sorted($plain[$k]), $k . ' changed when gifts were typed');
    }
    expect(json_decode($tagged['order'], true)[1][0][1])->toBe('Senka Foam');
    expect($tagged['drawer'])->toContain('1 Full Size Product + 1 Mini Product + 2 Masks');
    foreach ($tagged as $k => $bytes) {
        expect($bytes)->not->toContain('gift_kind')->not->toContain('need_mask')->not->toContain('Face masks');
    }
});

it('never puts a type or a criterion in a public /api payload', function () {
    /*
     * /api/* is unauthenticated (CLAUDE.md). MUTATION: return the model
     * instead of Product::toApi(), or add gift_kind to it. RED.
     */
    $p = fg4Product('Public Serum', ['gift_kind' => 'full']);
    fg4Range(100, 299, [[$p, 1, 'full']], ['need_mask' => 2, 'need_mini' => 0, 'need_full' => 1]);
    app(FreeGifts::class)->compile();
    fg4Fresh();

    foreach (['/api/products', '/api/products/' . $p->slug, '/api/settings'] as $url) {
        $body = test()->getJson($url)->getContent();
        expect($body)->not->toContain('gift_kind')->not->toContain('need_mask')->not->toContain('"kind"');
    }
    expect(test()->getJson('/api/products/' . $p->slug)->assertOk()->getContent())->toContain('Public Serum');
});
