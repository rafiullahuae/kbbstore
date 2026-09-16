<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Review Settings admin API — Store → Reviews → Review Settings — Lane BB
|------------------------------------------------------------------------------
|
| NOT LOADED YET. CLAUDE.md forbids this lane from editing routes/web.php, so
| the require below is the integrator's to add. Delete the two words above when
| you add it — tests/Feature/RouteFileHeadersTest.php fails any route file that
| goes on calling itself unmounted once web.php requires it, and it matches on a
| pattern, not on a literal, so rewording is not a way around it.
|
| ONE LINE, inside the EXISTING admin-api group in routes/web.php — the one
| opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| immediately after the review routes already there:
|
|     // Store -> Reviews -> Review Settings: the screen for the sr_* keys the
|     // product page has always read and nothing has ever written.
|     require __DIR__.'/review-settings-admin.php';
|
| IT MUST GO INSIDE THAT GROUP, and not because of habit. These endpoints let
| the caller change how many reviews the storefront sends, whether submissions
| are accepted at all, and the per-IP submission ceiling. Mounted outside
| `auth:admin` that is an anonymous switch for turning a live store's anti-spam
| limit up to 50 and its review section off.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated"). Nothing in
| this file reads or returns a review row, so no reviewer's `author_email` or
| `ip` passes through it either way — but the group is still the point, because
| the WRITE side is what needs the guard here, not the read.
|
| WHY THE PATHS ARE /review-settings AND NOT /reviews/settings.
|
| NOT A STYLE CHOICE — the obvious path is taken, and silently. routes/web.php
| line 498 already registers
|
|     Route::put('/reviews/{id}', [AdminController::class, 'updateReview']);
|
| with NO numeric constraint on {id}, and it is registered BEFORE this file is
| required. Laravel dispatches the FIRST matching route, so `PUT
| /admin-api/reviews/settings` never reaches this controller at all: it matches
| that route with $id = 'settings', and because updateReview() types the
| parameter `int` under strict_types the request dies with a TypeError and a
| 500. Measured, not reasoned about — the first draft of this file used
| /reviews/settings and nineteen tests failed with exactly that error.
|
| routes/reviews-admin.php avoids the same trap by constraining its own
| `{review}` with whereNumber(). That constraint is on ITS routes and does
| nothing for the untyped one in web.php, which is another lane's file and not
| this one's to fix. A path outside the /reviews/ prefix is the fix that does
| not depend on anybody else's route, in either order, cached or not.
|
| Resulting paths:
|
|     GET  /admin-api/review-settings      the current values + option lists
|     PUT  /admin-api/review-settings      save
|
| AND A ROUTE CACHE NOTE. This adds routes, so the package that ships it also
| ships database/migrations/..._clear_caches_review_settings.php. Without it the
| compiled route table on the server does not know these paths and the screen
| answers 404 while every file is present and correct — the failure CLAUDE.md
| names first.
*/

use App\Http\Controllers\Admin\ReviewSettingsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/review-settings', [ReviewSettingsApiController::class, 'show']);
Route::put('/review-settings', [ReviewSettingsApiController::class, 'update']);
