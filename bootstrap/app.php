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
         * ▲ AND IT WAS FORGOTTEN, WHICH IS HOW WE LEARNED IT NEED NOT BE HERE.
         * The owner switched Arabic on and reported "/ar gives everywhere 404
         * not found" — twice. He had done nothing wrong: this line had never
         * reached the server and could not. AppServiceProvider::boot() now
         * prepends the same middleware, and app/ DOES ship. Providers boot
         * before Kernel::handle() reads $this->middleware to build the Pipeline,
         * so the prepend still lands in the global stack in time; the whole
         * suite passes with THIS line commented out, which is how that was
         * established rather than argued.
         *
         * THIS LINE STAYS ANYWAY, and is not redundant. prependMiddleware()
         * array_searches before it unshifts, so a host that did get the
         * hand-edit registers one copy and not two. Removing it would also make
         * the global-pipeline registration depend entirely on a provider hook, a
         * quieter thing to lose to a future Laravel upgrade than a line in the
         * file whose job is the middleware stack. Both are pinned by
         * tests/Feature/ArabicUrlsWorkWithoutTheHandEditTest.php.
         *
         * FORGETTING IT IS SAFE. With the line absent, the provider still
         * registers the middleware. Nothing else in this feature depends on it: the admin screens,
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
        // Lane SP: the settings maps are read from the cache once per request,
        // not once per setting (App\Services\SettingsService). Prepended LAST
        // so it is the outermost middleware and covers every one after it.
        $middleware->prepend(\App\Http\Middleware\SettingsRequestMemo::class);

        /*
         * THE REDIRECT TABLE, AND THE SECOND HALF OF THE SAME STORY.
         *
         * CheckRedirects was written as middleware and registered as middleware
         * nowhere, so the only live reader of the `redirects` table was the
         * 404 handler in AppServiceProvider — which means a redirect row for an
         * address the shop already answers could never fire. Measured, not
         * assumed: rows pointing /shop/, a category archive and a product
         * address at /PROOF-INERT/ changed nothing. docs/GP-ADDRESSES-LAND.md
         * has the transcript.
         *
         * APPENDED TO THE GLOBAL STACK, NOT TO THE `web` GROUP, and that is
         * the whole correction. The earlier attempt this class's comment
         * records went into the web group, which runs AFTER the router has
         * matched a route — far too late to change WHICH route matches. Only
         * the global pipeline runs before routing, exactly as the block above
         * says for SetLocaleFromPath.
         *
         * append(), so it runs after SetLocaleFromPath rather than before it:
         * /ar has to be off the path before the row is matched, because
         * `redirects.source` carries no locale segment.
         *
         * THE ONE DIFFERENCE BETWEEN THE TWO REGISTRATIONS, named because it is
         * real and because it is not worth restructuring this file for: append()
         * puts this AFTER Laravel's own global middleware, while the provider's
         * prepend puts it before them. Nothing in that set reads or rewrites the
         * path -- TrustProxies, HandleCors, ValidatePostSize, TrimStrings and
         * ConvertEmptyStringsToNull all leave getPathInfo() alone -- so the two
         * orders answer identically for every request except one: under
         * `artisan down`, a host carrying only the provider registration serves
         * the 301 and a host carrying both serves the 503. A redirect to a page
         * that is itself down is not a defect worth a second ordering rule.
         *
         * AND LIKE THE LINE ABOVE IT, THIS ONE CANNOT SHIP. bootstrap/ is on
         * BuildPackage::NEVER_SHIP. AppServiceProvider::boot() prepends the
         * same class, from a file that does ship; both together register one
         * copy, because prependMiddleware() array_searches before it unshifts.
         * tests/Feature/RedirectMiddlewareTest.php pins both halves.
         */
        $middleware->append(\App\Http\Middleware\CheckRedirects::class);

        /*
         * THE GUEST REDIRECT, DECIDED BY AUDIENCE. (Lane SEC)
         *
         * ▲ THIS LINE USED TO READ:
         *
         *     // Unauthenticated back-office requests go to the admin login, not /login.
         *     $middleware->redirectGuestsTo(fn () => route('admin.login'));
         *
         * The comment described a scope the call does not have.
         * redirectGuestsTo() sets three APPLICATION-WIDE statics, so every
         * unauthenticated request to anything behind any guard answered with
         * the admin login -- including /my-account/orders and every other page
         * behind `auth:customer`. A logged-out shopper who clicked "Your
         * orders" was handed the owner's secret admin address in their address
         * bar and their browser history. `admin_path` is a secret everywhere
         * else in this codebase (CLAUDE.md, SettingController::PUBLIC_KEYS,
         * Mail\NewOrderAlert); this was the one door it walked out of.
         *
         * App\Support\GuestRedirect decides from the matched route's GUARD --
         * `auth:admin` is the back office, everything else is a shopper -- and
         * never from a string match on the path, because `admin_path` is
         * configurable and a shop that changed it would fall through to the
         * wrong branch.
         *
         * AND LIKE THE TWO REGISTRATIONS ABOVE, THIS ONE CANNOT SHIP.
         * bootstrap/ is on BuildPackage::NEVER_SHIP and UpdateGuard's forbidden
         * list. AppServiceProvider::boot() sets the same three statics to the
         * same closure, from a file that DOES ship, and its call lands after
         * this one (this callback runs when the HTTP kernel is resolved, which
         * is before providers boot), so the provider's registration is the one
         * that reaches the live shop and the one that wins. Both together are
         * safe: same closure, same statics.
         * tests/Feature/AdminPathNeverLeaksTest.php pins both halves.
         */
        $middleware->redirectGuestsTo(
            fn (\Illuminate\Http\Request $request) => \App\Support\GuestRedirect::for($request)
        );

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
         *
         * ── AND A SECOND ONE, FOR THE SAME REASON ──────────────────── Lane PG
         *
         * `kbb_filters` is whether the shopper has opened the filter rail on
         * /shop or a category archive. It ships HIDDEN (the owner asked for
         * that in as many words), the Hide and Show buttons write the cookie in
         * the click that toggles the class, and store/shop.blade.php reads it on
         * the server so the answer is in the <body> tag as it is sent — a class
         * added by script after load paints the sidebar and then takes it away,
         * which is a layout shift on the largest block of the page.
         *
         * SAFE TO EXEMPT, and in the same narrow way. The value is compared
         * against the single string 'open' and is NEVER PRINTED, so it cannot
         * reach the page in any form; anything else — no cookie, a stale value,
         * a forged one — is the shipped default. Nothing is authorised, priced
         * or filtered on the strength of it: it decides one CSS class, and a
         * visitor who forges it has done no more than press the button beside
         * it.
         */
        $middleware->encryptCookies(except: ['kbb_tz', 'kbb_filters']);

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
    /*
     * THE THIRD SOURCE, AND IT IS THE ONE A NEW INSTALL USES.
     *
     * `bootstrap/public-path.php` does not exist in this repository and is not
     * shipped by anything. install.php writes it, once, when a customer sets the
     * shop up on their own hosting -- because the fallback below names a folder
     * on exactly one server in the world and there is no shell for them to fix
     * it with.
     *
     * It is consulted BETWEEN the two existing sources, so nothing changes for
     * anyone: a preview or a CI job still wins with the real environment
     * variable, and a server that has neither still gets the literal below,
     * byte for byte. On the live shop this include simply does not exist and the
     * expression is what it has always been.
     *
     * @ on the include deliberately: an unreadable or half-written file must
     * degrade to the fallback rather than take the site down, and this line runs
     * before any error handler exists to make sense of a warning.
     */
    ->usePublicPath(
        getenv('KBB_PUBLIC_PATH')
        ?: (is_file(__DIR__.'/public-path.php') ? (@include __DIR__.'/public-path.php') : null)
        ?: '/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade'
    );
