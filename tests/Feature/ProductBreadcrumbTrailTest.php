<?php

declare(strict_types=1);

/*
 * The product page breadcrumb follows the same category as "More {category}".
 *                                                                  (Lane BC)
 *
 * The owner said yes to: Home › Skincare › Toner › Product — the product's
 * most specific category, with the categories above it that it is filed
 * under. It used to print `categories->first()`, which has no ORDER BY: a
 * product filed under Skincare and Toner could show "Skincare" above the title
 * while block 2 said "More Toner".
 *
 * MUTATIONS, each run and each red (storage/bc-logs/bc-mut.py):
 *   B1  ProductCategory::trail() — the parent walk removed (`while (false)`)
 *       → "shows the child with its parent before it" red in both insert
 *       orders, and the JSON-LD and Arabic cases: Home / Toner, no Skincare.
 *   B2  ProductController — trail() called without ProductRecs' chosen id →
 *       "agrees with More {category}" red: the stock-settled tie names Pads
 *       above the title and "More Mists" below it.
 *   B3  ProductController::breadcrumbTrail() — only the last category kept
 *       (the old one-category JSON-LD) → "the same path in the
 *       BreadcrumbList" red: Skincare missing from the JSON-LD.
 *   B4  store/product.blade.php — the crumb back to one category
 *       (`categories->take(1)`, the old `first()`) → the parent/child cases,
 *       "agrees" and the JSON-LD case red.
 *   B5  ProductCategory::candidates() — the id sort dropped → "agrees" red:
 *       handed the categories highest id first, the product's category is
 *       Mists, not the stable lowest id, Pads. (SQLite already returns the
 *       relation in id order, so only the reversed relation shows it.)
 *   B6  store/product.blade.php — the crumb's " / " separator changed →
 *       "leaves a product with one category, or none, exactly as it was"
 *       red (and the parent/child cases).
 *   B7  ProductCategory::primary() — the chosen id taken on trust instead of
 *       checked against the candidates → "agrees" red (an id that is not
 *       one of the product's deepest categories names no category at all).
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
use App\Services\ProductRecs;
use App\Support\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function bcProduct(string $name, array $categories, int $sales = 5, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'brand_id' => null,
        'total_sales' => $sales,
    ], $extra));

    foreach ($categories as $c) {
        DB::table('category_product')->insert(['product_id' => $p->id, 'category_id' => $c->id]);
    }

    return $p;
}

/** @return array{0: Category, 1: Category} [Skincare, Toner] */
function bcShelves(): array
{
    $parent = Category::create(['slug' => 'skincare-'.Str::lower(Str::random(4)), 'name' => 'Skincare', 'depth' => 0, 'position' => 0]);
    $child = Category::create(['slug' => 'toner-'.Str::lower(Str::random(4)), 'name' => 'Toner', 'parent_id' => $parent->id, 'depth' => 1, 'position' => 0,
        'path' => $parent->slug.'/']);
    $child->forceFill(['path' => $parent->slug.'/'.$child->slug])->save();
    $parent->forceFill(['path' => $parent->slug])->save();

    return [$parent, $child];
}

/** The visible crumb's links, as [name, href]. */
function bcCrumb(string $html): array
{
    preg_match('#<div class="crumb">(.*?)</div>#s', $html, $m);
    preg_match_all('#<a href="([^"]*)">([^<]*)</a>#', $m[1] ?? '', $links, PREG_SET_ORDER);

    return array_map(fn ($l) => [html_entity_decode($l[2]), $l[1]], $links);
}

/** The BreadcrumbList's items, as [position, name, item]. */
function bcJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $found = null;
    $walk = function ($node) use (&$walk, &$found): void {
        if (! is_array($node)) {
            return;
        }
        if (($node['@type'] ?? null) === 'BreadcrumbList') {
            $found = $node;

            return;
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    foreach ($m[1] as $json) {
        $walk(json_decode($json, true));
    }

    return array_map(fn ($i) => [$i['position'], $i['name'], $i['item'] ?? null], $found['itemListElement'] ?? []);
}

/** The name "More {category}" is about: its tab (the default), or its block. */
function bcRecsCategory(string $html): ?string
{
    if (preg_match('#id="rp-t-category"[^>]*>More ([^<]*)</button>#', $html, $m) === 1
        || preg_match('#<h2 id="rp2-h">More ([^<]*)</h2>#', $html, $m) === 1) {
        return html_entity_decode($m[1]);
    }

    return null;
}

function bcPage(\Tests\TestCase $test, Product $p, string $prefix = ''): string
{
    return $test->get($prefix.'/product/'.$p->slug.'/')->assertOk()->getContent();
}

it('shows the child with its parent before it, whatever order the shelves were filed in', function (bool $parentFirst) {
    [$parent, $child] = bcShelves();
    $p = bcProduct('Two Shelf Toner', $parentFirst ? [$parent, $child] : [$child, $parent]);
    bcProduct('Another Toner', [$child]);

    $html = bcPage($this, $p);

    expect(bcCrumb($html))->toBe([
        ['Home', '/'],
        ['Skincare', '/collections/'.$parent->slug.'/'],
        ['Toner', '/collections/'.$parent->slug.'/'.$child->slug.'/'],
    ])
        ->and($html)->toContain('</a> / Two Shelf Toner</div>');
})->with(['parent row first' => [true], 'child row first' => [false]]);

it('agrees with "More {category}" on every product — tab or block — including a tie it settles by stock', function () {
    [$parent, $child] = bcShelves();
    $pads = Category::create(['slug' => 'pads-'.Str::lower(Str::random(4)), 'name' => 'Pads', 'depth' => 0, 'position' => 0]);
    $mists = Category::create(['slug' => 'mists-'.Str::lower(Str::random(4)), 'name' => 'Mists', 'depth' => 0, 'position' => 0]);
    $brand = Brand::create(['slug' => 'anua-'.Str::lower(Str::random(4)), 'name' => 'Anua']);

    // With a brand that has another product: the category is the second TAB.
    $two = bcProduct('Two Shelf', [$child, $parent], 5, ['brand_id' => $brand->id]);
    bcProduct('Anua Other', [], 5, ['brand_id' => $brand->id]);
    bcProduct('Toner Mate', [$child]);
    // No brand: the category is its own block. Equally deep: Mists has more
    // in stock, so it is Mists though Pads has the lower id. Filed Mists-first.
    $tie = bcProduct('Pad Mist', [$mists, $pads]);
    bcProduct('Pad A', [$pads]);
    foreach (['Mist A', 'Mist B', 'Mist C'] as $n) {
        bcProduct($n, [$mists]);
    }

    foreach ([[$two, 'Toner', 'rp-t-category'], [$tie, 'Mists', 'rp2-h']] as [$p, $name, $where]) {
        $html = bcPage($this, $p);
        $crumb = bcCrumb($html);

        expect(end($crumb)[0])->toBe($name)
            ->and(bcRecsCategory($html))->toBe($name)
            ->and($html)->toContain('id="'.$where.'"');
    }

    // The category switched off: the crumb still names one shelf, the stable
    // one — the lowest id, whatever order the relation hands the rows over in.
    app(AlsoLikeSettings::class)->save(['cat_on' => false]);
    ProductRecs::forget((int) $tie->id);
    $crumb = bcCrumb(bcPage($this, $tie));
    expect(end($crumb)[0])->toBe('Pads');

    $fresh = Product::query()->with('categories:id,name,slug,path,parent_id,depth')->find($tie->id);
    foreach ([$fresh->categories->sortBy('id'), $fresh->categories->sortByDesc('id')] as $order) {
        $fresh->setRelation('categories', $order->values());
        expect(ProductCategory::primary($fresh)->name)->toBe('Pads')
            ->and(ProductCategory::primary($fresh, (int) $mists->id)->name)->toBe('Mists')
            // An id that is not one of the candidates is not taken on trust.
            ->and(ProductCategory::primary($fresh, (int) $child->id)->name)->toBe('Pads');
    }
});

it('prints the same path in the BreadcrumbList as above the title, absolute and in order', function () {
    [$parent, $child] = bcShelves();
    $p = bcProduct('Two Shelf Toner', [$child, $parent]);

    $html = bcPage($this, $p);
    $ld = bcJsonLd($html);
    $crumb = bcCrumb($html);

    expect(array_column($ld, 0))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($ld, 1))->toBe(['Home', 'Shop', 'Skincare', 'Toner', 'Two Shelf Toner']);

    foreach ($ld as [, , $item]) {
        expect($item)->toMatch('#^https?://#');
    }

    // The categories in the JSON-LD are the crumb's, name for name and address for address.
    $ldCats = array_slice($ld, 2, -1);
    $crumbCats = array_slice($crumb, 1);
    expect(array_column($ldCats, 1))->toBe(array_column($crumbCats, 0));
    foreach ($ldCats as $i => [, , $item]) {
        expect(parse_url($item, PHP_URL_PATH))->toBe($crumbCats[$i][1]);
    }
});

it('leaves a product with one category, or none, exactly as it was', function () {
    [, $child] = bcShelves();
    $one = bcProduct('One Shelf Toner', [$child]);
    $none = bcProduct('No Shelf Thing', []);

    // A child filed alone: its parent is not on the product, so the crumb is
    // what it always was — no query is spent to find the parent.
    expect(bcPage($this, $one))->toContain('<div class="crumb"><a href="/">Home</a> / <a href="/collections/'.$child->path.'/">Toner</a> / One Shelf Toner</div>')
        ->and(bcPage($this, $none))->toContain('<div class="crumb"><a href="/">Home</a> / <a href="/shop/">Shop</a> / No Shelf Thing</div>');

    expect(array_column(bcJsonLd(bcPage($this, $one)), 1))->toBe(['Home', 'Shop', 'Toner', 'One Shelf Toner']);
});

it('costs no query: a two-shelf product\'s page asks what a one-shelf product\'s asks', function () {
    [$parent, $child] = bcShelves();
    $one = bcProduct('One Shelf', [$child]);
    $two = bcProduct('Two Shelf', [$child, $parent]);

    $count = function (Product $p): int {
        bcPage($this, $p); // warm this product's own caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        bcPage($this, $p);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count($two))->toBe($count($one));
});

it('works in Arabic, with Arabic addresses in the crumb and absolute ones in the JSON-LD', function () {
    app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
    [$parent, $child] = bcShelves();
    $p = bcProduct('Two Shelf Toner', [$child, $parent]);

    $html = bcPage($this, $p, '/ar');
    $crumb = bcCrumb($html);

    expect(array_column($crumb, 0))->toBe(['Home', 'Skincare', 'Toner'])
        ->and($crumb[1][1])->toStartWith('/ar/')
        ->and($crumb[2][1])->toStartWith('/ar/');

    foreach (bcJsonLd($html) as [$pos, , $item]) {
        expect($item)->toMatch('#^https?://#');
    }
});
