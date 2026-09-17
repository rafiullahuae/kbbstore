<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\SettingsService;
use App\Support\ReviewWall;
use App\Support\Seo;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        // Newsletter confirm/unsubscribe, opened from a shopper's inbox. Without
        // this the root catch-all below swallows them and every confirmation
        // link in every mail this shop sends resolves to a post lookup.
        'newsletter',
        // Back-in-stock alerts, basket reminders, and the page that turns this
        // shop's marketing mail off. Same reason as 'newsletter' above, and one
        // of them matters more: /mail-preferences is the address in the footer
        // of every marketing mail this shop sends. Swallowed by the root
        // catch-all it becomes a post lookup, which is a 404 — an unsubscribe
        // link that 404s is the complaint that gets a sending domain blocked.
        'notify-me', 'mail-preferences',
        'about', 'delivery', 'faqs', 'contact-us',
        'privacy-policy', 'terms-and-conditions',
        /*
         * Language prefixes (Lane EP).
         *
         * NOT needed for routing: App\Http\Middleware\SetLocaleFromPath strips
         * /ar before the router runs, so the router never sees it and this
         * catch-all is never offered it. They are here because of the OTHER
         * direction — an article or page whose slug was literally "ar" would be
         * published at an address the middleware eats, and it would simply
         * never be reachable, with nothing anywhere saying why. Reserving both
         * makes the editor refuse the slug instead.
         *
         * 'en' as well as 'ar': /en/ is a 301 to the unprefixed form once
         * Arabic is on, so a post called "en" has the same problem.
         */
        'ar', 'en',
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
     * The skin quiz page.
     *
     * routes/web.php has pointed /skin-quiz at this method since the route was
     * written, and the method did not exist — so the page 500'd on every visit.
     * The Blade view was there the whole time; only the controller half was
     * missing, which is why nothing looked obviously broken in the tree.
     *
     * The quiz itself is client-side and posts to /api/quiz, so there is
     * nothing to fetch here: the page needs its <head> and nothing else.
     */
    public function skinQuiz()
    {
        $base = self::siteBase();

        return view('store.skin-quiz', [
            'seo' => Seo::render([
                'type' => 'website',
                'title' => 'Skin Quiz',
                'description' => 'Answer a few questions and get a K-beauty routine matched to your skin type, concerns and the UAE climate.',
                'url' => $base . '/skin-quiz/',
                'breadcrumb' => [
                    ['name' => 'Home', 'url' => $base . '/'],
                    ['name' => 'Skin Quiz', 'url' => $base . '/skin-quiz/'],
                ],
            ]),
            'settings' => $this->settings,
        ]);
    }

    /**
     * The review wall. Same missing-method 500 as skinQuiz() above.
     *
     * AND, UNTIL NOW, TWELVE INVENTED CUSTOMERS. This method passed the view no
     * review data whatsoever, and store/review-wall.blade.php answered with
     * `var REVIEWS=[…]`: twelve fabricated people with names, star ratings,
     * relative dates, review bodies and "helpful" counts, a computeStats() that
     * derived "4.9" and "Based on 128 reviews" from them, and a header capsule
     * hard-coded to "4.9 · 128 reviews" over a product the page never looked
     * up. A real approved review could not reach this page, and the twelve
     * could not be taken off it by anything the owner could do. The SEO block
     * below submitted the URL to search engines the whole time.
     *
     * Everything the page prints now comes from App\Support\ReviewWall, which
     * reads `reviews` and shows nothing it cannot find there.
     *
     * THE DESCRIPTION IS NOW TWO DESCRIPTIONS, because the old one —
     * "What customers say about the K-beauty products they bought from us" —
     * is a description of the fabricated page. On a shop with no approved
     * reviews it describes content that is not there, which is the same claim
     * the page itself stopped making. SeoFilesController keeps /reviews/ out of
     * the sitemap in that state for the same reason; the page stays a 200 and
     * stays indexable either way.
     */
    public function reviewWall(Request $request)
    {
        $base = self::siteBase();

        $filter = ReviewWall::filter($request->query('rfilter'));
        $show = ReviewWall::show($request->query('rshow'));

        $summary = ReviewWall::summary();
        $cards = ReviewWall::cards($filter, $show);

        return view('store.review-wall', [
            'seo' => Seo::render([
                'type' => 'website',
                'title' => 'Customer Reviews',
                'description' => $summary['total'] > 0
                    ? 'Reviews left by customers of this shop, shown as they were approved.'
                    : 'This shop has not been reviewed yet. Reviews appear here once customers leave them.',
                'url' => $base . '/reviews/',
                'breadcrumb' => [
                    ['name' => 'Home', 'url' => $base . '/'],
                    ['name' => 'Reviews', 'url' => $base . '/reviews/'],
                ],
            ]),
            'settings' => $this->settings,
            'summary' => $summary,
            'reviews' => $cards['items'],
            'hasMore' => $cards['more'],
            'shown' => $show,
            'filter' => $filter,
        ]);
    }

    /**
     * The standalone app prototype at /app — now a developer preview behind the
     * admin session, and a 404 to everybody else.
     *
     * THE HISTORY. routes/web.php has pointed /app at this method since the
     * 2.60.41 baseline and the method was never written, so the page threw
     * "Call to undefined method ...PageController::app()" — a 500 to every
     * visitor — for most of the life of the repo. Writing the method turned a
     * 500 into a 200, and a 200 is what made the real defect reachable.
     *
     * WHAT A LOGGED-OUT VISITOR WAS BEING SERVED. resources/views/store/app.blade.php
     * is a complete second storefront: the same logo, the same lockup, the same
     * nav, the same payment chips. It is not recognisable as a prototype. And
     * everything in it is invented:
     *
     *   - `const PRODUCTS=[...]` — twenty-four products with names, brands,
     *     "was" prices, sale prices, star ratings and review counts, none of
     *     which is read from, or checked against, the products table.
     *   - `const COUPONS={GLOW30:..., KBB10:...}` — two discount codes the
     *     coupons table has never contained, advertised in the hero slide, on
     *     the product view, in the cart's own coupon box ("try GLOW30") and in
     *     the error toast a wrong code produces. The box accepts them and
     *     shows the money coming off.
     *   - "50+ Korean brands", "100% original" and "24/7 support" as literals.
     *
     * 2.60.190 shipped for exactly one of these: the real front page offering a
     * discount code the shop has not got. That fix was incomplete, because this
     * page went on offering the same code, plus a second one, at a public URL.
     *
     * WHY noindex WAS NOT A FIX. The old docblock reasoned that noindex kept
     * the invented catalogue out of search results, which it does. It does
     * nothing about the customer who has the URL — from a chat message, a
     * bookmark, a shared link — and that customer is the one who sees a price
     * and a coupon code that will not survive contact with the checkout.
     *
     * WHAT IT DOES NOW. The page is a developer preview, so it is served to a
     * developer: an authenticated admin session sees it exactly as before, and
     * everyone else gets the same 404 the router gives for a path that was
     * never registered. abort(404), not a redirect and not a 403, because a 403
     * confirms the page is there; the route is not a secret, but there is no
     * reason to advertise it either.
     *
     * The gate lives here rather than in routes/web.php on purpose: this deploys
     * as a signed zip to shared hosting with a compiled route cache, and a
     * middleware added to the route file does not take effect until that cache
     * is cleared. A check inside the controller is live the moment the file
     * lands. See CLAUDE.md, "How this ships".
     *
     * noindex stays for the admin who does reach it, for the same reason it was
     * added.
     */
    public function app()
    {
        if (! Auth::guard('admin')->check()) {
            abort(404);
        }

        $base = self::siteBase();

        return view('store.app', [
            'seo' => Seo::render([
                'type' => 'website',
                'title' => 'App Preview',
                'description' => 'A standalone preview of the K-Beauty Bliss shopping experience.',
                'url' => $base . '/app/',
                'noindex' => true,
            ]),
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
            // Three out of everything published, and `published_at` is the
            // same value for every article an import wrote: without `id` the
            // three are picked out of a tie by the database.
            ->latest('published_at')
            ->orderByDesc('id')
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
            /*
             * The cover only counts as an image when it IS one. `posts.cover`
             * is a CSS background value and most rows hold a gradient, which
             * went out of here as-is: Seo::absolute() sees no scheme on
             * "linear-gradient(135deg,#FFF0F4,#FCE0E8)", so it prefixed the
             * site base and published
             * <meta property="og:image" content="https://…/linear-gradient(…)">
             * plus the same string as schema.org Article.image. Facebook and
             * Twitter drop a card whose image 404s and Search Console reports
             * the Article's as invalid. Null publishes no image at all, which
             * is the honest answer for a post whose cover is decoration.
             */
            'image' => $seoOverride['og_image'] ?? \App\Support\CoverImage::src($post->cover),
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
