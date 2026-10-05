<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The owner app (Lane MAC) — a PIN-unlocked phone app at a secret address
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane to edit routes/web.php, so tools/mac-wire.php writes
| ONE line at the TOP LEVEL of web.php (not inside the admin group: the app has
| its own guard), directly above the admin login routes:
|
|     require __DIR__.'/owner-app.php';   // the owner app at its secret address (Lane MAC)
|
| The address is OwnerAppPath::current() — KBB_OWNER_APP_PATH in .env, else
| the `owner_app_path` setting the migration generated. With neither, NOTHING
| is registered: the app fails closed rather than falling back to a word.
| Every address carries an underscore, which PageController::slugPattern()
| cannot match, so the root-level article route can never shadow it however
| this file is ordered against it.
|
| NO SESSION, NO SHOP COOKIES. The five session/cookie/CSRF classes are taken
| off (the same list the crawl files drop): the app's guard is its own two
| HttpOnly cookies, scoped to this path (App\Services\OwnerApp\OwnerAppAuth),
| and its CSRF is an HMAC header checked by OwnerAppSession. Nothing here
| shares a cookie with the admin or the storefront.
|
| Paths, with {app} the secret segment:
|
|     GET  /{app}                         the shell (no data in it)
|     GET  /{app}/manifest.webmanifest    PWA manifest, scope = /{app}/
|     GET  /{app}/sw.js                   service worker, scope = /{app}/
|     GET  /{app}/api/state               not enrolled / PIN / unlocked
|     POST /{app}/api/enrol               email + PIN, first use on a device
|     POST /{app}/api/unlock              PIN on an enrolled device
|   behind the PIN (OwnerAppSession), capability per action:
|     POST /{app}/api/lock                                -
|     POST /{app}/api/forget                              -
|     GET  /{app}/api/dashboard                           orders.view (+ analytics.view for money)
|     GET  /{app}/api/orders                              orders.view
|     GET  /{app}/api/orders/{id}                         orders.view
|     POST /{app}/api/orders-bulk-status                  orders.manage
|     POST /{app}/api/orders/{id}/status                  orders.manage
|     POST /{app}/api/orders/{id}/notes                   orders.manage
|     POST /{app}/api/orders/{id}/mark-paid               orders.payment
|     GET  /{app}/api/products                            catalog.view
|     GET  /{app}/api/products/{id}                       catalog.view
|     POST /{app}/api/products/{id}                       catalog.manage
|     GET  /{app}/api/categories                          catalog.view
|     GET  /{app}/api/customers                           customers.view
|     GET  /{app}/api/customers/{id}                      customers.view
|     GET  /{app}/api/changes                             (filtered per capability)
|     GET  /{app}/api/notifications                       (filtered per capability)
|     POST /{app}/api/push, /api/push/off, /api/push/test -
|     POST /{app}/api/notify                              -
|
|     ANY  /{app}/{anything else}         404 JSON, with the app's headers
|
| OWN HOST (Lane SEC, optional). With `owner_app_host` set under Users & Roles
| → Owner app, the group below is registered with ->domain(host) ONLY: the
| secret path on the shop's own host answers nothing at all, and the app's
| host-only cookies belong to that host alone. Empty (the default) changes
| nothing.
|
| Shipped with 2027_08_25_100200_clear_caches_owner_app.php: a route added to a
| cached route table does not exist until the cache is cleared.
*/

use App\Http\Controllers\OwnerApp\AppController;
use App\Http\Controllers\OwnerApp\CustomersController;
use App\Http\Controllers\OwnerApp\DashboardController;
use App\Http\Controllers\OwnerApp\LiveController;
use App\Http\Controllers\OwnerApp\OrdersController;
use App\Http\Controllers\OwnerApp\ProductsController;
use App\Http\Middleware\OwnerAppHeaders;
use App\Http\Middleware\OwnerAppSession;
use App\Services\OwnerApp\OwnerAppPath;
use Illuminate\Support\Facades\Route;

$ownerAppPath = OwnerAppPath::current();

$ownerAppHost = OwnerAppPath::host();

if ($ownerAppPath !== null) {
    $ownerAppGroup = Route::prefix($ownerAppPath);
    if ($ownerAppHost !== null) {
        $ownerAppGroup->domain($ownerAppHost);
    }
    $ownerAppGroup
        ->withoutMiddleware(\App\Http\Controllers\Store\SeoFilesController::STATELESS)
        ->middleware(OwnerAppHeaders::class)
        ->name('owner-app.')
        ->group(function () {
            Route::get('/', [AppController::class, 'shell'])->name('shell');
            Route::get('/manifest.webmanifest', [AppController::class, 'manifest'])->defaults('oa_revalidate', true)->name('manifest');
            Route::get('/sw.js', [AppController::class, 'worker'])->defaults('oa_revalidate', true)->name('worker');

            Route::get('/api/state', [AppController::class, 'state'])->middleware('throttle:120,1,oa-state')->name('state');
            Route::post('/api/enrol', [AppController::class, 'enrol'])->middleware('throttle:20,1,oa-enrol')->name('enrol');
            Route::post('/api/unlock', [AppController::class, 'unlock'])->middleware('throttle:30,1,oa-unlock')->name('unlock');

            Route::prefix('api')->middleware([OwnerAppSession::class, 'throttle:300,1,oa-api'])->group(function () {
                Route::post('/lock', [AppController::class, 'lock'])->name('lock');
                Route::post('/forget', [AppController::class, 'forget'])->name('forget');

                Route::get('/dashboard', DashboardController::class)->name('dashboard');

                Route::get('/orders', [OrdersController::class, 'index'])->name('orders');
                Route::get('/orders/{id}', [OrdersController::class, 'show'])->whereNumber('id')->name('order');
                Route::post('/orders-bulk-status', [OrdersController::class, 'bulkStatus'])->name('orders.bulk');
                Route::post('/orders/{id}/status', [OrdersController::class, 'status'])->whereNumber('id')->name('order.status');
                Route::post('/orders/{id}/notes', [OrdersController::class, 'note'])->whereNumber('id')->name('order.note');
                Route::post('/orders/{id}/mark-paid', [OrdersController::class, 'markPaid'])->whereNumber('id')->name('order.paid');

                Route::get('/products', [ProductsController::class, 'index'])->name('products');
                Route::get('/categories', [ProductsController::class, 'categories'])->name('categories');
                Route::get('/products/{id}', [ProductsController::class, 'show'])->whereNumber('id')->name('product');
                Route::post('/products/{id}', [ProductsController::class, 'update'])->whereNumber('id')->name('product.update');

                Route::get('/customers', [CustomersController::class, 'index'])->name('customers');
                Route::get('/customers/{id}', [CustomersController::class, 'show'])->whereNumber('id')->name('customer');

                Route::get('/changes', [LiveController::class, 'changes'])->defaults('oa_passive', true)->name('changes');
                Route::get('/notifications', [LiveController::class, 'notifications'])->name('notifications');
                Route::post('/push', [LiveController::class, 'subscribe'])->name('push');
                Route::post('/push/off', [LiveController::class, 'unsubscribe'])->name('push.off');
                Route::post('/push/test', [LiveController::class, 'test'])->middleware('throttle:6,1,oa-push-test')->name('push.test');
                Route::post('/notify', [LiveController::class, 'notify'])->name('notify');
            });

            // LAST: everything else under the secret address (Lane SEC).
            Route::any('/{oa_rest}', [AppController::class, 'missing'])->where('oa_rest', '.*')->name('missing');
        });
}
