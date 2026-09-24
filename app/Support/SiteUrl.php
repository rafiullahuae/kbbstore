<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The shop's own address: deriving it, normalising it, and writing it down.
 *
 * =============================================================================
 * THE ONE DISTINCTION THIS CLASS EXISTS TO KEEP
 * =============================================================================
 *
 * There are two kinds of absolute URL in a web application and conflating them
 * is how shops get taken over.
 *
 *   IN-BAND   A redirect `Location`, a link on the page the visitor is looking
 *             at right now. The right host is the host of the request being
 *             answered, because the visitor is already on it. Building these
 *             from the REQUEST is correct, and it is what the framework does.
 *             A forged `Host` here only redirects the forger to their own
 *             domain, which costs nobody anything.
 *
 *   OUT-OF-BAND  An email, a webhook callback registered at a payment provider,
 *             a `<link rel="canonical">`, a sitemap `<loc>`, an IndexNow ping.
 *             The recipient is NOT the person who made the request. The right
 *             host is one the OWNER has confirmed, and it may never come from
 *             a request header — a `Host:` a visitor chose, written into a
 *             password-reset email, is account takeover.
 *
 * So: `fromRequest()` is labelled a PROPOSAL and is never written anywhere by
 * itself. `configured()` is the confirmed value, and it is the only thing an
 * out-of-band URL may be built from. The step between them is a human — an
 * owner, signed in, clicking a button that names both hosts.
 *
 * =============================================================================
 * WHY AN AUTHENTICATED CLICK IS ACTUALLY A DIFFERENT THING FROM A HEADER
 * =============================================================================
 *
 * It would be a thin distinction if an attacker could send `Host: evil.example`
 * carrying the owner's admin session. They cannot: a cookie is scoped to the
 * domain the browser thinks it is talking to, so a request the browser sends to
 * `evil.example` carries `evil.example`'s cookies and none of the shop's. To
 * reach this application with both a forged host AND an owner session you must
 * already hold the owner session on the shop's real domain — at which point the
 * shop is yours regardless and `.env` is the least of it.
 *
 * That is the whole security argument, and it is why the adopt endpoint takes
 * the host from the REQUEST IT IS ANSWERING rather than from its own body. A
 * body field would be a value an attacker could choose; the request host is a
 * value they can only choose for a request they are themselves making.
 *
 * =============================================================================
 * WHY WRITING `.env` IS MORE THAN file_put_contents()
 * =============================================================================
 *
 * Three separate ways it goes wrong, each already paid for somewhere:
 *
 *   NOT ATOMIC. `file_put_contents()` truncates and then writes. A crash, a
 *   disk full, an `ENOSPC` between the two leaves a `.env` with no `APP_KEY`,
 *   and an application with no `APP_KEY` does not boot at all — on a host whose
 *   only repair tool lives inside the application. So: write a temporary file
 *   in the same directory, fsync it, and `rename()` it over the target, which
 *   is atomic on every POSIX filesystem. The old file is whole until the
 *   instant the new one is whole.
 *
 *   NOT ESCAPED. `install.php`'s original `kbb_env_escape()` quoted a value
 *   only if it contained a space, a `"` or a `#`. A NEWLINE is none of those,
 *   and the URL validation in front of it (`^https?://[^\s/]+`) is unanchored
 *   at the end — so `https://good.example\nAPP_DEBUG=true` passed validation,
 *   was written unquoted, and became two `.env` lines. That is arbitrary
 *   configuration injection from a form field. `assign()` below refuses any
 *   value carrying a control character outright rather than trying to encode
 *   its way out, and quotes everything else.
 *
 *   NOT READ. **`.env` is not consulted at all while `bootstrap/cache/config.php`
 *   exists.** That is this repository's oldest landmine — it is why `KBB_NOINDEX`
 *   read `false` for its entire life. Writing `APP_URL` without deleting the
 *   compiled config changes precisely nothing, and the feature would appear to
 *   work (the file says the new domain) and not work (every link still says the
 *   old one). `writeEnv()` therefore clears the compiled caches as part of the
 *   write and reports whether it managed to.
 *
 * =============================================================================
 * WHAT `normalise()` KEEPS, AND THE ONE THING PEOPLE EXPECT IT TO DROP
 * =============================================================================
 *
 * It keeps a PATH. `env.staging.txt` ships `APP_URL=https://easywebsol.com/kbb-upgrade`
 * and `KBB_BASE_PATH=/kbb-upgrade`: a shop served out of a sub-folder has that
 * folder as part of its address, and stripping it would break every install that
 * is not at a domain root. What it drops is a trailing slash, a query string, a
 * fragment, `index.php`, a default port, and `user:pass@` credentials — that
 * last one because a URL carrying credentials in an email is a phishing pattern
 * and has no business in `APP_URL`.
 */
final class SiteUrl
{
    /** The `.env` key this class is about. */
    public const KEY = 'APP_URL';

    /**
     * A usable absolute site address, or null.
     *
     * Deliberately strict, because everything downstream of it — an email, a
     * canonical tag, a `.env` line — treats what comes back as trustworthy.
     *
     * @param  bool  $assumeHttps  a bare host like `example.com` becomes
     *                             `https://example.com`. The installer wants
     *                             that (people type a domain when asked for an
     *                             address); a value read back out of `.env`
     *                             does not, because a missing scheme there is a
     *                             fact worth seeing rather than papering over.
     */
    public static function normalise(string $raw, bool $assumeHttps = false): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || strlen($raw) > 255) {
            return null;
        }

        /*
         * THE FIRST GATE, AND THE ONE THAT MATTERS MOST.
         *
         * Any control character — \n, \r, \0, a tab, a vertical tab — and this
         * is not an address. A newline is how a form field becomes an extra
         * `.env` line; a NUL is how a string that looks safe to PHP stops being
         * safe to the C function underneath it. There is no legitimate URL that
         * needs one, so there is nothing to lose by refusing.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            if (! $assumeHttps) {
                return null;
            }

            $raw = 'https://'.ltrim($raw, '/');
        }

        $parts = parse_url($raw);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // Credentials in a site address are never what anybody meant, and a
        // `https://admin:secret@shop.example/` in an order email is a phishing
        // lesson nobody should have to learn from their own shop.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = self::host((string) $parts['host']);

        if ($host === null) {
            return null;
        }

        $port = '';

        if (isset($parts['port'])) {
            $p = (int) $parts['port'];

            if ($p < 1 || $p > 65535) {
                return null;
            }

            // A default port is noise, and `https://x:443` compared against
            // `https://x` as a different address is a mismatch banner that
            // never goes away.
            if (! ($scheme === 'http' && $p === 80) && ! ($scheme === 'https' && $p === 443)) {
                $port = ':'.$p;
            }
        }

        // Query and fragment are simply dropped: they belong to a page, never
        // to a site.
        $path = self::path((string) ($parts['path'] ?? ''));

        return $scheme.'://'.$host.$port.$path;
    }

    /**
     * A host, lower-cased and checked, or null.
     *
     * Permissive enough for `localhost`, a bracketed IPv6 literal and an IDN
     * already in punycode; strict enough that nothing which is not a host can
     * get through. Not an attempt at the full RFC — an address is being checked
     * for sanity, not parsed for a resolver.
     */
    private static function host(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, '[')) {
            return preg_match('/^\[[0-9a-f:.]{2,45}\]$/', $host) === 1 ? $host : null;
        }

        if (strlen($host) > 253) {
            return null;
        }

        // Labels of letters, digits and hyphens; a hyphen never at either end.
        return preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1
            ? $host
            : null;
    }

    /**
     * The sub-folder part, normalised: leading slash, no trailing slash, no
     * front controller, no traversal.
     *
     * `''` for a shop at a domain root, which is what the live site is.
     */
    private static function path(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '';
        }

        // `/kbb-upgrade/index.php` and `/kbb-upgrade/` are the same folder.
        $path = preg_replace('#/(index\.php|install\.php)$#i', '/', $path) ?? $path;
        $path = '/'.trim($path, '/');

        if ($path === '/') {
            return '';
        }

        // A `..` in a site address is either a mistake or an attack; either way
        // it is not an address.
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return $path;
    }

    /**
     * The address the owner has confirmed — `APP_URL`, normalised.
     *
     * THE ONLY THING AN OUT-OF-BAND URL MAY BE BUILT FROM. Empty string when
     * `APP_URL` is missing or unusable, which callers must treat as "we do not
     * know" rather than falling back to the request.
     */
    public static function configured(): string
    {
        return self::normalise((string) config('app.url')) ?? '';
    }

    /**
     * What this request is arriving on. **A PROPOSAL, NEVER A SETTING.**
     *
     * Every byte of this is chosen by whoever sent the request. It is safe to
     * SHOW to a signed-in owner and safe to redirect the sender to. It is never
     * safe to write down, to put in an email, or to print in a canonical tag.
     * Nothing in this class writes it anywhere; the adopt endpoint is the only
     * caller that turns it into a stored value, and only behind an authenticated
     * owner and an explicit confirmation of this exact string.
     */
    public static function fromRequest(Request $request): ?string
    {
        $base = rtrim($request->getBaseUrl(), '/');

        return self::normalise($request->getSchemeAndHttpHost().$base);
    }

    /**
     * The host of the confirmed address, for comparing. Empty when unknown.
     */
    public static function configuredHost(): string
    {
        $url = self::configured();

        return $url === '' ? '' : (string) parse_url($url, PHP_URL_HOST);
    }

    /**
     * Does the address this request arrived on disagree with `APP_URL`?
     *
     * Returns null when they agree, when either is unusable, or when there is
     * nothing to compare — so a caller can say `if ($m = SiteUrl::mismatch($r))`
     * and get silence in the normal case. On the live shop, which is served from
     * the host it is configured with, this is null and the banner never draws:
     * that is how this whole feature ships inert.
     *
     * @return array{configured: string, current: string, configured_host: string, current_host: string}|null
     */
    public static function mismatch(Request $request): ?array
    {
        $configured = self::configured();
        $current = self::fromRequest($request);

        if ($configured === '' || $current === null || $configured === $current) {
            return null;
        }

        return [
            'configured' => $configured,
            'current' => $current,
            'configured_host' => (string) parse_url($configured, PHP_URL_HOST),
            'current_host' => (string) parse_url($current, PHP_URL_HOST),
        ];
    }

    /**
     * The origin an IN-BAND absolute URL is built on: `https://host[:port]`,
     * with no base path, for the request being answered.
     *
     * =========================================================================
     * THIS IS THE HALF THAT IS ALLOWED TO COME FROM THE REQUEST
     * =========================================================================
     *
     * A redirect Location, a link on the page in front of the visitor. The
     * visitor is already on this host, so answering with it is both correct and
     * harmless: a forged `Host` redirects the forger to their own domain and
     * nobody else's.
     *
     * ▲ AND IT IS THE HALF THIS APPLICATION GETS BACKWARDS TODAY.
     * `Support\Url::redirect()` builds EVERY storefront redirect from APP_URL,
     * so a shop reached on a host that is not APP_URL throws its visitors onto
     * the old domain. Measured on this branch: with APP_URL at
     * `https://old-shop.test`, `GET https://new-shop.test/checkout` answers
     *
     *     302 Location: https://old-shop.test/cart/
     *
     * The visitor is on new-shop.test, their session cookie is scoped to
     * new-shop.test, and they have just been sent somewhere that cannot see it.
     * Once the old domain stops resolving, that redirect is a dead end -- and
     * it is the redirect a shopper meets on the way to paying.
     *
     * NO CACHE CAN POISON THIS. App\Http\Middleware\CacheHeaders never emits
     * `public` for HTML and leaves a non-200 alone, so a redirect built from
     * one visitor's host is never handed to another. That is the precondition
     * for using the request here at all, and it is why the class comment's
     * split can be drawn where it is instead of one step more conservatively.
     *
     * FALLS BACK TO APP_URL with no request -- a queue worker, a console
     * command, a scheduled job. There is no visitor to be in-band with there,
     * so the confirmed value is the only answer, and it is the safe direction
     * to fail in.
     */
    public static function origin(?Request $request = null): string
    {
        /*
         * THE REQUEST IS PASSED IN, NOT REACHED FOR, AND THAT IS THE POINT.
         *
         * `app()->runningInConsole()` is true under PHPUnit as well as under
         * artisan, so a class that resolves the bound request itself behaves
         * one way in production and another in every test of it -- which is how
         * a guard gets written, passes, and turns out never to have run in the
         * shape that matters. Naming the request makes the contract explicit:
         * "here is the visitor I am in-band with". No argument means there is
         * no visitor, and the confirmed value is the only answer.
         *
         * The bound request is still used when nothing is passed and this is
         * plainly an HTTP request, so a caller that has one in scope need not
         * thread it through.
         */
        if ($request === null && ! app()->runningInConsole() && app()->bound('request')) {
            $bound = request();

            if ($bound instanceof Request) {
                $request = $bound;
            }
        }

        if ($request instanceof Request) {
            $host = self::normalise($request->getSchemeAndHttpHost());

            if ($host !== null) {
                return $host;
            }
        }

        return self::externalOrigin();
    }

    /**
     * The origin an OUT-OF-BAND absolute URL is built on: always `APP_URL`,
     * never the request, with any base path taken off the end.
     *
     * An email, a webhook callback registered at a payment provider, a
     * canonical tag, a sitemap entry, an IndexNow ping. The recipient is not
     * the person who made the request, so a `Host:` header has no standing.
     * There is NO request fallback here and there must never be one: the whole
     * point of this method is that it answers the same thing whoever is asking.
     *
     * Empty string when APP_URL is unusable, which a caller must treat as "we
     * do not know" rather than reaching for the request.
     */
    public static function externalOrigin(): string
    {
        $url = self::configured();

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * One `.env` line: `KEY="value"`.
     *
     * ALWAYS QUOTED, and always with the same four escapes. Quoting a value
     * that does not need it costs nothing and removes the whole class of
     * question "does this one need it"; phpdotenv reads a double-quoted value
     * back byte for byte once `\`, `"`, `$` and a backtick are escaped.
     *
     * A value carrying a control character is refused rather than encoded —
     * see the class comment. There is no such site address, database name or
     * shop name, so refusing costs a real install nothing and closes the
     * injection.
     */
    public static function envLine(string $key, string $value): ?string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        $escaped = str_replace(
            ['\\', '"', '$', '`'],
            ['\\\\', '\\"', '\\$', '\\`'],
            $value
        );

        return $key.'="'.$escaped.'"';
    }

    /**
     * Rewrite `$pairs` into the text of a `.env`, leaving everything else alone.
     *
     * Every other key, every comment and every blank line comes back in its
     * original order and with its original bytes. A key that is not already
     * there is appended. A key that appears twice — which phpdotenv resolves as
     * last-wins — has EVERY occurrence rewritten, so the file cannot come out
     * of this saying two different things.
     *
     * Returns null if any pair is unwritable, because a partial rewrite of a
     * `.env` is worse than none.
     *
     * @param  array<string, string>  $pairs
     */
    public static function apply(string $contents, array $pairs): ?string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $seen = [];

        foreach ($pairs as $key => $value) {
            if (self::envLine((string) $key, (string) $value) === null) {
                return null;
            }
        }

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) !== 1) {
                continue;
            }

            $key = $m[1];

            if (! array_key_exists($key, $pairs)) {
                continue;
            }

            $lines[$i] = (string) self::envLine($key, (string) $pairs[$key]);
            $seen[$key] = true;
        }

        foreach ($pairs as $key => $value) {
            if (! isset($seen[(string) $key])) {
                $lines[] = (string) self::envLine((string) $key, (string) $value);
            }
        }

        $out = implode("\n", $lines);

        return str_ends_with($out, "\n") ? $out : $out."\n";
    }

    /**
     * Write `$pairs` into the `.env` at `$path`, atomically.
     *
     * Temporary file in the SAME DIRECTORY (rename() is only atomic within a
     * filesystem), flushed, then renamed over the target. At no instant does a
     * reader see a half-written `.env`, and a crash at any point leaves the old
     * one intact.
     *
     * @param  array<string, string>  $pairs
     * @return array{ok: bool, reason?: string, caches_cleared?: list<string>}
     */
    public static function writeEnv(string $path, array $pairs): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['ok' => false, 'reason' => 'There is no readable .env file to update.'];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return ['ok' => false, 'reason' => 'The .env file could not be read.'];
        }

        $next = self::apply($contents, $pairs);

        if ($next === null) {
            return ['ok' => false, 'reason' => 'That value cannot be written to a configuration file.'];
        }

        $dir = dirname($path);
        $tmp = $dir.'/.env.'.bin2hex(random_bytes(6)).'.tmp';

        $handle = @fopen($tmp, 'wb');

        if ($handle === false) {
            return ['ok' => false, 'reason' => 'Could not write beside the .env file. Check the folder is writable.'];
        }

        $written = @fwrite($handle, $next);

        // fflush before fsync: the first pushes PHP's buffer at the OS, the
        // second pushes the OS's at the disk. Without them a rename can land
        // ahead of the bytes it is renaming.
        if ($written !== false) {
            @fflush($handle);

            if (function_exists('fsync')) {
                @fsync($handle);
            }
        }

        @fclose($handle);

        if ($written === false || $written !== strlen($next)) {
            @unlink($tmp);

            return ['ok' => false, 'reason' => 'The new .env was only partly written, so nothing was changed.'];
        }

        // Carry the old file's permissions over rather than inheriting the
        // temporary file's; .env is 0640 on a correctly set up host and a
        // world-readable one hands out the database password.
        $mode = @fileperms($path);
        @chmod($tmp, $mode !== false ? ($mode & 0777) : 0640);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return ['ok' => false, 'reason' => 'Could not replace the .env file.'];
        }

        return ['ok' => true, 'caches_cleared' => self::clearCompiledConfig()];
    }

    /**
     * Delete the compiled config, and the compiled routes with it.
     *
     * WITHOUT THIS THE WRITE ABOVE DOES NOTHING. Laravel skips `.env` entirely
     * when `bootstrap/cache/config.php` is present, so a new `APP_URL` sits in a
     * file nothing reads. This repository has the scar: `KBB_NOINDEX` was
     * `false` for its whole life for exactly this reason.
     *
     * The routes cache goes too. It is not strictly required for `APP_URL`, but
     * it is regenerated free on the next request, and a domain move is precisely
     * the moment somebody discovers a stale route cache — CUTOVER-EXTRABEAUTY §1
     * is three paragraphs about clearing this folder by hand.
     *
     * @return list<string> what was actually removed, for the screen to show.
     */
    public static function clearCompiledConfig(): array
    {
        $app = app();
        $gone = [];

        /*
         * THE APPLICATION'S OWN GETTERS, NOT bootstrapPath('cache').
         *
         * Those four paths are overridable with APP_CONFIG_CACHE,
         * APP_ROUTES_CACHE and friends, and this project's own preview harness
         * sets them -- Tests\Support\CompiledCaches exists precisely so two
         * runs do not compile over each other. A hard-coded bootstrap/cache
         * deletes a file nothing is reading and leaves the one that is, which
         * is the silent half of this failure rather than the loud one.
         * Measured: a preview with APP_ROUTES_CACHE pointed elsewhere kept
         * serving the cached routes after a clear that reported success.
         */
        foreach ([
            $app->getCachedConfigPath(),
            $app->getCachedRoutesPath(),
            $app->getCachedServicesPath(),
            $app->getCachedPackagesPath(),
        ] as $file) {
            if (is_string($file) && $file !== '' && is_file($file) && @unlink($file)) {
                $gone[] = basename($file);
            }
        }

        // And the conventional routes-*.php beside them, which Laravel writes
        // one per cached route file and getCachedRoutesPath() names only one of.
        foreach ((array) glob($app->bootstrapPath('cache').'/routes-*.php') as $file) {
            if (is_string($file) && is_file($file) && @unlink($file)) {
                $gone[] = basename($file);
            }
        }

        return array_values(array_unique($gone));
    }
}
