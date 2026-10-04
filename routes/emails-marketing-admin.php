<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Emails → Customer emails + the template editor, and Growth & Marketing →
| Email Marketing — the admin half (Lane EK)
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane to edit routes/web.php, so the integrator wires this
| file. ONE line, INSIDE the existing admin-api group in routes/web.php (the
| one carrying `auth:admin` and NoStoreAdminApi), directly below the line
| `require __DIR__.'/emails-admin.php';`:
|
|     require __DIR__.'/emails-marketing-admin.php';
|
| Inside that group for the reason routes/mail-admin.php gives: several of
| these make the server send mail (tests to the signed-in admin; campaigns to
| customers). Nothing here is on /api/*.
|
| Capabilities (App\Support\AdminCapabilities — every route here is mapped;
| an unmapped route would be owner-only, the closed side):
|
|   emails.templates (owner)
|     GET  /admin-api/emails/customer
|     POST /admin-api/emails/customer/switch
|     GET  /admin-api/emails/templates/{template}
|     POST /admin-api/emails/templates/{template}
|     POST /admin-api/emails/templates/{template}/reset
|     POST /admin-api/emails/templates/{template}/test         throttle 6/min
|   emails.manage (owner) — Design & branding's preview, now with a draft
|     POST /admin-api/emails/preview?template=…
|
|   campaign.view (owner, manager)
|     GET  /admin-api/email-marketing/overview
|     GET  /admin-api/email-marketing/campaigns/{id}
|     POST /admin-api/email-marketing/campaigns/{id}/preview
|     GET  /admin-api/email-marketing/campaigns/{id}/review
|     GET  /admin-api/email-marketing/campaigns/{id}/report
|     GET  /admin-api/email-marketing/groups
|     POST /admin-api/email-marketing/groups/count             throttle 60/min
|     GET  /admin-api/email-marketing/products
|   campaign.manage (owner, manager)
|     POST /admin-api/email-marketing/campaigns
|     POST /admin-api/email-marketing/campaigns/{id}
|     POST /admin-api/email-marketing/campaigns/{id}/test      throttle 6/min
|     POST /admin-api/email-marketing/groups
|     POST /admin-api/email-marketing/groups/{id}/delete
|     POST /admin-api/email-marketing/groups/people
|   campaign.send (owner, manager — "Owner and Administrator", row 53)
|     POST /admin-api/email-marketing/campaigns/{id}/send
|     POST /admin-api/email-marketing/campaigns/{id}/cancel
|     POST /admin-api/email-marketing/pump                     throttle 6/min
|     POST /admin-api/email-marketing/settings
|
| Shipped with 2027_07_28_300900_clear_caches_email_marketing.php: a route
| added to a cached route table does not exist until the cache is cleared.
*/

use App\Http\Controllers\Admin\EmailMarketingController;
use App\Http\Controllers\Admin\EmailsApiController;
use App\Http\Controllers\Admin\EmailTemplatesController;
use Illuminate\Support\Facades\Route;

/* ------------------------------------------------------------ Customer emails */

Route::get('/emails/customer', [EmailTemplatesController::class, 'customer']);
Route::post('/emails/customer/switch', [EmailTemplatesController::class, 'setSwitch']);

Route::get('/emails/templates/{template}', [EmailTemplatesController::class, 'show'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}', [EmailTemplatesController::class, 'save'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}/reset', [EmailTemplatesController::class, 'reset'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}/test', [EmailTemplatesController::class, 'test'])
    ->where('template', '[a-z0-9_]{1,40}')
    ->middleware('throttle:6,1');

// The editor's live preview with unsaved sections and words. The GET beside
// it is routes/emails-admin.php's, unchanged.
Route::post('/emails/preview', [EmailsApiController::class, 'preview']);

/* ------------------------------------------------------------ Email Marketing */

Route::prefix('/email-marketing')->group(function () {
    Route::get('/overview', [EmailMarketingController::class, 'overview']);
    Route::post('/campaigns', [EmailMarketingController::class, 'create']);
    Route::get('/campaigns/{id}', [EmailMarketingController::class, 'show'])->whereNumber('id');
    Route::post('/campaigns/{id}', [EmailMarketingController::class, 'save'])->whereNumber('id');
    Route::post('/campaigns/{id}/preview', [EmailMarketingController::class, 'preview'])->whereNumber('id');
    Route::post('/campaigns/{id}/test', [EmailMarketingController::class, 'test'])->whereNumber('id')->middleware('throttle:6,1');
    Route::get('/campaigns/{id}/review', [EmailMarketingController::class, 'review'])->whereNumber('id');
    Route::post('/campaigns/{id}/send', [EmailMarketingController::class, 'send'])->whereNumber('id');
    Route::post('/campaigns/{id}/cancel', [EmailMarketingController::class, 'cancel'])->whereNumber('id');
    Route::get('/campaigns/{id}/report', [EmailMarketingController::class, 'report'])->whereNumber('id');

    Route::get('/groups', [EmailMarketingController::class, 'groups']);
    Route::post('/groups', [EmailMarketingController::class, 'saveGroup']);
    Route::post('/groups/count', [EmailMarketingController::class, 'count'])->middleware('throttle:60,1');
    Route::post('/groups/people', [EmailMarketingController::class, 'people']);
    Route::post('/groups/{id}/delete', [EmailMarketingController::class, 'deleteGroup'])->whereNumber('id');

    Route::get('/products', [EmailMarketingController::class, 'products']);
    Route::post('/pump', [EmailMarketingController::class, 'pump'])->middleware('throttle:6,1');
    Route::post('/settings', [EmailMarketingController::class, 'settings']);
});
