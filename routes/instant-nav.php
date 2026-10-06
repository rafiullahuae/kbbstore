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
| For the integrator: `require __DIR__.'/instant-nav.php';` beside
| buy-together.php in routes/web.php, once; ships a clear_caches migration.
*/

use Illuminate\Support\Facades\Route;

Route::post('/api/viewed', [\App\Http\Controllers\Store\ViewedController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('product.viewed-later');
