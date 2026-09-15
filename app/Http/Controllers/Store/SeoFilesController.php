<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeoFilesController extends Controller
{
    /**
     * The absolute base every URL in these files is built on.
     *
     * Was `$s['site_url'] ?? config('app.url') ?? url('/')`. The SEO screen
     * posts site_url on every save, so an admin who never filled it in has
     * '' stored there -- which ?? happily accepts, because '' is not null.
     * The result was a sitemap of relative <loc> values (rejected outright by
     * Search Console) and a robots.txt advertising "Sitemap: /sitemap.xml".
     * SeoSettings::firstFilled() takes the first candidate that is usable
     * rather than the first that exists.
     */
    private function base(): string
    {
        $s = SeoSettings::map();

        return rtrim(SeoSettings::firstFilled(
            $s['site_url'] ?? null,
            (string) config('app.url'),
            url('/')
        ), '/');
    }

    /** GET /sitemap.xml — dynamic sitemap of indexable URLs. */
    public function sitemap()
    {
        $s = SeoSettings::map();
        if (SeoSettings::from($s, 'sitemap_enabled') === '0') {
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

        // Articles live at the site root since 2.60.109; /post/{slug} and
        // /skincare-guide/{slug}/ both 301 to /{slug}/, so only the canonical
        // form belongs in the sitemap.
        // as of 2.60.93, so the redirect target is listed directly.
        if (Schema::hasTable('posts')) {
            $q = DB::table('posts')->select('slug', 'updated_at');

            if (Schema::hasColumn('posts', 'status')) {
                $q->where('status', 'published');
            }

            foreach ($q->get() as $post) {
                if (empty($post->slug)) continue;
                $add($base . '/' . $post->slug . '/', $post->updated_at ?? null, '0.6', 'monthly');
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
        $s = SeoSettings::map();

        if (SeoSettings::from($s, 'llms_enabled') !== '1') {
            return response('Not enabled', 404);
        }

        $base = $this->base();
        $name = SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        );
        $desc = SeoSettings::from($s, 'seo_default_description', '');

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
        $s = SeoSettings::map();
        $custom = SeoSettings::from($s, 'robots_txt', '');
        if ($custom !== '') {
            return response($custom, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
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
