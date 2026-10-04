<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Marketing Emails — admin API (Lane MK, packages E3 + E4)
|------------------------------------------------------------------------------
|
| TO BE MOUNTED by the integrator. CLAUDE.md forbids a lane to edit
| routes/web.php, so this is ONE line, INSIDE the existing admin-api group in
| routes/web.php (the one carrying `auth:admin` and NoStoreAdminApi), directly
| below the Emails require (`require __DIR__.'/emails-admin.php';`):
|
|     require __DIR__.'/marketing-emails-admin.php';
|
| Inside that group for the reason routes/mail-admin.php gives: POST …/test
| and POST …/send make the server send mail. Mounted anywhere unauthenticated
| they are a spam cannon with the shop's own sending reputation. NOTHING here
| is on /api/* (docs/EMAILS-PLAN.md: "Nothing marketing-related goes on
| /api/*"), and MarketingEmailsCapabilityTest walks every route below.
|
| Resulting paths, and the capability AdminCapabilities maps each to (plan §6;
| send = owner AND manager, the owner's D11). Support and editor get a 403 on
| every one:
|
|   marketing.email.view    GET  …/overview, …/campaigns, …/campaigns/{id},
|                                 …/campaigns/{id}/review, …/campaigns/{id}/progress,
|                                 …/templates, …/templates/{id}, …/templates/{id}/preview,
|                                 …/groups, …/options, …/products, …/reports, …/reports/{id}
|                           POST …/groups/count, …/groups/people   (read-only; the
|                                 rules travel in the body)
|   marketing.email.manage  POST/PUT/DELETE drafts, templates and groups,
|                           POST …/preview, POST …/campaigns/{id}/test (6 a minute)
|   marketing.email.send    POST …/campaigns/{id}/send (10 a minute, the
|                                 recipient count typed back), …/schedule,
|                                 …/unschedule, …/step, …/pause, …/resume,
|                                 …/cancel, POST …/limits
|   marketing.export        GET  …/groups/{id}/export (CSV)
|
| Every path is /admin-api/email-marketing/… .
|
| Shipped with database/migrations/2027_07_28_501100_clear_caches_marketing_emails.php,
| because a route added to a cached route table does not exist until the cache
| is cleared (CLAUDE.md).
*/

use App\Http\Controllers\Admin\MktCampaignsController;
use App\Http\Controllers\Admin\MktGroupsController;
use App\Http\Controllers\Admin\MktTemplatesController;
use Illuminate\Support\Facades\Route;

Route::prefix('email-marketing')->group(function () {
    Route::get('/overview', [MktCampaignsController::class, 'overview']);
    Route::get('/options', [MktCampaignsController::class, 'options']);
    Route::get('/products', [MktCampaignsController::class, 'products']);
    Route::post('/preview', [MktCampaignsController::class, 'preview']);
    Route::post('/limits', [MktCampaignsController::class, 'saveLimits']);

    Route::get('/campaigns', [MktCampaignsController::class, 'index']);
    Route::post('/campaigns', [MktCampaignsController::class, 'store']);
    Route::get('/campaigns/{id}', [MktCampaignsController::class, 'show'])->whereNumber('id');
    Route::put('/campaigns/{id}', [MktCampaignsController::class, 'update'])->whereNumber('id');
    Route::delete('/campaigns/{id}', [MktCampaignsController::class, 'destroy'])->whereNumber('id');
    Route::post('/campaigns/{id}/duplicate', [MktCampaignsController::class, 'duplicate'])->whereNumber('id');
    Route::get('/campaigns/{id}/review', [MktCampaignsController::class, 'review'])->whereNumber('id');
    Route::get('/campaigns/{id}/progress', [MktCampaignsController::class, 'progress'])->whereNumber('id');

    // A test goes to one address the admin types: the same ceiling as
    // POST /mail/test, the limit on what a stolen session can do with it.
    Route::post('/campaigns/{id}/test', [MktCampaignsController::class, 'test'])->whereNumber('id')
        ->middleware('throttle:6,1,mkt-test');

    // "Send campaign" is throttled to 10 a minute and needs the recipient
    // count typed back (plan §4).
    Route::post('/campaigns/{id}/send', [MktCampaignsController::class, 'send'])->whereNumber('id')
        ->middleware('throttle:10,1,mkt-send');
    Route::post('/campaigns/{id}/schedule', [MktCampaignsController::class, 'schedule'])->whereNumber('id')
        ->middleware('throttle:10,1,mkt-send');
    Route::post('/campaigns/{id}/unschedule', [MktCampaignsController::class, 'unschedule'])->whereNumber('id');
    Route::post('/campaigns/{id}/step', [MktCampaignsController::class, 'step'])->whereNumber('id')
        ->middleware('throttle:120,1,mkt-step');
    Route::post('/campaigns/{id}/pause', [MktCampaignsController::class, 'pause'])->whereNumber('id');
    Route::post('/campaigns/{id}/resume', [MktCampaignsController::class, 'resume'])->whereNumber('id');
    Route::post('/campaigns/{id}/cancel', [MktCampaignsController::class, 'cancel'])->whereNumber('id');

    Route::get('/reports', [MktCampaignsController::class, 'reports']);
    Route::get('/reports/{id}', [MktCampaignsController::class, 'report'])->whereNumber('id');

    Route::get('/templates', [MktTemplatesController::class, 'index']);
    Route::post('/templates', [MktTemplatesController::class, 'store']);
    Route::get('/templates/{id}', [MktTemplatesController::class, 'show'])->whereNumber('id');
    Route::get('/templates/{id}/preview', [MktTemplatesController::class, 'preview'])->whereNumber('id');
    Route::put('/templates/{id}', [MktTemplatesController::class, 'update'])->whereNumber('id');
    Route::delete('/templates/{id}', [MktTemplatesController::class, 'destroy'])->whereNumber('id');
    Route::post('/templates/{id}/duplicate', [MktTemplatesController::class, 'duplicate'])->whereNumber('id');

    Route::get('/groups', [MktGroupsController::class, 'index']);
    Route::post('/groups', [MktGroupsController::class, 'store']);
    Route::post('/groups/count', [MktGroupsController::class, 'count'])->middleware('throttle:120,1,mkt-count');
    Route::post('/groups/people', [MktGroupsController::class, 'people'])->middleware('throttle:120,1,mkt-count');
    Route::put('/groups/{id}', [MktGroupsController::class, 'update'])->whereNumber('id');
    Route::delete('/groups/{id}', [MktGroupsController::class, 'destroy'])->whereNumber('id');
    Route::get('/groups/{id}/export', [MktGroupsController::class, 'export'])->whereNumber('id');
});
