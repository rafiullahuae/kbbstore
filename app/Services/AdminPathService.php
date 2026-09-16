<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where the admin lives.
 *
 * Resolution order, and the order matters:
 *
 *   1. KBB_ADMIN_PATH in .env — if set, it always wins.
 *   2. the `admin_path` row in the settings table, editable from the admin.
 *   3. "admin".
 *
 * .env outranks the database deliberately. If a path is ever set that locks you
 * out of your own site, editing one line in .env restores access without needing
 * the admin you can no longer reach. Every self-hosted admin-path feature needs
 * an escape hatch outside itself; this is that hatch.
 *
 * The value is read once per request and cached, because it is needed while
 * routes are being registered — before the application is fully booted — and a
 * database query on every request at that point would be wasteful.
 */
class AdminPathService
{
    private const CACHE_KEY = 'kbb.admin_path';

    /**
     * Resolved once per request. current() runs while routes register and again
     * from the admin panel; on a file cache each call is a disk read.
     */
    private static ?string $memo = null;

    public static function current(): string
    {
        return self::$memo ??= self::resolve();
    }

    /**
     * Drop the memo so the next current() re-reads the setting.
     *
     * set() already does this for its own write. This is for the other way the
     * value changes underneath the memo: a row written straight into `settings`
     * or a cache that was cleared — which is what a test does, and what makes
     * the memo an order dependency in a long-lived process. Whichever test
     * resolved the admin path first would otherwise decide it for every test
     * after it.
     */
    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    private static function resolve(): string
    {
        $fromEnv = trim((string) env('KBB_ADMIN_PATH', ''), '/');

        if ($fromEnv !== '') {
            return $fromEnv;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                $row = DB::table('settings')->where('key', 'admin_path')->value('value');

                return $row === null ? '' : trim((string) $row, '/');
            });
        } catch (\Throwable) {
            // Routes must register even if the database is unreachable —
            // otherwise a database blip takes the whole site down, not just the
            // admin. Fall back rather than throw.
            return 'admin';
        }

        return $stored !== '' ? $stored : 'admin';
    }

    public static function isLockedByEnv(): bool
    {
        return trim((string) env('KBB_ADMIN_PATH', ''), '/') !== '';
    }

    /** @return array{ok: bool, error: ?string} */
    public static function set(string $path): array
    {
        $path = trim($path, '/');

        if (! preg_match('/^[a-z0-9][a-z0-9\-_]{2,40}$/i', $path)) {
            return ['ok' => false, 'error' => 'Use 3–40 characters: letters, numbers, hyphens or underscores. No slashes.'];
        }

        // Reserved words that would collide with real storefront routes.
        $reserved = ['shop', 'product', 'cart', 'checkout', 'my-account', 'api',
            'build', 'wp-content', 'storage', 'sitemap', 'robots', 'reviews', 'blog'];

        if (in_array(strtolower($path), $reserved, true)) {
            return ['ok' => false, 'error' => "\"{$path}\" is used by the storefront. Choose another word."];
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'admin_path'],
            ['value' => $path, 'autoload' => true, 'updated_at' => now(), 'created_at' => now()]
        );

        Cache::forget(self::CACHE_KEY);
        self::$memo = null;

        // admin_path is an autoloaded settings row, so it also sits inside
        // SettingsService::all()'s rememberForever payload and inside
        // Setting::map(). Writing through DB::table here bypasses both, and
        // rememberForever means stale means forever.
        Cache::forget('kbb.settings');
        \App\Models\Setting::flushMap();

        // Route caching would freeze the old path in place.
        foreach (glob(base_path('bootstrap/cache/routes*.php')) ?: [] as $file) {
            @unlink($file);
        }

        return ['ok' => true, 'error' => null];
    }
}
