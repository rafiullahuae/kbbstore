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
            if ($product->status === 'publish' && $product->is_visible) {
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
