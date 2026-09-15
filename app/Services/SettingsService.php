<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ModuleSetting;
use App\Models\ModuleToggle;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings and module toggles. Mirrors the WordPress side's split:
 * kbb_settings -> settings, kbb_modules -> module_toggles,
 * kbb_module_settings -> module_settings.
 *
 * Autoloaded settings are cached as one payload, because on shared hosting the
 * cache driver is a file and one read beats forty.
 */
class SettingsService
{
    private const CACHE_KEY = 'kbb.settings';
    private const MODULES_KEY = 'kbb.modules';

    public function all(): array
    {
        $cached = Cache::rememberForever(self::CACHE_KEY, function () {
            /*
             * Written as an explicit loop rather than pluck()->map()->all().
             *
             * The chained form threw "Call to undefined method Setting::map()"
             * in production, which means something in that chain returned a
             * model rather than a collection. Rather than depend on the return
             * type of a fluent call, this reads rows and builds the array
             * directly — there is nothing left to be wrong about.
             */
            $out = [];

            foreach (Setting::query()->where('autoload', true)->get(['key', 'value']) as $row) {
                $out[(string) $row->key] = $this->decode($row->value);
            }

            return $out;
        });

        // A cache written by an older build could hold something else entirely.
        // Never let a stale entry take the storefront down.
        if (! is_array($cached)) {
            Cache::forget(self::CACHE_KEY);

            return [];
        }

        return $cached;
    }

    /**
     * Per-request memo for keys outside the autoload map, misses included.
     *
     * Without it, every get() of a non-autoloaded key was a fresh SELECT, and
     * a miss was never remembered at all -- so a key that does not exist cost
     * one query per call, forever. Measured on /shop: 390 queries for four
     * products and 780 for twenty-four, 111 distinct keys re-queried, which
     * was the dominant cost of every storefront page.
     *
     * Static, so it lasts a request under PHP-FPM and no longer. That is the
     * same shape as the Setting::map() trap in CLAUDE.md, so both writers
     * clear it: set() drops the one key, flush() drops everything. A test
     * pins that a write is visible to the next read in-process.
     */
    private static array $memo = [];

    /**
     * Has this request already taken the whole-table snapshot? See get().
     *
     * Separate from `$memo === []` because a snapshot of a table with no
     * non-autoloaded rows is still a snapshot, and must not be retaken on
     * every subsequent miss.
     */
    private static bool $snapshotTaken = false;

    /** Distinguishes "looked it up and it is not there" from "not looked up". */
    private const MISS = "\0kbb-miss";

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        if (! array_key_exists($key, self::$memo)) {
            $this->snapshot();

            if (! array_key_exists($key, self::$memo)) {
                self::$memo[$key] = self::MISS;
            }
        }

        return self::$memo[$key] === self::MISS ? $default : self::$memo[$key];
    }

    /**
     * Read every setting outside the autoload map in ONE query.
     *
     * The memo above fixed re-reading the SAME key; it left one SELECT per
     * DISTINCT key, and the shared header, footer and product chrome read
     * around 105 distinct non-autoloaded keys on every page. So the previous
     * `Setting::find($key)` here cost one single-row SELECT per DISTINCT key on
     * EVERY storefront request -- 125 of the homepage's 164 queries, 126 of the
     * product page's 136, 105 of the shop's 112 -- which is the same N+1 the
     * memo was added to fix, one level up: per key rather than per call. With
     * the whole table read once, those pages are 40, 11 and 8. Measured, before
     * and after, in tests/Feature/StorefrontQueryBudgetTest.php.
     *
     * The settings table is the store's configuration, not a data table -- the
     * whole of it is a few hundred small rows, and all() already reads most of
     * it in one go -- so fetching it entire is cheaper than fetching three of
     * its rows separately, and bounds the cost at one query however many keys
     * a page goes on to ask for.
     */
    private function snapshot(): void
    {
        if (self::$snapshotTaken) {
            return;
        }

        $fresh = [];

        foreach (Setting::query()->get(['key', 'value']) as $row) {
            $fresh[(string) $row->key] = $this->decode($row->value);
        }

        // Keep the misses already remembered for keys the table still lacks,
        // so a re-read after a partial forgetMemo() does not go back to the
        // database for something that was not there a moment ago.
        foreach (self::$memo as $key => $value) {
            if ($value === self::MISS && ! array_key_exists($key, $fresh)) {
                $fresh[$key] = self::MISS;
            }
        }

        self::$memo = $fresh;
        self::$snapshotTaken = true;
    }

    /** Drop the per-request memo. Called by set() and flush(); also usable from tests. */
    public static function forgetMemo(?string $key = null): void
    {
        if ($key === null) {
            self::$memo = [];
            self::$snapshotTaken = false;

            return;
        }

        unset(self::$memo[$key]);

        // The snapshot is no longer a faithful copy of the table for this key,
        // so the next read of it has to be allowed back to the database.
        self::$snapshotTaken = false;
    }

    public function set(string $key, mixed $value, bool $autoload = true): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value), 'autoload' => $autoload]
        );

        Cache::forget(self::CACHE_KEY);
        self::forgetMemo($key);

        // Setting::map() keeps its own cache and is read by the SEO layer and
        // the original page controllers. Clearing one without the other leaves
        // half the site on the old value.
        Setting::flushMap();

        // Money resolves the currency once per container and holds it, so a
        // symbol or decimals change written mid-request would otherwise not be
        // visible until the next one.
        \App\Support\Money::forgetConfig();
    }

    /**
     * Is a module on? Mirrors kbb_mod(): unknown modules fall back to the given
     * default, so the storefront renders correctly before anything is configured.
     */
    public function moduleEnabled(string $module, bool $default = false): bool
    {
        $map = Cache::rememberForever(self::MODULES_KEY, function () {
            $out = [];

            foreach (ModuleToggle::query()->get(['module', 'enabled']) as $row) {
                $out[(string) $row->module] = (bool) $row->enabled;
            }

            return $out;
        });

        if (! is_array($map)) {
            Cache::forget(self::MODULES_KEY);

            return $default;
        }

        return array_key_exists($module, $map) ? (bool) $map[$module] : $default;
    }

    public function setModule(string $module, bool $enabled): void
    {
        ModuleToggle::updateOrCreate(['module' => $module], ['enabled' => $enabled]);
        Cache::forget(self::MODULES_KEY);
    }

    public function moduleSetting(string $module, string $key, mixed $default = null): mixed
    {
        $row = ModuleSetting::where('module', $module)->where('key', $key)->first();

        return $row ? $this->decode($row->value) : $default;
    }

    /**
     * The write half of moduleSetting(), which had only a reader.
     *
     * Mirrors setModule(): one row per module and key, matching the plugin's
     * per-module option array.
     */
    public function setModuleSetting(string $module, string $key, mixed $value): void
    {
        ModuleSetting::updateOrCreate(
            ['module' => $module, 'key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value)]
        );
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::MODULES_KEY);
        self::forgetMemo();
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }
}
