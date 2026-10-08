<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance -> Coming Soon page (Lane CS)
|------------------------------------------------------------------------------
|
| Required by routes/web.php INSIDE the guarded admin-api group (auth:admin +
| NoStoreAdminApi), so every path below is /admin-api/coming-soon...:
|
|     require __DIR__.'/coming-soon-admin.php';
|
| docs/cs-wiring.json / php tools/cs-wire.php apply that line. Every path maps
| to `comingsoon.manage` (AdminCapabilities::RULES), owner only by default.
|
| The gate itself is middleware (ComingSoonGate, ComingSoonAdminPass), not a
| route: the preview link is a query parameter on any address of the hidden
| host, so no public route is added.
*/

use App\Http\Controllers\Admin\ComingSoonApiController;
use Illuminate\Support\Facades\Route;

Route::get('/coming-soon', [ComingSoonApiController::class, 'show'])->name('admin.coming-soon');
Route::post('/coming-soon', [ComingSoonApiController::class, 'save'])->middleware('throttle:30,1')->name('admin.coming-soon.save');
Route::post('/coming-soon/link', [ComingSoonApiController::class, 'rotate'])->middleware('throttle:20,1')->name('admin.coming-soon.link');
Route::match(['GET', 'POST'], '/coming-soon/preview', [ComingSoonApiController::class, 'preview'])->middleware('throttle:60,1')->name('admin.coming-soon.preview');
