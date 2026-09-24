<?php

namespace App\Providers;

use App\Services\CartService;
use App\Models\Redirect;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Services\Update\BackupService;
use App\Services\Update\UpdateRunner;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Update services need filesystem paths, and Laravel cannot guess a
         * string constructor argument — asking the container for BackupService
         * without these throws "Unresolvable dependency". That was the 500 on
         * the Updates page.
         */
        $this->app->singleton(BackupService::class, fn () => new BackupService(
            storage_path('app/updates/backups'),
            base_path(),
        ));

        $this->app->singleton(UpdateRunner::class, fn ($app) => new UpdateRunner(
            $app->make(BackupService::class),
            base_path(),
        ));

        /*
         * One CartService per request, shared by everything that needs it.
         *
         * `scoped` rather than `singleton`: a singleton would survive between
         * requests on a queue worker and hand one customer's cart to the next.
         * Scoped is reset at the end of each request, which is exactly right.
         *
         * Without this, the layout's view composer and the cart controller each
         * built their own instance and resolved the cart separately — two
         * lookups per page, and two independent memos. (Rule 27)
         */
        $this->app->scoped(CartService::class);
        $this->app->scoped(SettingsService::class);

        /*
         * Bilingual foundation (Lane EP). Two bindings and nothing else.
         *
         * 1. __() READS THE DATABASE. Laravel's own translator is kept; only
         *    its loader is decorated, so validation messages and pagination
         *    wording still come from the framework's files and every Blade
         *    conversion the later lanes do is ordinary __() that any Laravel
         *    developer already knows.
         *
         *    This has to be an override rather than a new helper because the
         *    server has no shell and lang/ is not on UpdateGuard's allowed
         *    prefixes — a translation the owner types can only live in the
         *    database, and the framework has to be told to look there. See
         *    App\Services\Translation\DatabaseTranslationLoader.
         *
         *    extend(), not a fresh singleton: the FileLoader underneath is
         *    whatever the framework configured, including any path a package
         *    has added, and rebuilding it here would silently drop those.
         *
         * 2. THE MACHINE-TRANSLATION PROVIDER, which is NullProvider unless the
         *    owner has saved his own API key. That default is what makes the
         *    manual path — the one that must be free to operate — work with
         *    nothing configured, and what keeps the test suite off the network.
         *
         *    `scoped` rather than `singleton`, so a queue worker that runs one
         *    job before the key is saved and another after does not keep
         *    answering "no provider" for the life of the process. Same trap as
         *    Setting::map(), one layer up.
         */
        $this->app->extend('translation.loader', static fn ($loader) => new \App\Services\Translation\DatabaseTranslationLoader($loader));

        $this->app->scoped(\App\Services\Translation\TranslationProvider::class, static function () {
            $key = \App\Services\Translation\TranslationCredentials::apiKey();

            return $key === null
                ? new \App\Services\Translation\NullProvider
                : new \App\Services\Translation\GoogleProvider($key);
        });
    }

    public function boot(): void
    {
        /*
         * ── THE LINE THAT MAKES /ar EXIST, FROM A FILE A PACKAGE CAN SHIP ───
         *
         * SetLocaleFromPath has to run BEFORE the router, because it strips the
         * /ar segment so every existing route, RESERVED_SLUGS, the redirect map
         * and the sitemap can stay exactly as they are. Only the global
         * pipeline runs that early, which is why bootstrap/app.php prepends it.
         *
         * AND bootstrap/ IS ON BuildPackage::NEVER_SHIP. So that line has never
         * reached the server, and could not: it was documented as the one change
         * in this feature to be applied by hand. It was not applied, which is
         * why the owner reported "/ar gives 404 everywhere" after switching
         * Arabic on — the switch was on and the middleware that acts on it was
         * not there.
         *
         * A hand-edit to bootstrap/app.php is the worst thing to ask of an owner
         * with no shell: a mistake in that file stops the application booting at
         * all, which also stops the updater that would roll it back.
         *
         * PROVIDERS BOOT BEFORE THE PIPELINE IS BUILT, which is what makes this
         * work. Kernel::handle() calls bootstrap() — including BootProviders —
         * and only then reads $this->middleware to build the Pipeline. So a
         * prepend from here lands in the global stack in time, and this file
         * ships in a package like any other.
         *
         * BOTH REGISTRATIONS ARE SAFE TOGETHER. prependMiddleware() does an
         * array_search before it unshifts, so a server whose bootstrap/app.php
         * DOES carry the hand-applied line gets one registration, not two. The
         * bootstrap line is deliberately left in place rather than removed: a
         * shop that already applied it must not be broken by this, and losing
         * the global-pipeline registration on a future Laravel upgrade would
         * silently turn Arabic off again.
         *
         * STILL INERT UNTIL SWITCHED ON. The middleware reads Locale::enabled(),
         * false until Arabic is turned on in Translation → Settings, so this
         * changes nothing a shopper of an English-only shop can see.
         */
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if (method_exists($kernel, 'prependMiddleware')) {
            /*
             * ── THE REDIRECT TABLE, FINALLY WIRED TO SOMETHING ──────────────
             *
             * CheckRedirects has been written as middleware since the day it
             * was created and was registered as middleware nowhere. The only
             * live reader of the `redirects` table was the
             * NotFoundHttpException closure further down this method, and that
             * has one absolute consequence, measured rather than assumed:
             * AN ADDRESS THAT DOES NOT 404 COULD NEVER BE REDIRECTED BY A ROW.
             * Rows written for `/shop/`, a category archive and a product
             * address, all enabled and all matching byte for byte, changed
             * nothing at all. The old shop has five years of URLs and every one
             * of them that collides with a slug this app serves kept serving
             * the wrong page. See the class's own comment and
             * docs/GP-ADDRESSES-LAND.md.
             *
             * PREPENDED FIRST, WHICH MAKES IT RUN LAST OF THE THREE.
             * prependMiddleware() puts each new entry at the front, so the
             * order of these three calls is the reverse of their execution
             * order, and both ends of that matter:
             *
             *   CanonicalHost runs before it — no sense redirecting a path on
             *   a host the request is about to be forwarded off. It folds the
             *   path correction into its own hop through
             *   CheckRedirects::lookup(), so the visitor still makes one.
             *
             *   SetLocaleFromPath runs before it — /ar is stripped before the
             *   router sees the request and `redirects.source` carries no
             *   locale segment, so an Arabic visitor following an old link
             *   matches the same row an English one does. Url::redirect() puts
             *   the segment back on the way out.
             *
             * HERE AND NOT ONLY IN bootstrap/app.php, for the reason the block
             * above gives: bootstrap/ is on BuildPackage::NEVER_SHIP and
             * UpdateGuard's forbidden list, so a registration written only
             * there can never reach the live server. The bootstrap line exists
             * too and the two are safe together — prependMiddleware()
             * array_searches before it unshifts.
             *
             * INERT ON A SHOP WITH NO ROWS, which is what makes applying this
             * safe: it reads a cached set of `redirects.source` values and
             * steps aside when the incoming path is not one of them, at a cost
             * of zero queries. Nothing a shopper can see moves until a row
             * exists for an address the shop serves — which is the whole point.
             */
            $kernel->prependMiddleware(\App\Http\Middleware\CheckRedirects::class);

            $kernel->prependMiddleware(\App\Http\Middleware\SetLocaleFromPath::class);

            /*
             * CanonicalHost — forwards a listed alias to the real address and
             * marks anything private noindex. Registered HERE, and not in
             * bootstrap/app.php, for the reason the block above exists:
             * bootstrap/ is on BuildPackage::NEVER_SHIP and UpdateGuard's
             * forbidden list, so a registration there can never reach the
             * server in a package. This one can.
             *
             * BEFORE SetLocaleFromPath in execution order -- prependMiddleware
             * puts each new entry at the front, so the last one prepended runs
             * first -- which is what we want: there is no sense translating a
             * request that is about to be sent to another host.
             *
             * INERT UNTIL CONFIGURED. SiteHost::classify() answers CANONICAL
             * for every host while `canonical_host` is empty, which is how it
             * ships. Applying this package changes nothing until somebody fills
             * the field in on Settings -> Site address.
             */
            $kernel->prependMiddleware(\App\Http\Middleware\CanonicalHost::class);
        }

        /*
         * CacheHeaders -- APPENDED TO THE `web` GROUP, not prepended globally,
         * and for the opposite reason to the two above. Those two have to run
         * BEFORE the router: one rewrites the path so /ar matches an existing
         * route, the other may send the request to another host entirely. This
         * one sets a header on a finished response, so it wants to run LAST,
         * after every other middleware has stopped touching it.
         *
         * ▲ AND IT IS THE THIRD REGISTRATION IN THIS METHOD THAT EXISTS
         * BECAUSE bootstrap/app.php CANNOT SHIP. docs/FQ-CACHE-HEADERS.md
         * wrote out the one line to add to $middleware->web(append: [...]) by
         * hand; it was never applied, so App\Http\Middleware\CacheHeaders has
         * been complete, tested and called by nothing since Lane FQ. That is
         * the same story as the /ar block above, one file over.
         *
         * SAFE ALONGSIDE THE HAND-APPLIED LINE. appendMiddlewareToGroup() does
         * an array_search before it appends, so a server whose bootstrap/app.php
         * does carry it gets one registration and not two -- and a doubled
         * Cache-Control middleware is exactly the "two middlewares setting the
         * same header" the class's own docblock warns about.
         *
         * INERT UNTIL SWITCHED ON, which is the part that matters on a shop
         * that is already taking orders. CacheSettings::ENABLED ships false and
         * the middleware returns the response untouched until the owner turns
         * it on from Platform -> Cache. Applying this package changes no
         * response header anywhere.
         *
         * The isset() guard is not defensive padding: appendMiddlewareToGroup()
         * THROWS InvalidArgumentException for a group it does not know, and a
         * provider that throws is a shop that does not boot.
         */
        if (method_exists($kernel, 'appendMiddlewareToGroup')
            && method_exists($kernel, 'getMiddlewareGroups')
            && isset($kernel->getMiddlewareGroups()['web'])) {
            $kernel->appendMiddlewareToGroup('web', \App\Http\Middleware\CacheHeaders::class);
        }

        /*
         * `media_usages` — which image belongs to which product, brand or
         * category. One call, because the hooks themselves live with the
         * writer rather than being spelled out here: this file is shared by
         * every lane and four more closures in it is how it becomes
         * unreadable. See App\Support\MediaUsageWriter for what is
         * registered and, more importantly, for the two places the table can
         * still fall behind.
         */
        \App\Support\MediaUsageWriter::listen();

        /*
         * `orders.locale` — the language the customer was shopping in.
         *
         * One call, registering an Order::creating hook, for the same reason
         * MediaUsageWriter is one call: this file is shared by every lane and
         * another closure in it is how it stops being readable.
         *
         * A model hook rather than a line in CheckoutController, and that is
         * deliberate rather than convenient. Orders are created in FIVE places
         * — Store\CheckoutController, Api\CheckoutController,
         * ManualOrderBuilder, DemoContentController and GatewayPreflight — and
         * a rule written at four of them is a rule. See
         * App\Support\OrderLocale for what it does when there is no request.
         */
        \App\Support\OrderLocale::listen();

        /*
         * Shipping zones, their locations and their methods are read on EVERY
         * storefront page — the header's free-delivery bar asks for the store
         * country's threshold — and used to cost two queries every time they
         * were asked. ShippingService now reads the whole set once and caches
         * it (a dozen rows; it is configuration, not data).
         *
         * These hooks are what make that cache safe to keep forever rather than
         * on a TTL: every write to any of the three tables drops it, so an edit
         * on Store → Shipping is live on the next request exactly as it was
         * before the cache existed. Same shape, and the same reason, as the
         * catalogue hooks further down that evict the homepage fragments.
         *
         * `saved` AND `deleted`: switching a method off is a save, removing a
         * zone's last country is a delete, and both change what a shopper is
         * quoted.
         */
        foreach ([ShippingZone::class, ShippingZoneLocation::class, ShippingMethod::class] as $shippingModel) {
            $shippingModel::saved(fn () => ShippingService::flushZones());
            $shippingModel::deleted(fn () => ShippingService::flushZones());
        }

        /*
         * Redirects — the same shape, one line down, and for the same reason.
         *
         * CheckRedirects runs on every storefront request now, and answers "no
         * redirect for this path" out of a cached set of `redirects.source`
         * values rather than a query. That is what keeps a page that redirects
         * nothing at zero queries against the table; these two hooks are what
         * make the cache safe to keep forever rather than on a TTL. A row
         * added, switched off or deleted on Store → Redirects is live on the
         * next request, exactly as it was before the index existed.
         *
         * HERE AND NOT IN Redirect::booted(), which was tried first and does
         * not hold. Eloquent runs booted() ONCE PER PROCESS — Model::$booted is
         * static and keyed by class — while the event dispatcher is replaced
         * with every application instance. `Redirect` is first booted by the
         * Phase 9 seed migration, so in any process that outlives one
         * application (the suite, a queue worker, `artisan migrate`) the
         * listener booted() registered is attached to a dispatcher nothing
         * dispatches to, and the index silently stops being evicted. Measured
         * rather than reasoned about: a row created after a page had been
         * rendered was absent from the index, and the address it named went on
         * serving its own page. This method runs per application instance.
         *
         * `saved` AND `deleted`: switching a redirect off is a save, and a row
         * that no longer exists has to stop claiming its address.
         */
        Redirect::saved(fn () => \App\Http\Middleware\CheckRedirects::flushIndex());
        Redirect::deleted(fn () => \App\Http\Middleware\CheckRedirects::flushIndex());

        /*
         * Redirects & 404 manager.
         *
         * ▲ THIS IS NO LONGER THE ONLY READER OF THE TABLE, and the comment
         * that used to stand here said it was. CheckRedirects is registered as
         * global middleware at the top of this method, so it answers first for
         * every path a row claims — including the ones this closure could never
         * see, which was the defect: a redirect for an address the shop already
         * served could not fire at all.
         *
         * THE CHECK STAYS HERE ANYWAY, and it is belt and braces rather than
         * duplication. A host whose provider registration somehow did not land
         * keeps redirecting 404s exactly as it did before, which is the failure
         * mode that has to stay boring. It calls the same static findMatch(),
         * so the two cannot answer differently.
         *
         * The exception handler reliably catches every 404 regardless of source
         * (an unmatched path via the fallback route, or a controller's own 404
         * for a renamed slug), so the redirect check happens first here; only
         * when nothing matches does this fall through to logging it.
         */
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(
            function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
                $redirect = \App\Http\Middleware\CheckRedirects::findMatch($request);

                if ($redirect !== null) {
                    \App\Http\Middleware\CheckRedirects::recordHit($redirect);

                    /*
                     * Url::redirect(), not the bare target. See
                     * CheckRedirects::handle() for the whole argument; the
                     * short version is that redirect() hands a relative target
                     * to Laravel's UrlGenerator, which strips the trailing
                     * slash and knows nothing about the reader's language.
                     * Measured both ways on a running server: 15 of 15 rows
                     * landed on a non-canonical address, and an Arabic reader
                     * following one was dropped onto the English page. The
                     * BASE PATH was already right — UrlGenerator takes it from
                     * the request root — which is a correction to
                     * docs/GB-MEDIA-AND-REDIRECTS.md §6.4; the other two were
                     * not. This is the copy that actually runs.
                     */
                    return redirect(\App\Support\Url::redirect($redirect->target), $redirect->code);
                }

                if ($request->isMethod('GET') && !$request->is('admin*', 'admin-api*', 'api*')) {
                    \App\Support\NotFoundLogger::record($request->path(), $request->header('referer'));
                }

                return null;
            }
        );

        // In production, generate https URLs and set secure cookies behind the host's TLS proxy.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // The mini-cart drawer renders with every page, so it is correct on a
        // hard refresh rather than only after an add. Previously the header
        // badge came from the server while the drawer waited for JavaScript.
        \Illuminate\Support\Facades\View::composer(
            'partials.cart-drawer',
            \App\View\Composers\CartDrawerComposer::class
        );

        // @shortcodes($page->content) — renders [kbb_products ...] anywhere:
        // pages, posts, HTML blocks.
        \Illuminate\Support\Facades\Blade::directive(
            'shortcodes',
            fn ($expr) => "<?php echo \\App\\Support\\Shortcodes::render({$expr}); ?>"
        );

        // IndexNow: ping on publish, not on every save — a draft being
        // autosaved every few seconds would otherwise spam the endpoint
        // with URLs nobody can even reach yet. Fire-and-forget; IndexNow
        // itself never throws (see the service), so this can't turn a
        // product/post save into a failure over a search-engine ping.
        //
        // Deliberately NOT using Product::url() here — that goes through
        // Url::to(), which adds its own base-path prefix (e.g.
        // /kbb-upgrade), and site_url below already includes that same
        // prefix as part of the configured canonical base. Combining both
        // double-counts it (…/kbb-upgrade/kbb-upgrade/product/…, a URL
        // that 404s) — the path is built by hand instead, same as the
        // Post hook just below, which never had this problem because it
        // never went through Url::to() in the first place.
        \App\Models\Product::saved(function (\App\Models\Product $product) {
            /*
             * ProductVisibility::isLive(), not a hand-written status check.
             *
             * A product scheduled for next Tuesday is `publish` and visible and
             * satisfies the old two-part test the moment it is saved — so this
             * hook told Google to come and crawl a URL that will 404 until
             * Tuesday. Submitting a soft-404 to IndexNow is worse than
             * submitting nothing: it spends crawl budget and teaches the index
             * that the URL is missing, on exactly the page a launch date exists
             * to make a good first impression on.
             *
             * Nothing re-submits when the date passes, and that is deliberate
             * rather than a gap: the product is in the sitemap from that moment
             * on, and a real edit after launch saves the row again and pings
             * then. Manufacturing a ping with no scheduler to run it is the
             * problem this whole package avoids.
             */
            if (\App\Support\ProductVisibility::isLive($product)) {
                $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
                if ($base !== '') {
                    \App\Services\Seo\IndexNow::submitOne($base . '/product/' . $product->slug . '/');
                }
            }
        });

        \App\Models\Post::saved(function (\App\Models\Post $post) {
            if (($post->status ?? null) === 'published' && $post->slug) {
                $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
                if ($base !== '') {
                    \App\Services\Seo\IndexNow::submitOne($base . '/' . $post->slug . '/');
                }
            }
        });

        /*
         * Homepage fragment eviction.
         *
         * Store\HomeController serves the most-hit URL on the site out of eight
         * Cache::remember() keys — the product rails at 600s, the brand strip,
         * category tiles, journal row, routine, catalogue count and brand total
         * at 900s. All eight are built from products, brands, categories and
         * posts, and until this hook NOTHING ON ANY WRITE PATH EVICTED THEM:
         * HomeController::flushCache() existed with no callers at all. A price
         * edit, a sale, a `featured` toggle or an out-of-stock left the homepage
         * advertising the old answer for up to fifteen minutes, so a shopper
         * could click a tile at one price and land on a page at another.
         *
         * Bound to the MODEL rather than to each admin controller, for the same
         * reason the IndexNow ping above is: it is the one place every write has
         * to pass through. The review wall was evicted the other way, with a
         * literal key copied into each controller that writes reviews, and it
         * went stale twice because a new write path did not know to copy it.
         * Products are written from at least six places — the editor, the
         * catalogue grid and its three bulk actions, the reorder screen, the
         * importer and the seeders.
         *
         * Guarded on wasChanged() so the editor's open-and-close, which saves
         * without altering anything, does not throw away eight valid entries and
         * send the next visitor through a full rebuild.
         */
        $homeProductColumns = [
            'slug', 'name', 'brand_id', 'category_id', 'price', 'sale_price',
            'sale_starts_at', 'sale_ends_at', 'stock_status', 'image', 'rating',
            'review_count', 'featured', 'type', 'total_sales', 'status', 'is_visible',
            'deleted_at',
        ];

        /*
         * The /shop sidebar has the same hole under a different key.
         *
         * ShopController::flushSidebarCache() clears kbb.shop.cats and
         * kbb.shop.brands, both withCount(visible) tallies, and its own doc
         * comment says "call after any catalogue write". Five admin controllers
         * do call it — for category, brand and layout writes. NOT ONE PRODUCT
         * WRITE PATH DOES, and a product being hidden, published, deleted or
         * moved to another brand is exactly what those counts count. The
         * sidebar read "Cleansers (24)" next to a grid showing 23.
         *
         * Narrower column list than the homepage's: these are counts, so only
         * visibility and the brand/category a product is counted under move
         * them. A price edit does not.
         */
        $visibilityColumns = ['status', 'is_visible', 'published_at', 'brand_id', 'category_id', 'deleted_at'];

        \App\Models\Product::saved(function (\App\Models\Product $product) use ($homeProductColumns, $visibilityColumns) {
            if ($product->wasRecentlyCreated || $product->wasChanged($homeProductColumns)) {
                \App\Http\Controllers\Store\HomeController::flushCache();
            }

            if ($product->wasRecentlyCreated || $product->wasChanged($visibilityColumns)) {
                \App\Http\Controllers\Store\ShopController::flushSidebarCache();
            }
        });

        // Force-delete and soft-delete both land here; a restore is a save.
        \App\Models\Product::deleted(function () {
            \App\Http\Controllers\Store\HomeController::flushCache();
            \App\Http\Controllers\Store\ShopController::flushSidebarCache();
        });

        \App\Models\Post::saved(function (\App\Models\Post $post) {
            // `cover`, not `image` — the journal row renders posts.cover, and
            // there is no posts.image at all. wasChanged() on a column that does
            // not exist is silently never dirty, so naming the wrong one here
            // would have left a changed cover photo stale for fifteen minutes
            // with nothing failing anywhere.
            if ($post->wasRecentlyCreated || $post->wasChanged(['slug', 'title', 'excerpt', 'status', 'published_at', 'cover'])) {
                \Illuminate\Support\Facades\Cache::forget('kbb.home.posts');
            }
        });

        \App\Models\Post::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('kbb.home.posts');
        });

        // The strip is ordered by visible product count and the total is quoted
        // in the copy, so a rename, a new brand and a removal all move it.
        \App\Models\Brand::saved(function (\App\Models\Brand $brand) {
            if ($brand->wasRecentlyCreated || $brand->wasChanged(['name', 'slug', 'logo'])) {
                \Illuminate\Support\Facades\Cache::forget('kbb.home.brands');
                \Illuminate\Support\Facades\Cache::forget('kbb.home.brandcount');
            }
        });

        \App\Models\Brand::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('kbb.home.brands');
            \Illuminate\Support\Facades\Cache::forget('kbb.home.brandcount');
        });

        // Auto-301 on slug change: 'updating' (not 'saved') because this
        // needs the slug's OLD value, which is only still available before
        // the write actually happens. Only for already-published items —
        // a draft's slug changing isn't a broken link anywhere yet, since
        // nothing has ever linked to it.
        \App\Models\Product::updating(function (\App\Models\Product $product) {
            if (!$product->isDirty('slug') || $product->getOriginal('status') !== 'publish') {
                return;
            }

            $oldSlug = $product->getOriginal('slug');
            if (!$oldSlug || $oldSlug === $product->slug) {
                return;
            }

            \App\Support\RedirectManager::autoCreate('/product/' . $oldSlug . '/', '/product/' . $product->slug . '/');
        });

        \App\Models\Post::updating(function (\App\Models\Post $post) {
            if (!$post->isDirty('slug') || $post->getOriginal('status') !== 'published') {
                return;
            }

            $oldSlug = $post->getOriginal('slug');
            if (!$oldSlug || $oldSlug === $post->slug) {
                return;
            }

            \App\Support\RedirectManager::autoCreate('/' . $oldSlug . '/', '/' . $post->slug . '/');
        });
    }
}
