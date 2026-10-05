<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\SuperSale;
use Illuminate\Support\Facades\DB;

/**
 * /super-sale/ lists the "Super Sale" category in its Reorder order — the old
 * site's products in the old site's places. (Lane SS)
 *
 * THE DEFECT ON THE SHOP: the owner opened kbeautybliss.com/super-sale/ and
 * this shop's /super-sale/ side by side and they disagreed in two ways. Ours
 * listed every product with a markdown, biggest discount first, so (a) a
 * campaign product at full price — the Shark CryoGlow and the AGE-R Booster
 * Pro X2 in the catalogue export — was missing altogether, and (b) every
 * product sat somewhere else, because the old page orders by the Rearrange
 * Products plugin's menu_order (our `position`), not by discount.
 */
function ssProduct(string $slug, int $position, ?int $sale, ?Category $in = null, int $price = 10000): Product
{
    $p = Product::create([
        'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)),
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $price, 'sale_price' => $sale, 'position' => $position,
        'stock_status' => 'instock',
    ]);

    if ($in) {
        // The category's OWN number (Lane SO): /super-sale/ reads Super Sale's
        // order, not products.position. The same value, as the migration seeds it.
        $p->categories()->syncWithoutDetaching([$in->id => ['category_position' => $position]]);
    }

    return $p;
}

function ssCampaign(): Category
{
    return Category::create(['name' => 'Super Sale', 'slug' => 'super-sale', 'path' => 'super-sale']);
}

/** Product slugs in the order the grid draws them, first appearance only. */
function ssOrder(string $html, array $only): array
{
    preg_match_all('#/product/([a-z0-9-]+)/#', $html, $m);

    return array_values(array_filter(array_unique($m[1]), fn ($s) => in_array($s, $only, true)));
}

function ssSource(string $v): void
{
    app(SettingsService::class)->set(SuperSale::KEY, $v);
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('lists the Super Sale category in position order, full-price products included', function () {
    /*
     * MUTATION: send 'super-sale' back to the 'on_sale' arm (delete the
     * campaign block in CollectionController::show) -> the full-price product
     * vanishes and the order becomes biggest-discount-first; both lines red.
     */
    $cat = ssCampaign();
    ssProduct('ss-blusher', 0, 10500, $cat, 12800);          // position 0 sorts first, as on WooCommerce
    ssProduct('ss-pdrn-pink', 19, 5000, $cat);                // the biggest discount, but second by position
    ssProduct('ss-shark', 64, null, $cat, 245000);            // full price: missing from the old listing
    ssProduct('ss-steamer', 628, 9000, $cat);
    ssProduct('ss-not-in-campaign', 1, 1000);                 // reduced, but not in the category

    $html = $this->get('/super-sale')->assertOk()->getContent();
    $all = ['ss-blusher', 'ss-pdrn-pink', 'ss-shark', 'ss-steamer', 'ss-not-in-campaign'];

    expect(ssOrder($html, $all))->toBe(['ss-blusher', 'ss-pdrn-pink', 'ss-shark', 'ss-steamer']);
});

it('breaks a tie on position by name, as WooCommerce\'s menu_order title does', function () {
    /* MUTATION: drop ->orderBy('products.name') in SuperSale::apply -> id order, red. */
    $cat = ssCampaign();
    ssProduct('ss-zeta', 5, 900, $cat);
    ssProduct('ss-alpha', 5, 900, $cat);
    ssProduct('ss-first', 1, 900, $cat);

    $html = $this->get('/super-sale')->assertOk()->getContent();

    expect(ssOrder($html, ['ss-zeta', 'ss-alpha', 'ss-first']))->toBe(['ss-first', 'ss-alpha', 'ss-zeta']);
});

it('moves a product when Catalog → Reorder moves it', function () {
    $cat = ssCampaign();
    $a = ssProduct('ss-a', 1, 900, $cat);
    ssProduct('ss-b', 2, 900, $cat);

    // What CatalogReorderApiController writes for a category (Lane SO): the
    // product's number in THAT category, not products.position.
    DB::table('category_product')->where(['category_id' => $cat->id, 'product_id' => $a->id])->update(['category_position' => 3]);

    expect(ssOrder($this->get('/super-sale')->getContent(), ['ss-a', 'ss-b']))->toBe(['ss-b', 'ss-a']);
});

it('pages the campaign without repeating or dropping a product', function () {
    /* 30 products, all at one position: only the name/id tie-break makes LIMIT/OFFSET a partition. */
    $cat = ssCampaign();
    $slugs = [];
    for ($i = 1; $i <= 30; $i++) {
        $slugs[] = ssProduct('ss-p'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 7, 900, $cat)->slug;
    }

    $per = app(\App\Services\SiteLayout::class)->perPage(24);
    $seen = [];
    for ($page = 1; $page <= (int) ceil(30 / $per); $page++) {
        $rows = ssOrder($this->get('/super-sale?page='.$page)->getContent(), $slugs);
        expect(array_intersect($seen, $rows))->toBe([], "page {$page} repeats a product");
        $seen = array_merge($seen, $rows);
    }

    expect($seen)->toBe($slugs);   // every one of the 30, once, in name order
});

it('falls back to every reduced product when there is no Super Sale category', function () {
    /*
     * The default test shop has no such category, and a shop whose import has
     * not run yet must not show an empty campaign page.
     * MUTATION: remove the `$campaign = null` fallback -> the grid is empty, red.
     */
    ssProduct('ss-small-cut', 1, 9500);
    ssProduct('ss-big-cut', 2, 5000);

    $html = $this->get('/super-sale')->assertOk()->getContent();

    expect(ssOrder($html, ['ss-small-cut', 'ss-big-cut']))->toBe(['ss-big-cut', 'ss-small-cut'])
        ->and($html)->toContain('Every product currently reduced.');
});

it('keeps the old behaviour when the owner chooses it, and uses any category he picks', function () {
    $cat = ssCampaign();
    $other = Category::create(['name' => 'Eid Edit', 'slug' => 'eid-edit', 'path' => 'eid-edit']);
    ssProduct('ss-in-sale', 1, 9000, $cat);
    ssProduct('ss-in-eid', 1, null, $other);
    ssProduct('ss-deep-cut', 1, 1000);

    ssSource('on_sale');
    $html = $this->get('/super-sale')->getContent();
    expect(ssOrder($html, ['ss-in-sale', 'ss-in-eid', 'ss-deep-cut']))->toBe(['ss-deep-cut', 'ss-in-sale']);

    ssSource('category:'.$other->id);
    $html = $this->get('/super-sale')->getContent();
    expect(ssOrder($html, ['ss-in-sale', 'ss-in-eid', 'ss-deep-cut']))->toBe(['ss-in-eid']);
});

it('reads a stored source it does not recognise as the shipped default', function () {
    ssSource('category:0; DROP TABLE products');

    expect(SuperSale::source(app(SettingsService::class)))->toBe('auto');
});

it('changes nothing on the other three listings', function () {
    /* The campaign is /super-sale/ only: /new-in/ must not pick it up. MUTATION: drop `$key === 'super-sale'` -> red. */
    $cat = ssCampaign();
    ssProduct('ss-only-here', 1, 9000, $cat);
    ssProduct('ss-newest', 2, null);

    expect(ssOrder($this->get('/new-in')->getContent(), ['ss-only-here', 'ss-newest']))->toHaveCount(2);
});

it('costs the same number of queries for 3 campaign products as for 40', function () {
    /*
     * Rule 4: a page's cost stays flat as the catalogue grows. One correlated
     * EXISTS, the brand eager-load, no per-card query.
     * MUTATION: resolve each card's category with $product->categories in the
     * view -> 40 is dearer than 3, red.
     */
    $count = function (int $n): int {
        DB::table('category_product')->delete();
        Product::query()->delete();
        Category::query()->where('slug', 'super-sale')->delete();
        $cat = ssCampaign();
        for ($i = 1; $i <= $n; $i++) {
            ssProduct('ss-q'.$n.'-'.$i, $i, 900, $cat);
        }
        SettingsService::forgetMemo();
        $this->get('/super-sale');                      // warm the settings cache

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/super-sale')->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    $three = $count(3);
    $forty = $count(40);

    expect($forty)->toBe($three);
});
