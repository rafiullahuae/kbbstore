<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Tabby — webhook registration, and releasing an authorisation (Lane PG2)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file from inside the EXISTING `admin-api`
| group — the one opened with
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which is itself nested inside the `Route::middleware('auth:admin')` group. The
| require sits beside the other payment requires, next to
| `require __DIR__.'/payments-settlement.php';`.
|
| IT MUST STAY INSIDE THAT GROUP, and for two different reasons:
|
|   - The webhook-registration endpoints read the Tabby credentials and write a
|     public callback address into the merchant's Tabby account. Outside
|     `auth:admin` they are a way for a stranger to point this shop's payment
|     notifications at nothing, or to learn whether it is configured.
|   - The release endpoint decides what happens to money a customer has
|     committed. Outside `auth:admin` it is a way to release the hold on every
|     live order in the shop.
|
| Both are mapped in App\Support\AdminCapabilities::RULES already — the webhook
| pair to `payments.manage` (owner only, the same capability as the screen that
| stores the keys) and the release pair to `orders.money` (the same capability as
| capture and refund). Those entries ship in this package alongside the require,
| so the guard is in place for all four paths and AdminCapabilityMapTest sees no
| unmapped route.
|
| Resulting paths:
|
|     GET  /admin-api/payments/tabby/webhooks   what Tabby has been told, per
|                                               country. Changes nothing.
|     POST /admin-api/payments/tabby/webhooks   make Tabby's registration agree
|                                               with this shop.
|     GET  /admin-api/orders/{id}/void          can this hold be released?
|     POST /admin-api/orders/{id}/void          release it.
|
| WHY THE WEBHOOK PAIR EXISTS AT ALL, which is the point of the lane: Tabby does
| not take a callback URL from a dashboard field the way Stripe and Tamara do.
| The address is registered through their API, one registration per merchant
| country, and until that POST is made Tabby never contacts this shop — so a
| perfectly configured gateway authorises money and then goes silent, and the
| order sits `pending` until the authorisation lapses.
|
| A `clear_caches_*` migration ships with this package
| (2027_02_26_000000_add_tabby_void_tracking.php, which clears the route and
| config caches as well as adding `orders.voided_at`). Without it the compiled
| route cache on the live host knows none of these four paths and every one of
| them 404s while the buttons render perfectly.
|
| The ORDER of the require matters only in that it must come before web.php's
| GET-only Route::fallback, which every route in that group already does.
|
*/

use App\Http\Controllers\Admin\PaymentVoidController;
use App\Http\Controllers\Admin\TabbyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/tabby/webhooks', [TabbyWebhookController::class, 'show']);
Route::post('/payments/tabby/webhooks', [TabbyWebhookController::class, 'sync']);

Route::get('/orders/{id}/void', [PaymentVoidController::class, 'show'])
    ->where('id', '[0-9]+');

Route::post('/orders/{id}/void', [PaymentVoidController::class, 'store'])
    ->where('id', '[0-9]+');
