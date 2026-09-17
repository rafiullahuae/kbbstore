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
        /*
         * THE ONE LINE THAT MAKES /ar EXIST, AND THE ONE CHANGE IN THIS WHOLE
         * FEATURE THAT HAS TO BE APPLIED TO THE SERVER BY HAND.
         *
         * bootstrap/ is on BuildPackage::NEVER_SHIP and UpdateGuard forbids it
         * outright — rightly, since a bad bootstrap/app.php stops the
         * application booting at all and leaves the updater unable to roll
         * itself back. So this line reaches the server the way the
         * usePublicPath() line below did: edited in place, once.
         *
         * PREPENDED TO THE GLOBAL STACK, and it has to be. Middleware in the
         * `web` group runs AFTER the router has matched a route, which is too
         * late to change which route matches. Only the global pipeline runs
         * before the router, and stripping /ar there is what lets every
         * existing route, RESERVED_SLUGS, the redirect map and the sitemap stay
         * exactly as they are.
         *
         * FORGETTING IT IS SAFE. With the line absent, /ar/... finds no route
         * and 404s and the shop is English-only — which is what the shop is
         * today. Nothing else in this feature depends on it: the admin screens,
         * the translations table and __() all work without it. That is
         * deliberate, because the failure mode of a hand-applied edit has to be
         * "the new thing is not live yet", never "the shop is down".
         *
         * AND IT IS INERT UNTIL SWITCHED ON. The middleware reads
         * App\Support\Locale::enabled(), which is false until the owner turns
         * Arabic on in Translation → Settings. Applying this changes nothing a
         * shopper can see.
         */
        $middleware->prepend(\App\Http\Middleware\SetLocaleFromPath::class);

        // Unauthenticated back-office requests go to the admin login, not /login.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));

        /*
         * THE ONE COOKIE THE BROWSER WRITES, AND THEREFORE THE ONE THAT MUST
         * NOT BE ENCRYPTED.
         *
         * resources/js/kbb/app.js sets `kbb_tz` to the visitor's IANA time zone
         * — the only geo signal this application has that does not depend on
         * the host putting a country header on the request. EncryptCookies
         * expects every incoming cookie to carry Laravel's own encryption
         * envelope and silently drops anything that does not decrypt, so a
         * cookie written in JavaScript arrives as nothing at all.
         *
         * IT WAS ARRIVING AS NOTHING. ExtendedDelivery::detect() has read this
         * cookie since it was written, and the value it read was always null:
         * the time-zone tier of country detection has never once fired in a
         * browser. Measured through the real middleware stack — the same zone
         * sent plaintext (as a browser sends it) resolved to no country, and
         * sent through the test helper that marks a cookie "do not decrypt"
         * resolved correctly. Nothing errored, which is why it went unnoticed:
         * a missing signal and a signal that says "I don't know" look the same
         * from here.
         *
         * SAFE TO EXEMPT, and safe in the way that matters. The value is an
         * IANA zone name the visitor's own browser chose and could set to
         * anything regardless; nothing is authorised or charged on the strength
         * of it. ExtendedDelivery maps it through a fixed table, so a value not
         * on that table becomes null rather than a country, and
         * App\Support\ShopperCountry then requires the result to be two
         * letters. There is no secret in it to protect and no decision resting
         * on it to subvert.
         *
         * Everything else stays encrypted: the session, the cart token and the
         * remembered-login cookie are all written by the server and all carry
         * something worth protecting.
         */
        $middleware->encryptCookies(except: ['kbb_tz']);

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
