<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AdminPathService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Where an unauthenticated request is sent — decided by AUDIENCE. (Lane SEC)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE SECRET ADMIN ADDRESS WAS BEING HANDED TO EVERY LOGGED-OUT SHOPPER WHO
 * CLICKED "YOUR ORDERS".
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `bootstrap/app.php` carried one line, with a comment that described a scope
 * it did not have:
 *
 *     // Unauthenticated back-office requests go to the admin login, not /login.
 *     $middleware->redirectGuestsTo(fn () => route('admin.login'));
 *
 * `redirectGuestsTo()` is not scoped to the back office. It sets three
 * application-wide statics — Authenticate, AuthenticateSession and
 * AuthenticationException — so EVERY unauthenticated request to anything behind
 * any guard answered with the admin login. `/my-account/orders`, the address
 * book, the order detail page, the verification notice: all of them are behind
 * `auth:customer`, and all of them sent a shopper to `/<admin_path>/login`.
 *
 * The owner reported it from his own shop, off the order-tracking page:
 *
 *     "on the tracking result page, the login link is going to administration
 *      login, which is only for ME. Please make sure all the login, account etc
 *      url must go to the user login page. /mr-cool link or any administration
 *      (my links) must not show anywhere."
 *
 * The link itself was never wrong. `store/account/track.blade.php` points at
 * `/my-account/orders/`, which is correct; the redirect is what turned it into
 * the back-office address.
 *
 * ── WHY THIS IS A LEAK AND NOT A BROKEN LINK ───────────────────────────────
 *
 * `admin_path` is a secret in this codebase and is treated as one everywhere
 * else. CLAUDE.md names it among the columns that must never leave `settings`
 * through `/api/*`; `Api\SettingController::PUBLIC_KEYS` exists partly to hold
 * it in; `Mail\NewOrderAlert` refuses to print it into an email for the same
 * reason. A guest redirect put it in the address bar, the browser history and
 * any `Referer` the next click sends — of a stranger, not the owner.
 *
 * ── HOW THE AUDIENCE IS DECIDED: THE GUARD, NEVER THE PATH ─────────────────
 *
 * `admin_path` is configurable (KBB_ADMIN_PATH, or the `admin_path` settings
 * row), so a shop that changed it must not fall through to the wrong branch —
 * which is exactly what a `str_starts_with($request->path(), 'admin')` test
 * would do. What is NOT configurable is which guard a route asks for, and this
 * application names it on every guarded route without exception: `auth:admin`
 * for the back office, `auth:customer` for the storefront. So the decision is
 * read off the matched route's middleware, and the guard name is the input.
 *
 * ── AND IT FAILS TOWARDS THE STOREFRONT ────────────────────────────────────
 *
 * No matched route, an unparsable middleware entry, a guard this file has never
 * heard of: every one of them answers with the CUSTOMER login. That is the
 * direction that cannot leak. The worst a wrong answer can do here is show a
 * shopper's login form to somebody who wanted the admin one — who knows the
 * admin address already, because they typed it.
 *
 * ── WHERE IT IS REGISTERED, AND WHY IN TWO PLACES ──────────────────────────
 *
 * `bootstrap/app.php` is on BuildPackage::NEVER_SHIP and UpdateGuard's
 * forbidden list, so a fix written only there can never reach the live shop —
 * the same story as SetLocaleFromPath, CheckRedirects and CacheHeaders, each of
 * which is registered a second time from AppServiceProvider::boot() for exactly
 * this reason. This one is too, and the provider's registration is the one that
 * counts: `withMiddleware()`'s callback runs when the HTTP kernel is resolved,
 * which is BEFORE providers boot, so the provider's call to the same statics
 * lands last and wins. Both together are safe because they set the same three
 * statics to the same closure.
 */
final class GuestRedirect
{
    /**
     * The guard the back office runs on. One name, from config/auth.php, and
     * the only thing in this file that decides for the admin login.
     */
    public const ADMIN_GUARD = 'admin';

    /**
     * Middleware aliases and classes that name a guard to authenticate against.
     *
     * `auth` is the alias every route in this application uses. The two class
     * names are here because a route may be written with the class directly and
     * the alias would then never be seen.
     */
    private const AUTH_MIDDLEWARE = [
        'auth',
        \Illuminate\Auth\Middleware\Authenticate::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
    ];

    /**
     * The address this guest should be sent to.
     *
     * Absolute-path strings, because that is what
     * Illuminate\Foundation\Exceptions\Handler::unauthenticated() hands to
     * `redirect()->guest()` — which stores the intended URL in the session on
     * the way past, so a shopper who signs in lands on the page they asked for.
     * Store\CustomerAuthController::login() already ends in
     * `redirect()->intended('/my-account/')` and Admin\AdminAuthController
     * already ends in `redirect()->intended(route('admin'))`, so both halves of
     * that were in place before this file existed.
     */
    public static function for(?Request $request): string
    {
        return self::wantsBackOffice($request)
            ? route('admin.login')
            /*
             * Url::to() and not route('account'), because it is what every
             * other storefront link in this application is built with: it
             * carries KBB_BASE_PATH and the /ar segment, so an Arabic shopper
             * bounced off /ar/my-account/orders lands on /ar/my-account rather
             * than being dropped into English. The trailing slash matches
             * store/account/track.blade.php's own link to /my-account/orders/.
             */
            : Url::to('/my-account/');
    }

    /**
     * Should this request be REFUSED OUTRIGHT rather than pointed at a login?
     *
     * ═══════════════════════════════════════════════════════════════════════
     * THE SECOND DOOR THE ADMIN ADDRESS WALKED OUT OF, AND THE ONLY ONE LEFT.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * 398 of this shop's admin-guarded endpoints do NOT live under the secret
     * admin path. 397 are `admin-api/...` — a FIXED prefix anybody can guess —
     * and the last is `api/cart/debug`. Ask for one of them logged out and the
     * answer named the secret:
     *
     *     curl -sI https://extrabeauty.ae/admin-api/security
     *     Location: https://extrabeauty.ae/mr-cool/login
     *
     * So `admin_path` was readable by anyone who could type `admin-api`, which
     * is the whole of the protection it offers, gone through a different door
     * from the one the storefront redirect closed.
     *
     * ── WHO ACTUALLY TOOK THAT BRANCH, MEASURED RATHER THAN ASSUMED ────────
     *
     * Illuminate\Foundation\Exceptions\Handler::unauthenticated() answers a
     * request that expects JSON with `401 {"message":"Unauthenticated."}` and
     * NO Location header at all, and only falls through to
     * `redirect()->guest()` for everything else. Measured on this shop:
     *
     *     Accept: application/json        -> 401, Location NULL
     *     X-Requested-With: XMLHttpRequest-> 401, Location NULL
     *     a browser's own Accept          -> 302, Location .../mr-cool/login
     *
     * And every one of the console's 43 `fetch()` calls to `admin-api` sets
     * `Accept: application/json` — the wrapper in admin/app.blade.php and the
     * ~20 screen partials that each carry their own. So THE CONSOLE HAS NEVER
     * SEEN THAT REDIRECT. The only things that ever followed it were a stranger
     * with curl and five download navigations (see below).
     *
     * ── WHICH IS WHY THIS DOES NOT TOUCH THE JSON ANSWER ───────────────────
     *
     * ▲ Turning the console's 401 into a 404 would be a worse defect than the
     * one being fixed, and the console says so itself. THIRTY-THREE screens
     * read a 404 from their own endpoints as
     *
     *     "The … endpoints are not in this server's compiled route table yet.
     *      Clear the route cache (Platform → Cache) and reload."
     *
     * — 53 occurrences — because a package applied by hand without its
     * `clear_caches_*` migration is a real and frequent fault on this shop.
     * THREE screens already read 401/419 as *"Your admin session has expired —
     * nothing is wrong with this screen."* Collapsing the two would send the
     * owner to clear caches over an expired login, which is precisely the
     * "three faults with three different remedies, one sentence" trap those
     * comments were written against.
     *
     * The 401 leaks nothing: it carries no Location and names nothing. So the
     * JSON answer is left exactly as it is, and only the branch that actually
     * printed the address changes.
     *
     * ── AND IT IS 404, NOT A REFUSAL THAT ADMITS THE ADDRESS EXISTS ────────
     *
     * A stranger who browses to `admin-api/security` now gets the same 404 the
     * router gives for a path that was never registered. That is the shape
     * CLAUDE.md already records for `QuizController::expertRequest`, where a
     * forged token and an id that was never issued do the same work and return
     * the same 404.
     *
     * ── THE TEST IS THE GUARD, NEVER THE PATH ──────────────────────────────
     *
     * A route KEEPS the redirect when the admin path is one of its own
     * segments, because a requester who reached it has already typed the
     * secret and cannot be told anything they did not bring. Compared segment
     * by segment rather than by prefix, so `admin-api/...` is not mistaken for
     * a path under `admin`, and so a KBB_BASE_PATH prefix cannot shift the
     * comparison. Everything else that asks for the `admin` guard is hidden.
     */
    public static function hidesTheAddressInstead(?Request $request): bool
    {
        if ($request === null || ! self::wantsBackOffice($request)) {
            return false;
        }

        /*
         * The console's own calls, left exactly as they were. expectsJson() is
         * what Handler::unauthenticated() itself branches on, so this asks the
         * same question the framework asks rather than a second one that could
         * drift from it.
         */
        if ($request->expectsJson()) {
            return false;
        }

        $adminPath = trim(AdminPathService::current(), '/');

        if ($adminPath === '') {
            return true;
        }

        $uri = trim((string) $request->route()?->uri(), '/');

        return ! in_array($adminPath, explode('/', $uri), true);
    }

    /**
     * Is this request for the back office?
     *
     * TRUE ONLY when the matched route asks, in as many words, to be
     * authenticated against the `admin` guard. Everything else — including no
     * route at all — is a shopper.
     */
    public static function wantsBackOffice(?Request $request): bool
    {
        $route = $request?->route();

        if (! $route instanceof Route) {
            return false;
        }

        /*
         * gatherMiddleware() and not middleware(): it merges the route's own
         * list with any the controller declares. It is memoised on the Route
         * object and the router has already called it to build the pipeline
         * this very request is running inside, so reading it here costs
         * nothing at all — no query, no controller instantiation.
         */
        foreach ($route->gatherMiddleware() as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (in_array(self::ADMIN_GUARD, self::guardsNamedBy($entry), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The guards one middleware entry names, or none.
     *
     * `auth:admin` names one. `auth:admin,web` names two — Laravel tries each
     * in turn, so a list that mentions `admin` at all is a back-office route.
     * A bare `auth` names none HERE deliberately: it would fall to the default
     * guard in config/auth.php, which is `web`, and `web` is not the back
     * office — no route in this application uses either, and guessing is how
     * the wrong branch gets taken.
     *
     * @return list<string>
     */
    private static function guardsNamedBy(string $entry): array
    {
        $colon = strpos($entry, ':');

        if ($colon === false) {
            return [];
        }

        $name = substr($entry, 0, $colon);

        if (! in_array($name, self::AUTH_MIDDLEWARE, true)) {
            return [];
        }

        $guards = [];

        foreach (explode(',', substr($entry, $colon + 1)) as $guard) {
            $guard = trim($guard);

            if ($guard !== '') {
                $guards[] = $guard;
            }
        }

        return $guards;
    }
}
