<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Unauthenticated back-office requests go to the admin login, not /login.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));

        // Baseline security headers on every web response.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            // Sends X-Robots-Tag: noindex on staging only, driven by KBB_NOINDEX.
            // A staging copy of a live store is the classic duplicate-content
            // accident; production leaves the variable unset.
            \App\Http\Middleware\NoIndexStaging::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create()
    ->usePublicPath('/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade');
