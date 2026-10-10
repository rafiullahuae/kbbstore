<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Bounces & unsubscribes — admin API (Lane EB)
|------------------------------------------------------------------------------
|
| TO BE MOUNTED by the integrator. CLAUDE.md forbids a lane to edit
| routes/web.php, so this is ONE line, INSIDE the admin-api group (the one
| carrying `auth:admin` and NoStoreAdminApi), directly below the Marketing
| Emails require (`require __DIR__.'/marketing-emails-admin.php';`):
|
|     require __DIR__.'/email-health-admin.php';   // Growth & Marketing → Bounces & unsubscribes (Lane EB)
|
| Every path is /admin-api/email-health/… and every one is mapped in
| AdminCapabilities (EmailBouncesScreenTest walks them):
|
|   marketing.bounces.view     GET  …/overview, …/list
|                              POST …/deliverability/check   (6 a minute)
|   marketing.bounces.restore  POST …/bounced/restore
|   marketing.bounces.mailbox  POST …/mailbox, …/mailbox/test (6 a minute),
|                                   …/mailbox/run (3 a minute)   OWNER ONLY
|   marketing.email.send       POST …/pace
|   marketing.export           GET  …/bounced/export (CSV)
|
| Shipped with database/migrations/2027_10_20_100200_clear_caches_email_bounces.php.
*/

use App\Http\Controllers\Admin\EmailHealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('email-health')->group(function () {
    Route::get('/overview', [EmailHealthController::class, 'overview']);
    Route::get('/list', [EmailHealthController::class, 'list']);
    Route::get('/bounced/export', [EmailHealthController::class, 'export']);
    Route::post('/bounced/restore', [EmailHealthController::class, 'restore'])->middleware('throttle:30,1,eb-restore');

    Route::post('/mailbox', [EmailHealthController::class, 'saveMailbox']);
    // Each of these opens a TLS connection to Google: a stolen session must
    // not be able to turn them into a loop.
    Route::post('/mailbox/test', [EmailHealthController::class, 'testMailbox'])->middleware('throttle:6,1,eb-imap-test');
    Route::post('/mailbox/run', [EmailHealthController::class, 'runMailbox'])->middleware('throttle:3,1,eb-imap-run');

    Route::post('/pace', [EmailHealthController::class, 'savePace']);
    Route::post('/deliverability/check', [EmailHealthController::class, 'checkDns'])->middleware('throttle:6,1,eb-dns');
});
