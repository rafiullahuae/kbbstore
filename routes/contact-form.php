<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The contact page's inquiry form  (Lane CT)
|------------------------------------------------------------------------------
|
| Required from routes/web.php in the `web` group, beside newsletter-public.php
| — that group carries the session and CSRF, which a public form that writes
| must have. NOT routes/api.php: everything there is unauthenticated by design
| and has no CSRF at all.
|
|     require __DIR__.'/contact-form.php';   // Contact page inquiry form (Lane CT)
|
| /ar/contact-us/send reaches the same route: SetLocaleFromPath strips the
| prefix and sets the locale, and the controller redirects back to the page in
| that language.
|
| No throttle middleware: the controller holds the rate limit itself
| (RateLimiter, per address), so a visitor over it gets the page back with the
| message in place rather than a bare 429. A clear_caches_* migration ships with
| this file (2027_10_15_120200), because a route does nothing until the
| compiled route cache is dropped.
*/

use App\Http\Controllers\Store\ContactInquiryController;
use Illuminate\Support\Facades\Route;

Route::post('/contact-us/send', [ContactInquiryController::class, 'store'])
    ->name('contact.send');
