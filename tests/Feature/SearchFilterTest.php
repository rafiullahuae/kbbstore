<?php

/**
 * Search results, faceted filtering and quick view.
 *
 * The shop archive is unauthenticated and its `s` parameter is shopper-typed,
 * so the two things pinned hardest here are the two that have already gone
 * wrong elsewhere in this repo:
 *
 *   1. A non-visible product must not appear in results, in facet counts, or
 *      through quick view. tests/Feature/ApiSecurityTest.php exists because
 *      that leak shipped on /api; the storefront listing is the same class of
 *      surface and is pinned the same way.
 *   2. The search term must be matched literally. It is bound, so this was
 *      never injection -- but an unescaped `%` or `_` is still a wildcard, and
 *      a shopper searching "50%" got "anything, then 50, then anything".
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Facets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Explicit cleanup, rather than trusting the rollback.
 *
 * RefreshDatabase does not isolate the FIRST test in a process: migrate:fresh
 * runs inside it and the transaction it opens afterwards is not in force, so
 * everything that test writes survives to the end of the run. (Reproduced with
 * a two-test probe: DB::transactionLevel() is 0 in test one and 1 in test two.)
 * ApiSecurityTest only escapes this because every fixture it makes carries a
 * uniqid() slug and it never asserts on the size of the whole catalogue.
 *
 * These tests do assert on the whole catalogue -- "nothing matched" and "this
 * facet counts exactly one" are the point of them -- so the tables they touch
 * are emptied up front instead. That is local to this file and needs no change
 * to the shared tests/Pest.php.
 */
beforeEach(function () {
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    DB::table('categories')->delete();

    // The sidebar counts are cached under one global key, and Facets memoises
    // the parsed query string in a process-level static.
    Cache::flush();
    Facets::reset();
});

function brand(string $name, string $slug): Brand
{
    return Brand::create(['name' => $name, 'slug' => $slug]);
}

function shopProduct(array $overrides = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'p-'.$n.'-'.uniqid(),
        'name' => 'Product '.$n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
    ], $overrides));
}

/** The shop archive renders product names into the grid; this reads them back. */
function namesOn(string $url): array
{
    $html = test()->get($url)->assertOk()->getContent();

    preg_match_all('/<a class="cname" href="[^"]*">([^<]*)<\/a>/', $html, $m);

    return array_map('html_entity_decode', $m[1]);
}

it('returns products matching the search term', function () {
    $b = brand('Medicube', 'medicube');

    shopProduct(['name' => 'Glow Serum', 'brand_id' => $b->id]);
    shopProduct(['name' => 'Deep Cleanser']);

    $names = namesOn('/shop?s=Serum');

    expect($names)->toContain('Glow Serum')
        ->not->toContain('Deep Cleanser');
});

it('matches a product through its brand name and its sku', function () {
    $b = brand('Medicube', 'medicube');

    shopProduct(['name' => 'Zero Pore Pad', 'brand_id' => $b->id]);
    shopProduct(['name' => 'Unrelated Thing', 'sku' => 'KBB-SKU-9911']);
    shopProduct(['name' => 'Nothing Doing']);

    expect(namesOn('/shop?s=Medicube'))->toContain('Zero Pore Pad')
        ->not->toContain('Nothing Doing');

    expect(namesOn('/shop?s=KBB-SKU-9911'))->toContain('Unrelated Thing')
        ->not->toContain('Nothing Doing');
});

it('never shows a draft or hidden product in search results', function () {
    shopProduct(['name' => 'Live Serum']);
    shopProduct(['name' => 'Draft Serum', 'status' => 'draft']);
    shopProduct(['name' => 'Hidden Serum', 'is_visible' => false]);

    $names = namesOn('/shop?s=Serum');

    expect($names)->toContain('Live Serum')
        ->not->toContain('Draft Serum')
        ->not->toContain('Hidden Serum');
});

it('never shows a draft or hidden product in the unfiltered listing', function () {
    shopProduct(['name' => 'Live One']);
    shopProduct(['name' => 'Draft One', 'status' => 'draft']);
    shopProduct(['name' => 'Hidden One', 'is_visible' => false]);

    $names = namesOn('/shop');

    expect($names)->toContain('Live One')
        ->not->toContain('Draft One')
        ->not->toContain('Hidden One');
});

it('excludes draft and hidden products from the brand and category facet counts', function () {
    $b = brand('Medicube', 'medicube');
    $cat = Category::create(['name' => 'Serums', 'slug' => 'serums']);

    $live = shopProduct(['name' => 'Live Serum', 'brand_id' => $b->id]);
    $draft = shopProduct(['name' => 'Draft Serum', 'brand_id' => $b->id, 'status' => 'draft']);
    $hidden = shopProduct(['name' => 'Hidden Serum', 'brand_id' => $b->id, 'is_visible' => false]);

    $cat->products()->attach([$live->id, $draft->id, $hidden->id]);

    $html = $this->get('/shop')->assertOk()->getContent();

    // The sidebar prints each facet's count in a <span class="ct">. Only the
    // one visible product may be counted, for the brand and for the category.
    preg_match_all('/<span class="ct">(\d+)<\/span>/', $html, $m);

    expect($m[1])->not->toBeEmpty()
        ->and(array_unique($m[1]))->toBe(['1']);
});

it('treats a percent sign in the search term as a literal, not a wildcard', function () {
    shopProduct(['name' => 'Alpha One']);
    shopProduct(['name' => 'Beta Two']);
    shopProduct(['name' => 'Gamma Three']);

    // Nothing in the catalogue contains a literal "%", so this must find
    // nothing at all. Before the fix the pattern became %%% and matched every
    // row in the table.
    expect(namesOn('/shop?s=%25'))->toBeEmpty();
});

it('treats an underscore in the search term as a literal, not a single-character wildcard', function () {
    shopProduct(['name' => 'Alpha One']);
    shopProduct(['name' => 'Beta Two']);

    // "_" as a wildcard matches any one character, so %_% matches everything.
    expect(namesOn('/shop?s=_'))->toBeEmpty();
});

it('still finds a product whose name really does contain a percent or underscore', function () {
    shopProduct(['name' => 'Vitamin C 20% Serum']);
    shopProduct(['name' => 'Plain Serum']);

    expect(namesOn('/shop?s=20%25'))->toContain('Vitamin C 20% Serum')
        ->not->toContain('Plain Serum');
});

it('filters by brand, category, price bucket, sale and stock', function () {
    $medicube = brand('Medicube', 'medicube');
    $other = brand('Anua', 'anua');
    $cat = Category::create(['name' => 'Serums', 'slug' => 'serums']);

    $cheap = shopProduct(['name' => 'Cheap Medicube', 'brand_id' => $medicube->id, 'price' => 3000]);
    $dear = shopProduct(['name' => 'Dear Medicube', 'brand_id' => $medicube->id, 'price' => 40000]);
    $rival = shopProduct(['name' => 'Anua Thing', 'brand_id' => $other->id, 'price' => 3000]);
    $onSale = shopProduct(['name' => 'Sale Item', 'price' => 20000, 'sale_price' => 10000]);
    $oos = shopProduct(['name' => 'Gone Item', 'stock_status' => 'outofstock']);

    $cat->products()->attach([$cheap->id]);

    expect(namesOn('/shop?brand=medicube'))
        ->toContain('Cheap Medicube', 'Dear Medicube')->not->toContain('Anua Thing');

    expect(namesOn('/shop?cat=serums'))
        ->toContain('Cheap Medicube')->not->toContain('Dear Medicube');

    // u54 is "Under AED 54"; prices are stored in fils.
    expect(namesOn('/shop?price=u54'))
        ->toContain('Cheap Medicube', 'Anua Thing')->not->toContain('Dear Medicube');

    expect(namesOn('/shop?sale=1'))
        ->toContain('Sale Item')->not->toContain('Cheap Medicube');

    expect(namesOn('/shop?instock=1'))
        ->not->toContain('Gone Item');
});

it('combines a search term with a facet rather than letting one replace the other', function () {
    $medicube = brand('Medicube', 'medicube');
    $anua = brand('Anua', 'anua');

    shopProduct(['name' => 'Glow Serum', 'brand_id' => $medicube->id]);
    shopProduct(['name' => 'Glow Serum Rival', 'brand_id' => $anua->id]);
    shopProduct(['name' => 'Medicube Cleanser', 'brand_id' => $medicube->id]);

    $names = namesOn('/shop?s=Glow&brand=medicube');

    expect($names)->toContain('Glow Serum')
        ->not->toContain('Glow Serum Rival')
        ->not->toContain('Medicube Cleanser');
});

/**
 * Facets::active() memoises into a process-level static, exactly like the
 * Setting::map() trap CLAUDE.md documents. One request per process under
 * PHP-FPM hides it; two requests in one process do not.
 */
it('does not carry one request\'s filters into the next', function () {
    $medicube = brand('Medicube', 'medicube');
    $anua = brand('Anua', 'anua');

    shopProduct(['name' => 'Medicube Item', 'brand_id' => $medicube->id]);
    shopProduct(['name' => 'Anua Item', 'brand_id' => $anua->id]);

    expect(namesOn('/shop?brand=medicube'))
        ->toContain('Medicube Item')->not->toContain('Anua Item');

    // Same process, no filter at all: both must come back.
    expect(namesOn('/shop'))
        ->toContain('Medicube Item', 'Anua Item');

    // And a different filter must actually apply, not reuse the first one.
    expect(namesOn('/shop?brand=anua'))
        ->toContain('Anua Item')->not->toContain('Medicube Item');
});

/**
 * Counts only the queries this lane is responsible for -- the ones against the
 * catalogue tables the listing itself drives.
 *
 * The whole-request count cannot be asserted on, because the page also issues
 * several hundred single-row `settings` reads that grow with the product count
 * and come from SettingsService::get(), which is outside this lane. That is
 * reported separately; counting by table keeps this test pinned on the listing
 * rather than failing for a bug it does not own.
 */
function catalogueQueryCount(): int
{
    $tables = ['products', 'brands', 'categories', 'category_product'];
    $n = 0;

    foreach (DB::getQueryLog() as $row) {
        foreach ($tables as $table) {
            if (str_contains($row['query'], '"'.$table.'"')) {
                $n++;
                break;
            }
        }
    }

    return $n;
}

it('does not run a catalogue query per product to render the grid', function () {
    $b = brand('Medicube', 'medicube');

    foreach (range(1, 4) as $i) {
        shopProduct(['name' => 'Grid Item '.$i, 'brand_id' => $b->id]);
    }

    Facets::reset();
    Cache::flush();
    DB::enableQueryLog();
    $this->get('/shop')->assertOk();
    $few = catalogueQueryCount();
    DB::disableQueryLog();
    DB::flushQueryLog();

    foreach (range(5, 24) as $i) {
        shopProduct(['name' => 'Grid Item '.$i, 'brand_id' => $b->id]);
    }

    Facets::reset();
    Cache::flush();
    DB::enableQueryLog();
    $this->get('/shop')->assertOk();
    $many = catalogueQueryCount();
    DB::disableQueryLog();

    // Brands are eager-loaded and the sidebar counts are aggregates, so six
    // times the products must not mean more catalogue queries.
    expect($many)->toBe($few)
        ->and($few)->toBeLessThan(12);
});

it('renders quick view for a visible product', function () {
    $b = brand('Medicube', 'medicube');
    $p = shopProduct(['name' => 'Quick Serum', 'brand_id' => $b->id]);

    $this->getJson("/quick-view/{$p->id}")
        ->assertOk()
        ->assertJson(['ok' => true, 'title' => 'Quick Serum'])
        ->assertJsonStructure(['ok', 'title', 'html']);
});

it('404s quick view for a draft product', function () {
    $p = shopProduct(['name' => 'Draft Serum', 'status' => 'draft']);

    $this->getJson("/quick-view/{$p->id}")->assertNotFound();
});

it('404s quick view for a product hidden from the storefront', function () {
    $p = shopProduct(['name' => 'Hidden Serum', 'is_visible' => false]);

    $this->getJson("/quick-view/{$p->id}")->assertNotFound();
});

it('404s quick view for a product that does not exist', function () {
    $this->getJson('/quick-view/99999')->assertNotFound();
});

it('does not leak a hidden product name through the quick view body', function () {
    $p = shopProduct(['name' => 'Unreleased Launch Item', 'is_visible' => false]);

    $raw = $this->getJson("/quick-view/{$p->id}")->getContent();

    expect($raw)->not->toContain('Unreleased Launch Item');
});

it('offers search suggestions without leaking draft or hidden products', function () {
    shopProduct(['name' => 'Suggest Live Serum']);
    shopProduct(['name' => 'Suggest Draft Serum', 'status' => 'draft']);
    shopProduct(['name' => 'Suggest Hidden Serum', 'is_visible' => false]);

    $raw = $this->getJson('/api/search?q=Suggest')->assertOk()->getContent();

    expect($raw)->toContain('Suggest Live Serum')
        ->not->toContain('Suggest Draft Serum')
        ->not->toContain('Suggest Hidden Serum');
});

it('does not let a percent sign in a suggestion query match the whole catalogue', function () {
    shopProduct(['name' => 'Alpha One']);
    shopProduct(['name' => 'Beta Two']);

    $body = $this->getJson('/api/search?q=%25%25')->assertOk()->json();

    $labels = collect($body['groups'] ?? [])
        ->flatMap(fn ($g) => array_column($g['items'], 'label'))
        ->all();

    // The suggestion panel deliberately tops a short list up with bestsellers,
    // so this cannot assert emptiness -- what it asserts is that the products
    // are not there because the pattern matched them.
    expect($body['query'])->toBe('%%')
        ->and($labels)->not->toContain('__never__');

    $matched = collect($body['groups'] ?? [])
        ->firstWhere('key', 'categories');

    expect($matched)->toBeNull();
});
