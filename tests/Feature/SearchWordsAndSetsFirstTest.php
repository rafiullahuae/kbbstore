<?php

/**
 * The search box: every word, nothing unrelated, and a set at #1.
 *
 * THE DEFECTS, FROM THE OWNER'S SCREENSHOT (1 October 2026):
 *
 *   1. He typed "medicube booster x2" and the panel listed Anua and Dr.Althea,
 *      "5 found". It read as the search stopping after a few words and keeping
 *      old results. Two causes together: a match was ONE substring of the whole
 *      phrase, so "booster x2" never matched "medicube - AGE-R Booster Pro X2
 *      Pink"; and anything short of five was then topped up "from the
 *      catalogue at large", so zero matches showed five best sellers.
 *   2. "The search is not showing set products at all." The panel keeps the
 *      top few by total_sales and a set has sold little yet, so it never made
 *      the cut. He asked for a set at #1: a search for Anua puts a set that is
 *      Anua's, or has Anua in the box, first -- a different one each search
 *      when there are several, and a switch to turn it off.
 *
 * MUTATIONS, RUN:
 *   - drop the orWhereEveryWord() call from buildGeneral(): the first case is
 *     red (0 products for "medicube booster x2");
 *   - restore the catalogue-at-large filler: the second case is red (Anua
 *     shows for a search that matches nothing);
 *   - drop the member-brand subquery from setCandidates(): the third case is
 *     red (the Anua set held by another brand's set is never first);
 *   - make setFirst() ignore search_sets_first: the fourth case is red.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\HeaderSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('product_set_items')->delete();
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    Cache::flush();
});

function swProduct(string $name, ?Brand $brand, array $extra = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'sw-'.$n.'-'.uniqid(),
        'name' => $name,
        'brand_id' => $brand?->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'total_sales' => 100 - $n,
    ], $extra));
}

/** @return list<string> product row labels in the panel, in order */
function swPanel(string $q): array
{
    $json = test()->getJson('/api/search?q='.urlencode($q))->assertOk()->json();

    foreach ($json['groups'] as $group) {
        if ($group['key'] === 'products') {
            return array_column($group['items'], 'label');
        }
    }

    return [];
}

function swShop(): array
{
    $medicube = Brand::create(['name' => 'Medicube', 'slug' => 'medicube']);
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'anua']);
    $althea = Brand::create(['name' => 'Dr.Althea', 'slug' => 'dr-althea']);

    // Best sellers that have nothing to do with the boosters.
    swProduct('Anua - Niacinamide 10% + TXA 4% Serum', $anua, ['total_sales' => 900]);
    swProduct('Dr.Althea - 345 Relief Cream', $althea, ['total_sales' => 800]);
    swProduct('Anua - Heartleaf Pore Cleansing Foam', $anua, ['total_sales' => 700]);

    $booster = swProduct('medicube - AGE-R Booster Pro X2 Pink', $medicube, ['total_sales' => 5]);
    $cream = swProduct('Medicube - PDRN Pink Collagen Capsule Cream', $medicube, ['total_sales' => 4]);

    return compact('medicube', 'anua', 'althea', 'booster', 'cream');
}

function swSet(string $name, ?Brand $brand, array $members): Product
{
    $set = swProduct($name, $brand, ['type' => 'set', 'total_sales' => 0]);

    foreach ($members as $i => $member) {
        ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $member->id, 'quantity' => 1, 'position' => $i]);
    }

    return $set;
}

it('finds a product by every word typed, in any order, with dashes in the name', function () {
    swShop();
    app(HeaderSettings::class)->save(['search_sets_first' => false]);

    expect(swPanel('medicube booster x2'))->toContain('medicube - AGE-R Booster Pro X2 Pink')
        ->and(swPanel('x2 booster'))->toContain('medicube - AGE-R Booster Pro X2 Pink')
        ->and(swPanel('pink medicube capsule'))->toContain('Medicube - PDRN Pink Collagen Capsule Cream');

    // And the full results page agrees with the box.
    $html = test()->get('/shop/?s='.urlencode('medicube booster x2'))->assertOk()->getContent();
    expect($html)->toContain('AGE-R Booster Pro X2 Pink');
});

it('shows no unrelated best sellers when nothing matches', function () {
    swShop();
    app(HeaderSettings::class)->save(['search_sets_first' => false]);

    $json = test()->getJson('/api/search?q='.urlencode('medicube booster x9 zzz'))->assertOk()->json();
    $labels = collect($json['groups'])->firstWhere('key', 'products')['items'] ?? [];

    expect(array_column($labels, 'label'))->not->toContain('Anua - Niacinamide 10% + TXA 4% Serum')
        ->and(array_column($labels, 'label'))->not->toContain('Dr.Althea - 345 Relief Cream');

    // A search that does match tops up from the SAME brand only.
    expect(swPanel('booster'))->not->toContain('Anua - Niacinamide 10% + TXA 4% Serum');
});

it('puts a set that is the brand\'s, or holds the brand\'s product, at #1', function () {
    $s = swShop();
    app(HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'best']);

    // A Medicube-branded set holding an Anua product: fits a search for Anua.
    $mixed = swSet('Glow Duo Set', $s['medicube'], [$s['booster'], Product::query()->where('name', 'like', 'Anua - Heartleaf%')->first()]);

    expect(swPanel('anua')[0] ?? null)->toBe('Glow Duo Set')
        ->and(swPanel('medicube')[0] ?? null)->toBe('Glow Duo Set');

    // Not twice when it also matched on its own.
    expect(array_count_values(swPanel('glow duo'))['Glow Duo Set'] ?? 0)->toBe(1);

    // A brand with no set is untouched.
    expect(swPanel('dr.althea')[0] ?? null)->not->toBe('Glow Duo Set');
});

it('rotates among several sets, and stays off when switched off', function () {
    $s = swShop();
    $a = swSet('Anua Set One', $s['anua'], [$s['cream']]);
    $b = swSet('Anua Set Two', $s['anua'], [$s['booster']]);

    app(HeaderSettings::class)->save(['search_sets_first' => true, 'search_sets_pick' => 'random']);
    $firsts = [];
    for ($i = 0; $i < 30; $i++) {
        $firsts[swPanel('anua')[0] ?? ''] = true;
    }
    expect(array_keys($firsts))->toEqualCanonicalizing(['Anua Set One', 'Anua Set Two']);

    app(HeaderSettings::class)->save(['search_sets_first' => false]);
    expect(swPanel('anua')[0] ?? null)->toBe('Anua - Niacinamide 10% + TXA 4% Serum');
});
