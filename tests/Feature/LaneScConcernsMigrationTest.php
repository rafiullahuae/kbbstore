<?php

declare(strict_types=1);

/**
 * Lane SC: recent products into Shop by Skin Concern, adding only.
 *
 * What a defect would look like on the shop: a concern collection the owner
 * ordered by hand losing its order or a product (removal / reorder), the same
 * product listed twice in a collection after the package is applied again
 * (duplicate link), or the migration dying on a server whose code is older
 * than the package (an App\ class it cannot find).
 *
 * MUTATIONS, RUN: drop the `$have` check -- "does not duplicate" is red on the
 * unique key's absence in SQLite; give new links category_position 0 -- the
 * "keeps its order" case is red; delete links not in ADD -- "never removes" red.
 */

use Illuminate\Support\Facades\DB;

function scMigration(): object
{
    return require database_path('migrations/2027_11_07_200000_lane_sc_concerns_recent_products.php');
}

function scCat(string $slug): int
{
    return (int) DB::table('categories')->insertGetId(['slug' => $slug, 'name' => $slug, 'path' => $slug,
        'created_at' => now(), 'updated_at' => now()]);
}

function scProd(string $slug): int
{
    return (int) DB::table('products')->insertGetId(['slug' => $slug, 'name' => $slug, 'type' => 'simple',
        'status' => 'publish', 'price' => 5000, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()]);
}

it('adds the listed concerns, never duplicates, never removes and keeps the hand order', function () {
    $anti = scCat('anti-aging');
    $hyd = scCat('hydration-glow');
    $pig = scCat('pigmentation');
    // A concern this shop lacks (acne-blemish-care) must not be created.
    $cats = DB::table('categories')->count();

    $retinal = scProd('purito-seoul-txa-6-niacinamide-10-retinal-serum');
    $azelaic = scProd('purito-seoul-azelaic-acid-10-kojic-tea-tree-serum');
    $mine = scProd('owners-own-pick');

    // The owner already placed the retinal serum in Pigmentation, position 1, and his own pick in Anti-aging.
    DB::table('category_product')->insert(['category_id' => $pig, 'product_id' => $retinal, 'category_position' => 1]);
    DB::table('category_product')->insert(['category_id' => $anti, 'product_id' => $mine, 'category_position' => 2]);

    scMigration()->up();

    $link = fn (int $c, int $p) => DB::table('category_product')->where(['category_id' => $c, 'product_id' => $p]);

    expect($link($anti, $retinal)->count())->toBe(1)
        ->and($link($anti, $retinal)->value('category_position'))->toBeNull()
        ->and($link($pig, $retinal)->count())->toBe(1)
        ->and($link($pig, $retinal)->value('category_position'))->toBe(1)
        ->and($link($anti, $mine)->value('category_position'))->toBe(2)
        ->and($link($pig, $azelaic)->count())->toBe(1)
        // Not listed for hydration.
        ->and($link($hyd, $retinal)->count())->toBe(0)
        ->and(DB::table('categories')->count())->toBe($cats);

    $total = DB::table('category_product')->count();
    scMigration()->up();
    expect(DB::table('category_product')->count())->toBe($total);
});

it('changes nothing on a shop without the concerns or the products', function () {
    $before = DB::table('category_product')->count();
    scMigration()->up();
    expect(DB::table('category_product')->count())->toBe($before);
});

it('names no App class, so it runs against any server code', function () {
    $src = file_get_contents(database_path('migrations/2027_11_07_200000_lane_sc_concerns_recent_products.php'));
    $names = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
            $names[] = ltrim($t[1], '\\');
        }
    }
    expect(array_filter($names, fn ($n) => str_starts_with($n, 'App\\') || $n === 'App'))->toBe([]);
});

it('lists only concern slugs the shop uses', function () {
    $c = (new ReflectionClass(scMigration()))->getReflectionConstant('ADD')->getValue();
    $allowed = ['pigmentation', 'pore-care', 'anti-aging', 'hydration-glow', 'acne-blemish-care', 'redness-sensitive-skin', 'oily-skin'];
    foreach ($c as $slugs) {
        expect(array_diff($slugs, $allowed))->toBe([]);
    }
});
