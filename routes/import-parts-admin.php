<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import / Export → uploading an export bigger than the server accepts
|------------------------------------------------------------------------------
|
| ▲ MOUNTED BY THE INTEGRATOR IN 2.60.336, with the line shown below.
| CLAUDE.md makes routes/web.php the integrator's file, so this ships as its
| own file and the integrator adds the require.
|
| Written in the past tense on purpose: a header that describes its own
| mounting in the present tense is true the day the lane writes it and false
| the day the integrator does what it asked, and RouteFileHeadersTest exists
| because a header lying about mounting is how a route ends up mounted twice,
| or never.
|
| THE SCREEN FEELS FOR THESE ENDPOINTS RATHER THAN ASSUMING THEM. On a server
| where they are absent the five paths answer 404, and the import screen falls
| back to the single-request upload it has always used — so an older package
| applied on top of this one degrades to the previous behaviour instead of
| breaking the upload.
|
| The line goes inside the EXISTING admin-api group — the one that already
| carries `auth:admin` and NoStoreAdminApi — directly beneath the import
| require, because these endpoints belong to that screen:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/import-parts-admin.php';     // <- this file
|
| Resulting paths:
|
|     GET  /admin-api/import/part/limits   what this server takes in one request
|     POST /admin-api/import/part/begin    open a staging area, get the piece size
|     POST /admin-api/import/part          one piece
|     POST /admin-api/import/part/finish   join them and put the result through
|                                          the ordinary import door
|     POST /admin-api/import/part/abandon  give up, and leave nothing on disk
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop
| NoStoreAdminApi from the group — the note routes/urls-media-admin.php makes,
| for the same reason.
|
| WHY THIS EXISTS. The real shop's Orders zip measures 2.18 MB and PHP's
| default `upload_max_filesize` is 2M, which this build machine also reports.
| That upload is refused by PHP before any route runs: `$_FILES` is empty, no
| validator fires, and the browser shows a network error with no number in it.
| Raising the directive is not a fix — he may not be able to, a panel may reset
| it, and it returns the day his catalogue outgrows whatever it is raised to.
| So the file is cut into pieces sized from what the server says it will take
| AT RUNTIME (App\Services\ImportConsole\UploadParts::partBytes(), which is
| min(upload_max_filesize, post_max_size − overhead) less a margin) and joined
| back together here.
|
| WHY POST ON FOUR OF THE FIVE. Three of them write to disk and `abandon`
| deletes, so none of them may be reachable by a link prefetcher, a browser
| history restore or a host's cache warmer — the argument routes/import-admin.php
| makes at length for the same screen. `part/limits` is the exception because it
| is the exception to the reason: it reads two ini directives and answers, and
| a prefetch of it costs nothing and changes nothing.
|
| THE CAPABILITY IS `data.import`, DECLARED RATHER THAN INHERITED.
| AdminCapabilities::RULES already carries ['*', 'admin-api/import/**',
| 'data.import'] and `**` would match these paths on its own. They are named
| explicitly all the same, immediately above that wildcard, because CLAUDE.md
| rule 5 asks that a new admin endpoint's capability be a decision somebody
| made rather than one it fell into — and because these three write files.
| Sharing `data.import` with POST /import/upload is deliberate: they ARE that
| endpoint, cut into three requests, and an account that may put a 1 MB
| brands.csv on this server but not a 3 MB orders.zip is a distinction nobody
| wants to administer.
|
| FLAT PATHS, NO ROUTE PARAMETERS. The staging handle travels in the body and
| is matched against /^[0-9a-f]{32}$/ before it is near a path; it was minted
| by this server from random_bytes(), so a caller never names a directory.
|
| A clear_caches migration ships with this file:
| 2027_07_02_000000_clear_caches_import_parts.php. routes/web.php is compiled
| on the server, so a route added by a package does not exist until
| bootstrap/cache/routes-*.php is gone.
|
*/

use App\Http\Controllers\Admin\ImportPartsController;
use Illuminate\Support\Facades\Route;

Route::get('/import/part/limits', [ImportPartsController::class, 'limits']);

Route::post('/import/part/begin', [ImportPartsController::class, 'begin']);
Route::post('/import/part/finish', [ImportPartsController::class, 'finish']);
Route::post('/import/part/abandon', [ImportPartsController::class, 'abandon']);
Route::post('/import/part', [ImportPartsController::class, 'put']);
