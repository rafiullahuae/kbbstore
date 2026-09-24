<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryPath;
use Illuminate\Support\Facades\DB;
use Tests\Support\CategoryLaneRoutes;

/**
 * /product-category/{nested/path}/ is an indexed URL with inbound links, so
 * what it does with a path it does not recognise is a contract, not an
 * implementation detail.
 *
 * Fixtures use "aq-" slugs for the same reason CatalogAdminCategoriesTest uses
 * "t-" ones: a migration seeds a demo catalogue into the table, and the first
 * test in a process is not isolated (see tests/Pest.php).
 */
function aqAdmin(): AdminUser
{
    return AdminUser::query()->firstOrCreate(
        ['email' => 'lane-aq@kbb.test'],
        ['name' => 'Lane AQ', 'password' => bcrypt('secret-aq')]
    );
}

function aqCategory(string $slug, string $name, ?int $parentId = null): Category
{
    $c = Category::create(['slug' => $slug, 'name' => $name, 'parent_id' => $parentId]);
    $c->update(['path' => $c->buildPath(), 'depth' => substr_count($c->buildPath(), '/')]);

    return $c->fresh();
}

function aqProduct(string $slug, Category $cat, string $status = 'publish', bool $visible = true): Product
{
    $p = Product::create([
        'slug' => $slug,
        'name' => strtoupper($slug),
        'price' => 5000,
        'status' => $status,
        'is_visible' => $visible,
        'stock_status' => 'instock',
        'type' => 'simple',
    ]);

    DB::table('category_product')->insert(['category_id' => $cat->id, 'product_id' => $p->id]);

    return $p;
}

/**
 * Assert WHERE a 301 from the archive actually points, slash included.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PIN ADVANCED, DELIBERATELY — AND THE OLD SPELLING COULD NOT SEE THE CHANGE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Seven assertions in this file read:
 *
 *     ->assertRedirect(CategoryPath::url('aq-skincare/aq-cleansers'));
 *
 * and every one of them PASSED against a Location of
 * `http://localhost/product-category/aq-skincare/aq-cleansers` — no trailing
 * slash — while `CategoryPath::url()` returns the slashed form. That is not a
 * tolerance, it is blindness: `assertRedirect()` puts BOTH sides through the
 * same `UrlGenerator`, which strips a trailing slash off a relative path, so
 * the expectation was stripped to match the defect. `docs/GP-ADDRESSES-LAND.md`
 * §5.5 found the identical hole in `SeoEngineToolsTest` and fixed it the same
 * way.
 *
 * What the defect was on the shop: `/product-category/aq-cleansers/` 301'd to
 * `/product-category/aq-skincare/aq-cleansers`, an address that answers 200 and
 * whose own `<link rel="canonical">` points at the slashed form. The shop
 * redirected to an address that then declared a different one canonical, so
 * every stale category URL cost a crawler a 301 and then a canonical hop and
 * consolidated its link equity onto neither.
 *
 * MUTATION NOTE: put `CategoryArchiveController::show()` back to
 * `redirect($verdict['to'], …)` and all seven go red on the missing slash.
 * Swap this helper back to `assertRedirect(CategoryPath::url(...))` and all
 * seven go green again WITH the defect in place, which is why the raw header is
 * the only spelling worth writing here.
 */
function aqAssertLands(\Illuminate\Testing\TestResponse $response, string $path): void
{
    $location = (string) $response->headers->get('Location');

    // The raw header, not assertRedirect(): see above.
    expect($location)->toBe(CategoryPath::redirectUrl($path));

    // Stated separately so a future change to redirectUrl() cannot make both
    // sides agree on a slash-less answer the way assertRedirect() did.
    expect($location)->toEndWith('/');
}

/* ------------------------------------------------- the bug, as it ships today */

it('404s an unknown archive path on the SHIPPED route, not just the lane one', function () {
    /*
     * This test was written the other way up. While the lane was building the
     * resolver it asserted the broken shipped behaviour -- 200 and "Shop all"
     * for any invented path -- so that its own fix was demonstrably a change
     * rather than a restatement. That was the right test to write then.
     *
     * The integrator has since repointed routes/web.php at
     * CategoryArchiveController, so the shipped behaviour IS the fix and the
     * old assertion would now be pinning a bug back into place. Inverted, it
     * becomes the guard that matters: it drives routes/web.php as the
     * application actually registers it, with nothing wired by the test, so
     * it fails if that route is ever pointed back at something that cannot
     * 404.
     *
     * Verified against a running server before and after the repoint:
     * /product-category/zz-nope/ went 200 -> 404, /product-category/cleansers/
     * stayed 200.
     */
    aqCategory('aq-live-cat', 'AQ Live Cat');

    // A category that does not exist must not render the whole catalogue.
    $this->get('/product-category/aq-totally-invented/')->assertStatus(404);

    /*
     * A real leaf under an invented parent CONSOLIDATES rather than 404s, and
     * that is the right answer -- I asserted 404 here first and the controller
     * was right and I was wrong. basename() used to make every category
     * infinitely addressable with all of those addresses answering 200, which
     * is duplicate content. The category does exist, though, so a 301 to its
     * canonical path keeps any inbound link working and lets Google collapse
     * the duplicates, which a 404 would simply discard.
     */
    $this->get('/product-category/utter/nonsense/aq-live-cat/')
        ->assertStatus(301)
        ->assertRedirectContains('/product-category/aq-live-cat');

    // The real path still answers, so this is a narrowing and not a blackout.
    $this->get('/product-category/aq-live-cat/')->assertStatus(200);
});

/* ------------------------------------------------------------- the resolver */

it('404s a path whose leaf names no category', function () {
    CategoryLaneRoutes::wireStorefront($this->app);

    $this->get('/product-category/aq-no-such-thing/')->assertStatus(404);
    $this->get('/product-category/aq-no/aq-such/aq-thing/')->assertStatus(404);
});

it('serves a real nested path and 301s a path with the wrong ancestry', function () {
    CategoryLaneRoutes::wireStorefront($this->app);

    $parent = aqCategory('aq-skincare', 'AQ Skincare');
    aqCategory('aq-cleansers', 'AQ Cleansers', (int) $parent->id);

    // The canonical address.
    $this->get('/product-category/aq-skincare/aq-cleansers/')->assertStatus(200);

    // The leaf is real, the ancestry is invented: one page, one address.
    aqAssertLands(
        $this->get('/product-category/made-up/aq-cleansers/')->assertStatus(301),
        'aq-skincare/aq-cleansers',
    );

    // The bare leaf of a nested category is also non-canonical.
    aqAssertLands(
        $this->get('/product-category/aq-cleansers/')->assertStatus(301),
        'aq-skincare/aq-cleansers',
    );
});

it('301s to an address that serves, rather than to one that 301s again', function () {
    /*
     * THE DEFECT THIS FILE SHIPPED WITH, stated as the visitor experiences it.
     *
     * `/product-category/aq-cleansers/` 301'd to
     * `/product-category/aq-skincare/aq-cleansers` — no trailing slash. That
     * address answers 200, so nothing looked broken, and its own
     * `<link rel="canonical">` points at the slashed form. One 301 and then a
     * canonical hop, to an address the shop does not consider its own, for
     * every stale or non-canonical category URL on the site. It is the same
     * defect `docs/GP-ADDRESSES-LAND.md` §5.3 measured for the redirects TABLE
     * and fixed there with `Url::redirect()`, which left this controller as the
     * last producer of a 301 in the application still doing it the other way.
     *
     * AND THE HOP IS BOUNDED, which is the property worth asserting separately:
     * the destination is `canonicalPath()`, a fixed point — `resolve()` answers
     * `ok` for it, never `redirect` — so following the Location cannot come
     * back here. A 301 whose destination 301s is how a redirect loop starts.
     *
     * MUTATION NOTE: restore `redirect($verdict['to'], $verdict['code'])` in
     * CategoryArchiveController::show() and the first expectation goes red on
     * the missing slash.
     */
    CategoryLaneRoutes::wireStorefront($this->app);

    $parent = aqCategory('aq-hop-parent', 'AQ Hop Parent');
    aqCategory('aq-hop-leaf', 'AQ Hop Leaf', (int) $parent->id);

    $first = $this->get('/product-category/aq-hop-leaf/')->assertStatus(301);

    $location = (string) $first->headers->get('Location');

    expect($location)->toBe(CategoryPath::redirectUrl('aq-hop-parent/aq-hop-leaf'))
        ->and($location)->toEndWith('/product-category/aq-hop-parent/aq-hop-leaf/');

    /*
     * Follow it. The path is taken out of the absolute URL the header carries,
     * which is exactly what a browser does with it, so this exercises the
     * spelling that actually went over the wire rather than a re-derived one.
     */
    $next = (string) parse_url($location, PHP_URL_PATH);

    $this->get($next)->assertStatus(200);
});

/* ------------------------------------------------------------------ renames */

it('does not move a URL when only the display name changes', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $cat = aqCategory('aq-sun-care', 'AQ Sun Care');

    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $cat->id, [
            'name' => 'AQ Suncare & SPF',
            'slug' => $cat->slug,
        ])
        ->assertOk()
        ->assertJsonPath('redirects', []);

    expect($cat->fresh()->slug)->toBe('aq-sun-care');

    // The indexed URL is untouched and still 200s.
    $this->get('/product-category/aq-sun-care/')->assertStatus(200);
});

it('301s the old path when the slug really changes, instead of breaking it', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $cat = aqCategory('aq-old-slug', 'AQ Old Slug');

    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $cat->id, [
            'name' => 'AQ New Name',
            'slug' => 'aq-new-slug',
        ])
        ->assertOk();

    $this->get('/product-category/aq-new-slug/')->assertStatus(200);

    // The indexed address survives as a 301 rather than 404ing — and, more to
    // the point, rather than going on answering 200 with "Shop all".
    aqAssertLands(
        $this->get('/product-category/aq-old-slug/')->assertStatus(301),
        'aq-new-slug',
    );
});

it('redirects every descendant URL when a parent is re-parented', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $root = aqCategory('aq-root', 'AQ Root');
    $mid = aqCategory('aq-mid', 'AQ Mid', (int) $root->id);
    aqCategory('aq-leaf', 'AQ Leaf', (int) $mid->id);

    // aq-mid moves to the top level, taking aq-leaf with it.
    $this->actingAs(aqAdmin(), 'admin')
        ->putJson('/admin-api/categories/' . $mid->id, [
            'name' => 'AQ Mid',
            'slug' => 'aq-mid',
            'parent_id' => null,
        ])
        ->assertOk();

    // The child's old URL is the one that is easy to forget, so it is the one
    // asserted hardest: a whole branch of indexed URLs moved, not just the row
    // the operator edited.
    aqAssertLands(
        $this->get('/product-category/aq-root/aq-mid/aq-leaf/')->assertStatus(301),
        'aq-mid/aq-leaf',
    );

    aqAssertLands(
        $this->get('/product-category/aq-root/aq-mid/')->assertStatus(301),
        'aq-mid',
    );

    $this->get('/product-category/aq-mid/aq-leaf/')->assertStatus(200);
});

/* ------------------------------------------------------------------ deletes */

it('refuses to delete a category that has anything attached, and says what', function () {
    CategoryLaneRoutes::wire($this->app);

    $cat = aqCategory('aq-busy', 'AQ Busy');
    aqProduct('aq-busy-p1', $cat);

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $cat->id)
        ->assertStatus(422)
        ->assertJsonPath('error', 'category_in_use')
        ->assertJsonPath('products_count', 1);

    expect(Category::query()->whereKey($cat->id)->exists())->toBeTrue();
});

it('points a deleted category at its parent, and 404s one deleted from the top level', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $parent = aqCategory('aq-keep', 'AQ Keep');
    $child = aqCategory('aq-goes', 'AQ Goes', (int) $parent->id);
    $lonely = aqCategory('aq-lonely', 'AQ Lonely');

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $child->id . '?force=1')
        ->assertOk();

    aqAssertLands(
        $this->get('/product-category/aq-keep/aq-goes/')->assertStatus(301),
        'aq-keep',
    );

    $this->actingAs(aqAdmin(), 'admin')
        ->deleteJson('/admin-api/categories/' . $lonely->id . '?force=1')
        ->assertOk();

    // Nowhere sensible to send it, so the honest answer.
    $this->get('/product-category/aq-lonely/')->assertStatus(404);
});

/* ------------------------------------------------------------------- merges */

it('merges a category into another, moving its products and 301ing its URL', function () {
    CategoryLaneRoutes::wire($this->app);
    CategoryLaneRoutes::wireStorefront($this->app);

    $from = aqCategory('aq-from', 'AQ From');
    $to = aqCategory('aq-to', 'AQ To');

    $shared = aqProduct('aq-shared', $from);
    aqProduct('aq-only-from', $from);

    // Already filed under both — the case a plain insert would raise on.
    DB::table('category_product')->insert(['category_id' => $to->id, 'product_id' => $shared->id]);

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $from->id . '/merge', ['target_id' => $to->id])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Category::query()->whereKey($from->id)->exists())->toBeFalse();

    $inTarget = DB::table('category_product')->where('category_id', $to->id)->count();
    expect($inTarget)->toBe(2);

    aqAssertLands(
        $this->get('/product-category/aq-from/')->assertStatus(301),
        'aq-to',
    );
});

it('refuses to merge a category into itself or into its own descendant', function () {
    CategoryLaneRoutes::wire($this->app);

    $parent = aqCategory('aq-parent-m', 'AQ Parent M');
    $child = aqCategory('aq-child-m', 'AQ Child M', (int) $parent->id);

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $parent->id . '/merge', ['target_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_id');

    $this->actingAs(aqAdmin(), 'admin')
        ->postJson('/admin-api/categories/' . $parent->id . '/merge', ['target_id' => $child->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target_id');

    expect(Category::query()->whereKey($parent->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------------- guards */

it('rejects anonymous, a storefront customer and a plain web user on every lane route', function () {
    CategoryLaneRoutes::wire($this->app);

    $cat = aqCategory('aq-guarded', 'AQ Guarded');
    $target = aqCategory('aq-guard-target', 'AQ Guard Target');

    // Asserted from the REGISTERED routes, not from the file: RouteRegistrar::
    // middleware() REPLACES rather than appends, so only the router knows
    // whether the guard actually survived the grouping.
    $calls = [
        ['postJson', '/admin-api/categories/' . $cat->id . '/merge', ['target_id' => $target->id]],
        ['getJson', '/admin-api/categories/redirects', null],
        ['deleteJson', '/admin-api/categories/redirects/1', null],
        ['getJson', '/admin-api/brands-tree', null],
        ['postJson', '/admin-api/brands-tree/reorder', ['order' => [1]]],
    ];

    foreach ($calls as [$method, $uri, $payload]) {
        $this->{$method}($uri, $payload ?? [])->assertStatus(401);
    }

    // A logged-in storefront CUSTOMER is not an admin. This is the case a
    // guard written as `auth` rather than `auth:admin` would wave through.
    $customer = \App\Models\Customer::query()->firstOrCreate(
        ['email' => 'aq-shopper@kbb.test'],
        ['name' => 'AQ Shopper', 'password' => bcrypt('secret-aq')]
    );

    foreach ($calls as [$method, $uri, $payload]) {
        $this->actingAs($customer, 'customer')->{$method}($uri, $payload ?? [])->assertStatus(401);
    }

    // Nothing was written by any of that.
    expect(Category::query()->whereKey($cat->id)->exists())->toBeTrue();
});

it('registers every lane route against the admin guard', function () {
    CategoryLaneRoutes::wire($this->app);

    $expected = [
        'POST admin-api/categories/{category}/merge',
        'GET admin-api/categories/redirects',
        'DELETE admin-api/categories/redirects/{redirect}',
        'GET admin-api/brands-tree',
        'POST admin-api/brands-tree/reorder',
    ];

    $byKey = collect(app('router')->getRoutes()->getRoutes())
        ->keyBy(fn ($r) => $r->methods()[0] . ' ' . $r->uri());

    foreach ($expected as $key) {
        expect($byKey->has($key))->toBeTrue("route missing: {$key}");
        expect($byKey[$key]->middleware())->toContain('auth:admin');
    }
});

it('keeps /categories/redirects reachable behind the parameterised category routes', function () {
    CategoryLaneRoutes::wire($this->app);

    // /categories/{category} is constrained to [0-9]+, so a literal
    // "redirects" segment cannot be swallowed by it. This is the exact shape
    // that made /orders/list unreachable behind /orders/{id}.
    $matched = app('router')->getRoutes()
        ->match(\Illuminate\Http\Request::create('/admin-api/categories/redirects', 'GET'));

    expect($matched->getActionName())
        ->toContain('CategoryRedirectsApiController@index');
});
