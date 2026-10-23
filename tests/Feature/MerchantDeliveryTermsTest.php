<?php

declare(strict_types=1);

/*
 * WHY OUR PRODUCTS DID NOT LOOK LIKE A COMPETITOR'S ON GOOGLE (the owner, 23
 * October 2026, two search results side by side: theirs with "Free delivery",
 * ours with none, and ours describing the AGE-R Booster Pro X2 as "Shop
 * authentic Korean skincare in the UAE at KBeautyBliss...").
 *
 *  1. Store -> SEO & Meta -> Rich product results was off, so no product page
 *     told Google its delivery cost. His terms: "over 199 free delivery, below
 *     20 aed delivery fee all over UAE". Returns stay unstated: "we don't
 *     accept returns due to hygienic issues", and stating it prints "No
 *     returns" under every product.
 *  2. A product with no short description published the shop's sitewide
 *     sentence as its own description, on hundreds of pages.
 */

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function mdtMigration(): object
{
    return require database_path('migrations/2027_11_26_100000_merchant_listing_delivery_terms.php');
}

function mdtProduct(array $attrs = []): Product
{
    return Product::create(array_merge(['slug' => 'mdt-'.Str::random(8), 'name' => 'AGE-R Booster Pro X2', 'status' => 'publish',
        'is_visible' => true, 'price' => 112000, 'stock_status' => 'instock', 'type' => 'simple'], $attrs));
}

/** @return array<string, mixed> */
function mdtProductNode(Product $p): array
{
    $html = test()->get('/product/'.$p->slug.'/')->assertOk()->getContent();
    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);

    foreach ($m[1] as $json) {
        $d = json_decode($json, true);
        foreach (isset($d['@graph']) ? $d['@graph'] : [$d] as $node) {
            if (($node['@type'] ?? null) === 'Product') {
                return $node + ['_html' => $html];
            }
        }
    }

    return ['_html' => $html];
}

function mdtFresh(): void
{
    Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
    Cache::flush();
}

function mdtTrading(): void
{
    DB::table('orders')->insert(['order_number' => 'MDT-1', 'email' => 'mdt@example.test', 'created_at' => now(), 'updated_at' => now()]);
}

it('writes his delivery terms on a shop that has taken orders, and states no returns policy', function () {
    mdtTrading();
    $cheap = mdtProduct(['price' => 9900]);
    $dear = mdtProduct(['price' => 112000]);

    mdtMigration()->up();
    mdtFresh();

    expect(DB::table('settings')->whereIn('key', ['enable_merchant', 'merchant_ship_cost', 'merchant_ship_free_over'])->pluck('value', 'key')->all())
        ->toEqual(['enable_merchant' => '1', 'merchant_ship_cost' => '20', 'merchant_ship_free_over' => '199']);

    $low = mdtProductNode($cheap)['offers'];
    $high = mdtProductNode($dear)['offers'];

    // MUTATION: leave enable_merchant out of the migration. RED (no shippingDetails).
    expect($low['shippingDetails']['shippingRate']['value'])->toBe('20.00')
        ->and($low['shippingDetails']['shippingDestination']['addressCountry'])->toBe('AE')
        ->and($high['shippingDetails']['shippingRate']['value'])->toBe('0.00')
        // No returns: nothing said, so Google prints no "No returns".
        ->and($low)->not->toHaveKey('hasMerchantReturnPolicy');
});

it('keeps an amount he has already typed, and does nothing on a shop that has never sold', function () {
    mdtMigration()->up();
    expect(DB::table('settings')->where('key', 'enable_merchant')->exists())->toBeFalse();

    mdtTrading();
    Setting::updateOrCreate(['key' => 'merchant_ship_cost'], ['value' => '15']);
    mdtMigration()->up();

    expect(DB::table('settings')->where('key', 'merchant_ship_cost')->value('value'))->toBe('15')
        ->and(DB::table('settings')->where('key', 'enable_merchant')->value('value'))->toBe('1');

    mdtMigration()->down();
    expect(DB::table('settings')->where('key', 'merchant_ship_cost')->value('value'))->toBe('15')
        ->and(DB::table('settings')->where('key', 'enable_merchant')->exists())->toBeFalse();
});

it('describes a product with no short description in its own words, not the shop\'s', function () {
    $long = '<p>Benefits: This device is designed for various skin needs, delivering efficient and elevated care in a single use. With 6 modes and LED indication, you can create your own routine for a more complete care experience.</p>';
    $p = mdtProduct(['short_description' => null, 'description' => $long]);

    $node = mdtProductNode($p);
    preg_match('#<meta name="description" content="([^"]*)"#', $node['_html'], $meta);
    $desc = html_entity_decode($meta[1]);

    // MUTATION: delete the description fallback in ProductSeo::rawDescription(). RED.
    expect($desc)->toStartWith('Benefits: This device is designed for various skin needs')
        ->and($desc)->toEndWith('…')
        ->and(mb_strlen($desc))->toBeLessThanOrEqual(160)
        ->and($desc)->not->toContain('Shop authentic Korean skincare')
        ->and($node['description'])->toBe($desc);

    // A product WITH a short description is unchanged.
    $q = mdtProduct(['short_description' => '<p>A plumping toner.</p>', 'description' => $long]);
    preg_match('#<meta name="description" content="([^"]*)"#', mdtProductNode($q)['_html'], $m2);
    expect(html_entity_decode($m2[1]))->toBe('A plumping toner.');
});
