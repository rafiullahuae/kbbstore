<?php

declare(strict_types=1);

/*
 * "Frequently bought together" (Lane FBT, 14 November 2026). The owner:
 *
 *   "on the product page, The buy these together section should be named
 *    frequently bought together and it should show related products e.g if
 *    the product page which is open is also in a set then that set should be
 *    shown, and if it is from a same range of brand then those range products
 *    should be shown. And if it has a matching moisturizer or toner or eye
 *    cream those should be shown"
 *
 * App\Services\BuyTogether::pick() is the order; App\Support\ProductRange
 * reads the range from the name. Each case says what the defect looked like.
 *
 * Mutations, each red here:
 *   - pick(): drop `$takeUpTo($sets, 1)` before the range → "shows the set first" red.
 *   - ProductRange::key(): stop stripping the brand's words → "same range" red
 *     (every Anua product would share the key "anua").
 *   - BuyTogetherPairs::DEFAULT_PAIRS['toner'] back to serum-first → "toner page" red.
 *   - cold(): drop the `inRange` part from the union → "same range" red.
 *   - part(): drop the stock filter → "never sold out" red.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\BuyTogether;
use App\Services\BuyTogetherPairs;
use App\Services\BuyTogetherSettings;
use App\Services\SettingsService;
use App\Support\ProductRange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function fbtFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    Cache::forget(BuyTogetherPairs::CACHE_KEY);
}

function fbtOn(array $settings = []): void
{
    app(BuyTogetherSettings::class)->save(array_merge(['on' => true], $settings));
    fbtFlush();
}

function fbtCat(string $name): Category
{
    return Category::query()->where('name', $name)->first()
        ?? Category::create(['slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'name' => $name]);
}

function fbtProduct(string $name, array $cats, int $sales, array $extra = []): Product
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

/** @return list<string> names the section draws, the page's product first */
function fbtNames(Product $product, array $settings = []): array
{
    fbtOn($settings);
    $fresh = Product::with(['categories:id,name,slug,path,parent_id,depth', 'variants', 'brand:id,name,slug'])->find($product->id);

    return app(BuyTogether::class)->forProduct($fresh)['products']->pluck('name')->all();
}

/**
 * An Anua Heartleaf toner, its range (a cleansing oil, a cream), an Anua eye
 * cream from another range, a set holding the toner, and best sellers from
 * other brands on every shelf -- each outselling the Anua products, so a
 * missing preference puts them first rather than somewhere unseen.
 */
function fbtShop(): array
{
    $anua = Brand::create(['slug' => 'anua-'.Str::random(4), 'name' => 'Anua']);
    $other = Brand::create(['slug' => 'other-'.Str::random(4), 'name' => 'Other Brand']);
    $toner = fbtCat('Toners');
    $moist = fbtCat('Moisturizers');
    $eye = fbtCat('Eye Care');
    $serum = fbtCat('Face Serums');
    $oil = fbtCat('Cleansing Oils');
    $sun = fbtCat('Sunscreens');
    $sets = fbtCat('Skincare Sets');

    $self = fbtProduct('Anua - Heartleaf 77% Soothing Toner 250ml', [$toner], 10, ['brand_id' => $anua->id]);
    $set = fbtProduct('Anua - Heartleaf Glow Duo Set', [$sets], 1, ['brand_id' => $anua->id, 'type' => 'set']);
    ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $self->id, 'quantity' => 1, 'position' => 0]);

    return [
        'self' => $self,
        'set' => $set,
        'oil' => fbtProduct('Anua - Heartleaf Pore Control Cleansing Oil', [$oil], 5, ['brand_id' => $anua->id]),
        'cream' => fbtProduct('Anua - Heartleaf 70% Intense Calming Cream', [$moist], 4, ['brand_id' => $anua->id]),
        'eye' => fbtProduct('Anua - Peach 77 Niacin Eye Cream', [$eye], 3, ['brand_id' => $anua->id]),
        'xMoist' => fbtProduct('Big Moist Best', [$moist], 900000, ['brand_id' => $other->id]),
        'xEye' => fbtProduct('Big Eye Best', [$eye], 800000, ['brand_id' => $other->id]),
        'xSerum' => fbtProduct('Big Serum Best', [$serum], 700000, ['brand_id' => $other->id]),
        'xSun' => fbtProduct('Big Sun Best', [$sun], 600000, ['brand_id' => $other->id]),
        // Never offered, each one a best seller that would otherwise lead.
        'oosRange' => fbtProduct('Anua - Heartleaf Quercetinol Pore Deep Cleansing Foam', [], 99000000, ['brand_id' => $anua->id, 'stock_status' => 'outofstock']),
        'hiddenRange' => fbtProduct('Anua - Heartleaf Silky Moisture Sun Cream', [$sun], 99000000, ['brand_id' => $anua->id, 'is_visible' => false]),
        'cats' => compact('toner', 'moist', 'eye', 'serum', 'oil', 'sun', 'sets'),
        'anua' => $anua,
    ];
}

it('reads a range from "Brand - Range Product size", inside the brand only', function () {
    // The real shapes of the live catalogue's names (docs/ingredients-research).
    expect(ProductRange::key('Anua', 'Anua - Heartleaf 77% Soothing Toner'))->toBe('heartleaf')
        ->and(ProductRange::key('Anua', 'Anua - Heartleaf Pore Control Cleansing Oil'))->toBe('heartleaf')
        ->and(ProductRange::key('Round Lab', '1025 Dokdo Toner'))->toBe('1025 dokdo')
        ->and(ProductRange::key('numbuzin', 'numbuzin - No.9 NAD+ PDRN Glow Boosting Toner'))->toBe('no 9')
        // The brand row says "VT Cosmetics", the name says "VT".
        ->and(ProductRange::key('VT Cosmetics', 'VT - PDRN Essence 100'))->toBe('pdrn')
        ->and(ProductRange::key('Dr. Althea', 'Dr.Althea 345 Relief Cream'))->toBe('345 relief')
        ->and(ProductRange::key('APRILSKIN', 'APRILSKIN - Real Carrotene Acne Foam Cleanser'))->toBe('carrotene')
        // A product type is no range: "Anua - Toner" names nothing.
        ->and(ProductRange::key('Anua', 'Anua - Toner 250ml'))->toBeNull()
        ->and(ProductRange::needle('1025 dokdo'))->toBe('dokdo');
});

it('shows the set the product is in first, then its own range, then a matching routine step', function () {
    /*
     * Defect: the section showed "one best seller per complementary shelf",
     * so the Heartleaf toner's page offered three other brands' best sellers
     * and never the Heartleaf set it is sold in, nor the Heartleaf cleansing
     * oil and cream that go with it.
     */
    $s = fbtShop();

    expect(fbtNames($s['self']))->toBe([
        'Anua - Heartleaf 77% Soothing Toner 250ml',
        'Anua - Heartleaf Glow Duo Set',                 // (a) the set it is in
        'Anua - Heartleaf Pore Control Cleansing Oil',   // (b) its range
        'Anua - Heartleaf 70% Intense Calming Cream',    // (c) matching moisturiser: same range first
    ]);
});

it('fills a toner page with the routine steps after it: moisturiser and eye cream, same brand first', function () {
    /*
     * "if it has a matching moisturizer or toner or eye cream those should be
     * shown". Without the set and the range, a toner's page offers a
     * moisturiser, then an eye cream -- Anua's own before the bigger sellers
     * of other brands.
     */
    $s = fbtShop();

    expect(fbtNames($s['self'], ['sets' => false, 'range' => false, 'count' => 4]))->toBe([
        'Anua - Heartleaf 77% Soothing Toner 250ml',
        'Anua - Heartleaf 70% Intense Calming Cream',
        'Anua - Peach 77 Niacin Eye Cream',
        'Big Serum Best',
    ]);

    // And the map itself, resolved against the shop's shelves.
    fbtFlush();
    $pairs = app(BuyTogetherPairs::class);
    expect($pairs->defaultFor($s['cats']['toner']->id))->toBe([
        $s['cats']['moist']->id, $s['cats']['eye']->id, $s['cats']['serum']->id, $s['cats']['sun']->id,
    ]);
    expect($pairs->defaultFor($s['cats']['moist']->id))->toBe([
        $s['cats']['toner']->id, $s['cats']['serum']->id, $s['cats']['eye']->id, $s['cats']['sun']->id,
    ]);
});

it('lets his own category pairs lead when he has set them for the shelf', function () {
    // His hand beats the algorithm: Toners → Sunscreens only, saved by him.
    $s = fbtShop();
    fbtFlush();
    app(BuyTogetherPairs::class)->save([$s['cats']['toner']->id => [$s['cats']['sun']->id]]);
    fbtFlush();

    $names = fbtNames($s['self']);
    expect($names[1])->toBe('Big Sun Best')
        ->and($names[2])->toBe('Anua - Heartleaf Glow Duo Set');
});

it('never offers a sold-out or hidden range product, nor the product itself', function () {
    $s = fbtShop();
    $names = fbtNames($s['self'], ['count' => 6]);

    expect($names)->not->toContain('Anua - Heartleaf Quercetinol Pore Deep Cleansing Foam')
        ->not->toContain('Anua - Heartleaf Silky Moisture Sun Cream')
        ->and(array_count_values($names))->each->toBe(1)
        ->and($names[0])->toBe('Anua - Heartleaf 77% Soothing Toner 250ml');

    // A set that is hidden is not offered either.
    $s['set']->forceFill(['is_visible' => false])->save();
    expect(fbtNames($s['self']))->not->toContain('Anua - Heartleaf Glow Duo Set');
});

it('keeps a same-word product of ANOTHER brand out of the range', function () {
    // "Heartleaf" in a different brand's name is not Anua's Heartleaf range.
    $s = fbtShop();
    $b = Brand::create(['slug' => 'copy-'.Str::random(4), 'name' => 'Copycat']);
    fbtProduct('Copycat - Heartleaf Toner Pad', [], 50000000, ['brand_id' => $b->id]);

    expect(fbtNames($s['self'], ['count' => 6]))->not->toContain('Copycat - Heartleaf Toner Pad');
});

it('lets the set and range products be grouped by the one-request add', function () {
    // The bundle groups only what the section could have drawn -- with the
    // cache gone (ten minutes later), the set and the range still qualify.
    $s = fbtShop();
    fbtOn();
    Cache::flush();
    $main = Product::find($s['self']->id);

    expect(app(BuyTogether::class)->allowedCompanions($main, [$s['set']->id, $s['oil']->id]))
        ->toBe([$s['set']->id, $s['oil']->id]);
});

it('costs the same queries as before, cold and warm, with 3 or 40 range and set candidates', function () {
    /*
     * The sets and the range are two more SELECTs inside the ONE union the
     * section already ran: cold = the category list, the union, the brands
     * (3); warm = the cards and their brands (2) -- BuyTogetherTest's own
     * numbers before this lane. Forty more range products, set members and
     * shelf-mates must change neither.
     *
     * With a SET on show, each view adds App\Support\SetPricing::prime()'s
     * ONE batched read of the set's parts (a set is priced from them), as
     * every set card in the shop does -- one, however many sets. A page whose
     * product is in no set pays nothing more. Each view below is a fresh
     * request: the set-price memo is per process.
     */
    $s = fbtShop();
    $count = function () use ($s): int {
        $p = Product::with(['categories:id,name,slug,path,parent_id,depth', 'brand:id,name,slug'])->find($s['self']->id);
        app(BuyTogetherSettings::class)->all();
        __('store.buy_together.heading');
        \App\Support\SetPricing::forget();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        app(BuyTogether::class)->forProduct($p);

        return $n;
    };
    $measure = function (array $settings) use ($count): array {
        fbtOn($settings);
        Cache::flush();

        return [$count(), $count()];
    };

    $smallNoSet = $measure(['sets' => false]);
    $smallSet = $measure(['sets' => true]);

    foreach (range(1, 40) as $i) {
        fbtProduct("Anua - Heartleaf Extra {$i}", [$s['cats']['moist']], $i, ['brand_id' => $s['anua']->id]);
        $set = fbtProduct("Anua - Glow Bundle Set {$i}", [], $i, ['brand_id' => $s['anua']->id, 'type' => 'set']);
        ProductSetItem::create(['set_product_id' => $set->id, 'member_product_id' => $s['self']->id, 'quantity' => 1, 'position' => 0]);
    }

    expect($smallNoSet)->toBe([3, 2])
        ->and($smallSet)->toBe([4, 3])
        ->and($measure(['sets' => false]))->toBe($smallNoSet)
        ->and($measure(['sets' => true]))->toBe($smallSet);
});


it('is named "Frequently bought together", in English and in Arabic', function () {
    // The owner's new name for the section, and the Arabic draft beside it.
    expect(\App\Services\Translation\InterfaceStrings::english('store.buy_together.heading'))->toBe('Frequently bought together')
        ->and(\App\Services\Translation\ArabicInterfaceDrafts::all()['store.buy_together.heading'])->toBe('يُشترى معًا عادةً');

    $s = fbtShop();
    fbtOn();
    $html = (string) $this->get('/product/'.$s['self']->slug.'/')->assertOk()->getContent();
    expect($html)->toContain('>Frequently bought together</h2>');

    // The migration: the shipped Arabic swaps; his own Arabic is his.
    $migration = require database_path('migrations/2028_01_25_100000_frequently_bought_together.php');
    DB::table('translations')->where('field', 'store.buy_together.heading')->delete();
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.buy_together.heading',
        'value' => 'اشترِها معًا', 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    expect(DB::table('translations')->where('field', 'store.buy_together.heading')->value('value'))->toBe('يُشترى معًا عادةً');

    DB::table('translations')->where('field', 'store.buy_together.heading')->update(['value' => 'عنوانه هو']);
    $migration->up();
    expect(DB::table('translations')->where('field', 'store.buy_together.heading')->value('value'))->toBe('عنوانه هو');

    // A stored "off" for Matching products first moves on once; his English
    // heading moves only when it is the old standard one word for word.
    DB::table('settings')->updateOrInsert(['key' => 'bt_same_brand'], ['value' => '0']);
    DB::table('settings')->updateOrInsert(['key' => 'bt_title'], ['value' => 'Buy these together']);
    $migration->up();
    expect(DB::table('settings')->where('key', 'bt_same_brand')->value('value'))->toBe('1')
        ->and(DB::table('settings')->where('key', 'bt_title')->value('value'))->toBe('');
});
