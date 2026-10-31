<?php

declare(strict_types=1);

/*
 * The owner, 31 October 2026: "I have added the recent products. please check
 * and translate properly in arabic. and give me a complete patch of those
 * products translation including everything, also as per seo too."
 *
 * What a defect looks like on the shop: a product page on /ar still showing
 * English (the name, the text beside the price, the description tab), or its
 * <title> / meta description still English to a search engine; or the
 * owner's own Arabic replaced by the package.
 *
 * MUTATION, RUN: drop the published-row guard in put() -> the owner's Arabic
 * is replaced and the second case is red; write the name field only -> the
 * description assertions are red.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const LT3_FILE = 'migrations/2027_12_31_230000_arabic_new_products_31_october.php';

function lt3Migration(): object
{
    return require database_path(LT3_FILE);
}

function lt3Const(string $name): array
{
    return (new ReflectionClassConstant(lt3Migration(), $name))->getValue();
}

it('carries Arabic for all 29 products still English on /ar: name always, and every field the English has', function () {
    $data = lt3Const('PRODUCTS');
    expect($data)->toHaveCount(29);

    foreach ($data as $slug => [$name, $short, $desc]) {
        // "<brand in Arabic> - <product>", as every Arabic product name on the shop.
        expect(preg_match('/^\p{Arabic}.* - .*\p{Arabic}/u', (string) $name))->toBe(1, $slug);
        foreach ([$short, $desc] as $field) {
            if ($field === null) {
                continue;
            }
            expect(preg_match('/\p{Arabic}/u', $field))->toBe(1, $slug);
            // An English sentence left behind is the defect this package fixes.
            expect(preg_match('/\b(the|and|with|your|skin|apply|helps)\b/i', strip_tags($field)))->toBe(0, $slug);
        }
    }

    // The one product with no English description gets none invented.
    expect($data['medicube-pdrn-pink-vita-coating-mask-new'][2])->toBeNull()
        // Short descriptions only where the English has one (5 of the 29).
        ->and(count(array_filter($data, fn ($r) => $r[1] !== null)))->toBe(5)
        ->and(lt3Const('BRANDS'))->toBe(['FRANKLY' => 'فرانكلي', 'Frankly' => 'فرانكلي']);
});

it('publishes by slug, never over Arabic the owner typed, puts FRANKLY in Arabic, and shows Arabic on /ar -- title and meta description included', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'frankly-lt3', 'name' => 'FRANKLY']);
    $p = Product::create(['slug' => 'frankly-retinol-0-1-cream', 'name' => 'FRANKLY - Retinol 0.1% Cream', 'brand_id' => $brand->id, 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'description' => '<div><b>Key Benefits:</b></div><div>English body text.</div>']);
    $owned = Product::create(['slug' => 'tirtir-hydro-boost-enzyme-cleansing-balm', 'name' => 'TIRTIR - Hydro Boost Enzyme Cleansing Balm', 'status' => 'publish', 'is_visible' => true,
        'price' => 8500, 'stock_status' => 'instock', 'short_description' => 'English short']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('translations')->count();

    lt3Migration()->up();
    TranslationStore::flush();

    [$name, , $desc] = lt3Const('PRODUCTS')['frankly-retinol-0-1-cream'];
    $get = fn (int $id, string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => $f])->first();

    expect($get($p->id, 'name')->value)->toBe($name)
        ->and($get($p->id, 'name')->status)->toBe('published')
        ->and($get($p->id, 'name')->source_hash)->toBe(sha1('FRANKLY - Retinol 0.1% Cream'))
        ->and($get($p->id, 'description')->value)->toBe($desc)
        // His own Arabic stays; the package only filled what was empty.
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك')
        ->and(DB::table('translations')->where(['group' => 'brands', 'item_id' => $brand->id, 'field' => 'name'])->value('value'))->toBe('فرانكلي')
        // FRANKLY: name + description + brand; TIRTIR: name + description (short was his).
        ->and(DB::table('translations')->count())->toBe($before + 5);

    $html = test()->get('/ar/product/frankly-retinol-0-1-cream/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    expect($html)->toContain(e($name))->not->toContain('English body text')
        // SEO: the Arabic page's title and description are Arabic, the brand in Arabic too.
        ->and($t[1])->toContain('فرانكلي')->not->toContain('Retinol 0.1% Cream')
        ->and(preg_match('/\p{Arabic}/u', html_entity_decode($d[1])))->toBe(1)
        ->and($d[1])->not->toContain('English');

    // Run twice: nothing changes, nothing duplicates.
    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value', 'status'])->toArray();
    lt3Migration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value', 'status'])->toArray())->toEqual($snapshot);
});
