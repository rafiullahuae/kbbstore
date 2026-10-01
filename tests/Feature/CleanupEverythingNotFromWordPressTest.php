<?php

/**
 * "Remove all the products ... categories, etc etc also" -- keeping the pages,
 * menus and banners. The owner, 1 October 2026, before importing his real shop.
 *
 * WHAT HIS SHOP HELD, measured over SSH: 25 products, 6 categories, 8 brands,
 * 58 reviews, 2 orders and 3 customers that did not come from WordPress, beside
 * 7 pages, 2 menus (78 items), a homepage banner and 4 videos he wants kept.
 * The four original buckets reached 7 of the 25 products: the demo catalogue
 * was held back because his two TEST orders had bought it, and nothing at all
 * reached the categories, brands, orders or customers.
 *
 * THE FIXTURE IS HIS SHOP AFTER AN IMPORT HAS ALSO BEGUN, which is the harder
 * case: every kind of leftover he has, AND a real imported row of every kind
 * next to it, AND the design he is keeping. Then everything is purged, and the
 * assertions are by id: every leftover gone, every real row and every design
 * row still there, and nothing left pointing at an order that no longer exists.
 *
 * MUTATIONS, RUN:
 *   - drop the soldInRealOrder() guard from otherProductQuery(): red on the
 *     hand-made product a REAL imported order bought;
 *   - drop ->whereNull('source_id') from otherReviewQuery(): red on the review
 *     that carries a WordPress comment id under an older source label;
 *   - drop the side-table loop from deleteTestOrders(): red on the stock claim
 *     and the reconciliation finding left naming a deleted order.
 * And on -c phpunit-mysql.xml, deleting other_products with the self-referencing
 * NOT IN instead of by id fails with MySQL error 1093.
 */

use App\Services\Maintenance\PreMigrationCleanup;
use Illuminate\Support\Facades\DB;

/** @return array<string, array<string, list<int>>> */
function ceSeedHisShop(): array
{
    // The migrations seed a demo catalogue, demo reviews and placeholder
    // categories and brands into every database. Counted here as part of the
    // leftovers rather than cleared, because that is exactly what his shop has.
    $now = now();
    $go = ['products' => [], 'categories' => [], 'brands' => [], 'reviews' => [], 'orders' => [], 'customers' => []];
    $keep = ['products' => [], 'categories' => [], 'brands' => [], 'reviews' => [], 'orders' => [], 'customers' => [],
        'order_items' => [], 'pages' => [], 'menus' => [], 'menu_items' => [], 'banner_sets' => [], 'banner_cards' => []];

    $go['products'] = DB::table('products')->whereNull('wc_id')->pluck('id')->all();
    $go['categories'] = DB::table('categories')->whereNull('source_term_id')->pluck('id')->all();
    $go['brands'] = DB::table('brands')->whereNull('source_term_id')->pluck('id')->all();
    $go['reviews'] = DB::table('reviews')->whereNull('source_id')->pluck('id')->all();

    /* ---------------------------------------------- real, imported rows ---- */

    $keep['categories'][] = $realCat = DB::table('categories')->insertGetId([
        'name' => 'Real Toners', 'slug' => 'ce-real-toners', 'source_term_id' => 7001,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $keep['brands'][] = $realBrand = DB::table('brands')->insertGetId([
        'name' => 'Real COSRX', 'slug' => 'ce-real-cosrx', 'source_term_id' => 8001,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $keep['products'][] = $realProduct = DB::table('products')->insertGetId([
        'name' => 'Real Serum', 'slug' => 'ce-real-serum', 'sku' => 'KBB-4021', 'wc_id' => 4021,
        'price' => 9900, 'status' => 'publish', 'brand_id' => $realBrand, 'category_id' => $realCat,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // Hand-made here (no wc_id, no DEMO- sku) AND bought in a real imported
    // order. Sold is sold: it must stay.
    $keep['products'][] = $soldForReal = DB::table('products')->insertGetId([
        'name' => 'Hand-made, sold for real', 'slug' => 'ce-sold-real', 'sku' => null, 'wc_id' => null,
        'price' => 5000, 'status' => 'publish', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $go['products'] = array_values(array_diff($go['products'], [$soldForReal]));

    $keep['customers'][] = $realCustomer = DB::table('customers')->insertGetId([
        'email' => 'aisha@example.test', 'first_name' => 'Aisha', 'wp_user_id' => 412,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    // No wp_user_id (a guest at the old shop), but her order came from WooCommerce.
    $keep['customers'][] = $guestReal = DB::table('customers')->insertGetId([
        'email' => 'guest@example.test', 'first_name' => 'Guest', 'wp_user_id' => null,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $keep['orders'][] = $realOrder = DB::table('orders')->insertGetId([
        'order_number' => '5521', 'customer_id' => $guestReal, 'status' => 'completed',
        'email' => 'guest@example.test', 'total' => 14900, 'wc_order_id' => 9001,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach ([$realProduct, $soldForReal] as $pid) {
        $keep['order_items'][] = DB::table('order_items')->insertGetId([
            'order_id' => $realOrder, 'product_id' => $pid, 'name' => 'line', 'quantity' => 1,
            'unit_price' => 5000, 'subtotal' => 5000, 'total' => 5000, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $keep['reviews'][] = DB::table('reviews')->insertGetId([
        'product_id' => $realProduct, 'author_name' => 'Imported', 'author_email' => 'i@example.test',
        'rating' => 5, 'title' => 'Good', 'content' => 'From the old shop.', 'status' => 'approved',
        'source' => 'wp_comment', 'source_id' => 8101, 'created_at' => $now, 'updated_at' => $now,
    ]);
    // An older import path stamped 'woocommerce'. The comment id is what counts.
    $keep['reviews'][] = DB::table('reviews')->insertGetId([
        'product_id' => $realProduct, 'author_name' => 'Older import', 'author_email' => 'o@example.test',
        'rating' => 4, 'title' => 'Fine', 'content' => 'Also from the old shop.', 'status' => 'approved',
        'source' => 'woocommerce', 'source_id' => 8102, 'created_at' => $now, 'updated_at' => $now,
    ]);

    /* ------------------------------------------------- his leftovers ------- */

    $go['products'][] = $handTyped = DB::table('products')->insertGetId([
        'name' => 'Medicube booster set', 'slug' => 'ce-medicube-booster-set', 'sku' => null, 'wc_id' => null,
        'price' => 20000, 'status' => 'publish', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $demoSoldInTest = DB::table('products')->whereNull('wc_id')->where('sku', 'like', 'DEMO-%')->orderBy('id')->value('id');

    $go['customers'][] = $tester = DB::table('customers')->insertGetId([
        'email' => 'rite2rafi@example.test', 'first_name' => 'Tester', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $go['customers'][] = DB::table('customers')->insertGetId([
        'email' => 'nobody@example.test', 'first_name' => 'Signed up', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $couponId = DB::table('coupons')->insertGetId(['code' => 'CE-TEST', 'type' => 'percent', 'amount' => 1000,
        'created_at' => $now, 'updated_at' => $now]);

    foreach (['10004' => $tester, '10006' => null] as $number => $customerId) {
        $go['orders'][] = $orderId = DB::table('orders')->insertGetId([
            'order_number' => (string) $number, 'customer_id' => $customerId, 'status' => 'processing',
            'email' => 'rite2rafi@example.test', 'total' => 320400, 'wc_order_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $demoSoldInTest, 'name' => 'Demo', 'quantity' => 1,
            'unit_price' => 1000, 'subtotal' => 1000, 'total' => 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('payments')->insert(['id' => (string) Illuminate\Support\Str::uuid(), 'order_id' => $orderId,
            'provider' => 'test', 'amount' => 320400, 'currency' => 'AED', 'status' => 'paid',
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('coupon_redemptions')->insert(['coupon_id' => $couponId, 'customer_id' => $customerId,
            'order_id' => $orderId, 'email' => 'rite2rafi@example.test', 'amount' => 100,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('order_stock_claims')->insert(['order_id' => $orderId, 'shelf_table' => 'products',
            'shelf_id' => $demoSoldInTest, 'product_id' => $demoSoldInTest, 'quantity' => 1,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('reconciliation_findings')->insert(['run_id' => 1, 'provider' => 'test', 'kind' => 'missing',
            'order_id' => $orderId, 'summary' => 'test', 'fingerprint' => 'ce-'.$orderId,
            'created_at' => $now, 'updated_at' => $now]);
    }

    // A review written on this shop, and a demo one.
    $go['reviews'][] = DB::table('reviews')->insertGetId([
        'product_id' => $realProduct, 'author_name' => 'Here', 'author_email' => 'h@example.test',
        'rating' => 5, 'title' => 'Nice', 'content' => 'Left on the new shop.', 'status' => 'approved',
        'source' => 'sorina', 'source_id' => null, 'created_at' => $now, 'updated_at' => $now,
    ]);

    /* -------------------------------------------- the design he keeps ------ */

    $keep['pages'][] = DB::table('pages')->insertGetId(['slug' => 'ce-privacy-policy', 'title' => 'Privacy',
        'created_at' => $now, 'updated_at' => $now]);
    $keep['menus'][] = $menu = DB::table('menus')->insertGetId(['slug' => 'ce-header', 'name' => 'Header',
        'created_at' => $now, 'updated_at' => $now]);
    $keep['menu_items'][] = DB::table('menu_items')->insertGetId(['menu_id' => $menu, 'label' => 'Toners',
        'url' => '/collections/toners/', 'created_at' => $now, 'updated_at' => $now]);
    $keep['banner_sets'][] = $set = DB::table('banner_sets')->insertGetId(['name' => 'Home', 'slug' => 'ce-home',
        'created_at' => $now, 'updated_at' => $now]);
    $keep['banner_cards'][] = DB::table('banner_cards')->insertGetId(['banner_set_id' => $set,
        'image' => 'uploads/banners/ce.webp', 'created_at' => $now, 'updated_at' => $now]);

    // Everything any page/menu/banner row the migrations seeded is kept too.
    foreach (['pages', 'menus', 'menu_items', 'banner_sets', 'banner_cards'] as $t) {
        $keep[$t] = DB::table($t)->pluck('id')->all();
    }

    return ['go' => $go, 'keep' => $keep];
}

it('removes everything not from WordPress, and nothing that is, nor the design he keeps', function () {
    $seeded = ceSeedHisShop();
    $cleanup = new PreMigrationCleanup;
    $preview = $cleanup->preview();

    // The screen promises exactly the leftovers, bucket by bucket.
    expect($preview['counts']['test_orders'])->toBe(2)
        ->and($preview['counts']['test_customers'])->toBe(2)
        ->and($preview['counts']['demo_products'] + $preview['counts']['other_products'])
            ->toBe(count($seeded['go']['products']))
        ->and($preview['counts']['placeholder_categories'])->toBe(count($seeded['go']['categories']))
        ->and($preview['counts']['placeholder_brands'])->toBe(count($seeded['go']['brands']))
        ->and($preview['counts']['demo_reviews'] + $preview['counts']['other_reviews'])
            ->toBe(count($seeded['go']['reviews']))
        ->and($preview['buckets']['other_products']['held_back'])->toBe(1);

    $result = $cleanup->purge(PreMigrationCleanup::BUCKETS, $preview['counts']);
    expect($result['ok'])->toBeTrue(json_encode($result));

    foreach ($seeded['go'] as $table => $ids) {
        foreach ($ids as $id) {
            expect(DB::table($table)->where('id', $id)->exists())->toBeFalse("{$table} #{$id} survived and is a leftover");
        }
    }

    foreach ($seeded['keep'] as $table => $ids) {
        foreach ($ids as $id) {
            expect(DB::table($table)->where('id', $id)->exists())->toBeTrue("{$table} #{$id} was deleted and must stay");
        }
    }

    // The real order still resolves both of its lines to their products.
    expect(DB::table('order_items')->whereIn('id', $seeded['keep']['order_items'])
        ->whereNotNull('product_id')->count())->toBe(2);

    // Nothing is left naming an order that no longer exists.
    foreach (['payments', 'coupon_redemptions', 'order_stock_claims', 'reconciliation_findings', 'order_items'] as $t) {
        expect(DB::table($t)->whereNotNull('order_id')
            ->whereNotIn('order_id', DB::table('orders')->select('id'))->count())
            ->toBe(0, "{$t} still names a deleted order");
    }

    // And nothing is left that did not come from WordPress, but the one product
    // a real order bought.
    expect(DB::table('products')->whereNull('wc_id')->pluck('name')->all())->toBe(['Hand-made, sold for real'])
        ->and(DB::table('orders')->whereNull('wc_order_id')->count())->toBe(0)
        ->and(DB::table('categories')->whereNull('source_term_id')->count())->toBe(0)
        ->and(DB::table('brands')->whereNull('source_term_id')->count())->toBe(0)
        ->and(DB::table('reviews')->whereNull('source_id')->count())->toBe(0);
});

it('refuses the whole purge when a count has moved since the list was drawn', function () {
    ceSeedHisShop();
    $cleanup = new PreMigrationCleanup;
    $counts = $cleanup->preview()['counts'];

    $before = [DB::table('products')->count(), DB::table('orders')->count(), DB::table('categories')->count()];
    $counts['test_orders']++;

    $result = $cleanup->purge(PreMigrationCleanup::BUCKETS, $counts);

    expect($result['ok'])->toBeFalse()
        ->and([DB::table('products')->count(), DB::table('orders')->count(), DB::table('categories')->count()])
            ->toBe($before);
});
