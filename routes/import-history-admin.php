<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "What has been imported"  (Lane GF)
|------------------------------------------------------------------------------
|
| UNMOUNTED AS SHIPPED. This file is not required from routes/web.php yet, and
| nothing in it answers until it is. CLAUDE.md forbids this lane from editing
| routes/web.php, so the require is ONE LINE for the integrator, inside the
| EXISTING admin-api group — the one that already carries `auth:admin` and
| NoStoreAdminApi — directly beneath Lane AD's import require:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/import-history-admin.php';    // <- this file
|
| docs/GF-IMPORT-REFINEMENT.md carries it as an anchor and a replacement.
| WHOEVER ADDS THAT LINE SHOULD DELETE THIS PARAGRAPH: a route file that still
| claims to be unmounted after it has been mounted is a lie that the next lane
| reads and believes.
|
| THAT GROUP AND NOTHING ELSE. Everything here reads the record of what was
| imported, which carries the names of the owner's own WooCommerce site, the
| digests of his export files, and the note text lifted out of his catalogue —
| "11 orders had no email", "a review with no author imported as Anonymous".
| routes/api.php is unauthenticated by design in this application (CLAUDE.md
| and tests/Feature/ApiSecurityTest.php), so these must never go there.
|
| Resulting paths:
|
|     GET  /admin-api/import/history        the whole record, JSON
|     GET  /admin-api/import/history-page   the page the owner opens
|     GET  /admin-api/import/history.csv    the same record as a spreadsheet
|
| THE CAPABILITY RULE ALREADY EXISTS AND WAS CHECKED RATHER THAN ASSUMED.
| AdminCapabilities::RULES carries ['*', 'admin-api/import/**', 'data.import']
| and `**` matches every path beneath the prefix — which is exactly why these
| three sit under /import/ rather than under a prefix of this lane's own. A new
| prefix would fall through to the closed owner-only default and
| AdminCapabilityMapTest would fail by name; a new RULES entry beneath that
| wildcard would be dead text, shadowed by it. The pairing is pinned in
| tests/Feature/GfImportRefinementTest.php so that a later tidy-up of RULES
| which narrows the wildcard fails in the suite rather than as a 403 on a host
| with no shell.
|
| FLAT PATHS, NO ROUTE PARAMETERS. Everything a call applies to arrives in the
| query string and is clamped against a fixed range. The same rule
| import-admin.php and media-sideload-admin.php state, for the same reason:
| routes/web.php registers a great deal under admin-api and a bare single
| segment is the kind of name that collides. `history.csv` carries a dot
| deliberately, so a browser saving it gets a filename it will open.
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop NoStoreAdminApi
| from the group.
|
| ALL THREE ARE GETs AND NONE OF THEM WRITES. The reasoning import-admin.php
| gives about a GET that imports rows does not apply here because there is no
| such GET here — this file is the read side, and the write side stays where it
| is.
|
| A clear_caches migration ships with this file —
| 2026_11_23_000001_clear_caches_import_refinement.php — because routes/web.php
| is compiled on the server and the host has no shell, so these routes do not
| exist until bootstrap/cache/routes-*.php is gone. CLAUDE.md makes the pairing
| a convention for exactly this reason, and it matters more than usual here:
| the page this lane adds is the one the owner is told to open when he wants to
| know what happened, so a stale route cache would hide the screen built to
| answer that.
|
*/

use App\Http\Controllers\Admin\ImportHistoryApiController;
use Illuminate\Support\Facades\Route;

Route::get('/import/history', [ImportHistoryApiController::class, 'history']);
Route::get('/import/history-page', [ImportHistoryApiController::class, 'page']);
Route::get('/import/history.csv', [ImportHistoryApiController::class, 'csv']);
