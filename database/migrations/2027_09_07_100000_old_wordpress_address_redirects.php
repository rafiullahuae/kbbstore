<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Old WordPress addresses that answered 404 on this shop.
 *
 * Lane DM measured every WooCommerce URL family through the real kernel
 * (docs/DOMAIN-MOVE-KBEAUTYBLISS.md §E3). Products, categories, brands and
 * articles already forward; these did not: Yoast/WordPress sitemaps that
 * Google has on file, the blog feed, and WooCommerce's own account page names
 * that old customer emails link to. The owner, 7 October: bug fixes first.
 *
 * A row is added only where no row for that source exists yet, so a redirect
 * the owner already typed on Store → SEO & Meta → Redirects & 404s is never
 * overwritten. Ordinary rows, so they show and can be edited or switched off
 * on that screen.
 *
 * A row matches one exact spelling (CheckRedirects::spellings() only varies
 * percent-encoding), so the folder-style addresses carry both the WordPress
 * form with its trailing slash and the bare form.
 *
 * Not here, on purpose: /checkout/order-received/{id}/?key=… -- a row matches
 * one exact address, and that one carries the order id.
 */
return new class extends Migration
{
    public const ROWS = [
        '/sitemap_index.xml' => '/sitemap.xml',
        '/wp-sitemap.xml' => '/sitemap.xml',
        '/product-sitemap.xml' => '/sitemap.xml',
        '/product_cat-sitemap.xml' => '/sitemap.xml',
        '/post-sitemap.xml' => '/sitemap.xml',
        '/page-sitemap.xml' => '/sitemap.xml',
        '/feed/' => '/blog/',
        '/feed' => '/blog/',
        '/my-account/lost-password/' => '/my-account/forgot',
        '/my-account/lost-password' => '/my-account/forgot',
        '/my-account/edit-account/' => '/my-account/',
        '/my-account/edit-account' => '/my-account/',
    ];

    public function up(): void
    {
        $added = 0;

        foreach (self::ROWS as $source => $target) {
            if (DB::table('redirects')->where('source', $source)->exists()) {
                continue;
            }

            $row = ['source' => $source, 'target' => $target, 'code' => 301, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now()];

            if (\Illuminate\Support\Facades\Schema::hasColumn('redirects', 'auto_created')) {
                $row['auto_created'] = false;
            }

            DB::table('redirects')->insert($row);
            $added++;
        }

        CheckRedirects::flushIndex();

        if (app()->runningInConsole()) {
            echo "Old WordPress addresses: {$added} redirect(s) added (Store -> SEO & Meta -> Redirects & 404s).\n";
        }
    }

    public function down(): void {}
};
