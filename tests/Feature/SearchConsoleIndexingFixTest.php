<?php

declare(strict_types=1);

/*
 * Search Console, 21 October 2026: ~2,070 "not indexed" addresses on
 * kbeautybliss.com, almost all of them left over from the WordPress shop
 * (2.60.564).
 *
 * 1. robots.txt asks crawlers to stop fetching the WordPress-only query
 *    addresses: 1,357 sampled ?add-to-cart= links (each a 403 to a bot),
 *    ?rey-global-sections=, ?remove_item=&_wpnonce= and friends. The new
 *    shop's own parameters (filter_brands, paged, per_page) must stay
 *    crawlable, or category filters drop out of the index.
 * 2. The renamed products, removed products and old category/blog addresses
 *    in the 404 list forward with a 301 to the real page.
 *
 * MUTATION NOTES: drop 'add-to-cart' from ROBOTS_WORDPRESS_PARAMS -> RED
 * (case 1); add 'paged' to it -> RED (case 1); drop a MAP row -> RED
 * (case 2); remove the "already exists" check -> RED (case 3).
 */

use App\Http\Middleware\CheckRedirects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function scMigration(): object
{
    return require database_path('migrations/2027_11_21_100000_search_console_404_redirects.php');
}

it('asks crawlers to skip the WordPress-only query addresses, and only those', function () {
    $body = test()->get('/robots.txt')->assertOk()->getContent();

    foreach (['add-to-cart', 'rey-global-sections', 'remove_item', '_wpnonce', 'shop_view', 'attachment_id', 'get_svg_icon', 'wc-ajax'] as $p) {
        expect($body)->toContain("Disallow: /*?{$p}=\n")
            ->and($body)->toContain("Disallow: /*&{$p}=\n");
    }

    // The new shop's own parameters stay crawlable.
    foreach (['filter_brands', 'paged', 'per_page', 'orderby', 'page'] as $own) {
        expect($body)->not->toContain("?{$own}=")->not->toContain("&{$own}=");
    }

    // The existing lines are untouched, and the sitemap line still ends the file.
    expect($body)->toStartWith("User-agent: *\nAllow: /\nDisallow: /admin\n")
        ->and($body)->toContain("Disallow: /checkout\n")
        ->and($body)->toEndWith("/sitemap.xml\n");
});

it('forwards each Search Console 404 to the page that replaced it, in both spellings', function () {
    $m = scMigration();
    $rows = $m::rows();

    expect(count($rows))->toBe(count($m::MAP) * 2);

    foreach ([
        '/product/the-retinol-0-5-oil/' => '/product/cosrx-the-retinol-0-5-oil/',
        '/product/marine-care-eye-cream-30ml/' => '/product/heimish-marine-care-eye-cream-30ml/',
        '/product/round-lab-birch-juice-moisturizing-sunscreen/feed/' => '/product/round-lab-birch-juice-moisturizing-sunscreen-50ml/',
        '/product/tirtir-glossy-coating-mist/' => '/brands/tirtir/',
        '/product-category/lip-care/lip-oils/' => '/collections/lip-care/',
        '/news/' => '/blog/',
    ] as $from => $to) {
        expect($rows[$from] ?? null)->toBe($to, $from)
            ->and(CheckRedirects::findMatch(Request::create($from))?->target)->toBe($to, $from);

        $response = $this->get($from);
        expect($response->getStatusCode())->toBe(301, $from)
            ->and(parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe($to, $from);
    }

    // No chains and no loops: a target is never itself a source here.
    foreach ($rows as $from => $to) {
        expect(array_key_exists($to, $rows))->toBeFalse($from)
            ->and(array_key_exists(rtrim($to, '/'), $rows))->toBeFalse($from);
    }
});

it('keeps a redirect the owner already typed for the same address', function () {
    $src = '/product/the-retinol-0-5-oil/';
    DB::table('redirects')->where('source', $src)->delete();
    DB::table('redirects')->insert(['source' => $src, 'target' => '/my-own/', 'code' => 301, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    scMigration()->up();

    expect(DB::table('redirects')->where('source', $src)->value('target'))->toBe('/my-own/')
        ->and(DB::table('redirects')->where('source', $src)->count())->toBe(1);
});
