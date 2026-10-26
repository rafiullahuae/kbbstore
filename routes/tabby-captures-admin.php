<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin: Uncaptured orders (Store → Uncaptured orders)
|--------------------------------------------------------------------------
|
| Required from routes/web.php INSIDE the session-guarded `admin-api` group,
| beside payments-settlement.php, so it inherits auth:admin. On its own it
| would list orders to a stranger.
|
|     require __DIR__.'/tabby-captures-admin.php';
|
| One read:
|
|     GET /admin-api/orders/uncaptured   authorised-but-never-captured Tabby orders
|
| It carries `orders.money` through App\Support\AdminCapabilities (the same
| capability the capture endpoint holds). The screen's Capture button reuses the
| existing POST /admin-api/orders/{id}/capture (payments-settlement.php); this
| file adds no money-moving route. `uncaptured` is a literal segment and the
| settlement/capture routes constrain {id} to [0-9]+, so nothing collides.
|
| A `clear_caches_*` migration ships with the package; without it the compiled
| route cache would 404 this path while the sidebar row renders.
|
*/

use App\Http\Controllers\Admin\UncapturedOrdersController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/uncaptured', [UncapturedOrdersController::class, 'index'])
    ->name('admin.orders.uncaptured');
