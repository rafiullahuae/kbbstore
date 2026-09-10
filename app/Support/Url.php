<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Internal link builder.
 *
 * Every storefront path is written as it will appear in production — "/shop/",
 * "/product/foo/", "/my-account/" — and this prefixes the configured base path
 * at render time.
 *
 * Why not Laravel's url() helper: it derives the prefix from the incoming
 * request, which is right for most apps but wrong here. The URL Contract fixes
 * these paths exactly, so the prefix has to be an explicit setting we control,
 * not something inferred per request and liable to differ behind a proxy.
 *
 * Trailing slashes are preserved deliberately (U-01). WordPress uses them and
 * Laravel does not, so dropping one turns an indexed URL into a redirect.
 */
final class Url
{
    /**
     * The path prefix the site is served under.
     *
     * Derived from the request first, config second — deliberately that way
     * round. config('kbb.base_path') depends on config/kbb.php existing, on
     * KBB_BASE_PATH being in .env, and on the config cache being current. Any
     * one of those going wrong silently emits root-relative links, which is how
     * the checkout button ended up pointing at easywebsol.com/checkout.
     *
     * Request::getBaseUrl() is the prefix the front controller is actually
     * served from — "/kbb-upgrade" here, "" at a domain root. It cannot go
     * stale, cannot be cached wrong, and needs no configuration. If it is ever
     * necessary to override it, KBB_BASE_PATH still wins.
     *
     * Memoised: called once per link, and there can be a hundred on a page.
     */
    private static ?string $base = null;

    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        // An explicit setting always wins, so the behaviour stays overridable.
        $configured = trim((string) config('kbb.base_path', ''), '/');

        if ($configured !== '') {
            return self::$base = '/' . $configured;
        }

        // Console commands have no request; a root-relative path is correct there.
        if (! app()->runningInConsole() && app()->bound('request')) {
            return self::$base = rtrim((string) request()->getBaseUrl(), '/');
        }

        return self::$base = '';
    }

    /** Test seam, and used by the cart diagnostics. */
    public static function debugBase(): array
    {
        return [
            'configured' => (string) config('kbb.base_path', ''),
            'request_base_url' => app()->runningInConsole() ? null : request()->getBaseUrl(),
            'resolved' => self::base(),
            'app_url' => (string) config('app.url'),
        ];
    }

    /** A storefront path, prefixed for the current environment. */
    public static function to(string $path = '/'): string
    {
        $base = self::base();

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        // Absolute URLs and mailto/tel links pass through untouched.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /** A media path under /wp-content/uploads. */
    public static function media(string $path): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $root = (string) config('kbb.media_root', '/wp-content/uploads');
        $path = ltrim($path, '/');

        // Accept paths already carrying the root, so importers can store either form.
        if (str_starts_with('/' . $path, $root)) {
            return self::to($path);
        }

        return self::to(ltrim($root, '/') . '/' . $path);
    }

    /**
     * A URL safe to hand to redirect().
     *
     * to() returns a root-relative path with the base prefix, which is right for
     * an href — the browser resolves it against the host. redirect() is
     * different: Laravel resolves a relative path against APP_URL, and APP_URL
     * already ends in the base path. Passing to() there produced
     * /kbb-upgrade/kbb-upgrade/cart and a 404.
     *
     * This returns an absolute URL, which Laravel passes through untouched, so
     * the base appears exactly once however APP_URL is written.
     */
    public static function redirect(string $path = '/'): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $base = self::base();

        // Strip the base off APP_URL if it is already there, then add it once.
        if ($base !== '' && str_ends_with($appUrl, $base)) {
            $appUrl = substr($appUrl, 0, -strlen($base));
        }

        return rtrim($appUrl, '/') . self::to($path);
    }
}
