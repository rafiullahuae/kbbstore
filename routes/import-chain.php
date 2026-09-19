<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The loopback call that keeps an import going  (Lane GO)
|------------------------------------------------------------------------------
|
| NOT WIRED BY THIS LANE. CLAUDE.md forbids editing routes/web.php, so the
| integrator adds ONE line, AT TOP LEVEL in routes/web.php, beside the other
| top-level requires and BEFORE the Phase 9 file at the end:
|
|     require __DIR__.'/import-chain.php';
|
| immediately after
|
|     require __DIR__.'/checkout-card.php';
|
| docs/GO-BACKGROUND-IMPORT.md gives the anchor and the replacement verbatim.
|
|==============================================================================
| WHY THIS ONE IS NOT IN THE admin-api GROUP, WHICH IS THE WHOLE QUESTION
|==============================================================================
| Every other route that touches the importer is inside `auth:admin`, and
| routes/import-admin.php argues at length that they must be. This one cannot
| be, and not for convenience: IT IS CALLED BY THIS SERVER, with no browser, no
| cookie and no session, because the owner has closed the tab. That is the
| feature. There is no session for it to be inside.
|
| So the session is replaced by a credential, and the whole of that argument is
| in App\Services\ImportConsole\ImportChain and App\Http\Controllers\
| ImportChainController. The four properties that make it safe to mount here:
|
|   THE BATON IS 256 BITS, single use, stored only as a SHA-256, and travels in
|   a request header — never a path segment and never a query parameter, so it
|   is not written into this host's access log or any proxy's.
|
|   IT EXISTS ONLY WHILE A RUN DOES, and only because a signed-in admin pressed
|   a button. Between imports there is nothing for this route to accept.
|
|   THE REQUEST DECIDES NOTHING. Not the entity, not the row count, not the
|   options, not the mode — all of that is read from the run row an
|   authenticated admin wrote. The body is never looked at. A caller holding a
|   valid baton can make the owner's own import go one step forward and can do
|   nothing else.
|
|   IT ANSWERS NOTHING. 204 or an empty 404. No status, no counts, no file
|   names, no refused rows. There is no version of this endpoint that leaks
|   customer data, which is what routes/import-admin.php's `rejects` warning is
|   about.
|
|==============================================================================
| WHY web.php AND NOT api.php
|==============================================================================
| routes/api.php is the obvious home — it is unauthenticated by design and it
| is where routes/payments-webhooks.php puts the precedent for exactly this
| shape: a server calls us, a secret compared with hash_equals authorises it,
| and money moves. But CLAUDE.md's `/api/*` landmine is that EVERY endpoint
| there is public and that three of them leaked customer columns, and the
| standing instruction is to allowlist what a model returns before putting it
| there. Adding an endpoint that DRIVES AN IMPORT to that file invites the next
| reader to treat it as one more public read, and it is not one.
|
| At top level in the web group it is a named, two-segment path whose own route
| file says what it is. It carries SecurityHeaders and the session middleware
| (which it simply does not use) and costs nothing.
|
| CSRF IS EXCLUDED AT THE ROUTE, not in bootstrap/app.php. The caller has no
| session and therefore no token, so inside the web group every call would be a
| 419. routes/payments-webhooks.php's header names the two ways out —
| routes/api.php, or `validateCsrfTokens(except:)` in bootstrap/app.php — and
| BOTH ARE CLOSED HERE: the first for the reason above, the second because
| bootstrap/ is on BuildPackage::NEVER_SHIP, UpdateGuard forbids it outright,
| and a change there would have to be hand-applied to the server exactly like
| the usePublicPath() line. A route-level exclusion ships in the package, is
| visible in the file that declares the route, and applies to this one path
| rather than to a pattern that could later match something else.
|
| TWO SEGMENTS, AND THAT IS LOAD-BEARING. The last route registered in web.php
| matches a single path segment at the site root — every storefront URL has that
| shape — so a one-segment path here would have to be added to
| PageController::RESERVED_SLUGS as well. Two segments cannot be reached by it
| at all, and RootSlugCollisionTest keeps that true.
|
| THROTTLED. Far above anything the chain asks for: a slice takes seconds, so
| the real rate is a handful a minute. It bounds what an attacker who somehow
| held a baton could do, and it bounds a bug in kick() — a loop that called
| itself as fast as it could would otherwise be an import running at whatever
| rate the host managed.
|
| A clear_caches migration ships with this file, for the reason CLAUDE.md makes
| it a convention: the route table is compiled on the server and the host has no
| shell, so until bootstrap/cache/routes-*.php is gone this route does not
| exist — and the symptom would be the background feature reporting that the
| host refuses loopback on a host that does not.
|
*/

use App\Http\Controllers\ImportChainController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('/import-chain/continue', [ImportChainController::class, 'continue'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('import.chain.continue');
