<?php

declare(strict_types=1);

/*
 * Old WordPress addresses forward instead of 404ing.
 *
 * Lane DM, through the real kernel: /sitemap_index.xml, /wp-sitemap.xml and
 * Yoast's *-sitemap.xml (which Google has on file for kbeautybliss.com), /feed/,
 * and WooCommerce's /my-account/lost-password/ and /my-account/edit-account/
 * (linked from old customer emails) all answered 404 on this shop.
 * 2027_09_07_100000 adds one row each; a row the owner already typed is kept.
 *
 * MUTATION NOTES, RUN: drop a row from ROWS → RED (case 1); remove the
 * "already exists" check → RED (case 2).
 */

use App\Http\Middleware\CheckRedirects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('forwards each old WordPress address with a 301', function () {
    $rows = (require database_path('migrations/2027_09_07_100000_old_wordpress_address_redirects.php'))::ROWS;

    foreach ([
        '/sitemap_index.xml' => '/sitemap.xml',
        '/wp-sitemap.xml' => '/sitemap.xml',
        '/product-sitemap.xml' => '/sitemap.xml',
        '/feed/' => '/blog/',
        '/my-account/lost-password/' => '/my-account/forgot',
        '/my-account/edit-account/' => '/my-account/',
    ] as $from => $to) {
        expect($rows[$from] ?? null)->toBe($to, $from);

        // The exact WordPress spelling, trailing slash kept, as a browser sends it.
        expect(CheckRedirects::findMatch(Request::create($from))?->target)->toBe($to, $from);

        // And through the kernel (the test client drops a trailing slash, so
        // this also proves the bare spelling forwards).
        $response = $this->get($from);
        expect($response->getStatusCode())->toBe(301, $from)
            ->and(parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe($to, $from);
    }
});

it('keeps a redirect the owner already typed for the same address', function () {
    DB::table('redirects')->where('source', '/feed/')->delete();
    DB::table('redirects')->insert(['source' => '/feed/', 'target' => '/my-own/', 'code' => 301, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2027_09_07_100000_old_wordpress_address_redirects.php'))->up();

    expect(DB::table('redirects')->where('source', '/feed/')->value('target'))->toBe('/my-own/');
});
