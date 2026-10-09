<?php

/*
|------------------------------------------------------------------------------
| Lane SP: instant page changes
|------------------------------------------------------------------------------
|
| One storefront POST, inside the web group (session + CSRF): a product page
| that was fetched ahead writes "Recently viewed" when it is really opened.
| App\Http\Controllers\Store\ViewedController; App\Support\InstantNav.
|
| Lane AN: this is now the ONE beacon every opened shop page sends (a page
| view for Analytics, plus the two product counts it used to take or that had
| their own request), so the per-address limit is 120 a minute -- room for a
| family or an office behind one address, still a ceiling for a script.
|
| For the integrator: `require __DIR__.'/instant-nav.php';` beside
| buy-together.php in routes/web.php, once; ships a clear_caches migration.
*/

use Illuminate\Support\Facades\Route;

Route::post('/api/viewed', [\App\Http\Controllers\Store\ViewedController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('product.viewed-later');
