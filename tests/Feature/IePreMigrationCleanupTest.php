<?php

/**
 * "Delete un-wanted data and patches etc from the site."             (Lane IE)
 *
 * THE FIRST TEST IN THIS FILE IS THE ONLY ONE THAT REALLY MATTERS, and it is
 * first because it was written first. He is about to move a real shop — real
 * products, real customers, real orders, real reviews — into this application,
 * and a cleanup that deletes one row of it is worse than no cleanup at all,
 * because he will find out after the WooCommerce site is gone.
 *
 * So: seed a shop that has BOTH kinds of row, purge everything the screen
 * offers, and assert the real rows are all still there — by count AND by id.
 * A count alone passes a purge that deleted a real product and left a demo one.
 */

use App\Models\AdminUser;
use App\Services\Maintenance\PreMigrationCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CleanupAdminRoutes;

function ieOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Cleanup Owner',
        'email' => 'ie-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/**
 * A shop with both kinds of row in every table the cleanup touches.
 *
 * @return array<string, list<int>> what must survive, by table
 */
function ieSeedMixedShop(): array
{
    /*
     * A CLEAN SLATE FIRST, and it is worth saying why rather than just doing it.
     * `2026_08_27_100000_seed_demo_catalogue` seeds 24 demo products into EVERY
     * database including this one, and `2026_10_11_000002_seed_demo_reviews`
     * seeds demo reviews. They are exactly what this cleanup exists to remove,
     * so leaving them in would make every count below depend on a seeder another
     * lane owns. Cleared here so the fixture is the only population, and the
     * numbers in the assertions are the fixture's own.
     */
    DB::table('reviews')->where('source', 'demo')->delete();
    DB::table('products')->whereNull('wc_id')->where('sku', 'like', 'DEMO-%')->delete();

    /* ---- products: three real, three demo, one demo that has been SOLD ---- */

    $realA = DB::table('products')->insertGetId([
        'name' => 'Ginseng Serum', 'slug' => 'ie-real-serum', 'sku' => 'KBB-4021',
        'wc_id' => 4021, 'price' => 9950, 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $realB = DB::table('products')->insertGetId([
        'name' => 'Rice Toner', 'slug' => 'ie-real-toner', 'sku' => 'KBB-4022',
        'wc_id' => 4022, 'price' => 8000, 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * THE NASTY ONE: a real product he typed into THIS admin panel by hand, so
     * it has no wc_id at all. `wc_id IS NULL` alone would delete it, which is
     * why the sku marker is required on top. Its sku deliberately does not
     * begin with DEMO-.
     */
    $realC = DB::table('products')->insertGetId([
        'name' => 'Hand-typed Cleanser', 'slug' => 'ie-real-handtyped', 'sku' => 'HAND-001',
        'wc_id' => null, 'price' => 5000, 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $demo = [];

    foreach ([1, 2, 3] as $i) {
        $demo[] = DB::table('products')->insertGetId([
            'name' => "Demo Product {$i}", 'slug' => "ie-demo-{$i}",
            'sku' => 'DEMO-000'.$i, 'wc_id' => null, 'price' => 1000, 'status' => 'publish',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*
     * AND A DEMO PRODUCT SOMEBODY BOUGHT. It carries every demo marker, so the
     * markers alone would delete it — and its line item would be left pointing
     * at nothing on a real order. It must survive.
     */
    $demoSold = DB::table('products')->insertGetId([
        'name' => 'Demo Product Sold', 'slug' => 'ie-demo-sold',
        'sku' => 'DEMO-9999', 'wc_id' => null, 'price' => 1000, 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /* ---------------------------- a real customer and a real order --------- */

    $customer = DB::table('customers')->insertGetId([
        'email' => 'aisha@example.test', 'first_name' => 'Aisha', 'last_name' => 'Khan',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = DB::table('orders')->insertGetId([
        'order_number' => 'IE-1001', 'customer_id' => $customer, 'status' => 'completed',
        'email' => 'aisha@example.test', 'total' => 1000, 'wc_order_id' => 9001,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $item = DB::table('order_items')->insertGetId([
        'order_id' => $order, 'product_id' => $demoSold, 'name' => 'Demo Product Sold',
        'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'total' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /* ------------------------------------------------------------- reviews - */

    // Imported from his WordPress: source is not 'demo', and it carries the
    // WordPress comment id.
    $realReview = DB::table('reviews')->insertGetId([
        'product_id' => $realA, 'author_name' => 'Real Shopper', 'rating' => 5,
        'content' => 'Genuinely good.', 'author_email' => 'r@example.test', 'title' => 'Good', 'status' => 'approved', 'source' => 'woocommerce',
        'source_id' => 5001, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Written on THIS shop by a shopper. Default source, no source_id.
    $shopReview = DB::table('reviews')->insertGetId([
        'product_id' => $realA, 'author_name' => 'New Shopper', 'rating' => 4,
        'content' => 'Arrived fast.', 'author_email' => 'n@example.test', 'title' => 'Fast', 'status' => 'approved', 'source' => 'sorina',
        'source_id' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * THE OTHER NASTY ONE: a row stamped 'demo' that nonetheless carries a
     * WordPress comment id. Whatever put the stamp there, a row that names a
     * comment on his site came from his site. It must survive.
     */
    $demoButImported = DB::table('reviews')->insertGetId([
        'product_id' => $realA, 'author_name' => 'Imported Yet Stamped', 'rating' => 3,
        'content' => 'From the old shop.', 'author_email' => 'i@example.test', 'title' => 'Old', 'status' => 'approved', 'source' => 'demo',
        'source_id' => 5002, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $demoReviews = [];

    foreach ([1, 2] as $i) {
        $demoReviews[] = DB::table('reviews')->insertGetId([
            'product_id' => $demo[0], 'author_name' => "Demo Reviewer {$i}", 'rating' => 5,
            'content' => 'Seeded.', 'author_email' => 'd@example.test', 'title' => 'Seed', 'status' => 'approved', 'source' => 'demo',
            'source_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return [
        'must_survive_products' => [$realA, $realB, $realC, $demoSold],
        'must_go_products' => $demo,
        'must_survive_reviews' => [$realReview, $shopReview, $demoButImported],
        'must_go_reviews' => $demoReviews,
        'order' => [$order], 'order_item' => [$item], 'customer' => [$customer],
    ];
}

/* ====================================================================== 1 == */

it('deletes every demo row and not one real one', function () {
    /*
     * THE MUTATION NOTE, and it was run rather than reasoned about:
     *
     *   - drop `->where('sku', 'like', 'DEMO-%')` from demoProductQuery() and
     *     this goes red on the hand-typed cleanser (HAND-001), which has no
     *     wc_id and is a product he made himself;
     *   - drop the `whereNotExists` on order_items and it goes red on
     *     DEMO-9999, the demo product somebody bought;
     *   - drop `->whereNull('source_id')` from demoReviewQuery() and it goes
     *     red on the review stamped 'demo' that carries comment id 5002.
     *
     * Each of those three is a row this shop would have lost.
     */
    $seeded = ieSeedMixedShop();

    $before = [
        'products' => DB::table('products')->count(),
        'reviews' => DB::table('reviews')->count(),
        'orders' => DB::table('orders')->count(),
        'order_items' => DB::table('order_items')->count(),
        'customers' => DB::table('customers')->count(),
    ];

    $cleanup = new PreMigrationCleanup;
    $preview = $cleanup->preview();

    // The preview promises exactly the three unsold demo products and the two
    // seeded demo reviews, and nothing else.
    expect($preview['counts']['demo_products'])->toBe(3)
        ->and($preview['counts']['demo_reviews'])->toBe(2)
        ->and($preview['buckets']['demo_products']['held_back'])->toBe(1);

    $result = $cleanup->purge(PreMigrationCleanup::BUCKETS, $preview['counts']);

    expect($result['ok'])->toBeTrue()
        ->and($result['removed']['demo_products'])->toBe(3)
        ->and($result['removed']['demo_reviews'])->toBe(2);

    /* ---- BY ID. A count alone would pass a purge that took the wrong rows. - */

    foreach ($seeded['must_survive_products'] as $id) {
        expect(DB::table('products')->where('id', $id)->exists())
            ->toBeTrue("product #{$id} was deleted and it is REAL");
    }

    foreach ($seeded['must_survive_reviews'] as $id) {
        expect(DB::table('reviews')->where('id', $id)->exists())
            ->toBeTrue("review #{$id} was deleted and it is REAL");
    }

    foreach ($seeded['must_go_products'] as $id) {
        expect(DB::table('products')->where('id', $id)->exists())->toBeFalse();
    }

    foreach ($seeded['must_go_reviews'] as $id) {
        expect(DB::table('reviews')->where('id', $id)->exists())->toBeFalse();
    }

    /* ---- AND BY COUNT, on the tables it must not have touched at all. ----- */

    expect(DB::table('orders')->count())->toBe($before['orders'])
        ->and(DB::table('order_items')->count())->toBe($before['order_items'])
        ->and(DB::table('customers')->count())->toBe($before['customers'])
        ->and(DB::table('products')->count())->toBe($before['products'] - 3)
        ->and(DB::table('reviews')->count())->toBe($before['reviews'] - 2);

    // The order line still resolves to its product.
    expect(DB::table('order_items')->whereIn('product_id',
        DB::table('products')->select('id'))->count())
        ->toBe($before['order_items']);
});

/* ====================================================================== 2 == */

it('shows what it would delete and writes nothing while showing it', function () {
    $seeded = ieSeedMixedShop();

    $before = DB::table('products')->count();

    $preview = (new PreMigrationCleanup)->preview();

    // Looking changed nothing.
    expect(DB::table('products')->count())->toBe($before);

    // And it says what, with the rows named rather than only counted.
    expect($preview['buckets']['demo_products']['samples'])->toHaveCount(3)
        ->and(implode(' ', $preview['buckets']['demo_products']['samples']))->toContain('DEMO-0001')
        ->and(implode(' ', $preview['buckets']['demo_products']['samples']))->not->toContain('HAND-001');

    // And what it is protecting, by number.
    expect($preview['buckets']['demo_products']['protected']['products carrying a WooCommerce id (wc_id)'])
        ->toBeGreaterThanOrEqual(2);
});

/* ====================================================================== 3 == */

it('refuses when the shop has changed since the list was drawn', function () {
    /*
     * MUTATION: delete the `$expect[$key] !== $now[$key]` loop in purge() and
     * this goes red — the fourth demo product is deleted by a request that was
     * answering a list drawn before it existed.
     *
     * This is the guard that makes the delete impossible to fire by accident: a
     * tab left open, a replayed POST and a browser's back button all arrive
     * with figures that no longer describe the shop.
     */
    ieSeedMixedShop();

    $cleanup = new PreMigrationCleanup;
    $stale = $cleanup->preview();

    // The shop moves underneath it.
    DB::table('products')->insert([
        'name' => 'Demo Product 4', 'slug' => 'ie-demo-4', 'sku' => 'DEMO-0004',
        'wc_id' => null, 'price' => 1000, 'status' => 'publish',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = $cleanup->purge(['demo_products'], $stale['counts']);

    expect($result['ok'])->toBeFalse()
        ->and($result['stale'])->toBeTrue()
        ->and($result['message'])->toContain('Nothing was deleted')
        ->and(DB::table('products')->where('sku', 'like', 'DEMO-%')->count())->toBe(5);
});

/* ====================================================================== 4 == */

it('deletes nothing at all when no bucket is named', function () {
    ieSeedMixedShop();

    $before = DB::table('products')->count();

    $result = (new PreMigrationCleanup)->purge([], []);

    expect($result['ok'])->toBeFalse()
        ->and(DB::table('products')->count())->toBe($before);
});

/* ====================================================================== 5 == */

it('keeps the newest applied packages and removes only the older ones', function () {
    Storage::disk('local')->deleteDirectory('kbb-patch-archive');

    // Eight applied packages, oldest first.
    foreach (range(1, 8) as $i) {
        Storage::disk('local')->put("kbb-patch-archive/2.60.10{$i}_a-release.zip", str_repeat('z', 100 * $i));
        touch(Storage::disk('local')->path("kbb-patch-archive/2.60.10{$i}_a-release.zip"), time() - (1000 - $i));
    }

    // And something that is not a package. It is never counted and never goes.
    Storage::disk('local')->put('kbb-patch-archive/notes.txt', 'keep me');

    $cleanup = new PreMigrationCleanup;
    $preview = $cleanup->preview();

    expect($preview['counts']['patch_archives'])->toBe(3)         // 8 - KEEP_ARCHIVES
        ->and($preview['buckets']['patch_archives']['held_back'])->toBe(PreMigrationCleanup::KEEP_ARCHIVES)
        ->and($preview['buckets']['patch_archives']['bytes'])->toBe(100 + 200 + 300);

    $cleanup->purge(['patch_archives'], $preview['counts']);

    $left = Storage::disk('local')->files('kbb-patch-archive');

    expect(collect($left)->filter(fn ($f) => str_ends_with($f, '.zip'))->count())->toBe(5)
        ->and(Storage::disk('local')->exists('kbb-patch-archive/notes.txt'))->toBeTrue()
        ->and(Storage::disk('local')->exists('kbb-patch-archive/2.60.101_a-release.zip'))->toBeFalse()
        ->and(Storage::disk('local')->exists('kbb-patch-archive/2.60.108_a-release.zip'))->toBeTrue();
});

/* ====================================================================== 6 == */

it('is unreachable without signing in, and refuses every role but the owner', function () {
    CleanupAdminRoutes::wire($this->app);

    // Anonymous.
    $this->getJson('/admin-api/cleanup/preview')->assertStatus(401);
    $this->postJson('/admin-api/cleanup/purge', ['confirm' => 'DELETE'])->assertStatus(401);

    // Signed in, but not the owner. `data.cleanup` is owner-only.
    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(ieOwner($role), 'admin')
            ->getJson('/admin-api/cleanup/preview')
            ->assertStatus(403);

        $this->actingAs(ieOwner($role), 'admin')
            ->postJson('/admin-api/cleanup/purge', [
                'confirm' => 'DELETE', 'buckets' => ['demo_products'], 'expect' => ['demo_products' => 0],
            ])->assertStatus(403);
    }
});

/* ====================================================================== 7 == */

it('will not delete without the word typed', function () {
    CleanupAdminRoutes::wire($this->app);
    ieSeedMixedShop();

    $before = DB::table('products')->count();
    $owner = ieOwner();

    $preview = $this->actingAs($owner, 'admin')->getJson('/admin-api/cleanup/preview')->json();

    // No confirmation at all.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/cleanup/purge', [
        'buckets' => ['demo_products'], 'expect' => $preview['counts'],
    ])->assertStatus(422);

    // The wrong word.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/cleanup/purge', [
        'confirm' => 'yes', 'buckets' => ['demo_products'], 'expect' => $preview['counts'],
    ])->assertStatus(422);

    expect(DB::table('products')->count())->toBe($before);

    // And with it, it goes.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/cleanup/purge', [
        'confirm' => 'DELETE', 'buckets' => ['demo_products'], 'expect' => $preview['counts'],
    ])->assertOk();

    expect(DB::table('products')->count())->toBe($before - 3);
});

/* ====================================================================== 8 == */

it('ignores a bucket name it does not know rather than acting on it', function () {
    CleanupAdminRoutes::wire($this->app);
    ieSeedMixedShop();

    $owner = ieOwner();
    $before = DB::table('products')->count();

    // `orders` is not one of the four. It must be dropped, not honoured, and
    // the request then has nothing left to do.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/cleanup/purge', [
        'confirm' => 'DELETE', 'buckets' => ['orders', 'customers'], 'expect' => ['orders' => 1],
    ])->assertStatus(409);

    expect(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('products')->count())->toBe($before);
});

/* ====================================================================== 9 == */

it('leaves the Demo Content screen to do its own deleting', function () {
    /*
     * The brief: do not build a second purge if one already works. Demo Content
     * holds the primary key of every row it made in `demo_seed_log` and deletes
     * exactly those. This page counts them so the owner sees one picture, and
     * touches neither the log nor the rows it names.
     */
    DB::table('demo_seed_log')->insert([
        ['type' => 'orders', 'model' => 'Order', 'record_id' => 1, 'created_at' => now()],
        ['type' => 'orders', 'model' => 'Order', 'record_id' => 2, 'created_at' => now()],
    ]);

    $cleanup = new PreMigrationCleanup;
    $preview = $cleanup->preview();

    expect($preview['demo_seed_log']['count'])->toBe(2)
        ->and($preview['demo_seed_log']['where'])->toBe('Safety → Demo Content')
        // and it is NOT one of the things this page offers to delete
        ->and(PreMigrationCleanup::BUCKETS)->not->toContain('demo_seed_log');

    $cleanup->purge(PreMigrationCleanup::BUCKETS, $preview['counts']);

    expect(DB::table('demo_seed_log')->count())->toBe(2);
});

/* ===================================================================== 10 == */

it('drops a nested array in the request instead of casting it', function () {
    /*
     * MUTATION: remove the `$scalar` filter in CleanupApiController::purge()
     * and this is red on the PHP warning "Array to string conversion".
     *
     * Nothing here could ever have deleted the wrong thing — the intersect and
     * the count comparison both fail on the result either way — but a delete
     * endpoint should not be the place where that is true by luck.
     */
    CleanupAdminRoutes::wire($this->app);
    ieSeedMixedShop();

    $owner = ieOwner();
    $before = DB::table('products')->count();

    $this->actingAs($owner, 'admin')->postJson('/admin-api/cleanup/purge', [
        'confirm' => 'DELETE',
        'buckets' => [['demo_products'], 'demo_products'],
        'expect' => ['demo_products' => ['3']],
    ])->assertStatus(409);

    expect(DB::table('products')->count())->toBe($before);
});
