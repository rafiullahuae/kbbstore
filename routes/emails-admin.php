<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Emails admin API — the "Emails" parent menu (Lane RK, package E1)
|------------------------------------------------------------------------------
|
| CLAUDE.md forbids a lane to edit routes/web.php, so the integrator wires this
| file. ONE line, INSIDE the existing admin-api group in routes/web.php — the
| one carrying `auth:admin` and NoStoreAdminApi — directly below the mail
| require (the line reading `require __DIR__.'/mail-admin.php';`):
|
|     require __DIR__.'/emails-admin.php';
|
| It must go inside that group for the reason routes/mail-admin.php gives at
| length: POST /emails/test makes the server send a message to an address the
| caller typed. Mounted anywhere unauthenticated it is an open relay with the
| shop's own sending reputation. Nothing here is on /api/*.
|
| Resulting paths, and the capability AdminCapabilities maps each one to
| (owner-only, matching `store.settings`, which has always covered mail):
|
|     GET  /admin-api/emails/overview   emails.view
|     GET  /admin-api/emails/sending    emails.manage
|     POST /admin-api/emails/sending    emails.manage
|     POST /admin-api/emails/test       emails.manage   throttle 6/min
|     GET  /admin-api/emails/branding   emails.manage
|     POST /admin-api/emails/branding   emails.manage
|
| Emails → Sent mail adds no route: it reuses GET /admin-api/mail/log and the
| outbound backlog, which already exist, through the old screen's own loaders.
|
| Shipped with database/migrations/2027_07_25_000100_clear_caches_emails_menu.php,
| because a route added to a cached route table does not exist until the cache
| is cleared (CLAUDE.md).
*/

use App\Http\Controllers\Admin\EmailsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/emails/overview', [EmailsApiController::class, 'overview']);

Route::get('/emails/sending', [EmailsApiController::class, 'sending']);
Route::post('/emails/sending', [EmailsApiController::class, 'saveSending']);

/*
 * The same ceiling as POST /mail/test, for the same reason: it is the limit on
 * what a stolen session or a stuck retry loop can do with the shop's sending
 * reputation, and a shared host suspends accounts over bursts.
 */
Route::post('/emails/test', [EmailsApiController::class, 'test'])
    ->middleware('throttle:6,1');

Route::get('/emails/branding', [EmailsApiController::class, 'branding']);
Route::post('/emails/branding', [EmailsApiController::class, 'saveBranding']);
