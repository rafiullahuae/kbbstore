<?php

declare(strict_types=1);

/**
 * Shop by Concern: the owner's approved lists into the seven concern
 * categories, adding only.                                       (2.60.506)
 *
 * "proceed to apply this on live site, give me the patch, without disturbing
 * anything else." What disturbing would look like on the shop: a hand-ordered
 * Pore Care losing its order, a product dropped out of a category it was in,
 * or Acne Patches (a real category, slug acne-patches) mistaken for the acne
 * concern. Each is pinned below.
 *
 * MUTATIONS, RUN: give new links category_position 0 -- the "keeps its order"
 * case red; match categories by `LIKE '%acne%'` -- the acne-patches case red.
 */

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

function sbcRun(): void
{
    $m = require database_path('migrations/2027_11_01_100000_shop_by_concern_products.php');
    ob_start();
    $m->up();
    ob_end_clean();
}

/** Every listed product, as the live shop has them, so the catalogue guard lets it run. */
function sbcCatalogue(): void
{
    $m = require database_path('migrations/2027_11_01_100000_shop_by_concern_products.php');
    $c = (new ReflectionClass($m))->getReflectionConstant('CONCERNS')->getValue();
    $slugs = array_values(array_unique(array_merge(...array_column($c, 'products'))));
    $have = DB::table('products')->whereIn('slug', $slugs)->pluck('slug')->all();
    $now = now();
    $rows = [];
    foreach (array_diff($slugs, $have) as $slug) {
        $rows[] = ['slug' => $slug, 'name' => $slug, 'type' => 'simple', 'status' => 'publish', 'price' => 5000,
            'stock_status' => 'instock', 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }
}

function sbcProduct(string $slug): Product
{
    return Product::create([
        'slug' => $slug, 'name' => $slug, 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);
}

it('adds the listed products, keeps the hand-made order and every existing link, and creates only the missing concerns', function () {
    $pore = Category::create(['slug' => 'pore-care', 'name' => 'Pore Care', 'path' => 'pore-care']);
    $patches = Category::create(['slug' => 'acne-patches', 'name' => 'Acne Patches', 'path' => 'acne-patches']);

    // A product the owner placed and ordered by hand, which the list does not name.
    $mine = sbcProduct('owner-own-pick');
    DB::table('category_product')->insert(['category_id' => $pore->id, 'product_id' => $mine->id, 'category_position' => 1]);

    // A product on the Pore Care list, and one on the Acne list.
    $bha = sbcProduct('anua-bha-2-gentle-exfoliating-toner-150ml');
    $acne = sbcProduct('anua-azelaic-10-hyaluron-redness-soothing-pad');

    sbcCatalogue();
    $before = DB::table('categories')->count();

    sbcRun();

    expect(DB::table('category_product')->where(['category_id' => $pore->id, 'product_id' => $mine->id])->value('category_position'))->toBe(1)
        ->and(DB::table('category_product')->where(['category_id' => $pore->id, 'product_id' => $bha->id])->exists())->toBeTrue()
        ->and(DB::table('category_product')->where(['category_id' => $pore->id, 'product_id' => $bha->id])->value('category_position'))->toBeNull()
        // Acne Patches is not the acne concern and gains nothing.
        ->and(DB::table('category_product')->where('category_id', $patches->id)->count())->toBe(0);

    $acneCat = DB::table('categories')->where('slug', 'acne-blemish-care')->first();
    expect($acneCat)->not->toBeNull()
        ->and($acneCat->name)->toBe('Acne & Blemish Care')
        ->and($acneCat->path)->toBe('acne-blemish-care')
        ->and($acneCat->parent_id)->toBeNull()
        ->and(DB::table('category_product')->where(['category_id' => $acneCat->id, 'product_id' => $acne->id])->exists())->toBeTrue()
        // Pore Care existed and was used; the six others were created.
        ->and(DB::table('categories')->count())->toBe($before + 6);

    // Twice is the same as once.
    $links = DB::table('category_product')->count();
    sbcRun();
    expect(DB::table('category_product')->count())->toBe($links)
        ->and(DB::table('categories')->count())->toBe($before + 6);

    // And the new concern page answers.
    $this->get('/collections/acne-blemish-care/')->assertOk()->assertSee('Acne &amp; Blemish Care', false);
});

it('finds an existing concern by its name when its slug is different', function () {
    $oily = Category::create(['slug' => 'oily-skin-care', 'name' => 'Oily Skin', 'path' => 'oily-skin-care']);
    sbcCatalogue();
    $p = Product::where('slug', 'anua-bha-2-gentle-exfoliating-toner-150ml')->firstOrFail();

    sbcRun();

    expect(DB::table('categories')->where('slug', 'like', 'oily-skin%')->count())->toBe(1)
        ->and(DB::table('category_product')->where(['category_id' => $oily->id, 'product_id' => $p->id])->exists())->toBeTrue();
});

it('changes nothing on a shop that is not the live catalogue (a fresh install, the test sample)', function () {
    $before = DB::table('categories')->count();
    $links = DB::table('category_product')->count();
    sbcRun();
    expect(DB::table('categories')->count())->toBe($before)
        ->and(DB::table('category_product')->count())->toBe($links);
});
