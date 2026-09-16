<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Per-admin screen layout — the product editor's panel arrangement  (Lane AS)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so the
| file ships unmounted and the integrator adds ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — next to the product editor's own require,
| because this is that screen's preference store:
|
| INTEGRATOR: when you add that line, CHANGE THE WORDS "NOT WIRED YET" ABOVE.
| tests/Feature/RouteFileHeadersTest.php fails any route file that is required
| from web.php or api.php while still claiming to be unmounted — that test
| exists because fourteen files drifted into exactly that state and a reader
| concluded a live endpoint was dead code. Mounting this file without editing
| the header turns CI red, and the header is the thing at fault, not the route.
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/product-editor-admin.php';
|         // Where each operator's panel arrangement for that screen is kept.
|         // Same group, and for a sharper reason than the others — see below.
|         require __DIR__.'/editor-layout-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE.
|
| It is tempting to read "it only stores which box is above which box" as
| harmless and mount it somewhere cheaper. It is not harmless, for two reasons
| that have nothing to do with the data:
|
|   1. These are WRITE endpoints. /api/* in this application is unauthenticated
|      BY DESIGN — CLAUDE.md says so and the whole of
|      tests/Feature/ApiSecurityTest.php exists because each of its cases leaked
|      in production — so mounting any of this there hands anyone at all an
|      anonymous, unbounded write primitive against a table, one row per admin
|      per invented screen name. The controller's screen allowlist bounds the
|      damage; the guard is what prevents it.
|
|   2. A row here is keyed to ONE operator. Reading it without a guard tells an
|      anonymous caller how many admin accounts exist and lets them enumerate
|      them. The controller never takes an owner from the request — it takes it
|      from auth('admin') — which is only a scope at all if there is an
|      authenticated admin to take.
|
| ProductEditorLayoutTest asserts that refusal on EVERY route below, mounted
| exactly as this header describes, for an anonymous caller, a signed-in
| storefront customer and a plain `web` user — and it reads the middleware back
| off the REGISTERED routes rather than trusting the test harness, because
| RouteRegistrar::middleware() REPLACES rather than appends: a harness that
| chains it twice guards nothing while reading as though it does.
|
| It also asserts the cross-operator case directly: admin A posts a layout
| naming admin B's id, and B's row is unchanged.
|
| A `clear_caches_*` migration ships with this package
| (2026_10_07_000001_clear_caches_editor_layout.php). Routes, Blade and a new
| PHP class all change here, and the schema gains a table. Without it the
| compiled route cache on the live host knows none of these paths and the
| compiled Blade keeps serving the previous copy of the editor screen — so the
| arrange controls would simply not appear, and if they did, every reorder
| would 404 and the arrangement would never persist.
|
| Routes added:
|
|     GET  /admin-api/editor-layout           this operator's arrangement, or null
|     POST /admin-api/editor-layout           store it
|     POST /admin-api/editor-layout-reset     forget it — back to the build default
|
| WHY THE PATHS ARE FLAT, AND CARRY NO WILDCARD.
|
| routes/web.php already registers `GET /admin-api/products/{id}` with NO
| constraint on {id}. Laravel matches the first route registered, so a path an
| existing wildcard already covers is decided by where in a 500-line file
| somebody pasted a require line, and nothing about the resulting symptom points
| at the cause — the Orders lane lost a release to exactly that (`/orders/list`
| matched `/orders/{id}` and reached a controller whose signature is `int $id`).
| Every path below is a literal with no parameter at all, so it cannot collide
| whatever order the requires end up in, and the screen name travels in the
| query string or the body where the validator can put it on an allowlist.
|
| `editor-layout` is distinct from Lane AO's `product-editor-` prefix on
| purpose, so that lane's route-count assertion keeps counting its own six
| routes and not this lane's three.
|
| WHY RESET IS A POST AND NOT A DELETE ON THE SAME PATH. It needs to name a
| screen, and a DELETE with a body is the kind of thing an intermediary is
| entitled to strip. A flat sibling path is unambiguous, needs no verb
| negotiation, and cannot be reached by accident from the read path.
|
| WHAT IS DELIBERATELY ABSENT. No route that reads or writes another operator's
| layout, for any role, including owner. There is no administrative need to
| curate somebody else's furniture, and the absence is what makes the ownership
| scope a single unconditional WHERE rather than a branch with a role check in
| it — the shape of bug that gets shipped.
|
*/

use App\Http\Controllers\Admin\AdminScreenLayoutApiController;
use Illuminate\Support\Facades\Route;

Route::get('/editor-layout', [AdminScreenLayoutApiController::class, 'show']);
Route::post('/editor-layout', [AdminScreenLayoutApiController::class, 'save']);
Route::post('/editor-layout-reset', [AdminScreenLayoutApiController::class, 'reset']);
