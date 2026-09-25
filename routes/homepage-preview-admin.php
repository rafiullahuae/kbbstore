<?php

/*
|------------------------------------------------------------------------------
| Appearance → Homepage · Preview  (Lane P1, Phase 15)
|------------------------------------------------------------------------------
|
| ONE ROUTE. It renders the storefront homepage from the arrangement currently
| on the Appearance → Homepage screen — an arrangement nobody has saved — and
| writes nothing.
|
| INTEGRATOR: this file is a `require`, the way routes/homepage-content-admin.php
| already is. Add the last line below to routes/web.php, inside the admin-api
| group, directly under the three homepage routes that are already there:
|
|     Route::post('/homepage/layout', [\App\Http\Controllers\Admin\HomepageApiController::class, 'applyLayout']);
|     require __DIR__.'/homepage-content-admin.php';
|     require __DIR__.'/homepage-preview-admin.php';        <-- this file
|
| WHAT IT MOUNTS:
|
|     POST /admin-api/homepage/preview
|
| WHY IT IS NOT A NEW PREFIX, which is the same argument
| routes/homepage-content-admin.php makes and worth repeating because getting it
| wrong fails closed: App\Support\AdminCapabilities::RULES already carries
|
|     ['*', 'admin-api/homepage/**', 'content.manage'],
|
| so a route under this prefix is governed by the SAME capability as the save it
| previews — which is the right answer on its own merits, since seeing the page
| you are about to publish is not a lesser act than publishing it. A route
| mounted anywhere else would need a new RULES entry, and that map fails closed:
| the screen would 403 on a host with no shell to fix it from.
| tests/Feature/HomepagePreviewTest.php pins both halves — that the rule really
| reaches this path, and that a signed-out request gets nothing.
|
| WHY POST AND NOT GET. The arrangement is the request: seventeen rows of three
| values each does not belong in a query string, and a GET carrying it would be
| a URL that renders a page — cacheable, linkable, and logged with the payload
| in it. Nothing is mutated; the verb is about the body, not about the effect,
| and the docblock on the controller method says so where a reader will hit it.
|
| NO MIGRATION IS OWED BY THIS FILE ALONE, and that is worth stating because
| CLAUDE.md's rule is the opposite: a package that adds a route ships a
| clear_caches_* migration, because the compiled route cache will not have it.
| This lane's package must carry one — `2027_02_10_000000_clear_caches_homepage_preview`
| or whatever the release numbers it — for this route AND for the compiled view
| of resources/views/admin/app.blade.php, which this lane also changed. One
| migration covers both; shipping neither leaves the Preview button posting to a
| 404, which is the failure the screen's own error banner is written to name.
*/

use App\Http\Controllers\Admin\HomepageApiController;

Route::post('/homepage/preview', [HomepageApiController::class, 'preview']);
