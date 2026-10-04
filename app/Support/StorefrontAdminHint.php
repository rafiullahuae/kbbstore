<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The storefront's "an administrator may be here" hint. (Lane RA)
 *
 * ── WHY A HINT AND NOT THE SESSION ─────────────────────────────────────────
 *
 * The owner asked for a bar across the top of the shop and a pencil on every
 * category and brand header, "only administrator". The obvious build -- ask
 * auth('admin') while rendering the page and print the bar into the HTML -- is
 * the one this project must not do. The storefront's HTML is the same document
 * for everyone who asks for it: CacheHeaders can mark it cacheable, the owner
 * may put a CDN in front of it, and a page that carried the bar for one request
 * is one cache key away from showing the admin console's address to a shopper.
 *
 * So the HTML never changes. The page's ordinary JavaScript looks for THIS
 * cookie, and only when it is there does it ask an authenticated endpoint
 * (GET /admin-api/storefront/context) whether there is anything to draw. A
 * shopper's browser has no such cookie and makes no such request: zero bytes,
 * zero requests, zero queries for everybody who is not signed in to the back
 * office.
 *
 * ── WHAT THE COOKIE IS, AND WHAT IT IS NOT ─────────────────────────────────
 *
 * It is a HINT. It carries no secret and grants nothing. A shopper who types
 * `document.cookie = "kbb_ah=1"` makes their own browser send one request, which
 * the auth:admin middleware answers 401 -- and the loader then deletes the
 * cookie, so it does not even cost them a second one. The authority is the
 * session cookie, which is HttpOnly and is the same one the console uses.
 *
 * It is NOT HttpOnly (the page's script has to be able to see it), it is
 * SameSite=Lax and on the session's own path (`/` on the live shop,
 * `/kbb-upgrade` on staging -- the same path the session cookie is on, so the
 * two are always sent together), and Secure whenever the session cookie is.
 * Laravel encrypts it like any other cookie; the script only tests whether the
 * NAME is present, so the value does not matter.
 *
 * ── HOW LONG IT LIVES ──────────────────────────────────────────────────────
 *
 * As long as the sign-in it describes: the session lifetime, or -- when the
 * owner ticked "remember me", which keeps him signed in through the remember
 * cookie long after the session itself has expired -- 400 days, the ceiling
 * browsers honour. It is set at sign-in, refreshed every time the context
 * endpoint answers yes, and expired at sign-out.
 */
final class StorefrontAdminHint
{
    public const COOKIE = 'kbb_ah';

    /** Browsers cap a cookie's lifetime at 400 days. In minutes. */
    private const REMEMBERED_MINUTES = 400 * 24 * 60;

    /**
     * Does this account hold anything the storefront layer would draw?
     *
     * An account that holds neither capability is not given the hint at all,
     * so a support login does not make every shop page ask a question whose
     * answer is always 403.
     */
    public static function wantedBy(?Authenticatable $admin): bool
    {
        if (! $admin instanceof AdminUser) {
            return false;
        }

        return self::can($admin, 'storefront.adminbar') || self::can($admin, 'storefront.quick_edit');
    }

    /** One account, one capability, with the owner short-circuit EnforceAdminCapability uses. */
    public static function can(AdminUser $admin, string $capability): bool
    {
        // Lane RL: the account's role and its own tweaks, owner first.
        return AdminRoles::can($admin, $capability);
    }

    /** The hint, for a sign-in that will last $remember ? a long time : one session. */
    public static function make(bool $remember): Cookie
    {
        $minutes = $remember ? self::REMEMBERED_MINUTES : max(1, (int) config('session.lifetime', 120));

        return cookie(
            self::COOKIE,
            '1',
            $minutes,
            self::path(),
            config('session.domain'),
            config('session.secure'),
            false,          // NOT HttpOnly: the storefront's script reads it.
            false,
            'lax',
        );
    }

    /** The same hint, refreshed for whatever sign-in this request carries. */
    public static function refresh(Request $request): Cookie
    {
        $guard = Auth::guard('admin');
        $remembered = method_exists($guard, 'getRecallerName')
            && $request->cookies->has($guard->getRecallerName());

        return self::make($remembered);
    }

    /** Expire it. Same name, path and domain, or the browser keeps the old one. */
    public static function forget(): Cookie
    {
        return cookie(
            self::COOKIE,
            '',
            -2628000,
            self::path(),
            config('session.domain'),
            config('session.secure'),
            false,
            false,
            'lax',
        );
    }

    private static function path(): string
    {
        $path = (string) config('session.path', '/');

        return $path === '' ? '/' : $path;
    }
}
