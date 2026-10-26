<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| GET /api/cart/token — a fresh CSRF token (cart panel freeze fix)
|--------------------------------------------------------------------------
|
| Required from routes/web.php INSIDE the `api/cart` prefix group, beside the
| drawer's own routes, so it runs on the same web stack (session + cookies):
|
|     require __DIR__.'/cart-token.php';
|
| App\Http\Controllers\Store\CartTokenController carries the story. Its own
| throttle counter (the 2.60.514 lesson: an unnamed throttle shares one counter
| per IP with every other unnamed throttle on the site). Thirty a minute is far
| above a real shopper, who asks once after a long absence.
|
| Shipped with a clear_caches_* migration: without it the compiled route cache
| 404s this path, and a 404 here is a panel that recovers by repainting rather
| than by retrying — no worse than today, but not the fix.
*/

use App\Http\Controllers\Store\CartTokenController;
use Illuminate\Support\Facades\Route;

Route::get('/token', CartTokenController::class)
    ->middleware('throttle:30,1,cart-token')
    ->name('cart.token');
