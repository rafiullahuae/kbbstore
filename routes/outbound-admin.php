<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Back-in-stock and cart recovery — admin API (Lane EN)
|------------------------------------------------------------------------------
|
| INTEGRATOR: this file needs one line INSIDE the existing admin-api group in
| routes/web.php — the one already carrying `auth:admin` and NoStoreAdminApi —
| directly below
|
|     require __DIR__.'/mail-admin.php';
|
| giving
|
|     require __DIR__.'/outbound-admin.php';
|
| It must go inside that group and this is not a style preference:
|
|   - GET  /admin-api/outbound/backlog   counts what has not been sent.
|   - GET  /admin-api/outbound/demand    what shoppers are waiting for — this
|                                        shop's out-of-stock demand, which is
|                                        commercial information.
|   - GET  /admin-api/outbound/recovery  the parsed recovery schedule.
|   - POST /admin-api/outbound/sweep     MAKES THE SERVER SEND EMAILS.
|
| That last one mounted anywhere unauthenticated is a button a stranger can
| press to spend this shop's sending reputation, as often as the throttle
| allows. routes/mail-admin.php makes the identical argument about its
| test-send, and it is the reason both live behind the admin session guard.
|
| Deliberately NOT in routes/api.php: everything there is unauthenticated by
| design, and CLAUDE.md records that group as the one that has leaked three
| times.
|
| These paths are relative because the group in routes/web.php supplies the
| `/admin-api` prefix, exactly as routes/mail-admin.php's do.
|
| A `clear_caches_*` migration ships with this package, per CLAUDE.md. See
| database/migrations/2026_11_10_000003_clear_caches_stock_alerts_and_recovery.php.
*/

use App\Http\Controllers\Admin\OutboundApiController;
use Illuminate\Support\Facades\Route;

Route::get('/outbound/backlog', [OutboundApiController::class, 'backlog']);
Route::get('/outbound/demand', [OutboundApiController::class, 'demand']);
Route::get('/outbound/recovery', [OutboundApiController::class, 'recovery']);

/*
 * The manual sweep, throttled.
 *
 * Behind the admin guard already, so this is not about strangers; it is about
 * the owner holding the button down. Each press sends up to OutboundTick::BUDGET
 * messages, and a screen with a spinner that retries is a screen that could work
 * through a backlog of thousands as fast as the transport allows — from one
 * browser tab, against a shared host's mail() limits, with this shop's domain in
 * the From line. Six a minute is plenty for a person checking whether it works.
 *
 * It cannot send anything twice however often it is pressed: every send inside
 * it goes through the same compare-and-swap claim as the automatic tick. See
 * Admin\OutboundApiController::sweep().
 */
Route::post('/outbound/sweep', [OutboundApiController::class, 'sweep'])
    ->middleware('throttle:6,1');
