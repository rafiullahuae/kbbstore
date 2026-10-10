<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Marketing Emails — the open pixel (Lane ER)
|------------------------------------------------------------------------------
|
| Mounted by the integrator at the TOP LEVEL of routes/web.php, beside
| `require __DIR__.'/marketing-public.php';` and above the catch-all of
| kbb-brands-blog.php:
|
|     require __DIR__.'/marketing-open-public.php';
|
|     GET /email/o/{token}.gif    a 1×1 GIF, always; one UPDATE by primary key
|                                 when the signed token is good and open
|                                 tracking is on (OpenPixel). No address, no
|                                 campaign, nothing personal in the URL.
|
| `email` is in PageController::RESERVED_SLUGS, so no article can shadow it.
| NOT in routes/api.php and not behind auth: an inbox loads it.
|
| WITHOUT THE SESSION. A mail proxy keeps no cookie, so every load would start
| a new session and write its file; this answer sets no cookie and writes none.
| The rate limit is inside the controller (it says why), so a busy Gmail proxy
| address is never answered with a broken image. ValidateCsrfToken goes WITH the
| session: it never checks a GET, but it writes the XSRF-TOKEN cookie from the
| session on every answer, and with no session that is a 500 on every load
| (MarketingEmailReportTest caught it). MarketingEmailsPublicTest still keeps the
| one-click unsubscribe the only state-changing route without it.
*/

use App\Http\Controllers\Store\MarketingOpenController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/email/o/{token}.gif', MarketingOpenController::class)
    ->where('token', '[0-9a-zA-Z\-]{1,80}')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class])
    ->name('marketing.open');
