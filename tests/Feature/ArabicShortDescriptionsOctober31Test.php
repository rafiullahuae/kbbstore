<?php

declare(strict_types=1);

/*
 * The owner, on 2.60.631: "short description is not translated. plz fix."
 *
 * What it looked like on the shop: FRANKLY Retinol 0.1% Cream (and 22 more)
 * on /ar with an Arabic name and description tab, and the English paragraph
 * beside the price -- which on /ar is also the page's meta description. The
 * short description had been saved as plain text, printed in a <p>, and the
 * 2.60.631 read of the live page only looked for a <div>.
 *
 * MUTATION: write the name or description column instead of the short
 * description -> red; drop the published-row guard -> the owner's Arabic is
 * replaced and red.
 */

use App\Models\Product;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

function lt3sMigration(): object
{
    return require database_path('migrations/2027_12_31_230100_arabic_short_descriptions_31_october.php');
}

it('carries an Arabic short description for the 23 products whose short description stayed English, and nothing else', function () {
    $data = (new ReflectionClassConstant(lt3sMigration(), 'PRODUCTS'))->getValue();
    expect($data)->toHaveCount(23)->toHaveKey('frankly-retinol-0-1-cream');
    foreach ($data as $slug => [$name, $short, $desc]) {
        expect($name)->toBeNull($slug)->and($desc)->toBeNull($slug)
            ->and(preg_match('/\p{Arabic}/u', (string) $short))->toBe(1, $slug)
            ->and(preg_match('/\b(the|and|with|your|skin)\b/i', strip_tags((string) $short)))->toBe(0, $slug);
    }
    // Not a slug 2.60.631 already gave a short description.
    $before = (new ReflectionClassConstant(require database_path('migrations/2027_12_31_230000_arabic_new_products_31_october.php'), 'PRODUCTS'))->getValue();
    foreach ($before as $slug => $row) {
        if ($row[1] !== null) {
            expect($data)->not->toHaveKey($slug);
        }
    }
});

it('puts the Arabic beside the price and in the meta description on /ar, and leaves Arabic the owner typed', function () {
    ArabicShop::on();
    $p = Product::create(['slug' => 'frankly-retinol-0-1-cream', 'name' => 'FRANKLY - Retinol 0.1% Cream', 'status' => 'publish', 'is_visible' => true,
        'price' => 11900, 'stock_status' => 'instock', 'short_description' => 'This vegan and cruelty-free cream is enriched with retinol.']);
    $owned = Product::create(['slug' => 'round-lab-pine-calming-cica-pad', 'name' => 'ROUND LAB - Pine Calming Cica Pad', 'status' => 'publish', 'is_visible' => true,
        'price' => 9000, 'stock_status' => 'instock', 'short_description' => 'English short']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

    lt3sMigration()->up();
    TranslationStore::flush();

    $row = DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'short_description'])->first();
    expect($row->status)->toBe('published')
        ->and($row->source_hash)->toBe(sha1('This vegan and cruelty-free cream is enriched with retinol.'))
        ->and(DB::table('translations')->where(['item_id' => $owned->id, 'field' => 'short_description'])->value('value'))->toBe('وصف كتبه المالك');

    $html = test()->get('/ar/product/frankly-retinol-0-1-cream/')->assertOk()->getContent();
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    expect($html)->not->toContain('This vegan and cruelty-free cream')
        ->and(html_entity_decode($d[1]))->toContain('هذا الكريم النباتي');

    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value'])->toArray();
    lt3sMigration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value'])->toArray())->toEqual($snapshot);
});
