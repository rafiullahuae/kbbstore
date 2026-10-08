<?php

declare(strict_types=1);

/*
 * Growth & Marketing → Google Shopping feed (Lane SEO).
 *
 * WHAT THE SHOP LOOKED LIKE WITHOUT IT: no product feed at all. WooCommerce's
 * feed plugin dies with WordPress at the move onto kbeautybliss.com, and Lane
 * DM found nothing in this application that produced one, so Merchant Center
 * would have had no source for Google Shopping or the free product listings.
 *
 * Every assertion below reads what /feeds/google-merchant.xml SERVES, parsed as
 * XML, never a helper's return value.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\Seo\MerchantFeed;
use Illuminate\Support\Facades\DB;
use Tests\Support\MerchantFeedRoutes;

const MF_BASE = 'https://kbeautybliss.test';
const MF_G = 'http://base.google.com/ns/1.0';

beforeEach(function () {
    MerchantFeedRoutes::wire();

    // The seeded demo catalogue is hidden from the feed's point of view, so
    // every count below is this file's own products and nothing else.
    DB::table('products')->update(['status' => 'draft']);

    foreach (['site_url' => MF_BASE] as $k => $v) {
        Setting::updateOrCreate(['key' => $k], ['value' => $v]);
    }
    Setting::flushMap();
    \Illuminate\Support\Facades\Cache::flush();
});

function mfProduct(array $attrs = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'mf-product-'.$n,
        'name' => 'MF Product '.$n,
        'status' => 'publish',
        'is_visible' => true,
        'type' => 'simple',
        'price' => 10000,
        'stock_status' => 'instock',
        'description' => '<p>A <b>calming</b> toner &amp; essence.</p>',
        'image' => '/wp-content/uploads/2021/05/mf-'.$n.'.jpg',
        'images' => ['/wp-content/uploads/2021/05/mf-'.$n.'.jpg', '/wp-content/uploads/2021/05/mf-'.$n.'-2.jpg'],
        'sku' => 'MF-'.$n,
    ], $attrs));
}

/** @return array<string, array<string, list<string>>> id => element => values */
function mfItems(?string $xml = null): array
{
    $xml ??= test()->get('/feeds/google-merchant.xml')->assertOk()->getContent();
    $doc = simplexml_load_string($xml);
    expect($doc)->not->toBeFalse();

    $out = [];
    foreach ($doc->channel->item as $item) {
        $row = [];
        foreach ($item->children(MF_G) as $name => $value) {
            $row[$name][] = (string) $value;
        }
        $out[$row['id'][0]] = $row;
    }

    return $out;
}

it('serves well-formed RSS 2.0 with the g: namespace and every attribute Merchant Center requires', function () {
    $brand = Brand::create(['slug' => 'mf-anua', 'name' => 'Anua']);
    $parent = Category::create(['slug' => 'mf-skincare', 'name' => 'Skincare']);
    $child = Category::create(['slug' => 'mf-toners', 'name' => 'Toners', 'parent_id' => $parent->id]);
    mfProduct(['sku' => 'ANUA-1', 'name' => 'Heartleaf Toner', 'brand_id' => $brand->id, 'category_id' => $child->id, 'gtin' => '8809640730665']);

    $res = test()->get('/feeds/google-merchant.xml')->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/xml');

    $xml = $res->getContent();
    expect($xml)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">');

    // Merchant Center's required set for a new product with a known GTIN.
    $item = mfItems($xml)['ANUA-1'];
    foreach (['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'brand', 'gtin', 'condition'] as $required) {
        expect($item)->toHaveKey($required);
    }

    expect($item['title'][0])->toBe('Heartleaf Toner')
        // Plain text, entities decoded once: Merchant Center rejects markup.
        ->and($item['description'][0])->toBe('A calming toner & essence.')
        ->and($item['link'][0])->toBe(MF_BASE.'/product/'.Product::where('sku', 'ANUA-1')->value('slug').'/')
        ->and($item['image_link'][0])->toStartWith(MF_BASE.'/wp-content/uploads/2021/05/')
        ->and($item['additional_image_link'])->toHaveCount(1)
        ->and($item['availability'][0])->toBe('in_stock')
        ->and($item['price'][0])->toBe('100.00 AED')
        ->and($item['brand'][0])->toBe('Anua')
        ->and($item['gtin'][0])->toBe('8809640730665')
        ->and($item['condition'][0])->toBe('new')
        // The category trail, root first. MUTATION: drop the parent walk in
        // MerchantFeed::categoryPaths() and this reads "Toners".
        ->and($item['product_type'][0])->toBe('Skincare > Toners')
        ->and($item)->not->toHaveKey('identifier_exists');
});

it('publishes only the allowlisted attributes, never a column', function () {
    mfProduct(['sku' => 'MF-ALLOW', 'wc_id' => 987654, 'total_sales' => 4321]);

    $xml = test()->get('/feeds/google-merchant.xml')->assertOk()->getContent();

    /*
     * CLAUDE.md's /api rule applied to a public file. The feed is fetched by
     * anybody who has the address; it must never be a dump of the row.
     * MUTATION: add 'wc_id' => $product->wc_id to MerchantFeed::item() and the
     * first assertion is red.
     */
    expect($xml)->not->toContain('987654')->not->toContain('4321');

    $allowed = ['id', 'item_group_id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability',
        'price', 'sale_price', 'sale_price_effective_date', 'brand', 'gtin', 'identifier_exists', 'condition', 'product_type'];

    foreach (mfItems($xml) as $item) {
        expect(array_diff(array_keys($item), $allowed))->toBe([]);
    }
});

it('leaves out what is not for sale on the shop: drafts, hidden, scheduled, noindex, priceless, pictureless', function () {
    mfProduct(['sku' => 'MF-LIVE']);
    mfProduct(['sku' => 'MF-DRAFT', 'status' => 'draft']);
    mfProduct(['sku' => 'MF-PRIVATE', 'status' => 'private']);
    mfProduct(['sku' => 'MF-HIDDEN', 'is_visible' => false]);
    mfProduct(['sku' => 'MF-LATER', 'published_at' => now()->addWeek()]);
    mfProduct(['sku' => 'MF-NOINDEX', 'seo' => ['noindex' => true]]);
    mfProduct(['sku' => 'MF-FREE', 'price' => 0]);
    mfProduct(['sku' => 'MF-NOPIC', 'image' => null, 'images' => []]);

    // MUTATION: replace ->visible() with ->query() in MerchantFeed::build() and
    // DRAFT, PRIVATE, HIDDEN and LATER appear.
    expect(array_keys(mfItems()))->toBe(['MF-LIVE']);
});

it('states a running sale as sale_price beside the regular price, with its window', function () {
    mfProduct(['sku' => 'MF-SALE', 'price' => 12000, 'sale_price' => 9000, 'sale_ends_at' => now()->addDays(5)]);
    mfProduct(['sku' => 'MF-OVER', 'price' => 12000, 'sale_price' => 9000, 'sale_ends_at' => now()->subDay()]);

    $items = mfItems();

    expect($items['MF-SALE']['price'][0])->toBe('120.00 AED')
        ->and($items['MF-SALE']['sale_price'][0])->toBe('90.00 AED')
        ->and($items['MF-SALE']['sale_price_effective_date'][0])->toContain('/');

    // A sale whose window closed is the ordinary price, as on the page.
    expect($items['MF-OVER']['price'][0])->toBe('120.00 AED')
        ->and($items['MF-OVER'])->not->toHaveKey('sale_price');
});

it('says identifier_exists=no when there is no valid GTIN on file, and maps back-orders to out of stock', function () {
    mfProduct(['sku' => 'MF-NOGTIN', 'gtin' => null, 'stock_status' => 'onbackorder']);
    mfProduct(['sku' => 'MF-BADGTIN', 'gtin' => '1234567890123']);

    $items = mfItems();

    // MUTATION: drop the identifier_exists branch in MerchantFeed::item() and
    // these items carry neither attribute -- which Merchant Center disapproves.
    expect($items['MF-NOGTIN']['identifier_exists'][0])->toBe('no')
        ->and($items['MF-BADGTIN']['identifier_exists'][0])->toBe('no')
        ->and($items['MF-BADGTIN'])->not->toHaveKey('gtin')
        // Seo::availability()'s reading, so the feed and the page's JSON-LD agree.
        ->and($items['MF-NOGTIN']['availability'][0])->toBe('out_of_stock');
});

it('lists a variable product as one item per option, grouped, each with its own price and stock', function () {
    $p = mfProduct(['sku' => 'MF-VAR', 'type' => 'variable', 'price' => null, 'name' => 'Cleansing Oil']);
    $size = DB::table('attributes')->insertGetId(['slug' => 'mf-size', 'name' => 'Size', 'created_at' => now(), 'updated_at' => now()]);

    foreach ([['30ml', 5000, 'instock'], ['100ml', 12000, 'outofstock']] as $i => [$label, $price, $stock]) {
        $v = ProductVariant::create(['product_id' => $p->id, 'sku' => 'MF-VAR-'.$label, 'price' => $price, 'stock_status' => $stock, 'position' => $i]);
        $av = DB::table('attribute_values')->insertGetId(['attribute_id' => $size, 'slug' => 'mf-'.$label, 'name' => $label, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_variant_attribute_value')->insert(['product_variant_id' => $v->id, 'attribute_value_id' => $av]);
    }

    $items = mfItems();

    // MUTATION: drop the variant branch in MerchantFeed::itemsFor() and there is
    // one item, MF-VAR, at one price for two sizes.
    expect(array_keys($items))->toBe(['MF-VAR-30ml', 'MF-VAR-100ml'])
        ->and($items['MF-VAR-30ml']['item_group_id'][0])->toBe('MF-VAR')
        ->and($items['MF-VAR-100ml']['item_group_id'][0])->toBe('MF-VAR')
        ->and($items['MF-VAR-30ml']['title'][0])->toBe('Cleansing Oil - 30ml')
        ->and($items['MF-VAR-30ml']['price'][0])->toBe('50.00 AED')
        ->and($items['MF-VAR-100ml']['price'][0])->toBe('120.00 AED')
        ->and($items['MF-VAR-100ml']['availability'][0])->toBe('out_of_stock');
});

it('never repeats an id, and escapes what it prints', function () {
    mfProduct(['sku' => 'DUP', 'name' => 'Lift & Glow "Serum" <b>']);
    $second = mfProduct(['sku' => 'DUP']);

    $xml = test()->get('/feeds/google-merchant.xml')->assertOk()->getContent();
    $items = mfItems($xml);

    expect(array_keys($items))->toBe(['DUP', 'kbb-'.$second->id])
        ->and($items['DUP']['title'][0])->toBe('Lift & Glow "Serum" <b>');
});

it('costs the same number of queries for 3 products as for 60', function () {
    $count = function (int $n): int {
        DB::table('products')->where('sku', 'like', 'MF-FLAT-%')->delete();
        $brand = Brand::firstOrCreate(['slug' => 'mf-flat'], ['name' => 'Flat']);
        $cat = Category::firstOrCreate(['slug' => 'mf-flat'], ['name' => 'Flat']);
        for ($i = 0; $i < $n; $i++) {
            $p = mfProduct(['sku' => 'MF-FLAT-'.$i, 'brand_id' => $brand->id, 'category_id' => $cat->id, 'type' => $i % 3 === 0 ? 'variable' : 'simple']);
            $p->categories()->attach($cat->id);
            if ($i % 3 === 0) {
                ProductVariant::create(['product_id' => $p->id, 'sku' => 'MF-FLAT-'.$i.'-a', 'price' => 4000, 'stock_status' => 'instock']);
            }
        }
        \App\Services\VariantPricing::invalidate();
        $q = 0;
        DB::listen(function () use (&$q) { $q++; });
        $built = app(MerchantFeed::class)->build();
        expect($built['products'])->toBe($n);

        return $q;
    };

    $three = $count(3);
    $sixty = $count(60);

    // Flat: one query per chunk of 200 and per eager-loaded relation, plus the
    // category tree. MUTATION: remove 'brand:id,name' from the eager loads and
    // the 60-product build makes 57 more queries than the 3-product one.
    expect($sixty)->toBe($three);
});

it('answers from cache until the catalogue changes, then rebuilds from the live rows', function () {
    $p = mfProduct(['sku' => 'MF-CACHE', 'image' => '/wp-content/uploads/2021/05/before.jpg', 'images' => []]);
    expect(mfItems()['MF-CACHE']['image_link'][0])->toEndWith('/before.jpg');

    // An editor save moves updated_at, which is in the cache key's stamp.
    $this->travel(2)->seconds();
    $p->update(['image' => '/wp-content/uploads/2021/05/after.jpg']);
    expect(mfItems()['MF-CACHE']['image_link'][0])->toEndWith('/after.jpg');

    // A writer that bypasses Eloquent (a rename, WebP's reference rewrite)
    // calls forget(). MUTATION: make forget() a no-op and this stays on after.jpg.
    DB::table('products')->where('id', $p->id)->update(['image' => '/wp-content/uploads/2021/05/renamed.jpg']);
    MerchantFeed::forget();
    expect(mfItems()['MF-CACHE']['image_link'][0])->toEndWith('/renamed.jpg');
});

it('answers 404 while the switch is off', function () {
    mfProduct();
    Setting::updateOrCreate(['key' => MerchantFeed::SETTING], ['value' => '0']);
    Setting::flushMap();

    test()->get('/feeds/google-merchant.xml')->assertNotFound();
});

it('serves no session cookie, so a cache in front may keep it', function () {
    mfProduct();
    $res = test()->get('/feeds/google-merchant.xml')->assertOk();

    expect($res->headers->getCookies())->toBe([])
        ->and($res->headers->get('Cache-Control'))->toContain('public');
});

/* ------------------------------------------------------------ the admin screen */

function mfAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'MF '.$role, 'email' => 'mf-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

it('shows the owner the address to paste and what the feed holds, and switches it', function () {
    mfProduct(['sku' => 'MF-ADMIN']);
    $owner = mfAdmin('owner');

    $body = test()->actingAs($owner, 'admin')->getJson('/admin-api/merchant-feed')->assertOk()->json();
    expect($body['enabled'])->toBeTrue()
        ->and($body['url'])->toBe(MF_BASE.'/feeds/google-merchant.xml')
        ->and($body['items'])->toBe(1)
        ->and($body['sample'][0]['id'])->toBe('MF-ADMIN');

    test()->actingAs($owner, 'admin')->postJson('/admin-api/merchant-feed', ['enabled' => false])->assertOk()->assertJson(['enabled' => false]);
    expect(Setting::where('key', MerchantFeed::SETTING)->value('value'))->toBe('0');
    test()->get('/feeds/google-merchant.xml')->assertNotFound();

    // A select stores one of its own options: anything but a boolean is refused.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/merchant-feed', ['enabled' => 'yes'])->assertStatus(422);
});

it('fails closed for a role without marketing.feed, and for nobody signed in', function () {
    // MUTATION: delete the two admin-api/merchant-feed lines from
    // AdminCapabilities::ROUTES and the editor gets a 200.
    test()->actingAs(mfAdmin('editor'), 'admin')->getJson('/admin-api/merchant-feed')->assertForbidden();
    test()->actingAs(mfAdmin('editor'), 'admin')->postJson('/admin-api/merchant-feed', ['enabled' => false])->assertForbidden();

    auth('admin')->logout();
    expect(test()->getJson('/admin-api/merchant-feed')->status())->toBeIn([401, 302, 403]);
});
