<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where the firewall's counters and bans live.                     (Lane FW)
 *
 * NOT THE PER-REQUEST COUNTERS — those are FirewallCounters' fixed table.
 * This store holds the rare things: the live view's aggregated log, the DNS
 * verdicts for claimed bots, the flush lock. It is the shop's own cache store
 * unless that is `database` (a query per write — the thing this module must
 * never add) or `dynamodb`/`null`; then the file store.
 *
 * FAILS OPEN, ALWAYS. Every call is wrapped: a Redis that has gone away, a full
 * disk or a permissions error makes the firewall count nothing and refuse
 * nothing, and the shop carries on. One warning per process, not one per
 * request.
 *
 * bump() IS THE ONE ATOMIC OPERATION. Cache::increment() on a key that does
 * not exist creates it with NO expiry on the file and Redis stores (and
 * returns false on Memcached), so a counter would live for ever. bump()
 * increments, and on the FIRST hit of a window (1, or false) writes the key
 * once with its TTL. One operation per request, two on the first request of a
 * window.
 */
final class FirewallStore
{
    private static ?Repository $repo = null;

    private static ?string $name = null;

    private static bool $warned = false;

    /**
     * The clock: time(), or the test clock when a test has frozen one (the
     * cache stores expire keys by Carbon's clock, so bans must agree with it).
     */
    public static function now(): int
    {
        return \Illuminate\Support\Carbon::hasTestNow() ? \Illuminate\Support\Carbon::now()->getTimestamp() : time();
    }

    /** The store this server's firewall log uses. */
    public static function resolve(): string
    {
        $stores = (array) config('cache.stores', []);

        $default = (string) config('cache.default', 'file');
        $driver = (string) ($stores[$default]['driver'] ?? $default);

        if (in_array($driver, ['database', 'dynamodb', 'null'], true)) {
            return isset($stores['file']) ? 'file' : 'array';
        }

        return $default;
    }

    public static function name(): string
    {
        return self::$name ??= self::resolve();
    }

    private static function repo(): ?Repository
    {
        if (self::$repo !== null) {
            return self::$repo;
        }

        try {
            return self::$repo = Cache::store(self::name());
        } catch (\Throwable $e) {
            self::warn($e);

            return null;
        }
    }

    public static function bump(string $key, int $ttl): int
    {
        try {
            $repo = self::repo();

            if ($repo === null) {
                return 0;
            }

            $n = $repo->increment($key);

            if ($n === false || $n === null) {
                $repo->add($key, 1, $ttl);

                return 1;
            }

            if ((int) $n === 1) {
                $repo->put($key, 1, $ttl);
            }

            return (int) $n;
        } catch (\Throwable $e) {
            self::warn($e);

            return 0;
        }
    }

    public static function get(string $key): mixed
    {
        try {
            return self::repo()?->get($key);
        } catch (\Throwable $e) {
            self::warn($e);

            return null;
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function many(array $keys): array
    {
        try {
            $repo = self::repo();

            return $repo === null ? [] : $repo->many($keys);
        } catch (\Throwable $e) {
            self::warn($e);

            return [];
        }
    }

    public static function put(string $key, mixed $value, int $ttl): void
    {
        try {
            self::repo()?->put($key, $value, $ttl);
        } catch (\Throwable $e) {
            self::warn($e);
        }
    }

    public static function add(string $key, mixed $value, int $ttl): bool
    {
        try {
            return (bool) self::repo()?->add($key, $value, $ttl);
        } catch (\Throwable $e) {
            self::warn($e);

            return false;
        }
    }

    public static function reset(): void
    {
        self::$repo = null;
        self::$name = null;
        self::$warned = false;
    }

    private static function warn(\Throwable $e): void
    {
        if (! self::$warned) {
            self::$warned = true;

            try {
                Log::warning('firewall store unavailable, firewall is counting nothing', ['store' => self::$name, 'exception' => class_basename($e)]);
            } catch (\Throwable) {
            }
        }
    }
}
