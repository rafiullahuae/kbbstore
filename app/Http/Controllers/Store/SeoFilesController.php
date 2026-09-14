<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeoFilesController extends Controller
{
    /**
     * The absolute base every URL in these files is written against.
     *
     * Read through SeoSettings because the admin screen saves an unfilled Site
     * URL as '', and `$s['site_url'] ?? config('app.url')` kept the empty
     * string — which produced a sitemap of relative "/shop" entries and a
     * robots.txt pointing at "/sitemap.xml". Both files are consumed by
     * crawlers that have no base to resolve against.
     */
    private function base(): string
    {
        $s = new SeoSettings;

        return rtrim($s->get('site_url') ?? (string) (config('app.url') ?: url('/')), '/');
    }

    /** GET /sitemap.xml — dynamic sitemap of indexable URLs. */
    public function sitemap()
    {
        $s = new SeoSettings;
        if ($s->get('sitemap_enabled', '1') === '0') {
            return response('Sitemap disabled', 404);
        }

        $base = $this->base();
        $urls = [];
        $add = function ($loc, $lastmod = null, $priority = '0.6', $freq = 'weekly') use (&$urls) {
            $urls[] = compact('loc', 'lastmod', 'priority', 'freq');
        };

        // Static pages
        $add($base . '/', null, '1.0', 'daily');
        $add($base . '/shop', null, '0.9', 'daily');
        $add($base . '/reviews', null, '0.5', 'weekly');
        $add($base . '/skin-quiz', null, '0.5', 'monthly');
        $add($base . '/blog', null, '0.6', 'weekly');

        // Products
        if (Schema::hasTable('products')) {
            $q = DB::table('products');
            if (Schema::hasColumn('products', 'status')) $q->where('status', 'active');
            foreach ($q->get() as $p) {
                if (empty($p->slug)) continue;
                $add($base . '/product/' . $p->slug, $p->updated_at ?? null, '0.8', 'weekly');
            }
        }

        // Categories
        if (Schema::hasTable('categories')) {
            foreach (DB::table('categories')->get() as $c) {
                if (empty($c->slug)) continue;
                $add($base . '/category/' . $c->slug, $c->updated_at ?? null, '0.6', 'weekly');
            }
        }

        // Blog posts
        if (Schema::hasTable('posts')) {
            $q = DB::table('posts');
            if (Schema::hasColumn('posts', 'status')) $q->where('status', 'published');
            foreach ($q->get() as $post) {
                if (empty($post->slug)) continue;
                $add($base . '/post/' . $post->slug, $post->updated_at ?? null, '0.6', 'monthly');
            }
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1) . '</loc>';
            if (!empty($u['lastmod'])) {
                $d = @date('Y-m-d', strtotime((string) $u['lastmod']));
                if ($d) $xml .= '<lastmod>' . $d . '</lastmod>';
            }
            $xml .= '<changefreq>' . $u['freq'] . '</changefreq>';
            $xml .= '<priority>' . $u['priority'] . '</priority></url>' . "\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** GET /robots.txt — crawl rules + sitemap pointer. */
    public function robots()
    {
        $s = new SeoSettings;
        $custom = $s->get('robots_txt');
        if ($custom !== null) {
            return response($custom, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        $base = $this->base();
        $body = "User-agent: *\nAllow: /\n"
              . "Disallow: /admin\nDisallow: /admin-api\nDisallow: /api\nDisallow: /checkout\n\n"
              . "Sitemap: {$base}/sitemap.xml\n";
        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
