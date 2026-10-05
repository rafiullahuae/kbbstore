<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where the owner app lives: one secret first path segment.
 *
 * Resolution order, the same as AdminPathService and for the same reason:
 *
 *   1. KBB_OWNER_APP_PATH in .env — always wins, the escape hatch. Read
 *      through config('owner_app.path') so `config:cache` keeps it;
 *   2. the `owner_app_path` settings row, written by the migration with a
 *      fresh random value and re-rollable from Users & Roles → Owner app;
 *   3. NOTHING. Unlike the admin, there is no fallback word: an app with no
 *      address configured is not mounted at all. Failing closed is the point.
 *
 * ── WHY EVERY ADDRESS CARRIES AN UNDERSCORE ────────────────────────────────
 *
 * The last route in web.php serves an article at any single lowercase
 * segment, and its pattern (PageController::slugPattern()) can never match a
 * segment with an underscore in it. So an article published later can never
 * shadow the app, and the app does not have to be added to RESERVED_SLUGS —
 * which would have printed the secret into a list a test walks. The rule is
 * enforced by valid(), and the generated value always has one.
 *
 * It is NOT written into robots.txt: a Disallow line would publish the very
 * address it is meant to protect. The app answers every request with
 * `X-Robots-Tag: noindex, nofollow, noarchive` instead, and nothing links to it.
 */
final class OwnerAppPath
{
    public const SETTING = 'owner_app_path';

    private const CACHE_KEY = 'kbb.owner_app_path';

    public const HOST_SETTING = 'owner_app_host';

    private const HOST_CACHE_KEY = 'kbb.owner_app_host';

    /** @var string|false|null false = no host configured */
    private static string|false|null $hostMemo = null;

    /** @var string|false|null false = resolved to "not configured" */
    private static string|false|null $memo = null;

    public static function current(): ?string
    {
        if (self::$memo === null) {
            self::$memo = self::resolve() ?? false;
        }

        return self::$memo === false ? null : self::$memo;
    }

    public static function forgetMemo(): void
    {
        self::$memo = null;
        self::$hostMemo = null;
    }

    /**
     * Is this request path (relative, as Request::path() gives it) the app or
     * under it? Decoded and case-folded first: /ABC_DEF or /%61bc_def is a
     * miss the router answers with the shop's 404, and it spells the secret
     * just as well.
     */
    public static function covers(string $path): bool
    {
        $app = self::current();
        $path = strtolower(trim(rawurldecode($path), '/'));

        return $app !== null && ($path === $app || str_starts_with($path, $app.'/'));
    }

    public static function isLockedByEnv(): bool
    {
        return self::fromEnv() !== '';
    }

    /** 12–64 characters, lowercase letters, digits, - and _, at least one _. */
    public static function valid(string $path): bool
    {
        return (bool) preg_match('/^(?=[a-z0-9_-]*_)[a-z0-9][a-z0-9_-]{10,62}[a-z0-9]$/D', $path);
    }

    /** About 113 bits: ten base-36 characters, an underscore, twelve more. */
    public static function generate(): string
    {
        $pick = static function (int $n): string {
            $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= $alphabet[random_int(0, 35)];
            }

            return $out;
        };

        return $pick(10).'_'.$pick(12);
    }

    /** Write a new address. The old one stops answering at once. */
    public static function set(string $path): bool
    {
        if (! self::valid($path)) {
            return false;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => self::SETTING],
            ['value' => $path, 'autoload' => false, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
        Cache::forget('kbb.settings');
        \App\Models\Setting::flushMap();
        self::$memo = null;

        // A compiled route table would keep serving the old address.
        foreach (glob(base_path('bootstrap/cache/routes*.php')) ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    /** The address, creating one if the migration could not. */
    public static function ensure(): string
    {
        if (self::isLockedByEnv() && ($current = self::current()) !== null) {
            return $current;
        }

        // The ROW decides, never the cache: a cache that outlived its database
        // (a restored backup, a shared cache directory) would otherwise answer
        // with an address no row backs, and nothing would ever be written.
        $stored = trim((string) DB::table('settings')->where('key', self::SETTING)->value('value'), '/');

        if (self::valid($stored)) {
            if (self::current() !== $stored) {
                Cache::forget(self::CACHE_KEY);
                self::$memo = null;
            }

            return $stored;
        }

        $path = self::generate();
        self::set($path);

        return $path;
    }

    private static function fromEnv(): string
    {
        return trim((string) config('owner_app.path', ''), '/');
    }

    /* ---------------------------------------------------- its own host */

    /**
     * OPTIONAL: the one host the app answers on, e.g. owner.extrabeauty.ae.
     * Empty (the default) keeps the app on the shop's own host at its secret
     * path. When set, routes/owner-app.php registers the group with
     * ->domain(host) ONLY, so the path on the shop's host is not mounted at
     * all, and the app's host-only cookies belong to that host alone: no
     * script on any page of the shop runs on the app's origin.
     */
    public static function host(): ?string
    {
        if (self::$hostMemo === null) {
            try {
                $stored = Cache::rememberForever(self::HOST_CACHE_KEY, static function (): string {
                    return (string) DB::table('settings')->where('key', self::HOST_SETTING)->value('value');
                });
            } catch (\Throwable) {
                $stored = '';
            }
            $stored = is_string($stored) ? strtolower(trim($stored)) : '';
            self::$hostMemo = self::validHost($stored) ? $stored : false;
        }

        return self::$hostMemo === false ? null : self::$hostMemo;
    }

    /** A bare DNS name: no scheme, port, path, IP literal or trailing dot; at least two labels. */
    public static function validHost(string $host): bool
    {
        return strlen($host) <= 253
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host);
    }

    /** Write the host ('' clears it). The compiled route table is dropped, as set() does. */
    public static function setHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host !== '' && ! self::validHost($host)) {
            return false;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => self::HOST_SETTING],
            ['value' => $host, 'autoload' => false, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::HOST_CACHE_KEY);
        Cache::forget('kbb.settings');
        \App\Models\Setting::flushMap();
        self::$hostMemo = null;

        foreach (glob(base_path('bootstrap/cache/routes*.php')) ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    private static function resolve(): ?string
    {
        $fromEnv = self::fromEnv();

        if ($fromEnv !== '') {
            return self::valid($fromEnv) ? $fromEnv : null;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, static function (): string {
                return trim((string) DB::table('settings')->where('key', self::SETTING)->value('value'), '/');
            });
        } catch (\Throwable) {
            return null;
        }

        return is_string($stored) && self::valid($stored) ? $stored : null;
    }
}
