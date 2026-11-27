<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance -> Time deal strip  (Lane TDS)
|------------------------------------------------------------------------------
| The slim countdown strip above the header. App\Services\TimeDealStrip holds the settings and every byte the shop
| prints. Required from routes/web.php inside the admin-api group, under whatsapp-button-admin.php.
|
|   GET  /admin-api/time-deal-strip   timedeal.manage
|   POST /admin-api/time-deal-strip   timedeal.manage
|
| Shipped with 2028_02_27_100200_clear_caches_time_deal_strip.php.
*/

use App\Http\Controllers\Admin\TimeDealStripApiController;
use Illuminate\Support\Facades\Route;

Route::get('/time-deal-strip', [TimeDealStripApiController::class, 'show']);
Route::post('/time-deal-strip', [TimeDealStripApiController::class, 'save']);
