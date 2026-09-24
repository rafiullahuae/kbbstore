<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Security  (Lane C — Phase 18, items 6 and 7)
|------------------------------------------------------------------------------
|
| The report screen over the administrative audit trail: a verdict line, the
| failed sign-ins, the rate-limit trips that vanish silently today, and the
| audit rows with their before/after.
|
| Resulting paths:
|
|     GET  /admin-api/security   the three tabs of switches, plus the report
|     POST /admin-api/security   save the switches
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other module route files. It is not required
| from anywhere yet. `/api/*` is unauthenticated and every row behind these two
| endpoints carries an operator's email, their role and an IP address, so the
| guarded group is not a preference here.
|
| CAPABILITY. `security.view`, owner-only, mapped in App\Support\
| AdminCapabilities. NOT `system.diagnostics`, although both are owner-only as
| this ships: the day somebody widens diagnostics to a manager, this must not
| widen with it from a different file with nothing to notice. See the mapping's
| own comment.
|
| NOTHING HERE BLOCKS ANYTHING. Phase 18's sequencing is report before enforce,
| and these two endpoints are the report. There is no gate, no block list and no
| integrity enforcement in this round; a lane that shipped one early would have
| broken the order the whole plan rests on.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_11_000001_clear_caches_security_module.php.
*/

use App\Http\Controllers\Admin\SecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/security', [SecurityController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.security');

Route::post('/security', [SecurityController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.security.save');
