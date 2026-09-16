<?php

namespace App\Providers;

use App\Services\CartService;
use App\Services\SettingsService;
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
    }

    public function boot(): void
    {
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

        // Redirects & 404 manager. Both checks live here, in the exception
        // handler, rather than as real middleware — see CheckRedirects'
        // own doc comment for why that approach didn't actually work for
        // most requests despite looking correct. The exception handler
        // reliably catches every 404 regardless of source (an unmatched
        // path via the fallback route, or a controller's own 404 for a
        // renamed slug), so the redirect check happens first here; only
        // when nothing matches does this fall through to logging it.
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(
            function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
                $redirect = \App\Http\Middleware\CheckRedirects::findMatch($request);

                if ($redirect !== null) {
                    \App\Http\Middleware\CheckRedirects::recordHit($redirect);

                    return redirect($redirect->target, $redirect->code);
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
