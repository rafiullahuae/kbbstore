<?php

/*
|--------------------------------------------------------------------------
| Emails → Customer emails → Review request → Schedule & rules — Lane RV
|--------------------------------------------------------------------------
|
| Wired into routes/web.php by the integrator, INSIDE the admin-api group,
| beside emails-templates-admin.php:
|
|     require __DIR__.'/review-request-admin.php';   // Emails → Customer emails → Review request → Schedule & rules (Lane RV)
|
|     GET  /admin-api/emails/review-requests    the rules, their schema, the report
|     POST /admin-api/emails/review-requests    save the rules
|
| Capability `emails.review_requests` (owner and manager), mapped in
| App\Support\AdminCapabilities ABOVE the emails wildcard; a role without it
| gets the console's 403. Shipped with
| 2027_10_27_700100_clear_caches_review_requests.php.
*/

use App\Http\Controllers\Admin\ReviewRequestsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/emails/review-requests', [ReviewRequestsApiController::class, 'show']);
Route::post('/emails/review-requests', [ReviewRequestsApiController::class, 'save']);
