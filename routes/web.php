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
// Home — server-rendered. The original page fetched /api/products from the
// browser, which 404s under a subdirectory, and kept its cart in a JavaScript
// array, which is why the header badge moved while the server saw nothing.
Route::get('/', \App\Http\Controllers\Store\HomeController::class)->name('home');

// Newsletter. Kept at /api/subscribe: the layout already publishes that path as
// KBB.routes.subscribe, and the homepage form was the thing pointing elsewhere.
// The second route is the address the form used to post to, so any cached page
// still holding it lands somewhere real instead of on a 404.
Route::post('/api/subscribe', [\App\Http\Controllers\Store\SubscribeController::class, 'store'])
    ->name('subscribe');
Route::post('/subscribe', [\App\Http\Controllers\Store\SubscribeController::class, 'store']);
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
Route::get('/product-category/{path}', function (\Illuminate\Http\Request $request, string $path) {
    $slug = basename(trim($path, '/'));

    return app(\App\Http\Controllers\Store\ShopController::class)->index($request, $slug);
})->where('path', '.*')->name('category');
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
    // Read-only. Open it in the browser to see exactly what the server thinks
    // is in the cart — cookie, cart id, line count. No writes, no uploads.
    Route::get('/debug', [\App\Http\Controllers\Store\CartController::class, 'debug']);
    Route::get('/drawer',  [\App\Http\Controllers\Store\CartController::class, 'drawer']);
    Route::post('/add',    [\App\Http\Controllers\Store\CartController::class, 'add']);
    Route::post('/update', [\App\Http\Controllers\Store\CartController::class, 'update']);
    Route::post('/remove', [\App\Http\Controllers\Store\CartController::class, 'remove']);
    Route::post('/coupon', [\App\Http\Controllers\Store\CartController::class, 'coupon']);
});

// Checkout — ported page, server-side totals, order placement.
Route::get('/checkout',         [\App\Http\Controllers\Store\CheckoutController::class, 'page'])->name('checkout');
Route::post('/api/checkout/rates', [\App\Http\Controllers\Store\CheckoutController::class, 'rates'])->name('checkout.rates');
Route::post('/checkout/place',  [\App\Http\Controllers\Store\CheckoutController::class, 'place'])->name('checkout.place');
Route::get('/checkout/success', [\App\Http\Controllers\Store\CheckoutController::class, 'success'])->name('checkout.success');
Route::get('/checkout/pending', fn () => redirect(\App\Support\Url::redirect('/checkout/')))->name('checkout.pending');
Route::get('/skin-quiz',   [PageController::class, 'skinQuiz'])->name('skin-quiz');
Route::get('/reviews',     [PageController::class, 'reviewWall'])->name('review-wall');
Route::get('/blog',        [PageController::class, 'blog'])->name('blog');
Route::get('/post/{slug?}',[PageController::class, 'post'])->name('post');
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

    Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
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
        Route::get('/customers',             [AdminController::class, 'customers']);
        Route::get('/quiz-leads',            [AdminController::class, 'quizLeads']);
        Route::get('/orders',                [AdminController::class, 'orders']);
        Route::get('/orders/{id}',           [AdminController::class, 'order']);
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
Route::middleware('guest:customer')->group(function () {
    // The account pages the header and panel have been linking to.
    Route::get('/my-account',               [\App\Http\Controllers\Store\AccountController::class, 'index'])->name('account');
    Route::get('/my-account/orders',        [\App\Http\Controllers\Store\AccountController::class, 'orders'])->name('account.orders');
    Route::get('/my-account/edit-address',  [\App\Http\Controllers\Store\AccountController::class, 'addresses'])->name('account.addresses');
    Route::get('/my-account/forgot',        [\App\Http\Controllers\Store\AccountController::class, 'forgot'])->name('account.forgot');
    Route::get('/track-my-order',           [\App\Http\Controllers\Store\AccountController::class, 'track'])->name('account.track');

    Route::post('/my-account/login', [CustomerAuthController::class, 'login'])->name('customer.login');
    Route::post('/my-account/register', [CustomerAuthController::class, 'register'])->name('customer.register');
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
    Route::post('/updates/{release}/rollback', [UpdateController::class, 'rollback'])->name('admin.updates.rollback');
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


