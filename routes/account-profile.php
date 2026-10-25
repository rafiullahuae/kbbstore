<?php

declare(strict_types=1);

use App\Http\Controllers\Store\AccountProfileController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| My account -> Profile & password  (Lane ACC)
|------------------------------------------------------------------------------
|
| Required from routes/web.php INSIDE the auth:customer group, beside the
| orders and the address book: a guest never reaches these. The two writes are
| POSTs under the web group's CSRF check, and each has its own throttle
| counter (an unnamed throttle shares one counter per address with every ping
| on the site; 2.60.514). The password form is also throttled per customer in
| AccountProfileController. A clear_caches migration ships with it so the
| compiled route cache learns the paths.
*/
Route::get('/my-account/profile', [AccountProfileController::class, 'show'])->name('account.profile');
Route::post('/my-account/profile', [AccountProfileController::class, 'update'])
    ->middleware('throttle:20,1,account-profile')->name('account.profile.update');
Route::post('/my-account/profile/password', [AccountProfileController::class, 'password'])
    ->middleware('throttle:10,1,account-password')->name('account.profile.password');
