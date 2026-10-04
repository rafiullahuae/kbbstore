<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Emails → Customer emails and the template builder (Lane EK, module 1)
|------------------------------------------------------------------------------
|
| Transactional email only — nothing marketing lives here (the owner, 4 Oct:
| "one is Email (including the order status emails etc, NO marketing
| emails!)"). Marketing is routes/marketing-emails-admin.php.
|
| CLAUDE.md forbids a lane to edit routes/web.php, so the integrator wires this
| file. ONE line, INSIDE the existing admin-api group in routes/web.php (the
| one carrying `auth:admin` and NoStoreAdminApi), directly below the line
| `require __DIR__.'/emails-admin.php';`:
|
|     require __DIR__.'/emails-templates-admin.php';
|
| Capabilities (docs/EMAILS-PLAN.md §6; every route has an explicit rule in
| App\Support\AdminCapabilities ABOVE the emails wildcard):
|
|   emails.view (owner, manager)
|     GET  /admin-api/emails/customer
|     GET  /admin-api/emails/templates/{template}
|   emails.test (owner, manager)
|     POST /admin-api/emails/templates/{template}/test     throttle 6/min
|   emails.manage (owner)
|     POST /admin-api/emails/customer/switch
|     POST /admin-api/emails/templates/{template}
|     POST /admin-api/emails/templates/{template}/reset
|     POST /admin-api/emails/preview?template=…            the editor's live
|          preview with unsaved changes; the GET beside it is
|          routes/emails-admin.php's, unchanged
|
| Shipped with 2027_07_28_300900_clear_caches_email_templates.php.
*/

use App\Http\Controllers\Admin\EmailsApiController;
use App\Http\Controllers\Admin\EmailTemplatesController;
use Illuminate\Support\Facades\Route;

Route::get('/emails/customer', [EmailTemplatesController::class, 'customer']);
Route::post('/emails/customer/switch', [EmailTemplatesController::class, 'setSwitch']);

Route::get('/emails/templates/{template}', [EmailTemplatesController::class, 'show'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}', [EmailTemplatesController::class, 'save'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}/reset', [EmailTemplatesController::class, 'reset'])->where('template', '[a-z0-9_]{1,40}');
Route::post('/emails/templates/{template}/test', [EmailTemplatesController::class, 'test'])
    ->where('template', '[a-z0-9_]{1,40}')
    ->middleware('throttle:6,1');

Route::post('/emails/preview', [EmailsApiController::class, 'preview']);
