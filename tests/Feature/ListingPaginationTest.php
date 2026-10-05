<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\AdminCapabilities;
use App\Support\ListingPagination;
use Illuminate\Support\Facades\DB;
use Tests\Support\ListingPaginationRoutes;

/**
 * Catalog → Pagination. (Lane PG)
 *
 * The owner: "also i allow option to turn off the pagination function
 * completely for any page, any category or brand, in case of turned off, all
 * the products will show at once. keep this option under Catelog > Pagination."
 *
 * Each case names the defect it would have caught and the mutation that turns
 * it red.
 */

/* ---------------------------------------------------------------- helpers */

function pgAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PG '.$role,
        'email' => "pg-{$role}@example.test",
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function pgCategory(string $slug = 'pg-serums'): Category
{
    $c = Category::query()->create(['name' => 'PG Serums', 'slug' => $slug, 'parent_id' => null]);
    $c->forceFill(['path' => $slug, 'depth' => 0])->save();

    return $c->fresh();
}

function pgBrand(string $slug = 'pg-glow'): Brand
{
    return Brand::create(['name' => 'PG Glow', 'slug' => $slug]);
}

/** $n visible, in-stock products, in the category and brand when given. */
function pgProducts(int $n, ?Category $category = null, ?Brand $brand = null, string $prefix = 'pg-prod'): void
{
    static $seq = 0;

    for ($i = 1; $i <= $n; $i++) {
        $seq++;
        $p = Product::create([
            'slug' => "{$prefix}-{$seq}", 'name' => "PG Product {$seq}", 'brand_id' => $brand?->id,
            'price' => 1000 + $seq, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
            'created_at' => now()->subMinutes($seq),
        ]);

        if ($category) {
            $p->categories()->syncWithoutDetaching([$category->id]);
        }
    }
}

/** Save through the same service the screen does, and drop every memo. */
function pgSet(bool $on, array $overrides = []): void
{
    expect(ListingPagination::save($on, $overrides))->toBe([]);
    pgForget();
}

function pgForget(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

/** How many distinct products the page links to, and how many pager links it draws. */
function pgCount(string $html, string $prefix = 'pg-prod'): array
{
    preg_match_all('#/product/'.preg_quote($prefix, '#').'-(\d+)/#', $html, $m);
    preg_match_all('#class="page-numbers[^"]*"#', $html, $links);

    return [count(array_unique($m[1])), count($links[0])];
}

function pgQueries(string $path): int
{
    pgForget();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($path)->assertOk();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

beforeEach(function () {
    ListingPaginationRoutes::wire($this->app);
});

/* ------------------------------------------------------- shipped default */

it('ships with pagination on and no overrides, so every listing asks for the page size it always did', function () {
    /*
     * CLAUDE.md rule 1: the owner did not ask to turn pagination off anywhere,
     * so applying the package must move nothing. MUTATION: default
     * globalOn() to false, or make perPage() return CAP for 'follow', and the
     * category below draws all 30 with no pager -- red.
     */
    $cat = pgCategory();
    pgProducts(30, $cat);

    expect(ListingPagination::globalOn())->toBeTrue()
        ->and(ListingPagination::overrides())->toBe(['category' => [], 'brand' => [], 'page' => []])
        ->and(ListingPagination::perPage('category', $cat->id, 24))->toBe(app(SiteLayout::class)->perPage(24))
        ->and(ListingPagination::perPage('page', 'shop', 24))->toBe(app(SiteLayout::class)->perPage(24));

    [$products, $links] = pgCount($this->get('/collections/pg-serums/')->assertOk()->getContent());

    // The shipped "Load more on scroll" batch of 12, and its pager underneath.
    expect($products)->toBe(app(SiteLayout::class)->batchSize())
        ->and($links)->toBeGreaterThan(0);

    $this->get('/collections/pg-serums/?paged=2')->assertOk();
});

/* ---------------------------------------------- off: everything on page 1 */

it('shows every product of a category on one page, with no page links, when that category is off', function () {
    /*
     * THE ASK: "in case of turned off, all the products will show at once".
     * MUTATION: drop the ListingPagination::perPage() call from ShopController
     * (back to SiteLayout::perPage) and only 12 come back, with a pager.
     */
    $cat = pgCategory();
    pgProducts(30, $cat);
    pgSet(true, ['category' => [(string) $cat->id => 'off']]);

    $html = $this->get('/collections/pg-serums/')->assertOk()->getContent();
    [$products, $links] = pgCount($html);

    expect($products)->toBe(30)
        ->and($links)->toBe(0)
        ->and($html)->not->toContain('class="kbb-pager"')
        ->and($html)->not->toContain('rel="next"')
        ->and($html)->not->toContain('?paged=2');
});

it('shows every product of a brand on one page when that brand is off, even with the brand page set to page', function () {
    /*
     * The brand page's own switch, Appearance → Site layout → Brand page →
     * "Show every product of the brand on one page", is turned OFF here, so
     * only the override can be what puts all 30 on the page. MUTATION: make
     * BrandController read $layout->perPage() whatever showsAll() says -- 12.
     */
    $brand = pgBrand();
    pgProducts(30, null, $brand);
    app(SiteLayout::class)->save(['brand_all' => false]);
    pgSet(true, ['brand' => [(string) $brand->id => 'off']]);

    $html = $this->get('/brands/pg-glow/')->assertOk()->getContent();
    [$products, $links] = pgCount($html);

    expect($products)->toBe(30)->and($links)->toBe(0)->and($html)->not->toContain('?paged=2');
});

it('shows every product of a listing page on one page when that page is off', function () {
    /*
     * /new-in/ draws 24 a page as shipped (one batch of 12 on scroll).
     * MUTATION: have CollectionController::perPage() ignore its key -- 12.
     */
    pgProducts(30);
    pgSet(true, ['page' => ['new-in' => 'off']]);

    [$products, $links] = pgCount($this->get('/new-in/')->assertOk()->getContent());

    expect($products)->toBe(30)->and($links)->toBe(0);

    // Another listing page that was not switched keeps its pages.
    [$sale, $saleLinks] = pgCount($this->get('/best-sellers/')->assertOk()->getContent());
    expect($sale)->toBeLessThan(30)->and($saleLinks)->toBeGreaterThan(0);
});

it('turns pagination off on every listing at once with the global switch', function () {
    /*
     * MUTATION: make globalOn() ignore the stored '0' and /shop/, the
     * category, the brand (with its own switch off) and /new-in/ page again.
     */
    $cat = pgCategory();
    $brand = pgBrand();
    pgProducts(30, $cat, $brand);
    app(SiteLayout::class)->save(['brand_all' => false]);
    pgSet(false);

    foreach (['/shop/', '/collections/pg-serums/', '/brands/pg-glow/', '/new-in/'] as $url) {
        [$products, $links] = pgCount($this->get($url)->assertOk()->getContent());
        expect($products)->toBe(30, $url)->and($links)->toBe(0, $url);
    }
});

/* -------------------------------------------------------------- precedence */

it('lets a listing override the global switch in both directions', function () {
    /*
     * Global off, this category ON: it pages. Global on, the other OFF: all.
     * MUTATION: check globalOn() before the override in showsAll() and the
     * first assertion pair is red.
     */
    $a = pgCategory('pg-a');
    $b = pgCategory('pg-b');
    pgProducts(30, $a, null, 'pg-a');
    pgProducts(30, $b, null, 'pg-b');

    pgSet(false, ['category' => [(string) $a->id => 'on']]);
    [$aCount, $aLinks] = pgCount($this->get('/collections/pg-a/')->getContent(), 'pg-a');
    [$bCount] = pgCount($this->get('/collections/pg-b/')->getContent(), 'pg-b');
    expect($aCount)->toBe(12)->and($aLinks)->toBeGreaterThan(0)->and($bCount)->toBe(30);

    pgSet(true, ['category' => [(string) $b->id => 'off']]);
    [$aCount] = pgCount($this->get('/collections/pg-a/')->getContent(), 'pg-a');
    [$bCount] = pgCount($this->get('/collections/pg-b/')->getContent(), 'pg-b');
    expect($aCount)->toBe(12)->and($bCount)->toBe(30);
});

it('keeps a following brand on the brand page switch while pagination is on, and lets a brand turn pagination on', function () {
    /*
     * The shipped brand page shows everything (brand_all, on as shipped) and
     * must go on doing so -- "follow" with pagination on is that switch. A
     * brand set ON pages even so. MUTATION: drop the brand_all clause from
     * showsAll() and the brand page starts paging on apply -- red.
     */
    $brand = pgBrand();
    pgProducts(30, null, $brand);

    [$all, $links] = pgCount($this->get('/brands/pg-glow/')->getContent());
    expect($all)->toBe(30)->and($links)->toBe(0);

    pgSet(true, ['brand' => [(string) $brand->id => 'on']]);
    [$paged, $links] = pgCount($this->get('/brands/pg-glow/')->getContent());
    expect($paged)->toBe(12)->and($links)->toBeGreaterThan(0);
});

/* --------------------------------------------------------- old page URLs */

it('sends ?paged=N on a listing that is off to page one for good, filters kept', function () {
    /*
     * A page-2 address that was indexed or bookmarked while the listing paged
     * must not 404 once page one holds everything, and must not answer 200 as
     * a duplicate of page one. 301 to the listing, sort kept -- the brand
     * page's rule. MUTATION: delete the redirect in ShopController and this
     * is the 404 its "past the end" rule gives.
     */
    $cat = pgCategory();
    pgProducts(30, $cat);
    pgSet(true, ['category' => [(string) $cat->id => 'off']]);

    // The Location exactly as the listing prints its own address, trailing
    // slash included: assertRedirect() normalises the slash away on both
    // sides, which is how a Location of /collections/toners (one more hop to
    // the slashed URL) went unnoticed until the preview measured it.
    // MUTATION: put back redirect()->to(Facets::pageUrl(1), 301) in
    // ShopController -- the Location loses its slash, red.
    $loc = fn ($r) => (string) parse_url((string) $r->headers->get('Location'), PHP_URL_PATH)
        .(parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY) ? '?'.parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY) : '');

    $r = $this->get('/collections/pg-serums/?paged=2')->assertStatus(301);
    expect($loc($r))->toBe('/collections/pg-serums/');
    $r = $this->get('/collections/pg-serums/?orderby=price&paged=3')->assertStatus(301);
    expect($loc($r))->toBe('/collections/pg-serums/?orderby=price');

    // A listing page uses ?page=; same rule. MUTATION: delete allOnPageOne().
    pgSet(true, ['page' => ['new-in' => 'off']]);
    $r = $this->get('/new-in/?page=2')->assertStatus(301);
    expect($loc($r))->toBe('/new-in/');

    // And the brand page's own redirect now follows the override too.
    $brand = pgBrand();
    pgProducts(3, null, $brand, 'pg-br');
    $this->get('/brands/pg-glow/?paged=2')->assertStatus(301)->assertRedirect('/brands/pg-glow/');
});

it('keeps real later pages past the cap, so no listing becomes one enormous page', function () {
    /*
     * CAP + 1 products with /shop/ off: page one holds CAP, page two holds the
     * one left over and is a real page, not a redirect. MUTATION: drop the
     * `$total <= CAP` guard and page two 301s away -- the last product is
     * unreachable.
     */
    $now = now();
    $rows = [];
    for ($i = 1; $i <= ListingPagination::CAP + 1; $i++) {
        $rows[] = ['slug' => "pg-cap-{$i}", 'name' => "PG Cap {$i}", 'price' => 1000, 'status' => 'publish',
            'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    pgSet(true, ['page' => ['shop' => 'off']]);

    // The migrations seed a handful of products of their own, so the shop
    // holds CAP + 1 + those; page one is exactly CAP cards whichever they are.
    $total = Product::query()->visible()->count();
    $first = $this->get('/shop/')->assertOk()->getContent();
    preg_match_all('#<a[^>]+href="[^"]*/product/([a-z0-9-]+)/#', $first, $m);
    [, $links] = pgCount($first, 'pg-cap');
    expect(count(array_unique($m[1])))->toBe(ListingPagination::CAP)->and($links)->toBeGreaterThan(0);

    $second = $this->get('/shop/?paged=2')->assertOk()->getContent();
    preg_match_all('#<a[^>]+href="[^"]*/product/([a-z0-9-]+)/#', $second, $m2);
    expect(count(array_unique($m2[1])))->toBe($total - ListingPagination::CAP)
        ->and(array_intersect(array_unique($m[1]), array_unique($m2[1])))->toBe([]);
});

/* ------------------------------------------------------------------- SEO */

it('keeps the canonical on the listing itself when everything is on one page', function () {
    /*
     * MUTATION: build the canonical from a page number and it names ?paged=1
     * or carries a rel="next" to a page that now redirects.
     */
    $cat = pgCategory();
    pgProducts(30, $cat);
    pgSet(true, ['category' => [(string) $cat->id => 'off']]);

    $html = $this->get('/collections/pg-serums/')->assertOk()->getContent();

    expect(preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m))->toBe(1)
        ->and($m[1])->toEndWith('/collections/pg-serums/')
        ->and($html)->not->toContain('rel="next"')
        ->and($html)->not->toContain('rel="prev"');
});

/* ---------------------------------------------------------------- budget */

it('costs the same queries for 3 products as for 40 with pagination off', function () {
    /*
     * "Every product at once" must not mean a query per product. Measured on
     * the category, the brand and a listing page, each with 3 and then 40.
     * MUTATION: drop ->with('brand:id,name,slug') from ShopController's query
     * and the category grows by one query per product -- red.
     */
    $cat = pgCategory();
    $brand = pgBrand();
    pgProducts(3, $cat, $brand);
    pgSet(false);

    $paths = ['/collections/pg-serums/', '/brands/pg-glow/', '/new-in/', '/shop/'];
    foreach ($paths as $p) {
        $this->get($p)->assertOk();   // warm
    }
    $small = array_map('pgQueries', $paths);

    pgProducts(37, $cat, $brand);
    foreach ($paths as $p) {
        [$n] = pgCount($this->get($p)->getContent());
        expect($n)->toBe(40, $p);
    }
    $large = array_map('pgQueries', $paths);

    expect($large)->toBe($small);
});

it('adds no query to a listing for reading the setting', function () {
    /*
     * Two autoloaded settings rows, read from the cached map. MUTATION: read
     * the overrides with Setting::query() in overrides() and the count with
     * an override saved is one higher than with none.
     */
    $cat = pgCategory();
    pgProducts(3, $cat);
    $this->get('/collections/pg-serums/')->assertOk();
    $before = pgQueries('/collections/pg-serums/');

    pgSet(true, ['category' => [(string) $cat->id => 'on'], 'page' => ['shop' => 'on']]);
    $this->get('/collections/pg-serums/')->assertOk();

    expect(pgQueries('/collections/pg-serums/'))->toBe($before);
});

/* ----------------------------------------------------------- admin screen */

it('hands the screen to owner, manager and editor and refuses support, both ways', function () {
    /*
     * Fails closed: a path missing from AdminCapabilities::RULES is owner-only.
     * MUTATION: drop 'editor' from pagination.manage -- the editor's 200 is a
     * 403. Drop the RULES line -- the manager's 200 is a 403.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/pagination'))->toBe('pagination.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/pagination'))->toBe('pagination.manage')
        ->and(AdminCapabilities::CAPABILITIES['pagination.manage'])->toBe(['owner', 'manager', 'editor']);

    foreach (['owner', 'manager', 'editor'] as $role) {
        $this->actingAs(pgAdmin($role), 'admin')->getJson('/admin-api/pagination')->assertOk();
    }

    $this->actingAs(pgAdmin('support'), 'admin');
    $this->getJson('/admin-api/pagination')->assertForbidden();
    $this->postJson('/admin-api/pagination', ['on' => false, 'overrides' => []])->assertForbidden();

    expect(ListingPagination::globalOn())->toBeTrue();
});

it('refuses a guest outright', function () {
    $this->getJson('/admin-api/pagination')->assertUnauthorized();
    $this->postJson('/admin-api/pagination', ['on' => false, 'overrides' => []])->assertUnauthorized();
});

it('saves the switch and the overrides and reads them back', function () {
    $cat = pgCategory();
    $brand = pgBrand();
    $this->actingAs(pgAdmin(), 'admin');

    $res = $this->postJson('/admin-api/pagination', [
        'on' => false,
        'overrides' => [
            'category' => [(string) $cat->id => 'on'],
            'brand' => [(string) $brand->id => 'off'],
            'page' => ['super-sale' => 'off', 'shop' => 'follow'],
        ],
    ])->assertOk()->json();

    expect($res['ok'])->toBeTrue()
        ->and($res['on'])->toBeFalse()
        ->and($res['overrides']['category'])->toBe([(string) $cat->id => 'on'])
        ->and($res['overrides']['brand'])->toBe([(string) $brand->id => 'off'])
        // 'follow' is the absence of an override, never a stored value.
        ->and($res['overrides']['page'])->toBe(['super-sale' => 'off'])
        ->and($res['cap'])->toBe(ListingPagination::CAP)
        ->and(collect($res['pages'])->pluck('key')->all())->toContain('shop', 'new-in', 'super-sale', 'under-54', 'concern-acne');
});

it('refuses a value that is not one of its options, an id that does not exist and an unknown page, writing nothing', function () {
    /*
     * A select stores one of its own options; ids must exist. MUTATION: drop
     * the whereIn() existence check in save() and 999999 is stored.
     */
    $cat = pgCategory();
    $this->actingAs(pgAdmin(), 'admin');

    foreach ([
        ['category' => [(string) $cat->id => 'maybe']],
        ['category' => ['999999' => 'off']],
        ['brand' => ['999999' => 'off']],
        ['page' => ['not-a-listing' => 'off']],
        ['category' => ['1 or 1=1' => 'off']],
        ['product' => ['1' => 'off']],
    ] as $bad) {
        $this->postJson('/admin-api/pagination', ['on' => false, 'overrides' => $bad])->assertStatus(422);
    }

    $this->postJson('/admin-api/pagination', ['on' => 'sideways', 'overrides' => []])->assertStatus(422);

    pgForget();
    expect(ListingPagination::globalOn())->toBeTrue()
        ->and(DB::table('settings')->whereIn('key', [ListingPagination::KEY_ON, ListingPagination::KEY_OVERRIDES])->count())->toBe(0);
});

it('drops a stored override written behind the screen\'s back that is not one of its options', function () {
    /*
     * A raw UPDATE or an import must not reach a controller. MUTATION: return
     * the decoded setting from overrides() without cleaning it.
     */
    app(SettingsService::class)->set(ListingPagination::KEY_OVERRIDES, [
        'category' => ['5' => 'off', '6' => 'sideways', 'x' => 'off'],
        'evil' => ['1' => 'off'],
        'page' => ['shop' => 'on'],
    ]);
    pgForget();

    expect(ListingPagination::overrides())->toBe(['category' => ['5' => 'off'], 'brand' => [], 'page' => ['shop' => 'on']]);
});

it('reads the screen in the same number of queries for 3 categories and brands as for 40', function () {
    /*
     * The picker searches a list the page already has, so the read must stay
     * flat as the catalogue grows. MUTATION: count each category's products
     * in payload() and 40 costs 37 more than 3.
     */
    $this->actingAs(pgAdmin(), 'admin');

    $make = function (int $from, int $to) {
        for ($i = $from; $i <= $to; $i++) {
            pgCategory("pg-c{$i}");
            pgBrand("pg-b{$i}");
        }
    };

    $make(1, 3);
    $this->getJson('/admin-api/pagination')->assertOk();
    pgForget();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = $this->getJson('/admin-api/pagination')->assertOk()->json();
    $q3 = count(DB::getQueryLog());

    $make(4, 40);
    pgForget();
    DB::flushQueryLog();
    $large = $this->getJson('/admin-api/pagination')->assertOk()->json();
    $q40 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The migrations seed a few categories and brands of their own: 37 more.
    expect(count($large['categories']) - count($small['categories']))->toBe(37)
        ->and(count($large['brands']) - count($small['brands']))->toBe(37)
        ->and($q40)->toBe($q3);
});
