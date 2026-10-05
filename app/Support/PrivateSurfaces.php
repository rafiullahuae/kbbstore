<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AdminPathService;
use App\Services\OwnerApp\OwnerAppPath;

/**
 * Every address a search engine must never index (Lane OA3).
 *
 * The owner, 5 October: "make sure the sync etc must be OFF for any search
 * engine." Before this the answer depended on which surface you asked: the
 * owner app sent `X-Robots-Tag` from its own route middleware, the admin login
 * page carried only a <meta> tag (which a crawler reads only if it parses the
 * HTML, and which a JSON answer from /admin-api cannot carry at all), and
 * nothing at all marked the token pages a password-reset email links to.
 *
 * One list, read by App\Http\Middleware\PrivateNoIndex on every response and
 * by the two places that hand addresses to search engines — robots.txt (a
 * custom file the owner types) and IndexNow — so a private address is neither
 * served indexable nor ever named to a crawler.
 *
 * NOT IN robots.txt, ON PURPOSE. A Disallow line naming the owner app would
 * publish the one secret that keeps scanners off it, and a Disallow stops the
 * crawl that would have read the noindex. The header does the job instead.
 */
final class PrivateSurfaces
{
    public const ROBOTS = 'noindex, nofollow, noarchive';

    /** First path segments that are never a page for the public. */
    public const ROOTS = ['admin-api', 'import-chain', '_kbb-health', 'up'];

    /** /my-account/<this>/… carry a one-time token in the address, or ask for an email to send one to. */
    public const TOKEN_PAGES = ['forgot', 'reset', 'welcome', 'verify'];

    /**
     * Is this request path (relative, as Request::path() gives it) a private
     * surface? The owner app is matched by covers() here: callers of this
     * method are building a file for a crawler, where leaving the address out
     * is the whole point. The middleware matches the app and the admin by
     * their ROUTE instead (PrivateNoIndex), so a mistyped spelling of either
     * secret answers exactly like any other 404, and a storefront page pays no
     * settings read for the question.
     */
    public static function isPrivatePath(string $path): bool
    {
        if (self::isPrivateRoot($path)) {
            return true;
        }

        $first = explode('/', self::bare($path))[0];

        return ($first !== '' && $first === strtolower(trim(AdminPathService::current(), '/')))
            || OwnerAppPath::covers(self::bare($path));
    }

    /** The fixed half of the list: no setting is read to answer it. */
    public static function isPrivateRoot(string $path): bool
    {
        $segments = explode('/', self::bare($path));

        return in_array($segments[0], self::ROOTS, true)
            || ($segments[0] === 'my-account' && in_array($segments[1] ?? '', self::TOKEN_PAGES, true));
    }

    /** Decoded, lower-cased, no locale segment, no outer slashes. */
    private static function bare(string $path): string
    {
        [, $path] = Locale::splitPath('/'.ltrim(rawurldecode($path), '/'));

        return strtolower(trim($path, '/'));
    }

    /**
     * Is this the owner app's own host? Everything served there is private,
     * including a shop page reached by typing a path the app does not own.
     *
     * Asked only when the request is NOT on the shop's own host: the dedicated
     * host is read through the cache, and the storefront must not pay a cache
     * read per page for a setting that is empty on almost every install.
     */
    public static function isPrivateHost(string $host): bool
    {
        $host = SiteHost::normalise($host);

        if ($host === '' || $host === SiteHost::canonical() || $host === SiteHost::normalise((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
            return false;
        }

        return $host === OwnerAppPath::host();
    }

    /** A full URL: on the owner app's host, or at a private path. */
    public static function isPrivateUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== '' && self::isPrivateHost($host)) {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = trim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
        $path = trim($path, '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base.'/'))) {
            $path = substr($path, strlen($base));
        }

        return self::isPrivatePath($path);
    }

    /**
     * Drop every line that names a private address from a robots.txt the owner
     * typed himself. Nothing else in his text moves: a file with no secret in
     * it comes back byte for byte.
     */
    public static function scrubRobots(string $body): string
    {
        $secrets = array_values(array_filter([
            OwnerAppPath::current(),
            OwnerAppPath::host(),
            ($admin = strtolower(trim(AdminPathService::current(), '/'))) !== 'admin' ? $admin : null,
        ], static fn ($s) => is_string($s) && $s !== ''));

        if ($secrets === []) {
            return $body;
        }

        $lines = preg_split('/(?<=\n)/', $body) ?: [$body];
        $kept = array_filter($lines, static function (string $line) use ($secrets): bool {
            $lower = strtolower(rawurldecode($line));
            foreach ($secrets as $secret) {
                if (preg_match('/(^|[^a-z0-9_-])'.preg_quote($secret, '/').'($|[^a-z0-9_-])/', $lower) === 1) {
                    return false;
                }
            }

            return true;
        });

        return implode('', $kept);
    }
}
