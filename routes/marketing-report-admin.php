<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Marketing Emails → Reports: recipients, CSV, open
| tracking (Lane ER)
|------------------------------------------------------------------------------
|
| Mounted by the integrator INSIDE the admin-api group of routes/web.php,
| beside `require __DIR__.'/marketing-emails-admin.php';`:
|
|     require __DIR__.'/marketing-report-admin.php';
|
| Capabilities (AdminCapabilities, first match wins):
|     GET  reports/{id}/recipients   marketing.email.view (the email wildcard)
|     GET  reports/{id}/export       marketing.export — its own line, ABOVE
|                                    the GET wildcard
|     POST open-tracking             marketing.email.manage (the wildcard)
*/

use App\Http\Controllers\Admin\MktReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('email-marketing')->group(function () {
    Route::get('/reports/{id}/recipients', [MktReportController::class, 'recipients'])->whereNumber('id')
        ->middleware('throttle:120,1,mkt-report');
    Route::get('/reports/{id}/export', [MktReportController::class, 'export'])->whereNumber('id')
        ->middleware('throttle:10,1,mkt-report-export');
    Route::post('/open-tracking', [MktReportController::class, 'openTracking']);
});
