<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The CSP violation endpoint  (Lane C — Phase 18, item 5, report-only)
|------------------------------------------------------------------------------
|
| ONE ROUTE, AND IT IS PUBLIC. A browser posts a content-security-policy
| violation from whatever page the visitor is on, with no session and no token.
|
| ▲ WIRING. THIS FILE GOES IN routes/api.php, NOT routes/web.php, and not in
| the admin-api group where routes/security-admin.php lives. One line:
|
|     require __DIR__.'/security-csp.php';
|
| routes/api.php specifically, and the precedent is routes/payments-webhooks.php
| — its header makes the same argument for the same reason:
|
|   1. The api group has no CSRF middleware. A browser posting a violation has
|      no token to send, so inside the web group every report would be a 419
|      and the screen would stay empty for ever with nothing to notice.
|   2. routes/api.php already applies App\Http\Middleware\SecurityHeaders to
|      everything in it, so this answer carries the same baseline headers the
|      rest of the shop's responses do.
|
| Mounted there, the live URL is (base path included — production serves this
| application under /kbb-upgrade):
|
|     POST https://<host>/kbb-upgrade/api/csp-report
|
| It has to be exactly that, because it is what the policy header tells the
| browser. App\Services\Security\ContentSecurityPolicy::reportUri() builds it
| from App\Support\Url::base(), which is how the rest of this application
| answers "what am I served under"; SecurityCspTest asserts that what the policy
| advertises is the path this file registers, so the two cannot drift.
|
| WHY /api AND NOT A CAPABILITY. CLAUDE.md's rule is that every new ADMIN
| endpoint gets its own capability and fails closed, and the two admin endpoints
| this module added in earlier rounds have one each (`security.view`,
| `security.integrity`). This is not an admin endpoint and cannot be: there is
| no authenticated version of it to build. What stands in for a capability here
| is that the endpoint can do nothing — it answers 204 to everything, echoes
| nothing, reads four allowlisted fields out of the body and writes at most one
| bounded row. See App\Services\Security\CspViolations.
|
| THE THROTTLE IS NOT DECORATION. This is the only writer into `audit_events`
| that a stranger can reach. 60 a minute per address is far above what a real
| browser sends for a page view — Chromium collapses identical reports per
| document — and far below what would be needed to make the row ceiling work
| hard. It is the first of five bounds; the other four are on the class.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_13_000001_clear_caches_security_csp.php.
*/

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Route;

Route::post('/csp-report', [CspReportController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('security.csp-report');
