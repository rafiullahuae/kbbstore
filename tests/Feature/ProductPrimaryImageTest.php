<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Setting;

/**
 * THE PRODUCT PAGE NAMES ITS OWN PICTURE FOR GOOGLE.
 *
 * What it looked like (owner, 23 October): Google's result for the Centellian24
 * 360° Shot PDRN Lifting Eye Cream showed the medicube Collagen Jelly Cream jar
 * as its thumbnail. The page's own image, og:image, feed and sitemap were all
 * the eye cream; the jelly cream was one of the "you may also like" cards
 * lower down. Nothing told Google the Product was the page's main entity, so
 * every card photo was an equal candidate.
 *
 * Mutation note: delete the mainEntityOfPage block in Seo.php and the first
 * case is red.
 */
beforeEach(function () {
    Setting::updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbeautybliss.test']);
    Setting::flushMap();
});

function ppiProduct(array $attrs = []): Product
{
    return Product::create(array_merge([
        'slug' => 'ppi-'.uniqid(),
        'name' => 'Centellian24 - 360 Shot PDRN Lifting Eye Cream',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
        'image' => '/wp-content/uploads/eye-cream-main.webp',
        'images' => ['/wp-content/uploads/eye-cream-main.webp', '/wp-content/uploads/eye-cream-2.webp'],
    ], $attrs));
}

function ppiProductNode(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    foreach ($m[1] as $raw) {
        $node = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($node['@type'] ?? null) === 'Product') {
            return $node;
        }
    }

    return [];
}

it('marks the product as the page\'s main entity with its first photo as the primary image', function () {
    $p = ppiProduct();
    $node = ppiProductNode($this->get('/product/'.$p->slug.'/')->assertOk()->getContent());

    $main = $node['mainEntityOfPage'] ?? null;
    expect($main)->toBeArray();
    expect($main['@type'])->toBe('WebPage');
    expect($main['@id'])->toBe($node['url']);
    expect($main['primaryImageOfPage']['@type'])->toBe('ImageObject');
    expect($main['primaryImageOfPage']['url'])->toEndWith('/wp-content/uploads/eye-cream-main.webp');
    expect($main['primaryImageOfPage']['url'])->toBe(((array) $node['image'])[0]);
});

it('states no primary image for a product that has no photo', function () {
    $p = ppiProduct(['image' => null, 'images' => []]);
    $node = ppiProductNode($this->get('/product/'.$p->slug.'/')->assertOk()->getContent());

    expect($node)->not->toHaveKey('mainEntityOfPage');
});
