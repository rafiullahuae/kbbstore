<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingController;
use Illuminate\Support\Facades\Route;

// These are the exact endpoints the storefront pages call (base '/api').
//
// Every POST here is unauthenticated and writes a row, and none of them was
// rate limited. The review endpoint was the sharpest case: the storefront form
// at /reviews/submit has a honeypot, a captcha and a five-per-hour limit, and
// this route reached the same table with none of them -- so the captcha was
// bypassable by posting here instead. The throttles below are a ceiling;
// submitReview also applies the same per-IP limit and honeypot the storefront
// uses, so the protection does not depend on middleware wiring alone.
// SecurityHeaders is appended to the *web* group in bootstrap/app.php.
// These routes load through the api group instead, so every /api response
// has gone out without nosniff, Referrer-Policy, or the frame and
// permissions headers. Applied here rather than in bootstrap so it ships
// as an ordinary patch.
Route::middleware(\App\Http\Middleware\SecurityHeaders::class)->group(function () {

Route::get('/products',                 [ProductController::class, 'index']);
Route::get('/products/{slug}',          [ProductController::class, 'show']);
Route::get('/products/{slug}/reviews',  [ProductController::class, 'reviews']);
Route::post('/products/{slug}/reviews', [ProductController::class, 'submitReview'])
    ->middleware('throttle:10,60');

Route::get('/posts',        [PostController::class, 'index']);
Route::get('/posts/{slug}', [PostController::class, 'show']);

Route::get('/settings', [SettingController::class, 'index']);
Route::get('/reviews',  [ReviewController::class, 'index']);

Route::post('/quiz',             [QuizController::class, 'store'])
    ->middleware('throttle:20,60');
Route::post('/quiz/{id}/expert-request', [QuizController::class, 'expertRequest'])
    ->middleware('throttle:10,60');
Route::post('/checkout/session', [CheckoutController::class, 'session'])
    ->middleware('throttle:30,60');

/*
 * Payment webhooks. In the api group deliberately: a provider's server carries
 * no session and no CSRF token, so these cannot live in the web group without
 * an exemption in bootstrap/app.php. Each route carries a per-gateway URL
 * secret, and the handler verifies the provider's own signature on top -- the
 * secret only keeps unsigned noise off the handler.
 */
require __DIR__.'/payments-webhooks.php';

/*
 * The content-security-policy violation endpoint. In the api group for the
 * same reason the webhooks above are: a browser posting a violation carries
 * no session and no CSRF token, so in the web group every report would be a
 * 419 and Store -> Security would stay empty for ever with nothing to notice.
 * It inherits the SecurityHeaders this group applies.
 */
require __DIR__.'/security-csp.php';

});
