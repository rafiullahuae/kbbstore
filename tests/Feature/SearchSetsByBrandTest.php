<?php

declare(strict_types=1);

/**
 * Store -> Site Search -> Sets in search -> "Set shown first, by brand".
 *
 * THE OWNER'S ASK (1 October 2026), after "sets first" shipped: "if multiple
 * sets in anua, then it should display random on every search or i should also
 * control to choose which set need to display against every brand in search
 * box." Random (and best seller) was already the rule; this is the control --
 * one chosen set per brand, which is shown at #1 whenever the search names that
 * brand, whatever the rule says. A brand left on "Follow the rule above" keeps
 * the rule.
 *
 * What each case pins, and the defect it would have caught on the shop:
 *
 *   1. The chosen Anua set is #1 on every search for Anua, under the random
 *      rule, 25 times running -- not "usually". And Medicube, with no choice
 *      of its own, still rotates.
 *   2. Unpublish the chosen set, or delete it: the box does not 500, does not
 *      show a hidden product, and quietly goes back to the rule.
 *   3. The save refuses a set that does not fit the brand (another brand's
 *      set holding none of its products), an ordinary product, an unknown
 *      brand -- and writes NOTHING, not even the ordinary settings beside it.
 *   4. The admin list is one row per brand that has a fitting set, with its
 *      count, and costs one query however many brands there are.
 *   5. The search box costs the same number of queries with a choice saved as
 *      without.
 *
 * MUTATIONS, RUN (each red, then restored):
 *   - setFirst() ignores the saved map (`$chosen = null;`): case 1 red -- the
 *     25 searches for Anua rotate among its three sets at #1, not the chosen
 *     one; case 5 red as well.
 *   - setCandidates() drops ->visible(): case 2 red -- the unpublished set is
 *     still #1.
 *   - SearchSetChoices::clean() skips the fits-this-brand check: case 3 red --
 *     the Medicube-only set saves against Anua (200, expected 422).
 *   - SearchSetChoices::fitting() drops the `$held` half of the UNION: case 4
 *     red -- the Medicube set holding an Anua toner is missing from Anua's row
 *     (count 2, expected 3); case 1 red too, its save is refused.
 *   - SearchSetChoices::map() reads its row from the table on each call
 *     instead of from the autoloaded payload: case 5 red -- a repeat search
 *     costs 1 query instead of 0; case 4 red (2 queries for the list).
 *   - The first draft memoised the map on a controller property. Laravel
 *     keeps the controller on its Route, so the map outlived the request and
 *     a choice saved after the first search was never seen: case 1 was red
 *     ('Anua Toner Duo' where the newly chosen 'Glow Mix Set' belonged).
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use App\Support\SearchSetChoices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('product_set_items')->delete();
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    DB::table('settings')->where('key', SearchSetChoices::KEY)->delete();

    Cache::flush();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function sbProduct(string $name, ?Brand $brand, array $extra = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'sb-'.$n.'-'.uniqid(),
        'name' => $name,
        'brand_id' => $brand?->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'total_sales' => 100 - $n,
    ], $extra));
}

function sbSet(string $name, ?Brand $brand, array $members, int $sales = 0): Product
{
    $set = sbProduct($name, $brand, ['type' => 'set', 'total_sales' => $sales]);

    foreach ($members as $i => $member) {
        ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $member->id, 'quantity' => 1, 'position' => $i]);
    }

    return $set;
}

/** Anua with three fitting sets (two its own, one Medicube set holding an Anua toner), Medicube with two. */
function sbShop(): array
{
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'anua']);
    $medicube = Brand::create(['name' => 'Medicube', 'slug' => 'medicube']);
    $althea = Brand::create(['name' => 'Dr.Althea', 'slug' => 'dr-althea']);

    $toner = sbProduct('Anua - Heartleaf 77% Toner', $anua, ['total_sales' => 900]);
    $serum = sbProduct('Anua - Niacinamide Serum', $anua, ['total_sales' => 800]);
    $booster = sbProduct('Medicube - AGE-R Booster Pro', $medicube, ['total_sales' => 700]);
    $pad = sbProduct('Medicube - Zero Pore Pad', $medicube, ['total_sales' => 600]);
    sbProduct('Dr.Althea - 345 Relief Cream', $althea, ['total_sales' => 500]);

    $anuaBest = sbSet('Anua Best Seller Set', $anua, [$toner, $serum], 50);
    $anuaDuo = sbSet('Anua Toner Duo', $anua, [$toner], 10);
    $mixed = sbSet('Glow Mix Set', $medicube, [$booster, $toner], 30);
    $medOnly = sbSet('Medicube Pore Set', $medicube, [$pad], 20);

    return compact('anua', 'medicube', 'althea', 'anuaBest', 'anuaDuo', 'mixed', 'medOnly', 'toner');
}

function sbFirst(string $q): ?string
{
    $json = test()->getJson('/api/search?q='.urlencode($q))->assertOk()->json();

    foreach ($json['groups'] as $group) {
        if ($group['key'] === 'products') {
            // The match id is internal and must never reach the shopper.
            expect($group['items'][0])->not->toHaveKey('id');

            return $group['items'][0]['label'] ?? null;
        }
    }

    return null;
}

function sbOwner(string $role = 'owner'): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Sets Owner',
        'email' => 'sets-'.uniqid().'@example.test',
        'password' => 'password-long-enough',
        'role' => $role,
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

it('shows the chosen set first for its brand on every search, and leaves another brand on the rule', function () {
    $s = sbShop();
    sbOwner();

    app(HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'random']);

    // The LEAST-selling Anua set, so neither "best" nor luck could explain it.
    test()->postJson('/admin-api/site-search', [
        'settings' => ['search_sets_pick' => 'random'],
        'sets_by_brand' => [$s['anua']->id => $s['anuaDuo']->id, $s['medicube']->id => 0],
    ])->assertOk()->assertJsonPath('ok', true);

    expect(SearchSetChoices::map())->toBe([$s['anua']->id => $s['anuaDuo']->id]);

    $anuaFirsts = [];
    $medFirsts = [];

    for ($i = 0; $i < 25; $i++) {
        $anuaFirsts[sbFirst('anua')] = true;
        $medFirsts[sbFirst('medicube')] = true;
    }

    expect(array_keys($anuaFirsts))->toBe(['Anua Toner Duo'])
        // Medicube has no choice: still a different one of ITS sets each search.
        ->and(array_keys($medFirsts))->toEqualCanonicalizing(['Glow Mix Set', 'Medicube Pore Set']);

    // It wins over "best" too.
    app(HeaderSettings::class)->save(['search_sets_pick' => 'best']);
    expect(sbFirst('anua'))->toBe('Anua Toner Duo')
        ->and(sbFirst('medicube'))->toBe('Glow Mix Set');

    // A set held by another brand's box can be Anua's choice as well.
    test()->postJson('/admin-api/site-search', [
        'settings' => [],
        'sets_by_brand' => [$s['anua']->id => $s['mixed']->id],
    ])->assertStatus(422); // `settings` is required, as before this lane
    test()->postJson('/admin-api/site-search', [
        'settings' => ['search_sets_first' => true],
        'sets_by_brand' => [$s['anua']->id => $s['mixed']->id],
    ])->assertOk();
    expect(sbFirst('anua'))->toBe('Glow Mix Set');

    // Switched off, nothing is forced to the top, chosen or not.
    app(HeaderSettings::class)->save(['search_sets_first' => false]);
    expect(sbFirst('anua'))->toBe('Anua - Heartleaf 77% Toner');
});

it('falls back to the rule, silently, when the chosen set is unpublished or deleted', function () {
    $s = sbShop();
    app(HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'best']);
    SearchSetChoices::save([$s['anua']->id => $s['anuaDuo']->id]);

    expect(sbFirst('anua'))->toBe('Anua Toner Duo');

    // Unpublished: the best-selling Anua set takes #1, and the hidden set is
    // nowhere in the panel.
    $s['anuaDuo']->update(['status' => 'draft']);
    Cache::flush();
    $json = test()->getJson('/api/search?q=anua')->assertOk()->json();
    expect(sbFirst('anua'))->toBe('Anua Best Seller Set')
        ->and(json_encode($json))->not->toContain('Anua Toner Duo');

    // Deleted outright: the same, and no error.
    $s['anuaDuo']->update(['status' => 'publish']);
    $s['anuaDuo']->delete();
    Cache::flush();
    expect(sbFirst('anua'))->toBe('Anua Best Seller Set');

    // The admin row no longer offers it, and shows "Follow the rule" rather
    // than a selection it could not save back.
    sbOwner();
    $rows = collect(test()->getJson('/admin-api/site-search')->assertOk()->json('sets_by_brand'))->keyBy('brand');
    expect($rows['Anua']['chosen'])->toBe(0)
        ->and(array_column($rows['Anua']['sets'], 'name'))->not->toContain('Anua Toner Duo');
});

it('refuses, and writes nothing, for a set that does not fit the brand or a brand that does not exist', function () {
    $s = sbShop();
    sbOwner();
    $plain = Product::query()->where('name', 'Anua - Niacinamide Serum')->first();
    $hidden = sbSet('Anua Hidden Set', $s['anua'], [$s['toner']]);
    $hidden->update(['is_visible' => false]);

    $before = app(HeaderSettings::class)->get('search_sets_pick');

    foreach ([
        'another brand\'s set, none of its products' => [$s['anua']->id => $s['medOnly']->id],
        'an ordinary product, not a set' => [$s['anua']->id => $plain->id],
        'a hidden set' => [$s['anua']->id => $hidden->id],
        'a set id that does not exist' => [$s['anua']->id => 999999],
        'a brand that does not exist' => [999999 => $s['anuaDuo']->id],
        'a brand with no sets at all' => [$s['althea']->id => $s['anuaDuo']->id],
        'not a number' => [$s['anua']->id => 'DROP TABLE'],
        'not a brand id' => ['anua' => $s['anuaDuo']->id],
    ] as $why => $map) {
        test()->postJson('/admin-api/site-search', [
            'settings' => ['search_sets_pick' => $before === 'best' ? 'random' : 'best'],
            'sets_by_brand' => $map,
        ])->assertStatus(422)->assertJsonPath('ok', false);

        expect(SearchSetChoices::map())->toBe([], $why)
            ->and(app(HeaderSettings::class)->get('search_sets_pick'))->toBe($before, $why.': the other settings must not be half-saved');
    }

    // Not a map at all.
    test()->postJson('/admin-api/site-search', ['settings' => ['search_sets_first' => true], 'sets_by_brand' => 'x'])->assertStatus(422);

    // A role without content.manage cannot write it (the existing Site Search
    // capability, which fails closed).
    sbOwner('support');
    test()->postJson('/admin-api/site-search', [
        'settings' => ['search_sets_first' => true],
        'sets_by_brand' => [$s['anua']->id => $s['anuaDuo']->id],
    ])->assertForbidden();
    expect(SearchSetChoices::map())->toBe([]);
});

it('lists one row per brand with a fitting set, with its count, in one query', function () {
    $s = sbShop();
    sbOwner();
    SearchSetChoices::save([$s['medicube']->id => $s['medOnly']->id]);

    // The settings payload is loaded by every admin request anyway; warm it
    // so what is counted is the list itself.
    SearchSetChoices::map();
    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });
    $rows = SearchSetChoices::adminRows();
    expect($queries)->toBe(1);

    $byBrand = collect($rows)->keyBy('brand');

    // Dr.Althea has no set and no set holds its products: no row for it.
    expect($byBrand->keys()->all())->toBe(['Anua', 'Medicube'])
        ->and($byBrand['Anua']['count'])->toBe(3)
        // Best sellers first -- the order the "best" rule uses.
        ->and(array_column($byBrand['Anua']['sets'], 'name'))->toBe(['Anua Best Seller Set', 'Glow Mix Set', 'Anua Toner Duo'])
        ->and($byBrand['Anua']['chosen'])->toBe(0)
        ->and($byBrand['Medicube']['count'])->toBe(2)
        ->and($byBrand['Medicube']['chosen'])->toBe($s['medOnly']->id);

    // Still one query with many more brands and sets.
    for ($i = 0; $i < 12; $i++) {
        $b = Brand::create(['name' => 'Brand '.$i, 'slug' => 'brand-'.$i]);
        sbSet('Set of brand '.$i, $b, [sbProduct('Thing '.$i, $b)]);
    }
    $queries = 0;
    expect(count(SearchSetChoices::adminRows()))->toBe(14)
        ->and($queries)->toBe(1);

    // And the screen carries it.
    $json = test()->getJson('/admin-api/site-search')->assertOk()->json();
    expect($json['sets_by_brand'])->toHaveCount(14)
        ->and(collect($json['tabs'])->firstWhere('key', 'sets')['fields'])->toHaveCount(2);
});

it('costs the search box no extra query when a choice is saved', function () {
    $s = sbShop();
    app(HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'random']);

    $count = function (string $q): int {
        Cache::flush();
        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();
        Setting::flushMap();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->getJson('/api/search?q='.urlencode($q))->assertOk();

        return $n;
    };

    $count('anua'); // the first request of a process pays one-off costs; compare like with like
    $without = $count('anua');
    SearchSetChoices::save([$s['anua']->id => $s['anuaDuo']->id]);
    $with = $count('anua');

    expect($with)->toBe($without)
        ->and(sbFirst('anua'))->toBe('Anua Toner Duo');

    // A cached payload is shared, and the pick on top of it is made from the
    // map already in memory: a repeat search for the same term runs no query
    // at all (measured 0; 11 cold, with or without the choice saved).
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    test()->getJson('/api/search?q=anua')->assertOk();
    expect($n)->toBe(0);
});
