<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Storefront health check  (Lane DH)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__ . '/health-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. RouteFileHeadersTest watches this
| paragraph: a route file that web.php requires may not go on describing itself
| as still awaiting that line. The wording above is deliberately a description
| of WHERE the require belongs rather than a claim about whether it is there
| yet, so it stays true on both sides of that edit — and deliberately does not
| repeat the older phrasing that guard searches for, because the guard reads the
| whole file and a quotation of the claim is the claim.
|
| THAT GROUP, AND NOTHING ELSE. /api/* in this app is unauthenticated by design.
| The endpoint below renders every public page in-process and, when one fails,
| returns the exception message and the application file and line it came from.
| That is a stack trace of the owner's shop handed to whoever asks — a database
| error message alone names tables and columns. It is also expensive (see COST),
| so outside auth:admin it is a free amplification: one cheap request, eight
| page renders. AdminHealthCheckTest asserts the refusal for an anonymous
| caller, mounted exactly as this header describes.
|
| Routes added:
|
|     GET /admin-api/health    render every public page and report each status
|
| CAPABILITY. system.diagnostics — owner only, mapped in App\Support\
| AdminCapabilities::RULES beside schema-inspect and catalogue-audit, which
| reveal the same class of thing. It is a read, so there is no write rule to
| order above it; it is listed above no wildcard that could reach it either.
| Without that entry the map's closed default makes it owner-only anyway, and
| AdminCapabilityMapTest fails any admin route nothing maps.
|
| COST, AND WHY THE THROTTLE IS PART OF THE ROUTE. One call renders EIGHT
| complete pages inside the worker serving it: 276ms cold and 69ms warm on a
| 24-product SQLite demo catalogue, ~6MB of resident memory on top of the
| console's own, and more of both on the live shop's ~2,400 products over
| MySQL. That is fine to press and not fine to hold down — this host has a
| handful of PHP workers and a repeated click is the whole pool rendering the
| shop at itself while real shoppers queue. Six a minute is far above any
| honest use of a button a person presses after an update, and far below a
| rate that can take the site down. The console never polls it and the
| dashboard does not run it on load; it runs on an explicit click only.
|
| A clear_caches_* migration ships with this package, because a route added to
| routes/web.php does nothing until the compiled route cache is dropped — see
| database/migrations/2026_11_02_000000_clear_caches_storefront_health.php.
*/

use App\Http\Controllers\Admin\HealthApiController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthApiController::class, 'run'])
    ->middleware('throttle:6,1');
