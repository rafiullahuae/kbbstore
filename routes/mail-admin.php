<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Mail admin API — Store → Mail (Lane J)
|------------------------------------------------------------------------------
|
| LOADED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so
| the integrator wired it up. One line, INSIDE the existing admin-api group in
| routes/web.php — the one already carrying `auth:admin` and NoStoreAdminApi —
| directly below the payments require at routes/web.php:284 (the line reading
| `require __DIR__.'/payments-admin.php';`) on the branch this was written
| against:
|
|     require __DIR__.'/mail-admin.php';
|
| It must go inside that group, and this is not a style preference:
|
|   - GET  /admin-api/mail       reads the SMTP configuration.
|   - POST /admin-api/mail       writes the SMTP password.
|   - POST /admin-api/mail/test  MAKES THE SERVER SEND AN EMAIL TO AN ADDRESS
|                                SUPPLIED BY THE CALLER.
|
| That last one mounted anywhere unauthenticated is an open relay: a spammer
| posts a list of addresses and the store's own mail server, with the store's
| own reputation, delivers for them. It is also a blind outbound request, which
| makes it useful for probing the host's network from the outside. The admin
| session guard is the only thing standing between those two things and the
| internet.
|
| Deliberately NOT in routes/api.php: everything there is unauthenticated by
| design (see CLAUDE.md).
|
| Resulting paths:
|
|     GET  /admin-api/mail
|     POST /admin-api/mail
|     POST /admin-api/mail/test
|
| GET never returns the SMTP password — it comes back empty with a `has_value`
| flag. POST treats a blank password as "unchanged", so saving the screen after
| editing the host does not wipe the credential; the literal "-" clears it.
|
*/

use App\Http\Controllers\Admin\MailApiController;
use Illuminate\Support\Facades\Route;

Route::get('/mail', [MailApiController::class, 'show']);
Route::post('/mail', [MailApiController::class, 'save']);

/*
 * The test-send, throttled.
 *
 * 6 per minute, per authenticated admin session. The guard already limits this
 * to people who can log in, so the throttle is not the primary defence — it is
 * the ceiling on what a stolen admin session, or a stuck retry loop in the
 * admin bundle, can do with the store's sending reputation. A shared host
 * measures outbound volume in tens per hour and will suspend the account over
 * a burst, which would take the whole storefront down, not just mail.
 *
 * 6 is well above any honest use: checking a setting change takes one press and
 * a look at an inbox.
 */
Route::post('/mail/test', [MailApiController::class, 'test'])
    ->middleware('throttle:6,1');

/*
|------------------------------------------------------------------------------
| Which status changes email the customer (Lane BS)
|------------------------------------------------------------------------------
|
|     GET  /admin-api/mail/status-emails
|     POST /admin-api/mail/status-emails      {status, enabled}
|
| Added to THIS file rather than to routes/web.php, per CLAUDE.md, and to this
| file rather than a new one because it is the same screen's data: these two
| sit inside the admin-api group that already carries `auth:admin` and
| NoStoreAdminApi, which is the whole reason the require above is where it is.
|
| Mounted under /mail/ and not /orders/ deliberately. It is store-wide mail
| configuration — "does a dispatch email go out at all" — not a fact about any
| order, and the per-order tick box that reads the same answer travels on the
| status-change request itself (`notify`) rather than through an endpoint of its
| own. See App\Services\Mail\OrderStatusMailPolicy for why there is one answer
| and not two.
|
| No throttle. Unlike /mail/test these send nothing; they read and write a
| module toggle, and the admin guard is the control that matters.
*/
Route::get('/mail/status-emails', [\App\Http\Controllers\Admin\OrderEmailPolicyController::class, 'show']);
Route::post('/mail/status-emails', [\App\Http\Controllers\Admin\OrderEmailPolicyController::class, 'save']);
