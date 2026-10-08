<?php

declare(strict_types=1);

/*
 * The three recommendation blocks at the foot of a product page. (Lane RP2)
 *
 * The owner, changing the plan Lane RP shipped in 2.60.428: "first block will
 * be brand … 2nd block will be category … 3rd block will be You may also like
 * but the products will be picked as best sellers. and also make sure no any
 * repeat product should be there in all 3 blocks, cross wise repeat also
 * should not." Then: "also give facility to choose slider or grid, and along
 * with number of products to display … for desktop and mobile seperately with
 * global options."
 *
 * App\Services\ProductRecs chooses, App\Services\AlsoLikeSettings::layoutFor()
 * decides slider/grid and how many per device, partials/product/recs-block
 * draws, App\Support\CardFragments caches each card.
 *
 * WHAT THE PAGE DID BEFORE, which the cases below would have caught: two tabs
 * (brand / category) opened from a listing-page hint, "Complete your routine"
 * from routine shelves, and "Continue shopping" from the viewed cookie — and
 * no control of slider/grid or of the count per device.
 *
 * MUTATIONS, each run and each red (storage/rp2-logs/rp2-mut.py):
 *   M1  ProductRecs — block 1 not taken out of blocks 2 and 3, in both places
 *       that do it (forProduct()'s `$taken += …$one` and lists()'s `$skip +=
 *       …BRAND`; either alone still holds) → "never repeats a product" red: an
 *       Anua toner shows in blocks 1 and 2.
 *   M2  ProductRecs — the product itself let in (part()'s `!= id` and
 *       forProduct()'s `$taken` both dropped) → "never repeats" red.
 *   M3  ProductRecs::categoryCandidates() — the parent filter dropped (keep
 *       every category) → "most specific category" red in the stale-depth
 *       case and, with the depth sort also dropped, in both insert orders:
 *       block 2 names Skincare.
 *   M3b ProductRecs::lists() — `$stocked > $best` made `>=` (the LAST equal
 *       candidate wins) → "breaks a tie … lowest id" red: Mists, not Pads.
 *   M4  ProductRecs::forProduct()'s `&& $one !== []` AND recs.blade.php's
 *       isNotEmpty() both dropped (either alone still holds) → "a brand with
 *       nothing else" red: an empty "More from Solo" is printed.
 *   M5  ProductRecs::lists() — the best-seller pool sized `$n3 + 2 * SLACK`
 *       only → "fills every block to its count" red.
 *   M6  ProductRecs::lists() — inStockFirst() off the BEST part → "in stock
 *       first" red: block 3 opens on a sold-out best seller.
 *   M7  AlsoLikeSettings::layoutFor() — a block's own layout ignored → "a
 *       block's own setting beats the global one" red.
 *   M8  AlsoLikeSettings::layoutFor() — "Same as global" read as the block's
 *       standard → "falls through Same as global" red.
 *   M9  recs-block.blade.php — the nth-child rule's `+ 1` dropped → "renders
 *       the larger count once" red: the phone would hide one card too many.
 *   F1–F4 as Lane RP: CardFragments' signature row, settings hash, locale key,
 *       and the union's `rp_src` tag.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AlsoLikeSettings;
use App\Services\ProductRecs;
use App\Services\ProductSections;
use App\Support\AlsoLikePicks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function recsProduct(string $name, ?Brand $brand, array $categories, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 5000,
        'stock_status' => 'instock',
        'brand_id' => $brand?->id,
        'total_sales' => $sales,
    ], $extra));

    if ($categories !== []) {
        $p->categories()->sync(array_map(fn ($c) => $c->id, $categories));
    }

    return $p;
}

function recsCategory(string $name): Category
{
    return Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name,
        'depth' => 0, 'position' => -1, 'path' => null]);
}

/**
 * Brand and category overlap on purpose: four Anua TONERS are the shop's best
 * sellers, so without the no-repeat rule each of them would be in all three
 * blocks.
 *
 * @return array<string, mixed>
 */
function recsShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::lower(Str::random(4)), 'name' => 'Anua']);
    $other = Brand::create(['slug' => 'other-'.Str::lower(Str::random(4)), 'name' => 'Other']);
    $far = Brand::create(['slug' => 'far-'.Str::lower(Str::random(4)), 'name' => 'Far']);

    $toners = recsCategory('Toners');
    $serums = recsCategory('Serums');
    $hair = recsCategory('Hair');

    return [
        'brand' => $anua, 'other' => $other, 'far' => $far,
        'toners' => $toners, 'serums' => $serums, 'hair' => $hair,
        'self' => recsProduct('Heartleaf Toner', $anua, [$toners], 10),
        // Anua toners, the shop's best sellers: brand, category AND best.
        'a1' => recsProduct('Anua Toner One', $anua, [$toners], 1000400),
        'a2' => recsProduct('Anua Toner Two', $anua, [$toners], 1000300),
        'a3' => recsProduct('Anua Toner Three', $anua, [$toners], 1000200),
        'a4' => recsProduct('Anua Toner Four', $anua, [$toners], 1000100),
        'a5' => recsProduct('Anua Serum', $anua, [$serums], 500),
        // Other brands' toners: block 2 after block 1 has taken Anua's.
        't1' => recsProduct('Toner One', $other, [$toners], 290),
        't2' => recsProduct('Toner Two', $other, [$toners], 190),
        't3' => recsProduct('Toner Three', $other, [$toners], 90),
        // Best sellers in no other block.
        'x1' => recsProduct('Hair Best', $far, [$hair], 90000),
        'x2' => recsProduct('Hair Next', $far, [$hair], 80000),
        'x3' => recsProduct('Hair Third', $far, [$hair], 70000),
        // Never shown while "Hide out-of-stock" is on: same brand AND category
        // AND the top seller, so a missing filter puts it first everywhere.
        'oos' => recsProduct('Anua Sold Out', $anua, [$toners], 5000000, ['stock_status' => 'outofstock']),
    ];
}

/** The HTML of one block, by its heading id. */
function recsBlock(string $html, string $headingId): string
{
    preg_match('#<section [^>]*aria-labelledby="'.preg_quote($headingId, '#').'".*?</section>#s', $html, $m);

    return $m[0] ?? '';
}

/** @return list<string> product slugs linked from a fragment, in order, de-duplicated */
function recsSlugs(string $fragment): array
{
    preg_match_all('#href="/product/([^/"?]+)/"#', $fragment, $m);

    return array_values(array_unique($m[1]));
}

function recsPage(\Tests\TestCase $test, Product $p): string
{
    return $test->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

function recsCards(string $html, string $headingId): int
{
    return substr_count(recsBlock($html, $headingId), 'class="kbb-card kbb-tile');
}

/* ═══════════════════════════════ the blocks ═══════════════════════════════ */

it('draws brand, then category, then best sellers, with their own headings', function () {
    $s = recsShop();
    $html = recsPage($this, $s['self']);

    $one = recsBlock($html, 'rp1-h');
    $two = recsBlock($html, 'rp2-h');
    $three = recsBlock($html, 'ymal-h');

    expect($one)->toContain('<h2 id="rp1-h">More from Anua</h2>')->toContain('data-ymal ')
        ->and($two)->toContain('<h2 id="rp2-h">More Toners</h2>')->toContain('class="rel kbb-pgrid"')->not->toContain('data-ymal')
        ->and($three)->toContain('<h2 id="ymal-h">You may also like</h2>')->toContain('<div class="eyebrow">Best sellers</div>')
        ->and(strpos($html, 'id="rp1-h"'))->toBeLessThan(strpos($html, 'id="rp2-h"'))
        ->and(strpos($html, 'id="rp2-h"'))->toBeLessThan(strpos($html, 'id="ymal-h"'));

    // Block 1 is the brand, best sellers first; block 2 the category without
    // them; block 3 the shop's best sellers without both.
    expect(array_slice(recsSlugs($one), 0, 5))->toBe([$s['a1']->slug, $s['a2']->slug, $s['a3']->slug, $s['a4']->slug, $s['a5']->slug])
        ->and(recsSlugs($two))->toBe([$s['t1']->slug, $s['t2']->slug, $s['t3']->slug])
        ->and(array_slice(recsSlugs($three), 0, 3))->toBe([$s['x1']->slug, $s['x2']->slug, $s['x3']->slug]);

    // No tabs, no listing-page hint, no recently viewed any more.
    expect($html)->not->toContain('data-rp-tab')->not->toContain('data-rp-paths')->not->toContain('rp3-h');
});

it('never repeats a product across the three blocks, nor shows the product itself', function () {
    $s = recsShop();
    // And with "Buy these together" on, as it excludes from blocks 2 and 3.
    app(\App\Services\BuyTogetherSettings::class)->save(['on' => true]);
    $html = recsPage($this, $s['self']);

    $all = array_merge(recsSlugs(recsBlock($html, 'rp1-h')), recsSlugs(recsBlock($html, 'rp2-h')), recsSlugs(recsBlock($html, 'ymal-h')));

    expect($all)->not->toBeEmpty()
        ->and(count($all))->toBe(count(array_unique($all)))
        ->and($all)->not->toContain($s['self']->slug)
        ->and($all)->not->toContain($s['oos']->slug)
        // The Anua toners are in block 1 only, though each is also a toner and a best seller.
        ->and(recsSlugs(recsBlock($html, 'rp2-h')))->not->toContain($s['a1']->slug)
        ->and(recsSlugs(recsBlock($html, 'ymal-h')))->not->toContain($s['a1']->slug);

    // Whatever "Buy these together" drew stays out of blocks 2 and 3, as before.
    preg_match('#<section[^>]*class="[^"]*kbb-fbt.*?</section>#s', $html, $fbt);
    $bt = array_diff(recsSlugs($fbt[0] ?? ''), [$s['self']->slug]);
    expect(array_intersect($bt, array_merge(recsSlugs(recsBlock($html, 'rp2-h')), recsSlugs(recsBlock($html, 'ymal-h')))))->toBe([]);
});

it('fills every block to its count even when the brand and the category are the shop\'s best sellers', function () {
    $s = recsShop();
    foreach (range(1, 12) as $i) {
        recsProduct('Anua Top '.$i, $s['brand'], [$s['toners']], 900000 + $i);
        recsProduct('Toner Top '.$i, $s['other'], [$s['toners']], 800000 + $i);
    }

    $html = recsPage($this, $s['self']);

    expect(recsCards($html, 'rp1-h'))->toBe(12)
        ->and(recsCards($html, 'rp2-h'))->toBe(10)
        ->and(recsCards($html, 'ymal-h'))->toBe(12);
});

it('draws no brand block for a product with no brand, or a brand with nothing else', function () {
    $s = recsShop();

    $lone = recsProduct('No Brand Toner', null, [$s['toners']], 5);
    $html = recsPage($this, $lone);
    expect($html)->not->toContain('id="rp1-h"')
        ->and(recsBlock($html, 'rp2-h'))->toContain('More Toners')
        ->and(recsBlock($html, 'ymal-h'))->not->toBe('');

    $solo = Brand::create(['slug' => 'solo-'.Str::lower(Str::random(4)), 'name' => 'Solo']);
    $only = recsProduct('Solo Toner', $solo, [$s['toners']], 5);
    expect(recsPage($this, $only))->not->toContain('id="rp1-h"')->not->toContain('More from Solo');
});

it('draws no category block for a product with a brand and no category', function () {
    $s = recsShop();
    $bare = recsProduct('Anua No Shelf', $s['brand'], [], 5);
    $html = recsPage($this, $bare);

    expect(recsBlock($html, 'rp1-h'))->toContain('More from Anua')
        ->and($html)->not->toContain('id="rp2-h"')
        ->and(recsBlock($html, 'ymal-h'))->not->toBe('');
});

/**
 * A parent and a child shelf, with the product in both — its pivot rows
 * written parent-first or child-first.
 *
 * @return array{0: Product, 1: Category, 2: Category}
 */
function recsTwoShelves(array $s, bool $parentFirst): array
{
    $parent = Category::create(['slug' => 'skincare-'.Str::lower(Str::random(4)), 'name' => 'Skincare', 'depth' => 0, 'position' => 0]);
    $child = Category::create(['slug' => 'toner-'.Str::lower(Str::random(4)), 'name' => 'Toner', 'parent_id' => $parent->id, 'depth' => 1, 'position' => 0]);
    // Ids decide nothing: the parent is made first and has the lower id in
    // one run, and is given the higher one in the other.
    if (! $parentFirst) {
        [$parent, $child] = [$child, $parent];
        $parent->forceFill(['name' => 'Skincare', 'parent_id' => null, 'depth' => 0])->save();
        $child->forceFill(['name' => 'Toner', 'parent_id' => $parent->id, 'depth' => 1])->save();
    }

    $p = recsProduct('Two Shelf Toner '.($parentFirst ? 'a' : 'b'), $s['other'], [], 5);
    DB::table('category_product')->insert($parentFirst
        ? [['product_id' => $p->id, 'category_id' => $parent->id], ['product_id' => $p->id, 'category_id' => $child->id]]
        : [['product_id' => $p->id, 'category_id' => $child->id], ['product_id' => $p->id, 'category_id' => $parent->id]]);

    recsProduct('Skincare Only', $s['far'], [$parent], 400);
    recsProduct('Toner Only', $s['far'], [$child], 300);

    return [$p, $parent, $child];
}

it('uses the product\'s most specific category for block 2, whatever order its shelves were filed in', function (bool $parentFirst) {
    $s = recsShop();
    [$p, $parent, $child] = recsTwoShelves($s, $parentFirst);

    $two = recsBlock(recsPage($this, $p), 'rp2-h');

    expect($two)->toContain('<h2 id="rp2-h">More Toner</h2>')
        ->and(recsSlugs($two))->not->toBeEmpty();

    // And its cards are that shelf's — never the parent's own.
    $ids = DB::table('category_product')->where('category_id', $child->id)->pluck('product_id')->all();
    $slugs = Product::query()->whereIn('id', $ids)->pluck('slug')->all();
    expect(array_diff(recsSlugs($two), $slugs))->toBe([]);
})->with(['parent row first' => [true], 'child row first' => [false]]);

it('keeps the child even when the stored depth says otherwise, and never climbs to a thinner shelf\'s parent', function () {
    $s = recsShop();
    [$p, $parent, $child] = recsTwoShelves($s, true);
    // A depth CategoryTree has not resynced yet: both read 0. The child is
    // still the one whose parent is also on the product.
    DB::table('categories')->where('id', $child->id)->update(['depth' => 0]);

    $html = recsPage($this, $p);
    // "Toner" has one other product; "Skincare" would fill more. It stays Toner.
    expect(recsBlock($html, 'rp2-h'))->toContain('More Toner</h2>')
        ->and(recsCards($html, 'rp2-h'))->toBe(1);
});

it('breaks a tie between two equally deep shelves by stock, then by the lowest id, every time', function () {
    $s = recsShop();
    $pads = recsCategory('Pads');   // the lower id
    $mists = recsCategory('Mists'); // the higher id
    $p = recsProduct('Pad Mist', $s['other'], [$mists, $pads], 5);

    // Equal and thin (one other product each): the lowest id, Pads.
    recsProduct('Pad A', $s['far'], [$pads], 10);
    recsProduct('Mist A', $s['far'], [$mists], 10);
    foreach ([1, 2] as $run) {
        ProductRecs::forget((int) $p->id);
        expect(recsBlock(recsPage($this, $p), 'rp2-h'))->toContain('More Pads</h2>');
    }

    // Mists gains in-stock products (and a sold-out one, which does not count): Mists.
    recsProduct('Mist B', $s['far'], [$mists], 9);
    recsProduct('Mist C', $s['far'], [$mists], 8);
    recsProduct('Pad Sold Out', $s['far'], [$pads], 7, ['stock_status' => 'outofstock']);
    recsProduct('Pad Sold Out 2', $s['far'], [$pads], 6, ['stock_status' => 'outofstock']);
    app(AlsoLikeSettings::class)->save(['hide_oos' => false]);
    ProductRecs::forget((int) $p->id);
    expect(recsBlock(recsPage($this, $p), 'rp2-h'))->toContain('More Mists</h2>');

    // The candidates are the same whatever order the relation loads them in.
    $fresh = Product::query()->with('categories:id,name,slug,path,parent_id,depth')->find($p->id);
    $ids = fn () => array_map(fn ($c) => (int) $c->id, ProductRecs::categoryCandidates($fresh));
    $before = $ids();
    $fresh->setRelation('categories', $fresh->categories->reverse()->values());
    expect($ids())->toBe($before)->and($before)->toBe([(int) $pads->id, (int) $mists->id]);
});

it('puts in-stock products first in blocks 2 and 3 when sold-out ones are allowed', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['hide_oos' => false]);
    $catOos = recsProduct('Sold Out Toner', $s['other'], [$s['toners']], 2000000, ['stock_status' => 'outofstock']);
    $bestOos = recsProduct('Sold Out Best', $s['far'], [$s['hair']], 3000000, ['stock_status' => 'outofstock']);

    $html = recsPage($this, $s['self']);
    $two = recsSlugs(recsBlock($html, 'rp2-h'));
    $three = recsSlugs(recsBlock($html, 'ymal-h'));

    // Block 1 orders by sales alone, as the brand tab always did.
    expect(recsSlugs(recsBlock($html, 'rp1-h'))[0])->toBe($s['oos']->slug)
        ->and($two)->toContain($catOos->slug)
        ->and(array_search($s['t3']->slug, $two, true))->toBeLessThan(array_search($catOos->slug, $two, true))
        // The sold-out one outsells x1 three times over, and still does not lead.
        ->and($three[0])->toBe($s['x1']->slug);
});

it('puts a product\'s own picks first in block 3, or alone, and never twice', function () {
    $s = recsShop();
    // a1 is block 1's and t3 block 2's: picking them does not repeat them.
    $s['self']->update(['also_like' => ['mode' => AlsoLikePicks::MODE_FIRST, 'ids' => [$s['x3']->id, $s['t3']->id, $s['a1']->id]]]);
    $html = recsPage($this, $s['self']);
    $three = recsSlugs(recsBlock($html, 'ymal-h'));

    expect(recsSlugs(recsBlock($html, 'rp2-h')))->toContain($s['t3']->slug)
        ->and($three)->not->toContain($s['t3']->slug)->not->toContain($s['a1']->slug)
        ->and(array_slice($three, 0, 2))->toBe([$s['x3']->slug, $s['x1']->slug]);

    $s['self']->update(['also_like' => ['mode' => AlsoLikePicks::MODE_ONLY, 'ids' => [$s['x3']->id, $s['x2']->id]]]);
    expect(recsSlugs(recsBlock(recsPage($this, $s['self']), 'ymal-h')))->toBe([$s['x3']->slug, $s['x2']->slug]);
});

it('links every card to a clean product URL, lazy, with no query string', function () {
    $s = recsShop();
    $html = recsPage($this, $s['self']);
    $foot = recsBlock($html, 'rp1-h').recsBlock($html, 'rp2-h').recsBlock($html, 'ymal-h');

    preg_match_all('#<a [^>]*href="([^"]+)"[^>]*>#', $foot, $links, PREG_SET_ORDER);
    expect($links)->not->toBeEmpty();

    foreach ($links as [$tag, $href]) {
        if (str_starts_with($href, '?add-to-cart=')) {
            expect($tag)->toContain('rel="nofollow"');

            continue;
        }

        expect($href)->toMatch('~^/product/[^?#"]+/$~');
    }

    preg_match_all('#<img [^>]*>#', $foot, $imgs);
    foreach ($imgs[0] as $img) {
        expect($img)->toContain('loading="lazy"');
    }
});

it('turns each block off on its own, keeps the order he set, and survives the retired settings', function () {
    $s = recsShop();

    app(AlsoLikeSettings::class)->save(['brand_on' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->not->toContain('id="rp1-h"')->toContain('id="rp2-h"')->toContain('id="ymal-h"');

    app(AlsoLikeSettings::class)->save(['brand_on' => true, 'cat_on' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->toContain('id="rp1-h"')->not->toContain('id="rp2-h"');

    app(AlsoLikeSettings::class)->save(['cat_on' => true, 'enabled' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->not->toContain('id="ymal-h"')->toContain('id="rp1-h"')->toContain('id="rp2-h"');

    app(AlsoLikeSettings::class)->save(['enabled' => true, 'order' => '321']);
    $html = recsPage($this, $s['self']);
    expect(strpos($html, 'id="ymal-h"'))->toBeLessThan(strpos($html, 'id="rp2-h"'))
        ->and(strpos($html, 'id="rp2-h"'))->toBeLessThan(strpos($html, 'id="rp1-h"'));

    // Values saved under the keys Lane RP shipped and Lane RP2 retired: never
    // read, and the page draws.
    $settings = app(\App\Services\SettingsService::class);
    foreach (['ymal_layout' => 'one', 'ymal_first' => 'category', 'ymal_rule' => 'sale', 'ymal_mix' => '3:1', 'ymal_fill' => false,
        'ymal_routine_on' => true, 'ymal_routine_count' => 99, 'ymal_recent_on' => true, 'ymal_recent_title' => '<b>x</b>'] as $k => $v) {
        $settings->set($k, $v);
    }
    $html = recsPage($this, $s['self']);
    expect($html)->toContain('id="rp1-h"')->toContain('id="rp2-h"')->toContain('id="ymal-h"');
});

/* ══════════════════════ slider or grid, per device ═════════════════════════ */

it('ships the look the page already had: slider, grid, slider, 12 · 10 · 12, nothing extra', function () {
    $s = recsShop();
    foreach (range(1, 14) as $i) {
        recsProduct('Anua Extra '.$i, $s['brand'], [$s['serums']], 100 + $i);
        recsProduct('Toner Extra '.$i, $s['other'], [$s['toners']], 50 + $i);
    }
    $before = recsPage($this, $s['self']);

    // The same page with every new control saved at its shipped value.
    $d = AlsoLikeSettings::defaults();
    app(AlsoLikeSettings::class)->save(array_filter($d, fn ($k) => str_contains($k, '_layout_') || str_contains($k, '_count_'), ARRAY_FILTER_USE_KEY));
    $after = recsPage($this, $s['self']);

    $foot = fn (string $h) => recsBlock($h, 'rp1-h').recsBlock($h, 'rp2-h').recsBlock($h, 'ymal-h');
    expect($foot($after))->toBe($foot($before))
        ->and($foot($before))->not->toContain('rp-dg')->not->toContain('rp-mg')->not->toContain('data-ymal-n="')
        ->and($before)->not->toContain(':nth-child(n+')
        ->and(recsCards($before, 'rp1-h'))->toBe(12)
        ->and(recsCards($before, 'rp2-h'))->toBe(10)
        ->and(recsCards($before, 'ymal-h'))->toBe(12);
});

it('makes every block a grid or a slider per device from the global setting', function () {
    $s = recsShop();

    app(AlsoLikeSettings::class)->save(['g_layout_d' => 'grid']);
    $html = recsPage($this, $s['self']);
    // Sliders on the phone, grids on the laptop: the carousel markup with rp-dg.
    expect(recsBlock($html, 'rp1-h'))->toContain('data-ymal ')->toMatch('/class="sec ymal rp-dg /')
        ->and(recsBlock($html, 'ymal-h'))->toMatch('/class="sec ymal rp-dg /')
        // Block 2 is a grid on both now: the plain grid, no script.
        ->and(recsBlock($html, 'rp2-h'))->toContain('class="sec ymal rp-grid ')->not->toContain('data-ymal');

    app(AlsoLikeSettings::class)->save(['g_layout_d' => 'std', 'g_layout_m' => 'slider']);
    $html = recsPage($this, $s['self']);
    // Block 2: grid on the laptop (standard), slider on the phone.
    expect(recsBlock($html, 'rp2-h'))->toContain('data-ymal ')->toMatch('/class="sec ymal rp-dg /')->not->toContain('rp-mg')
        ->and(recsBlock($html, 'rp1-h'))->not->toContain('rp-dg')->not->toContain('rp-mg');
});

it('lets a block\'s own setting beat the global one', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['g_layout_m' => 'grid', 'brand_layout_m' => 'slider', 'g_count_d' => '6', 'also_count_d' => '9']);
    $html = recsPage($this, $s['self']);
    $c = app(AlsoLikeSettings::class)->all();

    expect(recsBlock($html, 'rp1-h'))->not->toContain('rp-mg')
        ->and(recsBlock($html, 'ymal-h'))->toContain('rp-mg')
        ->and(AlsoLikeSettings::layoutFor($c, 'also')['nd'])->toBe(9)
        ->and(AlsoLikeSettings::layoutFor($c, 'brand')['nd'])->toBe(6);
});

it('falls through "Same as global" to the global value, and "Standard" to the block\'s own', function () {
    $c = AlsoLikeSettings::defaults();
    $c['g_layout_d'] = 'slider';
    $c['g_count_m'] = '5';
    $c['cat_layout_d'] = 'global';
    $c['count'] = 16;

    $cat = AlsoLikeSettings::layoutFor($c, 'cat');
    $also = AlsoLikeSettings::layoutFor($c, 'also');

    expect($cat['d'])->toBe('slider')->and($cat['m'])->toBe('grid')
        ->and($cat['nd'])->toBe(10)->and($cat['nm'])->toBe(5)->and($cat['n'])->toBe(10)
        // Block 3's standard is his saved `count`.
        ->and($also['nd'])->toBe(16)->and($also['nm'])->toBe(5);
});

it('renders the larger count once and hides the rest on the device that shows fewer', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['also_count_d' => '8', 'also_count_m' => '4']);
    $html = recsPage($this, $s['self']);

    expect(recsCards($html, 'ymal-h'))->toBe(8)
        ->and(recsBlock($html, 'ymal-h'))->toContain('data-ymal-n="8 4"')
        ->and($html)->toContain('@media(max-width:900px){#related>:nth-child(n+5){display:none}}')
        ->and($html)->not->toContain('@media(min-width:901px){#related>');

    // The other way round for block 1, on the laptop.
    app(AlsoLikeSettings::class)->save(['also_count_d' => 'global', 'also_count_m' => 'global', 'brand_count_d' => '4', 'brand_count_m' => '5']);
    $html = recsPage($this, $s['self']);
    expect(recsCards($html, 'rp1-h'))->toBe(5)
        ->and($html)->toContain('@media(min-width:901px){#rp1-r>:nth-child(n+5){display:none}}');

    // And the no-repeat rule runs on what is rendered: no repeat on either
    // device, whatever each one hides.
    $all = array_merge(recsSlugs(recsBlock($html, 'rp1-h')), recsSlugs(recsBlock($html, 'rp2-h')), recsSlugs(recsBlock($html, 'ymal-h')));
    expect(count($all))->toBe(count(array_unique($all)));
});

it('falls back to the default for a stored value that is not one of its options', function () {
    $s = recsShop();
    $settings = app(\App\Services\SettingsService::class);
    $settings->set('ymal_g_layout_d', 'carousel"><script>');
    $settings->set('ymal_g_count_m', '9999');
    $settings->set('ymal_brand_layout_m', 'grid;x');
    $settings->set('ymal_also_count_d', '-3');

    $c = app(AlsoLikeSettings::class)->all();
    expect($c['g_layout_d'])->toBe('std')->and((string) $c['g_count_m'])->toBe('std')
        ->and($c['brand_layout_m'])->toBe('global')->and((string) $c['also_count_d'])->toBe('global');

    $html = recsPage($this, $s['self']);
    expect($html)->not->toContain('carousel"><script>')->not->toContain('rp-dg')->not->toContain('rp-mg')
        ->and(recsCards($html, 'ymal-h'))->toBe(12);
});

/* ═══════════════════════════════ the cost ═════════════════════════════════ */

it('costs the same queries with 3 relatives as with 40, and no more warm', function () {
    $s = recsShop();
    $count = function () use ($s): int {
        app()->forgetInstance(\App\Services\SettingsService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/product/'.$s['self']->slug.'/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $cold = function () use ($s): void {
        ProductRecs::forget((int) $s['self']->id);
        \App\Services\BuyTogether::forget((int) $s['self']->id);
    };

    $count();
    $cold();
    $small = $count();

    foreach (range(1, 37) as $i) {
        recsProduct('More Anua '.$i, $s['brand'], [$s['toners']], 5 + $i);
        recsProduct('More Hair '.$i, $s['far'], [$s['hair']], 50 + $i);
    }

    $cold();
    $big = $count();

    expect($big)->toBe($small)
        ->and($count())->toBeLessThanOrEqual($big);
});

it('asks two statements for all three blocks, cold and warm — with one shelf or two equally deep', function () {
    $s = recsShop();
    // Two equally deep shelves are two SELECTs inside the same union, not a query each.
    $s['self']->categories()->attach(recsCategory('Pads')->id);
    $s['self']->load(['brand:id,name,slug', 'categories:id,name,slug,path,parent_id,depth']);
    expect(ProductRecs::categoryCandidates($s['self']))->toHaveCount(2);
    app(\App\Services\SettingsService::class)->all();
    app(ProductSections::class)->all();
    __('store.product.recs_tab_brand');

    $recs = app(ProductRecs::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $cold = $recs->forProduct($s['self']);
    $coldN = count(DB::getQueryLog());
    DB::flushQueryLog();
    $warm = $recs->forProduct($s['self']);
    $warmN = count(DB::getQueryLog());
    DB::disableQueryLog();

    $ids = fn (array $r) => [$r['brand']['products']->pluck('id')->all(), $r['category']['products']->pluck('id')->all(), $r['alsoLike']['products']->pluck('id')->all()];

    expect($coldN)->toBe(2)->and($warmN)->toBe(2)
        ->and($ids($warm))->toBe($ids($cold));
});

it('drops a product hidden after the lists were cached, on the very next view', function () {
    $s = recsShop();
    expect(recsPage($this, $s['self']))->toContain('/product/'.$s['a2']->slug.'/');

    // Through the query builder: no model hook clears the cached lists, so the
    // warm read's own visibility check is what has to catch it.
    DB::table('products')->where('id', $s['a2']->id)->update(['is_visible' => false]);

    expect(recsPage($this, $s['self']))->not->toContain('/product/'.$s['a2']->slug.'/');
});

it('asks nothing of the database when the foot is off on every device', function () {
    $s = recsShop();
    $p = Product::query()->with(['brand:id,name,slug', 'categories:id,name,slug,path,parent_id,depth'])->find($s['self']->id);
    app(ProductSections::class)->save(['related' => ['desktop' => false, 'mobile' => false]]);
    app(\App\Services\SettingsService::class)->all();
    app(ProductSections::class)->all();
    __('store.product.related_heading'); // the page has loaded its words by now

    DB::flushQueryLog();
    DB::enableQueryLog();
    $out = app(ProductRecs::class)->forProduct($p);
    expect(DB::getQueryLog())->toBe([])
        ->and($out['brand']['products'])->toHaveCount(0)
        ->and($out['alsoLike']['products'])->toHaveCount(0);
    DB::disableQueryLog();
});

/* ═══════════════════════════════ the admin ════════════════════════════════ */

it('puts every control on Appearance → Product page → You may also like, and drops the retired ones', function () {
    $tab = AlsoLikeSettings::TABS['ymal'][2];

    foreach (['g_layout_d', 'g_count_d', 'g_layout_m', 'g_count_m', 'brand_on', 'brand_title', 'brand_title_ar', 'cat_on', 'cat_title', 'cat_title_ar',
        'enabled', 'title', 'title_ar', 'order'] as $key) {
        expect($tab)->toContain($key);
    }
    foreach (['brand', 'cat', 'also'] as $b) {
        foreach (['layout_d', 'count_d', 'layout_m', 'count_m'] as $f) {
            expect($tab)->toContain($b.'_'.$f)->and(AlsoLikeSettings::SCHEMA[$b.'_'.$f][2])->toBe('global');
        }
    }
    foreach (['layout', 'first', 'rule', 'mix', 'fill', 'routine_on', 'routine_count', 'recent_on', 'recent_count', 'brand_count', 'cat_count'] as $gone) {
        expect(AlsoLikeSettings::SCHEMA)->not->toHaveKey($gone)->and($tab)->not->toContain($gone);
    }

    $d = AlsoLikeSettings::defaults();
    expect($d['brand_on'])->toBeTrue()->and($d['cat_on'])->toBeTrue()->and($d['enabled'])->toBeTrue()
        ->and($d['g_layout_d'])->toBe('std')->and($d['g_count_m'])->toBe('std')->and($d['order'])->toBe('123');
});

it('prints a typed heading as text, and his Arabic heading only on the Arabic page', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['brand_title' => '<b>Anua picks</b>', 'cat_title_ar' => 'المزيد من التونر']);

    $html = recsPage($this, $s['self']);
    expect(recsBlock($html, 'rp1-h'))->toContain('<h2 id="rp1-h">Anua picks</h2>')
        ->and(recsBlock($html, 'rp2-h'))->toContain('More Toners');

    app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
    $ar = $this->get('/ar/product/'.$s['self']->slug.'/')->assertOk()->getContent();
    expect(recsBlock($ar, 'rp2-h'))->toContain('المزيد من التونر');
});

it('ships no listing hint and no tab script any more', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/ymal.js'));
    $shop = (string) file_get_contents(resource_path('js/kbb/shop.js'));

    foreach (['sessionStorage', 'kbb_rp_from', 'data-rp-tab', 'innerHTML', 'getBoundingClientRect', 'offsetWidth', 'fetch(', 'sendBeacon'] as $api) {
        expect(str_contains($js, $api))->toBeFalse("ymal.js reaches for {$api}");
    }
    expect($shop)->not->toContain('sessionStorage')->not->toContain('kbb_rp_from');
});

/* ═════════════ the cached cards (App\Support\CardFragments) ══════════════ */

it('serves cached cards byte-identical to the component, cold and warm', function () {
    $s = recsShop();
    Cache::flush();

    $cold = recsPage($this, $s['self']);
    expect(Cache::get('kbb.card.en.'.$s['x1']->id))->toBeArray();
    $warm = recsPage($this, $s['self']);

    $foot = fn (string $h) => recsBlock($h, 'rp1-h').recsBlock($h, 'rp2-h').recsBlock($h, 'ymal-h');
    expect($foot($warm))->toBe($foot($cold));

    // A cached card is exactly what <x-product-card> draws inline in a grid.
    $p = Product::query()->select(\App\Http\Controllers\Store\ProductController::CARD_COLUMNS)->with('brand:id,name,slug')->find($s['x1']->id);
    $inline = \Illuminate\Support\Facades\Blade::render('@foreach ($ps as $item)<x-product-card :product="$item" />@endforeach', ['ps' => collect([$p])]);

    expect(Cache::get('kbb.card.en.'.$s['x1']->id)[1])->toBe($inline)
        ->and($foot($warm))->toContain($inline);
});

it('never serves a card from before a price or stock change', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['hide_oos' => false]);
    recsPage($this, $s['self']);

    $s['x1']->update(['price' => 12300]);
    $s['t1']->update(['stock_status' => 'outofstock']);

    $html = recsPage($this, $s['self']);

    expect(recsBlock($html, 'ymal-h'))->toContain('123')
        ->and(Cache::get('kbb.card.en.'.$s['x1']->id)[1])->toContain('123')
        ->and(Cache::get('kbb.card.en.'.$s['t1']->id)[1])
            ->toBe(\App\Support\CardFragments::render(Product::query()->select(\App\Http\Controllers\Store\ProductController::CARD_COLUMNS)->with('brand:id,name,slug')->find($s['t1']->id)));
});

it('re-renders the cards when a setting they print changes', function () {
    $s = recsShop();
    recsPage($this, $s['self']);
    expect(recsBlock(recsPage($this, $s['self']), 'rp2-h'))->not->toContain('data-kbb-wish=');

    app(\App\Services\SettingsService::class)->setModule('wishlist', true);

    expect(recsBlock(recsPage($this, $s['self']), 'rp2-h'))->toContain('data-kbb-wish=');
});

it('keys each language apart, and puts nothing per-visitor into a card', function () {
    $s = recsShop();
    app(\App\Services\SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, true);
    \App\Services\Translation\TranslationStore::put('ar', \App\Models\Translation::GROUP_UI, 0, 'store.product_card.add_to_cart', 'أضف إلى السلة', \App\Models\Translation::STATUS_PUBLISHED);

    $en = recsBlock(recsPage($this, $s['self']), 'rp2-h');
    $ar = recsBlock($this->get('/ar/product/'.$s['self']->slug.'/')->assertOk()->getContent(), 'rp2-h');

    expect($en)->toContain('Add to cart')->not->toContain('أضف إلى السلة')
        ->and($ar)->toContain('أضف إلى السلة')
        ->and(Cache::get('kbb.card.ar.'.$s['t1']->id))->toBeArray()
        ->and(Cache::get('kbb.card.en.'.$s['t1']->id))->toBeArray();

    $card = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/components/product-card.blade.php')));
    foreach (['session(', 'Session::', 'cookie(', 'request(', 'Request::', 'auth(', 'Auth::', 'csrf', '@auth', 'app(\\App\\Services\\CartService', 'CartService::current', 'auth()->'] as $needle) {
        expect(str_contains($card, $needle))->toBeFalse("the card reads {$needle} — it can no longer be shared between visitors");
    }
});

it('reuses the cards when the lists are rebuilt, and reads a repeat view as one entry', function () {
    $s = recsShop();
    recsPage($this, $s['self']);
    recsPage($this, $s['self']); // the warm lists: their rows carry no source tag
    $before = Cache::get('kbb.card.en.'.$s['x1']->id);
    $bundle = Cache::get('kbb.cards.en.'.$s['self']->id);

    expect(unserialize(gzinflate($bundle), ['allowed_classes' => false]))->toHaveKey($s['x1']->id);

    ProductRecs::forget((int) $s['self']->id);
    $p = Product::query()->with(['brand:id,name,slug', 'categories:id,name,slug,path,parent_id,depth'])->find($s['self']->id);
    $row = app(ProductRecs::class)->forProduct($p)['alsoLike']['products']->firstWhere('id', $s['x1']->id);

    expect($row->getAttribute('rp_src'))->not->toBeNull()
        ->and(\App\Support\CardFragments::many([$row])[$s['x1']->id])->toBe($before[1])
        ->and(Cache::get('kbb.card.en.'.$s['x1']->id))->toBe($before);
});
