<?php

/*
|------------------------------------------------------------------------------
| Appearance → Homepage content · Live preview  (Lane HL, Phase 15)
|------------------------------------------------------------------------------
|
| ONE ROUTE, and it is the join Phase 15's last unticked line asks for: "Live
| editing of homepage sections, reusing the settings schemas." It renders the
| storefront homepage from the arrangement the screen is holding, marks every
| section in that document so the screen can make it selectable, and hands back
| each section's controls AS THE MODULE FRAMEWORK EMITS THEM — the same
| SCHEMA/TABS/POLICY triple that draws every other settings screen in this
| console. It writes nothing.
|
| INTEGRATOR: this file is a `require`, the way routes/homepage-preview-admin.php
| already is. Add the last line below to routes/web.php, inside the admin-api
| group, directly under the homepage routes that are already there:
|
|     require __DIR__.'/homepage-content-admin.php';
|     require __DIR__.'/homepage-preview-admin.php';
|     require __DIR__.'/homepage-live-admin.php';          <-- this file
|
| WHAT IT MOUNTS:
|
|     POST /admin-api/homepage/live
|
| WHY IT IS NOT A NEW PREFIX. Same argument routes/homepage-preview-admin.php
| makes, and it is worth repeating because getting it wrong FAILS CLOSED:
| App\Support\AdminCapabilities::RULES already carries
|
|     ['*', 'admin-api/homepage/**', 'content.manage'],
|
| so a route under this prefix is governed by the SAME capability as the save it
| previews — which is right on its own merits, since seeing the page you are
| about to publish is not a lesser act than publishing it. A route mounted
| anywhere else would need a new RULES entry, and that map's default is closed:
| the screen would 403 on a host with no shell to fix it from.
| tests/Feature/HomepageLiveEditTest.php pins both halves — that the rule really
| reaches this path, and that a signed-out request gets nothing.
|
| WHY POST AND NOT GET. The arrangement is the request: seventeen rows of three
| values each does not belong in a query string, and a GET carrying it would be
| a URL that renders a page — cacheable, linkable, and logged with the payload
| in it. Nothing is mutated; the verb is about the body, not about the effect.
|
| WHY IT IS A SECOND ROUTE AND NOT A FLAG ON /homepage/preview. The two answer
| different questions and are pinned to different promises. The preview's
| contract is that its document is BYTE-IDENTICAL to what the shop serves —
| tests/Feature/HomepagePreviewTest.php §1 asserts exactly that — and this one's
| document deliberately is not: every section wrapper carries a selection hook.
| A mode flag on the preview would put both promises on one endpoint, where the
| byte-identity assertion would hold for one value of the flag and be silently
| untested for the other. Two routes, two contracts, one renderer underneath
| (HomepageApiController::renderHome, unchanged and shared).
|
| THIS LANE'S PACKAGE OWES A MIGRATION, and it ships one:
| `2027_03_21_000000_clear_caches_homepage_live`. The compiled route cache will
| not have this route, and the compiled view of
| resources/views/admin/partials/homepage-content-screen.blade.php will not have
| the tab that drives it. One migration covers both; shipping neither leaves the
| Live preview tab posting to a 404, which is the failure the screen's own error
| banner is written to name.
*/

use App\Http\Controllers\Admin\HomepageApiController;

Route::post('/homepage/live', [HomepageApiController::class, 'live']);
