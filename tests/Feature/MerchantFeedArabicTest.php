<?php

declare(strict_types=1);

/*
 * The Arabic Google Shopping feed (the owner, 23 October: "after that i asked
 * for the arabic"). /feeds/google-merchant-ar.xml is the same feed as the
 * English one -- the same ids, prices, stock and photos -- with the Arabic
 * name, description, brand and category the /ar/ product page shows, and links
 * to the /ar/ page. Without it Merchant Center had only English titles, so an
 * Arabic shopper saw English listings that opened the English page.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\Seo\MerchantFeed;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\MerchantFeedRoutes;

beforeEach(function () {
    MerchantFeedRoutes::wire();
    DB::table('products')->update(['status' => 'draft']);
    Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbeautybliss.test']);
    Setting::flushMap();
    \Illuminate\Support\Facades\Cache::flush();
});

function mfArProduct(string $sku, array $attrs = []): Product
{
    return Product::create(array_merge([
        'slug' => strtolower($sku), 'name' => 'Rice Toner '.$sku, 'status' => 'publish', 'is_visible' => true,
        'type' => 'simple', 'price' => 10000, 'stock_status' => 'instock', 'sku' => $sku,
        'description' => '<p>A calming toner.</p>', 'image' => '/wp-content/uploads/2021/05/'.$sku.'.jpg',
    ], $attrs));
}

function mfArPublish(string $group, int $id, string $field, string $value): void
{
    Translation::create(['locale' => 'ar', 'group' => $group, 'item_id' => $id, 'field' => $field, 'value' => $value, 'status' => 'published']);
    TranslationStore::flush();
}

/** @return array<string, array<string, string>> */
function mfArItems(string $url): array
{
    $doc = simplexml_load_string(test()->get($url)->assertOk()->getContent());
    expect($doc)->not->toBeFalse();
    $out = [];
    foreach ($doc->channel->item as $item) {
        $row = [];
        foreach ($item->children('http://base.google.com/ns/1.0') as $k => $v) {
            $row[$k] ??= (string) $v;
        }
        $out[$row['id']] = $row;
    }

    return $out;
}

it('serves Arabic names, descriptions, brands and categories with /ar/ links, and English where nothing is translated', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'mf-ar-brand', 'name' => 'Haruharu']);
    $cat = Category::create(['slug' => 'mf-ar-cat', 'name' => 'Toners']);
    $p = mfArProduct('MFAR-1', ['brand_id' => $brand->id, 'category_id' => $cat->id]);
    $p->categories()->attach($cat->id);
    mfArProduct('MFAR-2');
    mfArPublish('products', $p->id, 'name', 'تونر الأرز');
    mfArPublish('products', $p->id, 'description', '<p>تونر مهدّئ للبشرة.</p>');
    mfArPublish('brands', $brand->id, 'name', 'هاروهارو');
    mfArPublish('categories', $cat->id, 'name', 'تونر');

    $ar = mfArItems('/feeds/google-merchant-ar.xml');
    $en = mfArItems('/feeds/google-merchant.xml');

    // MUTATION: pass 'en' from the controller for the -ar route. RED (English title).
    expect($ar['MFAR-1']['title'])->toBe('تونر الأرز')
        ->and($ar['MFAR-1']['description'])->toBe('تونر مهدّئ للبشرة.')
        ->and($ar['MFAR-1']['brand'])->toBe('هاروهارو')
        ->and($ar['MFAR-1']['product_type'])->toBe('تونر')
        ->and($ar['MFAR-1']['link'])->toBe('https://kbeautybliss.test/ar/product/mfar-1/')
        // An untranslated product still lists, in English, on its /ar/ page.
        ->and($ar['MFAR-2']['title'])->toBe('Rice Toner MFAR-2')
        ->and($ar['MFAR-2']['link'])->toBe('https://kbeautybliss.test/ar/product/mfar-2/')
        // Same ids, prices, stock and photos as the English feed.
        ->and(array_keys($ar))->toBe(array_keys($en))
        ->and($ar['MFAR-1']['price'])->toBe($en['MFAR-1']['price'])
        ->and($ar['MFAR-1']['availability'])->toBe($en['MFAR-1']['availability'])
        ->and($ar['MFAR-1']['image_link'])->toBe($en['MFAR-1']['image_link'])
        // The English feed is untouched by the Arabic rows.
        ->and($en['MFAR-1']['title'])->toBe('Rice Toner MFAR-1')
        ->and($en['MFAR-1']['link'])->toBe('https://kbeautybliss.test/product/mfar-1/');
});

it('answers 404 for the Arabic feed while the Arabic shop is off, and keeps the English one', function () {
    mfArProduct('MFAR-OFF');

    test()->get('/feeds/google-merchant-ar.xml')->assertNotFound();
    test()->get('/feeds/google-merchant.xml')->assertOk();
});

it('costs the same number of queries for 3 Arabic products as for 60', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'mf-ar-flat', 'name' => 'Flat']);
    $cat = Category::create(['slug' => 'mf-ar-flat', 'name' => 'Flat']);
    $count = function (int $n) use ($brand, $cat): int {
        DB::table('products')->where('sku', 'like', 'MFARF-%')->delete();
        for ($i = 0; $i < $n; $i++) {
            $p = mfArProduct('MFARF-'.$i, ['brand_id' => $brand->id, 'category_id' => $cat->id]);
            $p->categories()->attach($cat->id);
            mfArPublish('products', $p->id, 'description', '<p>وصف '.$i.'</p>');
        }
        TranslationStore::flush();
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        expect(app(MerchantFeed::class)->build(null, MerchantFeed::SCHEME_SKU, 'ar')['products'])->toBe($n);

        return $q;
    };

    // MUTATION: read longFor() per product instead of per chunk. RED.
    expect($count(60))->toBe($count(3));
});

it('picks up a new Arabic translation without waiting for the cache to expire', function () {
    ArabicShop::on();
    $p = mfArProduct('MFAR-C');
    expect(mfArItems('/feeds/google-merchant-ar.xml')['MFAR-C']['title'])->toBe('Rice Toner MFAR-C');

    $this->travel(2)->seconds();
    // MUTATION: drop translationStamp() from the Arabic cache key. RED (stale English title).
    mfArPublish('products', $p->id, 'name', 'تونر جديد');
    expect(mfArItems('/feeds/google-merchant-ar.xml')['MFAR-C']['title'])->toBe('تونر جديد');
});
