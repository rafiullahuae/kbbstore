<?php

/**
 * Growth & Marketing -> Search Terms: real counts, ranked, light, and safe.
 *
 * THE OWNER'S ASK (1 October 2026): "a page under Growth > Search Terms where
 * i can see daily, weekly, monthly etc. the searched terms and counts for each
 * word/term ... list it ranked. real data, but super light."
 *
 * THREE DEFECTS FOUND ON THE WAY, each pinned here:
 *   1. The search box asked on every keystroke and EVERY request was counted,
 *      so "medicube" arrived as "me", "med", "medi", "medicube". A count is
 *      now taken only when the request says log=1 (search.js sends it once
 *      the search settles); old fragments are hidden on the page.
 *   2. A search that found nothing was dropped as noise -- the most useful row
 *      the page can show. It is counted now, and still never offered back in
 *      the panel's "most searched" list.
 *   3. The counter's upsert used GREATEST() and NOW(), which SQLite lacks,
 *      inside a catch that swallows everything: nothing was ever counted on
 *      SQLite and no test could see it.
 *
 * MUTATIONS, RUN: drop the `log` check in SearchController::suggest() -> the
 * first case is red (3 rows for one settled search); restore the `$results < 1`
 * return in SearchInsights::record() -> the second is red; put GREATEST back
 * unconditionally -> four cases are red (0 rows on SQLite); grant
 * `search_terms.view` to support -> the capability case is red (200, not 403).
 * Deleting the RULE instead stays green, correctly: an admin route with no rule
 * is refused for every role but owner, so it fails closed either way.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Services\SearchInsights;
use App\Services\SearchTermsReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('search_terms')->delete();
    Cache::flush();
});

function stProduct(string $name): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'medicube'], ['name' => 'Medicube']);

    return Product::create([
        'slug' => 'st-'.uniqid(), 'name' => $name, 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 10000, 'stock_status' => 'instock',
    ]);
}

function stAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'ST '.$role, 'email' => 'st-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-pass-1', 'role' => $role,
    ]);
}

it('counts a settled search once, and not the keystrokes on the way to it', function () {
    stProduct('Medicube Zero Pore Pad');

    foreach (['me', 'med', 'medi', 'medicube'] as $typed) {
        test()->getJson('/api/search?q='.$typed)->assertOk();
    }
    expect(DB::table('search_terms')->count())->toBe(0);

    test()->getJson('/api/search?q=medicube&log=1')->assertOk();
    test()->getJson('/api/search?q=medicube&log=1')->assertOk();

    $rows = DB::table('search_terms')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->term)->toBe('medicube')
        ->and((int) $rows[0]->hits)->toBe(2)
        ->and((int) $rows[0]->results)->toBeGreaterThan(0);
});

it('counts a search that found nothing, and keeps it out of the panel\'s most-searched list', function () {
    test()->getJson('/api/search?q=snail+cream+xyz&log=1')->assertOk();

    expect(DB::table('search_terms')->where('term', 'snail cream xyz')->value('results'))->toBe(0)
        ->and(app(SearchInsights::class)->popular(6))->not->toContain('snail cream xyz');

    $report = app(SearchTermsReport::class)->build('today', 'nothing');
    expect(array_column($report['rows'], 'term'))->toBe(['snail cream xyz'])
        ->and($report['totals']['nothing'])->toBe(1);
});

it('ranks by searches over the period, compares with the period before, and hides fragments', function () {
    $insights = app(SearchInsights::class);
    $this->travelTo(now()->subDays(8));
    $insights->record('sunscreen', 3);
    $this->travelBack();

    foreach (range(1, 5) as $i) { $insights->record('sunscreen', 3); }
    foreach (range(1, 3) as $i) { $insights->record('medicube', 4); }
    $insights->record('med', 9);          // a fragment from before settling
    $insights->record('serum set', 2);
    $insights->record('serum', 2);        // a whole word: stays

    $report = app(SearchTermsReport::class)->build('7d');
    $terms = array_column($report['rows'], 'term');

    expect($terms)->toBe(['sunscreen', 'medicube', 'serum', 'serum set'])
        ->and($report['rows'][0]['rank'])->toBe(1)
        ->and($report['rows'][0]['searches'])->toBe(5)
        ->and($report['rows'][1]['change'])->toBe('new')
        ->and($report['totals']['fragments_hidden'])->toBe(1);

    // The week before had one 'sunscreen'; 5 vs 1 is +400%.
    expect($report['rows'][0]['change'])->toBe(400);

    // And the switch shows them.
    $all = app(SearchTermsReport::class)->build('7d', 'all', '', 1, true);
    expect(array_column($all['rows'], 'term'))->toContain('med');

    // Today only, and a word filter.
    expect(array_column(app(SearchTermsReport::class)->build('today', 'all', 'sun')['rows'], 'term'))->toBe(['sunscreen']);
});

it('is behind its own capability: owner yes, support no', function () {
    app(SearchInsights::class)->record('pdrn', 3);

    test()->actingAs(stAdmin('owner'), 'admin')
        ->getJson('/admin-api/search-terms?period=today')
        ->assertOk()->assertJsonPath('rows.0.term', 'pdrn');

    test()->actingAs(stAdmin('support'), 'admin')
        ->getJson('/admin-api/search-terms?period=today')
        ->assertForbidden();

    // Inputs are held to their allowlists.
    test()->actingAs(stAdmin('owner'), 'admin')
        ->getJson('/admin-api/search-terms?period=forever')
        ->assertStatus(422);
});

it('prints every shopper-typed term escaped, and is wired into the console exactly once', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/search-terms-screen.blade.php'));

    // Every place a term reaches HTML goes through esc().
    expect($screen)->toContain("esc(r.term)")
        ->and($screen)->toContain('encodeURIComponent(r.term)')
        ->and(preg_match('/[\'"]\s*\+\s*r\.term\s*\+/', $screen))->toBe(0);

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.search-terms-screen')"))->toBe(1)
        ->and($app)->toContain("'searchterms':['Growth & Marketing','Search Terms']");

    $web = (string) file_get_contents(base_path('routes/web.php'));
    expect(substr_count($web, "Route::get('/search-terms'"))->toBe(1);
});
