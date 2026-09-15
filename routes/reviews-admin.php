<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reviews admin API — moderation screen — Lane AM
|------------------------------------------------------------------------------
|
| NOT LOADED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| the integrator wires it up. ONE LINE, inside the EXISTING admin-api group in
| routes/web.php — the one opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| immediately after the three existing review routes (the AdminController ones,
| `Route::get('/reviews', ...)`, `Route::put('/reviews/{id}', ...)` and
| `Route::post('/reviews/bulk', ...)`):
|
|     // The real Reviews moderation screen: paginated, filtered, searchable,
|     // with a detail view, bulk actions and a CSV export. Beside the three
|     // routes above rather than replacing them — see the note on paths below.
|     require __DIR__.'/reviews-admin.php';
|
| IT MUST GO INSIDE THAT GROUP. These endpoints return `author_email` on the
| list and the reviewer's `ip` on the detail view. CLAUDE.md names both as data
| that has already leaked in production. Mounted anywhere else this is a public
| endpoint handing over every reviewer's address and IP in one request — the
| exact leak Api\ReviewController was rewritten to close.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| WHY THE PATHS ARE NOT /admin-api/reviews AND /admin-api/reviews/{id}.
| routes/web.php ALREADY registers three review routes against AdminController,
| and that controller belongs to another lane:
|
|     GET  /admin-api/reviews          POST /admin-api/reviews/bulk
|     PUT  /admin-api/reviews/{id}
|
| Laravel dispatches the FIRST matching route, and this file is required after
| them, so anything registered here on an identical method+URI would be dead
| code that still looks wired. Every path below is therefore distinct, the same
| way Lane T put the Customers list at /admin-api/customers/list. Nothing here
| overrides or disables the old routes; they keep working until the integrator
| retires them.
|
| Resulting paths:
|
|     GET    /admin-api/reviews/list                  the moderation list
|     GET    /admin-api/reviews/export                CSV of the current view
|     GET    /admin-api/reviews/{review}              one review, full text + IP
|     PUT    /admin-api/reviews/{review}/moderate     approve / reject / pending
|     POST   /admin-api/reviews/bulk-moderate         the same, many at once
|
| ORDER MATTERS AND IS NOT LEFT TO CHANCE. `/reviews/list` and `/reviews/export`
| are literals that `/reviews/{review}` would otherwise swallow, so they are
| registered first AND `{review}` is constrained to digits with whereNumber().
| Either alone would do; both means reordering this file by accident cannot
| quietly turn the export into a 404.
*/

use App\Http\Controllers\Admin\ReviewsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/reviews/list', [ReviewsApiController::class, 'index']);
Route::get('/reviews/export', [ReviewsApiController::class, 'export']);

Route::post('/reviews/bulk-moderate', [ReviewsApiController::class, 'bulkModerate']);

Route::get('/reviews/{review}', [ReviewsApiController::class, 'show'])->whereNumber('review');
Route::put('/reviews/{review}/moderate', [ReviewsApiController::class, 'moderate'])->whereNumber('review');
