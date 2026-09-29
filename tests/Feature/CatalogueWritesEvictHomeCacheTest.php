<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Catalogue writes must drop the homepage fragments they invalidate.
 *
 * Store\HomeController serves the most-hit URL on the site out of eight
 * Cache::remember() keys: the four product rails (kbb.home.rails, 600s), the
 * brand strip and its total (kbb.home.brands, kbb.home.brandcount), the category
 * tiles (kbb.home.cats), the journal row (kbb.home.posts), the six-step routine
 * and its running total (kbb.home.routine) and the catalogue size quoted in the
 * copy (kbb.home.count) — the last seven all at 900s.
 *
 * Every one of those is built from products, brands, categories or posts, and
 * NOTHING ON ANY PRODUCT WRITE PATH EVICTED THEM. Editing a price, putting an
 * item on sale, toggling `featured`, marking something out of stock, hiding a
 * product, publishing an article or adding a brand left the homepage showing the
 * old answer for up to fifteen minutes, with nothing on screen admitting it. A
 * shopper could click a "AED 30" tile on the homepage and land on a AED 40 page.
 *
 * This is the same shape as the review wall, which was found stale twice
 * (ReviewModerationEvictsHomeWallTest, ReviewImportExportTest). Those were fixed
 * by adding Cache::forget to each admin controller that writes reviews. That
 * approach is why it broke twice: it depends on every future write path
 * remembering a literal key that lives in a different file, and products are
 * written from at least six places — the product editor, the catalogue grid and
 * its three bulk actions, the reorder screen, the importer and the seeders.
 *
 * So the eviction is bound to the MODEL instead, next to the IndexNow ping that
 * already hangs off Product::saved for the same reason: it is the one place
 * every write path has to go through. HomeController::flushCache() is the
 * eviction list and was, until this test, dead code with no callers at all.
 */
function cwAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Cache Owner',
        'email' => 'cache-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function cwProduct(array $overrides = []): Product
{
    return Product::create($overrides + [
        'slug' => 'cache-' . uniqid(),
        'name' => 'Cache Product',
        'sku' => 'CACHE-' . uniqid(),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 4000,
        'stock_status' => 'instock',
    ]);
}

/** Fill every homepage key with a sentinel, so we can see which survive. */
function cwWarm(): void
{
    foreach (['kbb.home.rails', 'kbb.home.brands', 'kbb.home.cats', 'kbb.home.posts',
              'kbb.home.routine', 'kbb.home.count', 'kbb.home.brandcount'] as $key) {
        Cache::put($key, ['stale'], 900);
    }
}

function cwSurvivors(array $keys): array
{
    return array_values(array_filter($keys, fn ($k) => Cache::get($k) === ['stale']));
}

/** The /shop sidebar's two withCount(visible) tallies. */
function cwWarmSidebar(): void
{
    Cache::put('kbb.shop.cats', ['stale'], 900);
    Cache::put('kbb.shop.brands', ['stale'], 900);
}

it('drops the product rails when a product price changes', function () {
    $product = cwProduct();

    cwWarm();
    $product->update(['price' => 9900]);

    expect(cwSurvivors(['kbb.home.rails', 'kbb.home.routine']))->toBe([]);
});

it('drops the rails when a product is put on sale', function () {
    $product = cwProduct();

    cwWarm();
    $product->update(['sale_price' => 1500]);

    expect(Cache::get('kbb.home.rails'))->toBeNull();
});

it('drops the rails and the catalogue count when a product is hidden', function () {
    $product = cwProduct();

    cwWarm();
    $product->update(['is_visible' => false]);

    // kbb.home.count is the "671 products" line in the copy; kbb.home.brands and
    // kbb.home.cats are withCount(visible) tallies. Hiding a product moves all
    // three, so a page that states a number never states a retired one.
    expect(cwSurvivors([
        'kbb.home.rails', 'kbb.home.count', 'kbb.home.brands', 'kbb.home.cats',
    ]))->toBe([]);
});

it('drops the rails when a product goes out of stock', function () {
    $product = cwProduct();

    cwWarm();
    $product->update(['stock_status' => 'outofstock']);

    expect(Cache::get('kbb.home.rails'))->toBeNull();
});

it('drops the rails when a product is deleted', function () {
    $product = cwProduct();

    cwWarm();
    $product->delete();

    expect(cwSurvivors(['kbb.home.rails', 'kbb.home.count']))->toBe([]);
});

it('drops the recommended rail when featured is toggled through the admin grid', function () {
    /*
     * The `featured` flag is what the recommended rail selects on, so this is
     * the most direct way to see the bug: the admin toggles it, the grid redraws
     * with the new state, and the homepage keeps advertising the old five.
     */
    $product = cwProduct();

    cwWarm();

    test()->actingAs(cwAdmin(), 'admin')
        ->postJson("/admin-api/catalog/products/{$product->id}/toggle-featured")
        ->assertOk();

    expect(Cache::get('kbb.home.rails'))->toBeNull();
});

it('drops the rails when a set\'s pricing RULE changes and nothing else does', function () {
    /*
     * ▲ THE HOLE THE WATCH LIST HAD, AND THE REASON IT WAS ARGUED SHUT RATHER
     *   THAN CLOSED.
     *
     * A rule-priced set does not keep its price in `price` in any meaningful
     * sense: SetPricing recomputes it at render from `set_price_mode`,
     * `set_discount` and `set_price_basis`. Those three were absent from
     * $homeProductColumns, and the argument for leaving them out was that
     * ProductEditorApiController -- the only writer of a set's rule -- writes
     * `price` in the same save, from SetPricing::derived().
     *
     * That reasoning is correct and it is a fact about ONE CALL SITE holding a
     * MODEL-LEVEL hook shut. The hook is bound to the model rather than to each
     * controller precisely because the review wall was done the other way and
     * went stale twice when a new write path did not know to copy the key --
     * and the header of this file says so in as many words.
     *
     * So this writes the rule THROUGH THE MODEL and changes nothing else, which
     * is exactly what a second writer will do: the discount moves from 10% to
     * 20% and the homepage keeps advertising the old figure for up to fifteen
     * minutes, while the product page prices it correctly. A shopper clicks one
     * price and lands on another -- the defect this whole hook exists for.
     *
     * MUTATION NOTE -- RAN. Remove `...SetPricing::COLUMNS` from
     * $homeProductColumns and this is red with kbb.home.rails surviving.
     */
    cwProduct([
        'type' => 'set',
        'set_price_mode' => \App\Support\SetPricing::MODE_PERCENT,
        'set_discount' => 1000,
    ]);

    /*
     * RE-READ, and the case is worthless without it. `wasRecentlyCreated` stays
     * true for the life of the object that did the INSERT, and the hook evicts
     * on `wasRecentlyCreated || wasChanged(...)` — so updating the instance that
     * created the row passes whether the watched columns are right or wrong.
     * The first draft of this case did exactly that and stayed GREEN under its
     * own mutation, which is the only failure mode a test has that matters.
     * The negative case at the foot of this file re-reads for the same reason.
     */
    $set = Product::query()->latest('id')->first();

    cwWarm();

    // The rule, and ONLY the rule. `price` is deliberately untouched, because a
    // writer that also moves `price` is the one case already covered.
    $set->update(['set_discount' => 2000]);

    expect(cwSurvivors(['kbb.home.rails', 'kbb.home.routine']))->toBe([],
        'a set\'s printed price changed and the homepage rails kept the old one');
});

it('drops the journal row when an article is published', function () {
    cwWarm();

    Post::create([
        'slug' => 'cache-article-' . uniqid(),
        'title' => 'Cache Article',
        'body' => '<p>Body.</p>',
        'status' => 'published',
        'published_at' => now(),
    ]);

    expect(Cache::get('kbb.home.posts'))->toBeNull();
});

it('drops the journal row when an article cover photo changes', function () {
    /*
     * The column is posts.cover; there is no posts.image. wasChanged() on a
     * column that does not exist is silently never dirty, so a guard naming the
     * wrong one passes every other test in this file and still leaves a changed
     * cover stale. This is the test that would have caught that, and did.
     */
    Post::create([
        'slug' => 'cache-cover-' . uniqid(),
        'title' => 'Cache Cover',
        'body' => '<p>Body.</p>',
        'status' => 'published',
        'published_at' => now(),
        'cover' => '/img/old.jpg',
    ]);

    // Fresh instance: wasRecentlyCreated stays true on the object that did the
    // insert and would evict on its own, passing this test no matter which
    // column the guard names. The editor loads the row back, and so does this.
    $post = Post::query()->latest('id')->first();

    cwWarm();
    $post->update(['cover' => '/img/new.jpg']);

    expect(Cache::get('kbb.home.posts'))->toBeNull();
});

it('drops the brand strip and its total when a brand is added', function () {
    cwWarm();

    Brand::create(['name' => 'Cache Brand', 'slug' => 'cache-brand-' . uniqid()]);

    expect(cwSurvivors(['kbb.home.brands', 'kbb.home.brandcount']))->toBe([]);
});

it('drops the shop sidebar counts when a product is hidden', function () {
    /*
     * ShopController::flushSidebarCache() is called by five admin controllers,
     * all of them for category, brand or layout writes, and by none of the
     * product write paths — so the sidebar counted a product the grid beside it
     * had already stopped showing.
     */
    $product = cwProduct();

    cwWarmSidebar();
    $product->update(['is_visible' => false]);

    expect(cwSurvivors(['kbb.shop.cats', 'kbb.shop.brands']))->toBe([]);
});

it('drops the sidebar counts when a product moves to another brand', function () {
    $product = cwProduct();
    $brand = Brand::create(['name' => 'Sidebar Brand', 'slug' => 'sidebar-brand-' . uniqid()]);

    cwWarmSidebar();
    $product->update(['brand_id' => $brand->id]);

    expect(cwSurvivors(['kbb.shop.cats', 'kbb.shop.brands']))->toBe([]);
});

it('drops the sidebar counts when a product is deleted', function () {
    $product = cwProduct();

    cwWarmSidebar();
    $product->delete();

    expect(cwSurvivors(['kbb.shop.cats', 'kbb.shop.brands']))->toBe([]);
});

it('leaves the sidebar counts alone when only a price changes', function () {
    /*
     * The sidebar shows counts, not prices. Re-counting the whole catalogue
     * because one product went on sale is work nobody asked for, so the
     * visibility column list is deliberately narrower than the homepage's.
     */
    cwProduct();
    $product = Product::query()->latest('id')->first();

    cwWarmSidebar();
    $product->update(['price' => 12300]);

    expect(cwSurvivors(['kbb.shop.cats', 'kbb.shop.brands']))
        ->toBe(['kbb.shop.cats', 'kbb.shop.brands']);
});

it('leaves the homepage alone when a write touches nothing it renders', function () {
    /*
     * The guard against over-evicting. Saving a product without changing
     * anything, which the editor does on every open-and-close, must not throw
     * away eight valid entries and send the next visitor through a full rebuild.
     */
    cwProduct();

    // Re-read rather than reusing the created instance: wasRecentlyCreated stays
    // true for the life of the object that did the insert, and a brand-new
    // product SHOULD evict. The editor loads the row fresh, so this is the shape
    // the guard actually has to survive.
    $product = Product::query()->latest('id')->first();

    cwWarm();
    $product->save();

    expect(Cache::get('kbb.home.rails'))->toBe(['stale']);
});
