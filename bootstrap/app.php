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
            /*
             * Makes admin_users.role mean something. It acts only on routes
             * that declare auth:admin and steps aside for everything else, so
             * the storefront pays one in_array() per request for it.
             *
             * Registered here, in the web group, rather than on the admin route
             * groups themselves, for two reasons. routes/web.php is owned by
             * the integrator and no lane may edit it. And a group-level
             * registration would only cover the groups someone remembered to
             * add it to, which is the failure mode this layer exists to end —
             * from here it reaches every auth:admin route in every route file,
             * including ones written after it.
             *
             * Position matters: after StartSession, which the web group
             * supplies, because the admin guard cannot resolve a user without
             * the session. It still runs before the route's own auth:admin,
             * which is why it passes an unauthenticated request straight
             * through rather than answering it — see the class doc comment.
             */
            \App\Http\Middleware\EnforceAdminCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create()
    /*
     * The web root is a different directory from the application root on the
     * production host, so Laravel has to be told where it is. The literal stays
     * the default, so production behaviour is unchanged and nothing needs to be
     * set there -- but hard-coding it alone made the app unservable anywhere
     * else: `artisan serve` died trying to include a front controller at a path
     * that exists on one machine in the world, which is why no page could be
     * previewed before shipping a package.
     *
     * getenv(), not env(): this runs while the Application is being
     * constructed, before LoadEnvironmentVariables has read .env, so env()
     * would always return the default here. The override therefore has to come
     * from the real process environment, which is what a preview or a CI job
     * can set and what production simply leaves unset.
     */
    ->usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade');
