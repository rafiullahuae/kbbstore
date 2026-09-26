<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The SEO back office — the preview and the to-do list  (Lane S7)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__ . '/seo-back-office.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. The wording above describes WHERE the
| require belongs rather than claiming whether it is there yet, so it stays true
| on both sides of that edit. SeoBackOfficeWiringTest pins the FINISHED state —
| the require present exactly once — which is green in this lane's worktree the
| day it is written and green after the integrator wires it. It does NOT pin the
| absence of the require, which is the mistake CLAUDE.md records three lanes
| making in one day.
|
| Routes added:
|
|     POST /admin-api/seo-preview    what Google will print for one page, from
|                                    the values in the boxes rather than the
|                                    values in the table
|     GET  /admin-api/seo-tasks      what is waiting on the owner, computed from
|                                    the shop's real state
|
| ── THAT GROUP, AND NOTHING ELSE ─────────────────────────────────────────────
|
| /api/* in this application is unauthenticated by design and neither of these
| may go there.
|
| `seo-tasks` reports where this shop is weak and what is unfinished — no
| address published, no Search Console token, a concern page two products short
| of existing. That is a competitor's briefing note, and it is the same argument
| routes/seo-audit-admin.php makes for the audit beside it.
|
| `seo-preview` is a POST that renders operator-supplied strings through
| App\Support\Seo. Public, it would be a free template-engine render per
| request against a shop's own settings — amplification with a keyboard on it.
|
| ── CAPABILITIES ─────────────────────────────────────────────────────────────
|
| Both are owner-only, and they are owner-only for two different reasons that
| are worth separating rather than collapsing into one row:
|
|   POST seo-preview  ->  store.settings
|
|       It is the SEO & Meta screen's own preview, and `admin-api/settings` —
|       the endpoint that SAVES every value it previews — is already mapped to
|       store.settings. Anyone who may change a published title may see what
|       that title will look like; anyone who may not, may not. Mapping the
|       preview any looser would hand a role the shop's own resolved titles and
|       descriptions without the screen that edits them; mapping it tighter
|       would mean the Save button worked and the preview beside it did not.
|
|   GET seo-tasks     ->  system.diagnostics
|
|       The same capability as seo-audit, catalogue-audit, schema-inspect and
|       health one row up, because it reveals the same class of thing about the
|       shop: which parts of it are unfinished. It is a read and it writes
|       nothing, so there is no write rule that has to sort above it.
|
| Both are written into App\Support\AdminCapabilities::RULES rather than left to
| the map's closed default. The default would make them owner-only anyway;
| AdminCapabilityMapTest exists so that a route is owner-only ON PURPOSE rather
| than because nobody thought about it, and it names any route that is not.
|
| ── COST, AND WHY ONLY ONE OF THE TWO IS THROTTLED HARD ──────────────────────
|
| `seo-tasks` runs App\Support\SeoAudit::run() — one pass over every visible
| product in chunks of 500, every category, every brand, every published article
| and the routed content pages — plus one further query for the concern tallies
| (App\Support\ConcernCollections::counts(), one query for all of them by
| construction). So it is the audit's cost plus one query, and it gets the
| audit's throttle: six a minute, far above any honest use of a screen a person
| opens after an update and far below a rate that can take a shared host down.
| Nothing polls it; the screen runs it on an explicit tab open only.
|
| `seo-preview` is two settings reads out of a cached map and two template
| renders — no query against the catalogue at all — and it is called ON INPUT,
| debounced, while somebody types a title. Six a minute would break the feature
| it exists for. 120 a minute is roughly two keystroke-bursts a second sustained
| for a minute, which no typist reaches and no screen here asks for, and it
| still bounds a script.
|
| ── A clear_caches_* MIGRATION SHIPS WITH THIS ───────────────────────────────
|
| A route added to routes/web.php does nothing on the production host until the
| compiled route table is dropped — CLAUDE.md records that as a convention every
| package adding a route follows. See
| database/migrations/2027_02_10_000000_clear_caches_seo_back_office.php.
*/

use App\Http\Controllers\Admin\SeoPreviewApiController;
use App\Http\Controllers\Admin\SeoTasksApiController;
use Illuminate\Support\Facades\Route;

Route::post('/seo-preview', [SeoPreviewApiController::class, 'show'])
    ->middleware('throttle:120,1');

Route::get('/seo-tasks', [SeoTasksApiController::class, 'index'])
    ->middleware('throttle:6,1');
