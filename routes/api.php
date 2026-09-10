<?php

use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingController;
use Illuminate\Support\Facades\Route;

// These are the exact endpoints the storefront pages call (base '/api').
Route::get('/products',                 [ProductController::class, 'index']);
Route::get('/products/{slug}',          [ProductController::class, 'show']);
Route::get('/products/{slug}/reviews',  [ProductController::class, 'reviews']);
Route::post('/products/{slug}/reviews', [ProductController::class, 'submitReview']);

Route::get('/posts',        [PostController::class, 'index']);
Route::get('/posts/{slug}', [PostController::class, 'show']);

Route::get('/settings', [SettingController::class, 'index']);
Route::get('/reviews',  [ReviewController::class, 'index']);

Route::post('/quiz',             [QuizController::class, 'store']);
Route::post('/quiz/{id}/expert-request', [QuizController::class, 'expertRequest']);
Route::post('/checkout/session', [CheckoutController::class, 'session']);
