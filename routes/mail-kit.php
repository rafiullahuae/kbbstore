<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The email kit's two public routes — Lane RM
|------------------------------------------------------------------------------
|
| TO BE MOUNTED by the integrator at the TOP LEVEL of routes/web.php, in the
| run of public requires, ABOVE `require __DIR__ . '/kbb-brands-blog.php';`
| (that file ends in a catch-all single-segment route):
|
|     require __DIR__.'/mail-kit.php';
|
| NOT in routes/api.php (CLAUDE.md: everything there is unauthenticated by
| design and has leaked before) and NOT behind auth: both are opened from an
| inbox by someone who is not signed in.
|
|     GET /mail/view/{token}                 the browser copy of one sent email
|     GET /mail/font/outfit-latin.woff2      the font every email asks for
|
| Each has its OWN throttle bucket — the third throttle argument is the key
| prefix, so a burst on one of these never spends any other route's budget,
| and theirs is never spent by others.
|
| A clear_caches migration ships with this package:
| database/migrations/2027_07_27_000200_clear_caches_email_kit.php.
*/

use App\Http\Controllers\Store\MailKitController;
use Illuminate\Support\Facades\Route;

// No ->where() on the token, deliberately: a malformed token must reach the
// same controller, the same single lookup and the same 404 as a forged or an
// expired one, rather than fall through to whatever other route might match.
Route::get('/mail/view/{token}', [MailKitController::class, 'webCopy'])
    ->middleware('throttle:30,1,mail-web-copy')
    ->name('mail.webCopy');

Route::get('/mail/font/outfit-latin.woff2', [MailKitController::class, 'font'])
    ->middleware('throttle:120,1,mail-font')
    ->name('mail.font');
