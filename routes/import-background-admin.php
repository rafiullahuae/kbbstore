<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → the run that keeps going with the tab closed  (Lane GO)
|------------------------------------------------------------------------------
|
| NOT WIRED BY THIS LANE. CLAUDE.md forbids editing routes/web.php, so the
| integrator adds ONE line, inside the EXISTING admin-api group — the one that
| already carries `auth:admin` and NoStoreAdminApi — beside the other import
| requires. docs/GO-BACKGROUND-IMPORT.md gives the anchor and the replacement
| verbatim; the line is:
|
|     require __DIR__.'/import-background-admin.php';
|
| immediately after
|
|     require __DIR__.'/import-history-admin.php';
|
| THAT GROUP AND NOTHING ELSE, and here the argument is the same one
| routes/import-admin.php makes, only shorter because these endpoints are
| smaller: POST /import/background CAUSES THE WHOLE CATALOGUE, CUSTOMER LIST
| AND ORDER HISTORY TO BE REWRITTEN from an uploaded file, unattended, for as
| long as it takes. Outside auth:admin that is a stranger pressing Import.
|
| Resulting paths:
|
|     GET  /admin-api/import/background        the bars and the chain state
|     GET  /admin-api/import/background-page   the live page — the owner's URL
|     POST /admin-api/import/background        carry this run on without a browser
|     POST /admin-api/import/background-control   {action: pause|resume|stop}
|
| NO NEW AdminCapabilities RULE. `['*', 'admin-api/import/**', 'data.import']`
| already matches every path above and `**` matches across segments; a second
| rule under the same prefix would be shadowed by it and would be dead text.
| That was established by asking AdminCapabilities::forPath() rather than by
| reading the table, and this lane's test asks it again for all four paths, so a
| future tidy-up that narrows the wildcard fails in the suite rather than as a
| 403 on a host with no shell.
|
| FLAT PATHS UNDER /import/, NO ROUTE PARAMETERS — the rule import-admin.php,
| import-history-admin.php and media-sideload-admin.php all state, for the
| reason they all give: routes/web.php registers a great deal under admin-api
| and a single bare segment is the kind of name that collides. `background` and
| `background-page` are two literal segments each; nothing here is built from
| anything a caller sent.
|
| WHY THE PAGE IS A GET UNDER admin-api RATHER THAN A NEW ADMIN PAGE ROUTE. The
| admin page routes live inline in routes/web.php, which this lane may not edit,
| and `$adminPath` is not in scope in a required file anyway. Served from this
| group it carries the identical session guard and the same no-store headers,
| which is what a live view wants. Lane GD's progress page is mounted the same
| way for the same reasons.
|
| WHY THE THREE WRITES ARE POSTs. They start and stop an unattended import. A
| GET that did would be fetched by a link prefetcher, a browser history restore
| and the host's own cache warmer — import-admin.php's reasoning, and it is
| sharper here, because the thing a prefetch would start does not stop when the
| page is closed.
|
| THE FOURTH ENDPOINT OF THIS FEATURE IS NOT IN THIS FILE. The loopback call
| that continues a chain has no session and cannot be in this group at all; it
| is routes/import-chain.php, and that file's header is the argument for it.
|
| A clear_caches migration ships with this file —
| 2026_11_25_000001_clear_caches_import_background.php — because the route table
| is compiled on the server and the host has no shell. Without it the button
| 404s, and, worse, the loopback route would not exist either, so the first kick
| would fail and the feature would report itself unavailable on a shop that
| actually has it.
|
*/

use App\Http\Controllers\Admin\ImportBackgroundController;
use Illuminate\Support\Facades\Route;

Route::get('/import/background', [ImportBackgroundController::class, 'progress']);
Route::get('/import/background-page', [ImportBackgroundController::class, 'page']);

Route::post('/import/background', [ImportBackgroundController::class, 'begin']);
Route::post('/import/background-control', [ImportBackgroundController::class, 'control']);
