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
|     GET  /admin-api/security             the tabs of switches, plus the report
|     POST /admin-api/security             save the switches
|     POST /admin-api/security/integrity   run the file-integrity check now
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other module route files, which is where the
| integrator required it. `/api/*` is unauthenticated and every row behind these two
| endpoints carries an operator's email, their role and an IP address, so the
| guarded group is not a preference here.
|
| CAPABILITIES. TWO, and both owner-only, both mapped in App\Support\
| AdminCapabilities.
|
|   `security.view`       the report and its switches. NOT `system.diagnostics`,
|                         although both are owner-only as this ships: the day
|                         somebody widens diagnostics to a manager, this must
|                         not widen with it from a different file with nothing
|                         to notice.
|   `security.integrity`  the Check now button, and ONLY that. Reading a report
|                         that is already written and making the server hash
|                         every file a package installed are different acts with
|                         different costs, so they are different capabilities —
|                         the day a manager may read this screen, that must not
|                         hand them a few thousand file reads on demand. Its
|                         rule is listed ABOVE the `security/**` wildcard, and
|                         RULES is first-match-wins.
|
| NOTHING HERE BLOCKS ANYTHING, AND NOTHING HERE RESTORES ANYTHING. Phase 18's
| sequencing is report before enforce, in every part: audit trail and reporting
| screen -> integrity checking in REPORT-ONLY mode -> CSP report-only -> the
| request gate in observe mode -> then, with real traffic observed, enforcement
| one rule at a time. The first two of those are behind these three endpoints
| and no more. There is no gate, no block list, no CSP and no file restore in
| this round; a lane that shipped one early would have broken the order the
| whole plan rests on.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_11_000001_clear_caches_security_module.php and,
| for the integrity route,
| database/migrations/2026_12_12_000001_clear_caches_security_integrity.php.
*/

use App\Http\Controllers\Admin\SecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/security', [SecurityController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.security');

Route::post('/security', [SecurityController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.security.save');

/*
 * A TIGHTER THROTTLE THAN ITS TWO SIBLINGS, and deliberately so. This is the
 * only endpoint in the module that does real work per call — a hash of every
 * file a package installed — and it is a button somebody can lean on. Six a
 * minute is more than anyone needs and far less than a shared plan minds. The
 * scan is throttled a second time inside the module by `integrity_hours`, which
 * is what the screen's ordinary open obeys; this button is the deliberate way
 * past that, so it wants a limit of its own.
 */
Route::post('/security/integrity', [SecurityController::class, 'integrity'])
    ->middleware('throttle:6,1')
    ->name('admin.security.integrity');
