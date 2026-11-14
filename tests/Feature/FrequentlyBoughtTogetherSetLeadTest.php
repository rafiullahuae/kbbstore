<?php

declare(strict_types=1);

/*
 * "Frequently bought together" never offers the page's product beside a set
 * that already holds it.                                   (14 November 2026)
 *
 * The owner, with a screenshot of the live page for "Anua - PDRN Collagen
 * Melting Patch For Glass Skin": the section drew the patch itself first, then
 * "Anua - PDRN + Hyaluron Ultimate Glass Skin Set" -- which CONTAINS the patch
 * -- then a toner pad and a serum, under "Buy 4 items together". One press put
 * the patch in the bag twice, once alone and once inside the set:
 *
 *   "if any product has set. then it should not show itself in the together
 *    purchase list. bcz it's already in the set. please give me the patch for
 *    it."
 *
 * Now the set leads in the product's place (BuyTogether::choose()), the button
 * counts and adds exactly the cards drawn, and the bundle check still reads
 * the PAGE product's own companion list (main_id), so nothing new can be
 * discounted.
 *
 * Mutations, each red here:
 *   - BuyTogether::forProduct(): `collect([$product])` instead of
 *     `collect([$picked['set'] ?? $product])` → "the set leads" red (the patch
 *     is card one again, the button reads 5, the cart holds the patch alone).
 *   - BuyTogether::choose(): reject only the lead set, not every holder set →
 *     a second set holding the patch is drawn ("leads with the set" red).
 *   - BuyTogether::choose(): drop the wider pick → three cards, not four
 *     ("still draws How many products" red).
 *   - fbt.blade.php: data-bt back to `$btMain->id` → main_id is the set, and
 *     the "validated against the page product" case red.
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

function slFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
}

function slOn(array $settings = []): void
{
    app(BuyTogetherSettings::class)->save(array_merge(['on' => true, 'count' => 4], $settings));
    slFlush();
}

function slCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function slProduct(string $name, array $cats, int $sales, array $extra = []): Product
{
    $p = Product::create(array_merge([
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'total_sales' => $sales,
    ], $extra));

    if ($cats !== []) {
        $p->categories()->sync(array_map(fn (Category $c) => $c->id, $cats));
    }

    return $p;
}

/** The live shape: the PDRN patch, the PDRN set holding it, a toner pad, a serum. */
function slShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::random(4), 'name' => 'Anua']);
    $patch = slProduct('Anua - PDRN Collagen Melting Patch For Glass Skin', [slCat('Face Masks')], 10, ['brand_id' => $anua->id, 'price' => 6900]);
    $set = slProduct('Anua - PDRN + Hyaluron Ultimate Glass Skin Set', [slCat('Skincare Sets')], 5, ['brand_id' => $anua->id, 'type' => 'set', 'price' => 19900]);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $patch->id, 'quantity' => 1, 'position' => 0]);

    return [
        'patch' => $patch,
        'set' => $set,
        'serum' => slProduct('Anua - PDRN Hyaluronic Acid Capsule 100 Serum', [slCat('Face Serums')], 9, ['brand_id' => $anua->id, 'price' => 8900]),
        'pad' => slProduct('Anua - Heartleaf 77 Clear Toner Pad', [slCat('Toners')], 8, ['brand_id' => $anua->id, 'price' => 7500]),
        'cream' => slProduct('Anua - PDRN Hyaluronic Acid 100 Moisturizing Cream', [slCat('Moisturizers')], 7, ['brand_id' => $anua->id, 'price' => 8000]),
        // Outsold by the whole demo catalogue and on no paired shelf: nothing
        // the patch's section could ever have offered.
        'lone' => slProduct('Lone Lip Tint', [], 0, ['price' => 4000]),
    ];
}

function slSection(Product $product): array
{
    $fresh = Product::with(['categories:id,name,slug,path,parent_id,depth', 'variants', 'brand:id,name,slug'])->find($product->id);

    return app(BuyTogether::class)->forProduct($fresh);
}

/** @return array{ids: list<int>, button: string, bt: int, html: string} what the page draws */
function slPage(Product $product): array
{
    $html = (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
    $start = strpos($html, '<section class="kbb-fbt');
    expect($start)->not->toBeFalse();
    $section = substr($html, $start, strpos($html, '</section>', $start) - $start);
    preg_match_all('/class="bt-cb" value="(\d+)"/', $section, $m);
    preg_match('/<span class="bt-label"[^>]*>([^<]*)</', $section, $b);
    preg_match('/data-bt="(\d+)"/', $section, $d);

    return ['ids' => array_map('intval', $m[1]), 'button' => trim($b[1] ?? ''), 'bt' => (int) ($d[1] ?? 0), 'html' => $section];
}

function slCart(): Cart
{
    return Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active', 'shipping_country' => 'AE', 'last_activity_at' => now()]);
}

/** Exactly the request fbt.js sends for the ticked cards: their ids and the section's data-bt. */
function slPress(Cart $cart, array $ids, int $mainId)
{
    app(CartService::class)->forget();

    return test()->withCredentials()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->postJson('/api/cart/add-together', [
            'items' => array_map(fn (int $id) => ['product_id' => $id, 'variant_id' => null], $ids),
            'main_id' => $mainId,
        ]);
}

it('leads with the set that holds the page product, and never draws the product itself', function () {
    /*
     * Defect: the patch's page drew [patch, set-with-the-patch, pad, serum] --
     * the patch twice over in one "Buy 4 items together".
     */
    $s = slShop();
    slOn();

    $names = slSection($s['patch'])['products']->pluck('name')->all();

    expect($names[0])->toBe('Anua - PDRN + Hyaluron Ultimate Glass Skin Set')
        ->and($names)->not->toContain('Anua - PDRN Collagen Melting Patch For Glass Skin')
        // Still "How many products" (4): the set takes the patch's slot.
        ->and($names)->toHaveCount(4)
        ->and(array_count_values($names))->each->toBe(1);

    // A SECOND set holding the patch is not drawn beside the first: it would
    // put the patch in the bag twice, the same defect one card later.
    $duo = slProduct('Anua - PDRN Patch Duo Set', [slCat('Skincare Sets')], 6, ['brand_id' => $s['set']->brand_id, 'type' => 'set']);
    ProductSetItem::create(['set_product_id' => $duo->id, 'member_product_id' => $s['patch']->id, 'quantity' => 2, 'position' => 0]);
    Cache::flush();
    slOn(['count' => 6]);

    $ids = slSection($s['patch'])['products']->pluck('id')->all();
    expect(array_values(array_intersect($ids, [$s['set']->id, $duo->id])))->toHaveCount(1)
        ->and($ids)->not->toContain($s['patch']->id)
        ->and(array_count_values($ids))->each->toBe(1);
});

it('draws, counts and adds exactly the cards shown -- the cart has no second patch', function () {
    $s = slShop();
    slOn();

    $page = slPage($s['patch']);

    expect($page['ids'][0])->toBe($s['set']->id)
        ->and($page['ids'])->not->toContain($s['patch']->id)
        ->and($page['ids'])->toHaveCount(4)
        ->and($page['button'])->toBe('Buy 4 items together')
        // The page is still the patch's: its view count and the bundle check.
        ->and($page['bt'])->toBe($s['patch']->id)
        ->and($page['html'])->not->toContain('data-bt-var');

    $cart = slCart();
    slPress($cart, $page['ids'], $page['bt'])->assertOk()->assertJsonPath('added_count', 4);

    $lines = $cart->items()->get();
    expect($lines->pluck('product_id')->map(fn ($i) => (int) $i)->sort()->values()->all())
        ->toBe(collect($page['ids'])->sort()->values()->all())
        ->and($lines->where('product_id', $s['patch']->id))->toHaveCount(0);
});

it('leaves a page whose product is in no set exactly as before: the product first', function () {
    $s = slShop();
    slOn();

    $pad = slSection($s['pad']);
    expect($pad['products']->first()->id)->toBe($s['pad']->id)
        ->and($pad)->not->toHaveKey('page_id');

    // And with the sets source off, the patch's own page is the old one too.
    slOn(['sets' => false]);
    Cache::flush();
    $patch = slSection($s['patch']);
    expect($patch['products']->first()->id)->toBe($s['patch']->id)
        ->and($patch['products']->pluck('id'))->not->toContain($s['set']->id)
        ->and($patch)->not->toHaveKey('page_id');
});

it('keeps the product first when the only set holding it is sold out', function () {
    // A sold-out set (drawn greyed, "Hide sold-out products" off) cannot be
    // bought, so it cannot stand in for the product.
    $s = slShop();
    $s['set']->forceFill(['stock_status' => 'outofstock'])->save();
    slOn(['hide_oos' => false]);
    Cache::flush();

    $ids = slSection($s['patch'])['products']->pluck('id')->all();
    expect($ids[0])->toBe($s['patch']->id)->and($ids)->toContain($s['set']->id);
});

it('groups a set-led press for the discount, validated against the page product -- never widened', function () {
    /*
     * With the bundle discount on, the set-led press is a bundle: main_id is
     * the patch, every line (the set included) is checked against the PATCH's
     * own companions. Swap one card for a product the patch's section could
     * never offer and nothing is grouped, exactly as before.
     */
    $s = slShop();
    slOn(['discount_on' => true, 'tier_3' => 5, 'tier_4' => 10, 'tier_5' => 15]);

    $page = slPage($s['patch']);
    $cart = slCart();
    slPress($cart, $page['ids'], $page['bt'])->assertOk();
    $lines = $cart->items()->get();
    expect($lines)->toHaveCount(4)
        ->and($lines->pluck('bt_group')->filter()->unique())->toHaveCount(1)
        ->and($lines->whereNull('bt_group'))->toHaveCount(0);

    $forged = $page['ids'];
    $forged[3] = $s['lone']->id;
    $cart2 = slCart();
    slPress($cart2, $forged, $s['patch']->id)->assertOk();
    expect($cart2->items()->count())->toBe(4)
        ->and($cart2->items()->whereNotNull('bt_group')->count())->toBe(0);

    // The allowed set is the page product's list: the lone tint is not on it.
    expect(app(BuyTogether::class)->allowedCompanions(Product::find($s['patch']->id), [$s['set']->id, $s['lone']->id]))
        ->toBe([$s['set']->id]);
});
