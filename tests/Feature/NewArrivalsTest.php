<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\NewArrivals;
use App\Services\SettingsService;
use App\Support\ScopeOrder;
use Illuminate\Support\Facades\DB;
use Tests\Support\NewArrivalsAdminRoutes;

/*
 * Lane NA -- New Arrivals keeps itself (App\Services\NewArrivals).
 *
 * The owner: "whatever products we add, that should auto go into New arrivals
 * and display the most recent ones by default ... maximum 300 ... give to
 * change the limit ... exclude any products", "only keep the last three months
 * products", "the new products we will add that will come auto in that on the
 * top", "give ability to change the sorting ... if new product added, it will
 * come auto on the top, unless we sort it again".
 *
 * Every case says what the shop looked like without the line it pins.
 */

beforeEach(function () {
    SettingsService::forgetMemo();
    NewArrivals::resetState();
    NewArrivals::forget();
    // The migration set seeds demo products; this file counts its own.
    DB::table('products')->update(['status' => 'draft']);
});

function naCat(array $rule = []): Category
{
    $cat = Category::create(['name' => 'New Arrivals', 'slug' => 'new-arrivals']);
    NewArrivals::saveRule($cat, $rule + NewArrivals::DEFAULT_RULE);

    return $cat->fresh();
}

function naP(string $slug, string $created, array $attrs = []): Product
{
    $p = Product::create($attrs + [
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock',
    ]);
    $p->forceFill(['created_at' => $created])->save();

    return $p;
}

/** Members, in the order the shop opens them. */
function naMembers(Category $cat): array
{
    return DB::table('category_product')->join('products', 'products.id', '=', 'category_product.product_id')
        ->where('category_product.category_id', $cat->id)->orderBy('category_product.'.ScopeOrder::CATEGORY_COLUMN)->pluck('products.slug')->all();
}

/** Product slugs on a rendered page, first appearance order, limited to $only. */
function naPage(string $html, array $only): array
{
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

function naAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'NA', 'email' => 'na-'.$role.'-'.uniqid().'@x.test', 'password' => 'secret-secret', 'role' => $role]);
}

function naDays(int $d): string
{
    return now()->subDays($d)->format('Y-m-d H:i:s');
}

it('holds the newest N, newest first, and respects the limit', function () {
    // MUTATION: drop ->limit() in targetRows(). RED: all five are in.
    foreach ([5, 4, 3, 2, 1] as $d) {
        naP('p'.$d, naDays($d));
    }
    $cat = naCat(['limit' => 3]);

    expect(naMembers($cat))->toBe(['p1', 'p2', 'p3']);

    // Limit raised from the screen's own endpoint path: the list follows.
    NewArrivals::saveRule($cat, ['limit' => 4] + NewArrivals::DEFAULT_RULE);
    expect(naMembers($cat))->toBe(['p1', 'p2', 'p3', 'p4']);
});

it('keeps an excluded product out, and the shop never shows it', function () {
    // MUTATION: drop the whereNotIn(exclude) in targetRows(). RED.
    $a = naP('ex-a', naDays(1));
    naP('ex-b', naDays(2));
    $cat = naCat();
    NewArrivals::savePicks($cat->id, 'exclude', [$a->id]);
    NewArrivals::sync($cat->id);

    expect(naMembers($cat))->toBe(['ex-b']);
    $html = $this->get('/collections/new-arrivals/')->assertOk()->getContent();
    expect(naPage($html, ['ex-a', 'ex-b']))->toBe(['ex-b']);
});

it('puts a newly added product on top at once, with no sync run, and drops the oldest past the limit', function () {
    /*
     * MUTATION: remove NewArrivals::listen() from AppServiceProvider (or the
     * flushPending() call in ShopController). RED: the page still shows the
     * old three -- the new product waits for the minute sync.
     */
    naP('n-old', naDays(30));
    naP('n-mid', naDays(20));
    naP('n-new', naDays(10));
    $cat = naCat(['limit' => 3]);
    NewArrivals::resetState();

    naP('n-fresh', now()->format('Y-m-d H:i:s'));   // the owner adds a product

    $html = $this->get('/collections/new-arrivals/')->assertOk()->getContent();
    expect(naPage($html, ['n-old', 'n-mid', 'n-new', 'n-fresh']))->toBe(['n-fresh', 'n-new', 'n-mid'])
        ->and(naMembers($cat))->toBe(['n-fresh', 'n-new', 'n-mid']);
});

it('takes a product out when it is unpublished, hidden or deleted', function () {
    // MUTATION: drop 'status' from listen()'s watched columns. RED for the draft.
    $a = naP('u-a', naDays(1));
    $b = naP('u-b', naDays(2));
    $c = naP('u-c', naDays(3));
    $cat = naCat();

    $a->update(['status' => 'draft']);
    $b->update(['is_visible' => false]);
    NewArrivals::flushPending();
    expect(naMembers($cat))->toBe(['u-c']);

    $c->delete();
    NewArrivals::flushPending();
    expect(naMembers($cat))->toBe([]);
});

it('does not re-sync per product on a bulk write: one sync for the whole batch', function () {
    // MUTATION: make defer() call syncAll() directly. RED: $runs is 40.
    $cat = naCat();
    NewArrivals::resetState();

    $queries = 0;
    DB::listen(function () use (&$queries) { $queries++; });
    for ($i = 1; $i <= 40; $i++) {
        Product::create(['slug' => 'bulk-'.$i, 'name' => 'Bulk '.$i, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 100, 'stock_status' => 'instock']);
    }
    expect(NewArrivals::$runs)->toBe(0)->and(NewArrivals::pending())->toBeTrue();

    NewArrivals::flushPending();
    NewArrivals::flushPending();   // spent: a second call does nothing
    expect(NewArrivals::$runs)->toBe(1)->and(count(naMembers($cat)))->toBe(40);
});

it('a sync with nothing to do writes nothing', function () {
    naP('i-a', naDays(1));
    $cat = naCat();

    $writes = 0;
    DB::listen(function ($q) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $q->sql)) {
            $writes++;
        }
    });
    NewArrivals::sync($cat->id);
    NewArrivals::sync($cat->id);
    expect($writes)->toBe(0);
});

it('keeps only products added inside the window, and an included older one rejoins', function () {
    // MUTATION: drop the created_at >= cutoff clause. RED: the 4-month-old one is in.
    naP('w-new', naDays(10));
    $old = naP('w-old', now()->subMonths(4)->format('Y-m-d H:i:s'));
    $cat = naCat();
    expect(naMembers($cat))->toBe(['w-new']);

    // MUTATION: drop the orWhereIn(include). RED: it stays out.
    NewArrivals::savePicks($cat->id, 'include', [$old->id]);
    NewArrivals::sync($cat->id);
    expect(naMembers($cat))->toBe(['w-new', 'w-old']);

    // Exclusion beats inclusion.
    NewArrivals::savePicks($cat->id, 'exclude', [$old->id]);
    NewArrivals::sync($cat->id);
    expect(naMembers($cat))->toBe(['w-new']);
});

it('ages a product out when the window passes, from the minute sync alone', function () {
    // MUTATION: remove the kbb:new-arrivals-sync schedule's command body. RED.
    naP('age-a', naDays(80));
    naP('age-b', naDays(10));
    $cat = naCat();
    expect(naMembers($cat))->toBe(['age-b', 'age-a']);

    $this->travel(15)->days();
    $this->artisan('kbb:new-arrivals-sync')->assertSuccessful();
    expect(naMembers($cat))->toBe(['age-b']);
    $this->travelBack();
});

it('with the window off, keeps any age', function () {
    naP('off-a', now()->subYears(2)->format('Y-m-d H:i:s'));
    naP('off-b', naDays(1));
    $cat = naCat(['window_on' => false]);

    expect(naMembers($cat))->toBe(['off-b', 'off-a']);
});

it('honours the owner order on the shop, puts a new product at 1, keeps relative order on removal, and resets', function () {
    /*
     * MUTATION: in arrange(), append entrants at the end instead of merging.
     * RED: the new product lands last. Change sortListing() to ignore
     * NewArrivals::isAuto(). RED: the page opens on created_at, not his order.
     */
    $a = naP('o-a', naDays(1));
    $b = naP('o-b', naDays(2));
    $c = naP('o-c', naDays(3));
    $cat = naCat();

    // He drags the oldest to the top.
    NewArrivals::saveGrid($cat, [$c->id, $a->id, $b->id], []);
    $page = fn () => naPage($this->get('/collections/new-arrivals/')->assertOk()->getContent(), ['o-a', 'o-b', 'o-c', 'o-new']);
    expect($page())->toBe(['o-c', 'o-a', 'o-b']);

    naP('o-new', now()->format('Y-m-d H:i:s'));
    NewArrivals::flushPending();
    expect($page())->toBe(['o-new', 'o-c', 'o-a', 'o-b']);

    $a->update(['status' => 'draft']);
    NewArrivals::flushPending();
    expect(naMembers($cat))->toBe(['o-new', 'o-c', 'o-b']);

    // A shopper's own sort still wins.
    $byDate = naPage($this->get('/collections/new-arrivals/?orderby=date')->getContent(), ['o-b', 'o-c', 'o-new']);
    expect($byDate)->toBe(['o-new', 'o-b', 'o-c']);

    NewArrivals::reset($cat);
    expect($page())->toBe(['o-new', 'o-b', 'o-c']);
});

it('leaves every other category exactly on its own default order', function () {
    // MUTATION: drop the isAuto() test in sortListing(). RED: the hand-ordered category flips.
    naCat();
    $other = Category::create(['name' => 'Other Shelf', 'slug' => 'na-other-shelf']);
    $x = naP('t-x', naDays(1));
    $y = naP('t-y', naDays(9));
    $other->products()->attach([$x->id => [ScopeOrder::CATEGORY_COLUMN => 2], $y->id => [ScopeOrder::CATEGORY_COLUMN => 1]]);
    NewArrivals::flushPending();

    $html = $this->get('/collections/na-other-shelf/')->assertOk()->getContent();
    expect(naPage($html, ['t-x', 't-y']))->toBe(['t-y', 't-x']);
    expect(DB::table('category_product')->where('category_id', $other->id)->count())->toBe(2);
});

it('sends /new-in/ to the collection in English and Arabic, and not when switched off', function () {
    // MUTATION: drop the NewInLink branch in CollectionController. RED: 200, the old listing.
    $cat = naCat();
    expect($this->get('/new-in/')->assertStatus(301)->headers->get('Location'))->toEndWith('/collections/new-arrivals/')
        ->and($this->get('/new-in/?utm_source=mail')->assertStatus(301)->headers->get('Location'))->toEndWith('/collections/new-arrivals/?utm_source=mail');

    \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    $to = $this->get('/ar/new-in/')->assertStatus(301)->headers->get('Location');
    expect($to)->toEndWith('/ar/collections/new-arrivals/');

    NewArrivals::saveRule($cat, ['on' => false] + NewArrivals::DEFAULT_RULE);
    $this->get('/new-in/')->assertOk();
});

it('does not loop when a redirect row sends the collection back to /new-in/', function () {
    naCat();
    \App\Models\Redirect::query()->create(['source' => '/collections/new-arrivals/', 'target' => '/new-in/', 'code' => 301, 'enabled' => true]);
    \Illuminate\Support\Facades\Cache::flush();

    $this->get('/new-in/')->assertOk();
});

it('switching off puts the hand-made rows back; other categories untouched', function () {
    $cat = Category::create(['name' => 'New Arrivals', 'slug' => 'new-arrivals']);
    $hand = naP('hand-old', now()->subYears(3)->format('Y-m-d H:i:s'));
    $cat->products()->attach($hand->id, [ScopeOrder::CATEGORY_COLUMN => 7]);
    naP('fresh-1', naDays(1));

    NewArrivals::saveRule($cat, NewArrivals::DEFAULT_RULE);
    expect(naMembers($cat))->toBe(['fresh-1']);

    NewArrivals::saveRule($cat->fresh(), ['on' => false] + NewArrivals::DEFAULT_RULE);
    expect(naMembers($cat))->toBe(['hand-old'])
        ->and((int) DB::table('category_product')->where('category_id', $cat->id)->value(ScopeOrder::CATEGORY_COLUMN))->toBe(7);
});

it('the migration fills the category once, and a second run changes nothing', function () {
    $cat = Category::create(['name' => 'New Arrivals', 'slug' => 'new-arrivals']);
    naP('m-a', naDays(1));
    $m = require base_path('database/migrations/2027_12_23_100000_new_arrivals_automatic.php');
    $m->up();
    expect(NewArrivals::isAuto($cat->fresh()))->toBeTrue()->and(naMembers($cat))->toBe(['m-a']);

    NewArrivals::saveRule($cat->fresh(), ['limit' => 5] + NewArrivals::DEFAULT_RULE);
    $m->up();
    expect(NewArrivals::rule($cat->fresh())['limit'])->toBe(5);
});

// ── Catalog -> New Arrivals ──────────────────────────────────────────────────

it('Display shows the shop order with excluded-but-qualifying products flagged in place; Save re-includes them', function () {
    /*
     * MUTATION: drop the excluded merge in grid(). RED: the excluded product
     * is missing from the grid and cannot be brought back with one tap.
     */
    NewArrivalsAdminRoutes::wire(app());
    $a = naP('g-a', naDays(1));
    $b = naP('g-b', naDays(2));
    $c = naP('g-c', naDays(3));
    $cat = naCat();
    NewArrivals::savePicks($cat->id, 'exclude', [$b->id]);
    NewArrivals::sync($cat->id);
    $owner = naAdmin();

    $items = $this->actingAs($owner, 'admin')->getJson('/admin-api/new-arrivals/grid')->assertOk()->json('items');
    expect(array_column($items, 'id'))->toBe([$a->id, $b->id, $c->id])
        ->and(array_column($items, 'excluded'))->toBe([false, true, false]);

    // One Save: b back in, a excluded, order c, b.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/save', [
        'order' => [$c->id, $b->id, $a->id], 'excluded' => [$a->id],
    ])->assertOk();

    expect(NewArrivals::picks($cat->id, 'exclude'))->toBe([$a->id])
        ->and(naMembers($cat))->toBe(['g-c', 'g-b']);
    expect(naPage($this->get('/collections/new-arrivals/')->getContent(), ['g-a', 'g-b', 'g-c']))->toBe(['g-c', 'g-b']);
});

it('saves the settings with validation, and resets to newest first', function () {
    NewArrivalsAdminRoutes::wire(app());
    $a = naP('s-a', naDays(1));
    $old = naP('s-old', now()->subMonths(6)->format('Y-m-d H:i:s'));
    $cat = naCat();
    $owner = naAdmin();
    $ok = ['on' => true, 'limit' => 300, 'window_on' => true, 'window_n' => 3, 'window_unit' => 'month', 'include' => [$old->id], 'exclude' => []];

    $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/settings', ['limit' => 0] + $ok)->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/settings', ['window_unit' => 'year'] + $ok)->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/settings', ['window_n' => 37] + $ok)->assertStatus(422);

    $json = $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/settings', $ok)->assertOk()->json();
    expect($json['count'])->toBe(2)->and(array_column($json['include'], 'id'))->toBe([$old->id]);

    NewArrivals::saveGrid($cat->fresh(), [$old->id, $a->id], []);
    expect(naMembers($cat))->toBe(['s-old', 's-a']);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/new-arrivals/reset')->assertOk();
    expect(naMembers($cat))->toBe(['s-a', 's-old']);
});

it('every New Arrivals endpoint has its own capability and fails closed', function () {
    // MUTATION: delete the two admin-api/new-arrivals rules in AdminCapabilities. RED for the manager.
    NewArrivalsAdminRoutes::wire(app());
    naCat();
    $calls = [
        ['GET', '/admin-api/new-arrivals'], ['GET', '/admin-api/new-arrivals/grid'], ['GET', '/admin-api/new-arrivals/search?q=ab'],
        ['POST', '/admin-api/new-arrivals/settings'], ['POST', '/admin-api/new-arrivals/save'], ['POST', '/admin-api/new-arrivals/reset'],
    ];

    foreach ($calls as [$method, $uri]) {
        expect(\App\Support\AdminCapabilities::forPath($method, ltrim(strtok($uri, '?'), '/')))->toBe('catalog.new_arrivals');
    }

    expect(\App\Support\AdminCapabilities::roleCan('owner', 'catalog.new_arrivals'))->toBeTrue()
        ->and(\App\Support\AdminCapabilities::roleCan('manager', 'catalog.new_arrivals'))->toBeTrue()
        ->and(\App\Support\AdminCapabilities::roleCan('made-up-role', 'catalog.new_arrivals'))->toBeFalse();

    foreach (['support', 'editor'] as $role) {
        foreach ($calls as [$method, $uri]) {
            $this->actingAs(naAdmin($role), 'admin')->json($method, $uri)->assertForbidden();
        }
    }

    $this->actingAs(naAdmin('manager'), 'admin')->getJson('/admin-api/new-arrivals')->assertOk();
});

it('costs the category page the same queries with 3 members as with 40', function () {
    // MUTATION: look a rule up per product card. RED: the counts differ.
    $cat = naCat(['window_on' => false]);
    $count = function () {
        NewArrivals::resetState();
        SettingsService::forgetMemo();
        $this->get('/collections/new-arrivals/')->assertOk();   // warm caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/collections/new-arrivals/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    for ($i = 1; $i <= 3; $i++) {
        naP('q-'.$i, naDays($i));
    }
    NewArrivals::flushPending();
    $three = $count();

    for ($i = 4; $i <= 40; $i++) {
        naP('q-'.$i, naDays($i));
    }
    NewArrivals::flushPending();
    expect(count(naMembers($cat)))->toBe(40);
    expect($count())->toBe($three);
});
