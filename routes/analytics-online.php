<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Analytics, "Online now"  (Lane AN2)
|------------------------------------------------------------------------------
|
| Required once from routes/api.php, inside its SecurityHeaders group, so it
| is stateless: no session, no CSRF, no cookie. A visible tab sends one every
| 45 s and one when it is hidden or left (resources/js/kbb/hit.js). 120 a
| minute per address leaves room for a family or an office behind one address.
| A clear_caches migration (2027_10_16_090100) ships with this file.
*/

use Illuminate\Support\Facades\Route;

Route::post('/online', [\App\Http\Controllers\Store\OnlineController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('analytics.online');
