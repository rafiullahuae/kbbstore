<?php

declare(strict_types=1);

/*
 * The owner, 26 October 2026: "i don't use any key, so translate everything in
 * the patch, and also assign the products to shop by skin in the patch".
 *
 * What a defect looks like on the shop: a product page on /ar still showing
 * English (the name, the text beside the price, or the description tab); the
 * owner's own Arabic replaced by the package; a Shop by Skin collection he
 * ordered by hand losing a product or its order; or the same product listed
 * twice in a collection after the package is applied again.
 *
 * MUTATION, RUN: drop the published-row guard in put() -> the second case is
 * red (the owner's Arabic is replaced). Dropping the `$have` check in
 * concerns() stays green: category_product's unique key plus insertOrIgnore
 * already refuse a duplicate, so the check only saves the insert. Deleting
 * links not listed, or writing a position, turns the third case red.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const LT2_FILE = 'migrations/2027_12_25_100000_arabic_and_skin_concerns_26_october.php';

function lt2Migration(): object
{
    return require database_path(LT2_FILE);
}

function lt2Const(string $name): array
{
    return (new ReflectionClassConstant(lt2Migration(), $name))->getValue();
}

function lt2Cat(string $slug): int
{
    return (int) DB::table('categories')->insertGetId(['slug' => $slug, 'name' => $slug, 'path' => $slug,
        'created_at' => now(), 'updated_at' => now()]);
}

function lt2Prod(string $slug, array $extra = []): int
{
    return (int) DB::table('products')->insertGetId($extra + ['slug' => $slug, 'name' => $slug, 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()]);
}

it('carries Arabic for all 32 listings: 12 whole products and 20 short descriptions, Arabic in every field it sets', function () {
    $data = lt2Const('PRODUCTS');
    expect($data)->toHaveCount(32);

    $whole = 0;
    foreach ($data as $slug => [$name, $short, $desc]) {
        expect($short)->not->toBeNull($slug)
            ->and(preg_match('/\p{Arabic}/u', (string) $short))->toBe(1, $slug);
        if ($name !== null) {
            $whole++;
            expect(preg_match('/^\p{Arabic}.* - /u', $name))->toBe(1, $slug)
                ->and(preg_match('/\p{Arabic}/u', (string) $desc))->toBe(1, $slug);
        }
        // An English sentence left behind is the defect this package fixes.
        expect(preg_match('/\b(the|and|with|your|skin)\b/i', strip_tags((string) $short.' '.$desc)))->toBe(0, $slug);
    }
    expect($whole)->toBe(12);

    // Every concern named is one of the seven Shop by Skin Concern collections.
    $seven = ['acne-blemish-care', 'pigmentation', 'anti-aging', 'hydration-glow', 'pore-care', 'oily-skin', 'redness-sensitive-skin'];
    foreach (lt2Const('CONCERNS') as $slug => $concerns) {
        expect(array_diff($concerns, $seven))->toBe([], $slug);
    }
});

it('publishes the Arabic on the product found by slug, never over Arabic the owner typed, skips missing slugs, and shows it on /ar', function () {
    ArabicShop::on();
    $p = Product::create(['slug' => 'arencia-eraser-soothing-cream', 'name' => 'Arencia - Eraser Soothing Cream', 'status' => 'publish', 'is_visible' => true,
        'price' => 11800, 'stock_status' => 'instock', 'short_description' => '<div>English blurb</div>', 'description' => '<p>English</p>']);
    $owned = Product::create(['slug' => 'tirtir-mask-fit-make-up-fixer', 'name' => 'TIRTIR - Mask Fit Make Up Fixer', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'short_description' => 'English short']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    // A draft (unpublished) row IS replaced: only published Arabic is the owner's.
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'description',
        'value' => 'مسودة', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    $brand = Brand::create(['slug' => 'sungboon-editor-lt2', 'name' => 'SUNGBOON EDITOR']);
    $rowsBefore = DB::table('translations')->count();

    lt2Migration()->up();
    TranslationStore::flush();

    [$name, $short, $desc] = lt2Const('PRODUCTS')['arencia-eraser-soothing-cream'];
    $get = fn (int $id, string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => $f])->first();

    expect($get($p->id, 'name')->value)->toBe($name)
        ->and($get($p->id, 'name')->status)->toBe('published')
        ->and($get($p->id, 'name')->source_hash)->toBe(sha1('Arencia - Eraser Soothing Cream'))
        ->and($get($p->id, 'short_description')->value)->toBe($short)
        ->and($get($p->id, 'description')->value)->toBe($desc)
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك')
        ->and(DB::table('translations')->where(['group' => 'brands', 'item_id' => $brand->id, 'field' => 'name'])->value('value'))->toBe('سونغبون إديتور')
        // 3 for Arencia (one of them an update of the draft) + 1 brand; the 30 slugs this shop lacks write nothing.
        ->and(DB::table('translations')->count())->toBe($rowsBefore + 3);

    $html = test()->get('/ar/product/arencia-eraser-soothing-cream/')->assertOk()->getContent();
    expect($html)->toContain(e($name))->and($html)->not->toContain('English blurb');

    // Run twice: nothing changes, nothing duplicates.
    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value', 'status'])->toArray();
    lt2Migration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value', 'status'])->toArray())->toEqual($snapshot);
});

it('adds the Shop by Skin links, keeps the owner\'s own links and order, skips what the shop lacks, and adds nothing the second time', function () {
    $red = lt2Cat('redness-sensitive-skin');
    $acne = lt2Cat('acne-blemish-care');
    $anti = lt2Cat('anti-aging');
    // pore-care is absent here: it must not be created.
    $cats = DB::table('categories')->count();

    $arencia = lt2Prod('arencia-eraser-soothing-cream');
    $abib = lt2Prod('abib-retinal-eye-serum-lifting-roller');
    $jumiso = lt2Prod('jumiso-pore-clearing-cleansing-oil'); // only pore-care, which this shop lacks
    $mine = lt2Prod('owners-own-pick');

    // The owner already put Arencia in Redness at position 3, and his own pick in Anti-aging.
    DB::table('category_product')->insert(['category_id' => $red, 'product_id' => $arencia, 'category_position' => 3]);
    DB::table('category_product')->insert(['category_id' => $anti, 'product_id' => $mine, 'category_position' => 1]);

    lt2Migration()->up();

    $link = fn (int $c, int $p) => DB::table('category_product')->where(['category_id' => $c, 'product_id' => $p]);

    expect($link($red, $arencia)->count())->toBe(1)
        ->and($link($red, $arencia)->value('category_position'))->toBe(3)
        ->and($link($acne, $arencia)->count())->toBe(1)
        ->and($link($acne, $arencia)->value('category_position'))->toBeNull()
        ->and($link($anti, $abib)->count())->toBe(1)
        ->and($link($anti, $mine)->value('category_position'))->toBe(1)
        ->and($link($red, $abib)->count())->toBe(0)
        ->and(DB::table('category_product')->where('product_id', $jumiso)->count())->toBe(0)
        ->and(DB::table('categories')->count())->toBe($cats);

    $total = DB::table('category_product')->count();
    lt2Migration()->up();
    expect(DB::table('category_product')->count())->toBe($total);
});

it('changes nothing on a shop without the products', function () {
    $tr = DB::table('translations')->count();
    $cp = DB::table('category_product')->count();
    lt2Migration()->up();
    expect(DB::table('translations')->count())->toBe($tr)
        ->and(DB::table('category_product')->count())->toBe($cp);
});
