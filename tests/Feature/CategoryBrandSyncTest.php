<?php

declare(strict_types=1);

/*
 * The owner, 1 November 2026: "We have Medicube category and also brand,
 * please equal these both, both should have same product like sync."
 *
 * What it looked like on the shop: /brands/medicube/ listed products the
 * /collections/medicube/ page did not, and two products in the collection had
 * no brand so the brand page missed them.
 *
 * MUTATION: drop the INSERT ... SELECT in sync() -> the brand's products stay
 * out of the category (red); drop the product saved() hook -> a new Medicube
 * product stays out until the daily run (red); unlink products of another
 * brand -> the hand-placed one disappears (red).
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CategoryBrandSync;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => CategoryBrandSync::forgetReady());

function cbsBrand(string $slug, string $name): int
{
    // The seeded shop may already have the brand: use it, with no products of its own.
    $id = DB::table('brands')->where('slug', $slug)->value('id')
        ?? DB::table('brands')->insertGetId(['slug' => $slug, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('products')->where('brand_id', $id)->update(['brand_id' => null]);

    return (int) $id;
}

function cbsCat(string $slug): int
{
    $id = DB::table('categories')->where('slug', $slug)->value('id')
        ?? DB::table('categories')->insertGetId(['slug' => $slug, 'name' => ucfirst($slug), 'path' => $slug, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('category_product')->where('category_id', $id)->delete();
    DB::table('categories')->where('id', $id)->update(['mirror_brand_id' => null]);

    return (int) $id;
}

function cbsProd(string $slug, ?int $brand): int
{
    return (int) DB::table('products')->insertGetId(['slug' => $slug, 'name' => $slug, 'type' => 'simple', 'brand_id' => $brand,
        'status' => 'publish', 'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()]);
}

function cbsIn(int $cat): array
{
    return DB::table('category_product')->where('category_id', $cat)->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->all();
}

it('links Medicube to Medicube on apply: the brand\'s products join the collection, unbranded ones there get the brand, nothing is taken out', function () {
    $medicube = cbsBrand('medicube', 'Medicube');
    $anua = cbsBrand('anua', 'Anua');
    $cat = cbsCat('medicube');
    $a = cbsProd('medicube-a', $medicube);          // brand only -> joins the collection
    $b = cbsProd('medicube-b', $medicube);          // both already, hand position 2
    $c = cbsProd('age-r-booster-pink', null);       // collection only, no brand -> gets Medicube
    $d = cbsProd('anua-toner', $anua);              // put in the collection by hand -> stays
    DB::table('category_product')->insert([['category_id' => $cat, 'product_id' => $b, 'category_position' => 2], ['category_id' => $cat, 'product_id' => $c, 'category_position' => null], ['category_id' => $cat, 'product_id' => $d, 'category_position' => null]]);

    (require database_path('migrations/2027_12_31_240000_category_mirror_brand.php'))->up();
    CategoryBrandSync::forgetReady();

    expect(DB::table('categories')->where('id', $cat)->value('mirror_brand_id'))->toBe($medicube)
        ->and(cbsIn($cat))->toBe([$a, $b, $c, $d])
        ->and((int) DB::table('products')->where('id', $c)->value('brand_id'))->toBe($medicube)
        ->and((int) DB::table('products')->where('id', $d)->value('brand_id'))->toBe($anua)
        ->and(DB::table('category_product')->where(['category_id' => $cat, 'product_id' => $b])->value('category_position'))->toBe(2);

    // Level now: a second run adds nothing.
    expect(CategoryBrandSync::sync())->toBe(['linked' => 0, 'branded' => 0, 'other_brand' => 1]);
});

it('takes in a product the moment it is created with, or moved to, the mirrored brand', function () {
    $medicube = cbsBrand('medicube', 'Medicube');
    $other = cbsBrand('cosrx', 'COSRX');
    $cat = cbsCat('medicube');
    DB::table('categories')->where('id', $cat)->update(['mirror_brand_id' => $medicube]);

    $new = Product::query()->create(['slug' => 'medicube-new', 'name' => 'medicube - New', 'brand_id' => $medicube, 'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock']);
    $moved = Product::query()->create(['slug' => 'was-cosrx', 'name' => 'Was COSRX', 'brand_id' => $other, 'status' => 'publish', 'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock']);
    expect(cbsIn($cat))->toBe([(int) $new->id]);

    $moved->update(['brand_id' => $medicube]);
    expect(cbsIn($cat))->toBe([(int) $new->id, (int) $moved->id]);
});

it('saves "Same products as brand" from the category editor, brings it level, and refuses a brand that does not exist', function () {
    $medicube = cbsBrand('medicube', 'Medicube');
    $cat = cbsCat('medicube');
    $p = cbsProd('medicube-x', $medicube);
    $owner = AdminUser::create(['name' => 'O', 'email' => 'cbs-' . uniqid() . '@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);

    test()->actingAs($owner, 'admin')->putJson('/admin-api/categories/' . $cat, ['name' => 'Medicube', 'slug' => 'medicube', 'mirror_brand_id' => 999999])->assertStatus(422);
    $r = test()->actingAs($owner, 'admin')->putJson('/admin-api/categories/' . $cat, ['name' => 'Medicube', 'slug' => 'medicube', 'mirror_brand_id' => $medicube])->assertOk();
    expect($r->json('brand_sync.linked'))->toBe(1)->and(cbsIn($cat))->toBe([$p]);

    // The editor gets it back to show the choice.
    $list = test()->actingAs($owner, 'admin')->getJson('/admin-api/categories')->assertOk()->json();
    $row = collect($list['categories'] ?? $list)->firstWhere('id', $cat);
    expect((int) $row['mirror_brand_id'])->toBe($medicube);

    // A save without the key (the Catalog tab shares this endpoint) leaves the link alone; null clears it.
    test()->actingAs($owner, 'admin')->putJson('/admin-api/categories/' . $cat, ['name' => 'Medicube', 'slug' => 'medicube'])->assertOk();
    expect((int) DB::table('categories')->where('id', $cat)->value('mirror_brand_id'))->toBe($medicube);
    test()->actingAs($owner, 'admin')->putJson('/admin-api/categories/' . $cat, ['name' => 'Medicube', 'slug' => 'medicube', 'mirror_brand_id' => null])->assertOk();
    expect(DB::table('categories')->where('id', $cat)->value('mirror_brand_id'))->toBeNull();
});

it('costs the same few statements for 3 products or 40', function () {
    $medicube = cbsBrand('medicube', 'Medicube');
    $cat = cbsCat('medicube');
    DB::table('categories')->where('id', $cat)->update(['mirror_brand_id' => $medicube]);
    $count = function (int $n) use ($medicube): int {
        DB::table('category_product')->delete();
        DB::table('products')->delete();
        for ($i = 0; $i < $n; $i++) {
            cbsProd('m-' . $n . '-' . $i, $medicube);
        }
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        CategoryBrandSync::sync();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $q;
    };
    CategoryBrandSync::ready(); // the one-time column check is not per run
    expect($count(40))->toBe($count(3));
});

it('puts the picker in the category editor and runs once a day', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/category-tree-screen.blade.php'));
    expect(substr_count($screen, '<select id="ct-mirror" disabled>'))->toBe(1)
        ->and($screen)->toContain("if (mirror && !mirror.disabled) payload.mirror_brand_id")
        ->and((string) file_get_contents(base_path('routes/console.php')))->toContain("->name('kbb:category-brand-sync')");
});
