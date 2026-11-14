<?php

declare(strict_types=1);

/*
 * "Frequently bought together" never draws a card beside a set that holds it,
 * whether or not the set's contents are recorded.         (Lane FBT3, 14 Nov 2026)
 *
 * THE LIVE DEFECT. The owner, with a screenshot of
 * kbeautybliss.com/product/dr-althea-345-relief-cream/: the row drew
 *
 *   [Dr.Althea 345 Relief Cream (the page), Dr. Althea 345 Relief Cream + Mist
 *    Spray Set, Dr.Althea 345 Relief Cream Mist Spray, Dr. Althea 0.1% Gentle
 *    Retinol Serum]   "Buy 4 items together"
 *
 *   "your solution is wrong, see i'm getting the product itself and also the
 *    2nd product in the same row, both are in the set. i need a strong
 *    solution to identify the product in the set and exclude it."
 *
 * 2.60.667 only knew RECORDED contents (product_set_items). On live, none of
 * the 72 set-like products has recorded contents: the set is a plain product
 * named "... + ... Set" whose description reads "What's Included in the Set:
 * Dr Althea 345 Relief Cream: ... Dr.Althea – 345 Relief Cream Mist Spray: ...".
 * App\Support\SetMembership reads membership from the recording, the NAME and
 * the DESCRIPTION; BuyTogether::arrange() takes every member out of the row
 * and refills it.
 *
 * Mutations, each run and red here (14 Nov 2026):
 *   - BuyTogether::choose(): `if (false)` for `if ($touched)` → four red:
 *     the live row comes back (cream first, the mist spray drawn).
 *   - SetMembership::linesOf(): stop splitting on ":" → "description
 *     listing" red (the serum stays beside the set).
 *   - SetMembership::links(): drop the brand_id comparison → "other brand"
 *     red (the other brand's mist spray is linked and disappears).
 *   - BuyTogether::choose(): one wider pick only (REFILL 1) → three red:
 *     the row is not refilled to four.
 *   - SetMembership::byName(): drop the elided-part branch → "A + B" red.
 *   - A separate description query instead of bt_desc on the union → the
 *     flat-query case red (4 cold, not 3).
 */

use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\BuyTogether;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherSettings;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Support\SetMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const SMB_SET_DESC = '<p><strong>What’s Included in the Set:</strong></p><ul>'
    .'<li><strong>Dr Althea 345 Relief Cream:</strong> A lightweight, soothing gel-cream enriched with Niacinamide, Panthenol, and Centella Asiatica.</li>'
    .'<li><strong>Dr.Althea – 345 Relief Cream Mist Spray:</strong> The Dr. Althea 345 Relief Cream Mist features a dual-layer formula.</li>'
    .'</ul><p><strong>Key Benefits:</strong></p>';

function smbOn(array $settings = []): void
{
    app(BuyTogetherSettings::class)->save(array_merge(['on' => true, 'count' => 4], $settings));
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
    Cache::flush();
}

function smbCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function smbProduct(string $name, array $cats, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'total_sales' => $sales,
    ], $extra));

    if ($cats !== []) {
        $p->categories()->sync(array_map(fn (Category $c) => $c->id, $cats));
    }

    return $p;
}

/** The live Dr. Althea row, the set UNRECORDED (a plain product named "... Set"). */
function smbShop(): array
{
    $b = Brand::create(['slug' => 'dr-althea-'.Str::random(4), 'name' => 'Dr. Althea']);
    $o = ['brand_id' => $b->id];

    return [
        'brand' => $b,
        'cream' => smbProduct('Dr.Althea - 345 Relief Cream', [smbCat('Moisturizers')], 50, $o),
        'set' => smbProduct('Dr. Althea 345 Relief Cream + Mist Spray Set', [smbCat('Skincare Sets')], 40, $o + ['price' => 17900, 'description' => SMB_SET_DESC]),
        'mist' => smbProduct('Dr.Althea - 345 Relief Cream Mist Spray', [smbCat('Toners')], 30, $o),
        'retinol' => smbProduct('Dr. Althea - 0.1% Gentle Retinol Serum', [smbCat('Serums')], 20, $o),
        'eye' => smbProduct('Dr. Althea - Pro Lab Eye Cream', [smbCat('Eye Care')], 15, $o),
        'mini' => smbProduct('Dr. Althea - 345 Relief Cream Mist Mini', [smbCat('Toners')], 10, $o),
        'sun' => smbProduct('Dr. Althea - Pro Lab Sunscreen SPF50', [smbCat('Sunscreens')], 5, $o),
    ];
}

function smbSection(Product $product): array
{
    $fresh = Product::with(['categories:id,name,slug,path,parent_id,depth', 'variants', 'brand:id,name,slug'])->find($product->id);

    return app(BuyTogether::class)->forProduct($fresh);
}

/** @return array{ids: list<int>, button: string, bt: int} what the page draws */
function smbPage(Product $product): array
{
    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
    $start = strpos($html, '<section class="kbb-fbt');
    expect($start)->not->toBeFalse();
    $section = substr($html, $start, strpos($html, '</section>', $start) - $start);
    preg_match_all('/class="bt-cb" value="(\d+)"/', $section, $m);
    preg_match('/<span class="bt-label"[^>]*>([^<]*)</', $section, $b);
    preg_match('/data-bt="(\d+)"/', $section, $d);

    return ['ids' => array_map('intval', $m[1]), 'button' => trim($b[1] ?? ''), 'bt' => (int) ($d[1] ?? 0)];
}

it('the exact live row: on the cream page the set leads, the cream and the mist spray are gone, the row is refilled to 4', function () {
    /*
     * Defect: [cream, Cream + Mist Spray Set, Cream Mist Spray, Retinol Serum]
     * -- two of the four cards were inside the second.
     */
    $s = smbShop();
    smbOn();

    $ids = smbSection($s['cream'])['products']->pluck('id')->all();

    expect($ids[0])->toBe($s['set']->id)
        ->and($ids)->not->toContain($s['cream']->id)
        ->and($ids)->not->toContain($s['mist']->id)
        ->and($ids)->toContain($s['retinol']->id)
        ->and($ids)->toHaveCount(4)
        ->and(array_count_values($ids))->each->toBe(1);

    // The mist spray's own page: the set holds it too, so the set is found
    // (by its name and description, not by a recording), leads there, and the
    // cream inside it is not drawn.
    $mist = smbSection($s['mist'])['products']->pluck('id')->all();
    expect($mist[0])->toBe($s['set']->id)
        ->and($mist)->not->toContain($s['cream']->id)
        ->and($mist)->not->toContain($s['mist']->id)
        ->and($mist)->toHaveCount(4);
});

it('draws, counts and adds exactly the cards shown -- the basket holds no second cream', function () {
    $s = smbShop();
    smbOn();

    $page = smbPage($s['cream']);
    expect($page['ids'][0])->toBe($s['set']->id)
        ->and($page['ids'])->toHaveCount(4)
        ->and($page['button'])->toBe('Buy 4 items together')
        ->and($page['bt'])->toBe($s['cream']->id);

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
    app(CartService::class)->forget();
    test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/api/cart/add-together', [
            'items' => array_map(fn (int $id) => ['product_id' => $id, 'variant_id' => null], $page['ids']),
            'main_id' => $page['bt'],
        ])->assertOk()->assertJsonPath('added_count', 4);

    $lines = $cart->items()->pluck('product_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
    expect($lines)->toBe(collect($page['ids'])->sort()->values()->all())
        ->and($lines)->not->toContain($s['cream']->id)
        ->and($lines)->not->toContain($s['mist']->id);
});

it('reads "A + B" set names, the elided second part included -- and nothing looser', function () {
    $set = 'Dr. Althea 345 Relief Cream + Mist Spray Set';

    expect(SetMembership::byName('Dr. Althea', $set, 'Dr.Althea - 345 Relief Cream'))->toBeTrue()
        ->and(SetMembership::byName('Dr. Althea', $set, 'Dr.Althea - 345 Relief Cream Mist Spray'))->toBeTrue()
        ->and(SetMembership::byName('Dr. Althea', $set, 'Dr. Althea - 345 Relief Cream Mist Mini'))->toBeFalse()
        ->and(SetMembership::byName('Dr. Althea', $set, 'Dr. Althea - 345 Relief Serum'))->toBeFalse()
        // "& / and" too, and sizes do not matter.
        ->and(SetMembership::byName('Anua', 'Anua - Heartleaf 77% Soothing Toner & Heartleaf 70 Daily Lotion Duo', 'Anua - Heartleaf 77% Soothing Toner 250ml'))->toBeTrue()
        ->and(SetMembership::byName('Celimax', 'Celimax - Retinal Booster and Cream Set', 'Celimax - Retinal Booster Cream'))->toBeTrue()
        ->and(SetMembership::byName('numbuzin', 'numbuzin - No.2 Rose PDRN Collagen Plumping Sheet Mask 5pcs Set', 'numbuzin - No.2 Rose PDRN Collagen Plumping Sheet Mask'))->toBeTrue()
        // A set OF cream masks does not hold the cream; a different number is a different product.
        ->and(SetMembership::byName('Dr. Althea', 'Dr. Althea - 345 Relief Cream Mask Set', 'Dr.Althea - 345 Relief Cream'))->toBeFalse()
        ->and(SetMembership::byName('Dr. Althea', 'Dr. Althea - 147 Barrier Cream Set', 'Dr.Althea - 345 Relief Cream'))->toBeFalse()
        ->and(SetMembership::isSetLike('simple', 'Beauty of Joseon - Glow Charging Rice Duo'))->toBeTrue()
        ->and(SetMembership::isSetLike('simple', 'Beauty of Joseon - Relief Sun'))->toBeFalse()
        ->and(SetMembership::isSetLike('set', 'Anything'))->toBeTrue();
});

it('reads a description that lists the set\'s contents -- line by line, never a phrase inside prose', function () {
    /*
     * Live "Dr. Althea - 345 Relief Complete Set": the name says nothing,
     * the description lists the products.
     */
    $s = smbShop();
    $serum = smbProduct('Dr. Althea - 345 Relief Serum', [smbCat('Serums')], 35, ['brand_id' => $s['brand']->id]);
    $s['set']->forceFill([
        'name' => 'Dr. Althea - 345 Relief Complete Set',
        'description' => '<p>What’s Included in the Set:</p><ol><li>Dr. Althea 345 Relief Serum: a light serum with niacinamide and panthenol.</li><li>2. Dr Althea 345 Relief Cream (50ml)</li></ol>'
            .'<p>Pairs beautifully with the 345 Relief Cream Mist Spray for a dewy finish.</p>',
    ])->save();
    smbOn();

    $ids = smbSection($s['cream'])['products']->pluck('id')->all();
    expect($ids[0])->toBe($s['set']->id)
        ->and($ids)->not->toContain($serum->id)
        ->and($ids)->not->toContain($s['cream']->id)
        // Named only inside a sentence: not a member, still offered.
        ->and($ids)->toContain($s['mist']->id)
        ->and($ids)->toHaveCount(4);

    $lines = SetMembership::linesOf('<p><strong>Dr.Althea – 345 Relief Cream Mist Spray:</strong> The Dr. Althea 345 Relief Cream Mist</p>');
    expect(SetMembership::byDescription('Dr. Althea', $lines, 'Dr.Althea - 345 Relief Cream Mist Spray'))->toBeTrue()
        // "345 Relief Cream" is the start of that line, not the line.
        ->and(SetMembership::byDescription('Dr. Althea', $lines, 'Dr.Althea - 345 Relief Cream'))->toBeFalse();
});

it('never links another brand\'s product with the same words', function () {
    $s = smbShop();
    $other = Brand::create(['slug' => 'copy-'.Str::random(4), 'name' => 'Copycat']);
    $lookalike = smbProduct('Copycat - 345 Relief Cream Mist Spray', [smbCat('Toners')], 99999, ['brand_id' => $other->id]);
    smbOn(['same_brand' => false]);

    $links = SetMembership::links(
        [1 => ['brand_id' => 7, 'brand' => 'Dr. Althea', 'name' => 'Dr. Althea 345 Relief Cream + Mist Spray Set', 'description' => SMB_SET_DESC, 'recorded' => []]],
        [2 => ['brand_id' => 8, 'brand' => 'Copycat', 'name' => 'Copycat - 345 Relief Cream Mist Spray'],
            3 => ['brand_id' => 7, 'brand' => 'Dr. Althea', 'name' => 'Dr.Althea - 345 Relief Cream Mist Spray']],
    );
    expect($links)->toBe([1 => [3 => 'name']]);

    // On the page: the look-alike (the best-selling toner) stays beside the set.
    $ids = smbSection($s['cream'])['products']->pluck('id')->all();
    expect($ids[0])->toBe($s['set']->id)->and($ids)->toContain($lookalike->id)
        ->and($ids)->not->toContain($s['mist']->id);
});

it('still honours recorded contents, for a member that is not the page product', function () {
    $s = smbShop();
    // The set's name and description say nothing; only the recording does.
    $s['set']->forceFill(['name' => 'Dr. Althea - Calm Gift Box Set', 'description' => '<p>A gift.</p>', 'type' => 'set'])->save();
    ProductSetItem::create(['set_product_id' => $s['set']->id, 'member_product_id' => $s['retinol']->id, 'quantity' => 1, 'position' => 0]);
    smbOn();

    // A page product the set does not hold: it stays first, the set shows,
    // the retinol inside the set does not.
    $ids = smbSection($s['eye'])['products']->pluck('id')->all();
    expect($ids[0])->toBe($s['eye']->id);

    if (in_array($s['set']->id, $ids, true)) {
        expect($ids)->not->toContain($s['retinol']->id);
    }

    expect(SetMembership::links(
        [5 => ['brand_id' => 1, 'brand' => 'X', 'name' => 'X - Gift Box Set', 'description' => null, 'recorded' => [6]]],
        [6 => ['brand_id' => 2, 'brand' => 'Y', 'name' => 'Y - Anything']],
    ))->toBe([5 => [6 => 'recorded']]);
});

it('leaves a row with no set in it exactly as it was picked', function () {
    $s = smbShop();
    $s['set']->forceDelete();
    smbOn();

    $p = Product::with(['categories:id,name,slug,path,parent_id,depth', 'variants', 'brand:id,name,slug'])->find($s['cream']->id);
    $bt = app(BuyTogether::class);
    $c = app(BuyTogetherSettings::class)->all();
    $got = $bt->forProduct($p);

    // The no-set row is the pick itself, unrearranged: the page first, then
    // pick()'s order, and no page_id.
    expect($got['products']->first()->id)->toBe($s['cream']->id)
        ->and($got)->not->toHaveKey('page_id')
        ->and($got['products']->pluck('id')->slice(1)->values()->all())
        ->toBe($bt->companions($p, $c)->pluck('id')->all());
});

it('costs no query more, cold or warm, with 3 or 40 unrecorded set-like candidates', function () {
    $s = smbShop();
    $count = function () use ($s): int {
        $p = Product::with(['categories:id,name,slug,path,parent_id,depth', 'brand:id,name,slug'])->find($s['cream']->id);
        app(BuyTogetherSettings::class)->all();
        __('store.buy_together.heading');
        \App\Support\SetPricing::forget();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        app(BuyTogether::class)->forProduct($p);

        return $n;
    };
    $measure = function () use ($count): array {
        smbOn();

        return [$count(), $count()];
    };

    $small = $measure();

    foreach (range(1, 40) as $i) {
        smbProduct("Dr. Althea - 345 Relief Glow Kit {$i}", [smbCat('Skincare Sets')], $i, ['brand_id' => $s['brand']->id, 'description' => SMB_SET_DESC]);
    }

    // The same union as before (description rides on it), then the brands.
    expect($small)->toBe([3, 2])->and($measure())->toBe($small);
});
