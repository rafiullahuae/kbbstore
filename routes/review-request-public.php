<?php

/*
|--------------------------------------------------------------------------
| The review request email's page — Lane RV
|--------------------------------------------------------------------------
|
| Wired into routes/web.php by the integrator, at the top level of the web
| group beside the other inbox-opened files (mail-kit, marketing-public):
|
|     require __DIR__.'/review-request-public.php';   // the review request email's page (Lane RV)
|
| NOT in routes/api.php (CLAUDE.md: everything there is public by design and
| has leaked three times) and NOT behind auth: it is opened from an inbox by
| someone who is not signed in. It needs the web group's session and CSRF for
| the form.
|
|     GET  /review-order/{token}/          the email's link: token → this browser's
|                                          session, then 303 to /review-order/ (the
|                                          credential never stays in the address bar)
|     GET  /review-order/                  the review form (?p=&r= preselects)
|     POST /review-order/                  store the reviews, pending (CSRF, session)
|     GET  /review-order/{token}/stop/     the email's unsubscribe link: token → session,
|                                          303 to /review-order/stop/ — changes nothing
|     GET  /review-order/stop/             "stop review request emails?" — one button
|     POST /review-order/stop/             the button (CSRF, the session's token)
|     POST /review-order/{token}/stop/     RFC 8058 one-click (List-Unsubscribe). CSRF-EXEMPT
|                                          BY EXACT ROUTE, as marketing-public's
|                                          unsubscribe is: a mail provider's one-click
|                                          POST carries no session; the token in the
|                                          path is the credential.
|
| `review-order` is a reserved first segment in Store\PageController, so the
| root catch-all can never read it as an article. Every route has its own
| throttle bucket. A clear_caches migration ships with this file:
| database/migrations/2027_10_27_700100_clear_caches_review_requests.php.
*/

use App\Http\Controllers\Store\ReviewRequestController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// The static addresses first: `stop` would otherwise be read as a token.
Route::get('/review-order/stop/', [ReviewRequestController::class, 'stopForm'])
    ->middleware('throttle:20,1,rr-stop')
    ->name('review-request.stop-form');

Route::post('/review-order/stop/', [ReviewRequestController::class, 'stopPress'])
    ->middleware('throttle:20,1,rr-stop-press')
    ->name('review-request.stop-press');

Route::get('/review-order/{token}/', [ReviewRequestController::class, 'open'])
    ->where('token', '(?!stop$)[A-Za-z0-9_-]{1,100}')
    ->middleware('throttle:30,1,rr-open')
    ->name('review-request.open');

Route::get('/review-order/', [ReviewRequestController::class, 'show'])
    ->middleware('throttle:60,1,rr-page')
    ->name('review-request.show');

Route::post('/review-order/', [ReviewRequestController::class, 'submit'])
    ->middleware('throttle:10,1,rr-submit')
    ->name('review-request.submit');

Route::get('/review-order/{token}/stop/', [ReviewRequestController::class, 'stopLink'])
    ->where('token', '(?!stop$)[A-Za-z0-9_-]{1,100}')
    ->middleware('throttle:20,1,rr-stop')
    ->name('review-request.stop-link');

/*
 * Generous: a one-click POST comes from the mail provider's servers, and an
 * unsubscribe that rate-limits is an unsubscribe that does not work.
 */
Route::post('/review-order/{token}/stop/', [ReviewRequestController::class, 'stop'])
    ->where('token', '(?!stop$)[A-Za-z0-9_-]{1,100}')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1,rr-stop-post')
    ->name('review-request.stop');
