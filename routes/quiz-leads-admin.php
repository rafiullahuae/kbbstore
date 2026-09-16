<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Quiz Leads  (Lane CH)
|------------------------------------------------------------------------------
|
| Mounted from routes/web.php, inside the EXISTING admin-api group — the group
| that already carries `web`, `auth:admin` and NoStoreAdminApi — directly below
| the read route:
|
|     Route::get('/quiz-leads',            [AdminController::class, 'quizLeads']);
|     require __DIR__ . '/quiz-leads-admin.php';
|
| (It shipped unmounted, because the lane that wrote it may not edit
| routes/web.php; the integrator added that line. RouteFileHeadersTest is what
| made this paragraph get updated rather than left stale — it fails any route
| file that web.php requires while the file still describes itself as
| unmounted. Note that the guard reads the whole file, so do not quote the old
| wording here to explain it: quoting it IS the claim, as far as a regex is
| concerned. That mistake was made once already, right here.)
|
| THAT GROUP, AND NOTHING ELSE. A quiz lead carries a shopper's name, email
| address and phone number, and the route below WRITES. /api/* in this app is
| unauthenticated by design, so mounting it there would let anyone on the
| internet reclassify the store's sales leads. AdminQuizLeadsTest asserts the
| refusal for an anonymous caller, and AdminCapabilityMapTest asserts that the
| capability map names it.
|
| A clear_caches_* migration ships with this package, because a route added to
| routes/web.php does nothing until the compiled route cache is dropped — see
| database/migrations/2026_10_13_000001_clear_caches_quiz_leads_status.php.
|
| Routes added:
|
|     PUT /admin-api/quiz-leads/{id}   move a lead between new / contacted /
|                                      converted / closed
|
| WHY THERE IS ONLY ONE. GET /admin-api/quiz-leads is already registered in
| routes/web.php and this lane extended that method in place (search, the expert
| request's message, and the conversion summary) rather than registering a
| second endpoint answering the same question on a different path. A duplicate
| GET here would have been dead code that still looked wired: Laravel dispatches
| the first matching route and web.php's is registered first.
*/

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::put('/quiz-leads/{id}', [AdminController::class, 'updateQuizLead'])
    ->whereNumber('id');
