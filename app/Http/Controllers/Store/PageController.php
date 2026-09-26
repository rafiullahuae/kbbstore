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
        /*
         * `import-chain` is the loopback endpoint the background import calls
         * to continue itself (Lane GO). It carries no `auth:admin`, by
         * necessity -- no browser is attached -- so it is the one new first
         * segment that an article published at the site root could shadow.
         * RootSlugCollisionTest caught it within a minute of the route being
         * mounted, which is the guard doing exactly its job.
         */
        'import-chain',
        // Catalogue
        'shop', 'product', 'product-category', 'cart', 'checkout', 'quick-view',
        'new-in', 'best-sellers', 'super-sale', 'everything-under-54-aed',
        /*
         * `concern` is the first segment of /concern/{concern}/ (Lane S), and
         * it is reserved for the same reason `routines` above it is: a concern
         * page 404s until the owner has written its copy AND tagged
         * ConcernCollections::MIN_PRODUCTS live products for it. An article
         * published at the slug "concern" would be reachable today and would
         * silently stop being reachable on the day he tagged his third acne
         * product -- a page disappearing because of a chip ticked on another
         * screen. RootSlugCollisionTest caught this the moment the route was
         * mounted, which is the guard doing its job a second time.
         */
        'concern',
        // Brands: the directory, plus the two addresses that now 301 to it
        'korean-skincare-brands', 'brands', 'brand',
        // The Journal: the index, plus the prefixes posts used to sit under
        'skincare-guide', 'blog', 'post',
        // Account and lists
        'my-account', 'track-my-order', 'my-wishlist', 'wishlist',
        // Standalone storefront pages
        // 'routines' is Phase 10's pair of pages (Lane FM). Reserved even
        // though the module ships OFF and both addresses 404 until it is turned
        // on: the reservation is about the EDITOR, not the router. A post
        // published at the slug "routines" today would be reachable, and would
        // then silently stop being reachable on the day the owner switched the
        // module on — a page disappearing because of a setting on another
        // screen is the hardest kind of report to act on.
        'app', 'skin-quiz', 'reviews', 'subscribe', 'routines',
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

    /**
     * A content page — /about/, /delivery/, /faqs/, /refund_returns/ and the
     * other three routed slugs.
     *
     * ── THE `seo` COLUMN ON THIS ROW WAS WRITTEN AND READ BY NOTHING ────────
     *
     * This method passed the view a row and no SEO context at all, so every
     * content page took the layout's defaults: the `<title>` from
     * @section('title'), the description from the SITE-WIDE
     * `seo_default_description`, and the canonical from the request path.
     * `pages.seo` — the same `{title, desc, og_image, canonical, noindex}` shape
     * that `products`, `categories`, `brands` and `posts` all carry — reached
     * the storefront nowhere.
     *
     * Measured on a running server before this change, with the override saved
     * on the `faqs` row:
     *
     *     seo.title     "OVERRIDE TITLE"        <title>Frequently Asked Questions · K-Beauty Bliss</title>
     *     seo.desc      "OVERRIDE DESCRIPTION"  <meta name="description" content="Shop Korean skincare in the UAE — …">
     *     seo.canonical /somewhere-else/        <link rel="canonical" href="…/faqs/">
     *     seo.noindex   true                    <meta name="robots" content="index, follow">
     *
     * The last line is the one that matters beyond tidiness. `SeoAudit::scanPages()`
     * SKIPS a page whose `seo.noindex` is set — it treats the page as
     * deindexed and stops auditing it — while the page itself went on inviting
     * the crawl. So the one screen the owner checks reported a page as out of
     * the index because he had asked for it, and the page was in it. That is the
     * same shape as the /cart leak CLAUDE.md records: two files disagreeing
     * about one decision, with the half nobody looks at being the wrong one.
     *
     * ── WHY EVERY KEY IS CONDITIONAL, AND NOT MERGED WITH A DEFAULT ─────────
     *
     * Rule 1. A page with no override must render byte-for-byte what it renders
     * today, and the layout's defaults are not reproducible from here: the title
     * comes from a Blade section this method cannot see, and the description
     * falls through Seo::describe() to a setting. So a key is passed only when
     * the override actually carries it, and an untouched shop hands the layout
     * an empty array — which array_merge treats as absent, exactly as before.
     * PageSeoOverridesTest pins the byte-identical half as well as the four
     * override cases.
     *
     * `title_is_final` and `title_token` travel with a title override for the
     * same reason `post()` sends them: an override goes through
     * TitleTemplate::render(), which DELETES any token it was not handed, so a
     * value carrying Yoast's `%%title%%` would otherwise publish the site name
     * alone — the defect that reached production on 671 product pages.
     *
     * NOT added here, deliberately: a `BreadcrumbList`. A content page emits
     * none today, and adding one would change all seven pages on a shop that
     * has set no override at all, which rule 1 forbids and which is a separate
     * decision with its own pin to advance.
     */
    public function show(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $override = is_array($page->seo) ? $page->seo : [];
        $seoCtx = [];

        if (trim((string) ($override['title'] ?? '')) !== '') {
            $seoCtx['title'] = (string) $override['title'];
            $seoCtx['title_is_final'] = true;
            $seoCtx['title_token'] = (string) $page->title;
        }

        if (trim((string) ($override['desc'] ?? '')) !== '') {
            $seoCtx['description'] = (string) $override['desc'];
        }

        if (trim((string) ($override['og_image'] ?? '')) !== '') {
            $seoCtx['image'] = (string) $override['og_image'];
        }

        /*
         * A canonical override is absolutised and scheme-checked by
         * Support\Seo::canonical() before it becomes an href, exactly as the
         * per-product and per-category overrides are, and SeoAudit's
         * `bad_canonical` check reports one pointing off-site. This method adds
         * no second opinion about it.
         */
        if (trim((string) ($override['canonical'] ?? '')) !== '') {
            $seoCtx['url'] = (string) $override['canonical'];
        }

        if (! empty($override['noindex'])) {
            $seoCtx['noindex'] = true;
        }

        return view('store.page', [
            'page' => $page,
            'settings' => $this->settings,
            // The layout reads `$seoCtx` and merges it over its own defaults;
            // see resources/views/layouts/store.blade.php. An empty array is the
            // no-override case and merges to nothing.
            'seoCtx' => $seoCtx,
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
            /*
             * `id`, for the same reason as post() below: a translation is
             * looked up by (group, item_id, field), so a row loaded without its
             * key cannot find its own Arabic and would print the English on a
             * page that is translated everywhere else. One more column on a
             * query that is already fetching the rows.
             */
            ->get(['id', 'slug', 'title', 'excerpt', 'tag', 'cover', 'published_at']);

        $base = self::siteBase();
        /*
         * `collection`, not `website` — docs/SEO-MODULE-ROUND-4.md §5.
         *
         * Lane S4 found this and could not make the edit: /skincare-guide/ is a
         * listing of articles and said NOTHING ABOUT ITSELF in the graph, in
         * either language, because `type: website` emits the site-wide WebSite
         * node and no node for this document. Every category archive in this
         * shop publishes a CollectionPage; the Journal index, which is the same
         * shape of page, published none.
         *
         * Seo::jsonLd()'s `collection` branch emits CollectionPage with this
         * page's own `url` and `inLanguage`, which is what makes the Arabic
         * index say it is Arabic rather than inheriting the English one's
         * claim. `collection.items` is deliberately NOT passed: that branch
         * only accepts a list when the canonical is self-referencing, and a
         * list of articles here is a later decision rather than part of
         * naming the page.
         *
         * `name` is the listing's own name and not the page title, for the
         * reason the branch's header gives: seo_title_template appends the site
         * name to every <title>, so passing the title would name the shop twice
         * in one node — once here and once in the Organization beside it.
         */
        $seo = Seo::render([
            'type' => 'collection',
            'collection' => ['name' => 'The Glow Journal'],
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
            /*
             * `id` IS PART OF THE SELECT AND IS NOT DECORATION. A translation
             * is looked up by (group, item_id, field), so a model loaded
             * without its key has no id to look itself up by — t() would
             * silently answer the English for ever, on a page that looks
             * translated everywhere else. Costs nothing: the same query, one
             * more column, and the rows are already being fetched.
             */
            ->get(['id', 'slug', 'title', 'tag', 'cover']);

        $base = self::siteBase();
        $seoOverride = is_array($post->seo) ? $post->seo : [];
        $canonical = $base . '/' . $post->slug . '/';

        $seo = Seo::render([
            'type' => 'article',
            // t() throughout: an Arabic article whose <title> and
            // <meta description> are English advertises itself as untranslated
            // in the one place a shopper decides whether to click.
            'title' => $seoOverride['title'] ?? $post->t('title'),
            'title_is_final' => !empty($seoOverride['title']),
            /*
             * WHAT `%%title%%` MEANS INSIDE THE OVERRIDE ABOVE.
             *
             * An override sets `title_is_final`, which sends the typed string
             * through Seo::titleOf()'s template branch — and
             * TitleTemplate::render() DELETES any token it was not handed.
             * `%%title%%` is Yoast's word for the POST title, which is this
             * post's own; Seo cannot recover it from `title`, because in that
             * branch `title` IS the template. Without this key, an editor
             * typing Yoast's shipped default into Content → Journal → SEO →
             * Page title publishes the site name alone, which is the defect
             * that reached production on 671 product pages and was fixed there
             * by adding exactly this key to Store\ProductController::show().
             *
             * t(), matching the two lines around it, so an Arabic article's tab
             * is not the English headline.
             *
             * INERT WITHOUT AN OVERRIDE. Seo::titleOf() reads `title_token`
             * only inside the `title_is_final` branch, so on the ordinary
             * article — no override, no final title — this key is never looked
             * at and the <head> is byte-for-byte what it was.
             */
            'title_token' => $post->t('title'),
            'description' => $seoOverride['desc'] ?? $post->t('excerpt') ?? '',
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
                'title' => $post->t('title'),
                'published_at' => optional($post->published_at)->toAtomString(),
            ],
            // 'Home' and 'Journal' are the English defaults of these two keys,
            // which is what these literals were. The visible crumb on this page
            // already prints the same two.
            'breadcrumb' => [
                ['name' => __('store.breadcrumb.home'), 'url' => $base . '/'],
                ['name' => __('store.journal.nav_journal'), 'url' => $base . '/skincare-guide/'],
                ['name' => $post->t('title'), 'url' => $canonical],
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
