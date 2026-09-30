<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Clean up before the migration"                    (Lane IE)
|------------------------------------------------------------------------------
|
| ▲ NOT YET MOUNTED, AND THE SUITE SAYS SO BY NAME.
|
| `tests/Feature/EverythingIsMountedOnceTest > it requires every route file
| exactly once` is RED while this line is missing:
|
|     cleanup-admin.php is required 0 times by routes/web.php + routes/api.php
|
| That is the guard working, not a defect in it. It walks routes/ and demands
| every file be required exactly once, because three features in this shop
| shipped with a perfect header and no require and never worked at all
| (checkout-card.php answered 405 for twelve days). A lane that adds a route
| file therefore CANNOT leave the suite green, and CLAUDE.md forbids that lane
| from editing routes/web.php to fix it — the two rules are in tension by
| design, and the resolution is that the integrator closes it in one line.
|
| routes/web.php is the integrator's file; this one asks to be required inside
| the EXISTING admin-api group — the one already carrying `auth:admin` and
| NoStoreAdminApi — beside the other import route files:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/import-history-admin.php';
|     require __DIR__.'/cleanup-admin.php';        // <- this file
|
| Resulting paths:
|
|     GET  /admin-api/cleanup/page       the screen the owner opens.
|     GET  /admin-api/cleanup/preview    what would be deleted. Writes nothing.
|     POST /admin-api/cleanup/purge      deletes it, and only what was shown.
|
| THAT GROUP AND NOTHING ELSE. routes/api.php is unauthenticated by design in
| this application (CLAUDE.md, tests/Feature/ApiSecurityTest.php). The GET here
| returns the shop's row counts and sample product names and the POST DELETES
| ROWS, so neither may ever be reachable from there.
|
| THE CAPABILITY IS ITS OWN AND IT FAILS CLOSED. `AdminCapabilities::RULES`
| gains ['*', 'admin-api/cleanup', 'data.cleanup'] and the `/**` beneath it,
| and `data.cleanup` is owner-only. It is NOT a reuse of `data.import`: the
| import endpoints add rows and these two remove them, and a role that may
| load the shop's catalogue in is not thereby a role that may delete part of
| it. Without a rule the pair would fall through to the closed owner-only
| default, which is safe but silent; the named rule is what
| AdminCapabilityMapTest can assert.
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop
| NoStoreAdminApi from the group.
|
| A clear_caches migration ships with this file —
| 2027_06_18_000000_clear_caches_pre_migration_cleanup.php — because
| routes/web.php is compiled on the server, so these routes do not exist until
| bootstrap/cache/routes-*.php is gone. CLAUDE.md makes that pairing a
| convention.
|
*/

use App\Http\Controllers\Admin\CleanupApiController;
use Illuminate\Support\Facades\Route;

Route::get('/cleanup/page', [CleanupApiController::class, 'page']);
Route::get('/cleanup/preview', [CleanupApiController::class, 'preview']);
Route::post('/cleanup/purge', [CleanupApiController::class, 'purge']);
