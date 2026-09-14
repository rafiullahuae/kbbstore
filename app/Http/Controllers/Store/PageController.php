<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\SettingsService;
use App\Support\Seo;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;

/**
 * Editable content pages: privacy policy, terms, delivery and so on.
 *
 * Content lives in the pages table rather than in templates, so it can be
 * changed in the admin without a release.
 */
class PageController extends Controller
{
    /**
     * Every first path segment the storefront already owns.
     *
     * This exists because a blog post is served from the site root — /{slug}/ —
     * which is a route shaped like "match anything". Registering it last is
     * enough for Laravel to prefer /cart over it *today*; this list is what
     * keeps that true tomorrow, when someone adds a route after it or names a
     * post "checkout". Order stops being the only thing holding the site up.
     *
     * Kept honest by a test rather than by discipline:
     * tests/Feature/RootSlugCollisionTest.php walks every route the router has
     * actually registered and fails if any static first segment is missing
     * here, so adding a route to web.php without updating this list is a red
     * suite rather than a silently swallowed page.
     *
     * Segments containing a dot or an underscore — /robots.txt, /sitemap.xml,
     * /llms.txt, /refund_returns, /_kbb-health, /_design-check — are excluded
     * by the character class in slugPattern() and are deliberately not
     * repeated here.
     */
    public const RESERVED_SLUGS = [
        // Routers and framework
        'api', 'admin', 'admin-api', 'up',
        // Catalogue
        'shop', 'product', 'product-category', 'cart', 'checkout', 'quick-view',
        'new-in', 'best-sellers', 'super-sale', 'everything-under-54-aed',
        // Brands: the directory, plus the two addresses that now 301 to it
        'korean-skincare-brands', 'brands', 'brand',
        // The Journal: the index, plus the prefixes posts used to sit under
        'skincare-guide', 'blog', 'post',
        // Account and lists
        'my-account', 'track-my-order', 'my-wishlist', 'wishlist',
        // Standalone storefront pages
        'app', 'skin-quiz', 'reviews', 'subscribe',
        'about', 'delivery', 'faqs', 'contact-us',
        'privacy-policy', 'terms-and-conditions',
        // Served off disk by the web server, never by PHP
        'storage', 'build', 'uploads', 'assets', 'images', 'fonts',
        // WordPress leftovers the old site still gets crawled for
        'wp-content', 'wp-admin', 'wp-includes', 'wp-json', 'feed',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The regex constraining the root-level post slug route.
     *
     * Two guards, and both are needed:
     *
     *   1. a negative lookahead over RESERVED_SLUGS, so the route cannot match
     *      a path the storefront already serves;
     *   2. a strict slug shape — lowercase letters, digits and single hyphens —
     *      so it cannot match a file name, a dotted path or an underscore path.
     *
     * The admin path is added at runtime rather than listed as a constant: it
     * is configurable (KBB_ADMIN_PATH, or the admin_path setting), so a static
     * list would go stale the moment anyone moved the admin.
     */
    public static function slugPattern(): string
    {
        $reserved = self::RESERVED_SLUGS;

        $adminPath = trim(AdminPathService::current(), '/');

        if ($adminPath !== '') {
            $reserved[] = $adminPath;
        }

        $alternates = implode('|', array_map(
            static fn (string $word): string => preg_quote($word, '/'),
            array_values(array_unique($reserved))
        ));

        return '(?!(?:' . $alternates . ')$)[a-z0-9]+(?:-[a-z0-9]+)*';
    }

    public function show(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return view('store.page', [
            'page' => $page,
            'settings' => $this->settings,
        ]);
    }

    /**
     * The blog listing — same missing-method 500 as post() below, and the
     * same root cause: the view fetched a nonexistent /api/posts endpoint
     * and silently fell back to three hardcoded demo posts regardless of
     * what was actually in the database. Rebuilt server-rendered, same as
     * the fix below.
     *
     * The index stays at /skincare-guide/, which is what the nav, the homepage
     * and the admin Pages screen all publish. Only the *article* URL moved.
     */
    public function blog()
    {
        $posts = Post::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->get(['slug', 'title', 'excerpt', 'tag', 'cover', 'published_at']);

        $base = self::siteBase();
        $seo = Seo::render([
            'type' => 'website',
            'title' => 'The Glow Journal',
            'description' => 'Skincare tips and the K-beauty edit — honest guides on routines, ingredients and sun care, written for the UAE.',
            'url' => $base . '/skincare-guide/',
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Journal', 'url' => $base . '/skincare-guide/'],
            ],
        ]);

        return view('store.blog', [
            'posts' => $posts,
            'seo' => $seo,
            'settings' => $this->settings,
        ]);
    }

    /**
     * A single article, served from the site root: /{slug}/.
     *
     * The owner settled this in Phase 9: posts live at the root with no prefix,
     * one slug per post, exactly as the live WordPress site serves them —
     * kbeautybliss.com/heartleaf-extract-transforming-k-beauty-skincare/. The
     * /skincare-guide/{slug}/ form this app invented is now a 301.
     *
     * The route reaching here is constrained by slugPattern(), so an unknown
     * slug that got this far is genuinely unknown rather than a storefront page
     * being shadowed. firstOrFail() 404s it, and the exception handler's
     * redirect check (AppServiceProvider) still gets its turn at that 404.
     */
    public function post(string $slug = '')
    {
        $post = Post::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $related = Post::query()
            ->where('status', 'published')
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(3)
            ->get(['slug', 'title', 'tag', 'cover']);

        $base = self::siteBase();
        $seoOverride = is_array($post->seo) ? $post->seo : [];
        $canonical = $base . '/' . $post->slug . '/';

        $seo = Seo::render([
            'type' => 'article',
            'title' => $seoOverride['title'] ?? $post->title,
            'title_is_final' => !empty($seoOverride['title']),
            'description' => $seoOverride['desc'] ?? $post->excerpt ?? '',
            'image' => $seoOverride['og_image'] ?? $post->cover,
            'url' => !empty($seoOverride['canonical']) ? $seoOverride['canonical'] : $canonical,
            'noindex' => !empty($seoOverride['noindex']),
            'article' => [
                'title' => $post->title,
                'published_at' => optional($post->published_at)->toAtomString(),
            ],
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Journal', 'url' => $base . '/skincare-guide/'],
                ['name' => $post->title, 'url' => $canonical],
            ],
        ]);

        return view('store.post', [
            'post' => $post,
            'related' => $related,
            'seo' => $seo,
            'settings' => $this->settings,
        ]);
    }

    /**
     * The retired /skincare-guide/{slug}/ article URL.
     *
     * A route rather than rows in the redirects table, because it has to cover
     * every post — including the ones written after the seeding migration ran,
     * which no seeded row could know about. The redirects table still gets its
     * turn: an unknown slug 404s from here, and the exception handler checks
     * the table on every 404.
     */
    public function legacyPost(string $slug = ''): RedirectResponse
    {
        if ($slug === '') {
            return redirect(Url::redirect('/skincare-guide/'), 301);
        }

        abort_unless(
            Post::query()->where('slug', $slug)->where('status', 'published')->exists(),
            404
        );

        // Url::redirect(), not route() or Url::to(): Laravel strips the
        // trailing slash off a registered URI, and redirect() resolves a
        // relative path against APP_URL — which already carries the base path
        // on staging, so Url::to() there produces /kbb-upgrade/kbb-upgrade/…
        return redirect(Url::redirect('/' . $slug . '/'), 301);
    }

    /**
     * The site's own base URL, for absolute canonical and breadcrumb links.
     *
     * Reads the site_url setting the SEO screen writes, exactly as blog() and
     * post() did before. Note Setting::map() memoises in a process-level static
     * as well as the cache (see CLAUDE.md) — fine here, since this is read-only
     * and the value does not change within a request.
     */
    private static function siteBase(): string
    {
        return rtrim((string) (Setting::map()['site_url'] ?? ''), '/');
    }
}
