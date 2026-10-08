<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Where the firewall's counters and bans live.                     (Lane FW)
 *
 * THE FASTEST STORE THE SERVER HAS, NEVER THE DATABASE. "auto" is the shop's
 * own cache store unless that store is `database` (a query per counter — the
 * thing this module must never add) or `dynamodb`/`null`; then the file store.
 * The owner can pick Redis, Memcached or APCu on the screen once the server has
 * one; save() checks it answers before keeping it.
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

    /** The store name "auto" (or a choice) resolves to on this server. */
    public static function resolve(string $choice = 'auto'): string
    {
        $stores = (array) config('cache.stores', []);

        if ($choice !== 'auto' && isset($stores[$choice])) {
            return $choice;
        }

        $default = (string) config('cache.default', 'file');
        $driver = (string) ($stores[$default]['driver'] ?? $default);

        if (in_array($driver, ['database', 'dynamodb', 'null'], true)) {
            return isset($stores['file']) ? 'file' : 'array';
        }

        return $default;
    }

    public static function use(string $choice): void
    {
        $name = self::resolve($choice);

        if ($name !== self::$name) {
            self::$name = $name;
            self::$repo = null;
        }
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

    public static function forget(string $key): void
    {
        try {
            self::repo()?->forget($key);
        } catch (\Throwable $e) {
            self::warn($e);
        }
    }

    /** Does $choice answer a write and a read? For the screen's save. */
    public static function works(string $choice): bool
    {
        try {
            $repo = Cache::store(self::resolve($choice));
            $key = 'fw:probe:'.bin2hex(random_bytes(4));
            $repo->put($key, 'ok', 10);
            $ok = $repo->get($key) === 'ok';
            $repo->forget($key);

            return $ok;
        } catch (\Throwable) {
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
