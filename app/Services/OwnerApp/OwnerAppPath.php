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
 *      fresh random value and re-rollable from Users & Roles → Owner app —
 *      New address for a random one, Custom address for one the owner types
 *      (Lane OA3; customProblem() is the gate);
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

    /**
     * 8–64 characters, lowercase letters, digits, - and _, at least one _,
     * starting and ending with a letter or digit. The floor was 12 while every
     * address was generated (23 characters); Lane OA3 lets the owner type his
     * own, and customProblem() holds a typed one to 8–40.
     */
    public static function valid(string $path): bool
    {
        return (bool) preg_match('/^(?=[a-z0-9_-]*_)[a-z0-9][a-z0-9_-]{6,62}[a-z0-9]$/D', $path);
    }

    /* ------------------------------------------- an address the owner types */

    public const CUSTOM_MIN = 8;

    public const CUSTOM_MAX = 40;

    /** Below this a typed address is accepted with a warning, never refused. */
    public const STRONG_LENGTH = 16;

    /**
     * Words a scanner tries first. A hint, not a block: an address made only of
     * these (and digits) is accepted, with a warning beside it.
     */
    public const WEAK_WORDS = ['admin', 'administrator', 'app', 'apps', 'beauty', 'backend', 'boss', 'control', 'console',
        'dash', 'dashboard', 'extra', 'extrabeauty', 'go', 'hidden', 'k', 'kbb', 'kbeauty', 'kbeautybliss', 'login', 'manage',
        'manager', 'me', 'mobile', 'my', 'office', 'owner', 'panel', 'pass', 'password', 'phone', 'pin', 'portal', 'private',
        'root', 'secret', 'secure', 'shop', 'signin', 'staff', 'store', 'team', 'test', 'the', 'user', 'web', 'x'];

    /**
     * Why the owner may not use this address, or null when he may. Lowercases
     * and trims slashes first; the caller stores normaliseCustom()'s result.
     *
     * The collision rules are the router's own and the slug tables', not a
     * word list: an address that equals a first segment the shop already
     * serves, or a page, article, category, brand, product, tag or redirect
     * the owner has published, would be one URL with two owners.
     */
    public static function customProblem(string $raw): ?string
    {
        $path = self::normaliseCustom($raw);
        $min = self::CUSTOM_MIN;
        $max = self::CUSTOM_MAX;

        if ($path === '') {
            return 'Type the address you want, for example rafi_store-2027.';
        }
        if (strlen($path) < $min || strlen($path) > $max) {
            return "Use {$min}–{$max} characters. This one has ".strlen($path).'.';
        }
        if (! preg_match('/^[a-z0-9_-]+$/D', $path)) {
            return 'Use only lowercase letters a–z, digits 0–9, - and _. No spaces, slashes, dots or other characters.';
        }
        if (! str_contains($path, '_')) {
            return 'Put at least one underscore (_) in it, e.g. rafi_store-2027. Shop pages and articles can never have an underscore in their address, so one guarantees that nothing you publish later can take over the app’s link.';
        }
        if (! self::valid($path)) {
            return 'Start and end with a letter or a digit, not - or _.';
        }
        if ($path === self::current()) {
            return 'That is already the app’s address.';
        }

        $admin = strtolower(trim(\App\Services\AdminPathService::current(), '/'));
        foreach (array_unique(array_filter([$admin, 'admin', 'admin-api', 'api'])) as $taken) {
            if ($path === $taken || str_starts_with($path, $taken)) {
                return "It may not begin with “{$taken}”: that is the start of the admin’s or the shop’s own addresses.";
            }
        }

        if (in_array($path, self::takenSegments(), true)) {
            return 'The shop already uses /'.$path.'/ for one of its own pages. Choose another.';
        }

        if (($what = self::slugOwner($path)) !== null) {
            return 'A '.$what.' on the shop already has the address /'.$path.'/. Choose another.';
        }

        return null;
    }

    public static function normaliseCustom(string $raw): string
    {
        return strtolower(trim(trim($raw), '/'));
    }

    /** A warning to show beside an accepted address, or null. Never a refusal. */
    public static function weakness(string $path, array $names = []): ?string
    {
        $warn = [];
        if (strlen($path) < self::STRONG_LENGTH) {
            $warn[] = 'it is short — '.self::STRONG_LENGTH.' or more characters is much harder to guess';
        }

        $words = self::WEAK_WORDS;
        foreach ($names as $name) {
            foreach (preg_split('/[^a-z]+/', strtolower((string) $name)) ?: [] as $token) {
                if (strlen($token) >= 2) {
                    $words[] = $token;
                }
            }
        }
        $parts = array_values(array_filter(preg_split('/[^a-z]+/', $path) ?: [], 'strlen'));
        if ($parts !== [] && array_diff($parts, $words) === []) {
            $warn[] = 'it is made of ordinary words, names and numbers, which scanners try first';
        }

        return $warn === [] ? null : 'Accepted, but '.implode(', and ', $warn).'. The address is the first thing that keeps scanners away from the app; mixing in a few random letters makes it far stronger.';
    }

    /** The first segment of every route the shop registers, plus the reserved list. */
    private static function takenSegments(): array
    {
        $out = \App\Http\Controllers\Store\PageController::RESERVED_SLUGS;
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'owner-app.')) {
                continue;
            }
            $first = strtolower(explode('/', trim($route->uri(), '/'))[0]);
            if ($first !== '' && ! str_contains($first, '{')) {
                $out[] = $first;
            }
        }

        return array_values(array_unique($out));
    }

    /** Which published thing already answers at /{path}/, if any. One indexed lookup per table. */
    private static function slugOwner(string $path): ?string
    {
        $tables = ['pages' => 'page', 'posts' => 'article', 'categories' => 'category', 'brands' => 'brand',
            'products' => 'product', 'tags' => 'tag', 'locale_slugs' => 'translated page'];

        foreach ($tables as $table => $label) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && DB::table($table)->where('slug', $path)->exists()) {
                return $label;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('redirects')
            // `/p`, `/p/` and everything under `/p/` — a range, not LIKE: an
            // underscore is a LIKE wildcard, and every address has one. '0'
            // is the byte after '/', so [`/p/`, `/p0`) is exactly "under /p/".
            && DB::table('redirects')->where(fn ($q) => $q->where('source', '/'.$path)
                ->orWhere(fn ($r) => $r->where('source', '>=', '/'.$path.'/')->where('source', '<', '/'.$path.'0')))->exists()) {
            return 'redirect';
        }

        return null;
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
