<?php

declare(strict_types=1);

/*
 * The three recommendation blocks at the foot of a product page.  (Lane RP)
 *
 * The owner, 8 October: "1st a SLIDER, 2nd a GRID, 3rd a SLIDER", the first
 * one context-aware — from a category page, more from that category; from a
 * brand page, more from that brand. App\Services\ProductRecs chooses,
 * resources/js/kbb/shop.js leaves the hint, resources/js/kbb/ymal.js reads it.
 *
 * WHAT THE PAGE DID BEFORE, which every case below would have caught: ONE row,
 * "You may also like", brand and category interleaved into a single list, and
 * nothing under it — no routine, no recently viewed, no way to see more of the
 * brand or the category the shopper came from.
 *
 * MUTATIONS, each run against this file and each red:
 *
 *   R1  ProductRecs::tabsMode() — return false → "draws both tabs" red: the
 *       page is the old single row, no data-rp-tabs.
 *   R2  ProductRecs::assemble() — drop the array_reverse() for `first` →
 *       "opens on the category when he chose it" red.
 *   R3  Both exclusions of block 1 removed — rank()'s `$skip` and
 *       assemble()'s `$taken += $block1` (either alone still holds) → "never
 *       repeats a product" red: a brand sibling on a routine shelf shows twice.
 *   R4  ProductRecs::routineOrder() — drop the inStockFirst() call → "in stock
 *       first" red: the sold-out best seller leads its shelf.
 *   R5  ProductRecs::routineOrder() — drop the tag CASE → "shares a skin
 *       concern first" red: the higher seller without the tag comes first.
 *   R6  ProductRecs::viewedIds() — return [] → "puts what the shopper viewed
 *       first" red.
 *   R7  ProductRecs::forProduct() — remove the onPage (Buy these together)
 *       exclusion → "never repeats Buy these together" red.
 *   R8  ProductRecs::pools() — the one load('brand') dropped (each card then
 *       lazy-loads its brand) → the flat-cost case red (3 vs 40 relatives).
 *   R9  partials/product/recs-routine.blade.php — anything printed outside
 *       its @if → "switched off is today's page, byte for byte" red.
 *
 *   All nine were RUN (storage/rp-logs/rp-mut.py in the lane's worktree) and
 *   each was red; R7 needed "Buy these together" switched on in its case,
 *   because that section ships off and an off section excludes nothing.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tag;
use App\Services\AlsoLikeRail;
use App\Services\AlsoLikeSettings;
use App\Services\ProductRecs;
use App\Services\ProductSections;
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
    // depth 0, position -1: the shelf BuyTogetherPairs picks for its kind,
    // ahead of the demo catalogue's own Serums, Moisturisers ...
    return Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name,
        'depth' => 0, 'position' => -1, 'path' => null]);
}

/** @return array<string, mixed> */
function recsShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::lower(Str::random(4)), 'name' => 'Anua']);
    $other = Brand::create(['slug' => 'other-'.Str::lower(Str::random(4)), 'name' => 'Other']);
    $far = Brand::create(['slug' => 'far-'.Str::lower(Str::random(4)), 'name' => 'Far']);

    $toners = recsCategory('Toners');
    $serums = recsCategory('Serums');
    $creams = recsCategory('Moisturisers');
    $suns = recsCategory('Sunscreens');
    $hair = recsCategory('Hair');

    $hydration = Tag::create(['slug' => 'hydration-'.Str::lower(Str::random(4)), 'name' => 'Hydration']);

    $self = recsProduct('Heartleaf Toner', $anua, [$toners], 10);
    $self->tags()->sync([$hydration->id]);

    $s = [
        'self' => $self,
        'brand' => $anua,
        'toners' => $toners,
        // Block 1, brand tab: Anua on other shelves. b1 is ALSO a serum, so a
        // missing exclusion shows it again in block 2.
        'b1' => recsProduct('Anua Serum', $anua, [$serums], 300),
        'b2' => recsProduct('Anua Cream', $anua, [$creams], 200),
        'b3' => recsProduct('Anua Hair', $anua, [$hair], 100),
        // Block 1, category tab: other brands' toners.
        'c1' => recsProduct('Toner One', $other, [$toners], 290),
        'c2' => recsProduct('Toner Two', $other, [$toners], 190),
        'c3' => recsProduct('Toner Three', $other, [$toners], 90),
        // Block 2, the routine after a toner: serum, moisturiser, sunscreen.
        // s1 shares the toner's tag and sells less than s2 — it still leads.
        's1' => recsProduct('Hydra Serum', $far, [$serums], 20),
        's2' => recsProduct('Bright Serum', $far, [$serums], 400),
        'm1' => recsProduct('Day Cream', $far, [$creams], 30),
        'moos' => recsProduct('Sold Out Cream', $far, [$creams], 9000, ['stock_status' => 'outofstock']),
        'u1' => recsProduct('Daily Sun', $far, [$suns], 25),
        // The shop's best sellers: block 3's fallback.
        'x1' => recsProduct('Hair Best', $far, [$hair], 90000),
        'x2' => recsProduct('Hair Next', $far, [$hair], 80000),
        'x3' => recsProduct('Hair Third', $far, [$hair], 70000),
        // Viewed earlier by the shopper, in no other block.
        'v1' => recsProduct('Old Find', $other, [$hair], 1),
    ];
    $s['s1']->tags()->sync([$hydration->id]);

    return $s;
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

it('draws both tabs with crawlable links, the brand open and the category closed', function () {
    $s = recsShop();
    $html = recsPage($this, $s['self']);
    $one = recsBlock($html, 'ymal-h');

    expect($one)->toContain('data-rp-tabs')
        ->and($one)->toContain('>More from Anua</button>')
        ->and($one)->toContain('>More Toners</button>')
        ->and($one)->toContain('id="rp-t-brand" aria-controls="rp-p-brand" aria-selected="true"')
        ->and($one)->toContain('id="rp-t-category" aria-controls="rp-p-category" aria-selected="false" tabindex="-1"');

    preg_match('#<div class="rp-panel" id="rp-p-brand"[^>]*>#', $one, $brandTag);
    preg_match('#<div class="rp-panel" id="rp-p-category"[^>]*>#', $one, $catTag);
    expect($brandTag[0])->not->toContain('hidden')
        ->and($brandTag[0])->toContain('data-rp-paths="/brands/'.$s['brand']->slug.'/"')
        ->and($catTag[0])->toContain(' hidden')
        ->and($catTag[0])->toContain('/collections/'.$s['toners']->slug.'/');

    // Both lists are links in the HTML, whichever tab is open.
    $panels = explode('id="rp-p-category"', $one);
    expect(recsSlugs($panels[0]))->toContain($s['b1']->slug, $s['b2']->slug, $s['b3']->slug)
        ->and(recsSlugs($panels[1]))->toContain($s['c1']->slug, $s['c2']->slug, $s['c3']->slug);
});

it('lists the category\'s parent shelves too, so a click from Skincare opens "More Toners"', function () {
    $s = recsShop();
    $parent = Category::create(['slug' => 'skin-'.Str::lower(Str::random(4)), 'name' => 'Skincare', 'depth' => 0, 'position' => 9]);
    $s['toners']->update(['parent_id' => $parent->id, 'depth' => 1, 'path' => $parent->slug.'/'.$s['toners']->slug]);

    $one = recsBlock(recsPage($this, $s['self']), 'ymal-h');
    preg_match('#<div class="rp-panel" id="rp-p-category"[^>]*data-rp-paths="([^"]*)"#', $one, $m);

    expect(explode(' ', $m[1] ?? ''))->toBe([
        '/collections/'.$parent->slug.'/'.$s['toners']->slug.'/',
        '/collections/'.$parent->slug.'/',
    ]);
});

it('opens on the category when he chose it as the tab that opens first', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['first' => 'category']);

    $one = recsBlock(recsPage($this, $s['self']), 'ymal-h');

    expect($one)->toContain('id="rp-t-category" aria-controls="rp-p-category" aria-selected="true"')
        ->and(strpos($one, 'id="rp-p-category"'))->toBeLessThan(strpos($one, 'id="rp-p-brand"'));
    preg_match('#<div class="rp-panel" id="rp-p-brand"[^>]*>#', $one, $brandTag);
    expect($brandTag[0])->toContain(' hidden');
});

it('draws one tab for a product with no brand, and the single row for one with neither', function () {
    $s = recsShop();
    $lone = recsProduct('No Brand Toner', null, [$s['toners']], 5);

    $one = recsBlock(recsPage($this, $lone), 'ymal-h');
    expect(substr_count($one, 'data-rp-tab>'))->toBe(1)
        ->and($one)->toContain('>More Toners</button>');

    $bare = recsProduct('Nothing At All', null, [], 5);
    $page = recsPage($this, $bare);
    expect($page)->not->toContain('data-rp-tabs')
        ->and(recsBlock($page, 'ymal-h'))->toContain('id="related" data-ymal-track');
});

it('builds block 2 from the routine shelves, sharing a skin concern first, one shelf at a time', function () {
    $s = recsShop();
    $two = recsBlock(recsPage($this, $s['self']), 'rp2-h');
    $slugs = recsSlugs($two);

    expect($two)->toContain('<h2 id="rp2-h">Complete your routine</h2>')
        ->and($two)->toContain('class="rel kbb-pgrid"')
        ->and($two)->not->toContain('ymal-track');
    // Toner → serum, moisturiser, sunscreen, in turn; s1 (shares the tag)
    // before s2 (sells more, no tag).
    expect(array_slice($slugs, 0, 3))->toBe([$s['s1']->slug, $s['m1']->slug, $s['u1']->slug])
        ->and(array_search($s['s1']->slug, $slugs, true))->toBeLessThan(array_search($s['s2']->slug, $slugs, true));
    // Sold out stays out while "Hide out-of-stock products" is on.
    expect($slugs)->not->toContain($s['moos']->slug);
});

it('puts in-stock products first when sold-out ones are allowed', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['hide_oos' => false]);

    $slugs = recsSlugs(recsBlock(recsPage($this, $s['self']), 'rp2-h'));

    expect($slugs)->toContain($s['moos']->slug)
        ->and(array_search($s['m1']->slug, $slugs, true))->toBeLessThan(array_search($s['moos']->slug, $slugs, true));
});

it('never repeats a product between the blocks, nor what Buy these together shows', function () {
    $s = recsShop();
    // "Buy these together" ships off; on, it takes the best seller of each
    // routine shelf — exactly what block 2 would otherwise lead with.
    app(\App\Services\BuyTogetherSettings::class)->save(['on' => true]);
    $html = recsPage($this, $s['self']);

    $one = recsSlugs(recsBlock($html, 'ymal-h'));
    $two = recsSlugs(recsBlock($html, 'rp2-h'));
    $three = recsSlugs(recsBlock($html, 'rp3-h'));

    expect($two)->not->toBeEmpty()->and($three)->not->toBeEmpty()
        ->and(array_intersect($one, $two))->toBe([])
        ->and(array_intersect($one, $three))->toBe([])
        ->and(array_intersect($two, $three))->toBe([])
        ->and(array_merge($one, $two, $three))->not->toContain($s['self']->slug)
        // b1 is a serum too: block 1 has it, so the routine does not.
        ->and($two)->not->toContain($s['b1']->slug);

    // Whatever "Buy these together" drew is not drawn again below it.
    preg_match('#<section[^>]*class="[^"]*kbb-fbt.*?</section>#s', $html, $fbt);
    $bt = array_diff(recsSlugs($fbt[0] ?? ''), [$s['self']->slug]);
    expect($bt)->toContain($s['s2']->slug, $s['u1']->slug)
        ->and(array_intersect($bt, array_merge($two, $three)))->toBe([]);
});

it('fills block 3 with best sellers for a first visit, and puts what the shopper viewed first', function () {
    $s = recsShop();

    $three = recsBlock(recsPage($this, $s['self']), 'rp3-h');
    expect($three)->toContain('<div class="eyebrow">Best sellers</div>')
        ->and($three)->toContain('<h2 id="rp3-h">Continue shopping</h2>')
        ->and(recsSlugs($three))->toContain($s['x3']->slug);
    // Best sellers, best first ("Buy these together" may have taken the top
    // one or two; what is left keeps the order).
    $sales = Product::query()->whereIn('slug', recsSlugs($three))->pluck('total_sales', 'slug');
    $inOrder = array_map(fn ($slug) => (int) $sales[$slug], recsSlugs($three));
    $sorted = $inOrder;
    rsort($sorted);
    expect($inOrder)->toBe($sorted);

    // Newest first, as the cookie keeps them; c3 is in block 1 already and the
    // product itself is never listed.
    $cookie = implode(',', [$s['self']->id, $s['v1']->id, $s['c3']->id]);
    $html = $this->withCookie('kbb_viewed', $cookie)->get('/product/'.$s['self']->slug.'/')->assertOk()->getContent();
    $three = recsBlock($html, 'rp3-h');

    expect($three)->toContain('<div class="eyebrow">Recently viewed</div>')
        ->and(recsSlugs($three)[0])->toBe($s['v1']->slug)
        ->and(recsSlugs($three))->not->toContain($s['c3']->slug);
});

it('links every card to a clean product URL, lazy, with no query string', function () {
    $s = recsShop();
    $html = recsPage($this, $s['self']);
    $foot = recsBlock($html, 'ymal-h').recsBlock($html, 'rp2-h').recsBlock($html, 'rp3-h');

    // Every navigating link is a plain /product/{slug}/ — InstantNav
    // prefetches it. The only other href is the card's own no-JavaScript
    // add to cart, rel=nofollow, which InstantNav never fetches anyway.
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

it('costs the same queries with 3 relatives as with 40, and fewer warm', function () {
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

    // Everything else on the page warm; only this product's choices cold.
    $cold = function () use ($s): void {
        ProductRecs::forget((int) $s['self']->id);
        AlsoLikeRail::forget((int) $s['self']->id);
        \App\Services\BuyTogether::forget((int) $s['self']->id);
    };
    $count();
    $cold();
    $small = $count();

    foreach (range(1, 37) as $i) {
        recsProduct('More Anua '.$i, $s['brand'], [$s['toners']], 5 + $i);
        recsProduct('More Serum '.$i, null, [$s['toners']], 5 + $i)->categories()->sync([Category::where('name', 'Serums')->orderByDesc('id')->value('id')]);
    }

    $cold();
    $big = $count();

    expect($big)->toBe($small);

    // Warm: the cached pools, one IN for all three blocks.
    $warm = $count();
    expect($warm)->toBeLessThanOrEqual($big);
});

it('asks two statements for all three blocks, cold and warm', function () {
    $s = recsShop();
    $s['self']->load(['brand:id,name,slug', 'categories:id,name,slug,path']);
    app(\App\Services\SettingsService::class)->all();
    app(ProductSections::class)->all();
    app(\App\Services\BuyTogetherPairs::class)->categories();
    __('store.product.recs_tab_brand');

    $recs = app(ProductRecs::class);
    $req = \Illuminate\Http\Request::create('/');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $cold = $recs->forProduct($s['self'], $req);
    $coldN = count(DB::getQueryLog());
    DB::flushQueryLog();
    $warm = $recs->forProduct($s['self'], $req);
    $warmN = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($coldN)->toBe(2)->and($warmN)->toBe(2)
        ->and($warm['routine']['products']->pluck('id')->all())->toBe($cold['routine']['products']->pluck('id')->all())
        ->and($warm['recent']['products']->pluck('id')->all())->toBe($cold['recent']['products']->pluck('id')->all());
});

it('switched off is today\'s page, byte for byte', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['layout' => 'one', 'routine_on' => false, 'recent_on' => false]);

    $html = recsPage($this, $s['self']);

    $product = Product::query()->with(['brand:id,name,slug', 'categories:id,name,slug,path'])->find($s['self']->id);
    $old = view('partials.you-may-also-like', [
        'alsoLike' => app(AlsoLikeRail::class)->forProduct($product),
        'modules' => app(ProductSections::class),
    ])->render();

    expect($old)->toContain('id="related" data-ymal-track')
        ->and($html)->toContain("  <!-- related -->\n".$old.'</div>')
        ->and($html)->not->toContain('data-rp-tabs')
        ->and($html)->not->toContain('rp2-h')
        ->and($html)->not->toContain('rp3-h');
});

it('turns each block off on its own, and keeps the order he set', function () {
    $s = recsShop();

    app(AlsoLikeSettings::class)->save(['routine_on' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->not->toContain('rp2-h')->and($html)->toContain('rp3-h')->and($html)->toContain('data-rp-tabs');

    app(AlsoLikeSettings::class)->save(['routine_on' => true, 'recent_on' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->toContain('rp2-h')->and($html)->not->toContain('rp3-h');

    app(AlsoLikeSettings::class)->save(['recent_on' => true, 'enabled' => false]);
    $html = recsPage($this, $s['self']);
    expect($html)->not->toContain('ymal-h')->and($html)->toContain('rp2-h')->and($html)->toContain('rp3-h');

    app(AlsoLikeSettings::class)->save(['enabled' => true, 'order' => '321']);
    $html = recsPage($this, $s['self']);
    expect(strpos($html, 'rp3-h'))->toBeLessThan(strpos($html, 'rp2-h'))
        ->and(strpos($html, 'rp2-h'))->toBeLessThan(strpos($html, 'id="ymal-h"'));

    // A posted order that is not one of the six is the default.
    app(AlsoLikeSettings::class)->save(['order' => '9;<b>']);
    expect(app(AlsoLikeSettings::class)->all()['order'])->toBe('123');
});

it('reads the hint only by comparing it, and writes it only from the listing grids', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/ymal.js'));
    $shop = (string) file_get_contents(resource_path('js/kbb/shop.js'));

    // One key, the one the server's docs name.
    expect($js)->toContain("const HINT = '".ProductRecs::HINT_KEY."';")
        ->and($shop)->toContain("sessionStorage.setItem('".ProductRecs::HINT_KEY."', decodeURI(window.location.pathname))");

    // Validated before use: a short absolute path, compared with the paths the
    // server printed — and never written into the page.
    expect($js)->toContain("from.length > 300 || from.charAt(0) !== '/'")
        ->and($js)->toContain(".split(' ').indexOf(from) !== -1");
    foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'getBoundingClientRect', 'offsetWidth', 'fetch(', 'XMLHttpRequest', 'sendBeacon'] as $api) {
        expect(str_contains($js, $api))->toBeFalse("ymal.js reaches for {$api}");
    }
    // Only the two listing grids write it.
    expect($shop)->toContain("document.getElementById('grid') || document.getElementById('brandGrid')");
});

it('puts every control on the You may also like tab of Appearance → Product page', function () {
    $tab = AlsoLikeSettings::TABS['ymal'][2];

    foreach (['layout', 'first', 'routine_on', 'routine_count', 'routine_title', 'routine_title_ar', 'recent_on', 'recent_count', 'recent_title', 'recent_title_ar', 'order'] as $key) {
        expect($tab)->toContain($key)->and(AlsoLikeSettings::SCHEMA)->toHaveKey($key);
    }

    // Shipped ON, as he asked.
    $d = AlsoLikeSettings::defaults();
    expect($d['layout'])->toBe('tabs')->and($d['first'])->toBe('brand')
        ->and($d['routine_on'])->toBeTrue()->and($d['recent_on'])->toBeTrue()
        ->and($d['order'])->toBe('123');

    // A count outside 4–12 is pulled into it.
    app(AlsoLikeSettings::class)->save(['routine_count' => 99, 'recent_count' => 1]);
    $c = app(AlsoLikeSettings::class)->all();
    expect($c['routine_count'])->toBe(12)->and($c['recent_count'])->toBe(4);
});

it('prints a typed heading as text, and his Arabic heading only on the Arabic page', function () {
    $c = AlsoLikeSettings::defaults();
    $c['routine_title'] = '<b>Next steps</b>';
    $c['recent_title_ar'] = 'تابعي التسوق';

    expect(ProductRecs::wording($c, 'routine', false)['title'])->toBe('<b>Next steps</b>')
        ->and(ProductRecs::wording($c, 'recent', true)['title'])->toBe('Continue shopping');

    // Saved through the schema, markup is stripped before it is stored.
    app(AlsoLikeSettings::class)->save(['routine_title' => '<script>x</script>Next']);
    expect(app(AlsoLikeSettings::class)->all()['routine_title'])->not->toContain('<script>');
});

/* ═════════════ the cached cards (App\Support\CardFragments) ══════════════
 *
 * The blocks' cards are rendered once and reused — the coordinator's speed
 * gate: ~43 cards cost ~8 ms of PHP per view uncached. What follows pins that
 * the cache serves EXACTLY what the component would draw, and never a card
 * from before a change.
 *
 * MUTATIONS, run and red:
 *   F1  CardFragments::signature() — drop the row → "a price or stock
 *       change" red: the old price is served.
 *   F2  CardFragments::shop() — hash only the language, not the settings
 *       snapshot and module switches → "a setting they print" red: the
 *       wishlist hearts stay off after Wishlist is switched on.
 *   F3  CardFragments::many() — key without the locale → "keys each
 *       language" red: one entry overwrites the other.
 *   F4  CardFragments::signature() — keep the union's `rp_src` tag → "reuses
 *       the cards when the choice is rebuilt" red.
 *   F5  ProductRecs::pools() — the best-seller pool sized without block 1's
 *       two tabs (`$tabs ? 0`) → "fills every block to its count" red: block
 *       3 draws 2 cards, not 10.
 */

it('serves cached cards byte-identical to the component, cold and warm', function () {
    $s = recsShop();
    \Illuminate\Support\Facades\Cache::flush();

    $cold = recsPage($this, $s['self']);
    expect(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id))->toBeArray();
    $warm = recsPage($this, $s['self']);

    $foot = fn (string $h) => recsBlock($h, 'ymal-h').recsBlock($h, 'rp2-h').recsBlock($h, 'rp3-h');
    expect($foot($warm))->toBe($foot($cold));

    // And a cached card is exactly what <x-product-card> draws in a grid: the
    // one-row layout still writes the tag inline, so compare the two.
    app(AlsoLikeSettings::class)->save(['layout' => 'one']);
    $inline = recsBlock(recsPage($this, $s['self']), 'ymal-h');
    $p = Product::query()->select(\App\Http\Controllers\Store\ProductController::CARD_COLUMNS)->with('brand:id,name,slug')->find($s['b1']->id);
    $card = \App\Support\CardFragments::render($p);

    expect($card)->toContain('/product/'.$s['b1']->slug.'/')
        ->and($inline)->toContain($card)
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id)[1])->toBe($card);
});

it('never serves a card from before a price or stock change', function () {
    $s = recsShop();
    app(AlsoLikeSettings::class)->save(['hide_oos' => false]);
    recsPage($this, $s['self']);

    $s['b1']->update(['price' => 12300]);
    $s['c1']->update(['stock_status' => 'outofstock']);

    $html = recsPage($this, $s['self']);
    preg_match('#<div class="kbb-card[^"]*"[^>]*>(?:(?!<div class="kbb-card).)*?/product/'.$s['b1']->slug.'/.*?</div>\s*</div>#s', recsBlock($html, 'ymal-h'), $b1);

    expect(recsBlock($html, 'ymal-h'))->toContain('123')
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id)[1])->toContain('123')
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['c1']->id)[1])
            ->toBe(\App\Support\CardFragments::render(Product::query()->select(\App\Http\Controllers\Store\ProductController::CARD_COLUMNS)->with('brand:id,name,slug')->find($s['c1']->id)));
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
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.ar.'.$s['s1']->id))->toBeArray()
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['s1']->id))->toBeArray();

    // The card reads no session, cookie, customer or request — one cached card
    // is right for every visitor. The wishlist heart is inert markup.
    $card = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/components/product-card.blade.php')));
    foreach (['session(', 'Session::', 'cookie(', 'request(', 'Request::', 'auth(', 'Auth::', 'csrf', '@auth', 'app(\\App\\Services\\CartService', 'CartService::current', 'auth()->'] as $needle) {
        expect(str_contains($card, $needle))->toBeFalse("the card reads {$needle} — it can no longer be shared between visitors");
    }
});

it('keeps one cache entry per product and language, however often it changes', function () {
    $s = recsShop();
    foreach ([5100, 5200, 5300] as $price) {
        $s['b1']->update(['price' => $price]);
        recsPage($this, $s['self']);
    }

    expect(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id)[1])->toContain('53');
});

it('reuses the cards when the choice is rebuilt, not only when it is cached', function () {
    /*
     * The cold choice comes out of the union with a source tag on each row;
     * the warm one does not. A signature that included the tag missed on
     * every cold view and re-rendered every card — measured, +6 ms.
     */
    $s = recsShop();
    recsPage($this, $s['self']);
    recsPage($this, $s['self']); // the warm choice: its rows carry no tag
    $before = \Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id);

    ProductRecs::forget((int) $s['self']->id);
    $p = Product::query()->with(['brand:id,name,slug', 'categories:id,name,slug,path'])->find($s['self']->id);
    $cold = app(ProductRecs::class)->forProduct($p, \Illuminate\Http\Request::create('/'));
    $row = collect($cold['alsoLike']['panels'][0]['products'])->firstWhere('id', $s['b1']->id);

    expect($row->getAttribute('rp_src'))->not->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = \App\Support\CardFragments::many([$row]);
    DB::disableQueryLog();

    expect($html[$s['b1']->id])->toBe($before[1])
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['b1']->id))->toBe($before);
});

it('fills every block to its count even when block 1 holds the shop\'s best sellers', function () {
    /*
     * The shared best-seller pool is block 2's top-up and block 3's fallback,
     * and block 1's two tabs can hold its top two dozen. A pool sized without
     * that (a trim tried while chasing the speed gate) left "Continue
     * shopping" with TWO cards on the preview shop. Here the brand and the
     * category are the shop's 24 best sellers.
     */
    $s = recsShop();
    $other = Brand::create(['slug' => 'big-'.Str::lower(Str::random(4)), 'name' => 'Big']);
    foreach (range(1, 12) as $i) {
        recsProduct('Anua Top '.$i, $s['brand'], [Category::where('name', 'Hair')->orderByDesc('id')->firstOrFail()], 500000 + $i);
        recsProduct('Toner Top '.$i, $other, [$s['toners']], 400000 + $i);
    }

    $html = recsPage($this, $s['self']);
    $cards = fn (string $id) => substr_count(recsBlock($html, $id), 'class="kbb-card kbb-tile');

    expect($cards('rp2-h'))->toBe(10)
        ->and($cards('rp3-h'))->toBe(10);
});

it('reads a repeat view\'s shared cards as one entry, and keeps the shopper\'s history out of it', function () {
    $s = recsShop();
    $cookie = implode(',', [$s['v1']->id]);
    $first = $this->withCookie('kbb_viewed', $cookie)->get('/product/'.$s['self']->slug.'/')->assertOk()->getContent();

    $raw = \Illuminate\Support\Facades\Cache::get('kbb.cards.en.'.$s['self']->id);
    $bundle = unserialize(gzinflate($raw), ['allowed_classes' => false]);

    expect($bundle)->toHaveKey($s['b1']->id)
        // v1 is this shopper's own; it lives only in its per-card entry.
        ->and($bundle)->not->toHaveKey($s['v1']->id)
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.card.en.'.$s['v1']->id))->toBeArray();

    // A second visitor with no history: the page's entry is not rewritten.
    $this->get('/product/'.$s['self']->slug.'/')->assertOk();
    expect(\Illuminate\Support\Facades\Cache::get('kbb.cards.en.'.$s['self']->id))->toBe($raw);

    // And the same visitor again: byte-identical page.
    $again = $this->withCookie('kbb_viewed', $cookie)->get('/product/'.$s['self']->slug.'/')->assertOk()->getContent();
    $foot = fn (string $h) => recsBlock($h, 'ymal-h').recsBlock($h, 'rp2-h').recsBlock($h, 'rp3-h');
    expect($foot($again))->toBe($foot($first));
});
