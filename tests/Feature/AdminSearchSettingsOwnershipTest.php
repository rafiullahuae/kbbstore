<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\EcommerceApiController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who owns the search settings, and does saving one actually change search?
 *
 * Store -> Ecommerce carried a "Search" tab with four fields. One of them,
 * `search_limit_products`, had no reader anywhere. The other three did have
 * readers -- and were still inert from that screen, because of WHERE the two
 * screens write:
 *
 *   HeaderSettings stores every one of its fields inside a SINGLE settings row
 *   called `header_settings`, a JSON blob, and SearchController reads them
 *   through it. EcommerceApiController::save() calls SettingsService::set(),
 *   which writes a TOP-LEVEL row named `search_min_chars`. Nothing reads that
 *   row. show() then read the value back from the same top-level key it wrote,
 *   so the screen looked like it was working.
 *
 * THESE TESTS ASSERT THE CONSEQUENCE, NOT THE FIELD LIST. A test that only
 * checked "the key was written" passes for a setting nothing reads -- that is
 * precisely the bug. So each one saves through a real admin endpoint and then
 * asks /api/search what the shopper is actually shown.
 *
 * The last test is the general guard. It is not about these four names: it
 * fails for ANY field a settings screen adds whose name belongs to
 * HeaderSettings, because such a field writes to a store its reader will never
 * look in.
 */
beforeEach(function () {
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    DB::table('categories')->delete();

    Cache::flush();
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
});

function searchOwnerAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Search Owner',
        'email' => 'search-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Eight matching products, so any panel limit from 3 to 8 is observable. */
function searchCatalogue(): void
{
    $brand = Brand::create(['name' => 'Lumibright', 'slug' => 'lumibright-' . uniqid()]);

    for ($i = 1; $i <= 8; $i++) {
        Product::create([
            'slug' => 'sunscreen-' . $i . '-' . uniqid(),
            'name' => 'Sunscreen ' . $i,
            'status' => 'publish',
            'is_visible' => true,
            'price' => 10000,
            'stock_status' => 'instock',
            'brand_id' => $brand->id,
            'total_sales' => 100 - $i,
        ]);
    }
}

/**
 * How many products the suggestion panel actually lists.
 *
 * The caches are dropped first because SearchController memoises a built
 * payload for five minutes under a key that includes the limits, and
 * SettingsService keeps a process-level memo -- the trap CLAUDE.md records.
 */
function productsInSuggestPanel(): int
{
    Cache::flush();
    SettingsService::forgetMemo();

    $groups = test()->get('/api/search?q=Sunscreen')->assertOk()->json('groups') ?? [];

    foreach ($groups as $group) {
        if (($group['key'] ?? '') === 'products') {
            return count($group['items']);
        }
    }

    return 0;
}

it('changes what the panel returns when Site Search saves the product limit', function () {
    searchOwnerAdmin();
    searchCatalogue();

    // The default is 5, and the catalogue has 8, so a change to 3 is visible
    // in both directions rather than being masked by the number of matches.
    expect(productsInSuggestPanel())->toBe(5);

    test()->postJson('/admin-api/site-search', ['settings' => ['search_results_max' => 3]])
        ->assertOk();

    expect(productsInSuggestPanel())->toBe(3);

    test()->postJson('/admin-api/site-search', ['settings' => ['search_results_max' => 8]])
        ->assertOk();

    expect(productsInSuggestPanel())->toBe(8);
});

it('applies the same product limit on the extended brand-match path', function () {
    searchOwnerAdmin();
    searchCatalogue();

    /*
     * SearchController reads `search_results_max` at FOUR call sites, and a
     * test that only walks one of them proves only that one. This is the
     * extended path: with extended search on, a query that IS a brand name is
     * answered by buildForBrand(), which reads the limit separately. A red-
     * then-green pass on the general path alone stayed green when this call
     * site was broken, which is how the gap was found.
     */
    test()->postJson('/admin-api/site-search', [
        'settings' => ['search_extended_enabled' => true, 'search_results_max' => 4],
    ])->assertOk();

    $count = function () {
        Cache::flush();
        SettingsService::forgetMemo();

        $groups = test()->get('/api/search?q=Lumibright')->assertOk()->json('groups') ?? [];

        foreach ($groups as $group) {
            if (($group['key'] ?? '') === 'products') {
                return count($group['items']);
            }
        }

        return 0;
    };

    expect($count())->toBe(4);

    test()->postJson('/admin-api/site-search', ['settings' => ['search_results_max' => 7]])
        ->assertOk();

    expect($count())->toBe(7);
});

it('applies the same product limit to the starter panel', function () {
    searchOwnerAdmin();
    searchCatalogue();

    // The fourth call site: the panel shown before anything is typed.
    $popular = function () {
        Cache::flush();
        SettingsService::forgetMemo();

        return count(test()->get('/api/search/starter')->assertOk()->json('popular') ?? []);
    };

    expect($popular())->toBe(5);

    test()->postJson('/admin-api/site-search', ['settings' => ['search_results_max' => 3]])
        ->assertOk();

    expect($popular())->toBe(3);
});

it('hides the brands group when Site Search sets its limit to zero', function () {
    searchOwnerAdmin();
    searchCatalogue();

    // "Zero hides the group" — the claim the removed tab made falsely. It is
    // true here, of the screen that owns the setting, and this pins it.
    $keys = fn () => array_column(test()->get('/api/search?q=Lumibright')->assertOk()->json('groups') ?? [], 'key');

    Cache::flush();
    SettingsService::forgetMemo();
    expect($keys())->toContain('brands');

    test()->postJson('/admin-api/site-search', ['settings' => ['search_limit_brands' => 0]])
        ->assertOk();

    Cache::flush();
    SettingsService::forgetMemo();
    expect($keys())->not->toContain('brands');
});

it('refuses a search setting that no reader knows about', function () {
    searchOwnerAdmin();

    // Site Search validates against HeaderSettings::SCHEMA, so a key nothing
    // reads cannot be stored in the first place. This is the property that
    // makes it the owner rather than a second opinion.
    test()->postJson('/admin-api/site-search', ['settings' => ['search_limit_products' => 2]])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Setting::where('key', 'search_limit_products')->exists())->toBeFalse();
});

it('no longer offers any search setting on the Ecommerce screen', function () {
    searchOwnerAdmin();
    searchCatalogue();

    $tabs = test()->get('/admin-api/ecommerce')->assertOk()->json('tabs');

    $offered = [];

    foreach ($tabs as $tab) {
        foreach ($tab['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $offered[] = $field['name'];
            }
        }
    }

    expect(array_filter($offered, fn ($n) => str_starts_with($n, 'search_')))->toBe([]);

    /*
     * And the consequence, which is the half that matters. The field list
     * above could be satisfied by a rename; this cannot. Posting the old key
     * must neither be stored nor change the panel.
     */
    $before = productsInSuggestPanel();

    test()->postJson('/admin-api/ecommerce', ['settings' => ['search_limit_products' => 1]])
        ->assertOk()
        ->assertJsonPath('saved', 0);

    expect(Setting::where('key', 'search_limit_products')->exists())->toBeFalse()
        ->and(productsInSuggestPanel())->toBe($before);
});

it('never lets a settings screen write a key that HeaderSettings owns', function () {
    /*
     * The general guard, and the reason the four dead fields are gone rather
     * than repointed.
     *
     * EcommerceApiController writes with SettingsService::set($name, ...),
     * which makes a top-level settings row. HeaderSettings reads its keys out
     * of the `header_settings` blob and never looks at a top-level row. So any
     * field here whose name is also a HeaderSettings key is, by construction,
     * written somewhere its reader will not look -- inert, and inert in the
     * quietest possible way, because the screen reads its own value back and
     * displays it.
     *
     * This fails for the next such field as well as for the four that existed,
     * which is the point: it is about the collision, not the names.
     */
    $schema = (new ReflectionClass(EcommerceApiController::class))->getMethod('schema');
    $schema->setAccessible(true);

    $tabs = $schema->invoke(app(EcommerceApiController::class));

    $names = [];

    foreach ($tabs as $tab) {
        foreach (array_keys($tab['fields'] ?? []) as $name) {
            $names[] = $name;
        }
    }

    expect($names)->not->toBeEmpty();

    $collisions = array_values(array_intersect($names, array_keys(HeaderSettings::SCHEMA)));

    expect($collisions)->toBe([]);
});
