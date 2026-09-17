<?php

use App\Http\Controllers\Store\PageController;
use App\Http\Controllers\Store\SeoFilesController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Admin path
|------------------------------------------------------------------------------
|
| Set KBB_ADMIN_PATH in .env. Named routes (admin.login, admin.updates, ...) are
| unchanged, so every redirect and link keeps working wherever the path points.
|
*/
$adminPath = \App\Services\AdminPathService::current();

// The old paths return 404 rather than redirect: a redirect would announce where
// the admin moved to, which defeats the point of moving it.
if ($adminPath !== 'admin') {
    Route::any('/admin/{any?}', fn () => abort(404))->where('any', '.*');
}


// Storefront — each page is your finalized design, served verbatim.
Route::get('/sitemap.xml', [SeoFilesController::class, 'sitemap']);
Route::get('/robots.txt',  [SeoFilesController::class, 'robots']);
Route::get('/llms.txt',    [SeoFilesController::class, 'llms']);
Route::get('/{key}.txt', [SeoFilesController::class, 'indexNowKeyFile'])
    ->where('key', '[a-zA-Z0-9\-]{8,128}');
// Home — server-rendered. The original page fetched /api/products from the
// browser, which 404s under a subdirectory, and kept its cart in a JavaScript
// array, which is why the header badge moved while the server saw nothing.
Route::get('/', \App\Http\Controllers\Store\HomeController::class)->name('home');

// Newsletter. Kept at /api/subscribe: the layout already publishes that path as
// KBB.routes.subscribe, and the homepage form was the thing pointing elsewhere.
// The second route is the address the form used to post to, so any cached page
// still holding it lands somewhere real instead of on a 404.
// Throttled because the handler answers differently for an address already on
// the list ('nl_duplicate') than for a new one ('nl_success'), which makes an
// unlimited public POST a newsletter-membership oracle — and, separately,
// an unlimited way to fill the subscribers table. 10/minute is far above any
// real signup rate and well below a useful enumeration rate.
Route::post('/api/subscribe', [\App\Http\Controllers\Store\SubscribeController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('subscribe');
Route::post('/subscribe', [\App\Http\Controllers\Store\SubscribeController::class, 'store'])
    ->middleware('throttle:10,1');
// Shop — server-rendered with real URLs per the URL Contract (Phase 2).
// /shop/page/2/ pagination, ?filter_brands=, ?orderby= all preserved.
// URL Contract U-07: /shop/page/2/ is indexed on the live site. The theme
// paginates with ?paged=, so this preserves the old address with a 301 rather
// than passing the page number in as a category slug.
Route::get('/shop/page/{page}', function (int $page) {
    return redirect(\App\Support\Url::redirect('/shop/') . ($page > 1 ? '?paged=' . $page : ''), 301);
})->whereNumber('page')->name('shop.page');
// Curated listings. These are the header links that had no route at all.
Route::get('/new-in', [\App\Http\Controllers\Store\CollectionController::class, 'show'])
    ->defaults('key', 'new-in')->name('collection.new');
Route::get('/best-sellers', [\App\Http\Controllers\Store\CollectionController::class, 'show'])
    ->defaults('key', 'best-sellers')->name('collection.best');
Route::get('/super-sale', [\App\Http\Controllers\Store\CollectionController::class, 'show'])
    ->defaults('key', 'super-sale')->name('collection.sale');
Route::get('/everything-under-54-aed', [\App\Http\Controllers\Store\CollectionController::class, 'show'])
    ->defaults('key', 'under-54')->name('collection.budget');

// Wishlist. Held in a cookie, so it works for guests.
Route::get('/my-wishlist', [\App\Http\Controllers\Store\WishlistController::class, 'index'])->name('wishlist');
Route::get('/wishlist', fn () => redirect(\App\Support\Url::to('/my-wishlist/')));
Route::post('/wishlist/toggle', [\App\Http\Controllers\Store\WishlistController::class, 'toggle'])->name('wishlist.toggle');
Route::get('/wishlist/ids', [\App\Http\Controllers\Store\WishlistController::class, 'ids']);

Route::get('/reviews/captcha', [\App\Http\Controllers\Store\ReviewController::class, 'captcha']);
Route::post('/reviews/submit', [\App\Http\Controllers\Store\ReviewController::class, 'submit']);
Route::post('/reviews/{review}/helpful', [\App\Http\Controllers\Store\ReviewController::class, 'helpful']);

// Editable content pages. Kept last among the storefront routes so a real
// page never shadows a functional one.
Route::get('/privacy-policy', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'privacy-policy');
Route::get('/terms-and-conditions', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'terms-and-conditions');
Route::get('/delivery', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'delivery');
Route::get('/refund_returns', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'refund_returns');
Route::get('/faqs', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'faqs');
Route::get('/about', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'about');
Route::get('/contact-us', [\App\Http\Controllers\Store\PageController::class, 'show'])->defaults('slug', 'contact-us');

// Search — the layout has been advertising this endpoint with nothing serving it.
Route::get('/api/search', [\App\Http\Controllers\Store\SearchController::class, 'suggest'])->name('search.suggest');
Route::get('/api/search/starter', [\App\Http\Controllers\Store\SearchController::class, 'starter']);
Route::get('/api/human-check', [\App\Http\Controllers\Store\AccountPanelController::class, 'question']);

Route::get('/shop', [\App\Http\Controllers\Store\ShopController::class, 'index'])->name('shop');

// Category archives render through the same controller — same filters, same
// grid, same sort. URL Contract U-03: /product-category/{nested/path}/
/*
 * Repointed from an inline closure at a real controller, because the closure
 * could not 404 and that was doing measurable harm.
 *
 * It took basename() of the path and handed the last segment to ShopController,
 * which falls back to the whole catalogue for a slug it does not recognise. So
 * /product-category/zz-nope/ answered 200 with every product on the site, and
 * so did /product-category/made-up/zz-child/ — verified against a running
 * server before this change. That is an unbounded crawlable space of soft 404s
 * all serving the same duplicate content, and it also meant a renamed category
 * did not break its old URL so much as leave it answering 200 with the wrong
 * page, forever and invisibly.
 */
Route::get('/product-category/{path}', [\App\Http\Controllers\Store\CategoryArchiveController::class, 'show'])
    ->where('path', '.*')->name('category');
// Product — /product/{slug}/ per the URL Contract. The old ?slug= form redirects
// so any existing link keeps working rather than 404ing.
Route::get('/product/{slug}', [\App\Http\Controllers\Store\ProductController::class, 'show'])->name('product.show');
Route::get('/product', function (\Illuminate\Http\Request $request) {
    $slug = (string) $request->query('slug', '');

    return $slug === ''
        ? redirect(\App\Support\Url::redirect('/shop/'))
        : redirect(\App\Support\Url::redirect('/product/' . $slug . '/'), 301);
})->name('product');
// Cart — server-rendered page plus the endpoints the drawer uses. These sit in
// web.php, not api.php: the cart is identified by a cookie and protected by
// CSRF, and Laravel 11's api.php has neither session nor cookie middleware.
Route::get('/cart', [\App\Http\Controllers\Store\CartController::class, 'page'])->name('cart');

Route::prefix('api/cart')->group(function () {
    // Read-only diagnostic: cookie, cart id, line count. ADMIN ONLY, and it
    // has to be. Its own doc comment used to say "nothing sensitive" while the
    // handler returned the five most recently active carts SITE-WIDE — their
    // ids, customer_ids and token prefixes — to anyone who opened the URL.
    // That is a cross-customer leak and an account-enumeration oracle, and it
    // was live. The guard goes here rather than in the handler so that it
    // cannot be lost again by an edit to the controller.
    Route::get('/debug', [\App\Http\Controllers\Store\CartController::class, 'debug'])
        ->middleware('auth:admin');
    Route::get('/drawer',  [\App\Http\Controllers\Store\CartController::class, 'drawer']);
    Route::post('/add',    [\App\Http\Controllers\Store\CartController::class, 'add']);
    Route::post('/update', [\App\Http\Controllers\Store\CartController::class, 'update']);
    Route::post('/remove', [\App\Http\Controllers\Store\CartController::class, 'remove']);
    // Throttled: CouponService::validate() answers differently for a code that
    // does not exist, one not active YET, one expired, and one fully redeemed.
    // That is deliberate UX, but unthrottled it lets anyone brute-force the
    // coupon namespace at line speed — including discovering promotions that
    // have not launched. 20/minute is far above any real shopper and far below
    // a useful enumeration rate. Found by Lane Z.
    Route::post('/coupon', [\App\Http\Controllers\Store\CartController::class, 'coupon'])
        ->middleware('throttle:20,1');
});

// Checkout — ported page, server-side totals, order placement.
Route::get('/checkout',         [\App\Http\Controllers\Store\CheckoutController::class, 'page'])->name('checkout');
Route::post('/api/checkout/rates', [\App\Http\Controllers\Store\CheckoutController::class, 'rates'])->name('checkout.rates');
Route::post('/checkout/place',  [\App\Http\Controllers\Store\CheckoutController::class, 'place'])->name('checkout.place');
Route::get('/checkout/success', [\App\Http\Controllers\Store\CheckoutController::class, 'success'])->name('checkout.success');
Route::get('/checkout/pending', fn () => redirect(\App\Support\Url::redirect('/checkout/')))->name('checkout.pending');
Route::get('/skin-quiz',   [PageController::class, 'skinQuiz'])->name('skin-quiz');
Route::get('/reviews',     [PageController::class, 'reviewWall'])->name('review-wall');

// Phase 10 — Build my routine. Two storefront pages, at the storefront level
// and in no group: they publish nothing a shop page does not already publish,
// because the cards are the shop's own component. Both 404 unless the module is
// on, and it ships off. Mounted BEFORE the root catch-all at the end of this
// file, and 'routines' is in Store\PageController::RESERVED_SLUGS so an editor
// cannot publish a post at an address this route owns.
require __DIR__.'/build-my-routine.php';

// The journal. Four places publish /skincare-guide/ -- the homepage twice plus
// every post card, MenuDemo's Blog node, MegaMenuApiController, the admin Pages
// registry and the health check -- and nothing routed it, so all of those were
// 404s. Meanwhile canonical tags and IndexNow were submitting /post/{slug},
// which the site never linked. Published URL and canonical URL now agree.
Route::get('/skincare-guide/', [PageController::class, 'blog'])->name('blog');
// The article URL moved to the site root in 2.60.109, matching the live
// WordPress addresses the owner supplied. The legacy route and the `post` name
// now live in routes/kbb-brands-blog.php, required at the end of this file.

// The Laravel-era addresses, kept as 301s so existing links and anything
// already indexed survive.
Route::get('/blog', fn () => redirect()->route('blog', [], 301));
Route::get('/post/{slug?}', fn (string $slug = '') => $slug === ''
    ? redirect()->route('blog', [], 301)
    : redirect()->route('post', ['slug' => $slug], 301));
Route::get('/app',         [PageController::class, 'app'])->name('app');

// ---------------------------------------------------------------------------
// Store back-office (admin). Session-guarded; API lives under /admin-api in the
// same web-session context (not api.php), so the admin guard is applied.
// ---------------------------------------------------------------------------
use App\Http\Controllers\Admin\PageController as AdminPage;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;

Route::get('/' . $adminPath . '/login',  [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/' . $adminPath . '/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/' . $adminPath . '/logout',[AdminAuthController::class, 'logout'])->name('admin.logout');

// `use ($adminPath)` is required: a PHP closure does not inherit the enclosing
// scope, so without it $adminPath is undefined inside this group.
Route::middleware('auth:admin')->group(function () use ($adminPath) {
    Route::get('/' . $adminPath, [AdminPage::class, 'app'])->name('admin');

    /*
    | Reads the tail of the real error log — for diagnosing a page that's
    | throwing a 500 with nothing useful shown to the visitor. Requires being
    | logged into the admin panel, same as everything else in this group; no
    | separate token to configure or lose track of, since whoever can reach
    | this URL is already someone who can already see everything else here.
    */
    Route::get('/' . $adminPath . '/kbb-health-log', function (\Illuminate\Http\Request $request) {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return response()->json(['ok' => true, 'log' => '(no log file yet)']);
        }

        $lines = (int) $request->query('lines', 200);
        $lines = max(20, min($lines, 2000));

        // Tail without reading the whole file — this log can grow large over
        // a long-running site, and a health check should never be the thing
        // that exhausts memory on the server it is trying to diagnose.
        $handle = fopen($path, 'r');
        $buffer = '';
        $chunk = 4096;
        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        $found = 0;

        while ($pos > 0 && $found <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $data = fread($handle, $read);
            $buffer = $data . $buffer;
            $found = substr_count($buffer, "\n");
        }

        fclose($handle);

        $tail = implode("\n", array_slice(explode("\n", $buffer), -$lines));

        return response('<pre style="white-space:pre-wrap;font-size:12px;padding:20px">'
            . htmlspecialchars($tail) . '</pre>');
    })->name('kbb.health.log');

    /*
    | A direct, definitive answer to "is this route actually registered" —
    | no SSH or file access needed. Lists every route Laravel currently has
    | matching a given search term, straight from the live route table
    | Laravel is actually dispatching against, not from reading the source
    | file (which can differ from what's really loaded if a stale compiled
    | cache or anything else is in play).
    */
    Route::get('/' . $adminPath . '/kbb-route-check', function (\Illuminate\Http\Request $request) {
        $search = (string) $request->query('search', 'demo-content');
        $rows = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_contains($route->uri(), $search)) {
                $rows[] = implode('|', $route->methods()) . '  ' . $route->uri()
                    . '  →  ' . ($route->getActionName() ?: '(closure)');
            }
        }

        $body = $rows ? implode("\n", $rows) : "No routes found matching \"{$search}\".";

        return response('<pre style="white-space:pre-wrap;font-size:13px;padding:20px">'
            . htmlspecialchars($body) . '</pre>');
    })->name('kbb.route.check');

    Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
        // Catalog → Reorder — the global product sort/menu_order value,
        // edited one category's slice at a time. See the controller's own
        // doc comment for why this is a single global column, not one
        // value per category.
        Route::post('/media/upload', [\App\Http\Controllers\Admin\MediaUploadController::class, 'upload']);
        Route::get('/redirects', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'index']);
        Route::get('/schema-inspect', [\App\Http\Controllers\Admin\SchemaInspectorApiController::class, 'inspect']);
        Route::get('/catalogue-audit', [\App\Http\Controllers\Admin\CatalogueAuditApiController::class, 'scan']);
        Route::post('/redirects', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'store']);
        Route::post('/redirects/{redirect}/toggle', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'toggle']);
        Route::delete('/redirects/{redirect}', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'destroy']);
        Route::post('/redirects/not-found/{notFound}/resolve', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'resolveNotFound']);
        Route::delete('/redirects/not-found/{notFound}', [\App\Http\Controllers\Admin\RedirectsApiController::class, 'destroyNotFound']);
        Route::get('/catalog/products', [\App\Http\Controllers\Admin\CatalogProductsApiController::class, 'index']);
        Route::get('/demo-content', [\App\Http\Controllers\Admin\DemoContentController::class, 'status']);
        Route::get('/posts', [\App\Http\Controllers\Admin\PostsApiController::class, 'index']);
        Route::post('/demo-content/{type}/import', [\App\Http\Controllers\Admin\DemoContentController::class, 'import']);
        Route::post('/demo-content/{type}/remove', [\App\Http\Controllers\Admin\DemoContentController::class, 'remove']);
        Route::post('/demo-content/import-all', [\App\Http\Controllers\Admin\DemoContentController::class, 'importAll']);
        Route::post('/demo-content/remove-all', [\App\Http\Controllers\Admin\DemoContentController::class, 'removeAll']);
        Route::post('/catalog/products/{product}/toggle-featured', [\App\Http\Controllers\Admin\CatalogProductsApiController::class, 'toggleFeatured']);
        Route::get('/catalog/reorder/scopes', [\App\Http\Controllers\Admin\CatalogReorderApiController::class, 'scopes']);
        Route::get('/catalog/reorder/{type}/{id}/products', [\App\Http\Controllers\Admin\CatalogReorderApiController::class, 'products'])->where('type', 'category|brand')->where('id', '[0-9]+');
        Route::post('/catalog/reorder/{type}/{id}/save-page', [\App\Http\Controllers\Admin\CatalogReorderApiController::class, 'savePage'])->where('type', 'category|brand')->where('id', '[0-9]+');
        Route::post('/catalog/reorder/{type}/{id}/move', [\App\Http\Controllers\Admin\CatalogReorderApiController::class, 'moveAbsolute'])->where('type', 'category|brand')->where('id', '[0-9]+');
        Route::post('/catalog/reorder/{type}/{id}/auto-sort', [\App\Http\Controllers\Admin\CatalogReorderApiController::class, 'autoSort'])->where('type', 'category|brand')->where('id', '[0-9]+');

        // Pages, split by who owns them: the storefront's fixed routes versus
        // rows in the pages table.
        // Login / register panel.
        Route::get('/account-panel',  [\App\Http\Controllers\Admin\AccountPanelApiController::class, 'show']);
        Route::post('/account-panel', [\App\Http\Controllers\Admin\AccountPanelApiController::class, 'save']);

        // Product styles: how cards look everywhere they appear.
        // Growth & Marketing → Newsletter.
        // Store → Delivery & Shipping → Extended.
        Route::get('/extended-delivery',         [\App\Http\Controllers\Admin\ExtendedDeliveryApiController::class, 'show']);
        Route::post('/extended-delivery',        [\App\Http\Controllers\Admin\ExtendedDeliveryApiController::class, 'save']);

        // Store → Delivery & Shipping.
        Route::get('/shipping',  [\App\Http\Controllers\Admin\ShippingApiController::class, 'show']);
        Route::post('/shipping', [\App\Http\Controllers\Admin\ShippingApiController::class, 'save']);

        // Store → Payment & Shipping Rules.
        Route::get('/pay-ship-rules',  [\App\Http\Controllers\Admin\PayShipRulesApiController::class, 'show']);
        Route::post('/pay-ship-rules', [\App\Http\Controllers\Admin\PayShipRulesApiController::class, 'save']);

        // Gateway credentials. Inside this group because it reads and writes
        // encrypted Stripe/Tabby/Tamara secrets; outside it, they would be
        // world-readable.
        require __DIR__.'/payments-admin.php';

        // Capture and refund settlement. Same group, same reason, only more
        // so: these endpoints move money. Capture takes a customer's funds;
        // the settlement read exposes what an authorisation is still worth.
        require __DIR__.'/payments-settlement.php';

        /*
         * The payments preflight check. Same group as the two files above, and that
         * placement is the whole point: it reports which credential fields are still
         * empty and echoes the exact request a gateway would send, redacted. Outside
         * auth:admin that is a map of this shop's payment configuration handed to
         * anybody who asks.
         */
        require __DIR__.'/payments-preflight.php';

    /*
     * Reconciliation. Same group, and for a stronger reason than the file
     * above: it moves no money, but what it RETURNS is a list of this shop's
     * payments, order numbers, provider references and amounts, together with a
     * precise description of where its money handling is weak. That is worse to
     * publish than the preflight beside it.
     */
    require __DIR__.'/payments-reconcile.php';

    /*
     * Stripe connect / disconnect. Same group: one of these takes a live secret
     * key in a request body and another takes the shop off its card processor
     * and deletes a webhook endpoint inside the owner's own Stripe account.
     */
    require __DIR__.'/payments-connect.php';

    /*
     * The Connect PLATFORM application — what the one-click button needs before
     * it can exist. Same group: it returns what this shop has stored for a
     * platform registration, and a read that describes a shop's payment
     * configuration is not a read to serve to the internet.
     */
    require __DIR__.'/payments-connect-platform.php';

    /*
     * The admin side of the back-in-stock and basket-reminder features: who is
     * waiting for what, the demand report, and the send. Inside this group
     * because the demand list is every address that has asked this shop for a
     * product — a marketing list of real customers, and the sort of thing
     * routes/api.php has leaked three times.
     */
    require __DIR__.'/outbound-admin.php';

    /*
     * The Translation console's API. Same group, and two of these earn it on
     * their own: /translations/machine/run spends the owner's money against his
     * own Google Cloud account, billed per character, and
     * /translations/settings writes the key it spends it with. The third reason
     * is quieter and worse — POST /translations writes text the storefront
     * renders to every shopper, so unauthenticated it is a way to put arbitrary
     * words on this shop's product pages.
     */
    require __DIR__.'/translations-admin.php';

        // The storefront health check behind Dashboard → Check now and Safety →
        // Debug & Monitor. Inside this group and nowhere else: when a page is
        // broken it answers with the exception message and the application file
        // and line that threw, which is the same class of thing as the raw error
        // log. Its capability is system.diagnostics, which is owner-only for
        // that reason — a support account that cannot read the log must not be
        // handed the interesting lines out of it.
        //
        // The require goes INSIDE this group rather than beside it because
        // RouteRegistrar::middleware() REPLACES rather than appends: a chained
        // ->middleware() on a fresh registration silently drops NoStoreAdminApi,
        // and the lane that wrote this hit exactly that and had an anonymous
        // caller run the check.
        require __DIR__.'/health-admin.php';

        // Brand CRUD and the directory display mode. Same group: it writes
        // catalogue records and accepts an uploaded logo path.
        require __DIR__.'/brands-admin.php';

        // The Customers screen: list, detail, export. Inside this group because
        // every row is personal data — name, email, phone, address and what the
        // person has spent. Outside it, the whole customer table is public.
        require __DIR__.'/customers-admin.php';

        // The Orders screen: list, export, bulk actions. Its paths are FLAT
        // (orders-list, not orders/list) because line 432 above registers
        // /orders/{id} with no constraint on {id} — a nested /orders/list is
        // matched by it, and whichever registered first wins. Flat names
        // cannot collide however these requires are ordered.
        require __DIR__.'/orders-admin.php';

        // Store → New Order: creating an order on a customer's behalf for the
        // WhatsApp and Instagram orders that never touch the website. Paths are
        // /manual-orders/... rather than hanging off /orders because
        // /orders/{id} above carries no constraint and would swallow /orders/new.
        require __DIR__.'/manual-orders-admin.php';

        // Store → Coupons. Inside this group and nowhere else: these endpoints
        // return the redemption list, which carries customer email addresses,
        // so mounting them unguarded would publish a customer list keyed by
        // which promotion each person answered.
        require __DIR__.'/coupons-admin.php';

        // The Import / Export screen. Same group and the same reason: it
        // accepts uploaded CSVs and writes customers, orders and products —
        // outside auth:admin that is a stranger rewriting the catalogue.
        require __DIR__.'/import-admin.php';

        // Invoices and packing slips. Same group: an invoice carries the
        // customer's name, address and phone, and the URL deliberately holds
        // no token of its own — the admin session is the only thing standing
        // between an order id and somebody's delivery address.
        require __DIR__.'/invoices-admin.php';

        // Mail settings and the test-send. Inside this group deliberately: an
        // unauthenticated endpoint that sends mail to a caller-supplied address
        // is an open relay.
        require __DIR__.'/mail-admin.php';

        // Categories and attributes. Same guarded group: these write catalogue
        // records and accept an uploaded image.
        require __DIR__.'/catalog-admin.php';

        // Catalog → Categories & Brands: merge, the redirect ledger, and the
        // brand tree with its reorder. Same guarded group — the redirect
        // ledger is a map of the store's old URLs and the merge endpoint
        // moves every product out of a category.
        require __DIR__.'/categories-brands-admin.php';

        // Content → Media Library: the grid of every image uploaded through the
        // admin, its search, and what each image is used by before deleting it.
        require __DIR__.'/media-library-admin.php';

        // Content → Media Library → "Make phone-sized copies": the tally, and
        // the bounded batch that walks the existing catalogue making the
        // smaller copies a phone actually downloads. Same group and the same
        // reason as the media library itself — it reads and writes files under
        // the web root. Its paths sit under admin-api/media, so the capability
        // map's existing content.manage entry covers them.
        require __DIR__.'/image-sizes-admin.php';

        // Content → HTML Blocks: reusable snippets placed into pages and posts
        // with [kbb_block slug="…"].
        require __DIR__.'/html-blocks-admin.php';

        // Catalog → Products: the list, inline edits, the detail panel, guarded
        // bulk actions and the filtered CSV export. Flat paths on purpose —
        // /admin-api/products/{id} above carries no constraint on {id}, so a
        // nested /catalog/products/... would be decided by where this line sits.
        require __DIR__.'/catalog-products-admin.php';


        // Catalog → Product editor: the full editor (gallery, categories, rich
        // description, SEO, scheduled publishing). Distinct prefix from the
        // create endpoints above, so mounting both is safe; the older create
        // path is the one to retire once this screen has been used in anger.
        require __DIR__.'/product-editor-admin.php';

        // Catalog → Build my routine: role tagging, the routines and the
        // module's settings. Same guarded group as the rest of admin-api —
        // GET /admin-api/routine-products lists every product INCLUDING drafts
        // and scheduled rows with their SKUs, which is an inventory of the shop
        // and never belongs in routes/api.php.
        require __DIR__.'/build-my-routine-admin.php';

        // The product editor's per-admin panel arrangement. Same guarded
        // group: it reads and writes a preference row keyed to the signed-in
        // admin, so it must never be reachable without one.
        require __DIR__.'/editor-layout-admin.php';

        // Growth & Marketing → Marketing Pixels.
        Route::get('/marketing-pixels',  [\App\Http\Controllers\Admin\MarketingPixelsApiController::class, 'show']);
        Route::post('/marketing-pixels', [\App\Http\Controllers\Admin\MarketingPixelsApiController::class, 'save']);

        // Catalogue → Product Labels.
        Route::get('/product-labels',  [\App\Http\Controllers\Admin\ProductLabelsApiController::class, 'show']);
        Route::post('/product-labels', [\App\Http\Controllers\Admin\ProductLabelsApiController::class, 'save']);

        // Store → Modules.
        Route::get('/modules',  [\App\Http\Controllers\Admin\ModulesApiController::class, 'show']);
        Route::post('/modules', [\App\Http\Controllers\Admin\ModulesApiController::class, 'save']);

        // Appearance → Mobile Header.
        Route::get('/mobile-header',  [\App\Http\Controllers\Admin\MobileHeaderApiController::class, 'show']);
        Route::post('/mobile-header', [\App\Http\Controllers\Admin\MobileHeaderApiController::class, 'save']);

        // Appearance → Section dividers.
        Route::get('/dividers',  [\App\Http\Controllers\Admin\SectionDividersApiController::class, 'show']);
        Route::post('/dividers', [\App\Http\Controllers\Admin\SectionDividersApiController::class, 'save']);

        Route::get('/mega-menu/menus',           [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'menus']);
        Route::post('/mega-menu/menus',          [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'createMenu']);
        Route::post('/mega-menu/demo',           [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'loadDemo']);
        Route::post('/mega-menu/menus/{menu}',   [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'updateMenu']);
        Route::post('/mega-menu/menus/{menu}/delete', [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'destroyMenu']);
        Route::post('/mega-menu/menus/{menu}/duplicate', [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'duplicateMenu']);
        Route::get('/mega-menu',              [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'show']);
        Route::post('/mega-menu',              [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'store']);
        Route::post('/mega-menu/reorder',      [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'reorder']);
        Route::post('/mega-menu/{item}/move',  [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'move']);
        Route::post('/mega-menu/{item}',       [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'update']);
        Route::post('/mega-menu/{item}/delete', [\App\Http\Controllers\Admin\MegaMenuApiController::class, 'destroy']);

        // Appearance → Cart panel.
        Route::get('/cart-panel',  [\App\Http\Controllers\Admin\CartPanelApiController::class, 'show']);
        Route::post('/cart-panel', [\App\Http\Controllers\Admin\CartPanelApiController::class, 'save']);

        Route::get('/newsletter',         [\App\Http\Controllers\Admin\NewsletterApiController::class, 'show']);
        Route::post('/newsletter',        [\App\Http\Controllers\Admin\NewsletterApiController::class, 'save']);
        Route::get('/newsletter/export',  [\App\Http\Controllers\Admin\NewsletterApiController::class, 'export']);

        Route::get('/product-styles',  [\App\Http\Controllers\Admin\ProductStylesApiController::class, 'show']);
        Route::post('/product-styles', [\App\Http\Controllers\Admin\ProductStylesApiController::class, 'save']);

        // Header: every part, grouped into tabs.
        Route::get('/header',  [\App\Http\Controllers\Admin\HeaderApiController::class, 'show']);
        Route::post('/header', [\App\Http\Controllers\Admin\HeaderApiController::class, 'save']);
        Route::get('/site-search',  [\App\Http\Controllers\Admin\SiteSearchApiController::class, 'show']);
        Route::post('/site-search', [\App\Http\Controllers\Admin\SiteSearchApiController::class, 'save']);

        // Mobile menu sheet: appearance and behaviour.
        Route::get('/mobile-menu',  [\App\Http\Controllers\Admin\MobileMenuApiController::class, 'show']);
        Route::post('/mobile-menu', [\App\Http\Controllers\Admin\MobileMenuApiController::class, 'save']);

        // Product page modules: visibility per device.
        Route::get('/product-page',  [\App\Http\Controllers\Admin\ProductPageApiController::class, 'show']);
        Route::post('/product-page', [\App\Http\Controllers\Admin\ProductPageApiController::class, 'save']);

        // Demo content on/off. Displays only; stores nothing.
        Route::get('/demo',  [\App\Http\Controllers\Admin\DemoApiController::class, 'show']);
        Route::post('/demo', [\App\Http\Controllers\Admin\DemoApiController::class, 'save']);

        // Homepage sections: visibility per device, order and grid skins.
        Route::get('/homepage',  [\App\Http\Controllers\Admin\HomepageApiController::class, 'show']);
        Route::post('/homepage', [\App\Http\Controllers\Admin\HomepageApiController::class, 'save']);
        Route::post('/homepage/layout', [\App\Http\Controllers\Admin\HomepageApiController::class, 'applyLayout']);

        // Appearance → Homepage content (Phase 15). The WORDS on the homepage,
        // as opposed to which of its sections appear, which the three routes
        // above already own. Same guarded group: it writes the settings the
        // storefront reads for the hero, the About paragraph and the ticker.
        require __DIR__.'/homepage-content-admin.php';

        // Storefront settings, grouped into tabs.
        Route::get('/ecommerce',  [\App\Http\Controllers\Admin\EcommerceApiController::class, 'show']);
        Route::post('/ecommerce', [\App\Http\Controllers\Admin\EcommerceApiController::class, 'save']);

        // Quantity bundle tiers.
        Route::get('/bundles',  [\App\Http\Controllers\Admin\BundleApiController::class, 'show']);
        Route::post('/bundles', [\App\Http\Controllers\Admin\BundleApiController::class, 'save']);

        // Product-grid skin + columns.
        Route::get('/layout',  [\App\Http\Controllers\Admin\LayoutApiController::class, 'show']);
        Route::post('/layout', [\App\Http\Controllers\Admin\LayoutApiController::class, 'save']);

        Route::get('/pages/store', [\App\Http\Controllers\Admin\PagesApiController::class, 'store']);
        Route::get('/pages/user',  [\App\Http\Controllers\Admin\PagesApiController::class, 'user']);

        // Core Updates panel (JSON). Sits alongside the standalone page, which
        // stays as the fallback for when the admin bundle itself is broken.
        Route::get('/updates',                    [\App\Http\Controllers\Admin\UpdateApiController::class, 'status']);
        Route::post('/updates/check',             [\App\Http\Controllers\Admin\UpdateApiController::class, 'check']);
        Route::post('/updates/apply',             [\App\Http\Controllers\Admin\UpdateApiController::class, 'apply']);
        Route::post('/updates/cancel',            [\App\Http\Controllers\Admin\UpdateApiController::class, 'cancel']);
        Route::post('/updates/restore/{backup}',  [\App\Http\Controllers\Admin\UpdateApiController::class, 'restore']);
        Route::get('/updates/{release}/download', [\App\Http\Controllers\Admin\UpdateApiController::class, 'download']);
        Route::post('/updates/admin-path',        [\App\Http\Controllers\Admin\UpdateApiController::class, 'adminPath']);

        Route::get('/stats',                 [AdminController::class, 'stats']);
        Route::get('/products',              [AdminController::class, 'products']);
        Route::get('/products/{id}',         [AdminController::class, 'getProduct']);
        Route::put('/products/{id}',         [AdminController::class, 'updateProduct']);
        Route::post('/inventory',            [AdminController::class, 'saveInventory']);
        Route::get('/analytics',             [AdminController::class, 'analytics']);
        Route::get('/users',                 [AdminController::class, 'users']);
        Route::post('/users',                [AdminController::class, 'createUser']);
        Route::put('/users/{id}',            [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}',         [AdminController::class, 'deleteUser']);
        Route::get('/settings',              [AdminController::class, 'settings']);
        Route::put('/settings',              [AdminController::class, 'updateSettings']);
        Route::get('/reviews',               [AdminController::class, 'reviews']);
        Route::put('/reviews/{id}',          [AdminController::class, 'updateReview']);
        Route::post('/reviews/bulk',         [AdminController::class, 'bulkReviews']);

        // Store → Reviews → All Reviews. Required AFTER the three routes above,
        // and on deliberately distinct paths: Laravel dispatches the first
        // match, so an identical method+URI here would be dead code that still
        // looked wired. Inside this group because the list returns author_email
        // and the detail view returns the reviewer's IP.
        require __DIR__.'/reviews-admin.php';

        // Store → Reviews → Review Settings: the screen for the sr_* keys the
        // product page has always read and nothing has ever written. On
        // /admin-api/review-settings, NOT /admin-api/reviews/settings — the
        // Route::put('/reviews/{id}') above is untyped and unconstrained, so
        // it matches "settings" as an id and this file would never be reached.
        require __DIR__.'/review-settings-admin.php';

        // Store → Reviews → Bulk Add / Bulk Likes. On /admin-api/review-bulk/*
        // rather than nested under /reviews/, for the same reason the review
        // settings routes are: the PUT /reviews/{id} below is untyped and
        // unconstrained and would swallow a nested path as an id.
        require __DIR__.'/review-bulk-admin.php';

        // Store → Reviews → Export/Import, Badge Themes, Rating Capsule and
        // Assign/Duplicate. Same prefix reasoning as the two above: kept off
        // /reviews/ so the untyped PUT /reviews/{id} cannot swallow them.
        require __DIR__.'/reviews-screens-admin.php';
        Route::get('/customers',             [AdminController::class, 'customers']);
        Route::get('/quiz-leads',            [AdminController::class, 'quizLeads']);

        /*
         * The write half of Quiz Leads — marking a lead contacted or closed.
         * Its own file, like the other admin route groups, so a lane can add
         * to it without touching this one. It must sit INSIDE this admin-api
         * group: the routes carry no middleware of their own, and outside the
         * group they would be public writes to the leads table.
         */
        require __DIR__ . '/quiz-leads-admin.php';
        Route::get('/orders',                [AdminController::class, 'orders']);
        Route::get('/orders/{id}',           [AdminController::class, 'order']);
        Route::get('/orders/{id}/detail',    [\App\Http\Controllers\Admin\AdminOrderController::class, 'show']);
        Route::post('/orders/{id}/notes',    [\App\Http\Controllers\Admin\AdminOrderController::class, 'addNote']);
        Route::post('/orders/{id}/refund',   [\App\Http\Controllers\Admin\AdminOrderController::class, 'refund']);
        Route::post('/orders/{id}/items',    [\App\Http\Controllers\Admin\AdminOrderController::class, 'addItem']);
        Route::put('/orders/{id}/items/{itemId}',    [\App\Http\Controllers\Admin\AdminOrderController::class, 'updateItem']);
        Route::delete('/orders/{id}/items/{itemId}', [\App\Http\Controllers\Admin\AdminOrderController::class, 'removeItem']);
        Route::delete('/orders/{id}',        [\App\Http\Controllers\Admin\AdminOrderController::class, 'trash']);
        Route::post('/orders/{id}/restore',  [\App\Http\Controllers\Admin\AdminOrderController::class, 'restore']);
        Route::put('/orders/{id}/address',   [\App\Http\Controllers\Admin\AdminOrderController::class, 'updateAddress']);
        Route::post('/orders/{id}/action',   [\App\Http\Controllers\Admin\AdminOrderController::class, 'runAction']);
        Route::put('/orders/{id}/status',    [AdminController::class, 'updateOrderStatus']);
    });
});

/*
|------------------------------------------------------------------------------
| KBB Phase 0 + Phase 1
|------------------------------------------------------------------------------
*/

use App\Http\Controllers\Store\CustomerAuthController;
use App\Http\Controllers\Store\DesignCheckController;

// Temporary: the Phase 1 design check. Delete when Phase 2 lands.
Route::get('/_design-check', DesignCheckController::class);

// Customer accounts. Paths match the live site exactly so no existing link,
// bookmark or email breaks at cutover.
//
// Split into three groups by who should actually reach them — this used to
// be one guest:customer group covering everything, which meant a logged-in
// customer was redirected away from every single one of their own account
// pages (confirmed directly: a real authenticated request to
// /my-account/orders came back a 302 to the homepage, not the order list).
// index() itself already branches on auth state internally to show either
// the dashboard or the login form, which is what exposed the mismatch —
// the route-level middleware was fighting the controller's own logic.
Route::get('/my-account', [\App\Http\Controllers\Store\AccountController::class, 'index'])->name('account');
Route::get('/track-my-order', [\App\Http\Controllers\Store\AccountController::class, 'track'])->name('account.track');
Route::get('/my-account/forgot', [\App\Http\Controllers\Store\AccountController::class, 'forgot'])->name('account.forgot');

Route::middleware('auth:customer')->group(function () {
    Route::get('/my-account/orders', [\App\Http\Controllers\Store\AccountController::class, 'orders'])->name('account.orders');
    Route::get('/my-account/orders/{id}', [\App\Http\Controllers\Store\AccountController::class, 'orderDetail'])->name('account.order');
    // Address book. `edit-address` keeps the live site's own path so existing
    // links and the account panel keep working; the CRUD paths are new.
    Route::get('/my-account/edit-address', [\App\Http\Controllers\Store\AddressController::class, 'index'])->name('account.addresses');
    Route::get('/my-account/edit-address/{id}', [\App\Http\Controllers\Store\AddressController::class, 'edit'])
        ->whereNumber('id')->name('account.addresses.edit');
    Route::post('/my-account/addresses', [\App\Http\Controllers\Store\AddressController::class, 'store'])->name('account.addresses.store');
    Route::post('/my-account/addresses/{id}', [\App\Http\Controllers\Store\AddressController::class, 'update'])
        ->whereNumber('id')->name('account.addresses.update');
    Route::post('/my-account/addresses/{id}/delete', [\App\Http\Controllers\Store\AddressController::class, 'destroy'])
        ->whereNumber('id')->name('account.addresses.delete');
    Route::post('/my-account/addresses/{id}/default', [\App\Http\Controllers\Store\AddressController::class, 'makeDefault'])
        ->whereNumber('id')->name('account.addresses.default');
});

Route::middleware('guest:customer')->group(function () {
    Route::post('/my-account/login', [CustomerAuthController::class, 'login'])->name('customer.login');
    // Throttled: the 'unique:customers,email' rule makes an anonymous POST a
    // definitive yes/no on whether an address already shops here. The arithmetic
    // human-check (account_check, on by default) already makes that slow rather
    // than free; this makes bulk enumeration impractical without changing the
    // wording a real shopper sees. Found by Lane Z.
    Route::post('/my-account/register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:10,1')
        ->name('customer.register');
});

Route::post('/my-account/logout', [CustomerAuthController::class, 'logout'])
    ->middleware('auth:customer')
    ->name('customer.logout');

/*
|------------------------------------------------------------------------------
| In-app updater
|------------------------------------------------------------------------------
|
| The health endpoint is public but token-guarded: the update runner calls it
| over HTTP from a separate process to check the site still boots. It reveals
| nothing beyond "yes, I started".
|
*/

use App\Http\Controllers\Admin\UpdateController;

Route::middleware(['auth:admin'])->prefix($adminPath)->group(function () use ($adminPath) {
    // The standalone updates page is retired — there is one Updates screen now,
    // inside the console. This redirects rather than 404s so old bookmarks and
    // the sidebar link both land in the right place.
    //
    // ?fallback=1 still reaches it. That is deliberate: the panel lives inside
    // the admin bundle, and if that bundle ever fails to load you still need
    // somewhere to install the update that fixes it. Same reasoning as
    // kbb-recover.php — the tool that saves you cannot depend on the thing that
    // is broken.
    Route::get('/updates', function (\Illuminate\Http\Request $request) {
        if ($request->query('fallback') === '1') {
            return app(UpdateController::class)->index();
        }

        return redirect(\App\Support\Url::redirect('/' . \App\Services\AdminPathService::current()) . '?go=updates');
    })->name('admin.updates');
    Route::post('/updates/upload', [UpdateController::class, 'upload'])->name('admin.updates.upload');
    Route::post('/updates/apply', [UpdateController::class, 'apply'])->name('admin.updates.apply');
    Route::post('/updates/cancel', [UpdateController::class, 'cancel'])->name('admin.updates.cancel');
    Route::post('/updates/{release}/rollback', [UpdateController::class, 'rollback'])->name('admin.updates.rollback');
    Route::get('/updates/{release}/download', [UpdateController::class, 'download'])->name('admin.updates.download');
    Route::post('/admin-path', [\App\Http\Controllers\Admin\AdminPathController::class, 'update'])->name('admin.path.update');
});

Route::get('/_kbb-health', function () {
    $token = (string) config('kbb.health_token', '');

    abort_if($token === '' || ! hash_equals($token, (string) request('token')), 404);

    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
    } catch (\Throwable $e) {
        return response()->json(['ok' => false, 'reason' => 'database'], 500);
    }

    return response()->json(['ok' => true, 'version' => config('kbb.version')]);
})->name('kbb.health');

// Brands. All three of these URLs existed in the app and none resolved: the
// homepage links to /brands/ twice, MenuDemo builds /korean-skincare-brands/
// and /brand/{slug}/ for the mega menu, and the fallback just 404s. /brands/
// becomes the real page because the homepage already treated it as one; the
// other two 301 to it and to the filtered listing respectively, so the same
// catalogue is not served under three addresses.
// Superseded in 2.60.109: the owner confirmed /korean-skincare-brands/ is the
// live address, so it became the directory and /brands/ became the redirect.
// Both, plus the per-brand page, are in routes/kbb-brands-blog.php.

// Gift wrapping toggle. Writes the choice to the session and returns fresh
// totals, so every path that recomputes them -- this, the rates endpoint, a
// full reload -- reads one server-side source rather than trusting a number
// the browser worked out.
Route::post('/checkout/gift', [\App\Http\Controllers\Store\CheckoutController::class, 'gift'])->name('checkout.gift');

// Quick view -- returns a rendered HTML fragment for the grid-card modal.
Route::get('/quick-view/{id}', [\App\Http\Controllers\Store\QuickViewController::class, 'show'])
    ->whereNumber('id')->name('quick.view');

/**
 * Catches every path that matches no route at all — most real WordPress
 * URLs during the migration, since the URL structure itself often changed,
 * not just individual slugs. Just raises a real 404; the redirect check
 * itself happens in AppServiceProvider's exception-handler registration,
 * which catches this 404 (and every other one, from any source) in one
 * place rather than needing this route wrapped in middleware that turned
 * out not to reliably run.
 */
Route::fallback(function () {
    abort(404);
});

/*
 * The order-received page's "finish your account" form. Top level, inside the
 * web group: it is posted by a shopper, so it needs the session and the CSRF
 * token, not the admin-api guard. Registered before the Phase 9 file because
 * that one ends in a catch-all root-segment route.
 */
require __DIR__.'/order-received.php';

/*
 * Customer password reset and email verification. Top level, in the web group:
 * these are storefront forms needing the session and CSRF, not the admin guard.
 * Before the Phase 9 file for the same reason as the line above — that one ends
 * in a catch-all root-segment route.
 */
require __DIR__.'/auth-customer.php';

/*
 * Newsletter confirmation and unsubscribe. Same group and the same reasoning as
 * the file above -- a shopper opens these from their own inbox, so the admin
 * guard would 302 every one of them, and the two POSTs are the acting half of a
 * GET-then-POST pair that needs the web group's session and CSRF. Before the
 * Phase 9 file, which ends in a catch-all root-segment route.
 *
 * Not in routes/api.php: everything there is unauthenticated by design and is
 * the group CLAUDE.md records as having leaked three times. These must be
 * reachable without a login AND carry CSRF, which only this group gives.
 */
require __DIR__.'/newsletter-public.php';

/*
 * Back-in-stock alerts, basket reminders, and the one page that turns any of
 * this shop's marketing mail off. Same group and the same reasoning again, with
 * one addition of its own: /cart/remind-me reads the CART COOKIE to decide
 * whose basket it is talking about, so it needs the web group's session, not
 * merely its CSRF. Before the Phase 9 file, which ends in a catch-all
 * root-segment route.
 */
require __DIR__.'/outbound-public.php';

/*
 * Adding a browsed product from the checkout page. Web group: it is posted by a
 * shopper and needs the session and CSRF. Before the Phase 9 file, which ends in
 * a catch-all root-segment route.
 */
require __DIR__.'/checkout-browsed.php';

/*
 * Changing a line's quantity from the checkout summary, in place. Same group
 * and the same reasoning as the file above: posted by a shopper, so it needs
 * the session and CSRF, and it must come before the Phase 9 catch-all.
 */
require __DIR__.'/checkout-line.php';

/*
 * Required last, and that placement is load-bearing. The final route in this
 * file matches a single path segment at the site root -- the shape of every
 * storefront URL there is -- so registration order is what keeps /cart reaching
 * CartController instead of being read as an article called "cart".
 *
 * PageController::slugPattern() refuses reserved first segments independently,
 * so the guard does not rest on order alone. RootSlugCollisionTest walks the
 * router as actually registered and fails on any unreserved static segment.
 */
require __DIR__ . '/kbb-brands-blog.php';
