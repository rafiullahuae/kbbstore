<?php

/*
 * Fixture for the Lane IE cleanup screen.
 *
 * A shop that has BOTH kinds of row, so the screen photographs what it actually
 * does rather than an empty state: real imported products beside seeded demo
 * ones, a demo product that has been SOLD, a real review beside seeded demo
 * ones, and a stack of applied update packages older than the five it keeps.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$owner = \App\Models\AdminUser::firstOrCreate(
    ['email' => 'owner@preview.test'],
    ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']
);

DB::table('admin_users')->where('id', $owner->id)->update([
    'password' => bcrypt('preview-secret-1'),
    'role' => 'owner',
]);

/* ---- real products, carrying the WooCommerce id the importer upserts on ---- */

foreach ([
    [4021, 'Ginseng Essence Water', 'ie-ginseng', 'KBB-4021', 9950],
    [4022, 'Rice Probiotics Toner', 'ie-rice', 'KBB-4022', 8000],
    [4023, 'Relief Sun SPF50', 'ie-relief', 'KBB-4023', 6500],
] as [$wc, $name, $slug, $sku, $price]) {
    DB::table('products')->updateOrInsert(['wc_id' => $wc], [
        'name' => $name, 'slug' => $slug, 'sku' => $sku, 'price' => $price,
        'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/* ---- a real product typed into THIS panel: no wc_id, and not a demo sku ---- */

DB::table('products')->updateOrInsert(['slug' => 'ie-hand-typed'], [
    'name' => 'Hand-typed Cleansing Balm', 'sku' => 'HAND-001', 'wc_id' => null,
    'price' => 5500, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
]);

/* ---- a demo product somebody bought, which must be held back ------------- */

DB::table('products')->updateOrInsert(['slug' => 'ie-demo-sold'], [
    'name' => 'Demo Product (sold)', 'sku' => 'DEMO-9999', 'wc_id' => null,
    'price' => 1000, 'status' => 'publish', 'created_at' => now(), 'updated_at' => now(),
]);

$sold = DB::table('products')->where('slug', 'ie-demo-sold')->value('id');

$customer = DB::table('customers')->insertGetId([
    'email' => 'aisha@example.test', 'first_name' => 'Aisha', 'last_name' => 'Khan',
    'created_at' => now(), 'updated_at' => now(),
]);

$order = DB::table('orders')->insertGetId([
    'order_number' => 'IE-1001', 'customer_id' => $customer, 'status' => 'completed',
    'email' => 'aisha@example.test', 'total' => 1000, 'wc_order_id' => 9001,
    'created_at' => now(), 'updated_at' => now(),
]);

DB::table('order_items')->insert([
    'order_id' => $order, 'product_id' => $sold, 'name' => 'Demo Product (sold)',
    'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'total' => 1000,
    'created_at' => now(), 'updated_at' => now(),
]);

/* ---- reviews: one imported, one written here, two seeded demo ------------ */

$real = DB::table('products')->where('wc_id', 4021)->value('id');

DB::table('reviews')->insert([
    'product_id' => $real, 'author_name' => 'Fatima A.', 'author_email' => 'f@example.test',
    'title' => 'Lovely', 'content' => 'Imported from the old shop.', 'rating' => 5,
    'status' => 'approved', 'source' => 'woocommerce', 'source_id' => 5001,
    'created_at' => now(), 'updated_at' => now(),
]);

foreach ([1, 2, 3] as $i) {
    DB::table('reviews')->insert([
        'product_id' => $real, 'author_name' => "Demo Reviewer {$i}",
        'author_email' => "d{$i}@example.test", 'title' => 'Seeded',
        'content' => 'Seeded demo review.', 'rating' => 5, 'status' => 'approved',
        'source' => 'demo', 'source_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/* ---- eight applied update packages, so three are older than the kept five - */

foreach (range(1, 8) as $i) {
    Storage::disk('local')->put(
        sprintf('kbb-patch-archive/2.60.3%02d_a-shipped-release.zip', $i),
        str_repeat('z', 40000 * $i)
    );
    @touch(
        Storage::disk('local')->path(sprintf('kbb-patch-archive/2.60.3%02d_a-shipped-release.zip', $i)),
        time() - (100000 - ($i * 1000))
    );
}

/* ---- a log file with something in it ------------------------------------ */

@file_put_contents(storage_path('logs/laravel.log'), str_repeat("preview log line\n", 4000));

/* ---- and rows the Demo Content screen owns, so the page can count them --- */

if (\Illuminate\Support\Facades\Schema::hasTable('demo_seed_log')) {
    foreach (range(1, 6) as $i) {
        DB::table('demo_seed_log')->insert([
            'type' => 'orders', 'model' => 'Order', 'record_id' => $i, 'created_at' => now(),
        ]);
    }
}

echo "ie-seed: done\n";
