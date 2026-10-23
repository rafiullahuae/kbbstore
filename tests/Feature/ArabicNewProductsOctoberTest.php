<?php

declare(strict_types=1);

/*
 * The owner, 23 October 2026: "translate completely all products we have added
 * in english recently ... find which has no arabic versions". 34 products had
 * no Arabic on /ar (their tab, heading and Google listing read English), plus
 * one with an English description and two English brand names.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

function anpMigration(): object
{
    return require database_path('migrations/2027_11_29_100000_arabic_new_products_23_october.php');
}

function anpData(): array
{
    return (new ReflectionClassConstant(anpMigration(), 'PRODUCTS'))->getValue();
}

it('carries Arabic for every one of the 35 listings, each with the English HTML tags intact', function () {
    $data = anpData();
    expect($data)->toHaveCount(35);

    foreach ($data as $slug => [$name, $short, $desc]) {
        if ($slug !== 'sebum-sweeper-pore-cleansing-silicone-brush') {
            expect(preg_match('/\p{Arabic}/u', (string) $name))->toBe(1, $slug);
        }
        expect(preg_match('/\p{Arabic}/u', (string) $desc))->toBe(1, $slug);
    }
});

it('publishes the Arabic on the product found by slug, never over Arabic already there, and shows it on /ar', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'jumiso-anp', 'name' => 'JUMISO']);
    $p = Product::create(['slug' => 'jumiso-niacinamide-10-serum', 'name' => 'JUMISO - Niacinamide 10 Serum', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'brand_id' => $brand->id, 'short_description' => '<div>English blurb</div>', 'description' => '<p>English</p>']);
    $owned = Product::create(['slug' => 'epii-hpt6-ultra-drop-10', 'name' => 'epii - HPT6 Ultra Drop 10%', 'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'name', 'value' => 'اسم كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    $eunyul = Brand::create(['slug' => 'eunyul-anp', 'name' => 'EUNYUL']);

    anpMigration()->up();
    TranslationStore::flush();

    $name = anpData()['jumiso-niacinamide-10-serum'][0];
    $row = DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'name'])->first();

    // MUTATION: drop the published-row guard in put(). RED (the owner's name is replaced).
    expect($row->value)->toBe($name)
        ->and($row->status)->toBe('published')
        ->and($row->source_hash)->toBe(sha1('JUMISO - Niacinamide 10 Serum'))
        ->and(DB::table('translations')->where(['item_id' => $owned->id, 'group' => 'products', 'field' => 'name'])->value('value'))->toBe('اسم كتبه المالك')
        ->and(DB::table('translations')->where(['group' => 'brands', 'item_id' => $eunyul->id, 'field' => 'name'])->value('value'))->toBe('يونيول');

    $html = test()->get('/ar/product/jumiso-niacinamide-10-serum/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    expect($html)->toContain(e($name))
        ->and(html_entity_decode($t[1]))->toContain('سيروم النياسيناميد');

    // Run twice: nothing changes, nothing duplicates.
    anpMigration()->up();
    expect(DB::table('translations')->where(['item_id' => $p->id, 'group' => 'products'])->count())->toBe(3);
});
