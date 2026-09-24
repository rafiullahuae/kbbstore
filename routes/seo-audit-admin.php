<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| SEO Audit  (Lane S)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__ . '/seo-audit-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. The wording above describes WHERE the
| require belongs rather than claiming whether it is there yet, so it stays true
| on both sides of that edit.
|
| THAT GROUP, AND NOTHING ELSE. The response is a map of this shop's weakest
| pages — which products have no description, which titles collide, which pages
| Google is likely ignoring. Unauthenticated that is a competitor's content
| plan, and it is a full catalogue scan per request, which is free
| amplification. /api/* in this application is public by design and this must
| not go there.
|
| Routes added:
|
|     GET /admin-api/seo-audit    scan the indexable surface and report findings
|
| CAPABILITY. system.diagnostics — owner only, mapped in App\Support\
| AdminCapabilities::RULES beside schema-inspect, catalogue-audit and health,
| which reveal the same class of thing about the shop. It is a read, so there
| is no write rule that has to sort above it. Without that entry the map's
| closed default makes it owner-only anyway; the entry exists so
| AdminCapabilityMapTest can see a route that was mapped on purpose rather than
| one nobody thought about.
|
| COST, AND WHY THE THROTTLE IS PART OF THE ROUTE. One call reads every visible
| product (in chunks of 500), every category, every brand, every published
| article and the seven routed content pages. That is a handful of queries and
| a bounded working set — SeoAudit keeps counts, not rows — but it is still a
| full pass over the catalogue inside one PHP worker, on a shared host with a
| handful of them. Six a minute is far above any honest use of a button a
| person presses after an update, and far below a rate that can take the site
| down. Nothing polls it; the screen runs it on an explicit tab open only.
|
| A clear_caches_* migration ships with this package, because a route added to
| routes/web.php does nothing until the compiled route table is dropped — see
| database/migrations/2026_09_24_000000_clear_caches_seo_audit.php.
*/

use App\Http\Controllers\Admin\SeoAuditApiController;
use Illuminate\Support\Facades\Route;

Route::get('/seo-audit', [SeoAuditApiController::class, 'scan'])
    ->middleware('throttle:6,1');
