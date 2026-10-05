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
 *   1. KBB_OWNER_APP_PATH in .env — always wins, the escape hatch;
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
    }

    public static function isLockedByEnv(): bool
    {
        return trim((string) env('KBB_OWNER_APP_PATH', ''), '/') !== '';
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
        $current = self::current();

        if ($current !== null) {
            return $current;
        }

        $path = self::generate();
        self::set($path);

        return $path;
    }

    private static function resolve(): ?string
    {
        $fromEnv = trim((string) env('KBB_OWNER_APP_PATH', ''), '/');

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
