<?php

declare(strict_types=1);

use App\Http\Controllers\Store\DeliveryLiveController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The delivery timeline's refresh  (Quiqup P3, QUIQUP-PLAN §6.4)
|------------------------------------------------------------------------------
|
| Required ONCE from routes/web.php at TOP LEVEL (not inside the auth:customer
| group: the guest route must stay reachable), next to the /track-my-order
| line. The account route carries its own auth:customer. Both are GETs that
| answer allowlisted JSON (DeliveryLiveController::KEYS) and are never
| prefetched (InstantNav fetches shop paths only, and never a query string).
| Each has its own throttle counter; the page's script makes at most 6 calls.
| A clear_caches migration ships with it so the compiled route cache learns
| the paths.
*/
Route::get('/track-my-order/live', [DeliveryLiveController::class, 'guest'])
    ->middleware('throttle:30,1,delivery-live-guest')->name('account.track.live');
Route::get('/my-account/orders/{id}/live', [DeliveryLiveController::class, 'account'])
    ->whereNumber('id')
    ->middleware(['auth:customer', 'throttle:30,1,delivery-live-account'])->name('account.order.live');
