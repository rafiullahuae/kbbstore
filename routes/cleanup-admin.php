<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Clean up before the migration"                    (Lane IE)
|------------------------------------------------------------------------------
|
| ▲ MOUNTED BY THE INTEGRATOR IN 2.60.336, AND THIS NOTE IS THE RECORD.
|
| It is written in the PAST TENSE deliberately. The header used to say "NOT YET
| MOUNTED", which was true the day the lane wrote it and false the moment the
| line below was added — and `RouteFileHeadersTest > it never claims a route
| file is unmounted when it is` went red naming this file, because a header
| that lies about mounting is how a route ends up mounted twice, or never.
|
| That test matches a PATTERN rather than two literals, after
| routes/payments-settlement.php survived a sweep of fourteen files by putting
| the word "yet" in the middle of the phrase instead of at the end — same
| claim, different word order, invisible to the guard.
|
| ▲ AND THE TWO SPELLINGS ARE DESCRIBED HERE, NEVER TYPED. The first draft of
|   this very note quoted them both to explain the point, and the guard went
|   red on the quotation: it reads prose and cannot tell a example from a
|   claim. That is the third time in one release that a comment explaining a
|   rule tripped the rule — the others were a Blade comment containing its own
|   closing marker, and a template comment naming the preview directory a sweep
|   forbids. Explain these things in words.
|
| THE TENSION THIS FILE WAS BORN INTO, kept because it will recur:
| `EverythingIsMountedOnceTest` demands every file in routes/ be required
| exactly once — three features in this shop shipped with a perfect header and
| no require and never worked at all (checkout-card.php answered 405 for twelve
| days). CLAUDE.md forbids a lane from editing routes/web.php. So a lane that
| adds a route file CANNOT leave the suite green, by design, and the resolution
| is that the integrator closes it in one line.
|
| It is required inside the EXISTING admin-api group — the one already carrying
| `auth:admin` and NoStoreAdminApi — beside the other import route files:
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
