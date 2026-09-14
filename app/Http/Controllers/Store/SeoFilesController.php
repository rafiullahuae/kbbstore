<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeoFilesController extends Controller
{
    private function base(): string
    {
        $s = Setting::map();
        return rtrim($s['site_url'] ?? config('app.url') ?? url('/'), '/');
    }

    /** GET /sitemap.xml — dynamic sitemap of indexable URLs. */
    public function sitemap()
    {
        $s = Setting::map();
        if (($s['sitemap_enabled'] ?? '1') === '0') {
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
        $add($base . '/reviews/', null, '0.5', 'weekly');
        $add($base . '/skin-quiz/', null, '0.5', 'monthly');
        $add($base . '/brands/', null, '0.5', 'weekly');
        $add($base . '/skincare-guide/', null, '0.6', 'weekly');

        // Products.
        //
        // This filtered on status 'active'. Products use 'publish' -- see
        // Product::scopeVisible() -- so the condition matched nothing and the
        // sitemap has been shipping with zero products in it. is_visible was
        // not checked either, which would have leaked hidden products the
        // moment the status string was corrected on its own.
        //
        // Trailing slash matters: Product::url() and the URL contract both use
        // /product/{slug}/, and /product/{slug} 301s to it. Without the slash
        // every entry here pointed at a redirect rather than the canonical URL.
        if (Schema::hasTable('products')) {
            $q = DB::table('products')->select('slug', 'updated_at');

            if (Schema::hasColumn('products', 'status')) {
                $q->where('status', 'publish');
            }

            if (Schema::hasColumn('products', 'is_visible')) {
                $q->where('is_visible', true);
            }

            if (Schema::hasColumn('products', 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            foreach ($q->get() as $p) {
                if (empty($p->slug)) continue;
                $add($base . '/product/' . $p->slug . '/', $p->updated_at ?? null, '0.8', 'weekly');
            }
        }

        // Categories.
        //
        // These were listed under /category/{slug}, which has never been a
        // route -- the real one is /product-category/{path}/, and Category::url()
        // builds it from the full nested path, not the bare slug. Every
        // category URL in the sitemap was a 404.
        if (Schema::hasTable('categories')) {
            $cols = DB::table('categories')->select('slug', 'updated_at');

            if (Schema::hasColumn('categories', 'path')) {
                $cols->addSelect('path');
            }

            foreach ($cols->get() as $c) {
                $path = trim((string) ($c->path ?? ''), '/') ?: $c->slug;

                if (empty($path)) continue;

                $add($base . '/product-category/' . $path . '/', $c->updated_at ?? null, '0.6', 'weekly');
            }
        }

        // Blog posts. /post/{slug} 301s to the canonical /skincare-guide/{slug}/
        // as of 2.60.93, so the redirect target is listed directly.
        if (Schema::hasTable('posts')) {
            $q = DB::table('posts')->select('slug', 'updated_at');

            if (Schema::hasColumn('posts', 'status')) {
                $q->where('status', 'published');
            }

            foreach ($q->get() as $post) {
                if (empty($post->slug)) continue;
                $add($base . '/skincare-guide/' . $post->slug . '/', $post->updated_at ?? null, '0.6', 'monthly');
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

    /**
     * GET /{key}.txt — IndexNow key-file verification. The route pattern
     * only matches strings shaped like a real IndexNow key (8-128 chars,
     * alphanumeric/dash), so this doesn't swallow every other .txt request
     * on the site — and still checks it's the actual current key, not just
     * a plausible-looking one, before serving anything.
     */
    public function indexNowKeyFile(string $key)
    {
        if ($key !== \App\Services\Seo\IndexNow::key()) {
            abort(404);
        }

        return response($key, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** GET /llms.txt — a plain-text summary for AI crawlers/agents, not a sitemap replacement. */
    public function llms()
    {
        $s = Setting::map();

        if (($s['llms_enabled'] ?? '1') !== '1') {
            return response('Not enabled', 404);
        }

        $base = $this->base();
        $name = $s['seo_site_name'] ?? ($s['store_name'] ?? 'K-Beauty Bliss');
        $desc = trim((string) ($s['seo_default_description'] ?? ''));

        $lines = [
            "# {$name}",
            '',
            $desc !== '' ? $desc : 'Online store.',
            '',
            '## Key pages',
            "- [Shop]({$base}/shop)",
            "- [Blog]({$base}/blog)",
        ];

        return response(implode("\n", $lines) . "\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }


    public function robots()
    {
        $s = Setting::map();
        if (!empty($s['robots_txt'])) {
            return response($s['robots_txt'], 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        }
        $base = $this->base();
        $body = "User-agent: *\nAllow: /\n"
              . "Disallow: /admin\nDisallow: /admin-api\nDisallow: /api\nDisallow: /checkout\n"
              // Thin or per-visitor pages: a cart, an account area and a
              // wishlist are different for every visitor and useless in a
              // result. Every crawl of them is budget not spent on a
              // product. /admin stays a decoy -- the real admin path is a
              // setting, and naming it here would publish the one thing
              // keeping it quiet.
              . "Disallow: /cart\nDisallow: /my-account\nDisallow: /my-wishlist\nDisallow: /wishlist\nDisallow: /track-my-order\n\n"
              . "Sitemap: {$base}/sitemap.xml\n";
        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
